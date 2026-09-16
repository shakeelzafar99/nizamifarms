<?php
/**
 * ✅ RULING 6 — "AND IS THE COMPLAINT FIXED?" (15-Sep-2026).
 *
 * The owner's ruling was: do NOT auto-close a ticket when a workshop visit is marked done.
 * Two reasons, both read out of the code rather than argued:
 *   1. `completionGate` lets the RIDER mark his own visit done — so auto-closing would let him
 *      close his own complaint, breaking "only a manager closes";
 *   2. before Part B a visit did not know WHICH ticket it fixed, so it would have had to close
 *      every open one on the machine.
 * Instead the manager is ASKED, at the moment he is already deciding what happened, with the
 * issues the visit went in for PRE-TICKED (which is what Part B made possible).
 *
 * ⚠⚠ These drive the CONTROLLER, because that is where the closing lives — a service-level test
 *    would prove nothing about it. `setUserResolver` is used rather than `loginUsingId` so both
 *    personas can be exercised in ONE process: only one user may be authenticated per process,
 *    and a manager check written after a rider check silently runs as the rider.
 *
 * What these prove:
 *   §1 a manager's done-form is OFFERED the machine's open issues, pre-ticked by coverage;
 *   §2 a RIDER is offered NOTHING — the ruling, enforced by the server and not by the form;
 *   §3 ticking closes them, through the one close engine, with the outcome note as the note;
 *   §4 unticked issues stay open;
 *   §5 a RIDER posting ids closes nothing, whatever his client sends;
 *   §6 an id that is not open on THIS machine is ignored;
 *   §7 the visit is still done even when a close fails — the two are not one transaction;
 *   §8 nothing left behind.
 *
 * Run:  php test_done_closes_issues.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CRM\WorkshopVisitController;
use App\Models\User;
use App\Services\Riders\VehicleTicketService as VT;
use App\Services\Riders\WorkshopVisitService as WV;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function section(string $t) { echo "\n== $t ==\n"; }

$wv = app(WV::class);
$vt = app(VT::class);
ok('the tables exist', $wv->available() && $vt->available(), true);
if (!$wv->available()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

$NOW = Carbon::parse('2026-09-15 14:00:00');
Carbon::setTestNow($NOW);

/** Call a controller action as a given user, without touching the auth singleton. */
$call = function (string $method, array $args, $user, array $body = [], string $verb = 'POST') {
    $req = Request::create('/x', $verb, $body);
    $req->setUserResolver(fn () => $user);
    $c = app(WorkshopVisitController::class);
    $res = $c->{$method}(...array_merge([$req], $args));
    return [json_decode($res->getContent(), true), $res->getStatusCode()];
};

section('§0 fixtures');
$planner = null; $rider = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if (!$planner && $wv->canApprove($u) && $wv->canSchedule($u) && $vt->canManage($u)) $planner = $u;
}
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $u = User::find((int) $uid);
    if ($u && !$vt->canManage($u) && !$wv->canSchedule($u)) { $rider = $u; break; }
}
ok('a manager who books, plans and manages tickets exists', (bool) $planner, null, true);
ok('a plain rider exists', (bool) $rider, null, true);
if (!$planner || !$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }
echo "  · manager={$planner->id} rider={$rider->id}\n";

$beforeV = DB::table(WV::T_VISIT)->count();
$beforeT = DB::table(VT::T_TICKET)->count();

DB::beginTransaction();
try {

$vid = (int) DB::table('t_ops_vehicle')->insertGetId([
    'vtype' => 'bike', 'reg_no' => 'ZDN-900', 'is_company' => 1, 'is_active' => 1,
    'created_at' => $NOW, 'updated_at' => $NOW]);
$other = (int) DB::table('t_ops_vehicle')->insertGetId([
    'vtype' => 'bike', 'reg_no' => 'ZDN-901', 'is_company' => 1, 'is_active' => 1,
    'created_at' => $NOW, 'updated_at' => $NOW]);
DB::table('t_ops_vehicle_assignment')->insert([
    'vehicle_id' => $vid, 'user_id' => (int) $rider->id,
    'assigned_on' => $NOW->copy()->subDays(10)->format('Y-m-d'), 'assigned_by' => (int) $planner->id,
    'created_at' => $NOW, 'updated_at' => $NOW]);

$mk = function (int $v, string $title) use ($rider, $NOW): int {
    return (int) DB::table(VT::T_TICKET)->insertGetId([
        'vehicle_id' => $v, 'opened_by' => (int) $rider->id, 'opened_for_user_id' => (int) $rider->id,
        'category' => 'problem', 'urgent' => 0, 'title' => $title, 'status' => 'acknowledged',
        'first_response_at' => $NOW->copy()->subDay(),
        'opened_at' => $NOW->copy()->subDays(4), 'last_message_at' => $NOW->copy()->subDays(4),
        'created_at' => $NOW, 'updated_at' => $NOW]);
};
$tCovered   = $mk($vid, 'DONE covered by the trip');
$tAlsoCov   = $mk($vid, 'DONE also covered');
$tUncovered = $mk($vid, 'DONE open but not this trip');
$tForeign   = $mk($other, 'DONE another bike');
$row = fn (int $t) => (array) DB::table(VT::T_TICKET)->where('id', $t)->first();

// Book TODAY, covering two of the three.
$r = $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->format('Y-m-d'), 'purpose' => 'repair',
    'ticket_ids' => [$tCovered, $tAlsoCov], 'confirm_replace' => 1]);
ok('a visit is booked covering two of the three', $r['ok'], true);
$visit = (int) $r['visit_id'];

// ─────────────────────────────────────────────────────────────────────────────
section('§1 the manager is OFFERED the issues, pre-ticked by coverage');

[$types] = $call('visitTypes', [$visit], $planner, [], 'GET');
ok('the endpoint answers him', $types['success'] ?? null, true);
ok('  …and says he may close tickets', $types['can_close_tickets'] ?? null, true);
$offered = collect($types['closeable_tickets'] ?? [])->keyBy('id');
ok('all three open issues on the machine are offered', $offered->count(), 3);
ok('  …and none from another bike', $offered->has($tForeign), false);
ok('⭐ the two the trip was FOR arrive ticked',
   [$offered[$tCovered]['covered_by_this_visit'], $offered[$tAlsoCov]['covered_by_this_visit']],
   [true, true]);
/**
 * ⚠ The third is offered but NOT ticked. The trip may have happened to fix it, but a manager
 *   tapping through must not close a complaint the bike never went in for.
 */
ok('  …and the one it was NOT for does not', $offered[$tUncovered]['covered_by_this_visit'], false);

// ─────────────────────────────────────────────────────────────────────────────
section('§2 a RIDER is asked NOTHING — the ruling, enforced by the server');

[$rt] = $call('visitTypes', [$visit], $rider, [], 'GET');
ok('the endpoint still answers him (he closes the VISIT)', $rt['success'] ?? null, true);
ok('  …but says he may not close tickets', $rt['can_close_tickets'] ?? null, false);
ok('  …and offers him NONE', count($rt['closeable_tickets'] ?? []), 0);

// ─────────────────────────────────────────────────────────────────────────────
section('§5 …and a rider POSTING ids closes nothing');

/**
 * ⚠⚠ The form omitting the question is not the rule — this is. A rider on an old, crafted or
 *    simply confused client may post anything; `VehicleTicketService::close()` re-checks him.
 */
[$rd, $rcode] = $call('done', [$visit], $rider,
    ['happened' => 1, 'outcome_note' => 'rider closing out',
     'close_ticket_ids' => [$tCovered, $tAlsoCov]]);
ok('his "it happened" is accepted', $rd['success'] ?? null, true);
ok('  …the visit IS done', $wv->find($visit)['status'], 'done');
ok('  ⭐ …but he closed NOTHING', $rd['tickets_closed'] ?? null, 0);
ok('    …the issues are still open',
   [$row($tCovered)['status'], $row($tAlsoCov)['status']], ['acknowledged', 'acknowledged']);

// ─────────────────────────────────────────────────────────────────────────────
section('§3 a MANAGER ticking them closes them, with the outcome note');

// A second visit, since the first is now done.
$r2 = $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->format('Y-m-d'), 'purpose' => 'repair',
    'ticket_ids' => [$tCovered, $tAlsoCov], 'confirm_replace' => 1]);
$visit2 = (int) $r2['visit_id'];

[$md] = $call('done', [$visit2], $planner,
    ['happened' => 1, 'outcome_note' => 'Chain and sprocket replaced',
     'close_ticket_ids' => [$tCovered]]);
ok('the visit is marked done', $md['success'] ?? null, true);
ok('  …and it says one issue was closed', $md['tickets_closed'] ?? null, 1);
ok('    …reported in the message too', str_contains($md['message'] ?? '', '1 issue closed'), true);
ok('the ticked issue is CLOSED', $row($tCovered)['status'], 'closed');
ok('  …by him', (int) $row($tCovered)['closed_by'], (int) $planner->id);
/**
 * ⭐ The outcome note IS the close note. He has already typed what happened, and asking him to
 *   type it twice is how close notes end up empty.
 */
ok('  …with the outcome note as the close note',
   $row($tCovered)['close_note'], 'Chain and sprocket replaced');

section('§4 an UNTICKED issue simply stays open');
ok('the other covered issue is untouched', $row($tAlsoCov)['status'], 'acknowledged');
ok('  …and so is the uncovered one', $row($tUncovered)['status'], 'acknowledged');

// ─────────────────────────────────────────────────────────────────────────────
section('§6 an id that is not open on THIS machine is ignored');

$r3 = $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->format('Y-m-d'), 'purpose' => 'repair',
    'ticket_ids' => [$tAlsoCov], 'confirm_replace' => 1]);
$visit3 = (int) $r3['visit_id'];
[$md3] = $call('done', [$visit3], $planner,
    ['happened' => 1, 'outcome_note' => 'tyre',
     // the other bike's issue, the one already closed, and a nonexistent id
     'close_ticket_ids' => [$tForeign, $tCovered, 999999999, $tAlsoCov]]);
ok('the visit is done', $md3['success'] ?? null, true);
ok('  …and exactly ONE legitimate issue closed', $md3['tickets_closed'] ?? null, 1);
ok('another bike’s issue is untouched', $row($tForeign)['status'], 'acknowledged');
ok('  …and the legitimate one closed', $row($tAlsoCov)['status'], 'closed');

// ─────────────────────────────────────────────────────────────────────────────
section('§7 a close that fails does not un-do the visit');

$r4 = $wv->schedule($planner, ['vehicle_id' => $vid, 'user_id' => (int) $rider->id,
    'visit_date' => $NOW->format('Y-m-d'), 'purpose' => 'repair', 'confirm_replace' => 1]);
$visit4 = (int) $r4['visit_id'];
[$md4] = $call('done', [$visit4], $planner,
    ['happened' => 1, 'close_ticket_ids' => [999999998]]);
/**
 * ⚠ The visit IS done. Turning a failed close into an error would make a manager mark the same
 *   visit done twice — and the second attempt is refused, so he would believe nothing worked.
 */
ok('the visit is done regardless', $md4['success'] ?? null, true);
ok('  …with nothing closed', $md4['tickets_closed'] ?? null, 0);
ok('  …and the visit really is done', $wv->find($visit4)['status'], 'done');

} finally {
    DB::rollBack();
    Carbon::setTestNow();
}

section('§8 nothing left behind');
ok('visit count back where it started', DB::table(WV::T_VISIT)->count(), $beforeV);
ok('ticket count back where it started', DB::table(VT::T_TICKET)->count(), $beforeT);
ok('no staged vehicles survived',
   DB::table('t_ops_vehicle')->where('reg_no', 'like', 'ZDN-90%')->count(), 0);

echo "\n────────────────────────────────────────────\n";
echo ($fail === 0 ? "✅  " : "❌  ") . "$pass passed, $fail failed\n";
echo "────────────────────────────────────────────\n";
exit($fail === 0 ? 0 : 1);
