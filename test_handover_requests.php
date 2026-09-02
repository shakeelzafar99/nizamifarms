<?php
/**
 * 🔁 RIDER-INITIATED VEHICLE HANDOVER REQUESTS (Sep-01 2026) — end to end.
 *
 * The scenario these are built around is the owner's own: Rajab checks in on his own
 * bike, asks for the VAN when it is handed to him in the morning, and asks to give it
 * back in the evening. Neither move happens until Shabib or Taimur approves, and when
 * they do, the machine moves through the SAME VehicleService::assign() the web fleet
 * screen has always used.
 *
 * What these pin:
 *   §1  the picker puts the machines he actually uses on top (owner ruling Q2)
 *   §2  a request MOVES NOTHING until it is approved
 *   §3  approving a "take" hands the van over, frees his own bike, records the meter
 *   §4  one open request per rider
 *   §5  approving a "return" gives his own bike back and logs the van's closing meter
 *   §6  the approver may CHANGE what he gets back (owner ruling)
 *   §7  two managers tapping Approve at the same moment — only one handover happens
 *   §8  the world is re-checked at approval time, not trusted from when he asked
 *   §9  a request that is ignored dies inside the shift (12h TTL)
 *   §10 the displaced rider is settled exactly as on the web
 *
 * ⚠ Undo stack unwinds in REVERSE from a shutdown function. Do not pipe through
 *   `head` — a closed stdout kills PHP and the restore never runs.
 *
 * Run:  php test_handover_requests.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
    $p = DB::table('t_ops_rider_profile')->where('user_id', $uid)->first(['default_vehicle_id', 'company_bike']);
    if (!$p) return;
    $undo(fn () => DB::table('t_ops_rider_profile')->where('user_id', $uid)->update([
        'default_vehicle_id' => $p->default_vehicle_id, 'company_bike' => $p->company_bike,
    ]));
};

const RAJAB = 82;    // stand-in: a rider holding nothing, so no real state is touched
const OTHER = 83;    // yesterday's van driver
const THIRD = 93;
const BOSS  = 79;    // Shabib — holds assign_vehicles
$TODAY = now()->format('Y-m-d');

foreach ([RAJAB, OTHER, THIRD] as $u) $snapProfile($u);
$undo(fn () => DB::table(HRS::TABLE)->whereIn('user_id', [RAJAB, OTHER, THIRD])->delete());

$svc = new VehicleService();
$res = new VehicleResolver();
$hrs = new HRS();

/**
 * ⚠⚠ THE BANNER IS SHARED WITH THE REAL WORLD. `live()` returns every open request
 *    on the box, and dev legitimately carries the owner's own test rows — so a bare
 *    `count($hrs->live())` or `live()[0]` reads somebody else's request and the suite
 *    fails for a reason that has nothing to do with the code. Every assertion below
 *    therefore scopes to THIS suite's riders (or to one known id).
 */
$liveMine = fn () => array_values(array_filter($hrs->live(),
    fn ($x) => in_array((int) $x['user_id'], [RAJAB, OTHER, THIRD], true)));
$liveOne  = fn (int $id) => (array_values(array_filter($hrs->live(),
    fn ($x) => (int) $x['id'] === $id))[0] ?? null);

ok('the request table is present', $hrs->available(), true);
ok('meter photo is optional for now (owner ruling Q3)', $hrs->photoRequired(), false);

// His own bike, and the van that yesterday's driver still holds.
$ownBike = $mkVehicle('TEST Rajab - own bike', 0);
$van     = $mkVehicle('TEST Van', 1, 'van');
$svc->assign($ownBike, RAJAB, '2026-08-01', BOSS);
$svc->assign($van, OTHER, '2026-08-01', BOSS);
// He has driven the van before — that is what should float it to the top of his list.
DB::table(VehicleService::T_ASSIGN)->insert([
    'vehicle_id' => $van, 'user_id' => RAJAB, 'assigned_on' => '2026-08-20',
    'released_on' => '2026-08-20', 'assigned_by' => BOSS, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();

// ============================================================================
head('§1 the picker leads with the machines he actually uses (owner ruling Q2)');

$opts = $hrs->options(RAJAB);
ok('it knows what he is holding', $opts['holding']['id'] ?? null, $ownBike);
$ids = array_column($opts['options'], 'id');
ok('the van he drives is FIRST', $ids[0] ?? null, $van);
ok('the van is labelled with who has it now',
   $opts['options'][0]['held_by'] ?? null,
   DB::table('t_sys_user')->where('id', OTHER)->value('fullname'));
ok('his own bike is not offered — he is on it', in_array($ownBike, $ids, true), false);

$strangerBike = $mkVehicle('TEST somebody elses own bike', 0);
$svc->assign($strangerBike, THIRD, '2026-08-01', BOSS);
flushAll();
ok('another man\'s PERSONAL bike is never offered',
   in_array($strangerBike, array_column($hrs->options(RAJAB)['options'], 'id'), true), false);

// ============================================================================
head('§2 a request MOVES NOTHING');

$r = $hrs->raise(RAJAB, 'take', $van, 74310, 'van handed to me at the depot');
ok('the request is accepted', $r['ok'] ?? false, true);
$reqId = (int) $r['id'];
flushAll();
ok('the van still belongs to yesterday\'s driver',
   (int) DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $van)
        ->whereNull('released_on')->value('user_id'), OTHER);
ok('he is still on his own bike', $res->currentVehicleFor(RAJAB), $ownBike);
ok('his card shows the request waiting', $hrs->openFor(RAJAB)['id'] ?? null, $reqId);
ok('the approver sees exactly one request', count($liveMine()), 1);
$card = $liveOne($reqId);
ok('the card names the current keeper', $card['current_keeper_name'] ?? null,
   DB::table('t_sys_user')->where('id', OTHER)->value('fullname'));
ok('and carries his claimed meter', $card['meter_claimed'] ?? null, 74310);

head('§3 one open request per rider');
$dup = $hrs->raise(RAJAB, 'take', $van, 74310, null);
ok('a second ask is refused', $dup['ok'] ?? true, false);
ok('   with a sentence he can act on', str_contains($dup['message'], 'already have'), true, true);

// ============================================================================
head('§4 approving the morning TAKE performs the real handover');

$d = $hrs->decide($reqId, true, BOSS);
flushAll();
ok('approval succeeds', $d['ok'] ?? false, true);
ok('the van is his', $res->currentVehicleFor(RAJAB), $van);
ok('his own bike was freed for the day', $svc->keeperOf($ownBike), null);
ok('the odometer he read is on the assignment row', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->value('handover_meter'), 74310);
ok('the request records which row it produced', (bool) DB::table(HRS::TABLE)
    ->where('id', $reqId)->value('applied_assignment_id'), true, true);
ok('the banner is empty again', count($liveMine()), 0);
ok('the assignment note points back at the request',
   str_contains((string) DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $van)
       ->whereNull('released_on')->value('note'), '#' . $reqId), true, true);

head('§4b the displaced driver was settled, not abandoned');
ok('yesterday\'s driver is no longer on the van', $res->currentVehicleFor(OTHER) !== $van, true, true);

// ============================================================================
head('§5 the evening RETURN gives his own bike back');

$rr = $hrs->raise(RAJAB, 'return', $van, 74495, 'parked at the depot');
ok('the hand-back request is accepted', $rr['ok'] ?? false, true);
$retId = (int) $rr['id'];
ok('it already knows what he gets back', $rr['request']['give_back_vehicle_id'] ?? null, $ownBike);
$rcard = $liveOne($retId);
ok('and the approver is shown the same answer', $rcard['give_back_suggested']['id'] ?? null, $ownBike);
ok('the approver is offered spares to change it to', is_array($rcard['give_back_spares'] ?? null), true, true);

$d2 = $hrs->decide($retId, true, BOSS);
flushAll();
ok('approval succeeds', $d2['ok'] ?? false, true);
ok('he is back on his own bike', $res->currentVehicleFor(RAJAB), $ownBike);
ok('the van is free again', $svc->keeperOf($van), null);
$logRow = DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $van)
    ->where('log_date', $TODAY)->first();
ok('the van\'s closing reading is on the VAN, not on his bike',
   (int) ($logRow->meter_end ?? 0), 74495);
ok('   credited to him as the driver', (int) ($logRow->driver_user_id ?? 0), RAJAB);
ok('   and his own bike carries no van odometer', DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $ownBike)->whereNull('released_on')->value('handover_meter'), null);

// ============================================================================
head('§6 the approver may CHANGE what he gets back (owner ruling)');

$spare = $mkVehicle('TEST spare bike', 1);
$svc->assign($van, RAJAB, $TODAY, BOSS);       // put him back on the van
flushAll();
$r3 = $hrs->raise(RAJAB, 'return', $van, 74600, null);
$d3 = $hrs->decide((int) $r3['id'], true, BOSS, ['give_back_vehicle_id' => $spare]);
flushAll();
ok('the decision succeeds', $d3['ok'] ?? false, true);
ok('he gets the machine the APPROVER chose, not the one proposed',
   $res->currentVehicleFor(RAJAB), $spare);

head('§6b and may hand him nothing at all, explicitly');
$svc->assign($van, RAJAB, $TODAY, BOSS);
flushAll();
$r4 = $hrs->raise(RAJAB, 'return', $van, 74700, null);
$d4 = $hrs->decide((int) $r4['id'], true, BOSS, ['give_back_none' => true]);
flushAll();
ok('the van comes back', $svc->keeperOf($van), null);
ok('and he holds nothing', $res->currentVehicleFor(RAJAB), null);

head('§6c a give-back somebody else holds is refused, not silently taken');
$svc->assign($van, RAJAB, $TODAY, BOSS);
$svc->assign($spare, THIRD, $TODAY, BOSS);      // third rider now holds the spare
flushAll();
$r5 = $hrs->raise(RAJAB, 'return', $van, 74800, null);
$d5 = $hrs->decide((int) $r5['id'], true, BOSS, ['give_back_vehicle_id' => $spare]);
flushAll();
ok('the third rider keeps his machine', $res->currentVehicleFor(THIRD), $spare);
ok('the van still came back', $svc->keeperOf($van), null);
ok('and the decision says what happened',
   str_contains($d5['message'] ?? '', 'held by somebody else'), true, true);

// ============================================================================
head('§7 two managers tapping Approve at the same moment');

$svc->assign($van, OTHER, $TODAY, BOSS);
flushAll();
$r6 = $hrs->raise(RAJAB, 'take', $van, 74900, null);
$id6 = (int) $r6['id'];
$first  = $hrs->decide($id6, true, BOSS);
$second = $hrs->decide($id6, true, BOSS);
flushAll();
ok('the first approval wins', $first['ok'] ?? false, true);
ok('the second is told it was already decided', $second['ok'] ?? true, false);
ok('   in plain words', str_contains($second['message'] ?? '', 'already'), true, true);
ok('and the machine moved exactly once', DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->count(), 1);

// ============================================================================
head('§8 the world is re-checked at approval time');

$retired = $mkVehicle('TEST to be retired', 1);
$r7 = $hrs->raise(THIRD, 'take', $retired, 100, null);
DB::table(VehicleService::T_VEHICLE)->where('id', $retired)->update(['is_active' => 0]);
flushAll();
$d7 = $hrs->decide((int) $r7['id'], true, BOSS);
ok('a machine retired since he asked is refused', $d7['ok'] ?? true, false);
ok('   and the request stays open for a human to close',
   DB::table(HRS::TABLE)->where('id', (int) $r7['id'])->value('status'), HRS::ST_PENDING);
ok('a retired machine also drops out of the banner',
   count(array_filter($hrs->live(), fn ($x) => $x['id'] === (int) $r7['id'])), 0);
DB::table(HRS::TABLE)->where('id', (int) $r7['id'])->update(['status' => HRS::ST_CANCELLED]);

// ============================================================================
head('§9 an ignored request dies inside the shift (12h TTL)');

$r8 = $hrs->raise(THIRD, 'take', $van, 75000, null);
$id8 = (int) $r8['id'];
ok('it is live now', count(array_filter($hrs->live(), fn ($x) => $x['id'] === $id8)), 1);
DB::table(HRS::TABLE)->where('id', $id8)
    ->update(['requested_at' => now()->subHours(HRS::TTL_HOURS + 1)]);
ok('and gone once it is older than the window',
   count(array_filter($hrs->live(), fn ($x) => $x['id'] === $id8)), 0);
ok('his own card stops showing it too', $hrs->openFor(THIRD), null);
ok('so a stale one cannot block a fresh ask', $hrs->raise(THIRD, 'take', $van, 75001, null)['ok'] ?? false, true);
DB::table(HRS::TABLE)->where('user_id', THIRD)->update(['status' => HRS::ST_CANCELLED]);

// ============================================================================
head('§10 the rider can withdraw his own request, and only his own');

$r9 = $hrs->raise(RAJAB, 'return', $res->currentVehicleFor(RAJAB) ?: $van, 75100, null);
$id9 = (int) $r9['id'];
ok('another rider cannot cancel it', $hrs->cancel($id9, THIRD)['ok'] ?? true, false);
ok('it is still open', $hrs->openFor(RAJAB)['id'] ?? null, $id9);
ok('he can cancel his own', $hrs->cancel($id9, RAJAB)['ok'] ?? false, true);
ok('and the banner clears', count($liveMine()), 0);
ok('cancelling twice is refused', $hrs->cancel($id9, RAJAB)['ok'] ?? true, false);

// ============================================================================
head('§11 rejecting moves nothing');

$svc->assign($van, OTHER, $TODAY, BOSS);
flushAll();
$r10 = $hrs->raise(RAJAB, 'take', $van, 75200, null);
$d10 = $hrs->decide((int) $r10['id'], false, BOSS, ['note' => 'van needed at the warehouse']);
flushAll();
ok('the rejection succeeds', $d10['ok'] ?? false, true);
ok('the van did not move', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->value('user_id'), OTHER);
ok('the reason is kept', DB::table(HRS::TABLE)->where('id', (int) $r10['id'])->value('decision_note'),
   'van needed at the warehouse');
ok('and the banner is empty', count($liveMine()), 0);

// ============================================================================
// Sep-01 ROUND 3 — the adversarial review's findings, pinned.
// ============================================================================

/** Arm a ride-home journey on a throwaway attendance row. */
$armFor = function (int $uid) use ($undo): int {
    $attId = DB::table('t_ops_attendance')->insertGetId([
        'user_id' => $uid, 'attendance_date' => now()->format('Y-m-d'),
        'logout_time' => '20:10:00',
        'home_expected_by' => now()->addMinutes(40)->format('Y-m-d H:i:s'),
        'home_eta_min' => 25, 'home_distance_km' => 9.4,
    ]);
    $undo(fn () => DB::table('t_ops_attendance')->where('id', $attId)->delete());
    return (int) $attId;
};
$timerOn = fn (int $attId) => DB::table('t_ops_attendance')->where('id', $attId)
    ->value('home_expected_by') !== null;

head('§12 the ride-home timer follows the SETTLEMENT, not the gap (review #1)');
// OTHER holds the van (from §11) and has an armed evening timer. RAJAB's take is
// approved with the displaced man settled onto ANOTHER COMPANY machine — his
// timer must survive, because he is still riding company iron home tonight.
$att12 = $armFor(OTHER);
$spare12 = $mkVehicle('TEST spare round3', 1);
$r12 = $hrs->raise(RAJAB, 'take', $van, 75300, null);
$d12 = $hrs->decide((int) $r12['id'], true, BOSS,
                    ['displaced_action' => 'vehicle', 'displaced_vehicle_id' => $spare12]);
flushAll();
ok('the approval succeeds', $d12['ok'] ?? false, true);
ok('the van is RAJAB\'s', $res->currentVehicleFor(RAJAB), $van);
ok('the displaced man landed on the spare', $res->currentVehicleFor(OTHER), $spare12);
ok('and his armed timer SURVIVED the handover', $timerOn($att12), true);

head('§12b …and dies when the settlement leaves him with nothing');
$r12b = $hrs->raise(OTHER, 'return', $spare12, null, null);
$d12b = $hrs->decide((int) $r12b['id'], true, BOSS, ['give_back_none' => true]);
flushAll();
ok('he now holds nothing', $res->currentVehicleFor(OTHER), null);
ok('and the timer went with the machine', $timerOn($att12), false);

head('§13 the closing meter never steals another driver\'s stint (review #2)');
// The van's log row for today already records THIRD's morning stint.
// (One row per vehicle+day — uq_vehicle_day — so clear §5's leftover first.)
DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $van)->where('log_date', $TODAY)->delete();
DB::table(VehicleService::T_METER_LOG)->insert([
    'vehicle_id' => $van, 'log_date' => $TODAY, 'meter_start' => 75300, 'meter_end' => 75390,
    'driver_user_id' => THIRD, 'note' => 'morning stint', 'entered_by' => BOSS,
    'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
$r13 = $hrs->raise(RAJAB, 'return', $van, 75450, null);
$d13 = $hrs->decide((int) $r13['id'], true, BOSS, ['give_back_none' => true]);
flushAll();
ok('the hand-back itself succeeds', $d13['ok'] ?? false, true);
$log13 = DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $van)
    ->where('log_date', $TODAY)->first();
ok('the morning driver KEEPS his stint', (int) ($log13->driver_user_id ?? 0), THIRD);
ok('   his readings untouched', [(int) $log13->meter_start, (int) $log13->meter_end], [75300, 75390]);
ok('   his note untouched', (string) $log13->note, 'morning stint');
ok('the reading still lives on the request row for the 🧾 editor',
   (int) DB::table(HRS::TABLE)->where('id', (int) $r13['id'])->value('meter_claimed'), 75450);
DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $van)->where('log_date', $TODAY)->delete();

head('§13b …and still writes when the row is HIS or nobody\'s');
$svc->assign($van, RAJAB, $TODAY, BOSS);
flushAll();
$r13b = $hrs->raise(RAJAB, 'return', $van, 75500, null);
$d13b = $hrs->decide((int) $r13b['id'], true, BOSS, ['give_back_none' => true]);
flushAll();
$log13b = DB::table(VehicleService::T_METER_LOG)->where('vehicle_id', $van)
    ->where('log_date', $TODAY)->first();
ok('a fresh row is written with him as driver', (int) ($log13b->driver_user_id ?? 0), RAJAB);
ok('   carrying his closing reading', (int) ($log13b->meter_end ?? 0), 75500);

head('§14 an EXPIRED request cannot move a machine (review #11)');
$svc->assign($van, OTHER, $TODAY, BOSS);
flushAll();
$r14 = $hrs->raise(RAJAB, 'take', $van, 75600, null);
DB::table(HRS::TABLE)->where('id', (int) $r14['id'])
    ->update(['requested_at' => now()->subHours(HRS::TTL_HOURS + 1)]);
$d14 = $hrs->decide((int) $r14['id'], true, BOSS);
flushAll();
ok('the stale approval is refused', $d14['ok'] ?? true, false);
ok('   with the expiry named', str_contains($d14['message'] ?? '', 'expired'), true, true);
ok('the row is marked expired, not left pending',
   DB::table(HRS::TABLE)->where('id', (int) $r14['id'])->value('status'), HRS::ST_EXPIRED);
ok('and the van never moved', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->value('user_id'), OTHER);

head('§15 an empty approve body executes the FRESH give-back, not the snapshot (review #3)');
// His own bike is HELD when he asks (snapshot = nothing)…
$svc->assign($van, RAJAB, $TODAY, BOSS);
$svc->assign($ownBike, THIRD, $TODAY, BOSS);
flushAll();
$r15 = $hrs->raise(RAJAB, 'return', $van, 75700, null);
ok('the snapshot honestly says "nothing" at raise time',
   DB::table(HRS::TABLE)->where('id', (int) $r15['id'])->value('give_back_vehicle_id'), null);
// …but it is FREE by the time the office approves (mobile posts an empty body).
$svc->release($ownBike, $TODAY, BOSS);
flushAll();
$card15 = $liveOne((int) $r15['id']);
ok('the banner shows the fresh answer', $card15['give_back_suggested']['id'] ?? null, $ownBike);
$d15 = $hrs->decide((int) $r15['id'], true, BOSS, []);
flushAll();
ok('and the empty-body approve DELIVERS what the banner showed',
   $res->currentVehicleFor(RAJAB), $ownBike);

head('§16 one borrowed day does not put a colleague\'s bike in the picker (review #5)');
// RAJAB once borrowed THIRD's personal bike for a day.
DB::table(VehicleService::T_ASSIGN)->insert([
    'vehicle_id' => $strangerBike, 'user_id' => RAJAB, 'assigned_on' => '2026-07-01',
    'released_on' => '2026-07-01', 'assigned_by' => BOSS, 'created_at' => now(), 'updated_at' => now(),
]);
flushAll();
ok('it does not appear in his picker',
   in_array($strangerBike, array_column($hrs->options(RAJAB)['options'], 'id'), true), false);
$r16 = $hrs->raise(RAJAB, 'take', $strangerBike, null, null);
ok('and a hand-built request for it is refused', $r16['ok'] ?? true, false);
ok('   naming the reason', str_contains($r16['message'] ?? '', 'personal bike'), true, true);

head('§17 the TAKE card says what the displaced man gets back (review #12)');
// OTHER's own bike exists and is free, and he holds the van.
$otherOwn = $mkVehicle('TEST Abdul - own bike', 0);
DB::table(VehicleService::T_ASSIGN)->insert([
    'vehicle_id' => $otherOwn, 'user_id' => OTHER, 'assigned_on' => '2026-06-01',
    'released_on' => '2026-06-02', 'assigned_by' => BOSS, 'created_at' => now(), 'updated_at' => now(),
]);
$svc->assign($van, OTHER, $TODAY, BOSS);
flushAll();
$r17 = $hrs->raise(RAJAB, 'take', $van, null, null);
$card17 = $liveOne((int) $r17['id']);
ok('the card carries the keeper\'s landing', $card17['keeper_gets_back'] ?? null,
   'TEST Abdul - own bike');
$hrs->cancel((int) $r17['id'], RAJAB);

// ============================================================================
echo "\n";
echo str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
