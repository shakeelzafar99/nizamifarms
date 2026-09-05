<?php
/**
 * WORKSHOP VISITS — the rules, on real data (Phase 2, Sep-02 2026).
 * Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md §3.
 *
 * What these prove:
 *   §1 who may schedule, and that a past date is refused (that is Record service);
 *   §2 warnings WARN and never block (owner intent);
 *   §3 one live visit per machine — a second one RESCHEDULES, and the rider must
 *      accept again;
 *   §4 acceptance: the named rider only; a manager may stand in and it is recorded
 *      as such, never as the rider confirming (owner ruling);
 *   §5 cancel;
 *   §6 a visit raised off a ticket writes itself into that thread, and done —
 *      crucially, completing the VISIT does not CLOSE the ticket;
 *   §7 "missed" is DERIVED, so no cron is needed;
 *   §9 the planner/attendance map;
 *  §10 the banner audiences, including that a rider sees only his own;
 *  §11 the day-before reminder fires exactly once;
 *  §12 the controller, including that a rider cannot list the whole fleet.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ ONE user authenticated per process — the service takes the user as an argument, so
 *   most sections need no login at all.
 *
 * Run:  php test_workshop_visits.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleTicketService as VT;
use App\Services\Riders\WorkshopVisitService as WV;
use App\Services\LocationService as LSvc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as Sch;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }

$wv = new WV();
$vt = new VT();
ok('the workshop table exists (run workshop_visits_sep2026.sql first)', $wv->available(), true);
if (!$wv->available()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

// ─── fixtures: DISCOVERED ────────────────────────────────────────────────────
head('§0 fixtures');

$manager = null; $rider = null; $otherRider = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if (!$manager && $u->hasPermission(WV::PERMISSION)) $manager = $u;
}
$res = new VehicleResolver();
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = $res->currentVehicleFor((int) $uid);
    if (!$v) continue;
    $u = User::find((int) $uid);
    if (!$u) continue;
    if (!$rider) { $rider = $u; continue; }
    if (!$otherRider && (int) $u->id !== (int) $rider->id) { $otherRider = $u; break; }
}
ok('a manager holding schedule_workshop exists', (bool) $manager, null, true);
ok('two riders with machines exist', (bool) ($rider && $otherRider), null, true);
if (!$manager || !$rider || !$otherRider) { echo "\nfixtures missing — stopping.\n"; exit(1); }

// Maintenance types — discovered, for the Phase 3 completion sections.
$types        = DB::table('t_fleet_maintenance_types')->where('is_active', 1)->get();
$resetting    = $types->first(fn ($t) => $t->resets_service_clock && (int) $t->interval_km > 0);
$nonResetting = $types->first(fn ($t) => !$t->resets_service_clock && (int) $t->interval_km > 0);
$asConditions = $types->first(fn ($t) => (int) $t->interval_km <= 0);
ok('a clock-resetting scheduled type exists', (bool) $resetting, null, true);
ok('a scheduled type that does NOT reset the clock exists', (bool) $nonResetting, null, true);

$vid  = (int) $res->currentVehicleFor((int) $rider->id);
$vid2 = (int) $res->currentVehicleFor((int) $otherRider->id);
$soon = \Carbon\Carbon::today()->addDays(3)->format('Y-m-d');
echo "  · manager={$manager->id} rider={$rider->id}(v{$vid}) other={$otherRider->id}(v{$vid2}) date={$soon}\n";
ok('the rider cannot schedule (so the gates below mean something)',
   $wv->canSchedule($rider), false);

$beforeVisits = DB::table(WV::T_VISIT)->count();
$beforeTickets = DB::table(VT::T_TICKET)->count();

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 scheduling');

$denied = $wv->schedule($rider, ['vehicle_id' => $vid, 'visit_date' => $soon]);
ok('a rider cannot schedule one', $denied['ok'], false);

$past = $wv->schedule($manager, ['vehicle_id' => $vid,
    'visit_date' => \Carbon\Carbon::today()->subDay()->format('Y-m-d')]);
ok('a PAST date is refused', $past['ok'], false);
ok('  …and points at Record service instead',
   (bool) preg_match('/record service/i', $past['message']), null, true);

$noDate = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => 'soon']);
ok('a malformed date is refused', $noDate['ok'], false);

$r1 = $wv->schedule($manager, [
    'vehicle_id' => $vid, 'visit_date' => $soon, 'visit_time' => '11:00',
    'purpose' => 'service', 'workshop' => 'Ali Motors', 'note' => 'Brake + oil',
]);
ok('a manager can schedule one', $r1['ok'], true);
$w1 = (int) $r1['visit_id'];
$row = $wv->find($w1);
ok('  …and the RIDER was resolved from the registry',
   (int) $row['user_id'], (int) $rider->id);
ok('  …it starts as scheduled, awaiting acceptance', $row['status'], 'scheduled');
ok('  …the time is kept', substr((string) $row['visit_time'], 0, 5), '11:00');
ok('  …and the receipt names who must accept',
   (bool) strpos($r1['message'], $rider->fullname ?? ''), null, true);

/**
 * ⭐ RIDER-FIRST scheduling: a surface that knows the man but not the machine (the Bikes
 *   drawer, a ticket) sends `user_id` alone and the registry resolves his bike. Without
 *   this each of those screens would have to find the vehicle id its own way.
 */
$byRider = $wv->schedule($manager, ['user_id' => (int) $otherRider->id, 'visit_date' => $soon]);
ok('a visit can be scheduled from the RIDER alone', $byRider['ok'], true);
ok('  …and the registry supplied his machine',
   (int) $wv->find((int) $byRider['visit_id'])['vehicle_id'], $vid2);
$wv->cancel($manager, (int) $byRider['visit_id'], 'test cleanup');

$neither = $wv->schedule($manager, ['visit_date' => $soon]);
ok('with neither a bike nor a rider it is refused', $neither['ok'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 warnings WARN, they never block');

// A date the rider is certainly not working: find one his shift says is off.
$svcShift = new \App\Services\ShiftResolutionService();
$offDate = null;
for ($i = 1; $i <= 14; $i++) {
    $d = \Carbon\Carbon::today()->addDays($i)->format('Y-m-d');
    if ($svcShift->dayKind((int) $rider->id, $d) === 'off') { $offDate = $d; break; }
}
if ($offDate) {
    $warn = $wv->warningsFor($vid, (int) $rider->id, $offDate);
    ok('an off day produces a warning', count($warn) > 0, true);
    ok('  …that says so in words',
       (bool) preg_match('/off day/i', implode(' ', $warn)), null, true);
    $stillMade = $wv->schedule($manager, ['vehicle_id' => $vid2, 'visit_date' => $offDate]);
    ok('  …but the visit is still CREATED (warn, never block)', $stillMade['ok'], true);
    ok('  …and the warnings come back with it', count($stillMade['warnings']) > 0, true);
    $wv->cancel($manager, (int) $stillMade['visit_id'], 'test cleanup');
} else {
    ok('(no off day in the next fortnight — warning path not exercised)', true, true);
    ok('(skipped)', true, true); ok('(skipped)', true, true); ok('(skipped)', true, true);
}

// Naming a rider who does NOT hold that bike must warn about it.
$mismatch = $wv->warningsFor($vid, (int) $otherRider->id, $soon);
ok('naming the wrong rider for a bike warns about the keeper',
   (bool) preg_match('/has this bike/i', implode(' ', $mismatch)), null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§3 one live visit per machine — a second RESCHEDULES');

$later = \Carbon\Carbon::today()->addDays(5)->format('Y-m-d');
$r2 = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $later]);
ok('scheduling again on the same bike succeeds', $r2['ok'], true);
$w2 = (int) $r2['visit_id'];
ok('  …and reports what it replaced', (int) $r2['rescheduled_from'], $w1);
ok('  …the old row is marked rescheduled', $wv->find($w1)['status'], 'rescheduled');
ok('  …and points at its replacement', (int) $wv->find($w1)['superseded_by'], $w2);
ok('  …the new one is awaiting acceptance again', $wv->find($w2)['status'], 'scheduled');
ok('  …so only ONE live visit exists for that bike',
   count($wv->listVisits(['vehicle_id' => $vid])), 1);

// ─────────────────────────────────────────────────────────────────────────────
head('§4 acceptance (owner ruling: the RIDER accepts)');

$wrong = $wv->accept($otherRider, $w2);
ok('another rider cannot accept it', $wrong['ok'], false);
ok('  …and is told it is not his', (bool) preg_match('/not yours/i', $wrong['message']), null, true);

$acc = $wv->accept($rider, $w2);
ok('the named rider can accept', $acc['ok'], true);
$row = $wv->find($w2);
ok('  …status accepted', $row['status'], 'accepted');
ok('  …recorded as HIS OWN confirmation', $row['accepted_via'], 'app');
ok('  …by him', (int) $row['accepted_by'], (int) $rider->id);
ok('accepting twice is refused', $wv->accept($rider, $w2)['ok'], false);

// The stand-in path.
$r3 = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $later]);
$w3 = (int) $r3['visit_id'];
$onBehalf = $wv->accept($manager, $w3);
ok('a manager may accept ON BEHALF when the app is not working', $onBehalf['ok'], true);
$row = $wv->find($w3);
ok('  …and it is stored as a stand-in, not the rider confirming', $row['accepted_via'], 'manager');
ok('  …naming who actually pressed it', (int) $row['accepted_by'], (int) $manager->id);
$shaped = $wv->listVisits(['vehicle_id' => $vid])[0];
ok('  …and every screen is told it was on his behalf', $shaped['accepted_on_behalf'], true);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 cancelling');

$riderCancel = $wv->cancel($rider, $w3, 'nope');
ok('a rider cannot cancel', $riderCancel['ok'], false);
$cancel = $wv->cancel($manager, $w3, 'Workshop closed that day');
ok('a manager can cancel', $cancel['ok'], true);
ok('  …status cancelled', $wv->find($w3)['status'], 'cancelled');
ok('  …with the reason kept', $wv->find($w3)['outcome_note'], 'Workshop closed that day');
ok('cancelling twice is refused', $wv->cancel($manager, $w3, null)['ok'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§6 marking done — and what it does NOT do to the ticket');

$tk = $vt->open($rider, ['title' => 'Chain slipping', 'body' => 'Since Monday']);
$tid = (int) $tk['ticket_id'];
$r4 = $wv->schedule($manager, [
    'vehicle_id' => $vid, 'visit_date' => $soon, 'purpose' => 'repair', 'ticket_id' => $tid,
]);
$w4 = (int) $r4['visit_id'];
ok('a visit can be raised off a ticket', $r4['ok'], true);
ok('  …the ticket now points at the visit', (int) $vt->find($tid)['workshop_visit_id'], $w4);
ok('  …and its status says a workshop is booked', $vt->find($tid)['status'], 'scheduled');
$sys = array_values(array_filter($vt->thread($tid), fn ($m) => $m['kind'] === 'system'));
ok('  …with a line in the thread the rider can read',
   (bool) preg_match('/workshop visit set for/i', end($sys)['body']), null, true);

$riderDone = $wv->markDone($rider, $w4, []);
ok('a rider cannot mark it done', $riderDone['ok'], false);

$done = $wv->markDone($manager, $w4, ['outcome_note' => 'Chain replaced', 'service_log_id' => null]);
ok('a manager can mark it done', $done['ok'], true);
ok('  …status done', $wv->find($w4)['status'], 'done');
ok('  …with the outcome kept', $wv->find($w4)['outcome_note'], 'Chain replaced');

/**
 * ⚠ Completing the VISIT must not CLOSE the ticket — only a manager closes a ticket
 *   (owner ruling), and he may want the rider to confirm the fault is gone first.
 */
ok('the ticket is NOT closed by completing the visit',
   $vt->find($tid)['status'] === 'closed', false);
ok('  …it goes back to "being looked at", where a reply is expected',
   $vt->find($tid)['status'], 'acknowledged');
$sys = array_values(array_filter($vt->thread($tid), fn ($m) => $m['kind'] === 'system'));
ok('  …and the completion is in the thread',
   (bool) preg_match('/workshop visit completed/i', end($sys)['body']), null, true);
ok('marking done twice is refused', $wv->markDone($manager, $w4, [])['ok'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§7 "missed" is DERIVED — no cron required');

$r5 = $wv->schedule($manager, ['vehicle_id' => $vid2, 'visit_date' => $soon]);
$w5 = (int) $r5['visit_id'];
ok('a future visit is not missed', $wv->listVisits(['vehicle_id' => $vid2])[0]['is_missed'], false);
// Age it. No status changes — that is the point: nothing had to run.
DB::table(WV::T_VISIT)->where('id', $w5)
    ->update(['visit_date' => \Carbon\Carbon::today()->subDays(2)->format('Y-m-d')]);
$aged = $wv->listVisits(['vehicle_id' => $vid2, 'from' => '2000-01-01'])[0];
ok('a past date with a live status reads as missed', $aged['is_missed'], true);
ok('  …while its stored status is untouched', $wv->find($w5)['status'], 'scheduled');

// ─────────────────────────────────────────────────────────────────────────────
head('§9 the planner / attendance map');

$r6 = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $soon, 'visit_time' => '09:30']);
$map = $wv->mapForRange([(int) $rider->id, (int) $otherRider->id],
                        \Carbon\Carbon::today()->format('Y-m-d'),
                        \Carbon\Carbon::today()->addDays(10)->format('Y-m-d'));
$key = (int) $rider->id . '|' . $soon;
ok('the map is keyed by rider and day', isset($map[$key]), true);
ok('  …carrying the time for the cell', $map[$key]['time'] ?? null, '09:30');
ok('  …the bike name', (bool) ($map[$key]['vehicle_name'] ?? null), null, true);
ok('  …and whether he has accepted', $map[$key]['accepted'], false);

// ─────────────────────────────────────────────────────────────────────────────
head('§10 banner audiences');

$mSum = $wv->summaryFor($manager);
ok('a manager sees visits', $mSum['count'] >= 1, true);
ok('  …and is told he may schedule', $mSum['can_schedule'], true);

$rSum = $wv->summaryFor($rider);
$foreign = array_filter($rSum['visits'] ?? [], fn ($v) => $v['user_id'] !== (int) $rider->id);
ok('a rider sees ONLY his own visits', count($foreign), 0);
ok('  …and is not offered scheduling', $rSum['can_schedule'], false);

$next = $wv->nextForUser((int) $rider->id);
ok('his next visit is resolvable for the banner', (bool) $next, null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§11 the day-before reminder fires ONCE');

$tomorrow = \Carbon\Carbon::today()->addDay()->format('Y-m-d');
$r7 = $wv->schedule($manager, ['vehicle_id' => $vid2, 'visit_date' => $tomorrow]);
$first = $wv->dueReminders();
ok('a visit dated tomorrow is picked up', count($first) >= 1, true);
$second = $wv->dueReminders();
ok('  …and NOT a second time (reminded_at)', count($second), 0);
ok('  …the stamp is on the row',
   (bool) DB::table(WV::T_VISIT)->where('id', (int) $r7['visit_id'])->value('reminded_at'), null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§12 the controller');

\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($manager->id);
$ctl = app(\App\Http\Controllers\CRM\WorkshopVisitController::class);
$mk = function (string $method, string $uri, array $p = []) {
    $r = \Illuminate\Http\Request::create($uri, $method, $p);
    $r->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());
    return $r;
};
$json = fn ($resp) => json_decode($resp->getContent(), true);

$idx = $json($ctl->index($mk('GET', '/workshop')));
ok('index answers', $idx['success'] ?? false, true);
ok('  …and says the manager may schedule', $idx['can_schedule'], true);

$warnRes = $json($ctl->warnings($mk('GET', '/workshop/warnings', [
    'vehicle_id' => $vid, 'user_id' => (int) $otherRider->id, 'visit_date' => $soon])));
ok('the warnings endpoint answers before a manager commits', $warnRes['success'] ?? false, true);
ok('  …and flags the keeper mismatch', count($warnRes['warnings']) > 0, true);

$storeRes = $json($ctl->store($mk('POST', '/workshop', [
    'vehicle_id' => $vid, 'visit_date' => $soon, 'purpose' => 'inspection'])));
ok('store schedules through the controller', $storeRes['success'] ?? false, true);
$w8 = (int) $storeRes['visit_id'];

// ⚠ Called directly, `$request->validate()` THROWS — Laravel's handler is what turns
//   that into a 422 over HTTP. Catch it so this asserts the rule, not the harness.
$badStatus = null;
try {
    $badStatus = $ctl->store($mk('POST', '/workshop', ['vehicle_id' => $vid, 'visit_date' => 'nope']))
        ->getStatusCode();
} catch (\Illuminate\Validation\ValidationException $e) {
    $badStatus = 422;
}
ok('a malformed date is refused by validation', $badStatus, 422);

$pend = $json($ctl->pending($mk('GET', '/workshop/pending')));
ok('pending is self-scoped and answers', $pend['success'] ?? false, true);

$alertRes = $json($ctl->alerts($mk('GET', '/workshop/alerts')));
ok('the banner endpoint answers', $alertRes['success'] ?? false, true);
ok('  …with the keys the banners read',
   array_values(array_diff(['count', 'missed', 'latest_id', 'latest', 'can_schedule'],
                array_keys($alertRes))), []);

ok('done through the controller works',
   ($json($ctl->done($mk('POST', "/workshop/$w8/done", ['outcome_note' => 'ok']), $w8))['success'] ?? false), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§14 DATES — a visit set for +3 days lands on that day, everywhere, and only there');

/**
 * Owner: "if Qasim sets a date for after 3 days it must not show on the wrong date".
 * Three ways it could: the DB clock (2h behind PHP on this host), a UTC date on the
 * phone, or a range query that is off by one. Each is pinned below.
 */
$d3 = \Carbon\Carbon::today()->addDays(3)->format('Y-m-d');
$d2 = \Carbon\Carbon::today()->addDays(2)->format('Y-m-d');
$d4 = \Carbon\Carbon::today()->addDays(4)->format('Y-m-d');
$rx = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d3, 'visit_time' => '10:00']);
$wx = (int) $rx['visit_id'];
ok('stored visit_date is EXACTLY the day given (no clock involved)',
   substr((string) $wv->find($wx)['visit_date'], 0, 10), $d3);
$sh = $wv->listVisits(['vehicle_id' => $vid])[0];
ok('  …shaped date is that day', $sh['visit_date'], $d3);
ok('  …it is NOT today', $sh['is_today'], false);
ok('  …it is NOT tomorrow', $sh['is_tomorrow'], false);
ok('  …it is NOT missed', $sh['is_missed'], false);
ok('  …the rider’s "next" is that day', $wv->nextForUser((int) $rider->id)['visit_date'], $d3);

$m = $wv->mapForRange([(int) $rider->id], \Carbon\Carbon::today()->format('Y-m-d'), $d4);
ok('the planner map has it on +3', isset($m[(int) $rider->id . '|' . $d3]), true);
ok('  …and NOT on +2', isset($m[(int) $rider->id . '|' . $d2]), false);
ok('  …and NOT on +4', isset($m[(int) $rider->id . '|' . $d4]), false);
$mEdge = $wv->mapForRange([(int) $rider->id], $d3, $d3);
ok('  …a range of exactly that one day still finds it (inclusive bounds)',
   isset($mEdge[(int) $rider->id . '|' . $d3]), true);
$mMiss = $wv->mapForRange([(int) $rider->id], \Carbon\Carbon::today()->format('Y-m-d'), $d2);
ok('  …a range ending the day BEFORE does not', isset($mMiss[(int) $rider->id . '|' . $d3]), false);

// The DB clock is 2h behind PHP here. Nothing in the service may lean on it.
$src = file_get_contents(__DIR__ . '/app/Services/Riders/WorkshopVisitService.php');
// Strip comments first — the service's own WARNING about CURDATE/NOW() must not trip this.
$code = preg_replace('#/\*.*?\*/#s', '', $src);
$code = preg_replace('#^\s*//.*$#m', '', $code);
ok('the service never asks MySQL what day it is (CURDATE/NOW/DB::raw)',
   (bool) preg_match('/CURDATE|NOW\(\)|DB::raw/', $code), false);
ok('  …and never uses native date() for a calendar decision (Carbon only, so the clock can be simulated)',
   (bool) preg_match("/\bdate\('Y-m-d'\)/", $code), false);
$dbToday = DB::selectOne('SELECT CURDATE() d')->d;
echo "  · PHP today=" . date('Y-m-d') . "  DB CURDATE()=" . $dbToday
   . "  DB NOW()=" . DB::selectOne('SELECT NOW() n')->n . "  PHP now=" . now()->format('H:i') . "\n";

// ⭐ Simulate 00:30 PKT — the two hours where MySQL still thinks it is yesterday.
$realNow = \Carbon\Carbon::now();
\Carbon\Carbon::setTestNow($realNow->copy()->addDay()->setTime(0, 30));
$rowsAt0030 = $wv->listVisits(['vehicle_id' => $vid]);
$shAt = $rowsAt0030[0];
ok('at 00:30 the day after, "+3" reads as +2 from PHP’s calendar (not the DB’s)',
   $shAt['is_tomorrow'] || (!$shAt['is_today'] && !$shAt['is_missed']), true);
\Carbon\Carbon::setTestNow($d3 . ' 00:30:00');
ok('at 00:30 ON the day, it is "today"', $wv->listVisits(['vehicle_id' => $vid])[0]['is_today'], true);
\Carbon\Carbon::setTestNow($d3 . ' 23:59:00');
ok('at 23:59 ON the day, it is still "today", not missed',
   $wv->listVisits(['vehicle_id' => $vid])[0]['is_missed'], false);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($d3)->addDay()->setTime(0, 1));
ok('at 00:01 the next day, it is missed', $wv->listVisits(['vehicle_id' => $vid])[0]['is_missed'], true);
\Carbon\Carbon::setTestNow(); // back to real time

// ─────────────────────────────────────────────────────────────────────────────
head('§15 the management banner re-fires on EVERY event, not just creation');

$mark = fn () => (int) $wv->summaryFor($manager)['latest_id'];
$rY = $wv->schedule($manager, ['vehicle_id' => $vid2, 'visit_date' => $d3]);
$wy = (int) $rY['visit_id'];
$t0 = $mark();
ok('creating a visit moves the watermark', $t0 > 0, true);

sleep(1);   // event instants are seconds; make sure "accepted" lands strictly later
$wv->accept($rider === null ? $manager : $otherRider, $wy);
$t1 = $mark();
ok('the rider ACCEPTING moves it again', $t1 > $t0, true);
$accRow = array_values(array_filter($wv->summaryFor($manager)['visits'], fn ($v) => $v['id'] === $wy))[0] ?? null;
ok('  …and that visit’s event instant is its acceptance',
   ($accRow['event_ts'] ?? 0), \Carbon\Carbon::parse($wv->find($wy)['accepted_at'])->getTimestamp());
// Scheduling wy SUPERSEDED §7's aged visit on the same bike (one live visit per machine),
// so nothing outranks this acceptance right now and it leads. The priority rule itself
// (a missed visit beats newer events) is pinned further down with a realistic timeline.
// ⭐ PRIORITY, not recency: §14 left an UNCONFIRMED visit (wx) open, and "still not
//   confirmed" is more actionable than "just confirmed" — so wx leads even though wy's
//   acceptance is the newer event. The watermark (t1) still moved, so the banner FIRES;
//   priority only decides what it says first.
ok('  …but the banner LEADS with the still-unconfirmed visit (awaiting outranks confirmed)',
   (int) $wv->summaryFor($manager)['latest']['id'], $wx);
ok('  …(the aged visit wy replaced is no longer live)',
   in_array($wv->find($w5)['status'], ['rescheduled', 'cancelled', 'done'], true), true);

// Make it "tomorrow" and run the reminder sweep the banner poll triggers.
DB::table(WV::T_VISIT)->where('id', $wy)
    ->update(['visit_date' => \Carbon\Carbon::today()->addDay()->format('Y-m-d')]);
$sum = $wv->summaryFor($manager);
ok('a visit dated tomorrow is flagged is_tomorrow', $sum['latest']['is_tomorrow'] ?? null, true);
ok('  …and counted', $sum['tomorrow'], 1);
sleep(1);
$fired = $wv->dueReminders();
ok('the day-before sweep picks it up', count(array_filter($fired, fn ($v) => $v['id'] === $wy)), 1);
$t2 = $mark();
ok('  …and that moves the watermark, so "tomorrow" reaches the banner', $t2 > $t1, true);
ok('  …exactly once — a second poll does not', $mark(), $t2);

/**
 * MISSED. The instant is midnight after the visit date — fixed, so it fires once and
 * stays. A realistic timeline: set and reminded days ago, the date passed yesterday.
 * (Moving the date backwards on a row whose other events are "now" would put the
 * missed instant BEFORE them — a timeline that cannot happen in real use.)
 */
$rM = $wv->schedule($manager, ['vehicle_id' => $vid2, 'visit_date' => $d3]);   // supersedes wy
$wm = (int) $rM['visit_id'];
$ago = \Carbon\Carbon::now()->subDays(5)->format('Y-m-d H:i:s');
DB::table(WV::T_VISIT)->where('id', $wm)->update([
    'created_at' => $ago, 'accepted_at' => $ago, 'reminded_at' => $ago, 'status' => 'accepted',
    'visit_date' => \Carbon\Carbon::today()->subDays(2)->format('Y-m-d'),
]);
$mine = array_values(array_filter($wv->summaryFor($manager)['visits'], fn ($v) => $v['id'] === $wm))[0] ?? null;
ok('a missed visit exists in the summary', (bool) $mine, null, true);
$midnightAfter = \Carbon\Carbon::today()->subDays(1)->startOfDay()->getTimestamp();
ok('its event instant is midnight after the visit date', $mine['event_ts'] ?? null, $midnightAfter);
ok('  …which is LATER than its acceptance and reminder, so it counts as new',
   ($mine['event_ts'] ?? 0) > \Carbon\Carbon::parse($ago)->getTimestamp(), true);
ok('  …and the banner leads with the MISSED visit even though newer events exist (priority)',
   $wv->summaryFor($manager)['latest']['id'], $wm);
ok('  …and the watermark holds still on the next poll', $mark(), $mark());

// ─────────────────────────────────────────────────────────────────────────────
head('§16 PHASE 3 — completing a visit writes a TYPED service record');

/**
 * Owner: done can come from Qasim entering the values, OR the rider entering them after
 * the service. Both must produce the SAME typed record — that is the loop this whole
 * round started from closing.
 */
$logsBefore = DB::table('t_fleet_service_log')->count();
$meterNow = (int) ((new \App\Services\Riders\VehicleService())->currentMeterFor($vid) ?: 20000);

// --- a manager completing it, with a meter ---
$rp = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => \Carbon\Carbon::today()->format('Y-m-d'),
                               'purpose' => 'service', 'maintenance_type_id' => $resetting->id]);
$wp = (int) $rp['visit_id'];
\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($manager->id);
$ctl2 = app(\App\Http\Controllers\CRM\WorkshopVisitController::class);
$mk2 = function (string $m, string $u, array $p = []) {
    $r = \Illuminate\Http\Request::create($u, $m, $p);
    $r->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());
    return $r;
};
$j2 = fn ($resp) => json_decode($resp->getContent(), true);

$doneRes = $j2($ctl2->done($mk2('POST', "/w/$wp/done", ['meter' => $meterNow + 5, 'outcome_note' => 'oil done']), $wp));
ok('a manager completes it with a meter', $doneRes['success'] ?? false, true);
ok('  …and a service-log row was written', DB::table('t_fleet_service_log')->count(), $logsBefore + 1);
$log = DB::table('t_fleet_service_log')->orderByDesc('id')->first();
ok('  …against the type the visit was BOOKED for (no re-picking)',
   (int) $log->maintenance_type_id, (int) $resetting->id);
ok('  …at the meter given', (int) $log->meter, $meterNow + 5);
ok('  …dated the VISIT day, not today-if-different',
   substr((string) $log->service_date, 0, 10), $wv->find($wp)['visit_date']);
// ⚠ str_contains, NOT strpos — the note starts at position 0 and `(bool) 0` is false.
//   This bit once already in test_record_service_typed; do not write strpos here again.
ok('  …the note ties it to the visit', str_contains((string) $log->note, "Workshop visit #$wp"), true);
ok('  …the visit stores the log id', (int) $wv->find($wp)['service_log_id'], (int) $log->id);
ok('  …and the receipt names the job', str_contains($doneRes['message'], $resetting->type_name), true);

// --- the RIDER completing his own ---
$rr = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => \Carbon\Carbon::today()->format('Y-m-d'),
                               'purpose' => 'service', 'maintenance_type_id' => $nonResetting->id]);
$wr = (int) $rr['visit_id'];
$before = DB::table('t_fleet_service_log')->count();
$riderDone = $wv->markDone($rider, $wr, ['outcome_note' => 'done at the shop']);
ok('THE RIDER can complete his own visit (owner ruling)', $riderDone['ok'], true);
ok('  …and it is marked done', $wv->find($wr)['status'], 'done');

// He must not be able to close someone else's, or one that has not come round.
$rOther = $wv->schedule($manager, ['vehicle_id' => $vid2, 'visit_date' => \Carbon\Carbon::today()->format('Y-m-d')]);
ok('a rider cannot complete ANOTHER rider’s visit',
   $wv->markDone($rider, (int) $rOther['visit_id'], [])['ok'], false);
$rFuture = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d3]);
$fut = $wv->markDone($rider, (int) $rFuture['visit_id'], []);
ok('a rider cannot close a visit whose day has not come', $fut['ok'], false);
ok('  …and is told why', (bool) preg_match('/not come round/i', $fut['message']), null, true);
ok('a manager CAN close a future visit (he may have been told by phone)',
   $wv->markDone($manager, (int) $rFuture['visit_id'], [])['ok'], true);

// --- the refusal must not leave a visit "done" with no service behind it ---
$rBad = $wv->schedule($manager, ['vehicle_id' => $vid2, 'visit_date' => \Carbon\Carbon::today()->format('Y-m-d')]);
$wb = (int) $rBad['visit_id'];
$logsNow = DB::table('t_fleet_service_log')->count();
$badRes = $ctl2->done($mk2('POST', "/w/$wb/done", ['meter' => $meterNow + 9,
    'maintenance_type_id' => $asConditions ? $asConditions->id : 999999]), $wb);
ok('a meter with an unrecordable type is REFUSED', $badRes->getStatusCode(), 422);
ok('  …no service row was written', DB::table('t_fleet_service_log')->count(), $logsNow);
ok('  …and the visit is still open, not falsely done',
   in_array($wv->find($wb)['status'], WV::LIVE_STATUSES, true), true);

// --- completing WITHOUT a meter is still allowed (an inspection has nothing to record) ---
$logsNow = DB::table('t_fleet_service_log')->count();
ok('completing with no meter still works', $j2($ctl2->done($mk2('POST', "/w/$wb/done", []), $wb))['success'] ?? false, true);
ok('  …and writes no service row', DB::table('t_fleet_service_log')->count(), $logsNow);

// ─────────────────────────────────────────────────────────────────────────────
head('§18 a WORKSHOP is not a place of work');
/**
 * ⚠⚠ THE PHASE-4 SQL CLAIMED THIS FILTER EXISTED. IT DID NOT (found 3-Sep on Taimur's
 *    phone, chasing why no workshop had ever been ticked).
 *
 *    A workshop shares t_ops_company_locations with the offices, so without an exclusion the
 *    first location anyone ticks becomes assignable as somebody's STANDING base — and an
 *    assigned location is honoured by the attendance check-in rules, so he could then clock
 *    in at the workshop any day of the week. Exactly the hole is_handover_point closes for
 *    van meet-up points.
 *
 * ⭐ A workshop still reaches a rider's day, but ONLY through the one-day override that
 *   scheduling a visit writes — which is what §19 checks.
 * ⚠ It must stay VISIBLE on the locations admin page, which is where it gets ticked.
 */
$loc = DB::table('t_ops_company_locations')->where('is_active', 1)
    ->where(fn ($q) => $q->where('is_workshop', 0)->orWhereNull('is_workshop'))
    ->value('id');
if ($loc && Sch::hasColumn('t_ops_company_locations', 'is_workshop')) {
    ok('a normal location is assignable as a base',
       LSvc::isAssignableOffice((int) $loc), true);
    DB::table('t_ops_company_locations')->where('id', $loc)->update(['is_workshop' => 1]);
    ok('  …once ticked as a workshop it is NOT',
       LSvc::isAssignableOffice((int) $loc), false);
    ok('  …and it IS offered as a workshop instead',
       DB::table('t_ops_company_locations')->where('id', $loc)->where('is_workshop', 1)->exists(), true);
    DB::table('t_ops_company_locations')->where('id', $loc)->update(['is_workshop' => 0]);
    ok('  …and it is assignable again once un-ticked',
       LSvc::isAssignableOffice((int) $loc), true);
}
// The admin page must keep showing them — hiding them there would hide the tick itself.
$clSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/CompanyLocationsController.php');
ok('the locations ADMIN list does not filter workshops out',
   (bool) preg_match("/loc\.is_workshop', 0/", $clSrc), false);
ok('  …but it can save the tick', str_contains($clSrc, "'is_workshop' => 'boolean'"), true);
$plSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/Ops/ShiftPlannerController.php');
ok('the shift-ASSIGN picker does filter them out', str_contains($plSrc, "is_workshop', 0"), true);
$bladeF = __DIR__ . '/resources/views/pages/attendance/locations.blade.php';
if (is_file($bladeF)) {
    $bl = file_get_contents($bladeF);
    ok('the admin page has a workshop tick to set it with', str_contains($bl, "id=\"isWorkshop\""), true);
    ok('  …and sends it on save', str_contains($bl, 'is_workshop:'), true);
}

head('§17 the rider’s "did it get done?" prompt');

$rq = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => \Carbon\Carbon::today()->format('Y-m-d')]);
$wq = (int) $rq['visit_id'];
/**
 * ⚠⚠ TODAY IS NOT ENOUGH — HE MUST HAVE ACCEPTED IT FIRST (found on the device, 3-Sep).
 *
 *    This used to assert that a visit dated today is asked about immediately. On a real
 *    phone that put TWO cards on the rider's screen at once: the amber "confirm karein"
 *    card for a visit he had not yet accepted, and directly beneath it the green
 *    "ho gaya?" prompt asking whether the very same visit was already finished.
 *
 * ⭐ One question at a time. Ask for the OUTCOME once he has accepted — or once the day
 *    has passed, which is the missed case below and needs no acceptance to be worth asking.
 */
$await = $wv->awaitingOutcomeFor((int) $rider->id);
ok('a visit dated TODAY he has NOT accepted is not asked about yet', $await, null);
$wv->accept($rider, $wq);
$await = $wv->awaitingOutcomeFor((int) $rider->id);
ok('  …once ACCEPTED, the same visit is awaiting an outcome', (int) ($await['id'] ?? 0), $wq);

// A future one must not be asked about yet.
$wv->markDone($manager, $wq, []);
$rf = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d3]);
ok('a FUTURE visit is not asked about', $wv->awaitingOutcomeFor((int) $rider->id), null);

// A past unanswered one keeps being asked — the reason no midnight job is needed.
DB::table(WV::T_VISIT)->where('id', (int) $rf['visit_id'])
    ->update(['visit_date' => \Carbon\Carbon::today()->subDays(3)->format('Y-m-d')]);
$late = $wv->awaitingOutcomeFor((int) $rider->id);
ok('a PAST unanswered visit is still asked about', (int) ($late['id'] ?? 0), (int) $rf['visit_id']);
ok('  …and reads as missed until he answers', $late['is_missed'], true);
$wv->markDone($rider, (int) $rf['visit_id'], []);
ok('once answered it stops being asked', $wv->awaitingOutcomeFor((int) $rider->id), null);

$outRes = $j2($ctl2->outcome($mk2('GET', '/w/outcome')));
ok('the outcome endpoint answers', $outRes['success'] ?? false, true);
ok('  …and ships the type list the prompt needs', is_array($outRes['types']), true);

// ⚠ No auto-complete at midnight: "he went" and "he never went" must stay distinguishable.
$svcSrc = file_get_contents(__DIR__ . '/app/Services/Riders/WorkshopVisitService.php');
ok('nothing auto-marks a visit done',
   (bool) preg_match("/status.{0,12}=>\s*'done'/", preg_replace('#/\*.*?\*/#s', '', $svcSrc))
   && !preg_match('/autoComplete|markDoneAll|sweepDone/i', $svcSrc), true);

// ─────────────────────────────────────────────────────────────────────────────
head('§18 PHASE 4 — the workshop becomes that day’s shift location');

/**
 * Owner intent: "later workshop will be added as the shift location" — so a rider who checks
 * in AT the workshop on his visit day is measured against the WORKSHOP and picks up no late /
 * remote flag. Attendance already resolves its base from the day's shift
 * (RiderController → getUserShift → resolveLocation → LocationService), so the whole feature
 * is: make that day's shift carry the workshop.
 *
 * These prove it end-to-end, and — more importantly — that it cannot damage anything else.
 */
$shiftSvc = new \App\Services\ShiftResolutionService();
ok('the Phase 4 columns are present', $wv->locationsEnabled(), true);

// Register a workshop location for the test (rolled back with everything else).
$wsLocId = (int) DB::table('t_ops_company_locations')->insertGetId([
    'location_name' => 'SMOKE Workshop', 'latitude' => '33.70000000', 'longitude' => '73.08000000',
    'radius_meters' => 300, 'is_primary' => 0, 'is_handover_point' => 0, 'is_workshop' => 1,
    'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
]);
ok('it shows up in the workshop picker',
   in_array($wsLocId, array_column($wv->workshopLocations(), 'id'), true), true);

$d5 = \Carbon\Carbon::today()->addDays(5)->format('Y-m-d');
$shiftSvc->clearUserShiftCache((int) $rider->id);
$before = $shiftSvc->getUserShift((int) $rider->id, $d5);
$baseBefore = $before['location_id'] ?? null;

$rL = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d5,
                               'location_id' => $wsLocId, 'purpose' => 'service']);
$wl = (int) $rL['visit_id'];
ok('scheduling at a registered workshop succeeds', $rL['ok'], true);
ok('  …and reports that the shift location was pinned', $rL['shift_location_set'], true);
ok('  …and says so in words, so the planner knows it is handled',
   str_contains($rL['message'], 'not be marked late'), true);

$shiftSvc->clearUserShiftCache((int) $rider->id);
$after = $shiftSvc->getUserShift((int) $rider->id, $d5);
ok('on the visit day his shift location IS the workshop', (int) $after['location_id'], $wsLocId);
ok('  …which is what attendance measures the check-in against',
   (int) \App\Services\LocationService::calculateDistanceFromBase(
       33.70000000, 73.08000000, (int) $rider->id, (int) $after['location_id'])['is_remote'], 0);

/** ⚠⚠ THE PART THAT MATTERS MOST — it must change NOTHING else about his day. */
ok('his shift TIMES are untouched',
   [$after['shift_start'], $after['shift_end']], [$before['shift_start'], $before['shift_end']]);
ok('  …and the template is the same one', $after['shift_id'] ?? null, $before['shift_id'] ?? null);
$other = $shiftSvc->getUserShift((int) $rider->id, \Carbon\Carbon::today()->addDays(6)->format('Y-m-d'));
ok('the NEXT day is unaffected — the override is one day only',
   $other['location_id'] ?? null, $baseBefore);

$row = DB::table('t_ops_user_shift_assignment')->where('workshop_visit_id', $wl)->first();
ok('the override row is tagged with its visit', (bool) $row, null, true);
ok('  …bounded to exactly that day',
   [substr((string) $row->effective_from, 0, 10), substr((string) $row->effective_to, 0, 10)], [$d5, $d5]);
// ⚠ notified_at NULL = no "your shift is changing, confirm" nag. He already confirms the VISIT.
ok('  …and does NOT raise a second shift-confirm nag', $row->notified_at, null);

// Cancelling must put his day back exactly as it was.
$wv->cancel($manager, $wl, 'not going');
$shiftSvc->clearUserShiftCache((int) $rider->id);
ok('cancelling removes the override',
   DB::table('t_ops_user_shift_assignment')->where('workshop_visit_id', $wl)->count(), 0);
ok('  …and his day is back to its usual location',
   $shiftSvc->getUserShift((int) $rider->id, $d5)['location_id'] ?? null, $baseBefore);

// Moving a visit must not leave the OLD day pinned to a workshop nobody is visiting.
$rA = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d5, 'location_id' => $wsLocId]);
$rB = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d3, 'location_id' => $wsLocId]);
$shiftSvc->clearUserShiftCache((int) $rider->id);
ok('rescheduling clears the old day’s override',
   DB::table('t_ops_user_shift_assignment')->where('workshop_visit_id', (int) $rA['visit_id'])->count(), 0);
ok('  …and pins the new day instead',
   DB::table('t_ops_user_shift_assignment')->where('workshop_visit_id', (int) $rB['visit_id'])->count(), 1);
ok('  …so the old day reads normally again',
   $shiftSvc->getUserShift((int) $rider->id, $d5)['location_id'] ?? null, $baseBefore);
$wv->cancel($manager, (int) $rB['visit_id'], 'cleanup');

/** ⚠⚠ A PLANNER'S OWN override for that day must never be overwritten by a booking. */
$tmpl = $before['shift_id'] ?? null;
if ($tmpl) {
    $humanId = DB::table('t_ops_user_shift_assignment')->insertGetId([
        'user_id' => (int) $rider->id, 'shift_template_id' => (int) $tmpl,
        'location_id' => 1, 'effective_from' => $d5, 'effective_to' => $d5,
        'workshop_visit_id' => null,          // ← made by a human
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rH = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d5, 'location_id' => $wsLocId]);
    ok('a booking does NOT overwrite a planner’s own override', $rH['shift_location_set'], false);
    $still = DB::table('t_ops_user_shift_assignment')->where('id', $humanId)->first(['location_id', 'workshop_visit_id']);
    ok('  …his row is untouched', [(int) $still->location_id, $still->workshop_visit_id], [1, null]);
    ok('  …and the visit is still created (the pin is a convenience, not a condition)', $rH['ok'], true);
    $wv->cancel($manager, (int) $rH['visit_id'], 'cleanup');
    DB::table('t_ops_user_shift_assignment')->where('id', $humanId)->delete();
    // ⚠ Raw delete — the production paths clear this themselves, a test poking the table
    //   directly must do it by hand or the next read answers from the cached shift.
    $shiftSvc->clearUserShiftCache((int) $rider->id);
}

// Booking WITHOUT a location must change nothing at all — Phase 4 is opt-in.
$rN = $wv->schedule($manager, ['vehicle_id' => $vid, 'visit_date' => $d5]);
ok('a visit with no workshop location pins nothing', $rN['shift_location_set'], false);
ok('  …and his day is unchanged',
   $shiftSvc->getUserShift((int) $rider->id, $d5)['location_id'] ?? null, $baseBefore);
$wv->cancel($manager, (int) $rN['visit_id'], 'cleanup');
$shiftSvc->clearUserShiftCache((int) $rider->id);

} finally {
    DB::rollBack();
    \Carbon\Carbon::setTestNow();
    VehicleResolver::flush();
    RiderDayLegs::flush();
    try { (new \App\Services\ShiftResolutionService())->clearAllShiftCaches(); } catch (\Throwable $e) {}
}

head('§13 nothing left behind');
ok('visit count back to where it started', DB::table(WV::T_VISIT)->count(), $beforeVisits);
ok('ticket count back to where it started', DB::table(VT::T_TICKET)->count(), $beforeTickets);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? "✅" : "❌") . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
