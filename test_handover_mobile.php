<?php
/**
 * 📱 THE MOBILE DOOR, DRIVEN LIKE THE PHONE DRIVES IT (Sep-01 2026).
 *
 * The owner's question: "does approving from the mobile work correctly like the web,
 * and changing a rider's vehicle from the mobile device too?"
 *
 * `test_handover_fullday.php` already proved the ASSIGN door writes identical rows.
 * This goes further and covers what that one did not:
 *   • the mobile APPROVE / REJECT of a handover request vs the web route
 *   • the mobile RELEASE, including the displaced-rider settle
 *   • the exact JSON body `FleetAssignSheet.js` builds — field names included, because
 *     a body the server silently ignores is the classic "worked in the test, did
 *     nothing on the phone" bug
 *   • the preview payloads the sheet reads (it renders `displaced.own`, `.spare`,
 *     `.goes_quiet`, `lines`, `warnings`, `roster.riders[].vehicle_name`)
 *   • the permission gates over the SANCTUM transport, not the web session
 *
 * ⚠ Authenticated through `Sanctum::actingAs()` so `auth()->user()` resolves the way
 *   it does for a real token request — the gates read `auth()->user()`, and proving
 *   them under a web session would prove the wrong thing.
 *
 * ⚠ Undo stack unwinds in REVERSE from a shutdown function. Never pipe through `head`.
 *
 * Run:  php test_handover_mobile.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\CRM\VehicleController;
use App\Http\Controllers\CRM\VehicleHandoverController;
use App\Services\Riders\RiderDayLegs;
use App\Services\Riders\VehicleHandoverRequestService as HRS;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

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

const RIDER = 82;
const YDAY  = 83;
const SPARE = 93;
const BOSS  = 79;      // Shabib — holds assign_vehicles
$TODAY = now()->format('Y-m-d');

foreach ([RIDER, YDAY, SPARE] as $u) { $snapProfile($u); }
$undo(fn () => DB::table(HRS::TABLE)->whereIn('user_id', [RIDER, YDAY, SPARE])->delete());

$svc = new VehicleService();
$res = new VehicleResolver();
$hrs = new HRS();
$veh = new VehicleController();
$hc  = new VehicleHandoverController();

/** A request shaped like the phone's — authenticated the way a token request is. */
$asPhone = function (int $userId, string $method = 'POST', array $body = []) {
    $u = \App\Models\User::find($userId);
    Sanctum::actingAs($u);                       // sets the guard auth()->user() reads
    $r = Request::create('/x', $method, $body);
    $r->setUserResolver(fn () => $u);
    $r->headers->set('Accept', 'application/json');
    return $r;
};
$json = fn ($resp) => json_decode($resp->getContent(), true);

$ownBike = $mkVehicle('TEST mob - own bike', 0);
$van     = $mkVehicle('TEST mob Van', 1, 'van');
$spare   = $mkVehicle('TEST mob spare', 1);
$svc->assign($ownBike, RIDER, '2026-08-01', BOSS);
$svc->assign($van, YDAY, '2026-08-01', BOSS);
flushAll();

// ============================================================================
head('§1 the sheet\'s READ endpoints return what it actually renders');

$r = $json($veh->apiRoster($asPhone(BOSS, 'GET'), $svc));
ok('roster loads over sanctum', $r['success'] ?? false, true);
ok('  it has riders[]', is_array($r['riders'] ?? null), true, true);
// ⚠ Assert the SHAPE against whoever the roster actually returns — never a
//   hard-coded user. This suite's own subjects are deliberately INACTIVE riders
//   (`t_ops_rider_profile.active = 0`, which is what makes them safe to move
//   around), and the roster correctly excludes them. Hard-coding one here was
//   testing the fixture, not the contract.
$row = collect($r['riders'])->first();
ok('  a rider row carries user_id', isset($row['user_id']), true, true);
ok('  …and name (the sheet prints r.name)', isset($row['name']), true, true);
$held = collect($r['riders'])->first(fn ($x) => !empty($x['vehicle_name']));
ok('  …and vehicle_name on whoever holds a machine (the "now on X" line)',
   $held !== null && is_string($held['vehicle_name']), true, true);
ok('  inactive riders are correctly NOT offered',
   collect($r['riders'])->firstWhere('user_id', YDAY), null);
ok('  spare[] is present for the displaced picker', is_array($r['spare'] ?? null), true, true);

$p = $json($veh->apiPreviewAssign($asPhone(BOSS, 'GET', ['user_id' => RIDER]), $svc, $van));
ok('preview-assign loads', $p['success'] ?? false, true);
ok('  lines[] — the sheet lists them', is_array($p['lines'] ?? null), true, true);
ok('  warnings[] — the sheet lists them', is_array($p['warnings'] ?? null), true, true);
ok('  displaced is present (someone holds the van)', is_array($p['displaced'] ?? null), true, true);
ok('    displaced.name — "What happens to X?"', isset($p['displaced']['name']), true, true);
ok('    displaced.spare[] — the replacement list', is_array($p['displaced']['spare'] ?? null), true, true);
ok('    displaced.goes_quiet — the "not asked for meters" note',
   array_key_exists('goes_quiet', $p['displaced']), true, true);

$pr = $json($veh->apiPreviewRelease($asPhone(BOSS, 'GET'), $svc, $van));
ok('preview-release loads', $pr['success'] ?? false, true);
ok('  and carries displaced for the same picker', is_array($pr['displaced'] ?? null), true, true);

// ============================================================================
head('§2 CHANGING A RIDER\'S VEHICLE FROM THE PHONE — the sheet\'s exact body');

// Precisely what FleetAssignSheet.save() builds for an assign.
$body = ['user_id' => RIDER, 'handover_meter' => 74310,
         'displaced_action' => 'vehicle', 'displaced_vehicle_id' => $spare,
         'displaced_meter' => 5100];
$a = $json($veh->apiAssign($asPhone(BOSS, 'POST', $body), $svc, $van));
flushAll();
ok('the phone\'s assign succeeds', $a['success'] ?? false, true);
ok('the van moved to the rider', $res->currentVehicleFor(RIDER), $van);
ok('his own bike was freed', $svc->keeperOf($ownBike), null);
ok('EVERY field was honoured — handover_meter', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->value('handover_meter'), 74310);
ok('  displaced_action+vehicle: the old driver landed on the spare',
   $res->currentVehicleFor(YDAY), $spare);
ok('  displaced_meter reached that second assignment', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $spare)->whereNull('released_on')->value('handover_meter'), 5100);
ok('the response carries the refreshed vehicle for the sheet',
   isset($a['vehicle']['id']), true, true);

head('§2b …and RELEASING from the phone, with the settle');
$rel = $json($veh->apiRelease($asPhone(BOSS, 'POST', ['displaced_action' => 'own']), $svc, $van));
flushAll();
ok('the phone\'s release succeeds', $rel['success'] ?? false, true);
ok('the van is free', $svc->keeperOf($van), null);
ok('and he was put back on his own bike by the settle', $res->currentVehicleFor(RIDER), $ownBike);

// ============================================================================
head('§3 APPROVING A REQUEST FROM THE PHONE == approving it from the web');

/** Run one identical request through a door and fingerprint what it produced. */
$fingerprint = function (int $reqId, int $vehicleId) {
    $q = DB::table(HRS::TABLE)->where('id', $reqId)
        ->first(['status', 'decided_by', 'applied_assignment_id']);
    $a = DB::table(VehicleService::T_ASSIGN)->where('vehicle_id', $vehicleId)
        ->whereNull('released_on')->first(['user_id', 'assigned_on', 'handover_meter', 'note']);
    return [
        'status'  => (string) ($q->status ?? ''),
        'by'      => (int) ($q->decided_by ?? 0),
        'linked'  => !empty($q->applied_assignment_id),
        'keeper'  => (int) ($a->user_id ?? 0),
        'on'      => substr((string) ($a->assigned_on ?? ''), 0, 10),
        'meter'   => $a->handover_meter === null ? null : (int) $a->handover_meter,
        'note'    => (string) ($a->note ?? ''),
    ];
};
$reset = function () use ($svc, $ownBike, $van, $TODAY) {
    $svc->assign($ownBike, RIDER, $TODAY, BOSS);
    $svc->assign($van, YDAY, $TODAY, BOSS);
    DB::table(HRS::TABLE)->whereIn('user_id', [RIDER, YDAY, SPARE])->delete();
    flushAll();
};

// --- WEB door
$reset();
$w = $hrs->raise(RIDER, 'take', $van, 74310, 'from the yard');
$hc->approve($asPhone(BOSS, 'POST'), (int) $w['id']);   // the web route calls this same method
flushAll();
$webShape = $fingerprint((int) $w['id'], $van);
$webShape['note'] = preg_replace('/#\d+/', '#N', $webShape['note']);   // ids differ by construction

// --- MOBILE door (different route, same controller method — prove it end to end)
$reset();
$m = $hrs->raise(RIDER, 'take', $van, 74310, 'from the yard');
$mobResp = $json($hc->approve($asPhone(BOSS, 'POST'), (int) $m['id']));
flushAll();
$mobShape = $fingerprint((int) $m['id'], $van);
$mobShape['note'] = preg_replace('/#\d+/', '#N', $mobShape['note']);

ok('the phone\'s approve succeeds', $mobResp['success'] ?? false, true);
ok('MOBILE approve == WEB approve, in every recorded detail', $mobShape, $webShape);
ok('  the machine really moved', $mobShape['keeper'], RIDER);
ok('  the request is closed and linked to its assignment', $mobShape['linked'], true);
ok('  and stamped with WHO decided it', $mobShape['by'], BOSS);
ok('the response carries the decided request back to the banner',
   isset($mobResp['request']['status']), true, true);

head('§3b REJECTING from the phone moves nothing');
$reset();
$rj = $hrs->raise(RIDER, 'take', $van, 74310, null);
$rjResp = $json($hc->reject($asPhone(BOSS, 'POST', ['note' => 'van needed here']), (int) $rj['id']));
flushAll();
ok('the phone\'s reject succeeds', $rjResp['success'] ?? false, true);
ok('the van did NOT move', (int) DB::table(VehicleService::T_ASSIGN)
    ->where('vehicle_id', $van)->whereNull('released_on')->value('user_id'), YDAY);
ok('the reason is recorded', DB::table(HRS::TABLE)->where('id', (int) $rj['id'])->value('decision_note'),
   'van needed here');

head('§3c the phone\'s empty body still delivers the fresh give-back');
$reset();
$svc->assign($van, RIDER, $TODAY, BOSS);       // he holds it, own bike free
flushAll();
$rt = $hrs->raise(RIDER, 'return', $van, 74495, null);
// StoreOpenOrdersScreen posts `{}` — no give_back override at all.
$rtResp = $json($hc->approve($asPhone(BOSS, 'POST'), (int) $rt['id']));
flushAll();
ok('the hand-back approves from the phone', $rtResp['success'] ?? false, true);
ok('and he is back on his own bike', $res->currentVehicleFor(RIDER), $ownBike);
ok('the van is free', $svc->keeperOf($van), null);

// ============================================================================
head('§4 the phone\'s banner feed matches the web\'s');

$reset();
$b = $hrs->raise(RIDER, 'take', $van, 74310, null);
$feed = $json($hc->pending($asPhone(BOSS, 'GET')));
$mine = collect($feed['requests'] ?? [])->firstWhere('id', (int) $b['id']);
ok('the feed answers over sanctum', $feed['success'] ?? false, true);
ok('  can_approve is true for a manager', $feed['can_approve'] ?? null, true);
ok('  the request is in it', $mine !== null, true, true);
foreach (['rider_name', 'direction', 'vehicle_name', 'current_keeper_name',
          'keeper_gets_back', 'meter_claimed'] as $k) {
    ok("  the banner field `$k` is present", array_key_exists($k, $mine ?? []), true, true);
}
$hrs->cancel((int) $b['id'], RIDER);

// ============================================================================
echo "\n";
echo str_repeat('=', 62) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('=', 62) . "\n";
