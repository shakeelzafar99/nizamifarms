<?php
/**
 * PHASE 0 — HANDOVER ENGINE HARDENING (Sep-01 2026).
 *
 * Five gaps found while auditing the rider↔vehicle assignment engine before building
 * mobile assign + rider handover requests on top of it. The engine itself was sound;
 * these are the seams around it.
 *
 *   G1  assign() did all its POST-COMMIT work (who was displaced, van cargo, the
 *       company_bike resync) from a `$current` read OUTSIDE the transaction — the
 *       stale read the lock inside exists to defeat. In the race, the displaced rider
 *       came back null and was silently left with nothing.
 *   G2  settleDisplacedRider accepted ANY vehicle id: the machine just handed over
 *       (undoing the handover) or one a third rider is holding (displacing him with
 *       no prompt).
 *   G3  ownVehicleFor returned the newest non-company machine EVER assigned to him —
 *       a colleague's bike he borrowed for a day counted. Live: Waseem held
 *       "Danish - own bike" on 7 Aug, so the silent 'own' default would hand Danish's
 *       personal bike to Waseem.
 *   G4  A ride-home timer armed at checkout was never cleared when the machine was
 *       handed over afterwards. Three live guards stop the rider being nagged, but
 *       homeIssues() judges the raw row, so he collects a false "home late — locked"
 *       mark in the reports.
 *   G5  The displaced rider's move carried no handover meter, so the machine he landed
 *       on always read "shared" for that day and was charged to nobody.
 *
 * ⚠ HARNESS RULE (cost a dirty replica once): every mutation registers its undo and
 *   the stack is unwound in REVERSE from a shutdown function. Do NOT pipe this through
 *   `head` — a closed stdout kills PHP on the broken pipe and the restore never runs.
 *
 * Run:  php test_handover_hardening.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Riders\HomeJourneyService;
use App\Services\Riders\RiderDayLegs;
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
    VehicleService::flushServiceMemo();
    VehicleResolver::flush();
    RiderDayLegs::flush();
}

// ---------------------------------------------------------------- undo stack
$UNDO = [];
$undo = function (callable $fn) use (&$UNDO) { $UNDO[] = $fn; };
register_shutdown_function(function () use (&$UNDO) {
    echo "\n-- restoring --\n";
    foreach (array_reverse($UNDO) as $fn) {          // ⚠ REVERSE, always
        try { $fn(); } catch (\Throwable $e) { echo "  ! undo failed: " . $e->getMessage() . "\n"; }
    }
    echo "-- restored --\n";
});

/** Make a throwaway machine and guarantee its removal. */
$mkVehicle = function (string $nick, int $isCompany) use ($undo): int {
    $id = DB::table(VehicleService::T_VEHICLE)->insertGetId([
        'vtype' => 'bike', 'nickname' => $nick, 'reg_no' => null,
        'is_company' => $isCompany, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $undo(fn () => DB::table(VehicleService::T_VEHICLE)->where('id', $id)->delete());
    $undo(fn () => DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $id)->delete());
    return (int) $id;
};

/** Snapshot a rider profile so assign()'s mirror writes can be rolled back. */
$snapProfile = function (int $uid) use ($undo): void {
    static $done = [];
    if (isset($done[$uid])) return;
    $done[$uid] = true;
    $p = DB::table('t_ops_rider_profile')->where('user_id', $uid)
        ->first(['default_vehicle_id', 'company_bike']);
    if (!$p) return;
    $undo(fn () => DB::table('t_ops_rider_profile')->where('user_id', $uid)->update([
        'default_vehicle_id' => $p->default_vehicle_id, 'company_bike' => $p->company_bike,
    ]));
};

// Subjects that hold NOTHING today, so no real assignment is disturbed.
const A = 82;   // Wajid
const B = 83;   // Abdul Malik
const C = 93;   // Sabir
const ACTOR = 79;

foreach ([A, B, C] as $u) { $snapProfile($u); }

$svc = new VehicleService();
ok('replica has the vehicle registry live', $svc->available(), true, true);
ok('VEHICLE_RULES is ON (these guards only bind when it is)', $svc->rulesEnabled(), true, true);

/**
 * G1's race, made deterministic. `keeperOf()` is the OUTER read assign() takes before
 * the transaction; overriding it lets us change the world in the gap the lock covers.
 * The stale answer is still returned, exactly as a concurrent writer would leave it.
 */
class RacyVehicleService extends VehicleService
{
    public $onceHook = null;
    public function keeperOf(int $vehicleId)
    {
        $stale = parent::keeperOf($vehicleId);
        if ($this->onceHook) { $h = $this->onceHook; $this->onceHook = null; $h(); }
        return $stale;   // deliberately pre-race
    }
}

// ============================================================================
head('§1 G1 — the DISPLACED rider comes from the locked read, not the stale one');

$v1 = $mkVehicle('TEST bike G1', 1);
$r = $svc->assign($v1, A, null, ACTOR);
ok('setup: machine assigned to A', $r['ok'] ?? false, true);

$racy = new RacyVehicleService();
// Between assign()'s outer read and its transaction, B takes the machine.
$racy->onceHook = function () use ($v1) {
    DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $v1)->whereNull('released_on')
        ->update(['released_on' => now()->format('Y-m-d'), 'released_by' => ACTOR]);
    DB::table(VehicleService::T_ASSIGN)->insert([
        'vehicle_id' => $v1, 'user_id' => B, 'assigned_on' => now()->format('Y-m-d'),
        'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now(),
    ]);
    flushAll();
};
$r = $racy->assign($v1, C, null, ACTOR);
flushAll();

ok('the handover still succeeds', $r['ok'] ?? false, true);
ok('displaced rider is B — who ACTUALLY lost it', $r['displaced_user_id'] ?? null, B);
ok('   (it is NOT the stale pre-race keeper A)', ($r['displaced_user_id'] ?? null) !== A, true, true);
ok('exactly one open row survives', DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $v1)->whereNull('released_on')->count(), 1);
ok('and it belongs to the incoming rider', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $v1)->whereNull('released_on')->value('user_id'), C);

head('§1b G1 — a concurrent assign to the SAME rider displaces nobody');
$v2 = $mkVehicle('TEST bike G1b', 1);
$svc->assign($v2, A, null, ACTOR);
$racy2 = new RacyVehicleService();
$racy2->onceHook = function () use ($v2) {                 // someone else already did it
    DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $v2)->whereNull('released_on')
        ->update(['released_on' => now()->format('Y-m-d'), 'released_by' => ACTOR]);
    DB::table(VehicleService::T_ASSIGN)->insert([
        'vehicle_id' => $v2, 'user_id' => C, 'assigned_on' => now()->format('Y-m-d'),
        'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now(),
    ]);
    flushAll();
};
$r = $racy2->assign($v2, C, null, ACTOR);
flushAll();
// ⚠ `??` would report NULL as "missing" — and null IS the answer here.
ok('the key is present', array_key_exists('displaced_user_id', $r), true, true);
ok('no displacement is reported', $r['displaced_user_id'], null);
ok('   (the stale read would have blamed A)', ($r['displaced_user_id'] ?? null) !== A, true, true);

// ============================================================================
head('§2 G3 — "his own bike" means he is its FIRST keeper');

$own = $mkVehicle('TEST own bike G3', 0);
DB::table(VehicleService::T_ASSIGN)->insert([          // A owns it: first, and for months
    'vehicle_id' => $own, 'user_id' => A, 'assigned_on' => '2026-01-10',
    'released_on' => '2026-08-06', 'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
$got = $svc->ownVehicleFor(A);
ok('the owner is offered his own bike', $got['id'] ?? null, $own);

DB::table(VehicleService::T_ASSIGN)->insert([          // B borrows it for ONE day
    'vehicle_id' => $own, 'user_id' => B, 'assigned_on' => '2026-08-07',
    'released_on' => '2026-08-07', 'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
ok('the BORROWER is never offered it', $svc->ownVehicleFor(B), null);
ok('and one day of lending does not cost the owner his bike', $svc->ownVehicleFor(A)['id'] ?? null, $own);

DB::table(VehicleService::T_ASSIGN)->insert([          // now somebody is holding it
    'vehicle_id' => $own, 'user_id' => B, 'assigned_on' => now()->format('Y-m-d'),
    'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
ok('a bike somebody currently holds is not offered back', $svc->ownVehicleFor(A), null);
DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $own)->whereNull('released_on')->delete();
flushAll();

head('§2b G3 — the live case that prompted the fix (real replica rows)');
$waseemOld = DB::table(VehicleService::T_ASSIGN . ' as a')
    ->join(VehicleService::T_VEHICLE . ' as v', 'v.id', '=', 'a.vehicle_id')
    ->where('a.user_id', 73)->where('v.is_company', 0)->where('v.is_active', 1)
    ->orderByDesc('a.assigned_on')->orderByDesc('a.id')->value('a.vehicle_id');
if ((int) $waseemOld === 5) {
    ok('OLD rule would hand Waseem vehicle 5 ("Danish - own bike")', (int) $waseemOld, 5);
    ok('NEW rule refuses it', $svc->ownVehicleFor(73), null);
    ok('and Danish still keeps his own bike', $svc->ownVehicleFor(84)['id'] ?? null, 5);
} else {
    echo "  ~ skipped: the replica no longer has Waseem's 7-Aug loan of vehicle 5\n";
}

// ============================================================================
head('§3 G2 — the replacement machine is validated on the SERVER');

$ctl  = new \App\Http\Controllers\CRM\VehicleController();
$ref  = new ReflectionMethod($ctl, 'settleDisplacedRider');
$ref->setAccessible(true);
$settle = fn (...$a) => $ref->invoke($ctl, $svc, ...$a);

$vHand  = $mkVehicle('TEST handed-over G2', 1);
$vThird = $mkVehicle('TEST third-rider G2', 1);
$vFree  = $mkVehicle('TEST free spare G2', 1);
$svc->assign($vHand, C, null, ACTOR);      // C now holds the "handed over" machine
$svc->assign($vThird, B, null, ACTOR);     // B is the innocent third rider
flushAll();

$msg = $settle(A, 'vehicle', $vHand, null, $vHand, null);
ok('refuses the machine that was just handed over', str_contains($msg, 'just handed over'), true, true);
ok('   and that machine still belongs to its new keeper', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $vHand)->whereNull('released_on')->value('user_id'), C);

$msg = $settle(A, 'vehicle', $vThird, null, $vHand, null);
ok('refuses a machine a third rider is holding', str_contains($msg, 'somebody else is holding it'), true, true);
ok('   and the third rider keeps it', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $vThird)->whereNull('released_on')->value('user_id'), B);

$msg = $settle(A, 'vehicle', $vFree, null, $vHand, null);
flushAll();
ok('a genuine free spare is still accepted', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $vFree)->whereNull('released_on')->value('user_id'), A);

// ============================================================================
head('§4 G5 — the displaced rider\'s landing machine records its handover meter');

$vLand = $mkVehicle('TEST landing G5', 1);
$settle(B, 'vehicle', $vLand, null, $vHand, 41250);
flushAll();
$row = DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $vLand)
    ->whereNull('released_on')->first(['user_id', 'handover_meter']);
ok('he is on the machine', (int) ($row->user_id ?? 0), B);
ok('and the odometer travelled with him', (int) ($row->handover_meter ?? 0), 41250);

$vLand2 = $mkVehicle('TEST landing G5b', 1);
$settle(B, 'vehicle', $vLand2, null, $vHand, null);
flushAll();
ok('no meter given → column left null, never 0', DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $vLand2)->whereNull('released_on')->value('handover_meter'), null);

// ============================================================================
head('§5 G4 — the ride-home timer is cleared when the machine leaves');

$hj = new HomeJourneyService();
$vHome = $mkVehicle('TEST home-journey G4', 1);

/** Arm a journey on a throwaway attendance row for $uid. */
$armFor = function (int $uid, $meterHome = null) use ($undo): int {
    $id = DB::table('t_ops_attendance')->insertGetId([
        'user_id' => $uid, 'attendance_date' => now()->format('Y-m-d'),
        'logout_time' => '20:10:00',
        'home_expected_by' => now()->addMinutes(40)->format('Y-m-d H:i:s'),
        'home_eta_min' => 25, 'home_distance_km' => 9.4, 'meter_home' => $meterHome,
    ]);
    $undo(fn () => DB::table('t_ops_attendance')->where('id', $id)->delete());
    return (int) $id;
};

$svc->assign($vHome, A, null, ACTOR);
flushAll();
$att = $armFor(A);
ok('setup: A holds a company machine', (new VehicleResolver())->holdsCompanyMachineNow(A), true);
ok('setup: his journey is armed', DB::table('t_ops_attendance')->where('id', $att)
    ->value('home_expected_by') !== null, true, true);

$hj->disarmIfNoCompanyMachine(A);
ok('while he still holds it, nothing is touched', DB::table('t_ops_attendance')->where('id', $att)
    ->value('home_expected_by') !== null, true, true);

$svc->release($vHome, null, ACTOR);          // the machine goes back
flushAll();
ok('after the release he holds no company machine',
   (new VehicleResolver())->holdsCompanyMachineNow(A), false);
$after = DB::table('t_ops_attendance')->where('id', $att)
    ->first(['home_expected_by', 'home_eta_min', 'home_distance_km', 'logout_time']);
ok('the timer is cleared', $after->home_expected_by, null);
ok('   eta cleared too', $after->home_eta_min, null);
ok('   distance cleared too', $after->home_distance_km, null);
ok('   but his checkout is untouched', substr((string) $after->logout_time, 0, 5), '20:10');
ok('so the state is no longer a journey at all', $hj->deriveState((object) [
    'home_expected_by' => null, 'home_arrived_at' => null, 'meter_home' => null,
    'home_meter_unlock_until' => null, 'logout_time' => '20:10:00',
]), 'none');
ok('and the reports stop accusing him', isset($hj->homeIssues(now()->format('Y-m-d'))[A]), false);

head('§5b G4 — a COMPLETED journey is history and is never rewritten');
$vHome2 = $mkVehicle('TEST home-journey G4b', 1);
$svc->assign($vHome2, B, null, ACTOR);
flushAll();
$attDone = $armFor(B, 18400);                // he recorded his meter
$svc->release($vHome2, null, ACTOR);
flushAll();
$done = DB::table('t_ops_attendance')->where('id', $attDone)
    ->first(['home_expected_by', 'meter_home']);
ok('the completed journey keeps its deadline', $done->home_expected_by !== null, true, true);
ok('and its reading', (int) $done->meter_home, 18400);

head('§5c G4 — a handover that leaves him on ANOTHER company machine keeps the timer');
$vX = $mkVehicle('TEST keeps-timer X', 1);
$vY = $mkVehicle('TEST keeps-timer Y', 1);
$svc->assign($vX, C, null, ACTOR);
flushAll();
$attC = $armFor(C);
$svc->assign($vY, C, null, ACTOR);           // swapped onto a different company bike
flushAll();
ok('he still holds company iron', (new VehicleResolver())->holdsCompanyMachineNow(C), true);
ok('so his ride-home timer stands', DB::table('t_ops_attendance')->where('id', $attC)
    ->value('home_expected_by') !== null, true, true);

head('§5d G4 — being moved onto his OWN bike disarms him too');
$ownC = $mkVehicle('TEST own bike G4d', 0);
DB::table(VehicleService::T_ASSIGN)->insert([
    'vehicle_id' => $ownC, 'user_id' => C, 'assigned_on' => '2026-02-01',
    'released_on' => '2026-02-02', 'assigned_by' => ACTOR, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
ok('setup: the timer is still armed', DB::table('t_ops_attendance')->where('id', $attC)
    ->value('home_expected_by') !== null, true, true);
$svc->assign($ownC, C, null, ACTOR);         // company bike Y closed, own bike given
flushAll();
ok('he is off company iron', (new VehicleResolver())->holdsCompanyMachineNow(C), false);
ok('and the timer went with the company bike', DB::table('t_ops_attendance')->where('id', $attC)
    ->value('home_expected_by'), null);

// ============================================================================
echo "\n";
echo str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
