<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Services\Riders\VanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Van operations API (Aug-2026) — the driver's app, and the store's van actions.
 *
 * ⭐ THE JOURNEY STRIP (owner ruling Aug-4, the anti-confusion rule). The driver
 *    never faces a row of buttons: ONE primary action whose label follows his
 *    state, and every decision is the same question — "Where to next?" — with at
 *    most two answers. So there is no "Van left" endpoint: LEAVING IS IMPLIED by
 *    starting the first leg, and the departure ping fires itself, exactly once.
 *
 * ⭐ SELECTED DISPATCH REUSES THE REAL ENGINE. `dispatchSelected` flips only the
 *    chosen orders `on_van → out_for_delivery` and then calls the SAME
 *    `calculateDeliveryEtas` every rider uses — same origin selection, same
 *    Google ETAs, same grace, same logging. Orders he did not pick stay `on_van`,
 *    which is inert to every OFD-gated mechanism, so they carry no promise and
 *    raise no "left without dispatching" flag until he dispatches them later.
 */
class VanController extends Controller
{
    // =================================================================
    // DRIVER — what am I carrying, and where am I in the trip?
    // =================================================================

    /**
     * The driver's manifest + trip state. This is the whole journey strip in one
     * call, because the strip is on his home screen and must never flicker
     * between two round trips.
     */
    public function manifest(Request $request, VanService $van, \App\Services\Riders\VanStopService $stops)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);

        try {
            if (!$van->available()) {
                return response()->json(['success' => true, 'is_van_driver' => false,
                                         'available' => false, 'trip' => null]);
            }

            $uid = (int) $user->id;
            $m   = $van->manifest($uid);
            $trip = $van->openTrip($uid);

            // He is "the van driver" if the registry says so OR he is actually
            // carrying something — a mid-day stand-in must not be locked out of
            // the cargo he is physically holding.
            $isDriver = $van->isVanDriver($uid) || $m['totals']['carried_total'] > 0 || $m['totals']['mine_on_van'] > 0;

            return response()->json([
                'success'       => true,
                'available'     => true,
                'is_van_driver' => $isDriver,
                // Echoed so his own load scans can name the van explicitly rather
                // than the server inferring "probably yours" from the token.
                'driver_user_id' => $uid,
                'trip'          => $this->tripPayload($trip, $m),
                'mine'          => $m['mine'],
                // ⭐ Each waiting rider's LIVE state — "still delivering, 2 stops
                //    left" vs "on the way, 2 km ~9 min". That is why the rider
                //    needs no "I'm coming" button: the van already knows.
                'carrying'      => $this->enrichWithRiderState($m['carrying'], $stops->currentStopPayload($uid)),
                // Tagged "On Van" but not yet scanned aboard — the load list.
                'to_load'       => $m['to_load'] ?? [],
                'totals'        => $m['totals'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Van manifest failed', ['user' => Auth::id(), 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the van'], 500);
        }
    }

    /**
     * Start a leg — the ONE action behind "Where to next?".
     *
     *   leg = deliveries  → he is going to customers. `order_ids` are the stops he
     *                       picked for THIS wave; they get timed. The rest stay on
     *                       the van with no clock.
     *   leg = to_stop     → he is going to a meet-up point. NOTHING is timed: this
     *                       is the owner's "not dispatch, no time calculation"
     *                       ruling, and it is why the two live behind one question
     *                       rather than one button.
     *
     * The FIRST leg of a trip stamps `departed_at` and fires the departure ping
     * once (`departure_notified_at` is the latch) — leaving is implied, never a
     * separate button.
     */
    public function startLeg(Request $request, VanService $van)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        if (!$van->available()) {
            return response()->json(['success' => false, 'message' => 'Van features are not set up yet.'], 422);
        }

        $data = $request->validate([
            'leg'         => 'required|in:deliveries,to_stop,done',
            'order_ids'   => 'nullable|array',
            'order_ids.*' => 'integer',
            'note'        => 'nullable|string|max:255',
        ]);

        $uid  = (int) $user->id;
        $trip = $van->ensureTrip($uid, null, $uid);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Could not start the trip.'], 500);
        }

        try {
            $firstLeg = empty($trip->departed_at);

            DB::table(VanService::T_TRIP)->where('id', $trip->id)->update(array_filter([
                'current_leg' => $data['leg'],
                'departed_at' => $firstLeg && $data['leg'] !== VanService::LEG_DONE ? now() : $trip->departed_at,
                'ended_at'    => $data['leg'] === VanService::LEG_DONE ? now() : null,
                'note'        => $data['note'] ?? $trip->note,
                'updated_at'  => now(),
            ], fn ($v) => $v !== null));

            // The departure ping — once per trip, whatever he re-plans afterwards.
            $notified = 0;
            if ($firstLeg && $data['leg'] !== VanService::LEG_DONE && empty($trip->departure_notified_at)) {
                $notified = $this->announceDeparture($uid, $van);
                DB::table(VanService::T_TRIP)->where('id', $trip->id)
                    ->update(['departure_notified_at' => now()]);
            }

            // A deliveries leg with picked stops dispatches them in the same
            // action — one press, not two.
            $dispatch = null;
            if ($data['leg'] === VanService::LEG_DELIVERIES && !empty($data['order_ids'])) {
                $dispatch = $this->dispatchSelectedInternal($request, $uid, $data['order_ids'], $van);
            }

            $fresh = $van->openTrip($uid);
            return response()->json([
                'success'         => true,
                'trip'            => $this->tripPayload($fresh, $van->manifest($uid)),
                'riders_notified' => $notified,
                'dispatch'        => $dispatch,
                'message'         => $this->legMessage($data['leg'], $firstLeg, $notified, $dispatch),
            ]);
        } catch (\Throwable $e) {
            Log::error('Van startLeg failed', ['user' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not update the trip.'], 500);
        }
    }

    /**
     * Dispatch a chosen set of his OWN on-van stops (also reachable on its own,
     * e.g. "Dispatch remaining" after the handover).
     */
    public function dispatchSelected(Request $request, VanService $van)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);

        $data = $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer',
        ]);

        $res = $this->dispatchSelectedInternal($request, (int) $user->id, $data['order_ids'], $van);
        if (!($res['ok'] ?? false)) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json(['success' => true] + $res);
    }

    /**
     * Flip the picked stops to OFD, then hand them to the REAL dispatch engine.
     *
     * ⚠ Deliberately NOT a second ETA implementation. Everything that makes
     *   dispatch correct — the phantom-GPS guard, the meet-point origin rule, the
     *   +5 fresh-wave grace, the promise/accountability log, the Google cache —
     *   lives in calculateDeliveryEtas, and a fork would rot away from it.
     */
    private function dispatchSelectedInternal(Request $request, int $driverId, array $orderIds, VanService $van): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (empty($orderIds)) return ['ok' => false, 'message' => 'Choose at least one delivery.'];

        try {
            $orders = OrderModel::whereIn('id', $orderIds)->get();
            $eligible = [];
            foreach ($orders as $o) {
                // Only HIS OWN stops, and only ones actually on the van. Someone
                // else's cargo can never be dispatched by the driver — it has to
                // be collected by its own rider first.
                if ((int) $o->assigned_rider_user_id !== $driverId) {
                    return ['ok' => false, 'message' => 'Order ' . $o->order_number
                        . ' is not yours to deliver — it is handed over at the meet-up point.'];
                }
                if ($o->order_status === VanService::STATUS_OFD) continue;   // already out, fine
                if ($o->order_status !== VanService::STATUS_ON_VAN) {
                    return ['ok' => false, 'message' => 'Order ' . $o->order_number . ' is not on the van.'];
                }
                $eligible[] = $o;
            }

            // Flip only the picked ones. The rest stay on_van = no promise, no
            // "left without dispatching" flag, customer still sees processing.
            DB::transaction(function () use ($eligible, $driverId) {
                foreach ($eligible as $o) {
                    $o->changeStatus(VanService::STATUS_OFD, 'Van driver dispatching his own stop', $driverId);
                }
            });

            // Preserve HIS chosen order as the route sequence.
            $sequence = [];
            foreach ($orderIds as $i => $oid) {
                $sequence[] = ['order_id' => $oid, 'priority' => $i + 1];
            }

            // Same engine, same rules. `scope=undispatched` keeps any wave he has
            // already promised frozen at its original times.
            $sub = Request::create('/internal/van-dispatch', 'POST', [
                'scope'          => 'undispatched',
                'order_sequence' => $sequence,
            ]);
            $sub->setUserResolver(fn () => Auth::user());

            $resp = app(RiderController::class)->calculateDeliveryEtas($sub, $driverId);
            $body = json_decode($resp->getContent(), true);

            return [
                'ok'         => ($body['success'] ?? false) === true,
                'message'    => $body['message'] ?? null,
                'dispatched' => count($eligible),
                'engine'     => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('Van dispatchSelected failed', ['driver' => $driverId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'Could not dispatch those deliveries.'];
        }
    }

    // =================================================================
    // MEET-UP STOPS
    // =================================================================

    /** The stop list the driver picks from (+ the trip's stops so far). */
    public function stops(Request $request, \App\Services\Riders\VanStopService $stops, VanService $van)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);

        $uid  = (int) $user->id;
        $trip = $van->openTrip($uid);
        $cur  = $stops->currentStopPayload($uid);

        // ⭐ HIS OWN ETA to the meet-up point. The store's board had this from the
        //    start; the driver — the one actually driving there — did not (owner
        //    spotted it Aug-4). Cached-or-approximate and warmed out of band, so
        //    this never blocks a poll. Dropped once he has arrived.
        if ($cur && ($cur['latitude'] ?? null) !== null && empty($cur['reached_at'])) {
            $pos = $this->lastFix($uid);
            if ($pos) {
                $eta = $this->etaBetween($pos, $cur['latitude'], $cur['longitude'], 'van_stop_eta:' . $uid);
                $cur['eta'] = $eta;
                if ($eta && ($eta['source'] ?? '') === 'approx') {
                    $this->warmEtas([['key' => $eta['cache_key'], 'from' => $pos,
                                       'to' => ['lat' => $cur['latitude'], 'lng' => $cur['longitude']]]]);
                }
            }
        }

        return response()->json([
            'success'      => true,
            'available'    => $stops->available(),
            'presets'      => $stops->presets($request->boolean('include_inactive')),
            'current'      => $cur,
            'trip_stops'   => $stops->tripStops($trip->id ?? null),
            'can_manage'   => $this->canManageStops($user),
        ]);
    }

    /**
     * Announce where the van will stop — a preset, or a pin dropped where he is.
     * Every rider with cargo aboard is told immediately: this is the message the
     * whole meet-up depends on, so it is sent here rather than left to a poll.
     */
    public function setStop(Request $request, \App\Services\Riders\VanStopService $stops, VanService $van)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);

        $data = $request->validate([
            'location_id' => 'nullable|integer',
            'latitude'    => 'nullable|numeric|between:-90,90',
            'longitude'   => 'nullable|numeric|between:-180,180',
            'label'       => 'nullable|string|max:100',
            // ⭐ The STORE may set the meet-up point for a van (owner ruling
            //    Aug-4) — it is often the store that knows where the riders
            //    already are. The driver still sees it and can change it: the
            //    next setStop simply supersedes this one, whoever sends it.
            'van_user_id' => 'nullable|integer',
        ]);

        $uid = (int) $user->id;
        if (!empty($data['van_user_id']) && (int) $data['van_user_id'] !== $uid) {
            if (!$this->canManageStops($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the driver or the store can set this van\'s meet-up point.',
                ], 403);
            }
            $uid = (int) $data['van_user_id'];
        }
        unset($data['van_user_id']);

        $res = $stops->setStop($uid, $data);
        if (!$res['ok']) return response()->json(['success' => false, 'message' => $res['message']], 422);

        $notified = $this->announceStop($uid, $van, $res);

        return response()->json([
            'success'         => true,
            'message'         => $res['message'] . ($notified ? ' ' . $notified . ' rider'
                                 . ($notified === 1 ? '' : 's') . ' told.' : ''),
            'stop'            => $stops->currentStopPayload($uid),
            'riders_notified' => $notified,
            'trip'            => $this->tripPayload($van->openTrip($uid), $van->manifest($uid)),
        ]);
    }

    /** "I'm here" — starts the store's waiting timer. */
    public function reachedStop(Request $request, \App\Services\Riders\VanStopService $stops)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        $data = $request->validate([
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $res = $stops->markReached((int) $user->id,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null);

        return response()->json(['success' => $res['ok'], 'message' => $res['message'],
                                 'stop' => $stops->currentStopPayload((int) $user->id)],
                                $res['ok'] ? 200 : 422);
    }

    /** Done here. */
    public function completeStop(Request $request, \App\Services\Riders\VanStopService $stops)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        $res = $stops->completeStop((int) $user->id);
        return response()->json(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    /** Create / rename / re-pin a preset stop (managers). */
    public function saveStop(Request $request, \App\Services\Riders\VanStopService $stops, VanService $van, $id = null)
    {
        $user = Auth::user();
        // ⭐ CREATE is open to the VAN DRIVER too (owner ruling Aug-4): he is the
        //    one standing at the spots, so he may name new ones from his phone.
        //    EDIT (an $id) / retire / promote stay with staff — renaming or
        //    killing a point everyone relies on is a management act.
        $isDriver = $user && $van->isVanDriver((int) $user->id);
        if (!$this->canManageStops($user) && !($isDriver && $id === null)) {
            return response()->json(['success' => false, 'message' => 'You cannot manage meet-up stops.'], 403);
        }
        $data = $request->validate([
            'name'      => 'required|string|max:100',
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_m'  => 'nullable|integer|min:50|max:5000',
            'is_active' => 'nullable|boolean',
        ]);

        $res = $stops->savePreset($data, $id ? (int) $id : null, (int) $user->id);
        return response()->json(['success' => $res['ok'], 'message' => $res['message'] ?? null,
                                 'presets' => $stops->presets(true)], $res['ok'] ? 200 : 422);
    }

    /** Retire a preset (never deleted — history references it). */
    public function retireStop(Request $request, \App\Services\Riders\VanStopService $stops, $id)
    {
        $user = Auth::user();
        if (!$this->canManageStops($user)) {
            return response()->json(['success' => false, 'message' => 'You cannot manage meet-up stops.'], 403);
        }
        $res = $stops->retirePreset((int) $id, (int) $user->id);
        return response()->json(['success' => $res['ok'], 'message' => $res['message'],
                                 'presets' => $stops->presets(true)], $res['ok'] ? 200 : 422);
    }

    /** Turn a one-off spot into a permanent stop (owner ruling Aug-4). */
    public function promoteStop(Request $request, \App\Services\Riders\VanStopService $stops, $handoverId)
    {
        $user = Auth::user();
        if (!$this->canManageStops($user)) {
            return response()->json(['success' => false, 'message' => 'You cannot manage meet-up stops.'], 403);
        }
        $data = $request->validate(['name' => 'required|string|max:100']);
        $res = $stops->promoteAdhoc((int) $handoverId, $data['name'], (int) $user->id);
        return response()->json(['success' => $res['ok'], 'message' => $res['message'],
                                 'presets' => $stops->presets(true)], $res['ok'] ? 200 : 422);
    }

    /**
     * The rider's "Meet the van" card.
     *
     * ⭐ Sequenced AFTER his current run (owner ruling Aug-4): if he is mid-run the
     *    card says so and waits its turn; the server states that rather than
     *    leaving each client to decide, so web and mobile agree.
     */
    public function meetCard(Request $request, \App\Services\Riders\VanStopService $stops, VanService $van)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        $uid = (int) $user->id;

        try {
            if (!$van->available()) {
                return response()->json(['success' => true, 'has_cargo' => false]);
            }

            $cargo = DB::table('t_crm_prod_order')
                ->where('assigned_rider_user_id', $uid)
                ->where('order_status', VanService::STATUS_ON_VAN)
                // ⭐ LOADED only. "On Van" without stamps is just the staff's plan
                //    — nothing is physically on any van yet, so telling the rider
                //    to go meet it would send him to a van that isn't carrying
                //    his boxes (and `van_user_id` would be NULL anyway).
                ->whereNotNull('van_user_id')
                ->whereNotNull('van_loaded_at')
                // ⭐⭐ NOT HIS OWN VAN. The driver is the assigned rider on his own
                //    stops AND the carrier of them, so without this he was shown
                //    "the van is waiting for you" about the van he is sitting in
                //    (seen on the device Aug-4). He collects nothing and scans no
                //    handover — his stops go straight to his wave picker.
                ->where('van_user_id', '!=', $uid)
                ->get(['id', 'order_number', 'van_user_id', 'expected_packets']);

            if ($cargo->isEmpty()) {
                return response()->json(['success' => true, 'has_cargo' => false]);
            }

            $driverId = (int) $cargo->first()->van_user_id;
            $trip = $van->openTrip($driverId);
            $stop = $stops->currentStopPayload($driverId);

            // How many stops he still has on the road right now.
            $remaining = DB::table('t_crm_prod_order')
                ->where('assigned_rider_user_id', $uid)
                ->where('order_status', VanService::STATUS_OFD)
                ->count();

            $driverName = DB::table('t_sys_user')->where('id', $driverId)->value('fullname');
            $departed   = $trip && $trip->departed_at;

            // One honest sentence for each real situation.
            if (!$departed) {
                $state = 'loading';
                $head  = 'Your orders are on the van';
                $sub   = 'It has not left the store yet.';
            } elseif (!$stop) {
                $state = 'awaiting_location';
                $head  = '🚚 The van has left';
                $sub   = ($driverName ?: 'The driver') . ' will send the meeting point shortly.';
            } elseif (!empty($stop['reached_at'])) {
                $state = 'waiting';
                $head  = '🚚 The van is waiting for you';
                $sub   = 'At ' . $stop['label']
                       . ($stop['waiting_minutes'] !== null ? ' · waiting ' . $stop['waiting_minutes'] . ' min' : '');
            } else {
                $state = 'en_route';
                $head  = '🚚 Meet the van';
                $sub   = ($driverName ?: 'The driver') . ' is on the way to ' . $stop['label'] . '.';
            }

            return response()->json([
                'success'      => true,
                'has_cargo'    => true,
                'state'        => $state,
                'headline'     => $head,
                'sub'          => $sub,
                'orders'       => $cargo->count(),
                'packets'      => (int) $cargo->sum(fn ($c) => (int) ($c->expected_packets ?: 1)),
                'driver_name'  => $driverName,
                'stop'         => $stop,
                // The card waits its turn behind stops he has already promised.
                'after_current_run' => $remaining > 0,
                'remaining_stops'   => $remaining,
            ]);
        } catch (\Throwable $e) {
            Log::error('Van meetCard failed', ['user' => $uid, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the van status'], 500);
        }
    }

    // =================================================================
    // THE STORE'S VAN PANEL
    // =================================================================

    /**
     * What the store sees while the van is out — TWO MODES, because the honest
     * answer differs (owner asked for "the best way to handle these cases"):
     *
     *   • `to_stop`     — he is going to meet riders. This is the mode with
     *                     something to watch: the stop, his ETA to it, the
     *                     waiting timer once he arrives, which riders are inbound
     *                     and how far off they are, and who has collected what.
     *   • `delivering`  — he only has his own drops. There is nothing van-shaped
     *                     to show; the existing Riders-Live board already tracks a
     *                     dispatched rider, so the panel just labels him and gets
     *                     out of the way.
     *
     * ⚠ ETAs NEVER block this panel. It polls, so a cold Google call would stall
     *   the board — the exact failure the live board was fixed for. Distance is
     *   always computed from the last GPS fix; the ETA is a warm cached value when
     *   there is one, otherwise a distance-based approximation, and fresh Google
     *   calls are warmed OUT OF BAND after the response has gone.
     */
    /**
     * Who is driving a van today — nothing else.
     *
     * Deliberately separate from storePanel: the store's orders screen only
     * needs to know whether a row belongs to a van driver (to offer the load
     * scan), and that must not cost a manifest + Google ETAs on every load.
     */
    public function drivers(VanService $van)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);

        return response()->json([
            'success' => true,
            'available' => $van->available(),
            'drivers' => $van->available() ? $van->todaysDrivers() : [],
        ]);
    }

    public function storePanel(Request $request, VanService $van, \App\Services\Riders\VanStopService $stops)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);
        if (!$user->hasPermission('view_open_orders') && !$user->hasMobilePermission('view_open_orders')) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }

        try {
            if (!$van->available()) {
                return response()->json(['success' => true, 'available' => false, 'vans' => []]);
            }

            // Every van with something on board or an open trip today.
            $driverIds = DB::table('t_crm_prod_order')
                ->whereNotNull('van_user_id')
                ->whereIn('order_status', [VanService::STATUS_ON_VAN, VanService::STATUS_OFD])
                ->whereNotNull('van_loaded_at')
                // Recent loads only — an OFD order keeps its van stamps for
                // reporting long after delivery, and without this bound every van
                // trip of the past would render a panel card forever.
                ->where('van_loaded_at', '>=', now()->subHours(20))
                ->distinct()->pluck('van_user_id')
                ->merge(DB::table(VanService::T_TRIP)->whereNull('ended_at')
                    // Same reason as riderIsAtVanMeetPoint's bound: a trip the
                    // driver forgot to close must not clutter the panel for days.
                    ->where('trip_date', '>=', today()->subDay()->format('Y-m-d'))
                    ->pluck('van_user_id'))
                // ⭐ …plus whoever is simply ASSIGNED a van today. Without this the
                //    panel only appeared once the first box was aboard, which is
                //    backwards: loading is the store's first job, so the card and
                //    its load-scan door must exist before anything is loaded.
                ->merge(collect($van->todaysDrivers())->pluck('user_id'))
                ->map(fn ($v) => (int) $v)->filter()->unique()->values();

            $warm = [];
            $vans = [];
            foreach ($driverIds as $did) {
                $m    = $van->manifest($did);
                $trip = $van->openTrip($did);
                $stop = $stops->currentStopPayload($did);
                $name = DB::table('t_sys_user')->where('id', $did)->value('fullname');
                $pos  = $this->lastFix($did);

                $mode = ($trip && $trip->current_leg === VanService::LEG_TO_STOP && $stop)
                    ? 'to_stop'
                    : (($trip && $trip->departed_at) ? 'delivering' : 'loading');

                $vanEta = null;
                if ($mode === 'to_stop' && $pos && $stop && $stop['latitude'] !== null && empty($stop['reached_at'])) {
                    $vanEta = $this->etaBetween($pos, $stop['latitude'], $stop['longitude'], 'van_stop_eta:' . $did);
                    if (($vanEta['source'] ?? '') === 'approx') {
                        $warm[] = ['key' => $vanEta['cache_key'], 'from' => $pos,
                                   'to' => ['lat' => $stop['latitude'], 'lng' => $stop['longitude']]];
                    }
                }

                // Inbound riders — only those who still have cargo to collect.
                $inbound = [];
                foreach ($m['carrying'] as $g) {
                    if (!empty($g['complete'])) continue;
                    $rpos = $this->lastFix($g['user_id']);
                    $eta  = null;
                    if ($rpos && $stop && $stop['latitude'] !== null) {
                        $eta = $this->etaBetween($rpos, $stop['latitude'], $stop['longitude'],
                                                 'rider_stop_eta:' . $g['user_id']);
                        if (($eta['source'] ?? '') === 'approx') {
                            $warm[] = ['key' => $eta['cache_key'], 'from' => $rpos,
                                       'to' => ['lat' => $stop['latitude'], 'lng' => $stop['longitude']]];
                        }
                    }
                    $inbound[] = [
                        'user_id'  => $g['user_id'],
                        'name'     => $g['name'],
                        'orders'   => $g['total'],
                        'handed'   => $g['handed'],
                        'packets'  => $g['packets'],
                        'eta'      => $eta,
                        'has_gps'  => $rpos !== null,
                    ];
                }

                $vans[] = [
                    'driver_user_id' => $did,
                    'driver_name'    => $name,
                    'mode'           => $mode,
                    'headline'       => $this->panelHeadline($mode, $name, $stop, $m),
                    'trip'           => $trip ? [
                        'id' => (int) $trip->id,
                        'departed_at' => $trip->departed_at,
                        'leg' => $trip->current_leg,
                    ] : null,
                    'stop'           => $stop,
                    'van_eta'        => $vanEta,
                    'van_position'   => $pos,
                    'inbound'        => $inbound,
                    // Every rider group incl. COMPLETED ones — `inbound` filters
                    // to who is still coming, but the store board must also show
                    // "collected ✅" once a rider has taken his boxes.
                    'carrying'       => $m['carrying'],
                    // ⭐ The DRIVER'S OWN orders. Without these the store could
                    //    see everyone's boxes except the driver's, which is the
                    //    one group nobody else is tracking.
                    'mine'           => $m['mine'],
                    // Tagged "On Van", not yet scanned aboard — the loading list.
                    'to_load'        => $m['to_load'] ?? [],
                    'totals'         => $m['totals'],
                    'trip_stops'     => $stops->tripStops($trip->id ?? null),
                ];
            }

            // Warm the cold ETAs AFTER the response — never on the poll's path.
            if (!empty($warm)) $this->warmEtas($warm);

            return response()->json(['success' => true, 'available' => true, 'vans' => $vans]);
        } catch (\Throwable $e) {
            Log::error('Van storePanel failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not load the van panel'], 500);
        }
    }

    /**
     * ⭐ Per-rider live state for the DRIVER's board (owner ruling Aug-4:
     *    "will it tell the van driver the different riders coming towards it?
     *     and if a rider is still finishing his deliveries it should show that").
     *
     *    This is deliberately instead of an "I'm on my way" button: the rider is
     *    already carrying a phone that reports GPS, and his remaining stops are
     *    already known — asking him to press one more thing would only add a
     *    step he can forget. Each group gets:
     *      state  = delivering | coming | waiting | collected
     *      detail = a short phrase the driver can read at a glance
     *
     *    ETAs never block: cached-or-approximate, same discipline as the panel.
     */
    private function enrichWithRiderState(array $groups, ?array $stop): array
    {
        $warm = [];
        foreach ($groups as &$g) {
            if (!empty($g['complete'])) {
                $g['state'] = 'collected';
                $g['detail'] = 'collected';
                continue;
            }

            // Still out on his own route? Then he cannot be here yet.
            $remaining = 0;
            try {
                $remaining = DB::table('t_crm_prod_order')
                    ->where('assigned_rider_user_id', $g['user_id'])
                    ->where('order_status', VanService::STATUS_OFD)
                    ->whereNotNull('estimated_delivery_at')
                    ->count();
            } catch (\Throwable $e) { /* a count is never worth a 500 */ }

            $eta = null;
            $pos = $this->lastFix((int) $g['user_id']);
            if ($pos && $stop && ($stop['latitude'] ?? null) !== null && empty($stop['reached_at'])) {
                $eta = $this->etaBetween($pos, $stop['latitude'], $stop['longitude'],
                                         'rider_stop_eta:' . $g['user_id']);
                if (($eta['source'] ?? '') === 'approx') {
                    $warm[] = ['key' => $eta['cache_key'], 'from' => $pos,
                               'to' => ['lat' => $stop['latitude'], 'lng' => $stop['longitude']]];
                }
            }

            $g['remaining_stops'] = $remaining;
            $g['eta'] = $eta;
            if ($remaining > 0) {
                $g['state']  = 'delivering';
                $g['detail'] = $remaining . ' stop' . ($remaining === 1 ? '' : 's') . ' first';
            } elseif ($eta) {
                $g['state']  = 'coming';
                $g['detail'] = $eta['distance_display'] . ' · ~' . $eta['minutes'] . ' min';
            } else {
                $g['state']  = 'waiting';
                $g['detail'] = 'waiting to collect';
            }
        }
        unset($g);

        if (!empty($warm)) $this->warmEtas($warm);
        return $groups;
    }

    /** Most recent usable GPS fix, or null. */
    private function lastFix(int $userId): ?array
    {
        try {
            $f = DB::table('t_ops_rider_location')
                ->where('user_id', $userId)
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->where('captured_at', '>=', now()->subMinutes(30))
                ->orderByDesc('captured_at')
                ->first(['latitude', 'longitude', 'captured_at']);
            if (!$f) return null;
            return [
                'lat' => (float) $f->latitude,
                'lng' => (float) $f->longitude,
                'captured_at' => $f->captured_at,
                'age_minutes' => max(0, (int) round((time() - strtotime((string) $f->captured_at)) / 60)),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Distance always; ETA from a warm cache, else approximated. Mirrors the live
     * board's rule so the two surfaces cannot quote different numbers, and so a
     * cold cache degrades to a sensible figure instead of a spinner.
     */
    /**
     * Beyond this, the fix is not a rider on his way — it is a bad reading.
     * Every delivery is inside the twin cities, so a "meet-up" 60 km away is
     * nonsense. Aug-4: two test phones reported RIYADH, which produced a
     * perfectly-arithmetic "2736 km · ~3318 min" on the driver's board. An
     * obviously-wrong number is worse than no number: it looks authoritative.
     */
    private const ETA_SANE_MAX_METRES = 60000;

    private function etaBetween(array $from, float $toLat, float $toLng, string $cacheKey): ?array
    {
        $metres = \App\Services\LocationService::calculateDistance($from['lat'], $from['lng'], $toLat, $toLng);
        if ($metres > self::ETA_SANE_MAX_METRES) {
            return null;   // caller shows "waiting"/no ETA rather than a fantasy
        }

        // ⭐⭐ THE KEY MUST INCLUDE WHERE HE IS. Keyed on the user alone, a cached
        //    Google answer was reused after he had moved somewhere else entirely
        //    — and reported as `google_maps`, i.e. authoritative. Caught Aug-4:
        //    a 3,328-minute figure cached from a bad fix was still being served
        //    for a 1.6 km trip. ~1 km buckets: close enough to keep hitting the
        //    cache while he crawls through traffic, coarse enough that a real
        //    move re-asks Google.
        $cacheKey .= sprintf(':%.2f,%.2f>%.3f,%.3f', $from['lat'], $from['lng'], $toLat, $toLng);

        $cached = \Cache::get($cacheKey);
        $minutes = (is_numeric($cached) && $cached >= 0) ? (int) round($cached) : null;
        $source  = 'google_maps';

        if ($minutes === null) {
            // ~22 km/h average city speed — the same approximation the live board uses.
            $minutes = max(1, (int) round(($metres / 1000) / 22 * 60));
            $source  = 'approx';
        }

        return [
            'minutes'          => $minutes,
            'source'           => $source,
            // The position-scoped key, so a caller warms the SAME entry it read.
            'cache_key'        => $cacheKey,
            'distance_meters'  => (int) round($metres),
            'distance_display' => $metres < 1000
                ? ((int) round($metres)) . ' m'
                : round($metres / 1000, 1) . ' km',
            'arrival_display'  => now()->addMinutes($minutes)->format('h:i A'),
            'gps_age_minutes'  => $from['age_minutes'] ?? null,
        ];
    }

    /** Fill cold ETA caches after the response has been sent. */
    private function warmEtas(array $jobs): void
    {
        try {
            app()->terminating(function () use ($jobs) {
                foreach ($jobs as $j) {
                    try {
                        if (\Cache::has($j['key'])) continue;
                        $eta = app(RiderController::class);
                        $ref = new \ReflectionMethod(RiderController::class, 'getMultiStopEtaFromGoogle');
                        $ref->setAccessible(true);
                        $res = $ref->invoke($eta, [
                            ['lat' => $j['from']['lat'], 'lng' => $j['from']['lng']],
                            ['lat' => $j['to']['lat'],   'lng' => $j['to']['lng']],
                        ]);
                        $mins = $res['legs'][0] ?? -1;
                        \Cache::put($j['key'], $mins, 180);
                    } catch (\Throwable $e) {
                        // A warm-up failure is invisible by design — the panel
                        // already showed an approximation.
                    }
                }
            });
        } catch (\Throwable $e) {
            // No terminating() in this context (console/queue) — skip silently.
        }
    }

    private function panelHeadline(string $mode, ?string $name, ?array $stop, array $m): string
    {
        $who = $name ?: 'The van';
        switch ($mode) {
            case 'to_stop':
                if ($stop && !empty($stop['reached_at'])) {
                    return $who . ' is waiting at ' . $stop['label']
                         . ($stop['waiting_minutes'] !== null ? ' (' . $stop['waiting_minutes'] . ' min)' : '');
                }
                return $who . ' is heading to ' . ($stop['label'] ?? 'the meet-up point');
            case 'delivering':
                $left = $m['totals']['carried_total'] - $m['totals']['carried_handed'];
                return $left > 0
                    ? $who . ' is delivering his own orders · ' . $left . ' still to hand over'
                    : $who . ' is delivering his own orders';
            default:
                return $who . ' is loading';
        }
    }

    // =================================================================
    // SCANS
    // =================================================================

    /**
     * Load a packet onto the van.
     *
     * ⭐ TWO DOORS ON PURPOSE (owner ruling Aug-4): loading goes faster when the
     *    store staff carrying boxes out AND the driver standing at the tailgate
     *    can both scan. So this accepts either:
     *      • anyone who may assign riders (store staff), or
     *      • THE DRIVER OF THAT VAN, scanning onto his own van only.
     *    The second is scoped tightly — `van_user_id` must be himself — so a
     *    driver can never load someone else's van, and no new permission key is
     *    needed for the common case.
     */
    public function loadScan(Request $request, VanService $van, $id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);

        $data = $request->validate([
            'scan_code'   => 'required|string|max:190',
            'van_user_id' => 'required|integer',
        ]);

        $isStoreStaff = $user->hasMobilePermission('assign_riders') || $user->hasPermission('assign_riders');
        $isOwnVan     = (int) $data['van_user_id'] === (int) $user->id
                        && $van->isVanDriver((int) $user->id);

        if (!$isStoreStaff && !$isOwnVan) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot load this van.',
            ], 403);
        }

        $order = OrderModel::find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Order not found'], 404);

        $res = $van->loadScan($order, $data['scan_code'], (int) $data['van_user_id'], (int) $user->id,
                              $request->boolean('manual'));
        return response()->json(['success' => $res['ok']] + $res, $res['ok'] ? 200 : 422);
    }

    /** The receiving rider collects his packets at the meet point. */
    public function handoverScan(Request $request, VanService $van, $id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['success' => false, 'message' => 'Not authorised'], 401);

        $data = $request->validate([
            'scan_code' => 'required|string|max:190',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $order = OrderModel::find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Order not found'], 404);

        $res = $van->handoverScan(
            $order, $data['scan_code'], (int) $user->id,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null
        );
        return response()->json(['success' => $res['ok']] + $res, $res['ok'] ? 200 : 422);
    }

    /** Manager records a handover without a scan (damaged label, dead phone). */
    public function handoverOverride(Request $request, VanService $van, $id)
    {
        $user = Auth::user();
        if (!$user || (!$user->hasPermission('assign_riders') && !$user->hasMobilePermission('assign_riders'))) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $data = $request->validate(['reason' => 'required|string|max:190']);

        $order = OrderModel::find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Order not found'], 404);

        $res = $van->handoverOverride($order, (int) $user->id, $data['reason']);
        return response()->json(['success' => $res['ok']] + $res, $res['ok'] ? 200 : 422);
    }

    /** Take an order back off the van. */
    public function unload(Request $request, VanService $van, $id)
    {
        $user = Auth::user();
        if (!$user || (!$user->hasPermission('assign_riders') && !$user->hasMobilePermission('assign_riders'))) {
            return response()->json(['success' => false, 'message' => 'Not authorised'], 403);
        }
        $order = OrderModel::find($id);
        if (!$order) return response()->json(['success' => false, 'message' => 'Order not found'], 404);

        $res = $van->unload($order, (int) $user->id);
        return response()->json(['success' => $res['ok']] + $res, $res['ok'] ? 200 : 422);
    }

    // =================================================================
    // internals
    // =================================================================

    /**
     * Tell every rider with cargo aboard that the van has left. Best-effort by
     * design: a failed push must never stop a van from leaving.
     */
    private function announceDeparture(int $driverId, VanService $van): int
    {
        $riders = $van->ridersAwaiting($driverId);
        if (empty($riders)) return 0;

        $driverName = DB::table('t_sys_user')->where('id', $driverId)->value('fullname') ?: 'The van';
        $sent = 0;
        foreach ($riders as $rid) {
            try {
                app(\App\Services\FirebaseService::class)->notifyUser(
                    $rid,
                    [
                        'title' => '🚚 The van has left',
                        'body'  => $driverName . ' is on the way with your orders. You will be told where to meet.',
                    ],
                    ['type' => 'van_departed', 'van_user_id' => (string) $driverId],
                    'shift_notifications'
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Van departure push failed', ['rider' => $rid, 'error' => $e->getMessage()]);
            }
        }

        Log::info('Van departed', ['driver' => $driverId, 'riders_notified' => $sent]);
        return $sent;
    }

    /**
     * Tell the riders with cargo where to meet. Best-effort per rider — one dead
     * token must not stop the others being told.
     */
    private function announceStop(int $driverId, VanService $van, array $stop): int
    {
        $riders = $van->ridersAwaiting($driverId);
        if (empty($riders)) return 0;

        $driverName = DB::table('t_sys_user')->where('id', $driverId)->value('fullname') ?: 'The van';
        $sent = 0;
        foreach ($riders as $rid) {
            try {
                app(\App\Services\FirebaseService::class)->notifyUser(
                    $rid,
                    [
                        'title' => '📍 Meet the van at ' . ($stop['label'] ?? 'the meet-up point'),
                        'body'  => $driverName . ' is heading there with your orders.',
                    ],
                    [
                        'type'      => 'van_stop_set',
                        'latitude'  => (string) ($stop['latitude'] ?? ''),
                        'longitude' => (string) ($stop['longitude'] ?? ''),
                        'label'     => (string) ($stop['label'] ?? ''),
                    ],
                    'shift_notifications'
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Van stop push failed', ['rider' => $rid, 'error' => $e->getMessage()]);
            }
        }
        Log::info('Van stop announced', ['driver' => $driverId, 'riders_notified' => $sent,
                                         'label' => $stop['label'] ?? null]);
        return $sent;
    }

    /**
     * Who may create/rename/retire the STOP LIST. Deliberately the rider-assignment
     * right (`assign_riders`) — deciding where the van meets riders is the same
     * kind of daily ops decision, not a system-admin one.
     */
    private function canManageStops($user): bool
    {
        if (!$user) return false;
        if (method_exists($user, 'isReadOnly') && $user->isReadOnly()) return false;
        return (bool) $user->hasPermission('assign_riders')
            || (method_exists($user, 'hasMobilePermission') && $user->hasMobilePermission('assign_riders'));
    }

    /** The journey strip, server-decided so web and mobile cannot word it differently. */
    private function tripPayload($trip, array $manifest): ?array
    {
        // Short words on purpose — the riders skim, they don't read.
        $toLoad = (int) ($manifest['totals']['to_load'] ?? 0);
        $toLoadNote = $toLoad > 0 ? ' · ' . $toLoad . ' to scan' : '';

        if (!$trip) {
            return [
                'id' => null, 'leg' => VanService::LEG_LOADING, 'departed' => false,
                'headline' => 'Trip not started',
                'sub' => ($manifest['totals']['mine_on_van'] + $manifest['totals']['carried_total'])
                       . ' on board' . $toLoadNote,
                'action' => ['key' => 'start', 'label' => '▶ Start trip'],
            ];
        }

        $t = $manifest['totals'];
        $leg = (string) $trip->current_leg;
        $departed = !empty($trip->departed_at);

        switch ($leg) {
            case VanService::LEG_DELIVERIES:
                $headline = 'Delivering';
                $sub = $t['mine_dispatched'] . ' of ' . $t['mine_total'] . ' of your stops timed'
                     . ($t['carried_total'] > 0 ? ' · ' . ($t['carried_total'] - $t['carried_handed']) . ' still to hand over' : '');
                $action = ['key' => 'replan', 'label' => 'Change plan'];
                break;
            case VanService::LEG_TO_STOP:
                $headline = 'Heading to the meet-up point';
                $sub = $t['riders_waiting'] . ' rider' . ($t['riders_waiting'] === 1 ? '' : 's') . ' waiting';
                $action = ['key' => 'replan', 'label' => 'Change plan'];
                break;
            case VanService::LEG_DONE:
                $headline = 'Trip finished';
                $sub = '';
                $action = ['key' => 'start', 'label' => '▶ Start a new trip'];
                break;
            default:
                $headline = $departed ? 'On the road' : 'Loading the van';
                $sub = ($t['mine_on_van'] + $t['carried_total'] - $t['carried_handed']) . ' on board' . $toLoadNote;
                $action = ['key' => 'start', 'label' => $departed ? 'Where to next?' : 'Where to next?'];
        }

        return [
            'id'          => (int) $trip->id,
            'leg'         => $leg,
            'departed'    => $departed,
            'departed_at' => $trip->departed_at,
            'headline'    => $headline,
            'sub'         => $sub,
            'action'      => $action,
        ];
    }

    private function legMessage(string $leg, bool $firstLeg, int $notified, ?array $dispatch): string
    {
        $bits = [];
        if ($firstLeg && $leg !== VanService::LEG_DONE) {
            $bits[] = $notified > 0
                ? 'Van marked as left — ' . $notified . ' rider' . ($notified === 1 ? '' : 's') . ' told.'
                : 'Van marked as left.';
        }
        if ($leg === VanService::LEG_TO_STOP)   $bits[] = 'Heading to the meet-up point.';
        if ($leg === VanService::LEG_DELIVERIES) {
            $bits[] = $dispatch && ($dispatch['ok'] ?? false)
                ? ($dispatch['dispatched'] ?? 0) . ' deliveries dispatched.'
                : 'Delivering.';
        }
        if ($leg === VanService::LEG_DONE) $bits[] = 'Trip closed.';
        return implode(' ', $bits) ?: 'Updated.';
    }
}
