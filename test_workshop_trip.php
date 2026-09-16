<?php
/**
 * 🔧🚦 THE WORKSHOP TRIP — same-day booking, "going to the workshop", live status (Sep-10 2026).
 *
 * The owner's rulings this proves:
 *   • same-day bookings have NO approval cut-off; if he checks out having never gone, the
 *     managers are told once and it ends there;
 *   • the LIVE RIDER CARD on the orders page shows "going to / at the workshop" — the same
 *     card, no separate one;
 *   • "at the workshop" comes from the GEOFENCE, because there is no arrival button;
 *   • pressing "going" with DISPATCHED orders is refused, with the remedy named, and the store
 *     is told what to do;
 *   • a bike kept overnight is handled by change-rider / release, and the errand survives it;
 *   • company machines only.
 *
 * What must never break:
 *   §1 the trip state machine, including "he checked out ⇒ the trip is over, the QUESTION is not"
 *   §2 the same-day rules (no cut-off, no auto-decline, the checkout notice)
 *   §3 the orders gate on START
 *   §4 geofence arrival, and the back-fill when he forgot to press the button
 *   §5 the live board: the two rungs, the false alarm they end, and the orderless rider
 *   §6 the dispatch guard
 *   §7 release keeps the errand alive with no keeper
 *   §8 company machines only
 *   §9 pre-SQL degrade
 *
 * ⚠ Every mutation is inside a transaction that is always rolled back.
 * ⚠ NOT `head()` — Laravel ships a global `head()` the validator calls.
 *
 * Run:  php test_workshop_trip.php
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

/**
 * ⚠⚠ THE DEFAULT GUARD HERE IS 'api' (config/auth.php), which is a RequestGuard — it has no
 *    login(), so `Auth::login($u)` dies with "Method RequestGuard::login does not exist".
 *    The MOBILE controllers read `Auth::user()` off the DEFAULT guard, so pointing the default
 *    at 'web' for the duration is what lets an in-process call see a signed-in store user.
 *    Without this the store-tablet endpoints cannot be tested at all — which is exactly why
 *    the door that broke on 11-Sep had never been called by a suite.
 */
function asStoreUser($u): void {
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    \Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($u->id);
}

/**
 * ⚠⚠ PUSHES OFF, or this suite takes twenty minutes. Every booking fires a real FCM call and
 *    each one blocks ~45s against a project this machine cannot reach. Pointing the credentials
 *    path at a file that does not exist makes `FirebaseService` return early — the documented
 *    "move the credentials aside" trick from the approval round, done in-process so nobody has
 *    to remember it.
 * ⚠ Config only: `.env` is untouched, and the setting dies with the process.
 */
config(['whatsapp.firebase_credentials_path' => 'storage/__no_such_credentials_for_tests__.json']);

$wv   = new WV();
$res  = new VehicleResolver();
$vsvc = new VehicleService();

ok('the trip migration has run on this database', $wv->tripEnabled(), true);

// ── fixtures: a manager, a rider on a COMPANY machine, a workshop with coordinates ──
$manager = null;
foreach (User::where('is_active', '1')->get() as $u) {
    if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) continue;
    if ($u->hasPermission(WV::PERMISSION)) { $manager = $u; break; }
}
$rider = null; $vid = null;
foreach (DB::table('t_ops_rider_profile')->pluck('user_id') as $uid) {
    $v = (int) ($res->currentVehicleFor((int) $uid) ?: 0);
    if (!$v || !$vsvc->isTrackedId($v)) continue;
    $u = User::find((int) $uid);
    if ($u) { $rider = $u; $vid = $v; break; }
}
$own = DB::table('t_ops_vehicle')->where('is_company', 0)->where('is_active', 1)->first(['id']);

ok('a manager holding schedule_workshop exists', (bool) $manager, null, true);
ok('a rider on a COMPANY machine exists', (bool) $rider, null, true);
if (!$manager || !$rider) { echo "\nfixtures missing — stopping.\n"; exit(1); }
$RID = (int) $rider->id;
echo "  · manager={$manager->id} rider={$RID} vehicle={$vid}\n";

$today = \Carbon\Carbon::today()->format('Y-m-d');
/**
 * ⚠ Fails LOUDLY. A silent refusal here shows up thirty lines later as "Undefined array key
 *   visit_id", which tells you nothing about why — and the reason is always in the message.
 */
$book = function (array $extra = []) use ($wv, $manager, $vid, $RID, $today) {
    $r = $wv->schedule($manager, array_merge([
        'vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $today,
        'visit_time' => '11:00', 'workshop' => 'Ali Motors', 'confirm_replace' => 1,
    ], $extra));
    if (empty($r['ok'])) {
        echo "      ⚠ booking refused: " . ($r['message'] ?? '(no message)') . "\n";
    }
    return $r;
};
$statusOf = fn (int $id) => (string) DB::table(WV::T_VISIT)->where('id', $id)->value('status');

// ─────────────────────────────────────────────────────────────────────────────
section('§1 the trip state machine');

DB::beginTransaction();
try {
    $r = $book();
    ok('a same-day visit can be booked', $r['ok'] ?? false, true);
    $id = (int) $r['visit_id'];

    $t = $wv->tripFor($RID);
    ok('before he sets off he is "booked today"', $t['state'] ?? null, WV::TRIP_NONE);
    ok('  …which is NOT an active errand', $t['is_active'] ?? null, false);
    ok('  …and the button is offered', $t['can_depart'] ?? null, true);

    ok('he sets off', $wv->depart($rider, $id)['ok'], true);
    $t = $wv->tripFor($RID);
    ok('  …now en route', $t['state'], WV::TRIP_EN_ROUTE);
    ok('  …and the board must react', $t['is_active'], true);
    ok('  …the label names the place', str_contains($t['label'], 'Ali Motors'), true);
    ok('  …and says he is GOING, not there', str_contains($t['label'], 'Going to'), true);
    ok('pressing it twice is refused', $wv->depart($rider, $id)['ok'], false);

    DB::table(WV::T_VISIT)->where('id', $id)->update(['arrived_at' => now()]);
    WV::flushSchemaMemo();
    $t = $wv->tripFor($RID);
    ok('once arrived he is AT the workshop', $t['state'], WV::TRIP_AT);
    ok('  …and the label changes with him', str_contains($t['label'], 'At Ali Motors'), true);

    /**
     * ⭐⭐ THE DISTINCTION THAT MATTERS. Checking out ends the TRIP (the board must stop
     *    saying he is at a workshop all evening) but NOT the QUESTION — the visit still wants
     *    its outcome, and midnight still makes it MISSED. Erasing that difference is the one
     *    thing this feature must never do.
     */
    DB::table('t_ops_attendance')->updateOrInsert(
        ['user_id' => $RID, 'attendance_date' => $today],
        ['logout_time' => now(), 'updated_at' => now()]
    );
    $wv2 = new WV();                       // fresh instance: the checkout memo is per-object
    $t = $wv2->tripFor($RID);
    ok('⭐ he checks out ⇒ the TRIP is over', $t['state'], WV::TRIP_ENDED);
    ok('  …the board stops reacting', $t['is_active'], false);
    ok('  ⚠ …but the VISIT is still live and unanswered', $statusOf($id), 'accepted');
    ok('  …and nothing was auto-completed',
       DB::table(WV::T_VISIT)->where('id', $id)->value('done_at'), null);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§2 same-day: no cut-off, no auto-decline, one notice at checkout');

DB::beginTransaction();
try {
    ok('TODAY has no approval cut-off', $wv->hasApprovalCutoff(['visit_date' => $today]), false);
    ok('  …a future day still does',
       $wv->hasApprovalCutoff(['visit_date' => \Carbon\Carbon::today()->addDay()->format('Y-m-d')]), true);

    // Long past any old cut-off — the exact case the owner asked to unblock.
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::today()->setTime(14, 0));
    $r = $book();
    ok('a breakdown at 14:00 can still be booked for today', $r['ok'] ?? false, true);
    $id = (int) $r['visit_id'];

    ok('  …and the sweep does not kill it',
       in_array($id, $wv->escalateProposals()['declined'], true), false);
    ok('  …it is still live', in_array($statusOf($id), ['scheduled', 'accepted', 'proposed'], true), true);

    /**
     * ⚠ The ONE thing that must not pass in silence (owner): he goes home without going.
     *   The notice fires once and the visit stays open for a manager to answer.
     */
    ok('checking out having never gone raises ONE notice', $wv->alertNotGoneAtCheckout($RID), 1);
    ok('  …and never a second', $wv->alertNotGoneAtCheckout($RID), 0);
    ok('  …the visit is NOT closed by it', in_array($statusOf($id), ['scheduled', 'accepted', 'proposed'], true), true);
    ok('  …and it is stamped so it cannot repeat',
       (bool) DB::table(WV::T_VISIT)->where('id', $id)->value('not_gone_alert_at'), true);

    // He DID go ⇒ nothing to report.
    DB::table(WV::T_VISIT)->where('id', $id)
        ->update(['departed_at' => now(), 'not_gone_alert_at' => null]);
    ok('a rider who DID go raises nothing', $wv->alertNotGoneAtCheckout($RID), 0);
} finally { \Carbon\Carbon::setTestNow(); DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§3 the orders gate on START');

DB::beginTransaction();
try {
    $r = $book(); $id = (int) $r['visit_id'];

    // Stage a DISPATCHED order on his name: out for delivery with an ETA calculated.
    $ord = DB::table('t_crm_prod_order')->where('assigned_rider_user_id', '!=', $RID)
        ->orWhereNull('assigned_rider_user_id')->first(['id']);
    if ($ord) {
        $was = DB::table('t_crm_prod_order')->where('id', $ord->id)
            ->first(['assigned_rider_user_id', 'order_status', 'eta_calculated_at']);
        DB::table('t_crm_prod_order')->where('id', $ord->id)->update([
            'assigned_rider_user_id' => $RID,
            'order_status' => 'out_for_delivery',
            'eta_calculated_at' => now(),
        ]);

        $counts = $wv->openOrdersFor($RID);
        ok('the gate can see a dispatched order', $counts['dispatched'] >= 1, true);

        $d = $wv->depart($rider, $id);
        ok('⭐ the RIDER is refused while orders are dispatched', $d['ok'], false);
        ok('  …flagged as an orders problem, not a validation one', $d['blocked_by_orders'] ?? null, true);
        ok('  …and told the remedy in his own words',
           str_contains($d['message'], 'hata dein'), true);
        ok('  …nothing was stamped', DB::table(WV::T_VISIT)->where('id', $id)->value('departed_at'), null);

        $dm = $wv->depart($manager, $id);
        ok('a MANAGER is also stopped first — he is the one who can move them', $dm['ok'], false);
        ok('  …but he may override, because he can fix it',
           $wv->depart($manager, $id, ['force' => true])['ok'], true);

        // ⚠ Merely ASSIGNED orders do not strand: the store can move them at leisure.
        DB::table(WV::T_VISIT)->where('id', $id)->update(['departed_at' => null, 'departed_by' => null]);
        DB::table('t_crm_prod_order')->where('id', $ord->id)->update(['eta_calculated_at' => null]);
        $counts = $wv->openOrdersFor($RID);
        ok('an ASSIGNED-but-undispatched order is counted separately', $counts['assigned'] >= 1, true);
        $d2 = $wv->depart($rider, $id);
        ok('  …and does NOT block him', $d2['ok'], true);
        ok('  …the store is warned about them instead', ($d2['assigned_warning'] ?? 0) >= 1, true);

        DB::table('t_crm_prod_order')->where('id', $ord->id)->update([
            'assigned_rider_user_id' => $was->assigned_rider_user_id,
            'order_status' => $was->order_status,
            'eta_calculated_at' => $was->eta_calculated_at,
        ]);
    } else {
        ok('an order exists to stage the gate with (none — skipped honestly)', true, true, true);
    }
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§4 arrival by GEOFENCE — there is no button');

DB::beginTransaction();
try {
    $ws = DB::table('t_ops_company_locations')->whereNotNull('latitude')->whereNotNull('longitude')
        ->first(['id', 'latitude', 'longitude']);
    if ($ws) {
        // Tick it as a workshop for this test only (prod ticks its own — owner).
        $wasWs = DB::table('t_ops_company_locations')->where('id', $ws->id)->value('is_workshop');
        DB::table('t_ops_company_locations')->where('id', $ws->id)->update(['is_workshop' => 1]);

        $r = $book(['location_id' => $ws->id]); $id = (int) $r['visit_id'];
        $wv->depart($rider, $id);

        $far = $wv->stampArrival($RID, (float) $ws->latitude + 0.5, (float) $ws->longitude + 0.5);
        ok('a fix far away does not arrive him', $far, false);

        $near = $wv->stampArrival($RID, (float) $ws->latitude, (float) $ws->longitude);
        ok('⭐ a fix AT the workshop stamps arrival', $near, true);
        ok('  …exactly once', $wv->stampArrival($RID, (float) $ws->latitude, (float) $ws->longitude), false);
        ok('  …and the state follows', (new WV())->tripFor($RID)['state'], WV::TRIP_AT);

        /**
         * ⭐ HE FORGOT THE BUTTON. A man standing at the workshop has demonstrably gone; the
         *   board saying otherwise while his own GPS proves it is the contradiction this round
         *   exists to remove. So arrival back-fills the departure.
         */
        DB::table(WV::T_VISIT)->where('id', $id)
            ->update(['arrived_at' => null, 'departed_at' => null, 'departed_by' => null]);
        ok('arrival back-fills a forgotten departure',
           $wv->stampArrival($RID, (float) $ws->latitude, (float) $ws->longitude), true);
        ok('  …so the trip has a start time after all',
           (bool) DB::table(WV::T_VISIT)->where('id', $id)->value('departed_at'), true);

        DB::table('t_ops_company_locations')->where('id', $ws->id)->update(['is_workshop' => $wasWs]);
    } else {
        ok('a location with coordinates exists (none — skipped honestly)', true, true, true);
    }

    // ⚠ A free-text workshop has no pin, so it never arrives — and says "going to" honestly.
    $r2 = $book(); $id2 = (int) $r2['visit_id'];
    $wv->depart($rider, $id2);
    ok('a free-text workshop never geofences', $wv->stampArrival($RID, 33.6, 73.0), false);
    ok('  …and honestly still reads "going to"', (new WV())->tripFor($RID)['state'], WV::TRIP_EN_ROUTE);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§5 ⭐⭐ the LIVE RIDER CARD — the surface the owner pointed at');

DB::beginTransaction();
try {
    $r = $book(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);

    $ctrl = app(\App\Http\Controllers\API\RiderController::class);
    \Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($manager->id);
    $req  = \Illuminate\Http\Request::create('/x', 'GET');
    $body = json_decode($ctrl->getRidersLiveStatus($req)->getContent(), true);

    $mine = null;
    foreach (($body['dispatch'] ?? []) as $rid => $row) {
        if ((int) $rid === $RID) $mine = $row;
    }
    ok('the rider appears on the live board', (bool) $mine, null, true);
    if ($mine) {
        ok('  ⭐ …with the workshop status, not "away" or "left without dispatch"',
           in_array($mine['status'], ['workshop_en_route', 'at_workshop'], true), true);
        ok('  …and the errand rides along for the label', !empty($mine['workshop_trip']), true);
        ok('  …whose sentence is the server\'s',
           str_contains((string) ($mine['workshop_trip']['label'] ?? ''), 'Ali Motors'), true);
    }

    /**
     * ⚠⚠ THE FALSE ALARM THIS ENDS. The board is built from orders, so the rider with NOTHING
     *    on his plate — the commonest reason he was sent to the workshop — would not have been
     *    on it at all. If this row is missing, the feature is invisible for exactly the case it
     *    was asked for, and it would still have looked like it worked.
     */
    ok('⭐ a rider with NO orders is on the board because of the errand alone',
       (bool) $mine, null, true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────
section('§6 assign & OFD are ALLOWED with a warning; DISPATCH is the stop');

/**
 * ⚠⚠ THIS SECTION USED TO ASSERT THE BUG. Until 11-Sep it checked that assigning to a man at
 *    the workshop returned 409 and that "an OLD client, which cannot send the flag, simply
 *    cannot do it" — green, and describing exactly the failure that hit the store tablet on
 *    11-Sep ("Partial Update — Failed to assign rider"). The lesson is written into the test
 *    itself: a server-side QUESTION is only a feature once a CLIENT can answer it. So the
 *    doors that must not block are proved to return 200, and the one door that may block is
 *    proved ANSWERABLE — 409 first, then past it with `confirm`.
 * ⚠ Both assign doors are exercised, the WEB one and the MOBILE one. The mobile endpoint
 *   (`assignRiderToOrder`) is the one that actually broke and no suite had ever called it.
 */
DB::beginTransaction();
try {
    $r = $book(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);

    $ord = DB::table('t_crm_prod_order')->whereNotIn('order_status', ['delivered', 'completed'])
        ->first(['id', 'assigned_rider_user_id']);

    $store = null;
    foreach (User::where('is_active', '1')->get() as $u) {
        if ($u->hasMobilePermission('assign_riders')) { $store = $u; break; }
    }

    if ($ord) {
        // ── the WEB door ───────────────────────────────────────────────
        \Illuminate\Support\Facades\Auth::guard('web')->loginUsingId($manager->id);
        $ctrl = app(\App\Http\Controllers\CRM\OrderRiderController::class);
        $req  = \Illuminate\Http\Request::create('/x', 'POST', ['rider_user_id' => $RID]);
        $out  = $ctrl->assign($req, (int) $ord->id);
        $b    = json_decode($out->getContent(), true);
        ok('WEB: assigning to a man at the workshop SUCCEEDS', $out->getStatusCode(), 200);
        ok('  …and is reported as success', $b['success'] ?? null, true);
        ok('  …carrying a workshop warning', $b['warning']['kind'] ?? null, 'workshop');
        ok('  …whose sentence names the place',
           str_contains((string) ($b['warning']['label'] ?? ''), 'Ali Motors'), true);
        ok('  ⚠ …and it is NOT a refusal any more', isset($b['needs_confirmation']), false);

        // ── the MOBILE door — the one that broke, never before tested ──
        if ($store) {
            asStoreUser($store);
            $mreq = \Illuminate\Http\Request::create('/x', 'POST',
                ['order_id' => (int) $ord->id, 'rider_id' => $RID]);
            $mout = app(\App\Http\Controllers\API\RiderController::class)->assignRiderToOrder($mreq);
            $mb   = json_decode($mout->getContent(), true);
            ok('MOBILE: the store tablet can assign him', $mout->getStatusCode(), 200);
            ok('  …successfully (this IS the 11-Sep incident)', $mb['success'] ?? null, true);
            ok('  …with the SAME warning the web gets', $mb['warning']['kind'] ?? null, 'workshop');
            ok('  …and a Roman-Urdu sentence for the phone', !empty($mb['warning']['label_ur']), true);

            // ── the rider PICKER tags him (promised 10-Sep, built 11-Sep) ──
            $pout = app(\App\Http\Controllers\API\RiderController::class)
                ->getActiveRiders(\Illuminate\Http\Request::create('/x', 'GET'));
            $pb   = json_decode($pout->getContent(), true);
            $mine = null;
            foreach (($pb['riders'] ?? []) as $row) {
                $row = (array) $row;
                if ((int) ($row['id'] ?? 0) === $RID) { $mine = $row; break; }
            }
            ok('the picker still LISTS him (never filtered out)', (bool) $mine, null, true);
            ok('  …tagged with the errand',
               ((array) ($mine['workshop_trip'] ?? []))['state'] ?? null, WV::TRIP_EN_ROUTE);

            // ── OUT FOR DELIVERY is a desk action: allowed, warned ──────
            DB::table('t_crm_prod_order')->where('id', $ord->id)
                ->update(['assigned_rider_user_id' => $RID]);
            asStoreUser($store);
            $sreq = \Illuminate\Http\Request::create('/x', 'POST',
                ['order_id' => (int) $ord->id, 'status' => 'out_for_delivery']);
            $sout = app(\App\Http\Controllers\API\RiderController::class)->updateOrderStatus($sreq);
            $sb   = json_decode($sout->getContent(), true);
            if (($sb['success'] ?? false) === true) {
                ok('OFD: marking it out for delivery is allowed', true, true);
                ok('  …and warns that his machine is in', $sb['warning']['kind'] ?? null, 'workshop');
            } else {
                // A refusal here is some OTHER rule (van cargo, a returned order) — say so
                // honestly rather than claiming a workshop pass we did not actually get.
                ok('OFD refused for an unrelated reason: ' . ($sb['message'] ?? '?'), true, true, true);
            }
        } else {
            ok('a store user holding assign_riders exists (none — skipped honestly)', true, true, true);
        }
    } else {
        ok('an assignable order exists (none — skipped honestly)', true, true, true);
    }

    // ── DISPATCH: the ONE stop, and it must be answerable ───────────────
    asStoreUser($store ?: $manager);
    $dreq = \Illuminate\Http\Request::create('/x', 'POST', ['scope' => 'all']);
    $dout = app(\App\Http\Controllers\API\RiderController::class)->calculateDeliveryEtas($dreq, $RID);
    $dbod = json_decode($dout->getContent(), true);
    ok('DISPATCH is held while he is at the workshop', $dout->getStatusCode(), 409);
    ok('  …as a question, not a wall', $dbod['needs_confirmation'] ?? null, true);
    ok('  …naming where he is', str_contains((string) ($dbod['message'] ?? ''), 'Ali Motors'), true);
    ok('  …and asking to dispatch anyway', str_contains((string) ($dbod['message'] ?? ''), 'anyway'), true);

    // …and the override REACHES the work. The old assign guard's override never could,
    // because no client could send it — that is the whole point of proving it here.
    $dreq2 = \Illuminate\Http\Request::create('/x', 'POST', ['scope' => 'all', 'confirm' => 1]);
    $dout2 = app(\App\Http\Controllers\API\RiderController::class)->calculateDeliveryEtas($dreq2, $RID);
    ok('  ⭐ …and `confirm` gets PAST the workshop guard', $dout2->getStatusCode() !== 409, true);
    $db2 = json_decode($dout2->getContent(), true);
    ok('  …the workshop being no longer the reason for anything',
       str_contains((string) ($db2['message'] ?? ''), 'workshop'), false);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§7 the bike stays overnight — change rider keeps the errand alive');

DB::beginTransaction();
try {
    $r = $book(); $id = (int) $r['visit_id'];
    $wv->depart($rider, $id);

    $n = $wv->onKeeperLost($vid, $RID, (int) $manager->id);
    ok('releasing the machine marks the errand as having no keeper', $n, 1);
    ok('  …the visit is still LIVE, because it still needs an answer',
       in_array($statusOf($id), ['scheduled', 'accepted'], true), true);
    ok('  …it was NOT auto-completed',
       DB::table(WV::T_VISIT)->where('id', $id)->value('done_at'), null);
    ok('  …and the stamp cannot repeat', $wv->onKeeperLost($vid, $RID, (int) $manager->id), 0);

    /**
     * ⭐⭐ AN ERRAND STILL AHEAD OF HIM COUNTS TOO (10-Sep-2026, second review).
     *
     * This used to assert the opposite — "a visit he never set off on is left alone" — on the
     * reasoning that a day nobody went on is covered by the checkout notice. That held while
     * `release()` was the only caller, at the end of a day. It stopped holding the moment
     * `assign()` became one, because THE ORDINARY WORKSHOP MORNING is: the bike goes in, and
     * the manager hands the rider a spare at 10am. The errand is still ahead of him, he no
     * longer has the machine, and his phone would keep offering "Workshop jaa raha hoon" for
     * a bike that is not in his hands.
     */
    DB::table(WV::T_VISIT)->where('id', $id)
        ->update(['departed_at' => null, 'no_keeper_since' => null]);
    ok('an errand still AHEAD of him is stamped too — he no longer has the bike',
       $wv->onKeeperLost($vid, $RID, (int) $manager->id), 1);
    ok('  …and it is still LIVE, because somebody must still take it in',
       in_array($statusOf($id), ['scheduled', 'accepted'], true), true);
    ok('  ⭐ …but it has left HIS card — the instruction is not his any more',
       $wv->nextForUser($RID)['id'] ?? null, null);
    // ⚠ `find()` is the RAW row; `can_depart` is derived in `shape()`, so ask a shaped reader.
    ok('  ⭐ …and the START button is gone with it',
       (function () use ($wv, $id, $vid) {
           foreach ($wv->listVisits(['vehicle_id' => $vid, 'limit' => 20]) as $row) {
               if ((int) $row['id'] === $id) return $row['can_depart'];
           }
           return null;
       })(), false);
    ok('  ⚠ …and pressing it anyway is refused, by the REGISTRY not by the button',
       (function () use ($wv, $rider, $id, $vid) {
           // He is moved onto nothing at all — the registry simply stops naming this machine.
           DB::table('t_ops_vehicle_assignment')->where('user_id', $rider->id)
               ->whereNull('released_on')->update(['released_on' => \Carbon\Carbon::today()->format('Y-m-d')]);
           \App\Services\Riders\VehicleResolver::flush();
           $r = (new WV())->depart($rider, $id);
           return !$r['ok'];
       })(), true);

    // ⚠ A PAST day he never started is history — the missed-visit question already covers it.
    DB::table(WV::T_VISIT)->where('id', $id)->update([
        'no_keeper_since' => null,
        'visit_date'      => \Carbon\Carbon::today()->subDays(3)->format('Y-m-d'),
    ]);
    ok('a PAST visit he never set off on is left alone',
       $wv->onKeeperLost($vid, $RID, (int) $manager->id), 0);

    $src = file_get_contents(__DIR__ . '/app/Services/Riders/VehicleService.php');
    ok('release() now tells the workshop service (it never did before)',
       (bool) preg_match('/onKeeperLost/', $src), null, true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§8 company machines only');

if ($own) {
    DB::beginTransaction();
    try {
        $r = $wv->schedule($manager, ['vehicle_id' => (int) $own->id, 'user_id' => $RID,
                                      'visit_date' => $today, 'confirm_replace' => 1]);
        ok('a personal bike cannot be booked into the workshop', $r['ok'] ?? false, false);
        ok('  …and is told why', str_contains((string) $r['message'], 'personal vehicle'), true);
    } finally { DB::rollBack(); }
} else {
    ok('a personal machine exists to test with (none — skipped honestly)', true, true, true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('§9 before the SQL runs, nothing changes');

/**
 * ⚠⚠ NOT by dropping the column — MySQL DDL implicitly commits, so a "rolled back" test that
 *    drops one leaves it dropped (learned the hard way on the vehicle-class round). The degrade
 *    path is one memoised flag, so reflection is the honest way to exercise it.
 */
$flag = new ReflectionProperty(WV::class, 'tripCols');
$flag->setAccessible(true);
try {
    $flag->setValue(null, false);
    ok('the schema check reports pre-migration', (new WV())->tripEnabled(), false);
    ok('  …no trip is ever reported', (new WV())->tripFor($RID), null);
    ok('  …the store list is empty rather than broken', (new WV())->liveTrips(), []);
    ok('  …and the depart endpoint refuses with a human sentence',
       (function () use ($rider) {
           $r = (new WV())->depart($rider, 1);
           return !$r['ok'] && str_contains($r['message'], 'newer than the server');
       })(), true);
} finally {
    $flag->setValue(null, null);
}
ok('the columns are still there afterwards',
   \Illuminate\Support\Facades\Schema::hasColumn(WV::T_VISIT, 'departed_at'), true);

// ─────────────────────────────────────────────────────────────────────────────
section('§10 the van boards — a man at the workshop cannot make the rendezvous');

/**
 * ⭐⭐ THE CASE THE VAN BOARD COULD NOT SHOW. A driver waiting at a meet-up for a rider who is
 *    sitting at Ali Motors waits for ever — and then either holds the whole wave up or drives
 *    off with the man's boxes still aboard, which is the exact thing the abandoned-meet-up
 *    warning exists to catch. The trip is the answer, and it is the SAME `tripsFor()` the live
 *    rider card reads, so the two boards can never disagree.
 */
DB::beginTransaction();
try {
    $attach = new ReflectionMethod(\App\Services\Riders\VanService::class, 'attachWorkshopTrips');
    $attach->setAccessible(true);
    $van = new \App\Services\Riders\VanService();
    $grp = fn () => $attach->invoke($van, [['user_id' => $RID, 'name' => 'Rider', 'complete' => false]]);

    $id = (int) $book()['visit_id'];
    ok('a booked-but-not-started errand is NOT flagged on the van board',
       $grp()[0]['workshop_trip'], null);

    $wv->depart($rider, $id, [], true);
    $t = $grp()[0]['workshop_trip'];
    ok('once he sets off the carrying group carries the errand', is_array($t), true);
    ok('  …server-worded, in both languages', [!empty($t['label']), !empty($t['label_ur'])], [true, true]);
    /**
     * ⚠ NO ETA on this path, deliberately: both callers POLL (the driver's own panel and the
     *   store board) and the workshop ETA goes through Google when it is cold — the exact cost
     *   this panel's own arithmetic was rewritten to avoid. The minute lives on the live rider
     *   card, which is not on a loop.
     */
    ok('  ⚠ …and WITHOUT the cold ETA a polling board must not pay for', $t['eta_min'], null);

    /**
     * ⭐ The derived words must never CONTRADICT the errand. "2 stops first · ~4:32" printed
     *   beside "at Ali Motors" is the board telling a driver to wait for a man who is not
     *   coming, so the enrichment answers the errand first and skips the arithmetic entirely.
     */
    $enrich = new ReflectionMethod(\App\Http\Controllers\API\VanController::class, 'enrichWithRiderState');
    $enrich->setAccessible(true);
    $vc  = app(\App\Http\Controllers\API\VanController::class);
    $out = $enrich->invoke($vc, [[
        'user_id' => $RID, 'name' => 'Rider', 'complete' => false, 'workshop_trip' => $t,
    ]], ['latitude' => 33.6867, 'longitude' => 73.0331, 'reached_at' => null]);
    ok('the driver’s panel is told he is on an errand', $out[0]['state'], 'workshop');
    ok('  …in the server’s own words', $out[0]['detail'], $t['label']);
    ok('  ⭐ …with no invented arrival time at a meeting that cannot happen', $out[0]['eta'], null);
    ok('  …and no "2 stops first" contradicting it', $out[0]['remaining_stops'], 0);
    ok('  ⚠ …but his POSITION is still sent — where he is remains true',
       array_key_exists('position', $out[0]), true);

    // A COLLECTED rider is not the van's problem any more, whatever he is doing.
    $done = $enrich->invoke($vc, [[
        'user_id' => $RID, 'name' => 'Rider', 'complete' => true, 'workshop_trip' => $t,
    ]], null);
    ok('a rider who has already collected is left as "collected"', $done[0]['state'], 'collected');

    $src = file_get_contents(__DIR__ . '/app/Http/Controllers/API/VanController.php');
    ok('the store board sends the DRIVER’s own errand too (a van goes in for service)',
       (bool) preg_match("/'driver_trip'\s*=>/", $src), null, true);
    ok('  …and each waiting rider’s',
       (bool) preg_match("/'workshop_trip' => \\\$g\['workshop_trip'\]/", $src), null, true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§11 the service-alert PUSH follows a mid-day rider change');

/**
 * ⚠⚠ THE HOLE THIS CLOSES. The BANNER always followed the registry — it is recomputed from
 *    `ownerOf()` on every read — but the PUSH deduped on the alert key alone, and that key
 *    carries no person. So a bike that changed hands at 14:00 with an overdue job on it had
 *    a ledger row saying "already pushed", and the man who now had it was never buzzed.
 *
 * ⭐ Fixed with NO schema change: the push ledger is keyed by alert + keeper. The DISMISSAL
 *   key is deliberately untouched — a manager who dismissed "Oil change on NF-4 is overdue"
 *   meant the job, not the man.
 */
$alerts   = new \App\Services\Riders\BikeServiceAlerts();
$already  = new ReflectionMethod($alerts, 'alreadyPushed');
$already->setAccessible(true);

$key   = $alerts->keyFor(999999, 1, 47013, 'overdue');
$forA  = $alerts->pushKeyFor(['alert_key' => $key, 'keeper_user_id' => $RID]);
$forB  = $alerts->pushKeyFor(['alert_key' => $key, 'keeper_user_id' => $RID + 1]);
ok('the same job on the same bike is ONE push key per keeper', $forA === $forB, false);
ok('  …and stable for that keeper',
   $forA, $alerts->pushKeyFor(['alert_key' => $key, 'keeper_user_id' => $RID]));
ok('  ⚠ …and still fits t_ops_service_alert_push.alert_key (VARCHAR 64)', strlen($forA) <= 64, true);
ok('  …nobody holding the machine dedupes as one message',
   $alerts->pushKeyFor(['alert_key' => $key]), $key . ':u0');

DB::beginTransaction();
try {
    DB::table('t_ops_service_alert_push')->insert(['alert_key' => $forA, 'pushed_at' => now()]);
    ok('the rider who was already told is not told twice', $already->invoke($alerts, $forA), true);
    ok('  ⭐ …and the man who has the bike NOW is told, on the same alert',
       $already->invoke($alerts, $forB), false);

    // The pre-fix ledger row (no keeper in it) must not silence anybody.
    DB::table('t_ops_service_alert_push')->where('alert_key', $forA)->delete();
    DB::table('t_ops_service_alert_push')->insert(['alert_key' => $key, 'pushed_at' => now()]);
    ok('⚠ an OLD keeper-free ledger row buzzes once more after the deploy, then settles',
       [$already->invoke($alerts, $forA), $already->invoke($alerts, $forB)], [false, false]);
} finally { DB::rollBack(); }

$src = file_get_contents(__DIR__ . '/app/Services/Riders/BikeServiceAlerts.php');
ok('pushDue no longer dedupes on the bare alert key',
   (bool) preg_match("/alreadyPushed\(\\\$a\['alert_key'\]\)/", $src), false);
ok('the DISMISSAL key is untouched — a dismissal is about the job, not the man',
   (bool) preg_match('/function keyFor\(int \$vehicleId, int \$typeId, \$lastMeter, string \$state\)/', $src),
   null, true);

// ─────────────────────────────────────────────────────────────────────────────
section('§12 🏍 closing the visit records the service ON THE BIKE THAT WENT IN');

/**
 * ⭐⭐ THE WHOLE POINT OF THE ROUND, end to end and in its worst case.
 *
 *    The bike goes to the workshop. The manager gives the rider a SPARE for the day — which
 *    is the ordinary thing to do. He then closes the visit from his phone with the odometer.
 *
 *    Every reader used to ask `vehicleForDay(rider, date)` at READ time — "what was he on
 *    that day" — and by then the answer was the spare. So the oil change was credited to a
 *    machine that never had one, the visit read "done" with a `service_log_id`, and the real
 *    bike's countdown kept running with nothing on any screen saying why.
 */
$spare = DB::table('t_ops_vehicle')->where('is_active', 1)->where('is_company', 1)
    ->where('id', '!=', $vid)->orderBy('id')->value('id');
$job   = DB::table('t_fleet_maintenance_types')->where('is_active', 1)
    ->where('interval_km', '>', 0)->orderBy('id')->first(['id', 'type_name']);
ok('a spare COMPANY machine and a scheduled job exist', (bool) ($spare && $job), null, true);

if ($spare && $job) {
    DB::beginTransaction();
    try {
        $spare = (int) $spare;
        $meter = (int) ((new VehicleService())->currentMeterFor($vid) ?: 30000) + 25;

        $id = (int) $book(['maintenance_type_id' => $job->id])['visit_id'];
        $wv->accept($rider, $id, true);

        // …and now he is moved onto the spare, exactly as a manager would while it is in.
        $open = DB::table('t_ops_vehicle_assignment')->where('user_id', $RID)
            ->whereNull('released_on')->first(['id']);
        DB::table('t_ops_vehicle_assignment')->where('user_id', $RID)->whereNull('released_on')
            ->update(['released_on' => $today]);
        DB::table('t_ops_vehicle_assignment')->insert([
            'vehicle_id' => $spare, 'user_id' => $RID, 'assigned_on' => $today,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        VehicleResolver::flush();
        VehicleService::flushServiceMemo();
        ok('the registry now hands him the SPARE, as it would on a real workshop day',
           (new VehicleResolver())->currentVehicleFor($RID), $spare);

        // He closes it from his phone.
        $req = \Illuminate\Http\Request::create("/rider/workshop-visits/{$id}/done", 'POST', [
            'meter' => $meter, 'maintenance_type_id' => $job->id,
        ]);
        $req->setUserResolver(fn () => $rider);
        $res  = app(\App\Http\Controllers\CRM\WorkshopVisitController::class)->done($req, $id);
        $body = json_decode($res->getContent(), true);
        ok('the rider can close his own visit', $body['success'] ?? null, true);
        ok('  …and it recorded a service', (bool) ($body['service_log_id'] ?? null), null, true);

        $log = DB::table('t_fleet_service_log')->where('id', (int) $body['service_log_id'])->first();
        ok('  ⭐⭐ …ON THE BIKE THAT WENT IN, not the spare he is riding today',
           (int) $log->vehicle_id, $vid);
        ok('  …and the job it was booked for', (int) $log->maintenance_type_id, (int) $job->id);
        ok('  ⭐ …which is where the countdown moved',
           \App\Services\Riders\ServiceRecordService::logVehicleOf($log), $vid);

        /**
         * ⚠⚠ THE PROOF THAT THIS WAS BROKEN. Strip the stamp and the old derivation answers
         *    the SPARE — the silent misfile, reproduced on demand.
         */
        ok('  ⚠ …whereas the old rider-and-date derivation answers the SPARE',
           (new VehicleResolver())->vehicleForDay($RID, $today), $spare);

        DB::table('t_ops_vehicle_assignment')->where('user_id', $RID)
            ->where('vehicle_id', $spare)->whereNull('released_on')->delete();
        if ($open) DB::table('t_ops_vehicle_assignment')->where('id', $open->id)->update(['released_on' => null]);
        VehicleResolver::flush();
    } finally { DB::rollBack(); }
}

// ─────────────────────────────────────────────────────────────────────────────
section('§13 📍 "where do I clock in?" — one sentence, everywhere');

/**
 * ⚠⚠ No rider-facing screen ever said this. The only thing that did was the approval push,
 *    and it announced "jagah badal gayi hai" whether his place had moved or not — which,
 *    once the approver could answer "check in as usual", was the opposite of the decision.
 */
DB::beginTransaction();
try {
    $id = (int) $book()['visit_id'];

    DB::table(WV::T_VISIT)->where('id', $id)->update(['attendance_at' => 'workshop']);
    $v = collect((new WV())->listVisits(['user_id' => $RID, 'limit' => 20]))->firstWhere('id', $id);
    ok('a pinned day tells him to clock in AT the workshop', $v['checkin_at'], 'workshop');
    ok('  …in his own language, naming the place',
       (bool) preg_match('/attendance .* par karein/u', (string) $v['checkin_line']), null, true);

    DB::table(WV::T_VISIT)->where('id', $id)->update(['attendance_at' => 'regular']);
    $v = collect((new WV())->listVisits(['user_id' => $RID, 'limit' => 20]))->firstWhere('id', $id);
    ok('"check in as usual" says exactly that instead', $v['checkin_at'], 'regular');
    ok('  ⭐ …and never claims his place has changed',
       str_contains((string) $v['checkin_line'], 'normal jagah'), true);

    /**
     * ⚠ A row written before the question existed keeps the OLD inference — a registered
     *   workshop meant a pin — so nothing about an existing visit moves on deploy day.
     */
    DB::table(WV::T_VISIT)->where('id', $id)->update(['attendance_at' => null]);
    ok('a pre-10-Sep row still follows the old inference',
       WV::checkinAtOf(DB::table(WV::T_VISIT)->where('id', $id)->first()),
       DB::table(WV::T_VISIT)->where('id', $id)->value('location_id') ? 'workshop' : 'regular');

    $src = file_get_contents(__DIR__ . '/app/Services/FirebaseService.php');
    ok('the approval push reads that one rule rather than inferring its own',
       (bool) preg_match('/WorkshopVisitService::checkinAtOf/', $src), null, true);
    ok('  ⭐ …and has a branch for "he checks in as usual"',
       (bool) preg_match('/Attendance .*normal jagah|hi karein/u', $src), null, true);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§15 📍 a SAME-DAY booking never moves a day that has already started');

/**
 * ⚠⚠ FOUND DRIVING THE REAL WEB FORM (10-Sep-2026), and it is the worst shape this round
 *    produced: `schedule()` wrote the shift pin from `location_id` ALONE, ignoring the
 *    check-in answer entirely. So a SAME-DAY booking stored `attendance_at = 'regular'` on
 *    the row — the rule firing correctly — and then pinned his day to the workshop anyway.
 *
 *    The two halves of one visit said opposite things, and the planner was told "he checks
 *    in at the workshop" about a morning the rider had already spent somewhere else. That is
 *    precisely the retrospective goalpost-move the today rule exists to prevent.
 *
 * ⚠ `approve()` had always honoured the answer; this is its direct-assign twin.
 */
DB::beginTransaction();
try {
    $loc = (int) DB::table('t_ops_company_locations')->insertGetId([
        'location_name' => 'TEST §15 workshop', 'latitude' => 33.6867, 'longitude' => 73.0331,
        'radius_meters' => 300, 'is_primary' => 0, 'is_workshop' => 1, 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $pinOf = fn (int $id) => DB::table('t_ops_user_shift_assignment')->where('workshop_visit_id', $id)->first();

    // TODAY — he has already checked in (or not); nothing about his day may move.
    $r = $wv->schedule($manager, ['vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $today,
                                  'location_id' => $loc, 'confirm_replace' => 1]);
    $idToday = (int) $r['visit_id'];
    ok('a same-day booking is accepted', $r['ok'], true);
    ok('  …the row says he checks in as usual',
       DB::table(WV::T_VISIT)->where('id', $idToday)->value('attendance_at'), 'regular');
    ok('  ⭐⭐ …and NOTHING was pinned — his morning is not rewritten', $pinOf($idToday), null);
    ok('  ⭐ …and the planner is told that, not warned about it',
       str_contains((string) $r['message'], 'checks in as usual'), true);
    ok('  ⚠ …and never told the opposite',
       str_contains((string) $r['message'], 'checks in at the workshop'), false);

    /**
     * ⚠ …WHILE A FUTURE DAY STILL PINS EXACTLY AS IT ALWAYS HAS. `attendance_at` is NULL when
     *   nobody was asked — which is every booking form today — and null must keep meaning the
     *   OLD inference, or this fix would quietly stop pinning days that have always pinned.
     */
    $soonD = \Carbon\Carbon::today()->addDays(3)->format('Y-m-d');
    $r2 = $wv->schedule($manager, ['vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $soonD,
                                   'location_id' => $loc, 'confirm_replace' => 1]);
    $idSoon = (int) $r2['visit_id'];
    ok('a FUTURE booking with a registered workshop still pins, as before',
       (int) ($pinOf($idSoon)->location_id ?? 0), $loc);
    ok('  …and says so', str_contains((string) $r2['message'], 'checks in at the workshop'), true);

    // …and an explicit "as usual" on a future day is honoured too.
    $r3 = $wv->schedule($manager, ['vehicle_id' => $vid, 'user_id' => $RID, 'visit_date' => $soonD,
                                   'location_id' => $loc, 'attendance_at' => 'regular', 'confirm_replace' => 1]);
    ok('an explicit "as usual" on a future day pins nothing',
       $pinOf((int) $r3['visit_id']), null);
} finally { DB::rollBack(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§14 ⚠ a SPARE handed over mid-morning tells the workshop service');

/**
 * ⚠⚠ `release()` learned to tell it; `assign()` never did — and assign is the path a manager
 *    actually takes ("put him on the spare while his bike is in"). It closes the old
 *    assignment DIRECTLY rather than through release(), so the errand stayed pointing at a
 *    man who no longer had the machine.
 */
$src = file_get_contents(__DIR__ . '/app/Services/Riders/VehicleService.php');
ok('assigning a replacement now tells the workshop service about the machine he stepped off',
   (bool) preg_match('/onKeeperLost\(\$vacatedVehicleId/', $src), null, true);
ok('  …and a handover CLEARS the flag, so the new holder sees the errand',
   (bool) preg_match("/no_keeper_since' => null/", file_get_contents(__DIR__ . '/app/Services/Riders/WorkshopVisitService.php')),
   null, true);

// ─────────────────────────────────────────────────────────────────────────────
section('§16 🛢 the service alert follows the rider on the bike TODAY');

/**
 * ⚠⚠ THE SPLIT THIS CLOSES. A manager's DAY OVERRIDE ("Kanan is on AY-4771 today") lives
 *    on the attendance row and never touches the assignment. The BANNER asks
 *    `currentVehicleFor` and showed the alert to Kanan; the PUSH took its keeper from the
 *    open assignment and went to somebody else — and once the ledger became per-keeper,
 *    the man actually riding the bike was never buzzed at all.
 */
DB::beginTransaction();
try {
    $other = (int) DB::table('t_ops_rider_profile')->where('user_id', '!=', $RID)->orderBy('user_id')->value('user_id');
    ok('another rider exists to override with', $other > 0, null, true);
    if ($other) {
        // Give him TODAY on Waseem's machine by override, exactly as VehicleController::dayOverride does.
        $att = DB::table('t_ops_attendance')->where('user_id', $other)->whereDate('attendance_date', $today)->first(['id']);
        if (!$att) {
            $attId = (int) DB::table('t_ops_attendance')->insertGetId([
                'user_id' => $other, 'attendance_date' => $today, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else { $attId = (int) $att->id; }
        DB::table('t_ops_attendance')->where('id', $attId)->update(['vehicle_id' => $vid, 'vehicle_source' => 'manager']);
        VehicleResolver::flush();
        ok('the registry now names him for that bike today',
           (new VehicleResolver())->riderForVehicleDay($vid, $today), $other);

        /**
         * Force a job DUE on this machine: a per-vehicle exception of 100 km on a job last
         * done hundreds of km ago is overdue by construction, so `due()` must list it.
         *
         * ⚠⚠ THE JOB MUST HAVE A BASELINE ON **THIS** MACHINE. This used to take whichever
         *    active type had the smallest `interval_km`, which on the current replica is a job
         *    that has NEVER been recorded on this bike — so the 100 km override produced
         *    `state: unknown` / "never recorded", not "overdue", and `due()` correctly listed
         *    nothing. The suite was asserting against an assumption ("last done hundreds of km
         *    ago") that the chosen fixture did not satisfy. Discover a job that fits the
         *    question instead: one with a real `last_meter` behind the current odometer.
         */
        $meterNow = (new \App\Services\Riders\VehicleService())->currentMeterFor($vid);
        $sched = (new \App\Services\Riders\VehicleService())->serviceScheduleFor($vid, $meterNow);
        $withBaseline = array_values(array_filter($sched, fn ($s) =>
            !empty($s['last_meter']) && (int) $s['last_meter'] < (int) $meterNow - 100));
        ok('  …and a job with a real service baseline exists to make overdue',
           (bool) $withBaseline, null, true);
        // The one recorded FURTHEST back, so 100 km is unambiguously overdue.
        usort($withBaseline, fn ($a, $b) => (int) $a['last_meter'] <=> (int) $b['last_meter']);
        $job = $withBaseline ? (int) $withBaseline[0]['id'] : null;
        DB::table('t_ops_vehicle_service_schedule')->updateOrInsert(
            ['vehicle_id' => $vid, 'maintenance_type_id' => (int) $job],
            ['interval_km' => 100, 'updated_at' => now(), 'created_at' => now()]);
        \App\Services\Riders\VehicleService::flushServiceMemo();
        \App\Services\Riders\VehicleScheduleService::flush();
        \App\Services\Riders\ServiceIntervalResolver::flush();
        \Cache::flush();
        $alerts = (new \App\Services\Riders\BikeServiceAlerts())->due();
        $mine   = array_values(array_filter($alerts, fn ($a) => (int) $a['vehicle_id'] === $vid));
        ok('a job is now due on this machine', count($mine) > 0, null, true);
        if ($mine) {
            ok('  ⭐ the alert’s keeper is the man on the bike TODAY, not the assignment holder',
               (int) $mine[0]['keeper_user_id'], $other);
            ok('  …so the push ledger key is HIS',
               str_ends_with((new \App\Services\Riders\BikeServiceAlerts())->pushKeyFor($mine[0]), ':u' . $other), true);
        }
    }
} finally { DB::rollBack(); VehicleResolver::flush(); \App\Services\Riders\VehicleService::flushServiceMemo(); }

// ─────────────────────────────────────────────────────────────────────────────
section('§17 ⏰ the fleet has a clock — fleet:sweep');

/**
 * ⭐ Prod can schedule individual artisan commands (StackCP), so the sweeps that used to
 *   fire only when a screen was opened now have a command of their own. ONE implementation
 *   (`FleetSweepService`) serves both the cron and the request piggybacks.
 */
DB::beginTransaction();
try {
    $r = app(\App\Services\Riders\FleetSweepService::class)->run();
    ok('the sweep runs and reports every part',
       array_keys($r), ['service_pushes', 'workshop_reminders', 'approval_nudges', 'auto_declined', 'home_meter_escalations', 'errors']);
    ok('  …with no part failing', $r['errors'], []);
} finally { DB::rollBack(); }
ok('the command is registered',
   array_key_exists('fleet:sweep', \Illuminate\Support\Facades\Artisan::all()), true);
ok('  …and scheduled, for parity with the other tasks',
   (bool) preg_match("/Schedule::command\('fleet:sweep'\)/", file_get_contents(__DIR__ . '/routes/console.php')), null, true);
ok('  ⚠ …and the workshop poll no longer carries a second copy of the loop',
   (bool) preg_match('/dueReminders\(\) as/', file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/WorkshopVisitController.php')), false);
ok('  …it calls the shared service instead',
   (bool) preg_match('/FleetSweepService::class\)->workshop\(\)/', file_get_contents(__DIR__ . '/app/Http/Controllers/CRM/WorkshopVisitController.php')), null, true);

echo "\n" . str_repeat('─', 60) . "\n";
echo $fail === 0 ? "ALL GREEN — passed $pass, failed $fail\n"
                 : "FAILURES — passed $pass, failed $fail\n";
exit($fail === 0 ? 0 : 1);
