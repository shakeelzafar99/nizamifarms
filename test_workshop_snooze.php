<?php
/**
 * ⏰ THE APPROVAL CARD'S "BAAD MEIN (6h)" (11-Sep-2026).
 *
 * Taimur's complaint: every planner sees every workshop proposal with only Approve and Decline
 * on it. He is usually only being INFORMED — but he must still see them, because on the days
 * Farooq is on leave he is the one who decides. So the audience cannot be narrowed; what was
 * missing was a way to say "not me, not now".
 *
 * What this proves:
 *   §1 a snooze hides it from THIS user only, and only for six hours
 *   §2 …and never from anybody else — the request stays live for the real decider
 *   §3 it is NOT a decision: the visit is still `proposed` and comes back afterwards
 *   §4 a decided visit leaves every list at once (the half that already worked)
 *   §5 ⚠ a planner is no longer asked to approve HIS OWN workshop day (open ruling 4)
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ NOT `head()` — Laravel ships a global `head()` the validator calls.
 *
 * Run:  php test_workshop_snooze.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Riders\VehicleResolver;
use App\Services\Riders\VehicleService;
use App\Services\Riders\WorkshopVisitService as WV;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function ok(string $what, $got, $want = null, bool $raw = false) {
    global $pass, $fail;
    $good = $raw ? (bool) $got : $got === $want;
    if ($good) { $pass++; echo "  ✓ $what\n"; }
    else {
        $fail++; echo "  ✗ $what\n";
        if (!$raw) {
            echo "      got:  " . var_export($got, true) . "\n";
            echo "      want: " . var_export($want, true) . "\n";
        }
    }
}
function section(string $t) { echo "\n== $t ==\n"; }

config(['whatsapp.firebase_credentials_path' => 'storage/__no_such_credentials_for_tests__.json']);

$wv   = new WV();
$res  = new VehicleResolver();
$vsvc = new VehicleService();

ok('the approval half is enabled here', $wv->approvalEnabled(), true);
ok('the dismissal table exists (no SQL needed for the snooze)',
   \Illuminate\Support\Facades\Schema::hasTable('t_ops_alert_dismissal'), true);

// ── fixtures: TWO planners, so "mine only" can actually be proved ──────
$planners = [];
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($wv->canApprove($u, false) || $wv->canApprove($u, true)) $planners[] = $u;
    if (count($planners) >= 2) break;
}
ok('two planners exist', count($planners) >= 2, true);

$booker = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission(WV::PERMISSION)) { $booker = $u; break; }
}
$rider = null; $vid = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if (!$v || !$vsvc->isTrackedId($v)) continue;
    // ⚠ NOT one of the planners — §5 needs that case on its own terms.
    if (in_array((int) $uid, array_map(fn ($p) => (int) $p->id, $planners), true)) continue;
    $u = User::find((int) $uid);
    if ($u) { $rider = $u; $vid = $v; break; }
}
ok('a booker and a non-planner rider exist', (bool) ($booker && $rider), null, true);
if (count($planners) < 2 || !$booker || !$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }

[$p1, $p2] = $planners;
$RID = (int) $rider->id;
$tomorrow = \Carbon\Carbon::tomorrow()->format('Y-m-d');
echo "  · planners={$p1->id},{$p2->id} rider={$RID} vehicle={$vid}\n";

/**
 * A PROPOSAL, not a booking. `schedule()` writes `proposed` only for a user who may schedule
 * but may not approve; when the booker is also a planner it is accepted outright, so the row
 * is forced to `proposed` here rather than depending on who the fixture picked.
 */
$propose = function () use ($wv, $booker, $vid, $RID, $tomorrow) {
    $r = $wv->schedule($booker, [
        'vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $tomorrow,
        'visit_time' => '10:00', 'workshop' => 'Snooze Motors', 'confirm_replace' => 1,
    ]);
    if (empty($r['ok'])) { echo "      ⚠ refused: " . ($r['message'] ?? '?') . "\n"; return $r; }
    DB::table(WV::T_VISIT)->where('id', $r['visit_id'])->update(['status' => 'proposed']);
    return $r;
};
$idsFor = fn ($u) => array_map(fn ($r) => (int) $r['id'], $wv->pendingApprovals($u, false, 50));

// ─────────────────────────────────────────────────
section('§1 ⏰ a snooze hides it from THIS planner, for six hours');

DB::beginTransaction();
try {
    $r = $propose(); $id = (int) $r['visit_id'];
    ok('the proposal is on his card', in_array($id, $idsFor($p1), true), true);

    $s = $wv->snoozeProposal($p1, $id);
    ok('⭐ he can put it off', $s['ok'] ?? false, true);
    ok('  …and is told for how long', str_contains((string) ($s['message'] ?? ''), '6'), true);
    ok('  ⭐⭐ …it leaves HIS card', in_array($id, $idsFor($p1), true), false);

    // …and comes back once the six hours are up.
    DB::table('t_ops_alert_dismissal')
        ->where('user_id', $p1->id)->where('alert_key', 'wsapproval:' . $id)
        ->update(['dismissed_at' => now()->subHours(7)]);
    ok('  ⭐ …and returns after six hours, still undecided', in_array($id, $idsFor($p1), true), true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§2 ⭐⭐ …and never hides it from the OTHER planner');

DB::beginTransaction();
try {
    $r = $propose(); $id = (int) $r['visit_id'];
    $wv->snoozeProposal($p1, $id);
    ok('it is gone from the snoozer', in_array($id, $idsFor($p1), true), false);
    ok('⭐⭐ …but still on the other planner\'s card', in_array($id, $idsFor($p2), true), true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§3 ⚠ a snooze is NOT a decision');

DB::beginTransaction();
try {
    $r = $propose(); $id = (int) $r['visit_id'];
    $wv->snoozeProposal($p1, $id);
    ok('⭐ the visit is still PROPOSED', DB::table(WV::T_VISIT)->where('id', $id)->value('status'), 'proposed');
    ok('  …not declined', (bool) DB::table(WV::T_VISIT)->where('id', $id)->value('declined_at'), false);

    // …and an already-decided one cannot be snoozed at all.
    DB::table(WV::T_VISIT)->where('id', $id)->update(['status' => 'scheduled']);
    $s = $wv->snoozeProposal($p1, $id);
    ok('⚠ a decided proposal cannot be put off', $s['already_decided'] ?? null, true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§4 ✅ once ANYONE decides, it leaves every card (already true)');

DB::beginTransaction();
try {
    $r = $propose(); $id = (int) $r['visit_id'];
    ok('both planners see it',
       in_array($id, $idsFor($p1), true) && in_array($id, $idsFor($p2), true), true);
    $wv->decline($p2, $id, 'not needed');
    ok('⭐ after one declines, it is gone from BOTH',
       !in_array($id, $idsFor($p1), true) && !in_array($id, $idsFor($p2), true), true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§5 ⚠⚠ a planner is not asked to approve his OWN workshop day');

DB::beginTransaction();
try {
    /**
     * The proposal names the PLANNER himself as the rider — the case an approval queue exists
     * to prevent.
     *
     * ⚠ Written straight to the table rather than through `schedule()`, because that door
     *   refuses a rider who does not hold the machine, and whether a given planner happens to
     *   hold one today is a fixture accident. The row is what `pendingApprovals()` reads, so
     *   this tests the filter on exactly the shape it will meet in production.
     */
    $r  = $propose();
    $id = (int) $r['visit_id'];
    DB::table(WV::T_VISIT)->where('id', $id)->update(['user_id' => (int) $p1->id]);

    ok('⭐⭐ he is NOT asked to approve his own workshop day', in_array($id, $idsFor($p1), true), false);
    ok('  ⭐ …but the other planner still is', in_array($id, $idsFor($p2), true), true);
} finally { DB::rollBack(); }

echo "\n────────────────────────────────────────────────────────────\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed 0\n"
                 : "passed $pass, FAILED $fail\n";
exit($fail === 0 ? 0 : 1);
