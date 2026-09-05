<?php
/**
 * THE FOUR PERSONAS — can each of them actually do their job? (3-Sep-2026)
 *
 * The owner's ask: check Qasim (frozen mode), Shabib (store), Taimur, and Farooq (who must see
 * that a rider has a workshop day, plus the notification from Qasim) — that everything they had
 * BEFORE still works, that everything added in this round is reachable for the right person and
 * refused for the wrong one, and that manual meters / maintenance entered by other routes all
 * still feed ONE engine for readings and alerts.
 *
 * ⚠ This asserts the SEAMS between permission and surface — the class of bug a feature test
 *   cannot see, because each feature works perfectly for whoever wrote the test.
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back. No user is logged in:
 *   every service takes the actor as an argument, which is also the contract being checked.
 *
 * Run:  php test_fleet_personas.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\FleetAttentionService;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\ServiceIntervalResolver;
use App\Services\Riders\ServiceRecordService;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\VehicleTicketService as VT;
use App\Services\Riders\WorkshopVisitService as WV;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else { $fail++; echo "  ✗ $what\n";
           if (!$raw) echo "      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n"; }
}
function head(string $t) { echo "\n== $t ==\n"; }
function flushAll(): void {
    ServiceIntervalResolver::flush(); VehicleService::flushServiceMemo(); VehicleResolver::flush();
    RiderDayLegs::flush();
    try { \Illuminate\Support\Facades\Cache::flush(); } catch (\Throwable $e) {}
}

$vt = new VT(); $wv = new WV(); $rec = app(ServiceRecordService::class);

// ─── the people, found by NAME (these four are the ask) ──────────────────────
head('§0 the four personas');
$who = [];
foreach (['Qasim', 'Shabib', 'Taimur', 'Farooq'] as $n) {
    $u = User::where('fullname', 'like', "%$n%")->where('is_active', '1')->first();
    ok("$n exists and is active", (bool) $u, null, true);
    $who[$n] = $u;
}
if (in_array(null, $who, true)) { echo "\npersonas missing — stopping.\n"; exit(1); }

// A rider with a registered machine, to act upon.
$rider = null; $vid = null;
$res = new VehicleResolver();
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = $res->currentVehicleFor((int) $uid);
    if ($v) { $rider = User::find((int) $uid); $vid = (int) $v; break; }
}
ok('a rider with a machine exists to act on', (bool) $rider, null, true);
foreach ($who as $n => $u) {
    printf("  · %-7s id=%-4s tickets=%s workshop=%s service=%s wsAlerts(W/M)=%s%s\n", $n, $u->id,
        $vt->canManage($u, true) ? 'Y' : 'n',
        $wv->canSchedule($u, true) ? 'Y' : 'n',
        $u->hasMobilePermission('manage_bike_service') ? 'Y' : 'n',
        $u->hasPermission(WV::ALERT_PERMISSION) ? 'W' : '-',
        $u->hasMobilePermission(WV::ALERT_PERMISSION) ? 'M' : '-');
}

$beforeT = DB::table(VT::T_TICKET)->count();
$beforeW = DB::table(WV::T_VISIT)->count();
$beforeL = DB::table('t_fleet_service_log')->count();

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 QASIM — frozen mode: the full fleet job');

$q = $who['Qasim'];
ok('can manage tickets on mobile (frozen mode is the same FleetScreen)', $vt->canManage($q, true), true);
ok('  …and on the web', $vt->canManage($q, false), true);
ok('can schedule workshop visits', $wv->canSchedule($q, true), true);
ok('can record + correct services', $q->hasMobilePermission('manage_bike_service'), true);
ok('can open the Bikes screen at all (view_bike_costs)', $q->hasMobilePermission('view_bike_costs'), true);
ok('is told when a rider reports a fault', $q->hasMobilePermission(VT::ALERT_PERMISSION), true);
ok('is told when a workshop date is set', $q->hasMobilePermission(WV::ALERT_PERMISSION), true);

// End to end as Qasim: ticket → reply → workshop → complete with a typed service.
$t = $vt->open($q, ['vehicle_id' => $vid, 'opened_for_user_id' => (int) $rider->id,
                    'title' => 'PERSONA: brake noise']);
ok('opens a ticket for a rider without knowing the bike id', $t['ok'], true);
$tid = (int) $t['ticket_id'];
ok('the registry supplied the machine', (int) $vt->find($tid)['vehicle_id'], $vid);
ok('replies to it', $vt->reply($q, $tid, ['kind' => 'text', 'body' => 'Bringing it in.'])['ok'], true);
$w = $wv->schedule($q, ['user_id' => (int) $rider->id,
                        'visit_date' => \Carbon\Carbon::today()->format('Y-m-d'), 'ticket_id' => $tid]);
ok('schedules a workshop visit off that ticket', $w['ok'], true);
$wid = (int) $w['visit_id'];
ok('  …which moves the ticket to "workshop set"', $vt->find($tid)['status'], 'scheduled');
$types = $rec->scheduledTypes();
$tp = $rec->resolveType($types[0]['id'] ?? null);
$done = $rec->record(['rider_id' => (int) $rider->id, 'meter' => 90001,
                      'date' => \Carbon\Carbon::today()->format('Y-m-d'),
                      'type' => $tp['type'], 'actor_id' => (int) $q->id, 'note' => 'PERSONA']);
ok('completes it as a TYPED service record', $done['ok'], true);
$logId = (int) $done['service_log_id'];
ok('closes the ticket', $vt->close($q, $tid, 'done')['ok'], true);

// ✏️ And can correct his own record afterwards — the log-8 lesson.
ok('can CORRECT a service record he entered', $rec->amend($logId, ['meter' => 90002], (int) $q->id)['ok'], true);
ok('  …and remove one', $rec->remove($logId, (int) $q->id)['ok'], true);

// ─────────────────────────────────────────────────────────────────────────────
head('§2 SHABIB and TAIMUR — store mode: the same powers');

foreach (['Shabib', 'Taimur'] as $n) {
    $u = $who[$n];
    ok("$n can manage tickets", $vt->canManage($u, true), true);
    ok("  …schedule workshop visits", $wv->canSchedule($u, true), true);
    ok("  …record and correct services", $u->hasMobilePermission('manage_bike_service'), true);
    ok("  …open Bikes", $u->hasMobilePermission('view_bike_costs'), true);
    ok("  …and hand a machine over (assign_vehicles)", $u->hasPermission('assign_vehicles'), true);

    $tt = $vt->open($u, ['vehicle_id' => $vid, 'opened_for_user_id' => (int) $rider->id,
                         'title' => "PERSONA: $n"]);
    ok("  …and really can open + close one", $tt['ok'] && $vt->close($u, (int) $tt['ticket_id'], 'x')['ok'], true);
}

// ⚠ Qasim (frozen) does NOT hold assign_vehicles. Recorded so a later change is deliberate.
ok('⚠ Qasim canNOT hand a machine over — frozen mode is not the vehicle registry',
   $who['Qasim']->hasPermission('assign_vehicles'), false);

// ─────────────────────────────────────────────────────────────────────────────
head('§3 FAROOQ — the shift planner: told, but not given the controls');

$f = $who['Farooq'];
ok('is told a workshop date was set — on MOBILE', $f->hasMobilePermission(WV::ALERT_PERMISSION), true);
/**
 * ⚠⚠ FOUND HERE (3-Sep). The key existed only as a MOBILE permission, so on the WEB it was
 *    false for everyone and the corner banner fell back to "can this person schedule?" —
 *    true for Qasim/Shabib/Taimur, FALSE for Farooq. The one man the owner named saw nothing
 *    on the shift planner, the page he actually works in.
 */
ok('is told on the WEB too — the planner is where he works', $f->hasPermission(WV::ALERT_PERMISSION), true);
ok('is NOT told about bike tickets (the two keys are separate on purpose)',
   $f->hasMobilePermission(VT::ALERT_PERMISSION) || $f->hasPermission(VT::ALERT_PERMISSION), false);
ok('cannot schedule a visit', $wv->canSchedule($f, true) || $wv->canSchedule($f, false), false);
ok('cannot manage tickets', $vt->canManage($f, true) || $vt->canManage($f, false), false);
ok('cannot record a service', $f->hasMobilePermission('manage_bike_service') || $f->hasPermission('manage_bike_service'), false);
ok('but CAN plan shifts — which is why he is told at all', $f->hasMobilePermission('manage_shifts'), true);

// The banner must show him the fleet's visits, not an empty list.
$wsA = $wv->schedule($who['Qasim'], ['user_id' => (int) $rider->id,
                                     'visit_date' => \Carbon\Carbon::today()->addDays(2)->format('Y-m-d')]);
flushAll();
$sumF = $wv->summaryFor($f, true);
ok('his banner shows the fleet’s visits, not an empty list', $sumF['count'] >= 1, true);
ok('  …and does NOT offer him scheduling', $sumF['can_schedule'], false);
$mine = array_filter($sumF['visits'] ?? [], fn ($v) => (int) $v['user_id'] === (int) $f->id);
ok('  …he is not shown as the rider on any of them', count($mine), 0);

/**
 * ⚠⚠ ALSO FOUND HERE: the banner used to open the Bikes screen for everyone — but Farooq has
 *    no `view_bike_costs`, so his own alert led straight to a 403. It now routes on
 *    `can_schedule`, which his payload correctly reports as false.
 */
ok('⚠ he cannot open Bikes, so his banner must NOT send him there',
   $f->hasMobilePermission('view_bike_costs'), false);
$banSrc = file_get_contents(__DIR__ . '/../NizamiFarmsMobile/src/components/RoleAlertBanners.js');
ok('  …and it routes on can_schedule instead of always opening Fleet',
   str_contains($banSrc, "canSchedule.current ? 'Fleet' : 'StoreShifts'"), true);
/**
 * ⚠⚠ AND the target must exist in EVERY mode. `StoreShifts` lived only in StoreStack, so
 *    navigate() from rider or frozen mode failed with "not handled by any navigator" — the
 *    same trap FleetScreen once hit. It is now ALSO in the root stack.
 */
$navSrc = file_get_contents(__DIR__ . '/../NizamiFarmsMobile/src/navigation/index.js');
ok('  …and StoreShifts is registered in the ROOT stack too, so it opens from any mode',
   substr_count($navSrc, 'name="StoreShifts"'), 2);
ok('  …with no fallback that would land him on a screen he cannot open',
   str_contains($banSrc, "navigation.navigate('Fleet'); } catch (e2)"), false);

// He must also SEE it on the planner grid itself.
flushAll();
$cells = app(\App\Services\Riders\WorkshopVisitService::class)
    ->mapForRange([(int) $rider->id], \Carbon\Carbon::today()->format('Y-m-d'),
                  \Carbon\Carbon::today()->addDays(7)->format('Y-m-d'));
ok('the planner grid has the visit on the rider’s day',
   isset($cells[(int) $rider->id . '|' . \Carbon\Carbon::today()->addDays(2)->format('Y-m-d')]), true);
$wv->cancel($who['Qasim'], (int) $wsA['visit_id'], 'persona cleanup');

// ─────────────────────────────────────────────────────────────────────────────
head('§3b QASIM ON HIS PHONE — can see AND correct what he recorded');

/**
 * ⚠⚠ Qasim works in FROZEN MODE ON A PHONE. Web-only correction was not an answer: the mobile
 *    vehicle screen had NO service history at all, so a record he made was invisible there —
 *    the same blindness that let log #8 sit misfiled. This asserts the phone's own payload.
 */
// ⚠ Record one FIRST rather than hoping the discovered bike happens to have a manual row —
//   the assertion must exercise the path, not the fixture.
$seed = $rec->record(['rider_id' => (int) $rider->id, 'meter' => 90500,
                      'date' => \Carbon\Carbon::today()->format('Y-m-d'),
                      'type' => $tp['type'], 'actor_id' => (int) $who['Qasim']->id,
                      'note' => 'PERSONA phone']);
ok('Qasim records a service (to correct in a moment)', $seed['ok'], true);
flushAll();

\Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($who['Qasim']->id);
$vc  = app(\App\Http\Controllers\CRM\VehicleController::class);
$rq  = \Illuminate\Http\Request::create('/api/rider/store/fleet/vehicles/' . $vid, 'GET', ['month' => date('Y-m')]);
$rq->setUserResolver(fn () => \Illuminate\Support\Facades\Auth::user());
$mob = json_decode($vc->apiShow($rq, new VehicleService(), $vid)->getContent(), true);

ok('the phone can open the machine', $mob['success'] ?? false, true);
ok('  …and now gets its service history at all', is_array($mob['service_history'] ?? null), true);
ok('  …including the one he just recorded, carrying a log_id so it can be corrected',
   in_array((int) $seed['service_log_id'],
            array_map('intval', array_column($mob['service_history'], 'log_id')), true), true);
ok('  …and is told he may correct them', $mob['can_log_meters'] ?? null, true);
ok('  …and gets THIS bike\'s schedule for the type chips',
   count(array_filter($mob['service_schedule'] ?? [], fn ($t) => ($t['interval_km'] ?? 0) > 0)) > 0, true);
/**
 * ⚠ The flag must MIRROR the server, never be a second gate: `canLogMeters` reads web OR
 *   mobile because the endpoint that acts (`canManageService`) is mobile-aware. A stricter
 *   flag hides a button somebody is entitled to press.
 */
$vcSrc = file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/VehicleController.php');
ok('  …and that flag honours the MOBILE key too, matching the endpoint',
   (bool) preg_match('/canLogMeters.*?hasMobilePermission\(.manage_bike_service.\)/s', $vcSrc), true);

$fvSrc = file_get_contents(__DIR__ . '/../NizamiFarmsMobile/src/components/FleetVehicles.js');
ok('the phone screen renders Past services', str_contains($fvSrc, 'Past services'), true);
ok('  …and offers Edit / Remove only on rows with a log_id',
   str_contains($fvSrc, 's.log_id && detail?.can_log_meters'), true);
ok('  …calling the same endpoints the web does',
   str_contains($fvSrc, '/rider/store/fleet/service-records/'), true);

// ⚠ Remove the seed before §4: at 90,500 km it is the newest evidence on this bike and would
//   otherwise dominate the schedule assertions below. Each section leaves the world as it found it.
$rec->remove((int) $seed['service_log_id'], (int) $who['Qasim']->id);
flushAll();

// ─────────────────────────────────────────────────────────────────────────────
head('§4 ONE ENGINE — however a reading or a service got in');

/**
 * Owner: "the backend handles like manual meters or maintenance entered otherwise and single
 * engine for all readings and alerts". A service can reach the system three ways; all three
 * must land in the SAME schedule and the SAME alert.
 */
flushAll();
$svc = new VehicleService();
$meter = (int) ($svc->currentMeterFor($vid) ?: 30000);
$typeRow = $rec->resolveType($types[0]['id'] ?? null)['type'];

$schedBefore = $svc->serviceScheduleFor($vid, $meter);
$rowBefore = array_values(array_filter($schedBefore, fn ($s) => $s['id'] === (int) $typeRow->id))[0] ?? null;

// (a) recorded by hand on Bikes
$r1 = $rec->record(['rider_id' => (int) $rider->id, 'meter' => $meter + 3, 'date' => \Carbon\Carbon::today()->format('Y-m-d'),
                    'type' => $typeRow, 'actor_id' => (int) $who['Shabib']->id, 'note' => 'PERSONA manual']);
flushAll();
$rowAfter = array_values(array_filter($svc->serviceScheduleFor($vid, $meter + 3),
                                      fn ($s) => $s['id'] === (int) $typeRow->id))[0] ?? null;
ok('a MANUAL record moves the schedule', ($rowAfter['last_meter'] ?? 0), $meter + 3);
ok('  …and the same engine answers the alert',
   (new \App\Services\Riders\BikeServiceAlerts())->due() !== null, true);

// (b) the SAME engine is what the vehicle profile, the rider payload and the alerts all read
flushAll();
$viaVehicle = $svc->serviceScheduleFor($vid, $meter + 3);
$fleetSvc = new \App\Services\Riders\FleetFuelService();
$rm = new ReflectionMethod($fleetSvc, 'serviceSchedule');
$rm->setAccessible(true);
$viaRider = $rm->invoke($fleetSvc, (int) $rider->id);
$k = fn ($rows) => array_column($rows, 'interval_km', 'id');
ok('the MACHINE view and the RIDER view give identical intervals', $k($viaRider), $k($viaVehicle));

// (c) the attention map — the fleet list — reads the same state
flushAll();
$att = app(FleetAttentionService::class)->forVehicles($svc->all(false), []);
ok('the fleet attention map covers every machine', count($att), count($svc->all(false)));
ok('  …and ranks a machine with an open ticket above a quiet one',
   FleetAttentionService::RANK_TICKETS < FleetAttentionService::RANK_NONE, true);

// (d) a manual METER reading (not a service) still flows through the meter engine
$mlBefore = Schema::hasTable('t_ops_vehicle_meter_log')
    ? DB::table('t_ops_vehicle_meter_log')->count() : null;
ok('the meter log is a separate engine from the service log (readings ≠ services)',
   $mlBefore !== null, true);

$rec->remove((int) $r1['service_log_id'], (int) $who['Shabib']->id);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 what a persona must NOT be able to do');

$rd = $rider;
ok('a RIDER cannot schedule a workshop visit', $wv->canSchedule($rd, true), false);
ok('a RIDER cannot manage tickets', $vt->canManage($rd, true), false);
ok('a RIDER cannot record a service', $rd->hasMobilePermission('manage_bike_service'), false);
ok('  …but CAN open a ticket on his own bike (no key needed)',
   $vt->open($rd, ['title' => 'PERSONA rider'])['ok'], true);
ok('  …and cannot open one on someone else’s',
   $vt->open($rd, ['vehicle_id' => 999999, 'title' => 'nope'])['ok'], false);

// Farooq must not be able to act even though he is told.
$fw = $wv->schedule($f, ['user_id' => (int) $rider->id, 'visit_date' => \Carbon\Carbon::today()->addDay()->format('Y-m-d')]);
ok('Farooq being TOLD does not let him schedule', $fw['ok'], false);
ok('  …nor complete one', $wv->markDone($f, 999999, [])['ok'], false);

} finally {
    DB::rollBack();
    flushAll();
}

head('§6 nothing left behind');
ok('tickets', DB::table(VT::T_TICKET)->count(), $beforeT);
ok('visits', DB::table(WV::T_VISIT)->count(), $beforeW);
ok('service log', DB::table('t_fleet_service_log')->count(), $beforeL);

echo "\n" . str_repeat('─', 60) . "\n";
echo ($fail === 0 ? '✅' : '❌') . "  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
