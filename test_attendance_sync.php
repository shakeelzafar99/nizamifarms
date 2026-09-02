<?php
/**
 * DOES ATTENDANCE STAY IN SYNC WITH THE VEHICLE REGISTRY? (Sep-01 2026)
 *
 * The owner's question after the handover work: "can attendance give out false alerts
 * or reports because of this, or does it follow the same engine so everything is in
 * sync?" It did not, in four places. These pin the fixes.
 *
 *   B1  DayChecksService asked the registry to excuse a rider who held no machine that
 *       day — but read his id out of a context array that never carried one. `(int)
 *       null` is user 0, who owns no rider profile, so the excusal returned false for
 *       everyone, every day, since it shipped. Dead code that looked alive.
 *   B2  The DAILY ⛽ tick had no handover rule at all, while the MONTH "Meter" column
 *       has always skipped a handover day for BOTH riders. The same page accused a man
 *       in the row and excused him in the column beside it.
 *   B3  `workIssueDays` filtered a whole MONTH by `t_ops_rider_profile.company_bike` —
 *       a column pinned to what the rider holds RIGHT NOW by syncCompanyBikeFlag. Every
 *       handover silently rewrote his history in the In-flags column.
 *   B4  Its continuity baseline survived a handover with no closing reading, so the
 *       NEXT day's reading — taken on a different bike — was reported as a huge gap.
 *
 * ⚠ Undo stack is unwound in REVERSE from a shutdown function. Do not pipe through
 *   `head`: a closed stdout kills PHP and the restore never runs.
 *
 * Run:  php test_attendance_sync.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\DayChecksService;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\WorkJourneyService;
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
    VehicleService::flushServiceMemo(); VehicleResolver::flush(); RiderDayLegs::flush();
}

$UNDO = [];
$undo = function (callable $fn) use (&$UNDO) { $UNDO[] = $fn; };
register_shutdown_function(function () use (&$UNDO) {
    echo "\n-- restoring --\n";
    foreach (array_reverse($UNDO) as $fn) {
        try { $fn(); } catch (\Throwable $e) { echo "  ! undo failed: " . $e->getMessage() . "\n"; }
    }
    echo "-- restored --\n";
});
$mkVehicle = function (string $nick, int $isCompany) use ($undo): int {
    $id = DB::table(VehicleService::T_VEHICLE)->insertGetId([
        'vtype' => 'bike', 'nickname' => $nick, 'is_company' => $isCompany,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $undo(fn () => DB::table(VehicleService::T_VEHICLE)->where('id', $id)->delete());
    $undo(fn () => DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $id)->delete());
    return (int) $id;
};

const A = 82;    // Wajid       — holds nothing today
const B = 83;    // Abdul Malik — holds nothing today
const C = 93;    // Sabir       — holds nothing today
const ACTOR = 79;
$DAY   = '2026-06-10';        // a quiet past date, safely inside no real assignment
$NEXT  = '2026-06-11';

$dc  = new DayChecksService();
$svc = new VehicleService();
$res = new VehicleResolver();
$wj  = new WorkJourneyService();

/** The ⛽ verdict for one day, with whatever context we choose to hand over. */
$meterOk = function (array $extra, ?string $on = null) use ($dc, $DAY) {
    $v = $dc->build([
        'login_time' => '09:00:00', 'logout_time' => '19:00:00',
        'role_name' => 'Rider', 'company_bike' => true,
        'meter_start' => null, 'meter_end' => null, 'meter_distance' => null,
        'road_distance_km' => null, 'gps_distance' => null,
        'checkout_info' => null, 'home_journey' => null, 'work_journey' => null,
        'checkout_unlock' => null, 'checkout_counted' => null,
    ] + $extra, [], $on ?: $DAY, 10.0);
    return $v['meter_ok'] ?? null;
};

// ---------------------------------------------------------------- fixtures
// ⚠⚠ EVERY assignment row is created BEFORE the first assertion. `machineHoldersOn`
//    memoizes per DATE, and a row inserted after that date has been asked about once
//    is invisible for the rest of the process — which is exactly how this suite first
//    "failed". (The memo now lives on the class and `flush()` clears it, but building
//    fixtures up front is the habit that keeps a suite honest either way.)
//
// A (82): holds $vSwap 01→10 June, hands it to B on the 10th, then nothing.
// B (83): receives $vSwap on the 10th, holds it to the 30th.
// C (93): holds $vOwnAll for the whole month and never hands it over.
$vSwap   = $mkVehicle('TEST swapped mid-June', 1);
$vHeld   = $mkVehicle('TEST held all June', 1);
DB::table(VehicleService::T_ASSIGN)->insert([
    ['vehicle_id' => $vSwap, 'user_id' => A, 'assigned_on' => '2026-06-01',
     'released_on' => $DAY, 'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now()],
    ['vehicle_id' => $vSwap, 'user_id' => B, 'assigned_on' => $DAY,
     'released_on' => '2026-06-30', 'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now()],
    ['vehicle_id' => $vHeld, 'user_id' => C, 'assigned_on' => '2026-06-01',
     'released_on' => '2026-06-30', 'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now()],
]);
flushAll();

// ============================================================================
head('§1 B1 — the registry excusal was dead code, and now fires');

// A quiet date nobody in this suite holds anything on.
$FREE = '2026-05-05';
ok('a rider with no machine that day IS excused by the registry',
   $res->meterExcusedOn(A, $FREE), true);
ok('   …but user 0 never was — the value the service used to compute',
   $res->meterExcusedOn(0, $FREE), false);

ok('WITHOUT user_id the day is judged (the old, broken behaviour)', $meterOk([], $FREE), false);
ok('WITH user_id the same day is excused', $meterOk(['user_id' => A], $FREE), true);

head('§1b a rider who DID hold a machine is still judged — the excusal is not a blanket');
ok('he held a machine that day', isset($res->machineHoldersOn($DAY)[C]), true);
ok('it is not a handover day for him', $res->isTransferDay($vHeld, $DAY), false);
ok('so a missing reading is still an issue', $meterOk(['user_id' => C]), false);

// ============================================================================
head('§2 B2 — the handover DAY is excused on BOTH sides, like the month column');

ok('the registry calls it a transfer day', $res->isTransferDay($vSwap, $DAY), true);
ok('BOTH riders count as holders that day (by design)',
   isset($res->machineHoldersOn($DAY)[A]) && isset($res->machineHoldersOn($DAY)[B]), true, true);
ok('the OUTGOING rider is not accused', $meterOk(['user_id' => A]), true);
ok('the INCOMING rider is not accused', $meterOk(['user_id' => B]), true);
ok('an explicit transfer_day from the web page is honoured',
   $meterOk(['user_id' => B, 'transfer_day' => true]), true);

head('§2b the day AFTER a handover is judged normally again');
ok('the incoming rider is judged on the next day', (function () use ($dc, $NEXT) {
    $v = $dc->build([
        'user_id' => B, 'login_time' => '09:00:00', 'logout_time' => '19:00:00',
        'role_name' => 'Rider', 'company_bike' => true,
        'meter_start' => null, 'meter_end' => null, 'meter_distance' => null,
        'road_distance_km' => null, 'gps_distance' => null,
        'checkout_info' => null, 'home_journey' => null, 'work_journey' => null,
        'checkout_unlock' => null, 'checkout_counted' => null,
    ], [], $NEXT, 10.0);
    return $v['meter_ok'] ?? null;
})(), false);

// ============================================================================
head('§3 B3 — a month is judged day by day, not by today\'s company_bike flag');

$days = $wj->companyHoldingDays([A, B], '2026-06-01', '2026-06-30');
ok('A held a company machine on the 1st', isset($days[A]['2026-06-01']), true);
ok('A still held it on the handover day', isset($days[A][$DAY]), true);
ok('A did NOT hold it the day after', isset($days[A][$NEXT]), false);
ok('B picked it up on the handover day', isset($days[B][$DAY]), true);
ok('B held it the day after', isset($days[B][$NEXT]), true);
ok('B did not hold it before it was his', isset($days[B]['2026-06-01']), false);

head('§3b the answer does not move when the NOW flag is flipped');
$before = json_encode($wj->companyHoldingDays([A, B], '2026-06-01', '2026-06-30'));
$origA = DB::table('t_ops_rider_profile')->where('user_id', A)->value('company_bike');
$undo(fn () => DB::table('t_ops_rider_profile')->where('user_id', A)->update(['company_bike' => $origA]));
DB::table('t_ops_rider_profile')->where('user_id', A)->update(['company_bike' => 1]);
flushAll();
ok('flag ON  → history unchanged', json_encode($wj->companyHoldingDays([A, B], '2026-06-01', '2026-06-30')), $before);
DB::table('t_ops_rider_profile')->where('user_id', A)->update(['company_bike' => 0]);
flushAll();
ok('flag OFF → history unchanged', json_encode($wj->companyHoldingDays([A, B], '2026-06-01', '2026-06-30')), $before);
DB::table('t_ops_rider_profile')->where('user_id', A)->update(['company_bike' => $origA]);

head('§3c a day-override outranks the assignment window, as it does on the sheets');
$ownB = $mkVehicle('TEST own bike override', 0);
if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'vehicle_id')) {
    $attId = DB::table('t_ops_attendance')->insertGetId([
        'user_id' => B, 'attendance_date' => $NEXT, 'login_time' => '09:00:00',
        'vehicle_id' => $ownB,
    ]);
    $undo(fn () => DB::table('t_ops_attendance')->where('id', $attId)->delete());
    flushAll();
    $d2 = $wj->companyHoldingDays([A, B], '2026-06-01', '2026-06-30');
    ok('overridden onto his OWN bike → that day is no longer a company day',
       isset($d2[B][$NEXT]), false);
    ok('   and the untouched days are unaffected', isset($d2[B][$DAY]), true);
    DB::table('t_ops_attendance')->where('id', $attId)->delete();
    flushAll();
} else {
    echo "  ~ skipped: t_ops_attendance has no vehicle_id column\n";
}

// ============================================================================
head('§4 B4 — a handover with no closing reading drops the baseline');

// A holds the machine to the handover day and never records a close; the next day
// he is on a different bike whose odometer reads nothing like the old one.
$attA1 = DB::table('t_ops_attendance')->insertGetId([
    'user_id' => A, 'attendance_date' => $DAY, 'login_time' => '09:00:00',
    'meter_start' => 40000, 'meter_start_source' => 'home',
    'work_expected_by' => $DAY . ' 09:30:00',
]);
$undo(fn () => DB::table('t_ops_attendance')->where('id', $attA1)->delete());
$attA2 = DB::table('t_ops_attendance')->insertGetId([
    'user_id' => A, 'attendance_date' => $NEXT, 'login_time' => '09:00:00',
    'meter_start' => 12000, 'meter_start_source' => 'home',      // a DIFFERENT bike
    'work_expected_by' => $NEXT . ' 09:30:00',
]);
$undo(fn () => DB::table('t_ops_attendance')->where('id', $attA2)->delete());

// Give A a home pin for the range of this test, else the method skips him entirely.
$pinA = DB::table('t_ops_rider_profile')->where('user_id', A)
    ->first(['home_latitude', 'home_longitude']);
$undo(fn () => DB::table('t_ops_rider_profile')->where('user_id', A)->update([
    'home_latitude' => $pinA->home_latitude ?? null, 'home_longitude' => $pinA->home_longitude ?? null,
]));
DB::table('t_ops_rider_profile')->where('user_id', A)
    ->update(['home_latitude' => 33.6844, 'home_longitude' => 73.0479]);
flushAll();

$issues = $wj->workIssueDays([A], '2026-06-01', '2026-06-30');
ok('the handover day itself raises no gap', isset($issues[A]['detail'][$DAY]) &&
   in_array('meter_gap', $issues[A]['detail'][$DAY], true), false);
ok('and the day AFTER is not accused of a 28,000 km jump',
   isset($issues[A]['detail'][$NEXT]) && in_array('meter_gap', $issues[A]['detail'][$NEXT], true), false);

head('§3d the NOW flag cannot widen a tracked rider\'s judged days (tightening)');
// A is KNOWN to the registry (his June windows). Flag him company TODAY and give him
// a junk row on a day OUTSIDE any window — the old code judged flagged riders on
// every day; the registry must now win for anyone it knows.
$origA2 = DB::table('t_ops_rider_profile')->where('user_id', A)->value('company_bike');
$undo(fn () => DB::table('t_ops_rider_profile')->where('user_id', A)->update(['company_bike' => $origA2]));
DB::table('t_ops_rider_profile')->where('user_id', A)->update(['company_bike' => 1]);
$attOut = DB::table('t_ops_attendance')->insertGetId([
    'user_id' => A, 'attendance_date' => '2026-06-27', 'login_time' => '09:00:00',
    'meter_start' => 99999, 'meter_start_source' => 'home',     // absurd vs anything
    'work_expected_by' => '2026-06-27 09:30:00',
]);
$undo(fn () => DB::table('t_ops_attendance')->where('id', $attOut)->delete());
flushAll();
$iF = $wj->workIssueDays([A], '2026-06-01', '2026-06-30');
ok('a flagged-but-not-holding day is NOT judged', isset($iF[A]['detail']['2026-06-27']), false);
DB::table('t_ops_rider_profile')->where('user_id', A)->update(['company_bike' => $origA2]);
DB::table('t_ops_attendance')->where('id', $attOut)->delete();
flushAll();

head('§4b a genuine gap on a NON-handover day is still reported');
$attB1 = DB::table('t_ops_attendance')->insertGetId([
    'user_id' => A, 'attendance_date' => '2026-06-20', 'login_time' => '09:00:00',
    'meter_start' => 12100, 'meter_start_source' => 'home', 'meter_home' => 12200,
    'work_expected_by' => '2026-06-20 09:30:00',
]);
$undo(fn () => DB::table('t_ops_attendance')->where('id', $attB1)->delete());
$attB2 = DB::table('t_ops_attendance')->insertGetId([
    'user_id' => A, 'attendance_date' => '2026-06-21', 'login_time' => '09:00:00',
    'meter_start' => 19999, 'meter_start_source' => 'home',       // 7,799 km overnight
    'work_expected_by' => '2026-06-21 09:30:00',
]);
$undo(fn () => DB::table('t_ops_attendance')->where('id', $attB2)->delete());
// He must hold a company machine on those days for the method to judge them at all.
DB::table(VehicleService::T_ASSIGN)->insert([
    'vehicle_id' => $vHeld, 'user_id' => A, 'assigned_on' => '2026-06-19',
    'released_on' => '2026-06-25', 'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
$issues2 = $wj->workIssueDays([A], '2026-06-01', '2026-06-30');
ok('a real overnight jump is still flagged',
   isset($issues2[A]['detail']['2026-06-21']) &&
   in_array('meter_gap', $issues2[A]['detail']['2026-06-21'], true), true);

// ============================================================================
head('§4c a day-override SKIP also drops the baseline (Sep-01 review #9)');
// 22 Jun: A is overridden onto his OWN bike (not an assignment boundary, so not
// a swap day). 23 Jun he is back on the company machine with a reading far from
// the pre-override close — that gap belongs to whoever rode the machine on the
// 22nd, not to him.
if (\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'vehicle_id')) {
    $att22 = DB::table('t_ops_attendance')->insertGetId([
        'user_id' => A, 'attendance_date' => '2026-06-22', 'login_time' => '09:00:00',
        'vehicle_id' => $ownB, 'meter_start' => 500, 'meter_start_source' => 'home',
        'work_expected_by' => '2026-06-22 09:30:00',
    ]);
    $undo(fn () => DB::table('t_ops_attendance')->where('id', $att22)->delete());
    $att23 = DB::table('t_ops_attendance')->insertGetId([
        'user_id' => A, 'attendance_date' => '2026-06-23', 'login_time' => '09:00:00',
        'meter_start' => 99999, 'meter_start_source' => 'home',
        'work_expected_by' => '2026-06-23 09:30:00',
    ]);
    $undo(fn () => DB::table('t_ops_attendance')->where('id', $att23)->delete());
    flushAll();
    $iss3 = $wj->workIssueDays([A], '2026-06-01', '2026-06-30');
    ok('the overridden day itself is not judged', isset($iss3[A]['detail']['2026-06-22']), false);
    ok('and the day AFTER carries no false meter_gap',
       isset($iss3[A]['detail']['2026-06-23']) && in_array('meter_gap', $iss3[A]['detail']['2026-06-23'], true), false);
    ok('while the genuine 21 Jun jump is still reported',
       isset($iss3[A]['detail']['2026-06-21']) && in_array('meter_gap', $iss3[A]['detail']['2026-06-21'], true), true);
} else {
    echo "  ~ skipped: t_ops_attendance has no vehicle_id column\n";
}

head('§5 meter-vs-GPS is silent on a handover day (Sep-01 review #14)');
$gpsCase = function (int $uid, string $on) use ($dc) {
    $v = $dc->build([
        'user_id' => $uid, 'login_time' => '09:00:00', 'logout_time' => '19:00:00',
        'role_name' => 'Rider', 'company_bike' => true,
        'meter_start' => 100, 'meter_end' => 130, 'meter_distance' => 30,
        'road_distance_km' => 200, 'gps_distance' => 200,
        'checkout_info' => null, 'home_journey' => null, 'work_journey' => null,
        'checkout_unlock' => null, 'checkout_counted' => null,
    ], [], $on, 10.0);
    return $v['meter_ok'] ?? null;
};
ok('a 170 km mismatch on a HANDOVER day raises nothing — his readings cover half the day',
   $gpsCase(B, $DAY), true);
ok('the same mismatch on a normal held day is still flagged',
   $gpsCase(C, $DAY), false);

// ============================================================================
echo "\n";
echo str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
