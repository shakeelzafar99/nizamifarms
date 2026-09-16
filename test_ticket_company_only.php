<?php
/**
 * BIKE TICKETS — COMPANY MACHINES ONLY, and the machine is NAMED (owner ruling, 15-Sep-2026).
 *
 *   "We should stop the riders from raising a ticket on an own bike or non company bike.
 *    Also when opening a ticket the flow clearly handles what bike they are on and tells
 *    the user as well so they know what vehicle they are communicating on."
 *
 * What these prove:
 *   §1 the RAISE list is narrowed  — `ticketableMachineIds` / `myMachines` drop own bikes;
 *   §2 the READ rule is NOT        — `ownMachineIds` / `visibilityScope` are untouched, so an
 *                                    own bike's existing ticket stays readable by its holder;
 *   §3 a rider cannot raise on his own bike, by any route (auto-resolve, or a posted id);
 *   §4 a MANAGER cannot either — the rule is about the machine, not the person;
 *   §5 a rider who holds ONLY an own bike gets the honest message, not "no bike assigned";
 *   §6 a company machine still works exactly as before (no regression);
 *   §7 the confirmation NAMES the machine, and the payload carries it;
 *   §8 `subjectMachineFor()` answers "which bike would a ticket for this rider land on".
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ ONE user is authenticated per process — this script authenticates nobody and passes the
 *   user objects explicitly, like test_vehicle_tickets.php.
 *
 * Run:  php test_ticket_company_only.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\VehicleTicketService as VT;
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

$svc = new VT();
$veh = new VehicleService();
$res = new VehicleResolver();

ok('the ticket tables exist', $svc->available(), true);
if (!$svc->available()) { echo "\nSQL not applied — stopping.\n"; exit(1); }

// ─── fixtures: DISCOVERED, never hard-coded ──────────────────────────────────
head('§0 fixtures');

$manager = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission(VT::PERMISSION)) { $manager = $u; break; }
}
ok('a manager holding manage_vehicle_tickets exists', (bool) $manager, null, true);

/**
 * A machine the company does NOT own, and one it does.
 *
 * ⚠⚠ DISCOVER AN OWN BIKE SOMEONE ACTUALLY HOLDS. The first `is_company = 0` row on the replica
 *    (v5, "Danish - own bike") has no live assignment, so taking it blindly skipped three of the
 *    sections that matter. Prefer a HELD one and fall back to any — the fixture-drift lesson:
 *    discover a fixture that fits the question, never the first row that matches the type.
 */
$ownVid = 0; $ownHolder = null;
$ownCandidates = DB::table('t_ops_vehicle')->where('is_company', 0)->where('is_active', 1)->pluck('id');
$riderIds = DB::table('t_ops_rider_profile')->pluck('user_id');
foreach ($ownCandidates as $cand) {
    foreach ($riderIds as $uid) {
        if (!in_array((int) $cand, $svc->ownMachineIds((int) $uid), true)) continue;
        $u = User::find((int) $uid);
        if (!$u || $svc->canManage($u)) continue;
        $ownVid = (int) $cand; $ownHolder = $u; break 2;
    }
}
if (!$ownVid) $ownVid = (int) ($ownCandidates->first() ?: 0);

$coVid  = (int) (DB::table('t_ops_vehicle')->where('is_company', 1)->where('is_active', 1)->value('id') ?: 0);
ok('an own (non-company) machine exists to test against', $ownVid > 0, true);
ok('  …and one that a rider actually HOLDS (so the real cases run)', (bool) $ownHolder, null, true);
ok('a company machine exists to test against', $coVid > 0, true);
if (!$manager || !$ownVid || !$coVid) { echo "\nfixtures missing — stopping.\n"; exit(1); }

ok('  …and the service agrees which is which',
   [$veh->isCompanyMachine($coVid), $veh->isCompanyMachine($ownVid)], [true, false]);
echo '  · manager=' . $manager->id . " ownBike=v{$ownVid} companyBike=v{$coVid}"
   . ' ownHolder=' . ($ownHolder ? $ownHolder->id : 'none') . "\n";

DB::beginTransaction();
try {

// ─────────────────────────────────────────────────────────────────────────────
head('§1 the RAISE list drops own bikes');

if ($ownHolder) {
    $own  = $svc->ownMachineIds((int) $ownHolder->id);
    $tick = $svc->ticketableMachineIds((int) $ownHolder->id);
    ok('his own bike IS among the machines he holds', in_array($ownVid, $own, true), true);
    ok('  …and is NOT among the machines he may raise on', in_array($ownVid, $tick, true), false);
    ok('  …ticketable is a SUBSET of held, never something new',
       array_values(array_diff($tick, $own)), []);
    $names = array_column($svc->myMachines((int) $ownHolder->id), 'id');
    ok('  …so the phone picker never offers it', in_array($ownVid, $names, true), false);
    foreach ($tick as $tid) {
        ok("  …every machine offered (v{$tid}) is a company one", $veh->isCompanyMachine($tid), true);
    }
} else {
    echo "  · nobody currently holds an own bike — §1 holder cases skipped\n";
}

// ─────────────────────────────────────────────────────────────────────────────
head('§2 the READ rule is deliberately NOT narrowed');

/**
 * ⭐⭐ The point of the whole separation. A ticket that already exists on an own bike — raised
 *    before this rule, or by some future manager path — must stay readable by whoever holds
 *    that machine. Narrowing `visibilityScope` would make a live conversation vanish from the
 *    phone of the man who is in it.
 */
$legacyId = (int) DB::table(VT::T_TICKET)->insertGetId([
    'vehicle_id' => $ownVid, 'opened_by' => (int) $manager->id, 'opened_for_user_id' => $ownHolder?->id,
    'category' => 'problem', 'urgent' => 0, 'title' => 'Legacy ticket on an own bike',
    'status' => 'open', 'opened_at' => now(), 'last_message_at' => now(),
    'created_at' => now(), 'updated_at' => now(),
]);
$legacy = $svc->find($legacyId);
ok('a manager can still READ a ticket on an own bike', $svc->mayRead($manager, $legacy), true);
if ($ownHolder) {
    ok('  …and so can the rider holding that machine', $svc->mayRead($ownHolder, $legacy), true);
    $listed = array_column($svc->listFor($ownHolder, ['status' => 'open', 'limit' => 100]), 'id');
    ok('  …and it still appears in his own list', in_array($legacyId, $listed, true), true);
    $r = $svc->reply($ownHolder, $legacyId, ['kind' => 'text', 'body' => 'Still talking about it']);
    ok('  …and he can still reply to it', $r['ok'], true);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§3 a rider cannot RAISE on an own bike');

if ($ownHolder) {
    $bad = $svc->open($ownHolder, ['vehicle_id' => $ownVid, 'title' => 'Own bike chain']);
    ok('posting the id by hand is refused', $bad['ok'], false);
    ok('  …and the message says company machines only',
       (bool) preg_match('/company machine/i', $bad['message']), null, true);
    ok('  …and nothing was written',
       (int) DB::table(VT::T_TICKET)->where('vehicle_id', $ownVid)->where('title', 'Own bike chain')->count(), 0);
} else {
    $bad = $svc->open($manager, ['vehicle_id' => $ownVid, 'title' => 'Own bike chain']);
    ok('posting an own-bike id is refused (checked via a manager)', $bad['ok'], false);
}

// ─────────────────────────────────────────────────────────────────────────────
head('§4 a MANAGER cannot either — the rule is about the MACHINE');

$mgrBad = $svc->open($manager, ['vehicle_id' => $ownVid, 'title' => 'Manager tries an own bike']);
ok('a manager is refused on an own bike too', $mgrBad['ok'], false);
ok('  …with the same reason', (bool) preg_match('/company machine/i', $mgrBad['message']), null, true);

// ─────────────────────────────────────────────────────────────────────────────
head('§5 a rider holding ONLY an own bike gets the HONEST message');

/**
 * ⚠ "You hold nothing" and "you hold only your own bike" are different situations, and the
 *   wrong sentence sends him to a manager who cannot help him either.
 */
/**
 * ⚠⚠ STAGE THE CUSTODY INSIDE THE TRANSACTION rather than hoping a rider happens to hold only
 *    his own bike today. On this replica everyone who has an own bike also holds a company one,
 *    so waiting for the real world to produce the fixture means this case never runs — and it
 *    is the one a real rider will hit. (Same lesson as replica-scenario-probes: stage it, and
 *    never let a precondition silently turn a check into a no-op.)
 */
if ($ownHolder) {
    // Release every COMPANY machine he holds, for the length of this transaction only.
    $held = $svc->ticketableMachineIds((int) $ownHolder->id);
    if ($held) {
        DB::table('t_ops_vehicle_assignment')
            ->where('user_id', (int) $ownHolder->id)
            ->whereIn('vehicle_id', $held)
            ->whereNull('released_on')
            ->update(['released_on' => now()->subMinute(), 'updated_at' => now()]);
    }
    $stillHolds  = $svc->ownMachineIds((int) $ownHolder->id);
    $nowTickable = $svc->ticketableMachineIds((int) $ownHolder->id);
    ok('staged: he now holds only non-company machines', $nowTickable, []);
    ok('  …but he still holds something', (bool) $stillHolds, null, true);

    if (!$nowTickable && $stillHolds) {
        $r = $svc->open($ownHolder, ['title' => 'Something rattles']);
        ok('he is refused', $r['ok'], false);
        ok('  …and is NOT told "no bike is assigned to you" — which would be untrue',
           (bool) preg_match('/no bike is assigned/i', $r['message']), false);
        ok('  …he is told his own bike is not on the company list',
           (bool) preg_match('/own bike|company machines only/i', $r['message']), null, true);
        echo "      message: {$r['message']}\n";
    }
} else {
    echo "  · no own-bike holder discovered — message case skipped\n";
}

// ─────────────────────────────────────────────────────────────────────────────
head('§6 a company machine is unaffected (no regression)');

$coHolder = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $u = User::find((int) $uid);
    if (!$u || $svc->canManage($u)) continue;
    if ($svc->ticketableMachineIds((int) $uid)) { $coHolder = $u; break; }
}
if ($coHolder) {
    $good = $svc->open($coHolder, ['title' => 'Front brake is loose', 'body' => 'It grabs late.']);
    ok('a rider can still raise on the company machine he holds', $good['ok'], true);
    $landed = (int) $svc->find((int) $good['ticket_id'])['vehicle_id'];
    ok('  …and it landed on a company machine', $veh->isCompanyMachine($landed), true);

    // ── §7 rides on this one ────────────────────────────────────────────────
    head('§7 the confirmation NAMES the machine');
    $label = $res->labelFor($landed);
    ok('the payload carries the vehicle id', (int) ($good['vehicle_id'] ?? 0), $landed);
    ok('  …and the label', $good['vehicle_name'] ?? null, $label);
    ok('  …and the message names it',
       (bool) ($label && strpos($good['message'], $label) !== false), null, true);
    echo "      message: {$good['message']}\n";
} else {
    echo "  · no non-manager rider holds a company machine — §6/§7 skipped\n";
}

// ─────────────────────────────────────────────────────────────────────────────
head('§8 subjectMachineFor — which bike would a ticket for this rider land on');

if ($coHolder) {
    $sm = $svc->subjectMachineFor((int) $coHolder->id);
    ok('it answers for a rider holding a company machine', (bool) $sm, null, true);
    ok('  …and says it is a company one', $sm['is_company'] ?? null, true);
    ok('  …and names it', (bool) ($sm['name'] ?? null), null, true);
    ok('  …and it agrees with what open() actually resolves',
       (int) $sm['id'], (int) $res->currentVehicleFor((int) $coHolder->id));
}
if ($ownHolder) {
    $sm = $svc->subjectMachineFor((int) $ownHolder->id);
    // He may hold a company machine as well; the flag is what matters, not the emptiness.
    if ($sm && (int) $sm['id'] === $ownVid) {
        ok('for an own-bike holder it reports is_company FALSE — so the sheet can warn first',
           $sm['is_company'], false);
    }
}
ok('an unknown user resolves to nothing rather than throwing',
   $svc->subjectMachineFor(999999999), null);

} finally {
    DB::rollBack();
}

echo "\n──────────────────────────────\n";
echo "  passed: $pass    failed: $fail\n";
echo "──────────────────────────────\n";
exit($fail === 0 ? 0 : 1);
