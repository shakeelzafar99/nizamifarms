<?php

namespace App\Services\Riders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery-time PROMISE — the yardstick lateness is measured against.
 *
 * The problem this solves: `RiderController::calculateDeliveryEtas` overwrites
 * `t_crm_prod_order.estimated_delivery_at` on every dispatch, and every lateness
 * surface compares `delivered_at` against that CURRENT value. So a rider running
 * late could press Re-dispatch, get fresh (later) times, and never be flagged —
 * the original commitment survived nowhere queryable.
 *
 * The rule (owner, Jul-2026): **the yardstick only resets when management
 * resets it.**
 *  - The FIRST dispatch of the day is the promise.
 *  - A STORE-initiated dispatch — or a rider dispatch that FOLLOWS a
 *    store-initiated cancel — sets a NEW promise: management sanctioned the
 *    re-plan (they inserted an urgent order, or re-routed him), so holding him
 *    to the old times would be unfair.
 *  - A RIDER-initiated re-dispatch still refreshes the live customer-facing
 *    ETAs (we WANT honest times going out) but does NOT move the promise.
 * A rider cancelling his OWN dispatch is therefore not a reset either — that
 * loophole is why `event='cancel'` rows are logged with `is_rider_self`.
 *
 * Everything here is FAIL-SAFE: with no table (or no rows for an order) the
 * writers no-op and `promisesFor()` returns nothing, so callers fall back to
 * today's behaviour. That is what lets the PHP deploy before the SQL runs, and
 * what makes pre-log historical orders keep rendering sensibly forever.
 */
class EtaPromiseService
{
    private const TABLE = 't_ops_eta_log';

    /** Cached per request — Schema::hasTable hits the information_schema. */
    private static ?bool $tableOk = null;

    private static function available(): bool
    {
        if (self::$tableOk === null) {
            try {
                self::$tableOk = Schema::hasTable(self::TABLE);
            } catch (\Throwable $e) {
                self::$tableOk = false;
            }
        }
        return self::$tableOk;
    }

    // ---- writers ---------------------------------------------------------

    /**
     * Record one dispatch wave. Called from inside calculateDeliveryEtas after
     * the ETAs are written. Never throws — a logging failure must never cost a
     * rider his dispatch.
     *
     * @param array $stops list of ['order_id' => int, 'estimated_delivery_at' => string, 'position' => int]
     */
    public static function logDispatch(
        int $riderId,
        string $batchTs,
        array $stops,
        ?int $byUserId,
        bool $isMidRun,
        int $deliveredBefore,
        string $scope
    ): void {
        if (!self::available() || empty($stops)) {
            return;
        }
        try {
            $now = now()->format('Y-m-d H:i:s');
            $rows = [];
            foreach ($stops as $s) {
                $rows[] = [
                    'order_id'              => (int) $s['order_id'],
                    'rider_id'              => $riderId,
                    'event'                 => 'dispatch',
                    'batch_ts'              => $batchTs,
                    'estimated_delivery_at' => $s['estimated_delivery_at'],
                    'position'              => (int) $s['position'],
                    'calculated_by'         => $byUserId,
                    'is_rider_self'         => ($byUserId !== null && (int) $byUserId === $riderId) ? 1 : 0,
                    'is_mid_run'            => $isMidRun ? 1 : 0,
                    'delivered_before'      => $deliveredBefore,
                    'scope'                 => $scope,
                    'created_at'            => $now,
                ];
            }
            DB::table(self::TABLE)->insert($rows);
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: dispatch log failed (non-fatal)', [
                'rider_id' => $riderId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a cancel-dispatch. Only a STORE-initiated cancel opens the door to
     * a new promise, but we log both so the rider-cancel case is provably not a
     * reset rather than merely unrecorded.
     */
    public static function logCancel(int $riderId, array $orderIds, ?int $byUserId): void
    {
        if (!self::available() || empty($orderIds)) {
            return;
        }
        try {
            $now = now()->format('Y-m-d H:i:s');
            $isSelf = ($byUserId !== null && (int) $byUserId === $riderId) ? 1 : 0;
            $rows = [];
            foreach ($orderIds as $oid) {
                $rows[] = [
                    'order_id'              => (int) $oid,
                    'rider_id'              => $riderId,
                    'event'                 => 'cancel',
                    'batch_ts'              => null,
                    'estimated_delivery_at' => null,
                    'position'              => null,
                    'calculated_by'         => $byUserId,
                    'is_rider_self'         => $isSelf,
                    'is_mid_run'            => 0,
                    'delivered_before'      => 0,
                    'scope'                 => null,
                    'created_at'            => $now,
                ];
            }
            DB::table(self::TABLE)->insert($rows);
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: cancel log failed (non-fatal)', [
                'rider_id' => $riderId, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ---- reader ----------------------------------------------------------

    /**
     * Promise per order id. Orders with no log rows are simply absent from the
     * result — callers must fall back to the order's current ETA.
     *
     * @return array<int, array> order_id => [
     *     promised_at, promise_batch_ts, promise_by, promise_is_store,
     *     final_at, retimed, retimed_by, retimed_by_rider, retimed_mid_run
     *   ]
     */
    public static function promisesFor(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!self::available() || empty($orderIds)) {
            return [];
        }
        try {
            $rows = DB::table(self::TABLE)
                ->whereIn('order_id', $orderIds)
                ->orderBy('order_id')
                ->orderBy('created_at')
                ->orderBy('id')          // stable within the same second
                ->get([
                    'order_id', 'event', 'batch_ts', 'estimated_delivery_at',
                    'calculated_by', 'is_rider_self', 'is_mid_run', 'created_at',
                ]);

            $byOrder = [];
            foreach ($rows as $r) {
                $byOrder[(int) $r->order_id][] = $r;
            }

            $out = [];
            foreach ($byOrder as $oid => $events) {
                $p = self::derive($events);
                if ($p !== null) {
                    $out[$oid] = $p;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: promise read failed (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Apply the rule to one order's chronological event list.
     *
     * Scoped to the LAST calendar day that carries a dispatch: an order
     * dispatched yesterday, left undelivered and re-dispatched today must be
     * judged against today's commitment, not yesterday's.
     */
    private static function derive(array $events): ?array
    {
        // Keep only the events of the most recent day that actually dispatched.
        $lastDispatchDay = null;
        foreach ($events as $e) {
            if ($e->event === 'dispatch') {
                $lastDispatchDay = substr((string) $e->created_at, 0, 10);
            }
        }
        if ($lastDispatchDay === null) {
            return null; // cancels only — nothing was ever promised
        }
        $events = array_values(array_filter(
            $events,
            fn ($e) => substr((string) $e->created_at, 0, 10) === $lastDispatchDay
        ));

        // The last STORE-initiated event (dispatch OR cancel) is the sanctioned
        // reset point; everything before it is superseded by management.
        $resetIdx = 0;
        foreach ($events as $i => $e) {
            if (!$e->is_rider_self) {
                $resetIdx = $i;
            }
        }

        // The promise = the first dispatch AT or AFTER that reset point.
        $promise = null;
        for ($i = $resetIdx; $i < count($events); $i++) {
            if ($events[$i]->event === 'dispatch') {
                $promise = $events[$i];
                break;
            }
        }
        // Reset was a store cancel never followed by a dispatch: the times were
        // deliberately withdrawn, so there is no promise to hold him to.
        if ($promise === null) {
            return null;
        }

        // The last dispatch = what the customer/app currently sees.
        $final = null;
        foreach ($events as $e) {
            if ($e->event === 'dispatch') {
                $final = $e;
            }
        }

        $retimed = $final && $final->estimated_delivery_at !== $promise->estimated_delivery_at;

        return [
            'promised_at'       => $promise->estimated_delivery_at,
            'promise_batch_ts'  => $promise->batch_ts,
            'promise_by'        => $promise->calculated_by !== null ? (int) $promise->calculated_by : null,
            'promise_is_store'  => !$promise->is_rider_self,
            'final_at'          => $final ? $final->estimated_delivery_at : null,
            'retimed'           => (bool) $retimed,
            'retimed_by'        => $retimed && $final->calculated_by !== null ? (int) $final->calculated_by : null,
            'retimed_by_rider'  => $retimed ? (bool) $final->is_rider_self : false,
            'retimed_mid_run'   => $retimed ? (bool) $final->is_mid_run : false,
        ];
    }

    /**
     * Rider-initiated re-dispatches that actually MOVED times already promised to
     * customers, made while he was already part-way through the day's deliveries.
     * The daily-issues "Mid-run route changes" section. One entry per wave.
     *
     * The load-bearing rule (owner, Jul-2026): a wave is a "route change" ONLY if
     * it RE-TIMES a stop whose latest prior event was itself a `dispatch` — i.e. a
     * live promise was overwritten. This is what keeps a GENUINE next wave honest:
     *  - A returning rider who finished wave 1 and dispatches fresh wave-2 orders
     *    has delivered_before > 0, but those orders were never dispatched before
     *    (no prior event, or a prior `cancel`), so nothing is re-timed → NOT
     *    flagged. Gating on delivered_before alone (the old bug) false-flagged
     *    exactly this case.
     *  - A `cancel` (e.g. cancel-dispatch at the office to merge in a new order)
     *    breaks the chain: the next dispatch is fresh, not a re-time.
     * This makes the report agree with the A3 push alert by construction (that
     * alert already gates on isRedispatch = "re-timed an already-timed stop").
     *
     * Also requires is_rider_self (store re-times are sanctioned) and
     * delivered_before > 0 (an at-office re-time before leaving moves nobody's
     * expectations — the per-order info chip still shows it, this row does not).
     *
     * Shift per re-timed order is measured against ITS OWN prior promise (the
     * immediately preceding dispatch), so it reports what THIS action moved.
     */
    public static function midRunChanges(int $riderId, string $date): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            // ALL events (dispatch AND cancel), chronological — cancels are needed
            // to tell a genuine re-time from a fresh wave.
            $rows = DB::table(self::TABLE)
                ->where('rider_id', $riderId)
                ->whereDate('created_at', $date)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['order_id', 'event', 'batch_ts', 'estimated_delivery_at',
                       'is_rider_self', 'delivered_before', 'created_at']);
            if ($rows->isEmpty()) {
                return [];
            }

            // Walk forward tracking, per order, its most recent PRIOR event and the
            // ETA that dispatch promised — so each dispatch can be classified as a
            // re-time (prior event was a dispatch) and its shift measured.
            $priorEvent = [];   // order_id => 'dispatch' | 'cancel'
            $priorEta   = [];   // order_id => last dispatch ETA seen so far
            $waves      = [];   // batch_ts => ['head' => row, 'shifts' => [], 'count' => n]

            foreach ($rows as $r) {
                $oid = (int) $r->order_id;

                if ($r->event === 'cancel') {
                    $priorEvent[$oid] = 'cancel'; // breaks the re-time chain
                    continue;
                }

                // A dispatch RE-TIMES this order only if its previous event was a
                // dispatch (a live promise existed and was overwritten).
                if (($priorEvent[$oid] ?? null) === 'dispatch') {
                    $key = (string) $r->batch_ts;
                    if (!isset($waves[$key])) {
                        $waves[$key] = ['head' => $r, 'shifts' => [], 'count' => 0];
                    }
                    $waves[$key]['count']++;
                    $base = $priorEta[$oid] ?? null;
                    if ($base && $r->estimated_delivery_at) {
                        $waves[$key]['shifts'][] = (int) round(
                            (strtotime($r->estimated_delivery_at) - strtotime($base)) / 60
                        );
                    }
                }

                // Advance this order's state for the next event.
                $priorEvent[$oid] = 'dispatch';
                $priorEta[$oid]   = $r->estimated_delivery_at;
            }

            $out = [];
            foreach ($waves as $w) {
                $head = $w['head'];
                // Rider-initiated, and he'd already started delivering. (A re-time
                // can't be the day's first wave, so no explicit first-wave guard is
                // needed — the prior-dispatch requirement already excludes it.)
                if (!$head->is_rider_self || (int) $head->delivered_before < 1) {
                    continue;
                }
                $shifts = $w['shifts'];
                $out[] = [
                    'at'               => $head->created_at,
                    'batch_ts'         => $head->batch_ts,
                    'order_count'      => $w['count'],          // orders whose promise moved
                    'delivered_before' => (int) $head->delivered_before,
                    'avg_shift_min'    => $shifts ? (int) round(array_sum($shifts) / count($shifts)) : null,
                    'max_shift_min'    => $shifts ? max($shifts) : null,
                ];
            }
            // Chronological for display.
            usort($out, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
            return $out;
        } catch (\Throwable $e) {
            Log::warning('EtaPromiseService: midRunChanges failed (non-fatal)', [
                'rider_id' => $riderId, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
