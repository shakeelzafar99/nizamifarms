<?php
/**
 * 🔁 RAJAB'S WHOLE DAY, THROUGH THE REAL ENGINES (Sep-01 2026).
 *
 * The owner's questions after the first live test:
 *   "once Shabib accepts, does the rest work — if he checks out with the same
 *    vehicle will the usual checks and process work? will giving it back work?
 *    are all these vehicle changes using a single engine, so whether done from
 *    the web, by store mode, or by the rider themselves they work correctly?"
 *
 * So this drives the ACTUAL day in order — ask, approve, work, hand back — and
 * after each step asserts what the LIVE gates say (meter demanded? which machine?
 * whose fuel? ride-home flow armed?), then proves the three doors are one engine
 * by running the same handover through each and comparing the rows they produce.
 *
 * ⚠ Undo stack unwinds in REVERSE from a shutdown function. Never pipe through
 *   `head` — a closed stdout kills PHP and the restore never runs.
 *
 * Run:  php test_handover_fullday.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\FuelClaimRules;
use App\Services\Riders\HomeJourneyService;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleHandoverRequestService as HRS;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
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
$mkVehicle = function (string $nick, int $isCompany, string $vtype = 'bike') use ($undo): int {
    $id = DB::table(VehicleService::T_VEHICLE)->insertGetId([
        'vtype' => $vtype, 'nickname' => $nick, 'is_company' => $isCompany,
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $undo(fn () => DB::table(VehicleService::T_VEHICLE)->where('id', $id)->delete());
    $undo(fn () => DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $id)->delete());
    $undo(fn () => DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $id)->delete());
    return (int) $id;
};
$snapProfile = function (int $uid) use ($undo): void {
    static $done = [];
    if (isset($done[$uid])) return; $done[$uid] = true;
    $p = DB::table('t_ops_rider_profile')->where('user_id', $uid)
        ->first(['default_vehicle_id', 'company_bike', 'home_latitude', 'home_longitude']);
    if (!$p) return;
    $undo(fn () => DB::table('t_ops_rider_profile')->where('user_id', $uid)->update([
        'default_vehicle_id' => $p->default_vehicle_id, 'company_bike' => $p->company_bike,
        'home_latitude' => $p->home_latitude, 'home_longitude' => $p->home_longitude,
    ]));
};

const RIDER = 82;   // stand-in for Rajab — holds nothing, so no real state moves
const YDAY  = 83;   // yesterday's van driver
const BOSS  = 79;   // Shabib
$TODAY = now()->format('Y-m-d');

foreach ([RIDER, YDAY] as $u) { $snapProfile($u); }
$undo(fn () => DB::table(HRS::TABLE)->whereIn('user_id', [RIDER, YDAY])->delete());

$svc = new VehicleService();
$res = new VehicleResolver();
$hrs = new HRS();
$hj  = new HomeJourneyService();
$fcr = new FuelClaimRules();

// He owns a bike and starts the day on it; the van is with yesterday's driver.
$ownBike = $mkVehicle('TEST fullday - own bike', 0);
$van     = $mkVehicle('TEST fullday Van', 1, 'van');
$svc->assign($ownBike, RIDER, '2026-08-01', BOSS);
$svc->assign($van, YDAY, '2026-08-01', BOSS);
// A home pin, so the ride-home flow can arm at all.
DB::table('t_ops_rider_profile')->where('user_id', RIDER)
    ->update(['home_latitude' => 33.6844, 'home_longitude' => 73.0479]);
flushAll();

ok('VEHICLE_RULES is ON, so these gates really bind', $svc->rulesEnabled(), true);

// ============================================================================
head('§1 MORNING — he checks in on his OWN bike, before asking for anything');

ok('the registry says he is on his own bike', $res->currentVehicleFor(RIDER), $ownBike);
ok('a meter IS demanded of him', $res->meterRequiredNow(RIDER), true);
ok('but he is NOT on company iron', $res->holdsCompanyMachineNow(RIDER), false);
ok('so the company-bike fuel rules do not apply', $fcr->ridesCompanyBike(RIDER, $TODAY), false);

head('§2 HE ASKS FOR THE VAN — and nothing about his day changes yet');
$r = $hrs->raise(RIDER, 'take', $van, 74310, 'van handed to me at the depot');
ok('the request is accepted', $r['ok'] ?? false, true);
$reqId = (int) $r['id'];
flushAll();
ok('he is STILL on his own bike', $res->currentVehicleFor(RIDER), $ownBike);
ok('the van is still yesterday driver\'s', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->value('user_id'), YDAY);
$mine = array_values(array_filter($hrs->live(), fn ($x) => $x['id'] === $reqId));
ok('the approver sees exactly this request', count($mine), 1);
ok('the card names who holds it now', ($mine[0]['current_keeper_name'] ?? null) !== null, true, true);
ok('and says what THAT man gets back', array_key_exists('keeper_gets_back', $mine[0]), true, true);

head('§3 SHABIB APPROVES — the handover really happens');
$d = $hrs->decide($reqId, true, BOSS);
flushAll();
ok('approval succeeds', $d['ok'] ?? false, true);
ok('the van is HIS', $res->currentVehicleFor(RIDER), $van);
ok('his own bike was freed', $svc->keeperOf($ownBike), null);
ok('yesterday\'s driver is off the van', $res->currentVehicleFor(YDAY) !== $van, true, true);
ok('the odometer he read is on the assignment row', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->value('handover_meter'), 74310);

head('§4 THE USUAL CHECKS NOW FOLLOW THE VAN — the owner\'s question');
ok('a meter is still demanded', $res->meterRequiredNow(RIDER), true);
ok('…and it is the VAN it is demanded for', $res->currentVehicleFor(RIDER), $van);
ok('he now counts as being on COMPANY iron', $res->holdsCompanyMachineNow(RIDER), true);
ok('so the company fuel rules DO apply', $fcr->ridesCompanyBike(RIDER, $TODAY), true);
ok('the ride-home flow is now his', is_array($hj->riderHomePin(RIDER)), true, true);
ok('today is a TRANSFER day for the van', $res->isTransferDay($van, $TODAY), true);
ok('both riders count as holders today, so reports excuse both sides',
   isset($res->machineHoldersOn($TODAY)[RIDER]) && isset($res->machineHoldersOn($TODAY)[YDAY]),
   true, true);
ok('his claims will stamp to the VAN from now on', $res->vehicleForDay(RIDER, $TODAY), $van);

head('§5 HE CHECKS OUT ON THE VAN — the ride-home timer arms and survives');
$att = DB::table('t_ops_attendance')->insertGetId([
    'user_id' => RIDER, 'attendance_date' => $TODAY, 'login_time' => '09:00:00',
    'logout_time' => '20:10:00',
    'home_expected_by' => now()->addMinutes(40)->format('Y-m-d H:i:s'),
    'home_eta_min' => 25, 'home_distance_km' => 9.4,
]);
$undo(fn () => DB::table('t_ops_attendance')->where('id', $att)->delete());
flushAll();
$timerOn = fn () => DB::table('t_ops_attendance')->where('id', $att)->value('home_expected_by') !== null;
ok('the timer is armed', $timerOn(), true, true);
$hj->disarmIfNoCompanyMachine(RIDER);
ok('and while he holds the van nothing disarms it', $timerOn(), true, true);

head('§6 EVENING — he asks to hand the van back');
$rr = $hrs->raise(RIDER, 'return', $van, 74495, 'parked at the depot');
ok('the hand-back request is accepted', $rr['ok'] ?? false, true);
$retId = (int) $rr['id'];
ok('it already knows he gets his own bike back',
   $rr['request']['give_back_vehicle_id'] ?? null, $ownBike);
flushAll();
ok('and nothing has moved yet', $res->currentVehicleFor(RIDER), $van);

head('§7 SHABIB APPROVES THE RETURN');
$d2 = $hrs->decide($retId, true, BOSS);
flushAll();
ok('approval succeeds', $d2['ok'] ?? false, true);
ok('he is back on his own bike', $res->currentVehicleFor(RIDER), $ownBike);
ok('the van is free for tomorrow', $svc->keeperOf($van), null);
$log = DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $van)
    ->where('log_date', $TODAY)->first();
ok('the van\'s closing reading is on the VAN', (int) ($log->meter_end ?? 0), 74495);
ok('   credited to HIM as its driver', (int) ($log->driver_user_id ?? 0), RIDER);
ok('his own bike carries no van odometer', DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $ownBike)->whereNull('released_on')->value('handover_meter'), null);

head('§8 …and the evening rules follow him back off company iron');
ok('he is off company iron', $res->holdsCompanyMachineNow(RIDER), false);
ok('company fuel rules no longer apply', $fcr->ridesCompanyBike(RIDER, $TODAY), false);
ok('a meter is STILL demanded — he holds his own bike', $res->meterRequiredNow(RIDER), true);
ok('the stale van timer was cleared, so no false "home late"', $timerOn(), false);
ok('   and the reports stop accusing him', isset($hj->homeIssues($TODAY)[RIDER]), false);
ok('his checkout time is untouched', substr((string) DB::table('t_ops_attendance')
    ->where('id', $att)->value('logout_time'), 0, 5), '20:10');

// ============================================================================
head('§9 ONE ENGINE — the three doors write IDENTICAL rows');

/** Fingerprint of what a handover actually wrote (note/id deliberately excluded). */
$shape = function () use ($van, $ownBike) {
    $a = DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $van)
        ->whereNull('released_on')->first(['user_id', 'assigned_on', 'handover_meter', 'assigned_by']);
    $p = DB::table('t_ops_rider_profile')->where('user_id', RIDER)
        ->first(['default_vehicle_id', 'company_bike']);
    return [
        'keeper'       => (int) ($a->user_id ?? 0),
        'assigned_on'  => substr((string) ($a->assigned_on ?? ''), 0, 10),
        'meter'        => $a->handover_meter === null ? null : (int) $a->handover_meter,
        'by'           => (int) ($a->assigned_by ?? 0),
        'mirror'       => (int) ($p->default_vehicle_id ?? 0),
        'company_bike' => (int) ($p->company_bike ?? 0),
        'own_bike_row' => DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $ownBike)
                            ->whereNull('released_on')->exists() ? 'open' : 'closed',
    ];
};
$reset = function () use ($svc, $ownBike, $van, $TODAY) {
    $svc->assign($ownBike, RIDER, $TODAY, BOSS);   // back to the morning state
    $svc->assign($van, YDAY, $TODAY, BOSS);
    flushAll();
};

$user = \App\Models\User::find(BOSS);
auth()->shouldUse('web');
auth()->guard('web')->setUser($user);
$ctl = new \App\Http\Controllers\CRM\VehicleController();
$mkReq = function () use ($user) {
    $q = \Illuminate\Http\Request::create('/x', 'POST', [
        'user_id' => RIDER, 'handover_meter' => 74310, 'displaced_action' => 'own',
    ]);
    $q->setUserResolver(fn () => $user);
    return $q;
};

// DOOR 1 — the WEB fleet screen
$reset();
$ctl->assign($mkReq(), $svc, $van);
flushAll();
$web = $shape();

// DOOR 2 — MOBILE store mode (apiAssign delegates to the same method)
$reset();
$ctl->apiAssign($mkReq(), $svc, $van);
flushAll();
$mob = $shape();

// DOOR 3 — the RIDER's own request, approved
$reset();
DB::table(HRS::TABLE)->where('user_id', RIDER)->delete();
$r3 = $hrs->raise(RIDER, 'take', $van, 74310, null);
$hrs->decide((int) $r3['id'], true, BOSS);
flushAll();
$rid = $shape();

ok('web door — the rider ends up holding it', $web['keeper'], RIDER);
ok('WEB and MOBILE wrote identical rows', $mob, $web);
ok('WEB and the RIDER REQUEST wrote identical rows', $rid, $web);
ok('   the meter was recorded the same way', $rid['meter'], 74310);
ok('   his own bike was freed the same way', $rid['own_bike_row'], 'closed');
ok('   the profile mirror moved the same way', $rid['mirror'], $van);

head('§9b …and only ONE class may write the assignment table');
$writes = (int) trim((string) shell_exec(
    'grep -rn "T_ASSIGN" app/ --include=*.php | grep -E "insert|update\\(" | wc -l'));
$inSvc  = (int) trim((string) shell_exec(
    'grep -rn "T_ASSIGN" app/Services/Riders/VehicleService.php | grep -E "insert|update\\(" | wc -l'));
ok('every write site lives in VehicleService', $writes === $inSvc && $writes > 0, true, true);

// ============================================================================
echo "\n";
echo str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
