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
            // Same self-heal as the store board — the driver's own strip must not
            // keep a finished meet-up alive either.
            $van->completeStopIfHandoverDone($uid, $stops);
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

        // ⭐ FINISHING WITH BOXES STILL ABOARD NEEDS A DELIBERATE YES. "Finish the
        //    trip" was a single unguarded tap: it closed the trip while riders'
        //    cargo was still on the van, and because the rider card derives
        //    "departed" from the OPEN trip, those riders were then told their
        //    orders "have not left the store yet" — about boxes driving away.
        //    Asked BEFORE ensureTrip so a stray confirm cannot create a trip.
        if ($data['leg'] === VanService::LEG_DONE && !$request->boolean('force')) {
            $t = $van->manifest($uid)['totals'];
            $uncollected = max(0, (int) $t['carried_total'] - (int) $t['carried_handed']);
            $myParked    = (int) $t['mine_on_van'];
            if ($uncollected > 0 || $myParked > 0) {
                $bits = [];
                if ($uncollected > 0) $bits[] = $uncollected . ' not collected';
                if ($myParked > 0)    $bits[] = $myParked . ' of yours';
                return response()->json([
                    'success'       => false,
                    'needs_confirm' => true,
                    'message'       => 'Still on the van: ' . implode(' · ', $bits) . '. Finish anyway?',
                ], 422);
            }
        }

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
            //
            // ⚠ CLAIM THE LATCH BEFORE PUSHING, IN ONE CONDITIONAL UPDATE. The
            //   old order (read the row → check the latch → push → set the latch)
            //   let two fast taps on "Where to next?" both read the same
            //   un-latched row and both announce, so every rider was told twice
            //   that the van had left. `WHERE departure_notified_at IS NULL` makes
            //   the database the referee: exactly one caller gets a row back.
            $notified = 0;
            if ($firstLeg && $data['leg'] !== VanService::LEG_DONE) {
                $claimed = DB::table(VanService::T_TRIP)
                    ->where('id', $trip->id)
                    ->whereNull('departure_notified_at')
                    ->update(['departure_notified_at' => now(), 'updated_at' => now()]);
                if ($claimed > 0) {
                    $notified = $this->announceDeparture($uid, $van);
                }
            }

            // ⭐ DRIVING AWAY CLOSES THE MEET-UP — but only a meet-up he was AT.
            //    Nothing used to close the stop except the driver remembering to
            //    press "Done", so riders kept seeing "the van is waiting for you"
            //    while it delivered elsewhere. The reached/planned distinction
            //    lives in closeOnLegChange: a REACHED stop closes when he drives
            //    off; a merely-PLANNED one survives the deliveries wave, because
            //    "deliver these three, then meet at X" is the whole point.
            (new \App\Services\Riders\VanStopService())->closeOnLegChange($uid, $data['leg']);

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
                // ⚠ The LEG started, but the dispatch inside it may have been
                //   refused — and a refusal used to vanish into a cheerful
                //   "Delivering." while nothing was timed and his stops sat on
                //   the van with no clock. The app alerts on this.
                'dispatch_failed' => $dispatch !== null && !($dispatch['ok'] ?? false),
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
                // Same leg rule as the store board, so his card and the store can
                // never quote different arrivals: heading there now → direct;
                // delivering first → chained after his remaining stops.
                if ($trip && (string) $trip->current_leg === VanService::LEG_TO_STOP) {
                    $eta = $this->etaBetween($pos, $cur['latitude'], $cur['longitude'], 'van_stop_eta:' . $uid);
                    if ($eta) $eta['warm_from'] = $pos;
                } else {
                    $eta = $this->etaToStopAfterStops($uid, $pos, $cur['latitude'], $cur['longitude'],
                                                      'van_stop_eta:' . $uid);
                }
                $cur['eta'] = $eta;
                if ($j = $this->warmJobFor($eta, $cur['latitude'], $cur['longitude'])) {
                    $this->warmEtas([$j]);
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
        $setByStore = false;
        if (!empty($data['van_user_id']) && (int) $data['van_user_id'] !== $uid) {
            if (!$this->canManageStops($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the driver or the store can set this van\'s meet-up point.',
                ], 403);
            }
            $uid = (int) $data['van_user_id'];
            $setByStore = true;
        }
        unset($data['van_user_id']);

        // ⭐ THE TARGET MUST ACTUALLY BE RUNNING A VAN. Setting a stop creates a
        //    trip, so without this any authenticated rider could mint himself an
        //    open van trip — which paints a ghost card on the store panel and,
        //    because the dispatch-origin guard trusts an open departed trip for
        //    14h, quietly bypasses the phantom-GPS office anchor. It also stops a
        //    STALE store card on a handover day from sending the OUTGOING driver
        //    and resurrecting the trip that was just closed.
        if (!$van->isVanDriver($uid) && !$van->isCarrying($uid)) {
            $who = DB::table('t_sys_user')->where('id', $uid)->value('fullname');
            return response()->json([
                'success' => false,
                'message' => ($who ?: 'That user') . ' is not driving a van right now. '
                           . 'Refresh the van board and try again.',
            ], 422);
        }

        // ⭐ WHO SET IT DECIDES WHETHER THE VAN HAS LEFT. The driver choosing a
        //    meet-up point IS him setting off (there is deliberately no "van
        //    left" button). The STORE naming the point is a plan — the van may
        //    still be on the loading bay — so it must not stamp a departure.
        $res = $stops->setStop($uid, $data, null, !$setByStore);
        if (!$res['ok']) return response()->json(['success' => false, 'message' => $res['message']], 422);

        $trip = $van->openTrip($uid);
        $notified = $this->announceStop($uid, $van, $res, !empty($trip->departed_at));

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

            $cargo = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.assigned_rider_user_id', $uid)
                ->where('o.order_status', VanService::STATUS_ON_VAN)
                // ⭐ LOADED only. "On Van" without stamps is just the staff's plan
                //    — nothing is physically on any van yet, so telling the rider
                //    to go meet it would send him to a van that isn't carrying
                //    his boxes (and `van_user_id` would be NULL anyway).
                ->whereNotNull('o.van_user_id')
                ->whereNotNull('o.van_loaded_at')
                // ⭐⭐ NOT HIS OWN VAN. The driver is the assigned rider on his own
                //    stops AND the carrier of them, so without this he was shown
                //    "the van is waiting for you" about the van he is sitting in
                //    (seen on the device Aug-4). He collects nothing and scans no
                //    handover — his stops go straight to his wave picker.
                ->where('o.van_user_id', '!=', $uid)
                ->get([
                    'o.id', 'o.order_number', 'o.van_user_id', 'o.expected_packets',
                    'o.handover_scanned_packets',
                    DB::raw('CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")) as customer_name'),
                ]);

            if ($cargo->isEmpty()) {
                return response()->json(['success' => true, 'has_cargo' => false]);
            }

            $driverId = (int) $cargo->first()->van_user_id;

            // ⚠ One rider's boxes can sit on TWO vans. The card describes ONE van,
            //   so everything it reports — the count, the packets and the collect
            //   checklist — is scoped to that same van. Reporting a total that
            //   spans vans while showing one van's stop is how "2 waiting" ends up
            //   next to a checklist of 3.
            $cargo = $cargo->filter(fn ($c) => (int) $c->van_user_id === $driverId)->values();
            $trip = $van->openTrip($driverId);
            $stop = $stops->currentStopPayload($driverId);

            // How many stops he still has on the road right now.
            $remaining = DB::table('t_crm_prod_order')
                ->where('assigned_rider_user_id', $uid)
                ->where('order_status', VanService::STATUS_OFD)
                ->count();

            $driverName = DB::table('t_sys_user')->where('id', $driverId)->value('fullname');
            $departed   = $trip && $trip->departed_at;

            // ⭐ "REACH THE VAN BY WHEN" (the owner's original ask): the van's own
            //    arrival at the stop, so the rider can pace his run instead of
            //    guessing. Same leg rule as every other surface — heading there
            //    now → direct; delivering his own stops first → chained after
            //    them. Cached-or-approximate, never blocking, and absent rather
            //    than wrong when GPS is stale.
            $vanEta = null;
            if ($departed && $stop && ($stop['latitude'] ?? null) !== null && empty($stop['reached_at'])) {
                $dpos = $this->lastFix($driverId);
                if ($dpos) {
                    if ((string) $trip->current_leg === VanService::LEG_TO_STOP) {
                        $vanEta = $this->etaBetween($dpos, $stop['latitude'], $stop['longitude'],
                                                    'van_stop_eta:' . $driverId);
                        if ($vanEta) $vanEta['warm_from'] = $dpos;
                    } else {
                        $vanEta = $this->etaToStopAfterStops($driverId, $dpos,
                            $stop['latitude'], $stop['longitude'], 'van_stop_eta:' . $driverId);
                    }
                    if ($j = $this->warmJobFor($vanEta, $stop['latitude'], $stop['longitude'])) {
                        $this->warmEtas([$j]);
                    }
                }
            }

            // ⭐ HIS OWN required arrival: finish the stops he is riding now, then
            //    get to the rendezvous — chained through his remaining promised
            //    stops with the same per-stop buffer, live-re-estimated from his
            //    GPS when he is running behind his promises. This is the "reach
            //    the van by WHEN" half of the card; the van's arrival above is
            //    the other half.
            $yourEta = null;
            if ($stop && ($stop['latitude'] ?? null) !== null) {
                $rpos = $this->lastFix($uid);
                if ($rpos) {
                    $yourEta = $this->etaToStopAfterStops($uid, $rpos,
                        $stop['latitude'], $stop['longitude'], 'rider_stop_eta:' . $uid);
                    if ($j = $this->warmJobFor($yourEta, $stop['latitude'], $stop['longitude'])) {
                        $this->warmEtas([$j]);
                    }
                }
            }
            $youBit = $yourEta ? ' · you ~' . $yourEta['arrival_display'] : '';

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
                       . ($stop['waiting_minutes'] !== null ? ' · waiting ' . $stop['waiting_minutes'] . ' min' : '')
                       . $youBit;
            } else {
                $state = 'en_route';
                $head  = '🚚 Meet the van';
                // Both times ride in the sentence itself (server-decided words),
                // so every app build shows them with no client change.
                $sub   = ($driverName ?: 'The driver') . ' is on the way to ' . $stop['label']
                       . ($vanEta ? ' — there ~' . $vanEta['arrival_display'] : '') . $youBit . '.';
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
                // When the van will be at the stop, and when HE can be — null
                // once arrived / when GPS cannot say. The sentence above already
                // carries both; these are for clients that render them apart.
                'van_eta'      => $vanEta,
                'your_eta'     => $yourEta,
                // ⭐ THE COLLECT CHECKLIST, FROM THE SERVER. The app used to build
                //    it from /rider/orders, which is scoped to him but NOT to a
                //    van: it listed boxes merely TAGGED "On Van" (still at the
                //    store) and boxes on a different van, so the header count and
                //    the checklist disagreed and a box that was never aboard could
                //    be "collected". These rows are exactly what he may collect
                //    here. `customer_name` is a STRING on purpose — /rider/orders
                //    returns `customer` as an OBJECT, which is what rendered the
                //    scanner white on Aug-4.
                'items'        => $cargo->map(fn ($c) => [
                    'id'               => (int) $c->id,
                    'order_number'     => $c->order_number,
                    'expected_packets' => (int) ($c->expected_packets ?: 1),
                    // Survives closing and reopening the scanner mid-order —
                    // the client only ever knew about scans it made itself.
                    'handover_scanned_count' => $c->handover_scanned_packets
                        ? count((array) json_decode($c->handover_scanned_packets, true)) : 0,
                    'customer_name'    => trim((string) $c->customer_name) ?: null,
                ])->values()->all(),
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

        // ⭐ `has_work` — is there anything VAN-SHAPED to do today?
        //    A van is assigned in the registry permanently, so "a driver exists"
        //    was true every single day and the store's 🚚 tab never went away
        //    (owner, Aug-6: "the van is always showing"). The tab should follow
        //    the WORK: something tagged for the van, something aboard, or a trip
        //    already running. Tagging the first order brings it back.
        $hasWork = false;
        if ($van->available()) {
            try {
                $hasWork = DB::table('t_crm_prod_order')
                        ->where('order_status', VanService::STATUS_ON_VAN)
                        ->exists()
                    || DB::table('t_crm_prod_order')
                        ->whereNotNull('van_loaded_at')
                        ->where('van_loaded_at', '>=', now()->subHours(20))
                        ->exists()
                    || DB::table(VanService::T_TRIP)
                        ->whereNull('ended_at')
                        ->where('trip_date', '>=', today()->subDay()->format('Y-m-d'))
                        ->exists();
            } catch (\Throwable $e) {
                // A lookup failure must not hide a tab the store may need.
                $hasWork = true;
            }
        }

        return response()->json([
            'success' => true,
            'available' => $van->available(),
            'drivers' => $van->available() ? $van->todaysDrivers() : [],
            'has_work' => $hasWork,
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
                // ⭐ Self-heal a finished (or pointless) meet-up before rendering:
                //    reached + nobody left to collect = the wait is over. This is
                //    what retires a zombie left by an unpressed "Done" — the prod
                //    board read "waiting (307 min)" about a van with nothing
                //    aboard until the next poll after this shipped.
                $van->completeStopIfHandoverDone($did, $stops);
                $m    = $van->manifest($did);
                $trip = $van->openTrip($did);
                $stop = $stops->currentStopPayload($did);
                $name = DB::table('t_sys_user')->where('id', $did)->value('fullname');
                $pos  = $this->lastFix($did);

                $mode = ($trip && $trip->current_leg === VanService::LEG_TO_STOP && $stop)
                    ? 'to_stop'
                    : (($trip && $trip->departed_at) ? 'delivering' : 'loading');

                // ⭐ The van's ETA to the rendezvous — shown on the DELIVERING leg
                //    too, not just once he is formally heading there. If he chose
                //    "deliver three of mine first, then meet the riders", the
                //    riders' whole plan depends on when he actually turns up, and
                //    that is exactly when the board used to show nothing at all.
                //
                // ⭐ THE LEG SAYS WHAT HE IS DOING, so it picks the arithmetic:
                //    to_stop    → he is driving there NOW: direct GPS→stop, even
                //                 if older promised stops are still open (his
                //                 declared intent outranks the schedule);
                //    delivering → stops first, THEN the rendezvous: arrival is
                //                 chained after his remaining promised stops.
                $vanEta = null;
                if ($pos && $stop && $stop['latitude'] !== null && empty($stop['reached_at'])
                    && in_array($mode, ['to_stop', 'delivering'], true)) {
                    if ($mode === 'to_stop') {
                        $vanEta = $this->etaBetween($pos, $stop['latitude'], $stop['longitude'],
                                                    'van_stop_eta:' . $did);
                        if ($vanEta) $vanEta['warm_from'] = $pos;
                    } else {
                        $vanEta = $this->etaToStopAfterStops($did, $pos, $stop['latitude'], $stop['longitude'],
                                                             'van_stop_eta:' . $did);
                    }
                    if ($j = $this->warmJobFor($vanEta, $stop['latitude'], $stop['longitude'])) $warm[] = $j;
                }

                // Inbound riders — only those who still have cargo to collect.
                $inbound = [];
                foreach ($m['carrying'] as $g) {
                    if (!empty($g['complete'])) continue;
                    $rpos = $this->lastFix($g['user_id']);
                    $eta  = null;
                    if ($rpos && $stop && $stop['latitude'] !== null) {
                        // ⭐ AFTER HIS REMAINING STOPS. A rider four drops from
                        //    finishing used to read "6 min" here, so the store and
                        //    the driver both planned around a meeting that could
                        //    not happen for an hour.
                        $eta = $this->etaToStopAfterStops($g['user_id'], $rpos,
                            $stop['latitude'], $stop['longitude'], 'rider_stop_eta:' . $g['user_id']);
                        if ($j = $this->warmJobFor($eta, $stop['latitude'], $stop['longitude'])) $warm[] = $j;
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
            // ⚠ The `reached_at` gate used to live here too, so the moment the
            //   driver tapped "I'm here" every rider collapsed to "waiting to
            //   collect" with no distance and no time — exactly when a waiting
            //   driver most wants to know how far off they are. The store board
            //   never had that gate, so the two surfaces disagreed all through
            //   the wait.
            if ($pos && $stop && ($stop['latitude'] ?? null) !== null) {
                $eta = $this->etaToStopAfterStops((int) $g['user_id'], $pos,
                    $stop['latitude'], $stop['longitude'], 'rider_stop_eta:' . $g['user_id']);
                if ($j = $this->warmJobFor($eta, $stop['latitude'], $stop['longitude'])) $warm[] = $j;
            }

            $g['remaining_stops'] = $remaining;
            $g['eta'] = $eta;
            if ($remaining > 0) {
                // Now that the ETA is chained through those stops, say WHEN — the
                // driver could see "3 stops first" but never how long that meant.
                // Only the CHAINED figure though: when his promises are overdue
                // the fallback is a direct drive-time, and "3 stops first · ~4:32"
                // built from that would promise an arrival his stops don't allow.
                $g['state']  = 'delivering';
                $g['detail'] = $remaining . ' stop' . ($remaining === 1 ? '' : 's') . ' first'
                             . ($eta && !empty($eta['after_stops']) ? ' · ~' . $eta['arrival_display'] : '');
            } elseif ($eta) {
                $g['state']  = 'coming';
                // Distance is absent when the figure rests on promised times
                // rather than a measured leg — don't render a stray separator.
                $g['detail'] = ($eta['distance_display'] ? $eta['distance_display'] . ' · ' : '')
                             . '~' . $eta['minutes'] . ' min';
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

    /**
     * ⭐ WHEN WILL THEY REACH THE MEET-UP POINT — allowing for the stops they
     *    still have to deliver first (owner ruling: "their eta and then meet up
     *    point reaching time").
     *
     * A straight GPS→stop line is the right answer ONLY for someone who is
     * driving there now. For anyone mid-route it is badly wrong: a rider with
     * four promised stops left showed "6 min" on the store board, so the van
     * planned around a rendezvous that could not happen for an hour.
     *
     * The chain is cheap because the expensive part is already paid for: every
     * promised stop carries `estimated_delivery_at` from the dispatch engine, so
     * the only leg left to price is the LAST stop → the meet point.
     *
     *      arrival ≈ last promised delivery time + drive(last stop → meet point)
     *
     * Falls back to the direct figure whenever there is nothing promised, or the
     * promises are already in the past (his run is effectively over). Returns the
     * usual eta shape plus `after_stops` / `stops_first`, and `warm_from` so the
     * caller can warm the SAME cache entry it just read.
     */
    /**
     * ⏱ Matches `calculateDeliveryEtas`' $stopTimeMinutes: `estimated_delivery_at`
     *   is the ARRIVAL at a stop, and ~10 minutes are spent delivering there —
     *   so the ride to the meet point starts one service-stop after the last
     *   promise. Leaving this out made every chained arrival ~10 min optimistic.
     */
    private const STOP_SERVICE_MIN = 10;

    private function etaToStopAfterStops(int $userId, ?array $pos, float $toLat, float $toLng, string $cacheKeyBase): ?array
    {
        $direct = function () use ($pos, $toLat, $toLng, $cacheKeyBase) {
            if (!$pos) return null;
            $e = $this->etaBetween($pos, $toLat, $toLng, $cacheKeyBase);
            if ($e) $e['warm_from'] = $pos;
            return $e;
        };

        try {
            $rows = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.assigned_rider_user_id', $userId)
                ->where('o.order_status', VanService::STATUS_OFD)
                ->whereNotNull('o.estimated_delivery_at')
                // Route order — the sequence he is actually riding.
                ->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')
                ->get([
                    'o.estimated_delivery_at',
                    DB::raw('COALESCE(c.latitude, c.geocoded_latitude) as lat'),
                    DB::raw('COALESCE(c.longitude, c.geocoded_longitude) as lng'),
                ]);
        } catch (\Throwable $e) {
            return $direct();
        }

        if ($rows->isEmpty()) return $direct();

        $stopsLeft = $rows->count();
        $last      = $rows->sortByDesc('estimated_delivery_at')->first();
        $lastTs    = strtotime((string) $last->estimated_delivery_at);

        if ($lastTs && $lastTs > time()) {
            // ── ON SCHEDULE: his promises still stand, and they already embody
            //    his position at dispatch + Google legs + the per-stop buffer.
            //    Delivered stops drop out of OFD, so the anchor tracks his
            //    actual progress poll by poll. Arrival = last promised arrival
            //    + the service stop there + the ride to the meet point.
            $tail = null;
            if ($last->lat !== null && $last->lng !== null) {
                $tail = $this->etaBetween(
                    ['lat' => (float) $last->lat, 'lng' => (float) $last->lng, 'age_minutes' => null],
                    $toLat, $toLng, $cacheKeyBase . ':tail'
                );
            }

            $arrivalTs = $lastTs + self::STOP_SERVICE_MIN * 60 + (int) (($tail['minutes'] ?? 0) * 60);

            return [
                'minutes'          => max(1, (int) round(($arrivalTs - time()) / 60)),
                // Honest about how it was built: the tail leg's source when we
                // have one, otherwise it rests purely on the promised times.
                'source'           => $tail['source'] ?? 'schedule',
                'cache_key'        => $tail['cache_key'] ?? null,
                'warm_from'        => ($tail && $last->lat !== null)
                    ? ['lat' => (float) $last->lat, 'lng' => (float) $last->lng] : null,
                'distance_meters'  => $tail['distance_meters'] ?? null,
                'distance_display' => $tail['distance_display'] ?? null,
                'arrival_display'  => date('h:i A', $arrivalTs),
                'gps_age_minutes'  => $pos['age_minutes'] ?? null,
                // What the boards say out loud: "after 3 stops · ~4:20 PM".
                'after_stops'      => true,
                'stops_first'      => $stopsLeft,
            ];
        }

        // ── RUNNING LATE: every promise is in the past but stops remain. The
        //    schedule is dead, so RE-ESTIMATE LIVE from where he actually is:
        //    chain his current GPS through the remaining stops in route order at
        //    the board's ~22 km/h, the same 10-min service per stop, then the
        //    tail to the meet point. This used to fall back to a DIRECT figure —
        //    "2 min away" about a rider with three stops still to deliver, the
        //    exact lie the chained ETA was built to kill.
        if (!$pos) return null;   // late AND no GPS — no honest number exists

        $minutes = 0.0;
        $cur     = ['lat' => (float) $pos['lat'], 'lng' => (float) $pos['lng']];
        foreach ($rows as $r) {
            if ($r->lat !== null && $r->lng !== null) {
                $m = \App\Services\LocationService::calculateDistance(
                    $cur['lat'], $cur['lng'], (float) $r->lat, (float) $r->lng);
                if ($m > self::ETA_SANE_MAX_METRES) return $direct();   // phantom fix — don't compound it
                $minutes += ($m / 1000) / 22 * 60;
                $cur = ['lat' => (float) $r->lat, 'lng' => (float) $r->lng];
            }
            // A stop with no pin still takes the service time.
            $minutes += self::STOP_SERVICE_MIN;
        }

        $tail = $this->etaBetween($cur + ['age_minutes' => null], $toLat, $toLng, $cacheKeyBase . ':tail');
        if (!$tail) return $direct();
        $minutes  += $tail['minutes'];
        $arrivalTs = time() + (int) round($minutes * 60);

        return [
            'minutes'          => max(1, (int) round($minutes)),
            // The chain legs are approximations even when the tail is cached
            // Google — never let a live re-estimate masquerade as authoritative.
            'source'           => 'approx',
            'cache_key'        => $tail['cache_key'],
            'warm_from'        => $cur,
            'distance_meters'  => null,
            'distance_display' => null,
            'arrival_display'  => date('h:i A', $arrivalTs),
            'gps_age_minutes'  => $pos['age_minutes'] ?? null,
            'after_stops'      => true,
            'stops_first'      => $stopsLeft,
            // Rebuilt from his live position, not the promise log.
            'live'             => true,
        ];
    }

    /** Queue a warm job for an approximated ETA, if it needs one. */
    private function warmJobFor(?array $eta, float $toLat, float $toLng): ?array
    {
        if (!$eta || ($eta['source'] ?? '') !== 'approx') return null;
        if (empty($eta['cache_key']) || empty($eta['warm_from'])) return null;
        return ['key' => $eta['cache_key'], 'from' => $eta['warm_from'],
                'to' => ['lat' => $toLat, 'lng' => $toLng]];
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
                if ($left > 0) {
                    return $who . ' is delivering his own orders · ' . $left . ' still to hand over';
                }
                // Nothing aboard at all (an empty departed trip — test runs, or a
                // day that finished its handover): "delivering his own orders"
                // would be a claim about orders that do not exist.
                if (($m['totals']['mine_total'] ?? 0) === 0 && ($m['totals']['carried_total'] ?? 0) === 0) {
                    return $who . ' is out with the van';
                }
                return $who . ' is delivering his own orders';
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

        // ⭐ If the meet-up point was set BEFORE this rider's cargo was aboard,
        //    he was never told where to go: `announceStop` can only reach riders
        //    with boxes already loaded. His FIRST box landing is the moment he
        //    becomes part of the rendezvous — tell him now, once.
        if (($res['ok'] ?? false) && !empty($res['complete'])) {
            $this->announceStopToNewRider($order, (int) $data['van_user_id'], $van);
        }

        return response()->json(['success' => $res['ok']] + $res, $res['ok'] ? 200 : 422);
    }

    /**
     * Push the current meet-up point to a rider whose FIRST box just completed
     * loading. Best-effort: a push failure must never fail a scan.
     */
    private function announceStopToNewRider($order, int $vanUserId, VanService $van): void
    {
        try {
            $riderId = (int) $order->assigned_rider_user_id;
            // The driver collects nothing — his stops go to his wave picker.
            if (!$riderId || $riderId === $vanUserId) return;

            $stop = app(\App\Services\Riders\VanStopService::class)->currentStopPayload($vanUserId);
            if (!$stop) return;   // no rendezvous set yet — announceStop will cover it later

            // Only his FIRST loaded box on this van — box two says nothing new.
            $loadedCount = DB::table('t_crm_prod_order')
                ->where('van_user_id', $vanUserId)
                ->where('assigned_rider_user_id', $riderId)
                ->where('order_status', VanService::STATUS_ON_VAN)
                ->whereNotNull('van_loaded_at')
                ->count();
            if ($loadedCount !== 1) return;

            $trip       = $van->openTrip($vanUserId);
            $driverName = DB::table('t_sys_user')->where('id', $vanUserId)->value('fullname') ?: 'The van';
            app(\App\Services\FirebaseService::class)->notifyUser(
                $riderId,
                [
                    'title' => '📍 Meet the van at ' . ($stop['label'] ?? 'the meet-up point'),
                    'body'  => !empty($trip->departed_at)
                        ? $driverName . ' is heading there with your orders.'
                        : $driverName . ' will meet you there once the van leaves.',
                ],
                [
                    'type'      => 'van_stop_set',
                    'latitude'  => (string) ($stop['latitude'] ?? ''),
                    'longitude' => (string) ($stop['longitude'] ?? ''),
                    'label'     => (string) ($stop['label'] ?? ''),
                ],
                'shift_notifications'
            );
        } catch (\Throwable $e) {
            Log::warning('First-box stop push failed', ['order' => $order->id ?? null, 'error' => $e->getMessage()]);
        }
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
    private function announceStop(int $driverId, VanService $van, array $stop, bool $departed = true): int
    {
        $riders = $van->ridersAwaiting($driverId);
        if (empty($riders)) return 0;

        $driverName = DB::table('t_sys_user')->where('id', $driverId)->value('fullname') ?: 'The van';
        // ⚠ Don't say he is on his way if the van has not left. The store often
        //   names the point while loading is still going on; "will meet you
        //   there" is true then, "is heading there" is not.
        $body = $departed
            ? $driverName . ' is heading there with your orders.'
            : $driverName . ' will meet you there once the van leaves.';
        $sent = 0;
        foreach ($riders as $rid) {
            try {
                app(\App\Services\FirebaseService::class)->notifyUser(
                    $rid,
                    [
                        'title' => '📍 Meet the van at ' . ($stop['label'] ?? 'the meet-up point'),
                        'body'  => $body,
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
            if ($dispatch && ($dispatch['ok'] ?? false)) {
                $bits[] = ($dispatch['dispatched'] ?? 0) . ' deliveries dispatched.';
            } elseif ($dispatch) {
                // Say what went wrong. "Delivering." over a failed dispatch left
                // the driver believing his stops were timed when none were.
                $bits[] = '⚠️ Not dispatched: ' . ($dispatch['message'] ?: 'could not time those deliveries.');
            } else {
                $bits[] = 'Delivering.';
            }
        }
        if ($leg === VanService::LEG_DONE) $bits[] = 'Trip closed.';
        return implode(' ', $bits) ?: 'Updated.';
    }
}
