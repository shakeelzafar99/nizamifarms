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

    /**
     * ⭐ HOW OLD A TAG MAY BE BEFORE IT IS CALLED STALE (Aug-2026).
     *
     * Same 20h window every other live van pointer already uses. A tag set the
     * evening before a morning run is normal; one still sitting a day later is
     * an order nobody loaded and nobody cleared.
     *
     * ⚠⚠ THIS FLAGS, IT NEVER HIDES. A stale tag stays in `to_load` on purpose:
     *    hiding it would be the one genuinely dangerous outcome here — an order
     *    silently dropped off the loading list is an order that gets left behind
     *    with nobody told. The only thing staleness suppresses is the store's
     *    van TAB auto-pinning itself open forever (see VanController::drivers).
     */
    public const STALE_TAG_HOURS = 20;

    /**
     * ⭐⭐ HOW OLD A GPS FIX MAY BE BEFORE WE STOP TRUSTING IT (Aug-2026).
     *
     * Defined ONCE, here, and echoed to every client as a STATE STRING — the
     * apps and the web card never re-derive freshness from a timestamp, so the
     * rider's card, the store board and the live map can never disagree about
     * whether the van's dot is trustworthy.
     *
     * ⚠⚠ A STALE FIX MUST NEVER DRIVE A PROMISE. This system has been bitten
     *    twice by confident-wrong positions — the 2,736 km "Riyadh" ETA and the
     *    position-blind ETA cache that served a 3,328-minute answer for a 1.6 km
     *    trip. So `stale` suppresses the ETA entirely and greys the marker at
     *    its LAST KNOWN spot: showing where he was ten minutes ago is honest,
     *    animating it as if it were live is not.
     */
    public const GPS_LIVE_MINUTES  = 2;    // ≤ this  → live
    public const GPS_AGING_MINUTES = 10;   // ≤ this  → aging (ETA marked "est")
                                           // >  this → stale (no ETA at all)

    /** live | aging | stale, from a `lastFix()` payload (null fix = stale). */
    public static function gpsState(?array $fix): string
    {
        if (!$fix || !isset($fix['age_minutes'])) return 'stale';
        $age = (int) $fix['age_minutes'];
        if ($age <= self::GPS_LIVE_MINUTES)  return 'live';
        if ($age <= self::GPS_AGING_MINUTES) return 'aging';
        return 'stale';
    }

    /** "GPS live" · "GPS 4 min ago" · "GPS 3h ago" — the same words everywhere. */
    public static function gpsLabel(?array $fix): string
    {
        $state = self::gpsState($fix);
        if ($state === 'live') return 'GPS live';
        if (!$fix || !isset($fix['age_minutes'])) return 'No GPS';
        $age = (int) $fix['age_minutes'];
        // Hours read as hours — "GPS 154 min ago" makes the reader do the
        // arithmetic the label exists to save. Only the store panel's
        // last-known fallback ever produces ages this old.
        if ($age >= 120) return 'GPS ' . (int) round($age / 60) . 'h ago';
        return 'GPS ' . $age . ' min ago';
    }

    /** Metres between two points. Plain haversine — no API call, no cache. */
    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** "1.2 km" / "450 m" — matches LocationService::formatDistance's shape. */
    public static function distanceDisplay(float $metres): string
    {
        return $metres >= 1000
            ? round($metres / 1000, 1) . ' km'
            : ((int) round($metres)) . ' m';
    }

    // =================================================================
    // AVAILABILITY
    // =================================================================

    /** Has batch 14 been run? Cached per process. */
    /** Sep-2026 column (`handover_help_note`) — schema-guarded, memoised. */
    public static function hasHelpNoteColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Schema::hasColumn('t_crm_prod_order', 'handover_help_note');
            } catch (\Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * 🆘 The rider cannot scan the label at the van (Sep-2026). Records his ask on
     *    the order so the store Van tab and the web van card show it, and pushes
     *    the dispatch-alert group. He is NOT blocked by this — his order page keeps
     *    the scanner; this is the door to the manager's no-scan record.
     */
    public function handoverHelp(OrderModel $order, int $riderId, string $reason): array
    {
        if (!$this->available()) return $this->fail('Van features are not set up yet (SQL batch 14).');
        if ((int) $order->assigned_rider_user_id !== $riderId) {
            return $this->fail('That order is not assigned to you.');
        }
        $c = self::custodyState($order);
        if (!$c['needs_handover']) {
            return $this->fail($c['handed_over']
                ? 'Ye order already collect ho chuka hai.'
                : 'Ye order abhi van par nahi hai.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) return $this->fail('Wajah likhein (kam az kam 3 huroof).');

        try {
            if (self::hasHelpNoteColumn()) {
                $order->handover_help_note = mb_substr(now()->format('H:i') . ' — ' . $reason, 0, 190);
                $order->save();
            }
            $riderName = DB::table('t_sys_user')->where('id', $riderId)->value('fullname') ?: ('Rider #' . $riderId);
            try {
                app(\App\Services\FirebaseService::class)->notifyVanHandoverHelp(
                    (int) $order->id, (string) $order->order_number, $riderId, $riderName, $reason);
            } catch (\Throwable $e) {
                Log::warning('Van handover help push failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            }
            Log::warning('Van handover help requested', [
                'order' => $order->id, 'number' => $order->order_number,
                'rider' => $riderId, 'van' => $order->van_user_id, 'reason' => $reason,
            ]);
            return ['ok' => true, 'message' => 'Store ko bata diya gaya hai — woh bina scan handover record karenge.'];
        } catch (\Throwable $e) {
            Log::error('VanService::handoverHelp failed', ['order' => $order->id, 'error' => $e->getMessage()]);
            return $this->fail('Could not send that request.');
        }
    }

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
    /**
     * Is this user driving a van (today, or on a given date)?
     *
     * ⚠⚠ ASKS `todaysDrivers()`, NOT THE RESOLVER DIRECTLY (fixed Aug-5, prod).
     *   On a handover day the resolver deliberately answers "yes" for BOTH the
     *   outgoing and the incoming rider — the outgoing one genuinely drove it that
     *   morning, which is the right answer for meters and fuel. It is the WRONG
     *   answer for "who is driving the van right now": it renamed the old driver's
     *   Orders tab to Van all day and left him holding the load-scan door for a van
     *   he had already handed over. One van has one driver at a time, and
     *   `todaysDrivers()` is the single place that decides which.
     */
    public function isVanDriver(int $userId, ?string $date = null): bool
    {
        try {
            foreach ($this->todaysDrivers($date) as $d) {
                if ((int) $d['user_id'] === $userId) return true;
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * ⭐ THE CARGO GOES WITH THE VAN (owner ruling, Aug-5 — from a prod handover).
     *
     * When a van changes hands mid-day, the boxes already scanned aboard are
     * physically in that vehicle. Until now they kept pointing at the OLD driver:
     * they vanished from the new driver's manifest, the riders waiting at the meet
     * point had nobody who could hand them over, and the only way out was the
     * `unload` path — which has no button on either surface.
     *
     * MOVES: orders still ABOARD (on_van, scanned aboard, not yet handed over).
     * LEAVES: anything already collected (handover_at set) or already out for
     *         delivery — those have left the van and belong to history.
     *
     * ⭐ The driver's OWN stops need no special case. Once the van is Farooq's, a
     *    box for Mashood is simply cargo Farooq carries for him — the existing
     *    "carrying for rider X" group and its handover scan take over, which is
     *    exactly the truth on the road.
     *
     * ⚠ Custody stamps (`van_loaded_at/by`, `dispatch_scanned_at`) are NOT cleared:
     *   they record who put the box on the van, which remains true. The handover
     *   scan re-stamps when it actually changes hands.
     *
     * Returns how many orders moved. Never throws — a failure here must not undo a
     * handover that has already been recorded.
     */
    public function moveCargo(int $vehicleId, int $fromUserId, int $toUserId, ?int $actorId = null): int
    {
        if (!$this->available() || $fromUserId === $toUserId) return 0;

        try {
            $ids = DB::table('t_crm_prod_order')
                ->where('van_user_id', $fromUserId)
                ->where('order_status', self::STATUS_ON_VAN)
                ->whereNotNull('van_loaded_at')
                ->whereNull('handover_at')
                ->pluck('id')->all();

            if (!$ids) {
                // Still end the old driver's trip — he is not driving it any more.
                $this->endTripOnHandover($fromUserId, $actorId);
                return 0;
            }

            // Attach to the new driver's OPEN trip if he has one. Otherwise null:
            // his next load or leg calls ensureTrip, which will adopt them. Never
            // leave them pointing at the old driver's trip — that trip is over.
            $newTrip = $this->openTrip($toUserId);

            DB::table('t_crm_prod_order')->whereIn('id', $ids)->update([
                'van_user_id'    => $toUserId,
                'van_vehicle_id' => $vehicleId,
                'van_trip_id'    => $newTrip->id ?? null,
                'updated_at'     => now(),
            ]);

            $this->endTripOnHandover($fromUserId, $actorId);

            Log::info('Van cargo moved with the vehicle', [
                'vehicle_id' => $vehicleId, 'from_user' => $fromUserId, 'to_user' => $toUserId,
                'orders' => $ids, 'by' => $actorId,
            ]);
            return count($ids);
        } catch (\Throwable $e) {
            Log::error('VanService::moveCargo failed', [
                'vehicle_id' => $vehicleId, 'from' => $fromUserId, 'to' => $toUserId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /** The outgoing driver's trip ends when the van does — a trip is one driver's run. */
    private function endTripOnHandover(int $userId, ?int $actorId = null): void
    {
        try {
            $trip = $this->openTrip($userId);
            if (!$trip) return;
            DB::table(self::T_TRIP)->where('id', $trip->id)->update([
                'ended_at'   => now(),
                'updated_at' => now(),
            ]);
            Log::info('Van trip closed by handover', ['trip' => $trip->id, 'user' => $userId, 'by' => $actorId]);
        } catch (\Throwable $e) {
            Log::warning('endTripOnHandover failed', ['user' => $userId, 'error' => $e->getMessage()]);
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

            // Whoever holds one on this date (assignment windows are date-scoped).
            //
            // ⚠⚠ ONE VAN, ONE DRIVER (fixed Aug-5 from a prod report: the board
            //   showed Mashood AND Farooq the day the van changed hands).
            //   A row released ON this date still COVERS this date — that is not a
            //   bug in the data (the registry had exactly one open row) and it is
            //   the right answer for fuel and meters, because the outgoing rider
            //   really did drive it that morning. But this method answers a
            //   different question: who is driving that van NOW. So rows are ranked
            //   per VEHICLE and only the winner is returned:
            //     open row (nobody has taken it back) → latest assigned_on → latest id.
            $rows = DB::table(VehicleService::T_ASSIGN)
                ->whereIn('vehicle_id', $vanIds)
                ->whereDate('assigned_on', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('released_on')->orWhereDate('released_on', '>=', $date);
                })
                ->orderBy('vehicle_id')
                ->get(['id', 'user_id', 'vehicle_id', 'assigned_on', 'released_on']);

            $best = [];
            foreach ($rows as $r) {
                $vid  = (int) $r->vehicle_id;
                $rank = [
                    $r->released_on === null ? 1 : 0,          // still held beats handed back
                    substr((string) $r->assigned_on, 0, 10),   // then the later handover
                    (int) $r->id,
                ];
                if (!isset($best[$vid]) || $rank > $best[$vid]['rank']) {
                    $best[$vid] = ['rank' => $rank, 'user_id' => (int) $r->user_id];
                }
            }

            $out = [];
            foreach ($best as $vid => $b) {
                $uid = $b['user_id'];
                // A manager's day-override still wins: re-ask the resolver, which is
                // the one place that applies it, so the two can never drift. If the
                // override moved him off the van today, he is not driving it today.
                if ($this->vanVehicleIdFor($uid, $date) !== $vid) continue;
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
                             bool $manual = false, bool $confirmPullback = false): array
    {
        if (!$this->available()) {
            return $this->fail('Van features are not set up on this server yet (SQL batch 14).');
        }

        // ⭐⭐ LOADING ALWAYS WINS (owner ruling, Aug-31). Scanning a box onto the
        //    van is a statement of physical fact; a delivery time is only a plan.
        //    So a packet arriving AFTER the driver dispatched must not be refused
        //    — "this one is out for delivery" was a dead end that left the store
        //    with nowhere to go but the `on_hold` two-hop, and the packet unrecorded.
        //
        //    Instead the order comes BACK to the van, its time is cleared
        //    (changeStatus does that for `on_van`), and the driver re-dispatches
        //    when loading is finished. Nothing already scanned is lost — packets
        //    accumulate — so the store never has to re-scan the whole order.
        //
        // ⚠ Asked, never silent: cancelling a delivery time the customer may
        //   already have been told is exactly the kind of thing that must be a
        //   deliberate yes. `$confirmPullback` is the yes.
        $pullingBack = false;
        if ($order->order_status === self::STATUS_OFD) {
            if ((int) ($order->van_user_id ?? 0) !== $vanUserId || empty($order->van_loaded_at)) {
                return $this->fail('That order is already out for delivery and is not on this van.');
            }
            // ⚠⚠ DRIVER'S OWN ONLY. Another rider's box also carries this van's
            //   stamps after a handover — but ITS dispatch means the rider
            //   collected it and rode away. Pulling that back would flip a
            //   status about a box that is physically kilometres from the
            //   scanner, while its `handover_at` still swears it was collected.
            //   The store-still-loading story is only ever true of the boxes
            //   the DRIVER delivers himself.
            if ((int) ($order->assigned_rider_user_id ?? 0) !== $vanUserId) {
                return $this->fail('Order ' . $order->order_number
                    . ' was collected from the van and is out for delivery — it cannot be scanned back.');
            }
            // ⭐ A REPEAT READ OF A BOX ALREADY ABOARD IS NOISE, NOT A DECISION.
            //   The camera re-reads labels constantly; without this, sweeping the
            //   scanner across a stack that includes a dispatched order popped
            //   the "cancel its delivery time?" question about a packet that is
            //   already recorded. Only a genuinely NEW packet is worth that
            //   question. A MANUAL tap is deliberate, so it still asks.
            $probe = $this->parseScan($scanCode, $order);
            if (!$probe['ok']) return $probe;
            if (!$manual && $probe['already']) {
                return $this->fail('Packet ' . $probe['idx'] . ' of ' . $order->order_number
                    . ' is already aboard — the order is out for delivery. Nothing changed.');
            }
            if (!$confirmPullback) {
                return [
                    'ok'            => false,
                    'needs_confirm' => 'pullback',
                    'order_number'  => $order->order_number,
                    'message'       => 'Order ' . $order->order_number . ' has already been sent out '
                        . 'with a delivery time. Scanning another packet puts it back on the van and '
                        . 'cancels that time — the driver can send it out again when loading is '
                        . 'finished. Nothing already scanned is lost. Continue?',
                ];
            }
            $pullingBack = true;
        } elseif (!in_array($order->order_status, ['processing', self::STATUS_ON_VAN], true)) {
            return $this->fail('Only orders being prepared can be loaded on the van. This one is '
                . str_replace('_', ' ', (string) $order->order_status) . '.');
        }
        if (!$order->assigned_rider_user_id) {
            return $this->fail('Assign this order to a rider before loading it on the van.');
        }

        $parsed = $this->parseScan($scanCode, $order);
        if (!$parsed['ok']) return $parsed;

        // ⭐ A HAND-ENTERED PACKET FILLS THE FIRST GAP. The app can only offer
        //    "the next one" derived from a COUNT — it never learns WHICH indices
        //    are aboard — so if packet 2 was scanned by camera and packet 1 is
        //    the one whose label is ruined, every manual tap re-sent 2 and the
        //    order could never be completed by hand at all. "No label" means one
        //    more packet is physically here, so record the lowest still missing.
        if ($manual) {
            $have = $this->decodePackets($order->van_loaded_packets);
            for ($i = 1; $i <= $parsed['target']; $i++) {
                if (!in_array($i, $have, true)) {
                    $parsed['idx'] = $i;
                    break;
                }
            }
            $parsed['already'] = in_array($parsed['idx'], $have, true);
        }

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

            // ⭐ A PULL-BACK RETURNS IT AT ONCE, complete or not — it is out for
            //    delivery with a packet that is not aboard yet, which is the one
            //    state that must never persist. The ETA is cleared by the
            //    transition itself (see OrderModel::changeStatus).
            if ($pullingBack) {
                $order->changeStatus(
                    self::STATUS_ON_VAN,
                    'Back on the van — another packet was scanned aboard after dispatch',
                    $actorId
                );
                Log::info('Van load scan pulled an order back from out-for-delivery', [
                    'order' => $order->id, 'number' => $order->order_number,
                    'van'   => $vanUserId, 'by' => $actorId,
                    'packets' => count($scanned) . '/' . $target,
                ]);
            }

            // The status flip is the LAST thing, and only once every packet is on
            // board — a half-loaded order must never look like it left the store.
            if (!$pullingBack && $complete && $order->order_status !== self::STATUS_ON_VAN) {
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

            // ⭐⭐ SAY WHEN A BOX ACTUALLY WENT ABOARD (Aug-31). A load scan on an
            //    order the store had already TAGGED "On Van" changes no status, so
            //    it wrote nothing at all: on 31 Aug the entire day's loading was
            //    invisible in the log, and "was it aboard when he pressed?" could
            //    only be answered by a hand-run SQL query against `van_loaded_at`.
            //    This is that answer, recorded as it happens.
            if ($complete && !$parsed['already']) {
                Log::info('Van load scan completed — order is aboard', [
                    'order'   => $order->id,
                    'number'  => $order->order_number,
                    'van'     => $vanUserId,
                    'by'      => $actorId,
                    'packets' => $target,
                    'manual'  => $manual,
                    'driver_own' => (int) $order->assigned_rider_user_id === $vanUserId,
                ]);
            }

            return [
                'ok' => true,
                'already_scanned' => $parsed['already'],
                'packets_unknown' => $parsed['packets_unknown'],
                'scanned_count'   => count($scanned),
                'target'          => $target,
                'complete'        => $complete,
                'pulled_back'     => $pullingBack,
                // ⭐ A repeat read says so. It used to report every re-read of the
                //    same label as a fresh success, which is what made waving the
                //    scanner look like it was accepting boxes it had never seen.
                'message'         => $parsed['already']
                    ? 'Packet ' . $parsed['idx'] . ' was already aboard (' . count($scanned) . ' of ' . $target . ')'
                    : ($complete
                        ? 'All packets on board — order is on the van'
                        : 'Packet ' . $parsed['idx'] . ' loaded (' . count($scanned) . ' of ' . $target . ')'),
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
            if (self::hasHelpNoteColumn()) $order->handover_help_note = null;
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
                                 ?float $lat = null, ?float $lng = null,
                                 string $source = 'meet_card', ?bool $nearVan = null): array
    {
        if (!$this->available()) return $this->fail('Van features are not set up yet (SQL batch 14).');

        if ($order->order_status !== self::STATUS_ON_VAN) {
            return $this->fail($order->order_status === self::STATUS_OFD
                ? 'You already have this order — it is out for delivery.'
                : 'That order is not on the van.');
        }

        // ⭐ TAG = PLAN, SCAN = PROOF — so it must actually BE aboard. "On Van"
        //    on its own is only the staff saying it should go by van; the box is
        //    still in the building until a load scan stamps the carrier. Without
        //    this, collecting a merely-tagged order wrote a handover that never
        //    happened and sent it out for delivery with no van recorded at all.
        if (empty($order->van_loaded_at) || empty($order->van_user_id)) {
            return $this->fail('That order has not been loaded onto a van yet — it is still at the store.');
        }

        if ((int) $order->assigned_rider_user_id !== $scannerId) {
            $name = DB::table('t_sys_user')->where('id', $order->assigned_rider_user_id)->value('fullname');
            return $this->fail('This order is for ' . ($name ?: 'another rider')
                . ' — ask the store to reassign it before you take it.');
        }

        $parsed = $this->parseScan($scanCode, $order, 'handover');
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
                // ⭐ A DELIVERY SCAN OLDER THAN THE HANDOVER WAS NOT A DELIVERY
                //    SCAN (Sep-2026). The order page used to let a rider run the
                //    door scanner on a box still on the van; that stamped
                //    `delivery_scanned_at` at the meet-up and the column stopped
                //    meaning "scanned at the customer". Clearing it here keeps the
                //    column honest and lets the real door scan stamp it afresh.
                if (!empty($order->delivery_scanned_at)) {
                    $order->delivery_scanned_at      = null;
                    $order->delivery_scanned_by      = null;
                    if (Schema::hasColumn('t_crm_prod_order', 'delivery_scanned_packets')) {
                        $order->delivery_scanned_packets = null;
                    }
                }
                // The scan happened after all — retire any "label won't scan" ask.
                if (self::hasHelpNoteColumn() && !empty($order->handover_help_note)) {
                    $order->handover_help_note = null;
                }
            }
            $order->save();

            if ($complete) {
                // ⭐ A LATE SCAN IS ALLOWED, AND SAYS SO (Sep-2026). The rider who
                //    forgot to collect at the meet-up scans the box from his order
                //    page at the customer's door — that IS the way out we give
                //    him, never a block. But the note and a warning line make it
                //    visible: the store sees ⚠ on the row, Taimur can grep it.
                $late = $source === 'order_page' && $nearVan === false;
                $note = $late ? 'Handed over from the van (late scan at delivery)' : 'Handed over from the van';
                if ($source === 'order_page') {
                    try {
                        Log::log($late ? 'warning' : 'info', $late ? 'Late van handover scan' : 'Van handover scan from order page', [
                            'order' => $order->id, 'number' => $order->order_number,
                            'rider' => $scannerId, 'van' => $order->van_user_id, 'near_van' => $nearVan,
                        ]);
                    } catch (\Throwable $e) {
                        // a log line is never worth a failed handover
                    }
                }
                // → OFD, still undispatched. `van_user_id` is KEPT: it is how the
                //   reports answer "which orders went out on the van".
                $order->changeStatus(self::STATUS_OFD, $note, $scannerId);

                // The last box of the last rider ends the meet-up by itself —
                // the driver never needed a "Done" press for a finished handover.
                $this->completeStopIfHandoverDone((int) $order->van_user_id);
            }

            return [
                'ok' => true,
                'already_scanned' => $parsed['already'],
                'packets_unknown' => $parsed['packets_unknown'],
                'scanned_count'   => count($scanned),
                'target'          => $target,
                'complete'        => $complete,
                'message'         => $parsed['already']
                    ? 'Packet ' . $parsed['idx'] . ' was already collected (' . count($scanned) . ' of ' . $target . ')'
                    : ($complete
                        ? 'Order collected — press Dispatch when you set off'
                        : 'Packet ' . $parsed['idx'] . ' collected (' . count($scanned) . ' of ' . $target . ')'),
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
        // Same rule as the scan: you cannot hand over what was never loaded.
        if (empty($order->van_loaded_at) || empty($order->van_user_id)) {
            return $this->fail('That order has not been loaded onto a van yet — it is still at the store.');
        }
        if (!$order->assigned_rider_user_id) return $this->fail('That order has no assigned rider.');

        try {
            $order->handover_at = now();
            $order->handover_scanned_by = $actorId;
            if (self::hasHelpNoteColumn()) $order->handover_help_note = null;
            $order->save();
            $order->changeStatus(self::STATUS_OFD,
                'Handed over without scan: ' . mb_substr($reason, 0, 180), $actorId);

            // Same rule as the scan: the last collected box ends the meet-up.
            $this->completeStopIfHandoverDone((int) $order->van_user_id);

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
    /**
     * ⭐⭐ THE DRIVER'S OWN STOPS THAT ARE REALLY ABOARD, STRAIGHT FROM THE DB.
     *
     * The truth the phone cannot be trusted for. On 31 Aug all five of Rajab's
     * boxes were stamped aboard by 12:45:29 and he dispatched at 12:49:33 — four
     * minutes later — yet only the FIRST THREE went. His picker had been built
     * from a manifest fetched in the 96-second window when exactly three were
     * loaded, and his phone had been logging "failed to connect" since 12:37, so
     * no later poll ever corrected it. The refresh we added is deliberately
     * silent on failure (a van lives in patchy coverage; one bad request must not
     * blank his screen) — which is exactly what let a stale list look confident.
     *
     * So the wave is checked against THIS, at the last gate, by the one party
     * that stamped `van_loaded_at` in the first place. No client state involved,
     * so it holds however old the phone's picture is.
     *
     * Priority-ordered, because that is the plan the store (or his own reorder —
     * both write `delivery_priority`) already agreed on.
     */
    public function loadedOwnStops(int $vanUserId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.van_user_id', $vanUserId)
                // HIS OWN only. Another rider's cargo leaves by the handover
                // scan and is never his to dispatch.
                ->where('o.assigned_rider_user_id', $vanUserId)
                ->where('o.order_status', self::STATUS_ON_VAN)
                ->whereNotNull('o.van_loaded_at')
                // Same 20h bound every other live van read uses — a box stranded
                // from a previous run must not join today's wave.
                ->where('o.van_loaded_at', '>=', now()->subHours(self::STALE_TAG_HOURS))
                ->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')
                ->get([
                    'o.id', 'o.order_number', 'o.delivery_priority', 'o.address_city',
                    DB::raw('CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")) as customer_name'),
                ])
                ->map(fn ($r) => [
                    'id'           => (int) $r->id,
                    'order_number' => $r->order_number,
                    'customer'     => trim((string) $r->customer_name) ?: null,
                    'area'         => $r->address_city ?: null,
                    'priority'     => $r->delivery_priority !== null ? (int) $r->delivery_priority : null,
                ])
                ->all();
        } catch (\Throwable $e) {
            // ⚠ FAIL OPEN. This powers a confirmation, not a permission: if the
            //   read breaks, the driver's own pick must still go out.
            Log::error('VanService::loadedOwnStops failed', ['user' => $vanUserId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * When a refused status change COULD be done properly instead, say by whom.
     *
     * ⭐ Farooq read the refusal on 31 Aug at 12:52:00 and did the `on_hold`
     *    two-hop six seconds later — not because he ignored it, but because the
     *    message named the DRIVER's panel and offered him nothing he could press.
     *    The web button existed by then and went unused all day: the store works
     *    in mobile store mode, not on the web panel. So the refusal now carries
     *    the release itself.
     *
     * Returns null unless this is a loaded driver's-own stop being sent out —
     * the one case the store can legitimately release.
     */
    public static function releaseHint($order, string $targetStatus): ?array
    {
        try {
            if ($targetStatus !== self::STATUS_OFD) return null;
            if ((string) ($order->order_status ?? '') !== self::STATUS_ON_VAN) return null;
            if (empty($order->van_loaded_at) || empty($order->van_user_id)) return null;
            if ((int) ($order->assigned_rider_user_id ?? 0) !== (int) $order->van_user_id) return null;

            $name = DB::table('t_sys_user')->where('id', $order->van_user_id)->value('fullname');
            return [
                'driver_id'   => (int) $order->van_user_id,
                'driver_name' => $name ?: 'the van driver',
                'order_id'    => (int) $order->id,
            ];
        } catch (\Throwable $e) {
            return null;   // a hint is a nicety, never a dependency
        }
    }

    public function manifest(int $vanUserId): array
    {
        if (!$this->available()) {
            return ['available' => false, 'mine' => [], 'carrying' => [], 'to_load' => [], 'totals' => $this->emptyTotals()];
        }

        try {
            // The rider's "label won't scan" note rides along when the column
            // exists (Sep-2026 SQL) — `help_note` is null on a server without it.
            $cols = [
                'o.id', 'o.order_number', 'o.order_status', 'o.assigned_rider_user_id',
                'o.delivery_priority', 'o.expected_packets', 'o.handover_at',
                'o.eta_calculated_at', 'o.address_line1', 'o.address_city',
                'o.van_loaded_packets', 'o.van_loaded_at',
                'u.fullname as rider_name',
                DB::raw('CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")) as customer_name'),
            ];
            if (self::hasHelpNoteColumn()) $cols[] = 'o.handover_help_note';

            $rows = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->where('o.van_user_id', $vanUserId)
                ->whereIn('o.order_status', [self::STATUS_ON_VAN, self::STATUS_OFD])
                ->whereNotNull('o.van_loaded_at')
                ->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')
                ->get($cols);

            $mine = [];
            $byRider = [];
            // The first box aboard = when loading actually began. The trip row's
            // own created_at is not it: `ensureTrip` can be minted by setting a
            // meet-up point before anything is scanned.
            $firstLoadedAt = null;
            foreach ($rows as $r) {
                if ($r->van_loaded_at !== null
                    && ($firstLoadedAt === null || (string) $r->van_loaded_at < $firstLoadedAt)) {
                    $firstLoadedAt = (string) $r->van_loaded_at;
                }
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
                    // ⭐ THE TIMES, NOT JUST THE FLAGS (Aug-2026). The boards could
                    //    say a box was collected but never WHEN, so nobody could
                    //    tell a handover running to plan from one an hour late.
                    //    Every figure here was already recorded by the scans — this
                    //    only stops throwing it away on the way to the screen.
                    'loaded_at'     => $r->van_loaded_at,
                    'handover_at'   => $r->handover_at,
                    'dispatched_at' => $r->eta_calculated_at,
                    // ⚠ "Label scan nahi ho raha" — the rider asked the store for a
                    //   no-scan handover (Sep-2026). Cleared by the scan, the
                    //   override, or the unload. Null = nothing asked.
                    'help_note'     => $r->handover_at === null ? ($r->handover_help_note ?? null) : null,
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
                            // When this rider's collection STARTED and FINISHED —
                            // the two points the trip timeline plots for him.
                            'first_handover_at' => null,
                            'last_handover_at'  => null,
                        ];
                    }
                    $byRider[$rid]['orders'][] = $item;
                    $byRider[$rid]['packets']  += $item['packets'];
                    if ($item['handed_over']) {
                        $byRider[$rid]['handed']++;
                        $h = (string) $item['handover_at'];
                        if ($byRider[$rid]['first_handover_at'] === null
                            || $h < $byRider[$rid]['first_handover_at']) {
                            $byRider[$rid]['first_handover_at'] = $h;
                        }
                        if ($byRider[$rid]['last_handover_at'] === null
                            || $h > $byRider[$rid]['last_handover_at']) {
                            $byRider[$rid]['last_handover_at'] = $h;
                        }
                    }
                }
            }

            // ⭐ TAGGED BUT NOT YET ABOARD. Staff mark "On Van" as the plan; the
            //    scan is what puts a box on THIS van. Until then the order sits
            //    here so the driver and the store both see what still has to be
            //    scanned — any rider's orders, because the van carries for
            //    everyone. (`van_user_id IS NULL` keeps a box already scanned
            //    onto another van off this list.)
            // ⭐ WHEN WAS IT TAGGED? The order row itself cannot say — `updated_at`
            //    moves for any edit — but the status history holds the exact
            //    moment: its CURRENT row is the `on_van` one for a tagged order.
            //    LEFT JOIN so a missing history row (very old data) simply reads
            //    "unknown age" and is never mistaken for stale.
            $staleBefore = now()->subHours(self::STALE_TAG_HOURS);

            $toLoad = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->leftJoin('t_crm_order_status_history as h', function ($j) {
                    $j->on('h.order_id', '=', 'o.id')
                      ->where('h.is_current', '=', 1)
                      ->where('h.status_code', '=', self::STATUS_ON_VAN);
                })
                ->where('o.order_status', self::STATUS_ON_VAN)
                ->whereNull('o.van_loaded_at')
                ->whereNull('o.van_user_id')
                ->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')
                ->get([
                    'o.id', 'o.order_number', 'o.assigned_rider_user_id',
                    'o.expected_packets', 'o.van_loaded_packets', 'o.address_city',
                    'u.fullname as rider_name', 'h.changed_at as tagged_at',
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
                    'tagged_at'        => $r->tagged_at,
                    // Flagged, never hidden — see STALE_TAG_HOURS.
                    'is_stale'         => $r->tagged_at !== null
                        && (string) $r->tagged_at < $staleBefore->format('Y-m-d H:i:s'),
                ])
                // ⚠ SAFETY NET, NOT A FIX FOR A KNOWN BUG. `changeStatus` clears
                //   `is_current` before inserting the new row, so exactly one
                //   history row should ever match the join — verified true for
                //   all 16,003 orders on the replica. But this list drives a
                //   COUNT the driver is warned with and the store acts on, and a
                //   duplicated row would silently inflate it. One cheap dedupe
                //   beats trusting an invariant maintained somewhere else.
                ->unique('id')
                ->values()->all();

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
                    'to_load_stale'    => count(array_filter($toLoad, fn ($o) => $o['is_stale'])),
                    'first_loaded_at'  => $firstLoadedAt,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('VanService::manifest failed', ['user' => $vanUserId, 'error' => $e->getMessage()]);
            return ['available' => true, 'mine' => [], 'carrying' => [], 'to_load' => [], 'totals' => $this->emptyTotals()];
        }
    }

    /**
     * Is this user physically carrying anything on a van right now?
     *
     * Used alongside `isVanDriver` wherever a van ACTION is authorised: the
     * registry answers "is he rostered on the van today", this answers "does he
     * have boxes aboard" — a mid-day stand-in must be able to act on cargo he is
     * actually holding, and nobody else may pretend to be running a van.
     */
    public function isCarrying(int $userId): bool
    {
        if (!$this->available()) return false;
        try {
            return DB::table('t_crm_prod_order')
                ->where('van_user_id', $userId)
                ->where('order_status', self::STATUS_ON_VAN)
                ->whereNotNull('van_loaded_at')
                // ⚠ Time-bounded: this AUTHORISES van actions (setStop), and a
                //   stranded box — unload still has no button — must not keep an
                //   old driver holding van powers for days. Same 20h window the
                //   store panel uses for this column.
                ->where('van_loaded_at', '>=', now()->subHours(20))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * ⭐ A MEET-UP'S JOB IS THE HANDOVER — WHEN NOBODY IS LEFT TO COLLECT, THE
     *    WAIT IS OVER (owner, Aug-6: "if there's no cargo why show waiting?").
     *
     * Closes the current stop when the driver has REACHED it and no rider has
     * loaded cargo left to take. Two ways to get there, both real:
     *   • the last rider scans his last box — the natural end of the meet-up;
     *   • the stop never had cargo behind it at all (a test run, or everything
     *     unloaded) — the zombie that sat on the prod board reading
     *     "waiting (307 min)" about a van with nothing aboard.
     *
     * An UN-reached stop is never touched (it is the plan), and a stop with
     * cargo still aboard keeps its honest waiting timer. Returns true when it
     * closed something. Never throws — this runs on read paths too.
     */
    public function completeStopIfHandoverDone(int $vanUserId, ?VanStopService $stops = null): bool
    {
        if (!$this->available()) return false;
        try {
            $stops = $stops ?: new VanStopService();
            $cur = $stops->currentStop($vanUserId);
            if (!$cur || !$cur->reached_at) return false;
            if (!empty($this->ridersAwaiting($vanUserId))) return false;
            $stops->completeStop($vanUserId);
            Log::info('Van stop auto-closed — nothing left to hand over', [
                'driver' => $vanUserId, 'stop' => $cur->id,
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * ⭐ WHO is still owed boxes, WITH NAMES AND COUNTS (Aug-2026).
     *
     * `ridersAwaiting()` answers "who" as bare ids — enough to push to, useless
     * for a sentence. Closing a meet-up is now an exceptional act that has to
     * NAME the person being stranded ("Kanan ka saman abhi van mein hai"), and
     * the manager banner has to say the same thing, so both read this.
     *
     * @return array<int, array{user_id:int,name:string,orders:int}>
     */
    public function ridersAwaitingDetail(int $vanUserId): array
    {
        if (!$this->available()) return [];
        try {
            return DB::table('t_crm_prod_order as o')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->where('o.van_user_id', $vanUserId)
                ->where('o.order_status', self::STATUS_ON_VAN)
                ->whereColumn('o.assigned_rider_user_id', '!=', 'o.van_user_id')
                ->whereNotNull('o.assigned_rider_user_id')
                // Same freshness bound every live van pointer carries: a box
                // stranded for days must not keep naming a rider forever.
                ->whereNotNull('o.van_loaded_at')
                ->where('o.van_loaded_at', '>=', now()->subHours(self::STALE_TAG_HOURS))
                ->groupBy('o.assigned_rider_user_id', 'u.fullname')
                ->select([
                    'o.assigned_rider_user_id as user_id',
                    'u.fullname as name',
                    DB::raw('COUNT(*) as orders'),
                ])
                ->get()
                ->map(fn ($r) => [
                    'user_id' => (int) $r->user_id,
                    'name'    => $r->name ?: ('Rider #' . (int) $r->user_id),
                    'orders'  => (int) $r->orders,
                ])->values()->all();
        } catch (\Throwable $e) {
            Log::warning('VanService::ridersAwaitingDetail failed', ['driver' => $vanUserId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /** "Kanan (3 boxes)" / "Kanan aur Waseem" — one short phrase for a prompt. */
    public static function describeAwaiting(array $detail): string
    {
        $bits = array_map(
            fn ($r) => $r['name'] . ' (' . $r['orders'] . ')',
            $detail
        );
        return implode(' · ', $bits);
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
    // DAY REVIEW — the trip, after the fact (D3)
    // =================================================================

    /**
     * Every van trip on a date, as a timeline the manager can read.
     *
     * Day Review already shows the PIECES of a van day — the driver's own
     * deliveries and each rider's post-handover run are ordinary orders — but
     * never the choreography between them: when it loaded, when it left, how
     * long it sat at the meet-up point, who kept it waiting, and how long a
     * rider took to start delivering after collecting. All of it is already
     * recorded; this only reads.
     *
     * Read-only, no schema, and every block is defensive: a van report must
     * never be the reason Day Review fails to open.
     */
    public function dayTrips(string $date): array
    {
        if (!$this->available()) return [];

        // A rider standing at the meet-up point for longer than this, or taking
        // longer than this to set off after collecting, is worth a look.
        $WAIT_FLAG_MIN     = 20;
        $DISPATCH_FLAG_MIN = 15;

        try {
            $trips = DB::table(self::T_TRIP)->whereDate('trip_date', $date)->orderBy('id')->get();
            if ($trips->isEmpty()) return [];

            $mins = function ($from, $to) {
                if (!$from || !$to) return null;
                $a = strtotime((string) $from); $b = strtotime((string) $to);
                if (!$a || !$b) return null;
                return max(0, (int) round(($b - $a) / 60));
            };

            $out = [];
            foreach ($trips as $t) {
                $driverName = (string) (DB::table('t_sys_user')->where('id', $t->van_user_id)
                    ->value('fullname') ?: 'Driver');

                // Orders on this trip. Matched by trip id, or — ONLY for orders
                // carrying no trip id at all (cargo moved between drivers gets
                // NULL) — by "this driver loaded it that day".
                //
                // ⚠ The fallback must be restricted to NULL-trip orders. As a
                //   plain OR it made EVERY trip of the day claim ALL the
                //   driver's boxes, so a finish-and-restart day (exactly the
                //   Aug-5 handover shape) double-counted everything per card.
                $orders = DB::table('t_crm_prod_order as o')
                    ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                    ->whereNotNull('o.van_loaded_at')
                    ->where(function ($q) use ($t, $date) {
                        $q->where('o.van_trip_id', $t->id)
                          ->orWhere(function ($w) use ($t, $date) {
                              $w->whereNull('o.van_trip_id')
                                ->where('o.van_user_id', $t->van_user_id)
                                ->whereDate('o.van_loaded_at', $date);
                          });
                    })
                    ->get([
                        'o.id', 'o.order_number', 'o.assigned_rider_user_id',
                        'o.van_loaded_at', 'o.handover_at', 'o.eta_calculated_at',
                        'u.fullname as rider_name',
                    ]);

                $loadedAt = null;
                $own = 0; $carried = 0; $collected = 0;
                $byRider = [];
                foreach ($orders as $o) {
                    if ($o->van_loaded_at && (!$loadedAt || $o->van_loaded_at < $loadedAt)) {
                        $loadedAt = $o->van_loaded_at;
                    }
                    if ((int) $o->assigned_rider_user_id === (int) $t->van_user_id) {
                        $own++;
                        continue;
                    }
                    $carried++;
                    $rid = (int) $o->assigned_rider_user_id;
                    if (!isset($byRider[$rid])) {
                        $byRider[$rid] = ['user_id' => $rid, 'name' => $o->rider_name ?: ('Rider #' . $rid),
                                          'orders' => 0, 'collected' => 0,
                                          'first_collect_at' => null, 'first_dispatch_at' => null];
                    }
                    $byRider[$rid]['orders']++;
                    if ($o->handover_at) {
                        $collected++;
                        $byRider[$rid]['collected']++;
                        if (!$byRider[$rid]['first_collect_at'] || $o->handover_at < $byRider[$rid]['first_collect_at']) {
                            $byRider[$rid]['first_collect_at'] = $o->handover_at;
                        }
                    }
                    if ($o->eta_calculated_at) {
                        if (!$byRider[$rid]['first_dispatch_at'] || $o->eta_calculated_at < $byRider[$rid]['first_dispatch_at']) {
                            $byRider[$rid]['first_dispatch_at'] = $o->eta_calculated_at;
                        }
                    }
                }

                $stops = DB::table(self::T_HANDOVER)->where('trip_id', $t->id)->orderBy('id')
                    ->get(['label', 'is_adhoc', 'status', 'set_at', 'reached_at', 'completed_at']);
                $firstReached = null;
                foreach ($stops as $s) {
                    if ($s->reached_at && (!$firstReached || $s->reached_at < $firstReached)) {
                        $firstReached = $s->reached_at;
                    }
                }

                $flags = [];
                $riders = [];
                foreach ($byRider as $g) {
                    // How long the VAN waited for this rider, and how long he
                    // then took to actually set off.
                    $wait = $mins($firstReached, $g['first_collect_at']);
                    $lag  = $mins($g['first_collect_at'], $g['first_dispatch_at']);
                    $riders[] = $g + [
                        'wait_minutes'         => $wait,
                        'dispatch_lag_minutes' => $lag,
                        'complete'             => $g['collected'] >= $g['orders'],
                    ];
                    if ($wait !== null && $wait > $WAIT_FLAG_MIN) {
                        $flags[] = $g['name'] . ' kept the van waiting ' . $wait . ' min';
                    }
                    if ($lag !== null && $lag > $DISPATCH_FLAG_MIN) {
                        $flags[] = $g['name'] . ' took ' . $lag . ' min to dispatch after collecting';
                    }
                    if ($g['collected'] < $g['orders']) {
                        $flags[] = $g['name'] . ' did not collect '
                                 . ($g['orders'] - $g['collected']) . ' of ' . $g['orders'];
                    }
                }
                if ($carried > 0 && $stops->isEmpty()) {
                    $flags[] = 'Carried orders but no meet-up point was ever set';
                }
                if (!$t->ended_at && $date < today()->format('Y-m-d')) {
                    $flags[] = 'Trip was never closed';
                }

                usort($riders, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

                $out[] = [
                    'trip_id'      => (int) $t->id,
                    'driver_name'  => $driverName,
                    'loaded_at'    => $loadedAt,
                    'departed_at'  => $t->departed_at,
                    'ended_at'     => $t->ended_at,
                    'duration_minutes' => $mins($t->departed_at, $t->ended_at),
                    'totals'       => [
                        'own' => $own, 'carried' => $carried,
                        'collected' => $collected, 'uncollected' => max(0, $carried - $collected),
                        'riders' => count($riders),
                    ],
                    'stops'        => $stops->map(fn ($s) => [
                        'label'           => $s->label ?: 'Meet-up point',
                        'is_adhoc'        => (int) $s->is_adhoc === 1,
                        'status'          => (string) $s->status,
                        'set_at'          => $s->set_at,
                        'reached_at'      => $s->reached_at,
                        'completed_at'    => $s->completed_at,
                        'waiting_minutes' => $mins($s->reached_at, $s->completed_at),
                    ])->all(),
                    'riders'       => $riders,
                    'flags'        => $flags,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('VanService::dayTrips failed', ['date' => $date, 'error' => $e->getMessage()]);
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
    /**
     * ⭐⭐ ONE READING OF "WHOSE HANDS IS THIS BOX IN" (Sep-2026).
     *
     * The guard below, the rider's order page and the delivery-scan endpoints
     * all need the same four facts about an order. Until Sep-5 the order page
     * had NONE of them — it could not tell a box riding on somebody's van from
     * a box in the rider's hand, so it offered "Mark Delivered" (and the
     * delivery scanner) for an order still on the van. Arslan scanned two boxes
     * through that scanner at the meet-up on Sep-3, thinking it was the
     * handover. Derived here ONCE so the page can never disagree with the guard
     * about whether a handover is still owed.
     *
     * Pure: reads the model, touches nothing, never throws.
     *
     * @return array{on_van:bool, loaded:bool, handed_over:bool, drivers_own:bool,
     *               fresh:bool, needs_handover:bool}
     */
    public static function custodyState($order): array
    {
        try {
            $current    = (string) ($order->order_status ?? '');
            $loaded     = !empty($order->van_loaded_at) && !empty($order->van_user_id);
            $handedOver = !empty($order->handover_at);
            $driversOwn = $loaded
                && (int) ($order->assigned_rider_user_id ?? 0) === (int) $order->van_user_id;
            $fresh = $loaded && $order->van_loaded_at >= now()->subHours(self::STALE_TAG_HOURS);

            return [
                'on_van'         => $current === self::STATUS_ON_VAN,
                'loaded'         => $loaded,
                'handed_over'    => $handedOver,
                'drivers_own'    => $driversOwn,
                'fresh'          => $fresh,
                // The one question the order page asks: is a handover scan
                // still the rider's next step for this box?
                'needs_handover' => $current === self::STATUS_ON_VAN
                    && $loaded && !$handedOver && !$driversOwn && $fresh,
            ];
        } catch (\Throwable $e) {
            return ['on_van' => false, 'loaded' => false, 'handed_over' => false,
                    'drivers_own' => false, 'fresh' => false, 'needs_handover' => false];
        }
    }

    public static function manualChangeBlock($order, string $targetStatus): ?string
    {
        $refusal = self::manualChangeReason($order, $targetStatus);

        // ⭐ A REFUSAL LEAVES A TRACE (Aug-30). Every door above returns 422 with
        //    this text and logs NOTHING, so the only record of a blocked change
        //    was the sentence on somebody's screen. Reconstructing the 29-Aug van
        //    run therefore took inference from a workaround pattern — five orders
        //    laundered through `on_hold` — rather than a search. One line here
        //    makes the next one findable.
        //
        // ⚠ Wrapped, and swallowing its own errors: this guard's contract is that
        //   it can never turn a status change into a 500, and a logging call must
        //   not be the thing that breaks it.
        if ($refusal !== null) {
            try {
                Log::info('Van guard refused a manual status change', [
                    'order'   => $order->id ?? null,
                    'number'  => $order->order_number ?? null,
                    'from'    => $order->order_status ?? null,
                    'to'      => $targetStatus,
                    'by'      => auth()->check() ? auth()->id() : null,
                    'loaded'  => !empty($order->van_loaded_at),
                    'van'     => $order->van_user_id ?? null,
                    'reason'  => $refusal,
                ]);
            } catch (\Throwable $e) {
                // never let the log break the guard
            }
        }

        return $refusal;
    }

    /** The rule itself. See `manualChangeBlock` for what wraps it and why. */
    private static function manualChangeReason($order, string $targetStatus): ?string
    {
        try {
            // Putting something ON the van is always allowed — it is also the
            // REPAIR for a box that left the van without being collected.
            if ($targetStatus === self::STATUS_ON_VAN) return null;

            $current    = (string) ($order->order_status ?? '');
            // Same reading as the order page (see custodyState) — the guard and
            // the page must never disagree about whether a handover is owed.
            $custody    = self::custodyState($order);
            $loaded     = $custody['loaded'];
            $handedOver = $custody['handed_over'];

            // ⭐⭐ THE DRIVER'S OWN STOPS ARE NEVER "HANDED OVER" — he cannot
            //    collect from himself, so `handover_at` stays NULL on them for
            //    life and his load scan IS their custody proof. Without this
            //    exclusion the guard below would refuse to let him deliver his
            //    own boxes, which is most of what the van actually does.
            $isDriversOwn = $custody['drivers_own'];

            if ($current !== self::STATUS_ON_VAN) {
                // ⚠⚠ THE TWO-HOP BYPASS (found in prod, 21 Aug). The rules below
                //   only ever looked at the CURRENT status, so `on_van → on_hold`
                //   (allowed on purpose — plans change) followed by
                //   `on_hold → out_for_delivery` walked straight past them: by
                //   the second hop the order was no longer on_van, so nothing
                //   looked. It happened eight seconds apart and put two boxes on
                //   the road with no collection scan and the board still reading
                //   "handed over 0 of 2".
                //
                //   So custody is keyed on the VAN POINTERS, not the status: an
                //   order still stamped with a van, never handed over, may not be
                //   sent out for delivery by hand whatever status it sits in.
                //
                // ⭐ Deliberately ONLY `out_for_delivery`. Blocking `delivered`
                //   too would strand a rider who is already holding the box —
                //   the damage is done by then and refusing helps nobody.
                if ($targetStatus === self::STATUS_OFD
                    && $loaded && !$handedOver && !$isDriversOwn
                    && $order->van_loaded_at >= now()->subHours(self::STALE_TAG_HOURS)) {

                    $rider = null;
                    try {
                        $rider = DB::table('t_sys_user')
                            ->where('id', $order->assigned_rider_user_id)->value('fullname');
                    } catch (\Throwable $e) {
                        // a name is a nicety, never a dependency
                    }
                    return 'This order is still recorded as loaded on the van and was never '
                         . 'collected. Put it back to "On Van" so ' . ($rider ?: 'the assigned rider')
                         . ' can scan it at the meet-up, or set it to Processing to take it off '
                         . 'the van first.';
                }
                return null;
            }

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
            //
            // ⚠ Deliberately OR, not the AND used by the pointer check above:
            //   here we are asking "has ANY custody stamp landed on this box",
            //   and a half-stamped row must be treated as aboard. Named apart
            //   from `$loaded` so the two rules can never be confused.
            $hasAnyVanStamp = !empty($order->van_loaded_at) || !empty($order->van_user_id);
            if (!$hasAnyVanStamp) return null;

            if (in_array($targetStatus, ['cancelled', 'refunded', 'on_hold', 'on-hold'], true)) {
                return null;
            }

            // ⭐ TAKING IT OFF THE VAN IS JUST A STATUS CHANGE (owner ruling
            //    Aug-6). Sending a loaded order back into the building IS the
            //    unload, so it is allowed from every ordinary door — and
            //    `OrderModel::changeStatus` clears the van pointers on that exact
            //    transition, which is what used to make a bare status change
            //    unsafe (the order kept its `van_user_id` and reappeared on the
            //    van's manifest the next time it went out). The dedicated
            //    `unload` endpoint still exists and does the same thing.
            if (in_array($targetStatus, ['processing', 'new', 'pending'], true)) {
                return null;
            }

            // ⭐ CUSTODY IS ALREADY PROVEN — let it through. A collected box (or
            //   one a manager recorded a no-scan handover for) has nothing left
            //   to guard: `handover_at` IS the proof this rule exists to demand.
            //   Without this the guard kept refusing after the very scan it had
            //   been asking for, and the only way out was another van door.
            if ($handedOver) return null;

            $rider = null;
            try {
                $rider = DB::table('t_sys_user')->where('id', $order->assigned_rider_user_id)->value('fullname');
            } catch (\Throwable $e) {
                // name is a nicety, never a dependency
            }

            // ⭐ THE DRIVER'S OWN STOPS NEED DIFFERENT WORDS. He cannot hand a box
            //   over to himself, so telling him to "collect it with the handover
            //   scan" describes an action that does not exist. His sanctioned
            //   door is the wave picker in his own van panel, which times the
            //   stops properly instead of dropping them out with no ETA.
            if ($isDriversOwn) {
                return 'This is the van driver\'s own stop. He sends it out himself from the '
                     . 'van panel — "Where to next?" → "My deliveries" — so it gets a delivery '
                     . 'time. Changing it by hand here would put it on the road with no ETA.';
            }

            // ⭐ NAME ONLY DOORS THAT EXIST (Sep-2026). This used to promise "a
            //    manager records a no-scan handover from the van panel" — an
            //    endpoint nothing on mobile or web ever called. Now the three
            //    real exits: the rider's own scan (works anywhere, the order
            //    page offers it), the store's no-scan record (store Van tab /
            //    web van card), or taking the box off the van.
            $riderName = $rider ?: 'the assigned rider';
            if ($targetStatus === 'delivered') {
                return 'Ye order abhi van par hai — pehle van handover scan karein '
                     . '(order page par "Van handover scan karein"). Label scan nahi ho raha '
                     . 'to store se "Bina scan handover" record karwayein.';
            }
            return 'This order is on the van and ' . $riderName . ' has not collected it. '
                 . 'He scans it from his order page or the meet-up card; if the label cannot be '
                 . 'scanned, record a no-scan handover from the Van tab; or set it to Processing '
                 . 'to take it off the van.';
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
    private function parseScan(string $code, OrderModel $order, string $mode = 'load'): array
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
        $idx = $idx ?: 1;

        // ⚠ `already` USED TO BE HARD-CODED FALSE (fixed Aug-5 from a prod report:
        //   "he waved the scanner around and it just marked it loaded"). Every
        //   re-read of the same label flashed a fresh green success, so waving at
        //   one box looked exactly like scanning several. The older dispatch
        //   scanner has always computed this properly — the van one was the odd
        //   one out. The count was never wrong; the FEEDBACK was.
        $scanned = [];
        $existing = $mode === 'handover' ? $order->handover_scanned_packets : $order->van_loaded_packets;
        if (!empty($existing)) {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) $scanned = array_map('intval', $decoded);
        }

        return [
            'ok' => true, 'idx' => $idx, 'target' => $target,
            'already' => in_array($idx, $scanned, true),
            // ⚠ Neither the order nor the label says how many packets there are, so
            //   ONE beep completes it. That is the long-standing rule (the dispatch
            //   scan does the same), but the scanner must SAY so rather than let a
            //   single read silently stand for a whole trolley.
            'packets_unknown' => empty($order->expected_packets) && empty($totalFromCode),
        ];
    }

    /** The packet indices already recorded in a JSON array column. */
    private function decodePackets($existing): array
    {
        if (empty($existing)) return [];
        $decoded = json_decode($existing, true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /** Add a packet index to a JSON array column, unique + sorted. */
    private function mergePacket($existing, int $idx): array
    {
        $scanned = array_values(array_unique($this->decodePackets($existing)));
        if (!in_array($idx, $scanned, true)) $scanned[] = $idx;
        sort($scanned);
        return $scanned;
    }

    private function emptyTotals(): array
    {
        // ⚠ KEEP IN SHAPE WITH manifest()'s totals. Every surface reads these
        //   keys straight off the payload; a key that exists on one path and not
        //   the other is a client-side undefined on exactly the failure path
        //   where the UI most needs to stay calm.
        return ['mine_total' => 0, 'mine_on_van' => 0, 'mine_dispatched' => 0,
                'carried_total' => 0, 'carried_handed' => 0, 'riders_waiting' => 0, 'to_load' => 0,
                'to_load_stale' => 0, 'first_loaded_at' => null];
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
