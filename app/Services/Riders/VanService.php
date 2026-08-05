<?php

namespace App\Services\Riders;

use App\Models\CRM\OrderModel;
use App\Services\Riders\VehicleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Van operations (Aug-2026) — loading, the trip, and handovers.
 *
 * ⭐ THE MODEL (owner ruling Aug-4). When the van is loaded, EVERY order on that
 *    trip goes to `on_van` — the driver's OWN deliveries included. He is the
 *    CARRIER on all of them (`van_user_id`) and the ordinary ASSIGNED RIDER on his
 *    own. One order is never both, so:
 *      • the store sees ONE truthful group, subdivided by assigned rider;
 *      • ONE scan rule covers everything — the scanner must be the ASSIGNED rider;
 *      • `on_van` doubles as the HOLD state, which is what makes "deliver three
 *        stops first, then meet the riders" work with no extra machinery: an
 *        on_van order is inert to every OFD-gated mechanism by construction
 *        (left-without-dispatch counts OFD-with-no-ETA only; the ETA/promise
 *        engine only ever reads OFD; the customer sees "processing").
 *
 * ⭐ EVERYTHING IS SCHEMA-GUARDED. `available()` gates the class on batch 14, so an
 *    upload that lands before the SQL degrades to "no van features" rather than
 *    erroring — the same discipline as VehicleService.
 */
class VanService
{
    public const T_TRIP     = 't_ops_van_trip';
    public const T_HANDOVER = 't_ops_van_handover';

    public const STATUS_ON_VAN = 'on_van';
    public const STATUS_OFD    = 'out_for_delivery';

    /** Legs a trip can be on. The journey strip's label is derived from this. */
    public const LEG_LOADING    = 'loading';
    public const LEG_DELIVERIES = 'deliveries';
    public const LEG_TO_STOP    = 'to_stop';
    public const LEG_DONE       = 'done';

    // =================================================================
    // AVAILABILITY
    // =================================================================

    /** Has batch 14 been run? Cached per process. */
    public function available(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $ok = Schema::hasTable(self::T_TRIP)
               && Schema::hasTable(self::T_HANDOVER)
               && Schema::hasColumn('t_crm_prod_order', 'van_user_id')
               && DB::table('t_crm_order_status_master')->where('status_code', self::STATUS_ON_VAN)->exists();
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * Is this user a van driver right now? Derived from the vehicle registry —
     * there is no separate flag to rotate and forget (registry plan §1.3).
     */
    public function isVanDriver(int $userId, ?string $date = null): bool
    {
        try {
            $res = new VehicleResolver();
            if (!$res->available()) return false;
            $v = $res->vehicle($res->vehicleForDay($userId, $date ?: today()->format('Y-m-d')));
            return $v && (string) $v->vtype === 'van';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The van he drives today, or null. */
    public function vanVehicleIdFor(int $userId, ?string $date = null): ?int
    {
        try {
            $res = new VehicleResolver();
            $id  = $res->vehicleForDay($userId, $date ?: today()->format('Y-m-d'));
            $v   = $res->vehicle($id);
            return ($v && (string) $v->vtype === 'van') ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Everyone driving a van TODAY, loaded or not.
     *
     * ⭐ The store panel used to be derived purely from cargo, which meant it
     *    appeared only AFTER the first box was scanned aboard — precisely the
     *    wrong way round, because the store's first job of the day is loading.
     *    This answers "who is driving?" from the registry alone, so the panel
     *    (and its load-scan door) is there from the moment the van is assigned.
     *
     *    Returns [['user_id' => int, 'name' => string, 'vehicle_id' => int], …].
     */
    public function todaysDrivers(?string $date = null): array
    {
        if (!$this->available()) return [];
        $date = $date ?: today()->format('Y-m-d');

        try {
            $res = new VehicleResolver();
            if (!$res->available()) return [];

            // Van vehicles first — there are a handful, so this stays tiny.
            // NOTE the conventions here: t_ops_vehicle.is_active is 1/0 (not
            // Y/N), and the assignment window columns are assigned_on /
            // released_on. Getting either wrong fails into the catch below and
            // silently returns "nobody drives a van today".
            $vanIds = DB::table(VehicleService::T_VEHICLE)
                ->where('vtype', 'van')->where('is_active', 1)
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (!$vanIds) return [];

            // Whoever holds one on this date (assignment windows are date-scoped,
            // and a manager's day-override wins — both live in the resolver).
            $holders = DB::table(VehicleService::T_ASSIGN)
                ->whereIn('vehicle_id', $vanIds)
                ->whereDate('assigned_on', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('released_on')->orWhereDate('released_on', '>=', $date);
                })
                ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

            $out = [];
            foreach ($holders as $uid) {
                // Re-ask the resolver rather than trusting the raw window: it is
                // the one place that applies overrides, so the two can't drift.
                $vid = $this->vanVehicleIdFor($uid, $date);
                if (!$vid) continue;
                $out[] = [
                    'user_id'    => $uid,
                    'name'       => (string) (DB::table('t_sys_user')->where('id', $uid)->value('fullname') ?: 'Driver'),
                    'vehicle_id' => $vid,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            // Swallowing keeps the store's orders screen alive, but a silent []
            // here reads as "nobody drives a van today" — which is exactly how a
            // wrong column name hid itself once. Leave a trail.
            Log::warning('todaysDrivers failed', ['date' => $date, 'error' => $e->getMessage()]);
            return [];
        }
    }

    // =================================================================
    // THE TRIP
    // =================================================================

    /** The open trip for this driver (not ended), or null. */
    public function openTrip(int $userId)
    {
        if (!$this->available()) return null;
        try {
            return DB::table(self::T_TRIP)
                ->where('van_user_id', $userId)->whereNull('ended_at')
                ->orderByDesc('id')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The trip a load belongs to, created on first use.
     *
     * ⚠ Deliberately NOT created at login or check-in: a trip row exists because
     *   something was loaded onto the van, so an idle day leaves no rows and the
     *   store panel has nothing to render.
     */
    public function ensureTrip(int $userId, ?int $vehicleId = null, ?int $actorId = null)
    {
        if (!$this->available()) return null;
        $open = $this->openTrip($userId);
        if ($open) {
            // ⚠ A trip the driver forgot to close must not leak into the next day:
            //   reusing it would keep the departure ping latched (riders never told
            //   the van left on day 2) and freeze the journey strip on yesterday's
            //   state. A previous-day open trip is closed here, exactly when the
            //   next load begins — not by a scheduler, so a genuine past-midnight
            //   run (left 11:50pm, still out at 00:30) is untouched until the
            //   STORE actually starts loading the next trip.
            if (substr((string) $open->trip_date, 0, 10) === today()->format('Y-m-d')) {
                return $open;
            }
            DB::table(self::T_TRIP)->where('id', $open->id)->update([
                'ended_at'    => now(),
                'current_leg' => self::LEG_DONE,
                'note'        => trim(((string) ($open->note ?? '')) . ' [auto-closed at next load]'),
                'updated_at'  => now(),
            ]);
            // Its stops close with it — a dangling "waiting" stop from yesterday
            // would keep rider meet-cards pointing at a rendezvous nobody is at.
            DB::table(self::T_HANDOVER)
                ->where('van_user_id', $userId)->whereNull('completed_at')
                ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
            Log::info('Stale van trip auto-closed at next load', ['trip_id' => $open->id, 'user' => $userId]);
        }

        try {
            $id = (int) DB::table(self::T_TRIP)->insertGetId([
                'van_user_id'    => $userId,
                'van_vehicle_id' => $vehicleId ?: $this->vanVehicleIdFor($userId),
                'trip_date'      => today()->format('Y-m-d'),
                'current_leg'    => self::LEG_LOADING,
                'created_by'     => $actorId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            return DB::table(self::T_TRIP)->where('id', $id)->first();
        } catch (\Throwable $e) {
            Log::error('VanService::ensureTrip failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // =================================================================
    // LOADING
    // =================================================================

    /**
     * Scan one packet of an order onto the van.
     *
     * Mirrors `RiderController::dispatchScan` exactly — same `ORDER|idx/total`
     * parsing, same unique-sorted packet array, same "complete" rule — because the
     * store already scans every packet before it hands anything to a rider, and
     * this is that same discipline pointed at the van.
     *
     * On completion the order flips `processing → on_van` and is stamped with the
     * carrier. ⭐ Any assigned order is loadable INCLUDING the driver's own — that
     * is the Aug-4 model, and the old "assigned ≠ carrier" validation is
     * deliberately absent.
     */
    public function loadScan(OrderModel $order, string $scanCode, int $vanUserId, int $actorId,
                             bool $manual = false): array
    {
        if (!$this->available()) {
            return $this->fail('Van features are not set up on this server yet (SQL batch 14).');
        }

        // Loadable states: still in the building, or already on the van (re-scan).
        if (!in_array($order->order_status, ['processing', self::STATUS_ON_VAN], true)) {
            return $this->fail('Only orders being prepared can be loaded on the van. This one is '
                . str_replace('_', ' ', (string) $order->order_status) . '.');
        }
        if (!$order->assigned_rider_user_id) {
            return $this->fail('Assign this order to a rider before loading it on the van.');
        }

        $parsed = $this->parseScan($scanCode, $order);
        if (!$parsed['ok']) return $parsed;

        $scanned = $this->mergePacket($order->van_loaded_packets, $parsed['idx']);
        $target  = $parsed['target'];
        $complete = count($scanned) >= $target;

        try {
            $trip = $this->ensureTrip($vanUserId, null, $actorId);

            $order->van_loaded_packets = json_encode(array_values($scanned));
            if ($complete && empty($order->van_loaded_at)) {
                $order->van_loaded_at  = now();
                $order->van_loaded_by  = $actorId;
                $order->van_user_id    = $vanUserId;
                $order->van_vehicle_id = $this->vanVehicleIdFor($vanUserId);
                $order->van_trip_id    = $trip->id ?? null;

                // ⭐ THE DRIVER'S OWN stops get their hand-over verification HERE:
                //    the load scan is the last custody scan they will ever have
                //    (he never collects from himself), so without this stamp his
                //    Dispatch button warned "not scanned for hand-over" about
                //    boxes that were scanned onto his own van. Everyone else's
                //    orders are stamped by the handover scan instead — the rule
                //    is one and the same: the box's LAST custody scan verifies it.
                if ((int) $order->assigned_rider_user_id === $vanUserId
                    && empty($order->dispatch_scanned_at)) {
                    $order->dispatch_scanned_at      = now();
                    $order->dispatch_scanned_by      = $actorId;
                    $order->dispatch_scanned_packets = json_encode(array_values($scanned));
                }
            }
            $order->save();

            // The status flip is the LAST thing, and only once every packet is on
            // board — a half-loaded order must never look like it left the store.
            if ($complete && $order->order_status !== self::STATUS_ON_VAN) {
                // ⭐ A hand-entered packet is recorded as such. Loading by hand is
                //    allowed (a smudged label must not stop the van), but the
                //    history has to say which it was — that distinction is the
                //    whole reason the scan is trusted in the first place.
                $order->changeStatus(
                    self::STATUS_ON_VAN,
                    $manual ? 'Loaded on the van (entered by hand)' : 'Loaded on the van',
                    $actorId
                );
            }

            return [
                'ok' => true,
                'already_scanned' => $parsed['already'],
                'scanned_count'   => count($scanned),
                'target'          => $target,
                'complete'        => $complete,
                'message'         => $complete
                    ? 'All packets on board — order is on the van'
                    : 'Packet ' . $parsed['idx'] . ' loaded (' . count($scanned) . ' of ' . $target . ')',
            ];
        } catch (\Throwable $e) {
            Log::error('VanService::loadScan failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return $this->fail('Could not record that scan.');
        }
    }

    /**
     * Take an order back off the van (mis-load, or it never made it out).
     * Returns it to `processing`, clearing the carrier stamps so the next trip
     * starts clean. Its history rows survive — only the live pointers are cleared.
     */
    public function unload(OrderModel $order, int $actorId): array
    {
        if (!$this->available()) return $this->fail('Van features are not set up yet (SQL batch 14).');
        if ($order->order_status !== self::STATUS_ON_VAN) {
            return $this->fail('That order is not on the van.');
        }
        try {
            $order->van_user_id = null;
            $order->van_vehicle_id = null;
            $order->van_trip_id = null;
            $order->van_loaded_at = null;
            $order->van_loaded_by = null;
            $order->van_loaded_packets = null;
            $order->save();
            $order->changeStatus('processing', 'Taken off the van', $actorId);

            return ['ok' => true, 'message' => 'Order taken off the van.'];
        } catch (\Throwable $e) {
            Log::error('VanService::unload failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return $this->fail('Could not take that order off the van.');
        }
    }

    // =================================================================
    // HANDOVER
    // =================================================================

    /**
     * The receiving rider scans his packets at the meet point.
     *
     * ⭐ ONE RULE: the scanner must be the order's ASSIGNED rider. That single test
     *    covers every case in the Aug-4 model — a rider taking his own orders
     *    passes, anyone touching someone else's is refused BY NAME (the Jul-18 Q5
     *    ruling), and the driver needs no special case because his own orders were
     *    never anyone else's to collect.
     *
     * On completion the order flips `on_van → out_for_delivery` UNDISPATCHED: it
     * joins his list as a stop with no promise yet, and he presses Dispatch to
     * start the clock (ruling Q1/V1). The store-set sequence rides along untouched
     * because `delivery_priority` is not cleared on this transition.
     */
    public function handoverScan(OrderModel $order, string $scanCode, int $scannerId,
                                 ?float $lat = null, ?float $lng = null): array
    {
        if (!$this->available()) return $this->fail('Van features are not set up yet (SQL batch 14).');

        if ($order->order_status !== self::STATUS_ON_VAN) {
            return $this->fail($order->order_status === self::STATUS_OFD
                ? 'You already have this order — it is out for delivery.'
                : 'That order is not on the van.');
        }

        if ((int) $order->assigned_rider_user_id !== $scannerId) {
            $name = DB::table('t_sys_user')->where('id', $order->assigned_rider_user_id)->value('fullname');
            return $this->fail('This order is for ' . ($name ?: 'another rider')
                . ' — ask the store to reassign it before you take it.');
        }

        $parsed = $this->parseScan($scanCode, $order);
        if (!$parsed['ok']) return $parsed;

        $scanned  = $this->mergePacket($order->handover_scanned_packets, $parsed['idx']);
        $target   = $parsed['target'];
        $complete = count($scanned) >= $target;

        try {
            $order->handover_scanned_packets = json_encode(array_values($scanned));
            if ($complete && empty($order->handover_at)) {
                $order->handover_at         = now();
                $order->handover_scanned_by = $scannerId;
                if ($lat !== null && $lng !== null) {
                    $order->handover_lat = $lat;
                    $order->handover_lng = $lng;
                }
                // ⭐ The handover scan IS the hand-over verification. Every packet
                //    was scanned onto the van by the store and has now been scanned
                //    off it by its rider — stricter custody than the ordinary
                //    dispatch scan. Without this stamp the rider's Dispatch button
                //    warned "not scanned for hand-over" about orders that had just
                //    been scanned twice (owner hit it on the device, Aug-4), and
                //    the store banner kept nagging about them too.
                if (empty($order->dispatch_scanned_at)) {
                    $order->dispatch_scanned_at      = now();
                    $order->dispatch_scanned_by      = $scannerId;
                    $order->dispatch_scanned_packets = json_encode(array_values($scanned));
                }
            }
            $order->save();

            if ($complete) {
                // → OFD, still undispatched. `van_user_id` is KEPT: it is how the
                //   reports answer "which orders went out on the van".
                $order->changeStatus(self::STATUS_OFD, 'Handed over from the van', $scannerId);
            }

            return [
                'ok' => true,
                'already_scanned' => $parsed['already'],
                'scanned_count'   => count($scanned),
                'target'          => $target,
                'complete'        => $complete,
                'message'         => $complete
                    ? 'Order collected — press Dispatch when you set off'
                    : 'Packet ' . $parsed['idx'] . ' collected (' . count($scanned) . ' of ' . $target . ')',
            ];
        } catch (\Throwable $e) {
            Log::error('VanService::handoverScan failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return $this->fail('Could not record that scan.');
        }
    }

    /**
     * Manager override: hand an order over without a scan (damaged label, dead
     * phone). Same end state, explicitly attributed, and it still refuses to hand
     * an order to anyone but its assigned rider — the rule is the model, not a
     * scanning detail.
     */
    public function handoverOverride(OrderModel $order, int $actorId, string $reason): array
    {
        if (!$this->available()) return $this->fail('Van features are not set up yet (SQL batch 14).');
        if ($order->order_status !== self::STATUS_ON_VAN) return $this->fail('That order is not on the van.');
        if (!$order->assigned_rider_user_id) return $this->fail('That order has no assigned rider.');

        try {
            $order->handover_at = now();
            $order->handover_scanned_by = $actorId;
            $order->save();
            $order->changeStatus(self::STATUS_OFD,
                'Handed over without scan: ' . mb_substr($reason, 0, 180), $actorId);

            Log::info('Van handover override', [
                'order' => $order->id, 'by' => $actorId, 'reason' => $reason,
            ]);
            return ['ok' => true, 'message' => 'Handover recorded.'];
        } catch (\Throwable $e) {
            Log::error('VanService::handoverOverride failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return $this->fail('Could not record that handover.');
        }
    }

    // =================================================================
    // READS
    // =================================================================

    /**
     * What the van is carrying, split the way the driver actually thinks about it:
     * his OWN stops (which he delivers) and everyone else's (which he hands over).
     */
    public function manifest(int $vanUserId): array
    {
        if (!$this->available()) {
            return ['available' => false, 'mine' => [], 'carrying' => [], 'to_load' => [], 'totals' => $this->emptyTotals()];
        }

        try {
            $rows = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->where('o.van_user_id', $vanUserId)
                ->whereIn('o.order_status', [self::STATUS_ON_VAN, self::STATUS_OFD])
                ->whereNotNull('o.van_loaded_at')
                ->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')
                ->get([
                    'o.id', 'o.order_number', 'o.order_status', 'o.assigned_rider_user_id',
                    'o.delivery_priority', 'o.expected_packets', 'o.handover_at',
                    'o.eta_calculated_at', 'o.address_line1', 'o.address_city',
                    'o.van_loaded_packets',
                    'u.fullname as rider_name',
                    DB::raw('CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")) as customer_name'),
                ]);

            $mine = [];
            $byRider = [];
            foreach ($rows as $r) {
                $item = [
                    'id'            => (int) $r->id,
                    'order_number'  => $r->order_number,
                    'status'        => $r->order_status,
                    'priority'      => $r->delivery_priority !== null ? (int) $r->delivery_priority : null,
                    'packets'       => (int) ($r->expected_packets ?: 1),
                    // How many of those packets are actually aboard — the store
                    // board shows "2/3" while a multi-packet order is half-loaded.
                    'loaded_packets' => $r->van_loaded_packets
                        ? count((array) json_decode($r->van_loaded_packets, true)) : 0,
                    'customer'      => trim((string) $r->customer_name) ?: null,
                    'area'          => $r->address_city ?: null,
                    'handed_over'   => $r->handover_at !== null,
                    'dispatched'    => $r->eta_calculated_at !== null,
                ];

                if ((int) $r->assigned_rider_user_id === $vanUserId) {
                    $mine[] = $item;
                } else {
                    $rid = (int) $r->assigned_rider_user_id;
                    if (!isset($byRider[$rid])) {
                        $byRider[$rid] = [
                            'user_id' => $rid,
                            'name'    => $r->rider_name ?: ('Rider #' . $rid),
                            'orders'  => [], 'packets' => 0, 'handed' => 0,
                        ];
                    }
                    $byRider[$rid]['orders'][] = $item;
                    $byRider[$rid]['packets']  += $item['packets'];
                    if ($item['handed_over']) $byRider[$rid]['handed']++;
                }
            }

            // ⭐ TAGGED BUT NOT YET ABOARD. Staff mark "On Van" as the plan; the
            //    scan is what puts a box on THIS van. Until then the order sits
            //    here so the driver and the store both see what still has to be
            //    scanned — any rider's orders, because the van carries for
            //    everyone. (`van_user_id IS NULL` keeps a box already scanned
            //    onto another van off this list.)
            $toLoad = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->where('o.order_status', self::STATUS_ON_VAN)
                ->whereNull('o.van_loaded_at')
                ->whereNull('o.van_user_id')
                ->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')
                ->get([
                    'o.id', 'o.order_number', 'o.assigned_rider_user_id',
                    'o.expected_packets', 'o.van_loaded_packets', 'o.address_city',
                    'u.fullname as rider_name',
                    DB::raw('CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")) as customer_name'),
                ])
                ->map(fn ($r) => [
                    'id'               => (int) $r->id,
                    'order_number'     => $r->order_number,
                    'rider_id'         => (int) $r->assigned_rider_user_id,
                    'rider_name'       => $r->rider_name ?: null,
                    'is_driver_own'    => (int) $r->assigned_rider_user_id === $vanUserId,
                    'customer_name'    => trim((string) $r->customer_name) ?: null,
                    'area'             => $r->address_city ?: null,
                    'expected_packets' => (int) ($r->expected_packets ?: 1),
                    'van_loaded_count' => $r->van_loaded_packets
                        ? count((array) json_decode($r->van_loaded_packets, true)) : 0,
                    'van_loaded_at'    => null,
                ])->values()->all();

            foreach ($byRider as &$g) {
                $g['total']    = count($g['orders']);
                $g['complete'] = $g['handed'] >= $g['total'] && $g['total'] > 0;
            }
            unset($g);

            return [
                'available' => true,
                'mine'      => $mine,
                'carrying'  => array_values($byRider),
                'to_load'   => $toLoad,
                'totals'    => [
                    'mine_total'       => count($mine),
                    'mine_on_van'      => count(array_filter($mine, fn ($m) => $m['status'] === self::STATUS_ON_VAN)),
                    'mine_dispatched'  => count(array_filter($mine, fn ($m) => $m['dispatched'])),
                    'carried_total'    => array_sum(array_map(fn ($g) => $g['total'], $byRider)),
                    'carried_handed'   => array_sum(array_map(fn ($g) => $g['handed'], $byRider)),
                    'riders_waiting'   => count(array_filter($byRider, fn ($g) => !$g['complete'])),
                    'to_load'          => count($toLoad),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('VanService::manifest failed', ['user' => $vanUserId, 'error' => $e->getMessage()]);
            return ['available' => true, 'mine' => [], 'carrying' => [], 'to_load' => [], 'totals' => $this->emptyTotals()];
        }
    }

    /** Riders who still have cargo on this van — the push audience, derived. */
    public function ridersAwaiting(int $vanUserId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table('t_crm_prod_order')
                ->where('van_user_id', $vanUserId)
                ->where('order_status', self::STATUS_ON_VAN)
                ->where('assigned_rider_user_id', '!=', $vanUserId)
                ->distinct()->pluck('assigned_rider_user_id')
                ->map(fn ($v) => (int) $v)->filter()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =================================================================
    // THE MANUAL-CHANGE GUARD (owner ruling Aug-4)
    // =================================================================

    /**
     * "The status should not be changed from van until the rider collects it by
     * scanning." — the owner, Aug-4.
     *
     * `changeStatus` deliberately enforces no transitions (WooCommerce sync and a
     * dozen internal flows rely on that), so this rule lives at the HUMAN doors:
     * the web edit form, the store-mode status endpoint, the Status-Hub service,
     * the rider's deliver endpoint and the CSV bulk import all consult this before
     * moving an order that is on the van. The van's own flows (handover scan,
     * no-scan override, unload, the driver's dispatch flip) call changeStatus
     * directly and are not affected — they ARE the sanctioned doors.
     *
     * Returns a refusal message, or null when the change is fine.
     *
     * Allowed manual targets from on_van — business reality, not loopholes:
     *   cancelled / refunded / on_hold: an order can die or pause while riding
     *   the van. changeStatus already restores deducted inventory on cancel, and
     *   the manifest drops the order (it filters on_van/OFD), so the driver just
     *   brings the box back. Everything else must go through a van door.
     */
    public static function manualChangeBlock($order, string $targetStatus): ?string
    {
        try {
            if (($order->order_status ?? null) !== self::STATUS_ON_VAN) return null;
            if ($targetStatus === self::STATUS_ON_VAN) return null;

            // ⭐⭐ THE STATUS IS THE PLAN, THE SCAN IS THE PROOF (owner ruling
            //    Aug-4, reversing a same-day entry block). Staff set "On Van" by
            //    hand to tell everyone THESE ORDERS GO BY VAN — without the tag
            //    there is no way to say so. The load scan then confirms the box
            //    physically went aboard and stamps WHOSE van and WHEN
            //    (`van_user_id`/`van_loaded_at`) — the fields every board reads.
            //
            //    So the custody rules below apply only once the order is
            //    actually LOADED. A tagged-but-unloaded order is just a plan,
            //    and plans may be changed freely.
            $loaded = !empty($order->van_loaded_at) || !empty($order->van_user_id);
            if (!$loaded) return null;

            if (in_array($targetStatus, ['cancelled', 'refunded', 'on_hold', 'on-hold'], true)) {
                return null;
            }

            if ($targetStatus === 'processing') {
                // ⚠️ The `unload` endpoint does this properly (it also clears the
                //    van stamps, which a bare status change would leave behind,
                //    stranding the order on a van it is no longer in). It has NO
                //    BUTTON YET — until it does, this message must not send
                //    people looking for one.
                return 'This order is on the van, so taking it off has to clear the van records '
                     . 'too — otherwise it stays attached to a van it is no longer in. Ask for '
                     . '"take off the van" to be run for this order.';
            }

            $rider = null;
            try {
                $rider = DB::table('t_sys_user')->where('id', $order->assigned_rider_user_id)->value('fullname');
            } catch (\Throwable $e) {
                // name is a nicety, never a dependency
            }

            return 'This order is on the van. '
                 . ($rider ? $rider : 'The assigned rider')
                 . ' must collect it with the handover scan (or a manager records a no-scan '
                 . 'handover from the van panel) — that is what proves who took it and where.';
        } catch (\Throwable $e) {
            // The guard must never turn a status change into a 500. Fail open =
            // pre-van behaviour.
            return null;
        }
    }

    // =================================================================
    // internals
    // =================================================================

    /**
     * Parse a packet QR against THIS order. Copied in behaviour from dispatchScan
     * so a picker's muscle memory carries over exactly.
     */
    private function parseScan(string $code, OrderModel $order): array
    {
        $code  = trim($code);
        $parts = explode('|', $code);
        $codeOrderNo = trim($parts[0] ?? '');
        $idx = null; $totalFromCode = null;

        if (isset($parts[1]) && strpos($parts[1], '/') !== false) {
            $pp  = explode('/', $parts[1]);
            $idx = is_numeric(trim($pp[0] ?? '')) ? (int) trim($pp[0]) : null;
            $totalFromCode = is_numeric(trim($pp[1] ?? '')) ? (int) trim($pp[1]) : null;
        }

        if ($codeOrderNo === '' || $codeOrderNo !== (string) $order->order_number) {
            return [
                'ok' => false,
                'code' => 'wrong_order',
                'scanned_order' => $codeOrderNo,
                'message' => $codeOrderNo !== ''
                    ? 'This packet belongs to order ' . $codeOrderNo
                    : 'QR not recognised',
            ];
        }

        $target = (int) ($order->expected_packets ?: $totalFromCode ?: 1);
        if ($target < 1) $target = 1;

        return ['ok' => true, 'idx' => $idx ?: 1, 'target' => $target, 'already' => false];
    }

    /** Add a packet index to a JSON array column, unique + sorted. */
    private function mergePacket($existing, int $idx): array
    {
        $scanned = [];
        if (!empty($existing)) {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $scanned = array_values(array_unique(array_map('intval', $decoded)));
            }
        }
        if (!in_array($idx, $scanned, true)) $scanned[] = $idx;
        sort($scanned);
        return $scanned;
    }

    private function emptyTotals(): array
    {
        return ['mine_total' => 0, 'mine_on_van' => 0, 'mine_dispatched' => 0,
                'carried_total' => 0, 'carried_handed' => 0, 'riders_waiting' => 0, 'to_load' => 0];
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
