<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Riders\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The Vehicles view of the Bikes tab (Aug-2026) — registering bikes and the van,
 * and giving them to riders.
 *
 * ACCESS, in two layers, deliberately separate:
 *   READING  — the same three keys that already open Bikes (FleetFuelController::
 *              PERMISSIONS). Anyone who can see running costs can see the fleet
 *              those costs belong to; no new access is created.
 *   WRITING  — `assign_vehicles`, a NEW key (batch 13). Reusing manage_bike_service
 *              would have handed assignment to everyone holding it (Qasim, Adnan,
 *              expense-fund); the owner asked for Shabib + Taimur, with Farooq
 *              addable later by ticking his role.
 *
 * ⚠ THE WRITE GATE IS THE PERMISSION ALONE — never a role-type check. Farooq's
 *   role is type='rider', so any admin|manager|supervisor gate would silently
 *   refuse him even with the box ticked. That exact bug blocked Qasim from filing
 *   bike claims until Jul-29.
 */
class VehicleController extends Controller
{
    /** Same read gate as the Bikes tab — keep in sync with FleetFuelController. */
    const VIEW_PERMISSIONS = ['view_rider_reports', 'web_menu_finance_hub', 'view_bike_costs'];

    /** Photos are the deliverable here, so allow a decent phone photo. */
    const MAX_PHOTO_KB = 8192;

    // =================================================================
    // READ
    // =================================================================

    /** The fleet, plus the roster the assign modal picks from. */
    public function index(Request $request, VehicleService $svc)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            // Not an error — the screen renders a "run batch 13" note instead. An
            // upload landing before the SQL must degrade, never explode.
            if (!$svc->available()) {
                return response()->json([
                    'success'   => true,
                    'available' => false,
                    'vehicles'  => [], 'riders' => [],
                    'can_manage' => false,
                    'message'   => 'Vehicles are not set up yet (SQL batch 13 has not been run).',
                ]);
            }

            return response()->json([
                'success'    => true,
                'available'  => true,
                'vehicles'   => $svc->all($request->boolean('include_retired')),
                'riders'     => $this->roster(),
                'can_manage' => $this->canManage(),
                // Photos are a separate, wider right — see canAddPhotos().
                'can_add_photos' => $this->canAddPhotos(),
                'transfer_grace_km' => $svc->transferGraceKm(),
                'rules_enabled'     => $svc->rulesEnabled(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the fleet'], 500);
        }
    }

    /** One vehicle with its assignment history and condition photos. */
    public function show(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $v = $svc->find((int) $id);
            if (!$v) return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);

            // ⭐ THE MACHINE'S spend — the SAME `claimsForVehicle` the rider's own
            //    profile reads, so a manager and a rider can never be shown two
            //    different reconstructions of one bike's history. Month-scoped;
            //    `?month=YYYY-MM` for the picker, defaults to this month.
            $month = $request->query('month');
            try {
                $m = $month ? \Carbon\Carbon::parse($month . '-01') : \Carbon\Carbon::today();
            } catch (\Throwable $e) {
                $m = \Carbon\Carbon::today();
            }
            $claims = $svc->claimsForVehicle((int) $id,
                $m->copy()->startOfMonth()->format('Y-m-d'),
                $m->copy()->endOfMonth()->format('Y-m-d'));

            $fuel = 0.0; $maint = 0.0;
            foreach ($claims as $c) {
                if (($c['category'] ?? '') === 'Petrol') $fuel += (float) $c['amount'];
                else $maint += (float) $c['amount'];
            }

            return response()->json([
                'success'    => true,
                'vehicle'    => $v,
                'riders'     => $this->roster(),
                'can_manage' => $this->canManage(),
                // Photos are a separate, wider right — see canAddPhotos().
                'can_add_photos' => $this->canAddPhotos(),
                'transfer_grace_km' => $svc->transferGraceKm(),
                'month'      => $m->format('Y-m'),
                'claims'     => $claims,
                // Totalled from the rows above, never a second calculation.
                'claims_total' => ['fuel_rs' => round($fuel, 2), 'maint_rs' => round($maint, 2),
                                   'count' => count($claims)],
                // The bike's Rs/km: selected month vs previous 3 pooled — the
                // SAME method the rider's screen reads.
                'averages'   => $svc->fuelAverages((int) $id, $m->format('Y-m')),
                // The current keeper's stint — the rider-vs-machine diagnostic.
                'keeper_stint' => $svc->keeperStintStats((int) $id),
                // ⭐ Per-type service schedule, keyed to THIS machine (owner ask,
                //   Aug-6) — anchored on the same current meter the header shows.
                'service_schedule' => $svc->serviceScheduleFor((int) $id,
                    isset($v['current_meter']) && $v['current_meter'] !== null ? (int) $v['current_meter'] : null),
                // ⭐ The machine's own service RECORD (owner ask, Aug-13). The list
                //   existed only on the rider's drill-down, so a handover split a
                //   bike's history between two people and neither list was the bike's.
                'service_history' => $svc->serviceHistoryFor((int) $id),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController show failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load that vehicle'], 500);
        }
    }

    /**
     * ⭐ THE MONTH'S KILOMETRES, DAY BY DAY — the receipt behind the figure on the
     *    profile (owner ruling, Aug-5).
     *
     * Its OWN endpoint, loaded only when a manager asks for it. The profile popover
     * opens on every "Profile ▸" click; the day list walks each keeper's attendance
     * and both meter anchors, which is real work to do for a panel most openings
     * never scroll to. Lazy keeps the popover as quick as it is today.
     */
    public function days(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            try {
                $m = $request->query('month')
                    ? \Carbon\Carbon::parse($request->query('month') . '-01')
                    : \Carbon\Carbon::today();
            } catch (\Throwable $e) {
                $m = \Carbon\Carbon::today();
            }
            return response()->json(['success' => true]
                + $svc->monthDays((int) $id, $m->format('Y-m')));
        } catch (\Throwable $e) {
            Log::error('VehicleController days failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the day list'], 500);
        }
    }

    /**
     * ⭐ WHICH MACHINE A CLAIM WILL LAND ON (owner ask, Aug-5).
     *
     * Every fuel/maintenance form — the rider's own, the manager's "for someone
     * else", the web modal — now names the bike before the claim is filed, so the
     * team can see what they are filing against. It follows reassignment by itself
     * because it asks the registry, and it is keyed to the CLAIM'S DATE, not today:
     * a backdated claim lands on the bike he had THAT day (which is exactly how the
     * server stamps it), so the form must say the same thing.
     *
     * Returns the machine only — no costs, no meter history. If the caller may not
     * look up other people's assignments it returns `vehicle: null` rather than an
     * error, so a form never breaks over a label.
     */
    public function forUser(Request $request, \App\Services\Riders\VehicleResolver $res, VehicleService $svc)
    {
        $me = $request->user() ?: auth()->user();
        // No user_id = "which bike is MINE" — what the rider's own form asks.
        $uid  = (int) ($request->query('user_id') ?: ($me->id ?? 0));
        $date = $request->query('date') ?: \Carbon\Carbon::today()->format('Y-m-d');
        try {
            $date = \Carbon\Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            $date = \Carbon\Carbon::today()->format('Y-m-d');
        }

        $isSelf = $me && (int) $me->id === $uid;
        $mayLook = $isSelf || $this->canView() || $this->mobileAllowed($request);
        if (!$uid || !$mayLook) {
            return response()->json(['success' => true, 'vehicle' => null]);
        }

        try {
            $vid = $res->vehicleForDay($uid, $date);
            if (!$vid) return response()->json(['success' => true, 'vehicle' => null]);

            $v = $svc->find($vid);
            if (!$v) return response()->json(['success' => true, 'vehicle' => null]);

            return response()->json([
                'success' => true,
                'date'    => $date,
                'vehicle' => [
                    'id'          => $v['id'],
                    'name'        => $v['name'],
                    'vtype'       => $v['vtype'],
                    'is_company'  => $v['is_company'],
                    'keeper_name' => $v['keeper_name'] ?? null,
                    'since'       => $v['keeper_since'] ?? null,
                    // The machine's live service state, so a form can show what it is
                    // about to be measured against.
                    'service'     => $v['service'] ?? null,
                ],
                /**
                 * ⭐ WHAT IS ALREADY ON RECORD (owner ask, Aug-16): "show them the last
                 *    maintenance entry so they know what they are entering, what is
                 *    already there, when, and by whom."
                 *
                 * ⭐ SERVED FROM THE ENDPOINT ALL FOUR FILING SURFACES ALREADY CALL —
                 *    the web modal, the manager's mobile form, the rider's own form and
                 *    the store form each fetch this to name the bike. Attaching the
                 *    context here means one implementation, one set of figures, and no
                 *    screen inventing a lookup that could disagree with the panel it
                 *    sits next to. It also means every one of them gains the feature at
                 *    once, with no new permission and no new route.
                 *
                 * Keyed to the MACHINE the claim will land on, not to the person filing
                 * it, so a manager filing for someone else sees that bike's history and
                 * a rider newly given a machine sees what his predecessor had done.
                 */
                'last_maintenance' => $svc->lastMaintenanceFor((int) $vid),
            ]);
        } catch (\Throwable $e) {
            Log::warning('VehicleController forUser failed', ['user' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['success' => true, 'vehicle' => null]);
        }
    }

    // =================================================================
    // MOBILE (Aug-2026) — the manager's Bikes screen, read only
    // =================================================================

    /**
     * ⭐ THE FLEET IN THE APP (owner ask, Aug-5). The Bikes screen on mobile showed
     *    RIDERS and their costs; the machines themselves — who has what, what each
     *    one costs to run, and now where its kilometres went — were web-only. A
     *    manager in the field could not answer "what has this bike done this month?"
     *
     * ⚠ READ ONLY, and that is deliberate. Assigning, releasing, editing, condition
     *   photos and base pins stay on the web: they are consequential, they want a
     *   real screen, and `assign_vehicles` has never been a mobile grant. These three
     *   endpoints add no write path and no new access — the gate is the SAME mobile
     *   `view_bike_costs` key the Bikes screen already runs on (FleetFuelController::
     *   mobileAllowed), so anyone who can see running costs can see the fleet they
     *   belong to, exactly as on the web.
     */
    public function apiIndex(Request $request, VehicleService $svc)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            if (!$svc->available()) {
                return response()->json(['success' => true, 'available' => false, 'vehicles' => []]);
            }
            return response()->json([
                'success'   => true,
                'available' => true,
                'vehicles'  => $svc->all(false),
                // Assigning/releasing/editing still belong to the web — the phone
                // has no endpoint for them, so it must not offer them.
                'can_manage' => false,
                // ⭐ …but condition photos CAN now be added from the phone
                //   (apiAddPhotos). Separate flag on purpose: it is the only
                //   write the app has, and conflating it with can_manage would
                //   light up buttons that would 404.
                'can_add_photos' => $this->canAddPhotos(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController apiIndex failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the fleet'], 500);
        }
    }

    /** One machine: its state, its month's claims, its averages and the keeper's stint. */
    public function apiShow(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            $v = $svc->find((int) $id);
            if (!$v) return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);

            $m = $this->safeMonth($request->query('month'));
            $claims = $svc->claimsForVehicle((int) $id,
                $m->copy()->startOfMonth()->format('Y-m-d'),
                $m->copy()->endOfMonth()->format('Y-m-d'));

            $fuel = 0.0; $maint = 0.0;
            foreach ($claims as $c) {
                if (($c['category'] ?? '') === 'Petrol') $fuel += (float) $c['amount'];
                else $maint += (float) $c['amount'];
            }

            return response()->json([
                'success'      => true,
                'vehicle'      => $v,
                'month'        => $m->format('Y-m'),
                'claims'       => $claims,
                'claims_total' => ['fuel_rs' => round($fuel, 2), 'maint_rs' => round($maint, 2),
                                   'count' => count($claims)],
                'averages'     => $svc->fuelAverages((int) $id, $m->format('Y-m')),
                'keeper_stint' => $svc->keeperStintStats((int) $id),
                // Additive — current APKs ignore it; a future one renders the
                // same per-type schedule the web profile shows.
                'service_schedule' => $svc->serviceScheduleFor((int) $id,
                    isset($v['current_meter']) && $v['current_meter'] !== null ? (int) $v['current_meter'] : null),
                // May this manager record a condition photo from the phone?
                'can_add_photos' => $this->canAddPhotos(),
                'months'       => $this->recentMonths(),
            ]);
        } catch (\Throwable $e) {
            Log::error('VehicleController apiShow failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load that vehicle'], 500);
        }
    }

    /**
     * ⭐ ADD CONDITION PHOTOS FROM THE PHONE (owner ask, Aug-12).
     *
     * The handover happens in the yard, not at a desk — so the photo has to be
     * takeable where the bike is. Same store, same table, same validation as the
     * web door: this only adds a second entrance, never a second rulebook.
     *
     * ⚠⚠ TWO GATES, BOTH REQUIRED, AND THEY MEAN DIFFERENT THINGS:
     *   • mobileAllowed()  — may he open Bikes on the phone at all
     *                        (`view_bike_costs`, the MOBILE key);
     *   • canAddPhotos()   — may he RECORD CONDITION (`assign_vehicles` OR
     *                        `manage_bike_service`, WEB keys — `hasPermission()`
     *                        reads the web permission table and works identically
     *                        under Sanctum, so one grant governs both surfaces and
     *                        a manager can never be allowed on web but refused on
     *                        the phone). It also refuses read-only accounts, which
     *                        is why the mobile key alone is not enough.
     *
     * ⚠ NOT canManage(): assigning/releasing/editing stay web-only and are a
     *   narrower right. See canAddPhotos() for why the two were separated.
     *
     * Deliberately NOT a new permission key: a key that exists only in SQL stays
     * invisible on the Roles screen, and nobody would remember to tick it.
     */
    public function apiAddPhotos(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        if (!$this->canAddPhotos()) {
            return response()->json([
                'success' => false,
                'message' => 'You can view the fleet but not record photos — '
                    . 'ask for the bike-service or vehicle-assignment right.',
            ], 403);
        }

        $request->validate([
            'photos'   => 'required|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'context'  => 'nullable|string|max:16',
            'note'     => 'nullable|string|max:255',
            'date'     => 'nullable|date',
        ]);

        if (!$svc->find((int) $id)) {
            return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 404);
        }

        // storePhotos streams each file (putFileAs) — a phone sends full-size
        // photos, and reading 8 of them into memory would exhaust PHP's limit.
        $note = $this->storePhotos($request, $svc, (int) $id,
                                   $request->input('context', 'condition'),
                                   $request->input('date'), null, $request->input('note'));

        return response()->json([
            'success' => true,
            'message' => $note ?: 'Nothing was uploaded.',
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /**
     * 🛢 SERVICE ALERTS — the dismissable banner list for whoever is asking.
     *
     * ONE method serves web and mobile because the audience rule lives in the
     * service, not here: a manager holding `receive_service_alerts` gets the whole
     * fleet, the rider holding a machine gets that machine, anyone else gets an
     * empty list (so the banner never renders rather than being hidden client-side).
     *
     * ⚠ NO view permission is checked. That is deliberate — a rider has no fleet
     *   access at all, yet must be told his own bike is due. `forUser()` is the gate.
     *
     * Also the piggyback point for the push sweep: prod has no scheduler, so the
     * act of a manager or rider opening the app is what drives notifications
     * (throttled to ~once every 30 min, deferred so this response is not slowed).
     */
    public function serviceAlerts(Request $request)
    {
        try {
            $svc = new \App\Services\Riders\BikeServiceAlerts();
            $alerts = $svc->forUser($request->user() ?: auth()->user());
            $svc->fireFromRequest();
            return response()->json(['success' => true, 'alerts' => $alerts]);
        } catch (\Throwable $e) {
            // A broken banner must never break the screen it sits on.
            Log::warning('serviceAlerts failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'alerts' => []]);
        }
    }

    /** Wave one away — per user, per service cycle (see BikeServiceAlerts::keyFor). */
    public function dismissServiceAlert(Request $request)
    {
        $data = $request->validate(['alert_key' => 'required|string|max:64']);
        $u = $request->user() ?: auth()->user();
        if (!$u) return response()->json(['success' => false], 401);

        return response()->json([
            'success' => (new \App\Services\Riders\BikeServiceAlerts())
                ->dismiss((int) $u->id, $data['alert_key']),
        ]);
    }

    /** The month's kilometres day by day — lazy, exactly as on the web. */
    public function apiDays(Request $request, VehicleService $svc, $id)
    {
        if (!$this->mobileAllowed($request)) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        try {
            return response()->json(['success' => true]
                + $svc->monthDays((int) $id, $this->safeMonth($request->query('month'))->format('Y-m')));
        } catch (\Throwable $e) {
            Log::error('VehicleController apiDays failed', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load the day list'], 500);
        }
    }

    /**
     * The Bikes screen's OWN mobile key — the same gate FleetFuelController uses.
     * Authoritative on purpose: an additive gate would make unticking it useless.
     */
    private function mobileAllowed(Request $request): bool
    {
        $u = $request->user();
        if (!$u || !method_exists($u, 'hasMobilePermission')) return false;
        return $u->hasMobilePermission('view_bike_costs');
    }

    /** Never trust a month off the wire, and never let it run into the future. */
    private function safeMonth($raw): \Carbon\Carbon
    {
        try {
            $c = $raw ? \Carbon\Carbon::parse($raw . '-01') : \Carbon\Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            $c = \Carbon\Carbon::today()->startOfMonth();
        }
        if ($c->gt(\Carbon\Carbon::today()->startOfMonth())) $c = \Carbon\Carbon::today()->startOfMonth();
        return $c->startOfMonth();
    }

    /** The last 6 months, for the app's month switcher. */
    private function recentMonths(): array
    {
        $out = [];
        $c = \Carbon\Carbon::today()->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $out[] = ['value' => $c->format('Y-m'), 'label' => $c->format('M Y')];
            $c->subMonthNoOverflow();
        }
        return $out;
    }

    /**
     * What assigning this vehicle to this rider would do — read-only, so the modal
     * can state the consequences before the manager commits. Deliberately its own
     * endpoint: the sentences are computed from live data (who holds what, whose
     * home pin is missing), not guessed in JavaScript.
     */
    public function previewAssign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $userId = (int) $request->query('user_id');
        if ($userId <= 0) {
            return response()->json(['success' => false, 'message' => 'user_id required'], 422);
        }
        $p = $svc->previewAssign((int) $id, $userId, $request->query('date'));
        return response()->json(['success' => true] + $p);
    }

    // =================================================================
    // WRITE
    // =================================================================

    public function save(Request $request, VehicleService $svc, $id = null)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $data = $request->validate([
            'vtype'      => 'nullable|in:bike,van',
            'reg_no'     => 'nullable|string|max:32',
            'nickname'   => 'nullable|string|max:64',
            'is_company' => 'nullable|boolean',
            'make_model' => 'nullable|string|max:64',
            'notes'      => 'nullable|string|max:255',
            'is_active'  => 'nullable|boolean',
            'service_interval_km' => 'nullable|integer|min:0|max:200000',
        ]);

        $res = $svc->saveVehicle($data, $id ? (int) $id : null, (int) auth()->id());
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        // ⚠ This form can change `service_interval_km`, which the derived schedule
        //   reads — and the derivation is cached across requests. Without this bump a
        //   manager saved a new per-bike schedule here and the card kept showing the
        //   old countdown until the cache expired (verified: 2,500 → 600 invisible).
        //   Bump BEFORE the find() below, so the payload we hand back is already the
        //   new answer rather than the one we just invalidated.
        \App\Services\Riders\VehicleService::bumpServiceEvidence((int) $res['id']);

        return response()->json([
            'success' => true,
            'message' => $id ? 'Vehicle updated.' : 'Vehicle added.',
            'vehicle' => $svc->find((int) $res['id']),
        ]);
    }

    /**
     * Give the vehicle to a rider. Condition photos may ride along in the same
     * request (the manager is standing next to the bike with his phone) — but a
     * photo failure must NEVER undo the assignment, so they are stored
     * best-effort after the fact and reported separately.
     */
    public function assign(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'user_id' => 'required|integer',
            'date'    => 'nullable|date',
            'note'    => 'nullable|string|max:255',
            'photos'   => 'nullable|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            // ⭐ PHASE D — what happens to the rider losing this machine.
            'displaced_action'     => 'nullable|in:none,own,vehicle',
            'displaced_vehicle_id' => 'nullable|integer',
            // ⭐ Aug-2026 — the odometer as the bike changed hands. OPTIONAL, and
            //   deliberately validated no further than "a number": a handover is a
            //   thing that already happened, and refusing to record it over a
            //   questionable digit would leave the register wrong about who has the
            //   bike. The preview warns; the engine ignores an out-of-range value.
            'handover_meter'       => 'nullable|integer|min:0|max:9999999',
        ]);

        $res = $svc->assign((int) $id, (int) $data['user_id'], $data['date'] ?? null,
                            (int) auth()->id(), $data['note'] ?? null,
                            isset($data['handover_meter']) ? (int) $data['handover_meter'] : null);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }

        $photoNote = $this->storePhotos($request, $svc, (int) $id, 'handover_in',
                                        $data['date'] ?? null, $res['id'] ?? null);

        $displacedNote = $this->settleDisplacedRider(
            $svc, $res['displaced_user_id'] ?? null,
            $data['displaced_action'] ?? null, $data['displaced_vehicle_id'] ?? null,
            $data['date'] ?? null
        );

        return response()->json([
            'success'      => true,
            'message'      => trim($res['message'] . ' ' . $photoNote . ' ' . $displacedNote),
            'changed'      => $res['changed'] ?? true,
            'vehicle'      => $svc->find((int) $id),
        ]);
    }

    /**
     * ⭐ PHASE D — GIVE THE DISPLACED RIDER SOMEWHERE TO LAND.
     *
     * Taking a machine off someone used to be a half-finished act: his assignment
     * closed and nothing else happened, so he silently held nothing while the old
     * profile checkboxes still had him on the company fuel protocol and being asked
     * for meters. Now the manager says explicitly what happens to him, and the three
     * answers are the three real ones:
     *
     *   'vehicle' → he moves onto another machine (picked from the spares)
     *   'own'     → his own registered bike is handed back to him — the case nothing
     *               used to do, which is why a rider's own bike could sit unassigned
     *               and blind for days
     *   'none'    → he genuinely has nothing, and from now on the system stops
     *               asking him for meters instead of pestering him about a bike he
     *               does not have
     *
     * ⚠ RUNS AFTER the main handover, never inside it. The primary assignment is the
     *   thing that must be atomic; if this second step fails, the handover still
     *   stands and the manager can place the rider from the fleet screen. The
     *   reverse — losing a recorded handover because a follow-up failed — is worse.
     *   So this NEVER throws: it returns a sentence, and a failure is reported as a
     *   note rather than as a failed handover.
     */
    private function settleDisplacedRider(VehicleService $svc, ?int $userId,
                                          ?string $action, $vehicleId, ?string $date): string
    {
        if (!$userId) {
            return '';
        }

        // ⭐ OWNER RULING (Aug-8): a rider with his OWN registered bike falls back to
        //   it BY DEFAULT — "no bike" only when the manager says so specifically.
        //   The live case: Danish gave DCR-799 back on 7 Aug and was left holding
        //   nothing while his own bike sat unassigned, so the registry stopped
        //   asking him for meters the moment he went back to riding it. No answer
        //   from the client (or an older client that never asks) now means "back on
        //   his own bike" whenever that bike exists and is free.
        if (!$action) {
            $action = $svc->ownVehicleFor($userId) ? 'own' : null;
            if (!$action) {
                return '';   // nothing to fall back to — the fleet screen shows him under "no machine"
            }
        }
        if ($action === 'none') {
            return 'The previous rider now has no bike, so he will not be asked for meter readings.';
        }

        try {
            $target = null;
            if ($action === 'own') {
                $own = $svc->ownVehicleFor($userId);
                $target = $own['id'] ?? null;
                if (!$target) return 'His own bike could not be handed back — assign it from the fleet screen.';
            } elseif ($action === 'vehicle') {
                $target = (int) $vehicleId;
                if ($target <= 0) return 'No replacement bike was chosen, so he now has none.';
            }

            $r = $svc->assign((int) $target, $userId, $date, (int) auth()->id(), 'Moved after handover');
            return ($r['ok'] ?? false)
                ? 'He was moved onto ' . ($svc->find((int) $target)['name'] ?? 'another bike') . '.'
                : 'He could not be moved onto the other bike (' . ($r['message'] ?? 'unknown error') . ').';
        } catch (\Throwable $e) {
            Log::error('settleDisplacedRider failed (handover itself is recorded)', [
                'user_id' => $userId, 'action' => $action, 'error' => $e->getMessage(),
            ]);
            return 'The handover is recorded, but the previous rider could not be placed — do it from the fleet screen.';
        }
    }

    public function release(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'date'     => 'nullable|date',
            'photos'   => 'nullable|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'displaced_action'     => 'nullable|in:none,own,vehicle',
            'displaced_vehicle_id' => 'nullable|integer',
        ]);

        // The photos are of the state it came BACK in, so they belong to the
        // assignment being closed — grab it before it is released.
        $closing = $svc->keeperOf((int) $id);

        $res = $svc->release((int) $id, $request->input('date'), (int) auth()->id());
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }

        $photoNote = $this->storePhotos($request, $svc, (int) $id, 'handover_out',
                                        $request->input('date'), $closing->id ?? null);

        // Taking a bike back displaces its keeper exactly as reassigning does.
        $displacedNote = $this->settleDisplacedRider(
            $svc, $res['displaced_user_id'] ?? null,
            $data['displaced_action'] ?? null, $data['displaced_vehicle_id'] ?? null,
            $request->input('date')
        );

        return response()->json([
            'success' => true,
            'message' => trim($res['message'] . ' ' . $photoNote . ' ' . $displacedNote),
            'changed' => $res['changed'] ?? true,
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /** Who loses this machine if it is taken back, and where can he land? */
    public function releasePreview(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $current = $svc->keeperOf((int) $id);
        if (!$current) {
            return response()->json(['success' => true, 'displaced' => null]);
        }
        $uid = (int) $current->user_id;
        return response()->json([
            'success'   => true,
            'displaced' => [
                'user_id'    => $uid,
                'name'       => DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: 'the current rider',
                'own'        => $svc->ownVehicleFor($uid),
                // Company machines only — same reasoning as previewAssign's list.
                'spare'      => array_values(array_filter($svc->spareVehicles(),
                                    fn ($s) => $s['id'] !== (int) $id && $s['is_company'])),
                'goes_quiet' => $svc->rulesEnabled(),
            ],
        ]);
    }

    /** Set or clear the vehicle's fixed overnight base (the van's parking). */
    public function setBase(Request $request, VehicleService $svc, $id)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $data = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_m'  => 'nullable|integer|min:50|max:5000',
            'clear'     => 'nullable|boolean',
        ]);

        $clear = !empty($data['clear']);
        $res = $svc->setBase(
            (int) $id,
            $clear ? null : (isset($data['latitude'])  ? (float) $data['latitude']  : null),
            $clear ? null : (isset($data['longitude']) ? (float) $data['longitude'] : null),
            isset($data['radius_m']) ? (int) $data['radius_m'] : null,
            (int) auth()->id()
        );
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json([
            'success' => true, 'message' => $res['message'], 'vehicle' => $svc->find((int) $id),
        ]);
    }

    /** Add condition photos on their own (not tied to a handover). */
    public function addPhotos(Request $request, VehicleService $svc, $id)
    {
        // ⭐ Photos are their OWN right (canAddPhotos) — a bike-service manager may
        //   record condition without being able to move machines between riders.
        //   Web and phone must answer this identically or the same man would be
        //   allowed on one screen and refused on the other.
        if (!$this->canAddPhotos()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to record vehicle photos'], 403);
        }
        $request->validate([
            'photos'   => 'required|array|max:8',
            'photos.*' => 'image|max:' . self::MAX_PHOTO_KB,
            'context'  => 'nullable|string|max:16',
            'note'     => 'nullable|string|max:255',
            'date'     => 'nullable|date',
        ]);

        $ctx  = $request->input('context', 'condition');
        $note = $this->storePhotos($request, $svc, (int) $id, $ctx, $request->input('date'), null,
                                   $request->input('note'));

        return response()->json([
            'success' => true,
            'message' => $note ?: 'Nothing was uploaded.',
            'vehicle' => $svc->find((int) $id),
        ]);
    }

    /**
     * "He was on a different bike that day."
     *
     * The ONE place `t_ops_attendance.vehicle_id` is ever written. Everything else
     * about which machine a day was on is derived from the assignment timeline
     * (VehicleResolver) — this exists for the exception the timeline cannot know:
     * a bike borrowed for an afternoon, a breakdown, a swap nobody recorded at the
     * time. Sending `vehicle_id` empty clears the override and hands the day back
     * to the timeline.
     *
     * Manager-only by ruling (`assign_vehicles`) — riders do not declare their own.
     */
    public function dayOverride(Request $request, \App\Services\Riders\VehicleResolver $res)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to assign vehicles'], 403);
        }
        $data = $request->validate([
            'user_id'    => 'required|integer',
            'date'       => 'required|date',
            'vehicle_id' => 'nullable|integer',
        ]);

        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_ops_attendance', 'vehicle_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicles are not set up on this server yet (SQL batch 13).',
                ], 422);
            }

            $date = \Carbon\Carbon::parse($data['date'])->format('Y-m-d');
            $uid  = (int) $data['user_id'];
            $vid  = !empty($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;

            // Only a day the rider actually has an attendance row for — there is
            // nowhere else to hang the override, and inventing a row would create a
            // phantom working day out of a bookkeeping correction.
            $row = DB::table('t_ops_attendance')
                ->where('user_id', $uid)->whereDate('attendance_date', $date)
                ->first(['id']);
            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => 'There is no attendance record for that rider on ' . $date . ', so there is no day to correct.',
                ], 422);
            }

            if ($vid) {
                $exists = DB::table(VehicleService::T_VEHICLE)->where('id', $vid)->exists();
                if (!$exists) {
                    return response()->json(['success' => false, 'message' => 'That vehicle no longer exists.'], 422);
                }
            }

            DB::table('t_ops_attendance')->where('id', $row->id)->update([
                'vehicle_id'     => $vid,
                'vehicle_source' => $vid ? 'manager' : null,
            ]);
            \App\Services\Riders\VehicleResolver::flush();
            // ⚠ A day override moves that day's claims (and its readings) between
            //   machines, so BOTH the machine gaining the day and the one losing it
            //   have a different derivation now. The config counter is the honest
            //   blunt instrument here — we do not know which machine lost the day.
            \App\Services\Riders\VehicleService::bumpServiceConfig();

            $label = $vid ? $res->labelFor($vid) : null;
            Log::info('Vehicle day override set', [
                'user_id' => $uid, 'date' => $date, 'vehicle_id' => $vid, 'by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => $vid
                    ? 'Recorded: he was on ' . $label . ' on ' . $date . '.'
                    : 'Cleared — that day follows the normal assignment again.',
                'vehicle_id' => $vid,
                'label'      => $label,
            ]);
        } catch (\Throwable $e) {
            Log::error('dayOverride failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that change.'], 500);
        }
    }

    /** What a rider was on for each day of a month — powers the drill-down chips. */
    public function riderDays(Request $request, \App\Services\Riders\VehicleResolver $res)
    {
        if (!$this->canView()) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $uid   = (int) $request->query('rider_id');
        $month = $request->query('month') ?: date('Y-m');
        if ($uid <= 0) {
            return response()->json(['success' => false, 'message' => 'rider_id required'], 422);
        }

        try {
            $from = \Carbon\Carbon::parse($month . '-01')->startOfMonth()->format('Y-m-d');
            $to   = \Carbon\Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d');

            $rows = DB::table('t_ops_attendance')
                ->where('user_id', $uid)
                ->whereBetween('attendance_date', [$from, $to])
                ->orderBy('attendance_date')
                ->get(['attendance_date', 'vehicle_id', 'vehicle_source']);

            // ⚠ Resolved from ONE read of this rider's assignment history rather than
            //   per day. Calling vehicleForDay() in the loop re-queried the override
            //   table for all ~31 days — and the override is already in the row we
            //   just fetched. Same for the transfer check, which only needs the set of
            //   dates on which any of his assignments started or ended.
            $windows = DB::table(VehicleService::T_ASSIGN)
                ->where('user_id', $uid)
                ->orderBy('assigned_on')->orderBy('id')
                ->get(['vehicle_id', 'assigned_on', 'released_on']);

            $default = DB::table('t_ops_rider_profile')->where('user_id', $uid)->value('default_vehicle_id');

            $out = [];
            foreach ($rows as $r) {
                $d = substr((string) $r->attendance_date, 0, 10);

                if ($r->vehicle_id) {
                    $vid = (int) $r->vehicle_id;
                } else {
                    // Open row first, then the latest start — the same precedence
                    // VehicleResolver applies on a transfer day.
                    $vid = null; $bestOpen = false; $bestFrom = null;
                    foreach ($windows as $w) {
                        $f = substr((string) $w->assigned_on, 0, 10);
                        $t = $w->released_on ? substr((string) $w->released_on, 0, 10) : null;
                        if ($f > $d || ($t !== null && $t < $d)) continue;
                        $isOpen = $t === null;
                        if ($vid === null || ($isOpen && !$bestOpen) || (($isOpen === $bestOpen) && $f >= $bestFrom)) {
                            $vid = (int) $w->vehicle_id; $bestOpen = $isOpen; $bestFrom = $f;
                        }
                    }
                    if ($vid === null && $default) $vid = (int) $default;
                }

                $out[] = [
                    'date'       => $d,
                    'vehicle_id' => $vid,
                    'label'      => $res->labelFor($vid),   // cached per vehicle
                    'overridden' => $r->vehicle_id !== null,
                    'transfer'   => false,                  // filled in below
                ];
            }

            // Transfer days, keyed on the VEHICLE across ALL its keepers. Deriving it
            // from this rider's own windows would disagree whenever a day was
            // overridden onto a machine he was never formally assigned to.
            //
            // ⭐ Aug-2026: this used to be a third hand-written copy of the rule,
            //   carrying a comment that it "matched VehicleResolver::isTransferDay
            //   exactly" — which is precisely the kind of promise that quietly stops
            //   being true. It now ASKS the resolver, which is the one definition.
            $vids = array_values(array_unique(array_filter(array_column($out, 'vehicle_id'))));
            if ($vids) {
                foreach ($out as &$row) {
                    if ($row['vehicle_id']) {
                        $row['transfer'] = $res->isTransferDay((int) $row['vehicle_id'], $row['date']);
                    }
                }
                unset($row);
            }

            return response()->json(['success' => true, 'days' => $out, 'can_manage' => $this->canManage()]);
        } catch (\Throwable $e) {
            Log::error('riderDays failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load those days'], 500);
        }
    }

    public function deletePhoto(Request $request, VehicleService $svc, $photoId)
    {
        if (!$this->canManage()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to manage vehicles'], 403);
        }
        $res = $svc->deletePhoto((int) $photoId);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json(['success' => true, 'message' => $res['message']]);
    }

    // =================================================================
    // helpers
    // =================================================================

    /**
     * Store uploaded photos, best-effort. Returns a sentence for the caller to
     * append to its own message. NEVER throws — an assignment that succeeded must
     * not be reported as failed because a JPEG did not save.
     */
    private function storePhotos(Request $request, VehicleService $svc, int $vehicleId,
                                 string $context, ?string $date, ?int $assignmentId,
                                 ?string $note = null): string
    {
        if (!$request->hasFile('photos')) return '';

        $saved = 0; $failed = 0;
        foreach ((array) $request->file('photos') as $file) {
            try {
                if (!$file || !$file->isValid()) { $failed++; continue; }
                $path = $this->storeOne($file, $vehicleId, $context);
                $res  = $svc->addPhoto($vehicleId, $path, $date, $context, $note,
                                       (int) auth()->id(), $assignmentId);
                $res['ok'] ? $saved++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Vehicle photo upload failed', [
                    'vehicle_id' => $vehicleId, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($saved && !$failed) return $saved . ' photo' . ($saved === 1 ? '' : 's') . ' saved.';
        if ($saved && $failed)  return $saved . ' saved, ' . $failed . ' could not be uploaded.';
        if ($failed)            return 'The photo could not be uploaded — the rest was saved.';
        return '';
    }

    /**
     * Same folder convention as the attendance meter pictures.
     *
     * ⚠ Streamed via putFileAs, NOT file_get_contents. This endpoint accepts up to
     *   8 photos of up to 8 MB each; reading them all into strings would put ~64 MB
     *   through PHP's memory limit on one request. The single-photo meter path gets
     *   away with it — a batch upload would not.
     *
     * The extension follows the actual file. The meter path hardcodes .jpg, which
     * works only because browsers sniff content; a PNG saved as .jpg is a small lie
     * that costs nothing to avoid here.
     */
    private function storeOne($file, int $vehicleId, string $context): string
    {
        $now  = now();
        $safe = preg_replace('/[^a-z_]/', '', strtolower($context)) ?: 'condition';
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) $ext = 'jpg';

        $dir  = 'vehicles/photos/' . $now->format('Y') . '/' . $now->format('m');
        $name = "vehicle_{$vehicleId}_{$now->format('Ymd_His')}_" . uniqid() . "_{$safe}.{$ext}";

        Storage::disk('public')->putFileAs($dir, $file, $name);
        return $dir . '/' . $name;
    }

    /**
     * The rider list the assign modal picks from — the Bikes roster (the "Delivery
     * Rider" tick on the People list), never the company-wide user list, whose
     * names render cut off and whose entries are mostly not riders at all.
     */
    private function roster(): array
    {
        try {
            // ⚠⚠ `has_vehicle` is derived from the OPEN ASSIGNMENT, never from
            //    `p.default_vehicle_id`. That column is a convenience mirror and it
            //    goes stale: one rider's currently points at a COLLEAGUE'S bike
            //    (test residue that survived a handover), so trusting it would have
            //    shown a man as equipped while he holds nothing — and this list is
            //    exactly what the fleet screen uses to find riders with no machine.
            $held = [];
            $since = [];
            try {
                foreach (DB::table(VehicleService::T_ASSIGN)->whereNull('released_on')
                            ->get(['user_id', 'vehicle_id', 'assigned_on']) as $a) {
                    $held[(int) $a->user_id]  = (int) $a->vehicle_id;
                    $since[(int) $a->user_id] = substr((string) $a->assigned_on, 0, 10);
                }
            } catch (\Throwable $e) { /* no registry → everyone reads as unequipped */ }

            // When he last gave one back — "no bike since 4 Aug" reads far better
            // than a bare "no bike", and tells the manager whether it is news.
            $lastHeld = [];
            try {
                foreach (DB::table(VehicleService::T_ASSIGN)->whereNotNull('released_on')
                            ->orderBy('released_on')
                            ->get(['user_id', 'released_on']) as $a) {
                    $lastHeld[(int) $a->user_id] = substr((string) $a->released_on, 0, 10);
                }
            } catch (\Throwable $e) { /* optional colour */ }

            return DB::table('t_ops_rider_profile as p')
                ->join('t_sys_user as u', 'u.id', '=', 'p.user_id')
                ->where('p.active', 1)
                ->orderBy('u.fullname')
                ->get(['p.user_id', 'u.fullname', 'p.company_bike', 'p.home_latitude'])
                ->map(fn ($r) => [
                    'user_id'      => (int) $r->user_id,
                    'name'         => $r->fullname,
                    'company_bike' => (int) $r->company_bike === 1,
                    'has_vehicle'  => isset($held[(int) $r->user_id]),
                    'vehicle_id'   => $held[(int) $r->user_id] ?? null,
                    'since'        => $since[(int) $r->user_id] ?? null,
                    'free_since'   => isset($held[(int) $r->user_id]) ? null : ($lastHeld[(int) $r->user_id] ?? null),
                    'has_home_pin' => $r->home_latitude !== null,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function canView(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        foreach (self::VIEW_PERMISSIONS as $p) {
            if ($u->hasPermission($p)) return true;
        }
        return false;
    }

    /**
     * May this user change the fleet? `assign_vehicles` and nothing else.
     * View-only accounts are refused regardless of the grant (ReadOnlyGuard).
     */
    private function canManage(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        return (bool) $u->hasPermission('assign_vehicles');
    }

    /**
     * ⭐ MAY HE RECORD A CONDITION PHOTO? (owner ask, Aug-12 — photos on mobile)
     *
     * Deliberately WIDER than canManage(), and deliberately not the same thing.
     * `assign_vehicles` is described in the DB as "Assign bikes/van to riders
     * (and record their condition)" — it bundles two very different powers:
     * MOVING a machine between riders (consequential; changes whose fuel the
     * company buys, who is asked for meters) and PHOTOGRAPHING one (evidence,
     * additive, undoable by ignoring it).
     *
     * Qasim runs bike upkeep and holds `manage_bike_service` — "Set bike service
     * schedules (record a service…)" — but is explicitly denied `assign_vehicles`
     * (`is_allowed = 0`). Gating photos on assignment alone would have locked the
     * very manager the owner asked to enable. Widening `canManage()` instead
     * would have handed him assign/release/edit, which the owner withheld on
     * purpose. So the photo right is its own question, answered by either key.
     *
     * ⚠ Precedent, not invention: `RequestController::store` already falls back
     *   to `manage_bike_service` for exactly this person and reason.
     */
    private function canAddPhotos(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        if (method_exists($u, 'isReadOnly') && $u->isReadOnly()) return false;
        return (bool) ($u->hasPermission('assign_vehicles')
                    || $u->hasPermission('manage_bike_service'));
    }
}
