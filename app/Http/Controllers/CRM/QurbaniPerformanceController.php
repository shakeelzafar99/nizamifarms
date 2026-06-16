<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\FIN\ConfigModel;
use App\Services\QurbaniSlotParser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qurbani Performance dashboard (Phase 5, May-2026).
 *
 * Web-only operational snapshot for the Qurbani event. Three pieces:
 *
 *   1. **Day-state machine** — Activate / Close / Reset buttons that
 *      toggle the `qurbani_ops_active` config. Late / at-risk math
 *      is dimmed when not active so we don't show false alarms in
 *      the days leading up to the event.
 *
 *   2. **Summary KPIs** — small set of totals: open / OFD / delivered
 *      / late / at-risk / dispatch coverage. Each KPI is a metric ID
 *      (e.g. `delivered_late`) that backs the drill-down call.
 *
 *   3. **Drilldown** — clicking a KPI in the UI calls the drill
 *      endpoint with that metric ID. The endpoint runs the matching
 *      query (same WHERE the KPI count used) and returns the underlying
 *      line-item rows. Used to validate that the summary numbers
 *      actually reflect the records the user sees.
 *
 * Permission gate: same as `qurbani.orders` — `access_qurbani_mode`.
 *
 * Source-of-truth for all slot times: `qurbani_slot_end_minute` on
 * the line item (parser auto-detect, optionally overridden by
 * t_crm_qurbani_field_options).
 */
class QurbaniPerformanceController extends Controller
{
    /**
     * Default "at risk" window in minutes — when an item is within
     * this many minutes of its slot end and not yet dispatched, the
     * UI flags it red. Persisted in config so the repo owner can
     * tweak without a deploy.
     */
    private const DEFAULT_AT_RISK_WINDOW_MIN = 100;

    /**
     * Default grace minutes for late detection. Mirrors the value
     * used by QurbaniSlotParser::compareEventToSlot in the orders
     * pages — keep these in lock-step or two parts of the UI will
     * disagree about who counts as "late".
     */
    private const DEFAULT_GRACE_MIN = 10;

    /**
     * Default "very late vs promise" threshold in minutes. Same value
     * the auto-WA worker uses to detect a customer-facing slip that
     * needs a delay-update message — so the KPI on this page and the
     * "send updated time" worker agree on what counts as a real slip.
     * Configurable via qurbani_wa_ofd_delay_threshold_minutes.
     */
    private const DEFAULT_VERY_LATE_PROMISE_MIN = 30;

    /**
     * SLA bucket configuration (May-2026) — single source of truth for
     * the "Slot SLA buckets" table that sits above the per-slot rollup.
     * Answers the question: "Of the items in slot X, how many hit
     * status Y on time vs how late?".
     *
     * Each event maps to a timestamp column on t_crm_prod_order_line_item
     * and a 5-bucket ladder measured in **signed minutes** vs the slot
     * end (`delta = event_time_of_day_minutes - slot_end_minute`).
     *
     *   - DELIVERED — the customer cares about being on or before slot
     *     end. So buckets go {within, 0–1h late, 1–2h late, 2–3h late,
     *     > 3h late}. No "early before slot end" bucket — everything
     *     before slot end is just "within slot".
     *
     *   - OUT FOR DELIVERY / SLAUGHTERED — operations cares about a
     *     2-hour pre-slot-end window. ≥ 2h before slot end is ideal
     *     (rider has a comfortable runway); the last 2h before slot
     *     end is "cutting it close"; everything after slot end is late.
     *
     * Bucket ranges use (min, max] semantics:
     *   - min=null, max=N   → delta <= N
     *   - min=N,    max=null → delta >  N
     *   - both              → delta >  min AND delta <= max
     * The PHP SQL builder, the drill WHERE, and the JS click handler
     * all read this constant — keeping the three layers in lock-step
     * is the whole point.
     *
     * Restricted to delivery_type != self (i.e. "Delivery" bucket only)
     * because the per-slot rollup user already confirmed self-collection
     * doesn't make sense for SLA — those customers come to us.
     */
    private function slaEvents(): array
    {
        return [
            'delivered' => [
                'label'        => 'Delivered',
                'event_column' => 'qurbani_delivered_at',
                'status_clause' => "li.qurbani_item_status = 'delivered' AND li.qurbani_delivered_at IS NOT NULL",
                'buckets' => [
                    ['key' => 'within',   'label' => 'Within slot', 'subline' => 'on or before end', 'min' => null,  'max' => 0,    'tone' => 'success'],
                    ['key' => 'late_1h',  'label' => '0–1h late',   'subline' => 'past slot end',    'min' => 0,     'max' => 60,   'tone' => 'warn'],
                    ['key' => 'late_2h',  'label' => '1–2h late',   'subline' => '',                  'min' => 60,    'max' => 120,  'tone' => 'warn'],
                    ['key' => 'late_3h',  'label' => '2–3h late',   'subline' => '',                  'min' => 120,   'max' => 180,  'tone' => 'danger'],
                    ['key' => 'late_xh',  'label' => '> 3h late',   'subline' => '',                  'min' => 180,   'max' => null, 'tone' => 'danger'],
                ],
            ],
            'out_for_delivery' => [
                'label'        => 'Out for delivery',
                'event_column' => 'qurbani_out_for_delivery_at',
                // Anything with a real OFD timestamp counts — even if
                // the item has since moved on to delivered, the moment
                // it went OFD is what we're scoring.
                'status_clause' => 'li.qurbani_out_for_delivery_at IS NOT NULL',
                'buckets' => [
                    ['key' => 'early',    'label' => '≥ 2h before', 'subline' => 'on track',           'min' => null,  'max' => -120, 'tone' => 'success'],
                    ['key' => 'close',    'label' => '< 2h before', 'subline' => 'cutting it close',   'min' => -120,  'max' => 0,    'tone' => 'warn'],
                    ['key' => 'late_1h',  'label' => '0–1h late',   'subline' => 'past slot end',      'min' => 0,     'max' => 60,   'tone' => 'warn'],
                    ['key' => 'late_2h',  'label' => '1–2h late',   'subline' => '',                    'min' => 60,    'max' => 120,  'tone' => 'danger'],
                    ['key' => 'late_xh',  'label' => '> 2h late',   'subline' => '',                    'min' => 120,   'max' => null, 'tone' => 'danger'],
                ],
            ],
            'slaughtered' => [
                'label'        => 'Slaughtered',
                'event_column' => 'qurbani_slaughtered_at',
                'status_clause' => 'li.qurbani_slaughtered_at IS NOT NULL',
                'buckets' => [
                    ['key' => 'early',    'label' => '≥ 2h before', 'subline' => 'on track',           'min' => null,  'max' => -120, 'tone' => 'success'],
                    ['key' => 'close',    'label' => '< 2h before', 'subline' => 'cutting it close',   'min' => -120,  'max' => 0,    'tone' => 'warn'],
                    ['key' => 'late_1h',  'label' => '0–1h late',   'subline' => 'past slot end',      'min' => 0,     'max' => 60,   'tone' => 'warn'],
                    ['key' => 'late_2h',  'label' => '1–2h late',   'subline' => '',                    'min' => 60,    'max' => 120,  'tone' => 'danger'],
                    ['key' => 'late_xh',  'label' => '> 2h late',   'subline' => '',                    'min' => 120,   'max' => null, 'tone' => 'danger'],
                ],
            ],
        ];
    }

    /**
     * Build the SQL fragment that turns one bucket spec into a WHERE
     * predicate against the signed `delta` expression. Shared by the
     * /slot-sla aggregate AND the /drill detail query so the two
     * numbers can never disagree.
     */
    private function slaBucketSql(string $deltaExpr, array $bucket): string
    {
        $min = $bucket['min'] ?? null;
        $max = $bucket['max'] ?? null;
        if ($min === null && $max !== null) {
            return "{$deltaExpr} <= {$max}";
        }
        if ($min !== null && $max === null) {
            return "{$deltaExpr} > {$min}";
        }
        return "{$deltaExpr} > {$min} AND {$deltaExpr} <= {$max}";
    }

    /**
     * GET /qurbani/performance
     *
     * Renders the dashboard shell. All numbers + records are loaded
     * lazily by the JS layer hitting /qurbani/api/performance/*.
     */
    public function index()
    {
        $dayState = $this->loadDayState();
        $days = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_day')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->pluck('option_value')
            ->all();
        // Phase 5 (May-2026) — default day-filter resolution.
        // Priority: active operational day > first day in field_options
        // > empty string (i.e. "All days"). The dropdown still lets the
        // user widen back to "All days", but loading the page already
        // shows a meaningful slice instead of the firehose.
        $defaultDay = '';
        if (($dayState['active'] ?? 0) === 1 && !empty($dayState['current_day'])) {
            $candidate = 'Day ' . $dayState['current_day'];
            $defaultDay = in_array($candidate, $days, true) ? $candidate
                : (count($days) > 0 ? $days[0] : '');
        } elseif (count($days) > 0) {
            $defaultDay = $days[0];
        }
        return view('pages.qurbani.performance', [
            'dayState'   => $dayState,
            'days'       => $days,
            'defaultDay' => $defaultDay,
        ]);
    }

    /**
     * GET /qurbani/api/performance/summary
     *
     * Returns the headline KPIs. Each row carries a `metric_id` that
     * the UI uses to call the drill endpoint when the user clicks the
     * card. Numbers respect the active-day filter when one is set.
     */
    public function summary(Request $request)
    {
        $day = $request->get('day');
        $now = now();
        $dayState = $this->loadDayState();
        $isActive = (int) ($dayState['active'] ?? 0) === 1;
        $atRiskWindow = (int) ConfigModel::get('qurbani_ops_at_risk_window_minutes', (string) self::DEFAULT_AT_RISK_WINDOW_MIN);
        if ($atRiskWindow < 1) $atRiskWindow = self::DEFAULT_AT_RISK_WINDOW_MIN;
        $graceMin = (int) ConfigModel::get('qurbani_late_grace_minutes', (string) self::DEFAULT_GRACE_MIN);
        if ($graceMin < 0) $graceMin = self::DEFAULT_GRACE_MIN;

        // Base query: every Qurbani line item with a slot end on file.
        // Excludes cancelled orders so deleted/cancelled rows don't
        // skew the late count. Day filter is applied after we know
        // which day the user picked (if any).
        $base = function () use ($day) {
            $q = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
                ->where(function ($q2) {
                    $q2->whereNull('o.order_status')
                       ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
                });
            if ($day) {
                $q->where('li.qurbani_day', $day);
            }
            return $q;
        };

        // KPI 1: total Qurbani items in scope.
        $totalItems = (clone $base())
            ->where(function ($q) {
                $q->whereNotNull('li.qurbani_day')
                  ->orWhereNotNull('li.qurbani_slot')
                  ->orWhereNotNull('li.qurbani_region');
            })
            ->count();

        // KPI 2: out for delivery (not yet delivered).
        $ofdCount = (clone $base())
            ->where('li.qurbani_item_status', 'out_for_delivery')
            ->whereNull('li.qurbani_delivered_at')
            ->count();

        // KPI 3: delivered.
        $deliveredCount = (clone $base())
            ->where('li.qurbani_item_status', 'delivered')
            ->count();

        // KPI 4: delivered LATE — actual delivered time-of-day past
        // slot end (signed minutes > grace). Uses MySQL math so we
        // don't pull every row to PHP.
        //
        // May-2026 fix — `qurbani_slot_end_minute` is `SMALLINT UNSIGNED`,
        // so naive subtraction `(... - li.qurbani_slot_end_minute)` poisons
        // the whole expression to UNSIGNED and explodes with
        // "BIGINT UNSIGNED value is out of range" on every row where the
        // delivery was EARLY (a negative signed result would otherwise
        // be perfectly normal). Forcing both operands to SIGNED keeps
        // the comparison correct AND silent on early deliveries.
        $deliveredLate = (clone $base())
            ->where('li.qurbani_item_status', 'delivered')
            ->whereNotNull('li.qurbani_delivered_at')
            ->whereNotNull('li.qurbani_slot_end_minute')
            ->whereRaw(
                '(CAST(HOUR(li.qurbani_delivered_at) * 60 + MINUTE(li.qurbani_delivered_at) AS SIGNED) - CAST(li.qurbani_slot_end_minute AS SIGNED)) > ?',
                [$graceMin]
            )
            ->count();

        // KPI 5: delivered ON TIME — same comparison but within grace.
        // Same SIGNED cast as KPI 4 above — see comment there for why.
        $deliveredOnTime = (clone $base())
            ->where('li.qurbani_item_status', 'delivered')
            ->whereNotNull('li.qurbani_delivered_at')
            ->whereNotNull('li.qurbani_slot_end_minute')
            ->whereRaw(
                '(CAST(HOUR(li.qurbani_delivered_at) * 60 + MINUTE(li.qurbani_delivered_at) AS SIGNED) - CAST(li.qurbani_slot_end_minute AS SIGNED)) <= ?',
                [$graceMin]
            )
            ->count();

        // KPI 6: AT RISK — slaughtered but not dispatched, with slot
        // end within $atRiskWindow minutes of NOW. Self-correcting:
        // once dispatched, the row drops out. Only meaningful when
        // the operational day is active.
        $atRiskCount = 0;
        if ($isActive) {
            $nowMin = (int) ($now->hour * 60 + $now->minute);
            $thresholdLow  = $nowMin;
            $thresholdHigh = $nowMin + $atRiskWindow;
            $atRiskCount = (clone $base())
                ->where('li.qurbani_item_status', 'slaughtered')
                ->whereNull('li.qurbani_dispatched_at')
                ->whereNotNull('li.qurbani_slot_end_minute')
                ->whereRaw('li.qurbani_slot_end_minute BETWEEN ? AND ?', [$thresholdLow - 30, $thresholdHigh])
                ->count();
        }

        // KPI 7: SELF-COLLECTION OVERDUE — delivery_type self-collect,
        // status still open, slot end already past. Only meaningful
        // when the operational day is active.
        $selfCollectionOverdue = 0;
        if ($isActive) {
            $nowMin = (int) ($now->hour * 60 + $now->minute);
            $selfCollectionOverdue = (clone $base())
                ->whereRaw("LOWER(COALESCE(li.qurbani_delivery_type,'')) LIKE ?", ['%self%'])
                ->where(function ($q) {
                    $q->whereNull('li.qurbani_item_status')
                      ->orWhereIn('li.qurbani_item_status', ['open', 'slaughtered']);
                })
                ->whereNotNull('li.qurbani_slot_end_minute')
                ->whereRaw('li.qurbani_slot_end_minute < ?', [$nowMin - $graceMin])
                ->count();
        }

        // KPI 8: dispatch gap — slaughtered but not dispatched (any
        // age). Useful baseline counter even when day not active.
        $dispatchGap = (clone $base())
            ->where('li.qurbani_item_status', 'slaughtered')
            ->whereNull('li.qurbani_dispatched_at')
            ->count();

        // KPIs 9a-c (May-2026 — Promise drift). Compares actual
        // qurbani_delivered_at against the EARLIEST OFD WhatsApp
        // promise (t_ops_qurbani_wa_log) per line item, falling back
        // to qurbani_estimated_delivery_at when no WA was sent. Three
        // buckets:
        //
        //   - delivered_on_promise        |drift| ≤ grace
        //   - delivered_late_promise      grace < drift ≤ very-late
        //   - delivered_very_late_promise drift > very-late
        //
        // Avg drift is reported on the on-promise card's subline so
        // the manager gets one number that summarises the whole day
        // without us inflating the KPI count.
        $veryLatePromiseMin = (int) ConfigModel::get(
            'qurbani_wa_ofd_delay_threshold_minutes',
            (string) self::DEFAULT_VERY_LATE_PROMISE_MIN
        );
        if ($veryLatePromiseMin < 1) $veryLatePromiseMin = self::DEFAULT_VERY_LATE_PROMISE_MIN;
        $promiseStats = $this->loadDeliveredPromiseStats($day, $graceMin, $veryLatePromiseMin);

        // KPI 9 (May-2026): Unread WhatsApp messages from Qurbani
        // customers. Counts DISTINCT customers in the current day
        // scope who have at least one WhatsApp conversation with
        // unread_count > 0 — the CS-manager landing metric.
        //
        // Implementation notes:
        //   - Wrapped in Schema::hasTable so dev environments without
        //     the WhatsApp tables don't blow up the dashboard.
        //   - Joins t_wa_conversations on customer_id (the standard
        //     auto-link the inbound webhook populates). Conversations
        //     that aren't linked to a customer record are ignored on
        //     purpose — without a customer_id we can't reliably tie
        //     them back to a Qurbani order anyway.
        //   - DISTINCT customer_id (not row count) because a single
        //     customer with 5 unread messages is still "1 customer
        //     needing attention" — that's the actionable unit.
        $unreadWaCustomers = 0;
        $waTableAvailable = Schema::hasTable('t_wa_conversations');
        if ($waTableAvailable) {
            $unreadWaCustomers = (clone $base())
                ->join('t_wa_conversations as wac', 'wac.customer_id', '=', 'o.customer_id')
                ->where('wac.unread_count', '>', 0)
                ->whereNotNull('o.customer_id')
                ->distinct()
                ->count('o.customer_id');
        }

        // Per-slot rollup: counts grouped by (delivery_type, slot end
        // minute) so the UI can split into "Delivery" vs "Self
        // Collection" tabs — those are different operational flows
        // and use different slot lists, so mixing them in one table
        // is confusing.
        //
        // Status counts walk the full lifecycle so the row reads
        // naturally left-to-right: open → slaughtered → ofd →
        // delivered. In normal flow `slaughtered` and "awaiting
        // dispatch" are the same set — once dispatched, status
        // moves to OFD — so we drop the redundant AND clause.
        $perSlotRows = (clone $base())
            ->whereNotNull('li.qurbani_slot_end_minute')
            ->select(
                'li.qurbani_delivery_type',
                'li.qurbani_slot',
                'li.qurbani_slot_end_minute',
                DB::raw("SUM(CASE WHEN li.qurbani_item_status IS NULL OR li.qurbani_item_status = '' OR li.qurbani_item_status = 'open' THEN 1 ELSE 0 END) as open_count"),
                DB::raw("SUM(CASE WHEN li.qurbani_item_status = 'slaughtered' THEN 1 ELSE 0 END) as slaughtered"),
                DB::raw("SUM(CASE WHEN li.qurbani_item_status = 'out_for_delivery' THEN 1 ELSE 0 END) as ofd"),
                DB::raw("SUM(CASE WHEN li.qurbani_item_status = 'delivered' THEN 1 ELSE 0 END) as delivered"),
                // May-2026 fix — CAST to SIGNED on both sides; see comment
                // on $deliveredLate KPI for why (qurbani_slot_end_minute is
                // UNSIGNED and unsigned subtraction crashes on early
                // deliveries).
                DB::raw("SUM(CASE WHEN li.qurbani_item_status = 'delivered' AND (CAST(HOUR(li.qurbani_delivered_at) * 60 + MINUTE(li.qurbani_delivered_at) AS SIGNED) - CAST(li.qurbani_slot_end_minute AS SIGNED)) > {$graceMin} THEN 1 ELSE 0 END) as delivered_late"),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('li.qurbani_delivery_type', 'li.qurbani_slot', 'li.qurbani_slot_end_minute')
            ->orderBy('li.qurbani_slot_end_minute')
            ->get()
            ->map(function ($r) {
                return [
                    'delivery_type'    => $r->qurbani_delivery_type,
                    'slot'             => $r->qurbani_slot,
                    'slot_end_minute'  => (int) $r->qurbani_slot_end_minute,
                    'slot_end_display' => QurbaniSlotParser::formatMinutes((int) $r->qurbani_slot_end_minute),
                    'open'             => (int) $r->open_count,
                    'slaughtered'      => (int) $r->slaughtered,
                    'ofd'              => (int) $r->ofd,
                    'delivered'        => (int) $r->delivered,
                    'delivered_late'   => (int) $r->delivered_late,
                    'total'            => (int) $r->total,
                ];
            });

        // Bucket into Delivery vs Self Collection. Anything whose
        // delivery_type contains "self" lands in self_collection;
        // everything else (including blank) goes to delivery.
        $perSlotDelivery = [];
        $perSlotSelfCollection = [];
        foreach ($perSlotRows as $row) {
            $dt = strtolower((string) ($row['delivery_type'] ?? ''));
            if (str_contains($dt, 'self')) {
                $perSlotSelfCollection[] = $row;
            } else {
                $perSlotDelivery[] = $row;
            }
        }
        $perSlot = [
            'delivery'        => $perSlotDelivery,
            'self_collection' => $perSlotSelfCollection,
        ];

        return response()->json([
            'success'    => true,
            'day_state'  => $dayState,
            'as_of'      => $now->toIso8601String(),
            'filter'     => ['day' => $day],
            'config'     => [
                'grace_minutes'        => $graceMin,
                'at_risk_window_min'   => $atRiskWindow,
                // May-2026 — promise-drift thresholds + headline avg
                // so the UI can render "avg +Nm" once near the new
                // KPI cluster without re-deriving the math.
                'very_late_promise_min' => $veryLatePromiseMin,
                'promise_drift'         => [
                    'on_count'        => $promiseStats['on'],
                    'late_count'      => $promiseStats['late'],
                    'very_late_count' => $promiseStats['very_late'],
                    'early_count'     => $promiseStats['early'],
                    'no_promise_count' => $promiseStats['no_promise'],
                    'avg_drift'       => $promiseStats['avg'],
                    'count'           => $promiseStats['count'],
                ],
            ],
            'kpis' => [
                [
                    'id'        => 'total_items',
                    'label'     => 'Qurbani items in scope',
                    'value'     => $totalItems,
                    'tone'      => 'neutral',
                    'subline'   => $day ? "Day filter: {$day}" : 'All days',
                    'drillable' => true,
                ],
                [
                    'id'        => 'ofd',
                    'label'     => 'Out for delivery',
                    'value'     => $ofdCount,
                    'tone'      => 'info',
                    'subline'   => 'OFD, not yet delivered',
                    'drillable' => true,
                ],
                [
                    'id'        => 'delivered',
                    'label'     => 'Delivered',
                    'value'     => $deliveredCount,
                    'tone'      => 'success',
                    'subline'   => 'Status = delivered',
                    'drillable' => true,
                ],
                [
                    'id'        => 'delivered_on_time',
                    'label'     => 'Delivered within slot',
                    'value'     => $deliveredOnTime,
                    'tone'      => 'success',
                    'subline'   => "Within {$graceMin}m grace of slot end",
                    'drillable' => true,
                ],
                [
                    'id'        => 'delivered_late',
                    'label'     => 'Delivered late',
                    'value'     => $deliveredLate,
                    'tone'      => 'danger',
                    'subline'   => "More than {$graceMin}m past slot end",
                    'drillable' => true,
                ],
                [
                    'id'        => 'at_risk',
                    'label'     => 'At risk (slaughtered, not dispatched)',
                    'value'     => $atRiskCount,
                    'tone'      => $atRiskCount > 0 ? 'danger' : 'muted',
                    'subline'   => "Slot end within next {$atRiskWindow}m",
                    'drillable' => $isActive,
                    'inactive'  => !$isActive,
                ],
                [
                    'id'        => 'self_collection_overdue',
                    'label'     => 'Self-collection overdue',
                    'value'     => $selfCollectionOverdue,
                    'tone'      => $selfCollectionOverdue > 0 ? 'danger' : 'muted',
                    'subline'   => 'Slot end already past · still open',
                    'drillable' => $isActive,
                    'inactive'  => !$isActive,
                ],
                [
                    'id'        => 'dispatch_gap',
                    'label'     => 'Dispatch gap',
                    'value'     => $dispatchGap,
                    'tone'      => $dispatchGap > 0 ? 'warn' : 'muted',
                    'subline'   => 'Slaughtered but not yet dispatched',
                    'drillable' => true,
                ],
                // May-2026 — Promise drift KPIs. Sit beside the slot-
                // based late counts so management can compare both
                // questions side-by-side: "did we hit the customer's
                // chosen slot?" vs "did we hit the time we promised
                // them at dispatch?".
                [
                    'id'        => 'delivered_on_promise',
                    'label'     => 'Delivered on promise',
                    'value'     => $promiseStats['on'],
                    'tone'      => 'success',
                    'subline'   => $promiseStats['count'] > 0
                        ? ('Avg drift ' . ($promiseStats['avg'] > 0 ? '+' : '') . $promiseStats['avg'] . 'm across ' . $promiseStats['count'] . ' delivered')
                        : 'Within ' . $graceMin . 'm of promised ETA',
                    'drillable' => true,
                ],
                [
                    'id'        => 'delivered_late_promise',
                    'label'     => 'Delivered late vs promise',
                    'value'     => $promiseStats['late'],
                    'tone'      => $promiseStats['late'] > 0 ? 'warn' : 'muted',
                    'subline'   => 'More than ' . $graceMin . 'm late vs WhatsApp promise',
                    'drillable' => true,
                ],
                [
                    'id'        => 'delivered_very_late_promise',
                    'label'     => 'Delivered very late vs promise',
                    'value'     => $promiseStats['very_late'],
                    'tone'      => $promiseStats['very_late'] > 0 ? 'danger' : 'muted',
                    'subline'   => 'More than ' . $veryLatePromiseMin . 'm late vs WhatsApp promise',
                    'drillable' => true,
                ],
                // May-2026 — Customer Service Manager KPI. Sits last
                // so the operational lifecycle (open → delivered) is
                // read first; CS metrics live to the right.
                [
                    'id'        => 'unread_wa',
                    'label'     => 'Unread WhatsApp',
                    'value'     => $unreadWaCustomers,
                    'tone'      => $unreadWaCustomers > 0 ? 'warn' : 'muted',
                    'subline'   => $waTableAvailable
                        ? 'Qurbani customers with unread messages'
                        : 'WhatsApp tables not deployed in this env',
                    'drillable' => $waTableAvailable,
                    'inactive'  => !$waTableAvailable,
                ],
            ],
            'per_slot' => $perSlot,
        ]);
    }

    /**
     * GET /qurbani/api/performance/slot-sla?event=delivered&day=...
     *
     * Phase 6 (May-2026) — per-slot SLA bucket counts. Sits above the
     * per-slot point-in-time rollup and answers "how *on-time* were
     * the events?" rather than "what state is everything in right now?"
     *
     * Event must be one of: delivered, out_for_delivery, slaughtered.
     * Self-collection is intentionally excluded — those customers come
     * to us, so the rider SLA framing doesn't apply.
     *
     * Returns:
     *   - meta.event            — echo of the chosen event
     *   - meta.event_label      — pretty name for the header
     *   - meta.buckets[]        — full bucket spec (label, subline,
     *                              tone, range) so the UI can build
     *                              headers without re-deriving them
     *   - rows[]                — one per slot end; each carries
     *                              counts[] (parallel to buckets[])
     *                              plus a total
     */
    public function slotSla(Request $request)
    {
        $event = (string) $request->get('event', 'delivered');
        $events = $this->slaEvents();
        if (!isset($events[$event])) {
            return response()->json([
                'success' => false,
                'message' => "Unknown event: {$event}. Allowed: " . implode(', ', array_keys($events)),
            ], 400);
        }
        $cfg = $events[$event];
        $day = $request->get('day');

        // Signed-minute delta between event time-of-day and slot end
        // minute. Same SIGNED CAST pattern as the existing late-count
        // SQL — see the `$deliveredLate` comment in summary() for why
        // both operands MUST cast to SIGNED (qurbani_slot_end_minute
        // is UNSIGNED, naive subtraction blows up on early events).
        $col = $cfg['event_column'];
        $deltaExpr = "(CAST(HOUR(li.{$col}) * 60 + MINUTE(li.{$col}) AS SIGNED) - CAST(li.qurbani_slot_end_minute AS SIGNED))";

        // Build the SUM(CASE...) for each bucket. Order is preserved
        // so the UI can render the same column order it sees here.
        $selectFragments = [
            'li.qurbani_slot',
            'li.qurbani_slot_end_minute',
        ];
        foreach ($cfg['buckets'] as $i => $b) {
            $cond = $this->slaBucketSql($deltaExpr, $b);
            $selectFragments[] = DB::raw("SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) as b{$i}");
        }
        $selectFragments[] = DB::raw('COUNT(*) as total');

        // Base query: same exclusions as the rest of the page —
        // exclude cancelled orders, restrict to Delivery (not self),
        // require slot-end minute, plus the event-specific status
        // clause from $cfg.
        $q = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->where(function ($q2) {
                $q2->whereNull('o.order_status')
                   ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            })
            // Delivery only — self-collection has no rider SLA.
            ->where(function ($q2) {
                $q2->whereNull('li.qurbani_delivery_type')
                   ->orWhereRaw("LOWER(COALESCE(li.qurbani_delivery_type,'')) NOT LIKE ?", ['%self%']);
            })
            ->whereNotNull('li.qurbani_slot_end_minute')
            ->whereRaw($cfg['status_clause']);
        if ($day) {
            $q->where('li.qurbani_day', $day);
        }

        $rows = $q->select($selectFragments)
            ->groupBy('li.qurbani_slot', 'li.qurbani_slot_end_minute')
            ->orderBy('li.qurbani_slot_end_minute')
            ->get();

        $out = $rows->map(function ($r) use ($cfg) {
            $counts = [];
            foreach ($cfg['buckets'] as $i => $b) {
                $col = 'b' . $i;
                $counts[] = (int) ($r->{$col} ?? 0);
            }
            return [
                'slot'             => $r->qurbani_slot,
                'slot_end_minute'  => (int) $r->qurbani_slot_end_minute,
                'slot_end_display' => QurbaniSlotParser::formatMinutes((int) $r->qurbani_slot_end_minute),
                'counts'           => $counts,
                'total'            => (int) $r->total,
            ];
        })->all();

        // Strip SQL guts out of bucket meta before exposing to the UI —
        // the frontend only needs label / subline / tone for rendering.
        $bucketsMeta = array_map(function ($b) {
            return [
                'key'     => $b['key'],
                'label'   => $b['label'],
                'subline' => $b['subline'],
                'tone'    => $b['tone'],
            ];
        }, $cfg['buckets']);

        return response()->json([
            'success' => true,
            'meta'    => [
                'event'        => $event,
                'event_label'  => $cfg['label'],
                'buckets'      => $bucketsMeta,
                'filter'       => ['day' => $day],
            ],
            'rows' => $out,
        ]);
    }

    /**
     * GET /qurbani/api/performance/drill?metric=...&day=...
     *
     * Returns the underlying line-item records for one of the KPIs
     * above. The metric ID drives the WHERE clause — keep this in
     * lock-step with summary() so a click and the headline number
     * always agree.
     */
    public function drill(Request $request)
    {
        $metric = (string) $request->get('metric', '');
        $day = $request->get('day');
        $slotEnd = $request->get('slot_end_minute');
        $now = now();
        $graceMin = (int) ConfigModel::get('qurbani_late_grace_minutes', (string) self::DEFAULT_GRACE_MIN);
        if ($graceMin < 0) $graceMin = self::DEFAULT_GRACE_MIN;
        $atRiskWindow = (int) ConfigModel::get('qurbani_ops_at_risk_window_minutes', (string) self::DEFAULT_AT_RISK_WINDOW_MIN);
        if ($atRiskWindow < 1) $atRiskWindow = self::DEFAULT_AT_RISK_WINDOW_MIN;
        $nowMin = (int) ($now->hour * 60 + $now->minute);

        // May-2026 (CS Manager view) — left-join a per-customer
        // WhatsApp summary so each row can carry the conversation id,
        // total unread, and last-message timestamp. Built as a sub-
        // query (grouped by customer_id) so that a customer with
        // multiple conversation rows doesn't duplicate the line-item
        // row. SUM(unread_count) gives the total unread across all
        // threads for that customer; MAX(id) gives a stable
        // conversation_id for the deep-link button.
        $waTableAvailable = Schema::hasTable('t_wa_conversations');
        // May-2026 (ETA freshness guard) — left-join the most-recent
        // OFD WhatsApp log row per line_item so each row can carry
        // the ETA that was actually sent to the customer
        // (delivery_time_used) and when. The CS manager uses this to
        // see if the current ETA differs from what the customer
        // believes — i.e. is the customer about to be given stale
        // info if I quote the current ETA back to them?
        //
        // We pull from t_ops_qurbani_wa_log filtered to:
        //   - trigger_event IN ('ofd', 'ofd_delay_update')
        //   - status = 'sent'
        //   - delivery_time_used NOT NULL (some skipped rows have null)
        // Then INNER JOIN to a per-line_item MAX(created_at) so we
        // only keep the latest sent row.
        $waLogAvailable = Schema::hasTable('t_ops_qurbani_wa_log');
        $q = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('t_sys_user as r', 'r.id', '=', 'li.qurbani_assigned_rider_user_id')
            ->where(function ($q2) {
                $q2->whereNull('o.order_status')
                   ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            });
        if ($waTableAvailable) {
            $q->leftJoin(DB::raw(
                '(SELECT customer_id, '
                . 'SUM(COALESCE(unread_count,0)) AS wa_unread, '
                . 'MAX(last_message_at) AS wa_last_at, '
                . 'MAX(id) AS wa_conv_id, '
                . 'MAX(wa_phone) AS wa_phone '
                . 'FROM t_wa_conversations '
                . 'WHERE customer_id IS NOT NULL '
                . 'GROUP BY customer_id) as wac'
            ), 'wac.customer_id', '=', 'c.id');
        }
        if ($waLogAvailable) {
            $q->leftJoin(DB::raw(
                "(SELECT l.line_item_id, "
                . "l.delivery_time_used AS messaged_eta_at, "
                . "l.trigger_event       AS last_wa_trigger, "
                . "l.created_at          AS last_wa_sent_at "
                . "FROM t_ops_qurbani_wa_log l "
                . "INNER JOIN ("
                . "  SELECT line_item_id, MAX(created_at) AS mx "
                . "  FROM t_ops_qurbani_wa_log "
                . "  WHERE trigger_event IN ('ofd','ofd_delay_update') "
                . "    AND status = 'sent' "
                . "  GROUP BY line_item_id"
                . ") mx ON mx.line_item_id = l.line_item_id AND mx.mx = l.created_at "
                . "WHERE l.trigger_event IN ('ofd','ofd_delay_update') "
                . "  AND l.status = 'sent'"
                . ") as wal"
            ), 'wal.line_item_id', '=', 'li.id');

            // May-2026 (CS Manager) — separate subquery for the
            // SLAUGHTER trigger so the CS manager's drill table can
            // show "✓ sent at 14:32" under the Slaughtered column
            // (analogous to wal for OFD). We need the latest log row
            // PER (line_item, trigger) regardless of status — that
            // way the UI can render:
            //   • status='sent'    → green ✓ + timestamp
            //   • status='failed'  → red ✗ + reason (so the manager
            //                        knows to manual-send via the new
            //                        🔪 Send Now action button)
            //   • status='skipped' → grey badge + reason
            // No row → blank dash. Latest-per-li is enforced via the
            // MAX(id) INNER JOIN, which works even when two log rows
            // share a created_at timestamp (the autoinc id is
            // monotonic per insert and tie-breaks correctly).
            $q->leftJoin(DB::raw(
                "(SELECT l.line_item_id, "
                . "l.status     AS slaughter_wa_status, "
                . "l.created_at AS slaughter_wa_sent_at, "
                . "l.skip_reason AS slaughter_wa_skip_reason "
                . "FROM t_ops_qurbani_wa_log l "
                . "INNER JOIN ("
                . "  SELECT line_item_id, MAX(id) AS mx "
                . "  FROM t_ops_qurbani_wa_log "
                . "  WHERE trigger_event = 'slaughtered' "
                . "  GROUP BY line_item_id"
                . ") mx ON mx.line_item_id = l.line_item_id AND mx.mx = l.id "
                . "WHERE l.trigger_event = 'slaughtered'"
                . ") as wals"
            ), 'wals.line_item_id', '=', 'li.id');

            // Mirror subquery for OFD — same latest-per-li shape but
            // exposing status / skip_reason (the existing wal join only
            // returns 'sent' rows because it filters on status='sent'
            // for the ETA-drift calculation). We need failed/skipped
            // rows visible too so the CS manager can see "we tried but
            // it bounced" and use the 🛵 Send Now button.
            $q->leftJoin(DB::raw(
                "(SELECT l.line_item_id, "
                . "l.status      AS ofd_wa_status, "
                . "l.created_at  AS ofd_wa_sent_at, "
                . "l.skip_reason AS ofd_wa_skip_reason, "
                . "l.trigger_event AS ofd_wa_trigger "
                . "FROM t_ops_qurbani_wa_log l "
                . "INNER JOIN ("
                . "  SELECT line_item_id, MAX(id) AS mx "
                . "  FROM t_ops_qurbani_wa_log "
                . "  WHERE trigger_event IN ('ofd','ofd_delay_update') "
                . "  GROUP BY line_item_id"
                . ") mx ON mx.line_item_id = l.line_item_id AND mx.mx = l.id "
                . "WHERE l.trigger_event IN ('ofd','ofd_delay_update')"
                . ") as walo"
            ), 'walo.line_item_id', '=', 'li.id');

            // May-2026 — EARLIEST OFD WhatsApp per line item. This is
            // the snapshot of "what time we originally told the
            // customer". Used by the new promise-drift KPIs +
            // delivered_off_promise drill metrics. Status='sent' only
            // (a failed send was never seen by the customer, so it
            // doesn't form a real promise). Mirror MIN(id) join so we
            // disambiguate when two rows happen to share created_at.
            $q->leftJoin(DB::raw(
                "(SELECT l.line_item_id, "
                . "l.delivery_time_used AS first_messaged_eta_at, "
                . "l.created_at         AS first_messaged_sent_at "
                . "FROM t_ops_qurbani_wa_log l "
                . "INNER JOIN ("
                . "  SELECT line_item_id, MIN(id) AS mn "
                . "  FROM t_ops_qurbani_wa_log "
                . "  WHERE trigger_event IN ('ofd','ofd_delay_update') "
                . "    AND status = 'sent' "
                . "    AND delivery_time_used IS NOT NULL "
                . "  GROUP BY line_item_id"
                . ") mn ON mn.line_item_id = l.line_item_id AND mn.mn = l.id "
                . "WHERE l.trigger_event IN ('ofd','ofd_delay_update') "
                . "  AND l.status = 'sent' "
                . "  AND l.delivery_time_used IS NOT NULL"
                . ") as wf"
            ), 'wf.line_item_id', '=', 'li.id');
        }
        if ($day) {
            $q->where('li.qurbani_day', $day);
        }
        if ($slotEnd !== null && $slotEnd !== '') {
            $q->where('li.qurbani_slot_end_minute', (int) $slotEnd);
        }
        // Phase 5b (May-2026) — optional delivery_type narrow used
        // by the per-slot tab clicks ("self" vs everything else)
        // so the drill list matches the count the user clicked.
        $deliveryTypeBucket = strtolower((string) $request->get('delivery_type_bucket', ''));
        if ($deliveryTypeBucket === 'self_collection') {
            $q->whereRaw("LOWER(COALESCE(li.qurbani_delivery_type,'')) LIKE ?", ['%self%']);
        } elseif ($deliveryTypeBucket === 'delivery') {
            $q->where(function ($q2) {
                $q2->whereNull('li.qurbani_delivery_type')
                   ->orWhereRaw("LOWER(COALESCE(li.qurbani_delivery_type,'')) NOT LIKE ?", ['%self%']);
            });
        }

        // May-2026 (CS Manager view) — text search for the
        // customer-services workflow. `q` covers name + order # +
        // product name (most CS tickets reference one of these),
        // `phone` is a separate input because phone numbers are
        // matched character-by-character (the leading-zero / +92
        // / 0092 variants are common in PK numbers).
        $searchTerm = trim((string) $request->get('q', ''));
        if ($searchTerm !== '') {
            $like = '%' . $searchTerm . '%';
            $q->where(function ($q2) use ($like) {
                $q2->where('c.first_name', 'like', $like)
                   ->orWhere('c.last_name', 'like', $like)
                   ->orWhereRaw("CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) like ?", [$like])
                   ->orWhere('o.order_number', 'like', $like)
                   ->orWhere('li.name', 'like', $like);
            });
        }
        $searchPhone = preg_replace('/[^0-9+]/', '', (string) $request->get('phone', ''));
        if ($searchPhone !== '') {
            // Strip leading zeros / +92 / 0092 so the search is
            // resilient to how the user typed the number. We match
            // on the last 9 digits which is the unique-enough tail
            // for Pakistani mobiles.
            $tail = substr($searchPhone, -9);
            $q->where(function ($q2) use ($tail, $searchPhone) {
                $q2->where('c.phone', 'like', '%' . $tail)
                   ->orWhere('c.phone_normalized', 'like', '%' . $tail)
                   ->orWhere('o.address_phone', 'like', '%' . $tail)
                   ->orWhere('c.phone', 'like', '%' . $searchPhone . '%');
            });
        }

        switch ($metric) {
            case 'total_items':
                $q->where(function ($q2) {
                    $q2->whereNotNull('li.qurbani_day')
                       ->orWhereNotNull('li.qurbani_slot')
                       ->orWhereNotNull('li.qurbani_region');
                });
                break;
            case 'ofd':
                $q->where('li.qurbani_item_status', 'out_for_delivery')
                  ->whereNull('li.qurbani_delivered_at');
                break;
            case 'delivered':
                $q->where('li.qurbani_item_status', 'delivered');
                break;
            case 'delivered_on_time':
                // May-2026 fix — CAST to SIGNED; see $deliveredLate KPI.
                $q->where('li.qurbani_item_status', 'delivered')
                  ->whereNotNull('li.qurbani_delivered_at')
                  ->whereNotNull('li.qurbani_slot_end_minute')
                  ->whereRaw(
                      '(CAST(HOUR(li.qurbani_delivered_at) * 60 + MINUTE(li.qurbani_delivered_at) AS SIGNED) - CAST(li.qurbani_slot_end_minute AS SIGNED)) <= ?',
                      [$graceMin]
                  );
                break;
            case 'delivered_late':
                // May-2026 fix — CAST to SIGNED; see $deliveredLate KPI.
                $q->where('li.qurbani_item_status', 'delivered')
                  ->whereNotNull('li.qurbani_delivered_at')
                  ->whereNotNull('li.qurbani_slot_end_minute')
                  ->whereRaw(
                      '(CAST(HOUR(li.qurbani_delivered_at) * 60 + MINUTE(li.qurbani_delivered_at) AS SIGNED) - CAST(li.qurbani_slot_end_minute AS SIGNED)) > ?',
                      [$graceMin]
                  );
                break;
            case 'at_risk':
                $q->where('li.qurbani_item_status', 'slaughtered')
                  ->whereNull('li.qurbani_dispatched_at')
                  ->whereNotNull('li.qurbani_slot_end_minute')
                  ->whereRaw('li.qurbani_slot_end_minute BETWEEN ? AND ?', [$nowMin - 30, $nowMin + $atRiskWindow]);
                break;
            case 'self_collection_overdue':
                $q->whereRaw("LOWER(COALESCE(li.qurbani_delivery_type,'')) LIKE ?", ['%self%'])
                  ->where(function ($q2) {
                      $q2->whereNull('li.qurbani_item_status')
                         ->orWhereIn('li.qurbani_item_status', ['open', 'slaughtered']);
                  })
                  ->whereNotNull('li.qurbani_slot_end_minute')
                  ->whereRaw('li.qurbani_slot_end_minute < ?', [$nowMin - $graceMin]);
                break;
            case 'dispatch_gap':
                $q->where('li.qurbani_item_status', 'slaughtered')
                  ->whereNull('li.qurbani_dispatched_at');
                break;
            case 'slaughtered':
                // Same set as dispatch_gap in the normal lifecycle —
                // exposed under a separate metric id so the per-slot
                // table can label its column "Slaughtered" without
                // implying a different definition.
                $q->where('li.qurbani_item_status', 'slaughtered');
                break;
            case 'open':
                $q->where(function ($q2) {
                    $q2->whereNull('li.qurbani_item_status')
                       ->orWhere('li.qurbani_item_status', '')
                       ->orWhere('li.qurbani_item_status', 'open');
                });
                break;
            case 'per_slot_total':
                // Just the slot row; no further filter needed once
                // slot_end_minute has been applied above.
                break;
            case 'unread_wa':
                // May-2026 — CS Manager metric. Requires the WA join
                // (already conditionally applied above); if the env
                // doesn't have the WhatsApp tables this metric is
                // marked inactive in summary() so should never reach
                // here, but we hard-fail anyway to be safe.
                if (!$waTableAvailable) {
                    return response()->json(['success' => false, 'message' => 'WhatsApp tables not deployed in this environment'], 400);
                }
                $q->where('wac.wa_unread', '>', 0);
                break;
            case 'sla_delivered':
            case 'sla_out_for_delivery':
            case 'sla_slaughtered':
                // Phase 6 (May-2026) — drill into one cell of the
                // new Slot SLA buckets table. The metric encodes which
                // event we're scoring; the `sla_bucket` query param
                // (0-indexed) picks which bucket of that event. Same
                // SIGNED-cast delta math as slotSla() so a click and
                // the displayed count always match.
                $slaKey = substr($metric, 4); // strip "sla_"
                $events = $this->slaEvents();
                if (!isset($events[$slaKey])) {
                    return response()->json(['success' => false, 'message' => "Unknown SLA metric: {$metric}"], 400);
                }
                $cfg = $events[$slaKey];
                $bucketIdx = (int) $request->get('sla_bucket', -1);
                if ($bucketIdx < 0 || $bucketIdx >= count($cfg['buckets'])) {
                    return response()->json(['success' => false, 'message' => "Missing/invalid sla_bucket for {$metric}"], 400);
                }
                $col = $cfg['event_column'];
                $delta = "(CAST(HOUR(li.{$col}) * 60 + MINUTE(li.{$col}) AS SIGNED) - CAST(li.qurbani_slot_end_minute AS SIGNED))";
                $cond = $this->slaBucketSql($delta, $cfg['buckets'][$bucketIdx]);
                // Delivery only — mirrors the aggregate. Self-collection
                // bookings are filtered out so this drill list matches
                // the count the user clicked. We also re-apply the
                // status clause (e.g. delivered-only) and the slot
                // narrow that the caller already passed in.
                $q->where(function ($q2) {
                    $q2->whereNull('li.qurbani_delivery_type')
                       ->orWhereRaw("LOWER(COALESCE(li.qurbani_delivery_type,'')) NOT LIKE ?", ['%self%']);
                })
                ->whereNotNull('li.qurbani_slot_end_minute')
                ->whereRaw($cfg['status_clause'])
                ->whereRaw($cond);
                break;
            case 'delivered_on_promise':
            case 'delivered_late_promise':
            case 'delivered_very_late_promise':
                // May-2026 — promise-drift drill cluster. Drift =
                // delivered_at − (earliest WA OFD promise OR system
                // ETA fallback). Drift expression mirrors
                // loadDeliveredPromiseStats() exactly — keep them in
                // lock-step so the KPI counter and the drill list
                // never diverge. When the WA log table isn't
                // deployed we degrade gracefully to system-ETA only.
                $veryLatePromise = (int) ConfigModel::get(
                    'qurbani_wa_ofd_delay_threshold_minutes',
                    (string) self::DEFAULT_VERY_LATE_PROMISE_MIN
                );
                if ($veryLatePromise < 1) $veryLatePromise = self::DEFAULT_VERY_LATE_PROMISE_MIN;
                $driftExpr = $waLogAvailable
                    ? "TIMESTAMPDIFF(MINUTE, COALESCE(wf.first_messaged_eta_at, li.qurbani_estimated_delivery_at), li.qurbani_delivered_at)"
                    : "TIMESTAMPDIFF(MINUTE, li.qurbani_estimated_delivery_at, li.qurbani_delivered_at)";
                $q->where('li.qurbani_item_status', 'delivered')
                  ->whereNotNull('li.qurbani_delivered_at');
                if ($waLogAvailable) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('wf.first_messaged_eta_at')
                           ->orWhereNotNull('li.qurbani_estimated_delivery_at');
                    });
                } else {
                    $q->whereNotNull('li.qurbani_estimated_delivery_at');
                }
                if ($metric === 'delivered_on_promise') {
                    $q->whereRaw("{$driftExpr} BETWEEN ? AND ?", [-$graceMin, $graceMin]);
                } elseif ($metric === 'delivered_late_promise') {
                    $q->whereRaw("{$driftExpr} > ? AND {$driftExpr} <= ?", [$graceMin, $veryLatePromise]);
                } else {
                    $q->whereRaw("{$driftExpr} > ?", [$veryLatePromise]);
                }
                break;
            default:
                return response()->json(['success' => false, 'message' => "Unknown metric: {$metric}"], 400);
        }

        $selects = [
            'li.id as line_item_id',
            'li.order_id',
            'o.customer_id',
            'o.order_number',
            'o.order_status',
            DB::raw("CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) as customer_name"),
            'c.phone as customer_phone',
            'li.name as product_name',
            'li.quantity',
            'li.qurbani_day',
            'li.qurbani_slot',
            'li.qurbani_slot_end_minute',
            'li.qurbani_region',
            'li.qurbani_sub_region',
            'li.qurbani_delivery_type',
            'li.qurbani_item_status',
            'li.qurbani_slaughtered_at',
            'li.qurbani_out_for_delivery_at',
            'li.qurbani_delivered_at',
            'li.qurbani_estimated_delivery_at',
            'li.qurbani_eta_calculated_at',
            'li.qurbani_dispatched_at',
            'r.fullname as rider_name',
        ];
        if ($waTableAvailable) {
            $selects[] = 'wac.wa_unread';
            $selects[] = 'wac.wa_last_at';
            $selects[] = 'wac.wa_conv_id';
            $selects[] = 'wac.wa_phone as wa_phone';
        }
        if ($waLogAvailable) {
            $selects[] = 'wal.messaged_eta_at';
            $selects[] = 'wal.last_wa_trigger';
            $selects[] = 'wal.last_wa_sent_at';
            // May-2026 — Slaughter/OFD outcome columns for the CS
            // Manager action cells. Status can be sent/failed/skipped;
            // skip_reason carries the human-readable cause.
            $selects[] = 'wals.slaughter_wa_status';
            $selects[] = 'wals.slaughter_wa_sent_at';
            $selects[] = 'wals.slaughter_wa_skip_reason';
            $selects[] = 'walo.ofd_wa_status';
            $selects[] = 'walo.ofd_wa_sent_at';
            $selects[] = 'walo.ofd_wa_skip_reason';
            $selects[] = 'walo.ofd_wa_trigger';
            // May-2026 — EARLIEST OFD WA promise per line item (the
            // "what we first told the customer" snapshot). Used by
            // the promise-drift drill renderer to show "Promised X,
            // Delivered Y, Drift Z".
            $selects[] = 'wf.first_messaged_eta_at';
            $selects[] = 'wf.first_messaged_sent_at';
        }
        $rows = $q->select($selects)
            ->orderBy('li.qurbani_slot_end_minute')
            ->orderBy('o.order_number')
            ->limit(500)
            ->get();

        // May-2026 (ETA freshness) — drift threshold mirrors the
        // same config the auto-sender uses for its delay-update
        // detection, so the warning chip the CS manager sees here
        // matches the "needs delay update" signal in the planner.
        $driftThreshold = (int) ConfigModel::get('qurbani_wa_ofd_delay_threshold_minutes', '30');
        if ($driftThreshold < 1) $driftThreshold = 30;

        // Enrich each row with slot_compare so the table can show
        // the same ETA/delivered vs slot chip used elsewhere.
        $rows = $rows->map(function ($r) use ($graceMin, $driftThreshold) {
            $slotCompare = null;
            if ($r->qurbani_slot_end_minute !== null) {
                if ($r->qurbani_delivered_at) {
                    $slotCompare = QurbaniSlotParser::compareEventToSlot(
                        (string) $r->qurbani_delivered_at,
                        (int) $r->qurbani_slot_end_minute,
                        $graceMin,
                        'delivered'
                    );
                } elseif ($r->qurbani_estimated_delivery_at) {
                    $slotCompare = QurbaniSlotParser::compareEventToSlot(
                        (string) $r->qurbani_estimated_delivery_at,
                        (int) $r->qurbani_slot_end_minute,
                        $graceMin,
                        'eta'
                    );
                }
            }
            // ── ETA freshness + drift (May-2026) ───────────────────
            // Three signals the CS manager cares about:
            //   1. eta_age_minutes — how stale the *displayed* ETA is.
            //      Auto-refresh keeps this fresh, but if the page sat
            //      idle the chip will say "calc'd 27m ago".
            //   2. drift_minutes  — current ETA vs what we actually
            //      WhatsApped to the customer. Positive = current is
            //      later (customer's expectation is too early).
            //   3. drift_state — bucketed for UI colour:
            //        none     = no ETA on file, or never messaged
            //        in_sync  = within ±5 min of last messaged ETA
            //        drifting = >5 min but ≤ threshold (yellow)
            //        stale    = > threshold minutes (red — customer
            //                   needs an updated WhatsApp)
            $etaAgeMin = null;
            if ($r->qurbani_eta_calculated_at) {
                try {
                    $etaAgeMin = (int) Carbon::parse($r->qurbani_eta_calculated_at)
                        ->diffInMinutes(now(), false);
                    if ($etaAgeMin < 0) $etaAgeMin = 0;
                } catch (\Throwable $e) { $etaAgeMin = null; }
            }
            $messagedEta = $r->messaged_eta_at ?? null;
            $driftMinutes = null;
            $driftState   = 'none';
            if ($messagedEta && $r->qurbani_estimated_delivery_at) {
                try {
                    $msg = Carbon::parse($messagedEta);
                    $cur = Carbon::parse($r->qurbani_estimated_delivery_at);
                    // Positive = current is LATER than messaged → customer
                    // has an early-by-N-minutes expectation. Negative =
                    // current is earlier → customer expects later; not
                    // user-facing critical but still surfaced.
                    $driftMinutes = (int) $msg->diffInMinutes($cur, false);
                    $abs = abs($driftMinutes);
                    if ($abs <= 5)                  $driftState = 'in_sync';
                    elseif ($abs <= $driftThreshold) $driftState = 'drifting';
                    else                             $driftState = 'stale';
                } catch (\Throwable $e) {
                    $driftMinutes = null;
                    $driftState = 'none';
                }
            }

            // ── Promise drift (May-2026) ───────────────────────────
            // Mirrors enrichDeliveredPromiseDrift() in RiderController.
            // Drift = delivered − earliest WA promise (or system ETA
            // when no WA was sent). Surfaced on every delivered row so
            // the records table can show a chip alongside the slot
            // compare. Pre-computed here so the JS doesn't have to.
            // first_messaged_eta_at only exists on $r when the WA log
            // tables are deployed (we conditionally select it above);
            // the ?? null guard handles dev/test envs without WA.
            $promiseDrift = null;
            $firstMessaged = isset($r->first_messaged_eta_at) ? $r->first_messaged_eta_at : null;
            $deliveredAt = $r->qurbani_delivered_at ?? null;
            if ($deliveredAt) {
                $promiseSource = null;
                $promiseEta = null;
                if ($firstMessaged) {
                    $promiseSource = 'whatsapp';
                    $promiseEta = $firstMessaged;
                } elseif ($r->qurbani_estimated_delivery_at) {
                    $promiseSource = 'system_eta';
                    $promiseEta = $r->qurbani_estimated_delivery_at;
                }
                if ($promiseEta) {
                    try {
                        $promiseCarbon = Carbon::parse($promiseEta);
                        $actualCarbon = Carbon::parse($deliveredAt);
                        $driftMin = (int) round($promiseCarbon->diffInMinutes($actualCarbon, false));
                        $bucket = 'on_promise';
                        if ($driftMin > $graceMin) {
                            $bucket = $driftMin > $driftThreshold ? 'very_late' : 'late';
                        } elseif ($driftMin < -$graceMin) {
                            $bucket = 'early';
                        }
                        $promiseDrift = [
                            'promise_source'       => $promiseSource,
                            'promised_eta_at'      => (string) $promiseEta,
                            'promised_eta_display' => $promiseCarbon->format('h:i A'),
                            'delivered_at_display' => $actualCarbon->format('h:i A'),
                            'promised_sent_at'     => $r->first_messaged_sent_at ?? null,
                            'drift_minutes'        => $driftMin,
                            'drift_bucket'         => $bucket,
                        ];
                    } catch (\Throwable $e) {
                        $promiseDrift = null;
                    }
                }
            }

            return [
                'line_item_id'                  => (int) $r->line_item_id,
                'order_id'                      => (int) $r->order_id,
                'customer_id'                   => isset($r->customer_id) && $r->customer_id ? (int) $r->customer_id : null,
                'order_number'                  => $r->order_number,
                'order_status'                  => $r->order_status,
                'customer_name'                 => trim($r->customer_name) ?: 'Unknown',
                'customer_phone'                => $r->customer_phone,
                'product_name'                  => $r->product_name,
                'quantity'                      => (float) $r->quantity,
                'qurbani_day'                   => $r->qurbani_day,
                'qurbani_slot'                  => $r->qurbani_slot,
                'qurbani_slot_end_minute'       => $r->qurbani_slot_end_minute !== null ? (int) $r->qurbani_slot_end_minute : null,
                'qurbani_slot_end_display'      => $r->qurbani_slot_end_minute !== null ? QurbaniSlotParser::formatMinutes((int) $r->qurbani_slot_end_minute) : null,
                'qurbani_region'                => $r->qurbani_region,
                'qurbani_sub_region'            => $r->qurbani_sub_region,
                'qurbani_delivery_type'         => $r->qurbani_delivery_type,
                'qurbani_item_status'           => $r->qurbani_item_status,
                'qurbani_slaughtered_at'        => $r->qurbani_slaughtered_at,
                'qurbani_out_for_delivery_at'   => $r->qurbani_out_for_delivery_at,
                'qurbani_delivered_at'          => $r->qurbani_delivered_at,
                'qurbani_estimated_delivery_at' => $r->qurbani_estimated_delivery_at,
                'qurbani_eta_calculated_at'     => $r->qurbani_eta_calculated_at,
                'qurbani_dispatched_at'         => $r->qurbani_dispatched_at,
                'rider_name'                    => $r->rider_name,
                'slot_compare'                  => $slotCompare,
                // May-2026 — promise drift snapshot. Null when neither
                // a WA promise nor a system ETA exists, or row isn't
                // delivered yet.
                'promise_drift'                 => $promiseDrift,
                // May-2026 CS Manager enrichment. Always present in
                // the response shape so the JS can render the
                // WhatsApp action unconditionally (it just shows
                // a disabled badge when wa_unread === 0).
                'wa_unread'                     => isset($r->wa_unread) && $r->wa_unread !== null ? (int) $r->wa_unread : 0,
                'wa_last_at'                    => $r->wa_last_at ?? null,
                'wa_conversation_id'            => isset($r->wa_conv_id) && $r->wa_conv_id ? (int) $r->wa_conv_id : null,
                'wa_phone'                      => $r->wa_phone ?? null,
                // ETA freshness — the CS-manager guard against stale
                // customer information. messaged_eta_at is the ETA
                // value we WhatsApped to the customer (NULL when no
                // OFD message has been sent yet); current ETA is the
                // one Performance + planner currently display.
                'eta_age_minutes'               => $etaAgeMin,
                'messaged_eta_at'               => $messagedEta,
                'last_wa_sent_at'               => $r->last_wa_sent_at ?? null,
                'last_wa_trigger'               => $r->last_wa_trigger ?? null,
                'eta_drift_minutes'             => $driftMinutes,
                'eta_drift_state'               => $driftState,
                // May-2026 — Slaughter / OFD WA outcome surfacing.
                // status NULL when no log row exists for that trigger
                // (i.e. message never attempted); otherwise one of
                // sent / failed / skipped. UI renders a coloured chip
                // + timestamp + (for failed/skipped) the reason.
                'slaughter_wa_status'           => $r->slaughter_wa_status ?? null,
                'slaughter_wa_sent_at'          => $r->slaughter_wa_sent_at ?? null,
                'slaughter_wa_skip_reason'      => $r->slaughter_wa_skip_reason ?? null,
                'ofd_wa_status'                 => $r->ofd_wa_status ?? null,
                'ofd_wa_sent_at'                => $r->ofd_wa_sent_at ?? null,
                'ofd_wa_skip_reason'            => $r->ofd_wa_skip_reason ?? null,
                'ofd_wa_trigger'                => $r->ofd_wa_trigger ?? null,
            ];
        })->all();

        // ETA-drift summary so the UI can show a single "N customers
        // have stale ETAs" pill in the records header without having
        // to scan all rows in JS.
        $staleCount    = 0;
        $driftingCount = 0;
        foreach ($rows as $row) {
            if ($row['eta_drift_state'] === 'stale')    $staleCount++;
            if ($row['eta_drift_state'] === 'drifting') $driftingCount++;
        }

        return response()->json([
            'success' => true,
            'metric'  => $metric,
            'count'   => count($rows),
            'rows'    => $rows,
            'eta_drift' => [
                'threshold_minutes' => $driftThreshold,
                'stale_count'       => $staleCount,
                'drifting_count'    => $driftingCount,
            ],
        ]);
    }

    /**
     * POST /qurbani/api/performance/send-wa-now
     *
     * May-2026 — CS Manager quick-action endpoint. Fires the
     * slaughter OR OFD WhatsApp template for one line item NOW,
     * bypassing the auto-worker's time-delay gate but respecting
     * every other gate (master switch, trigger enabled, template
     * configured, customer phone). Used by the 🔪 Slaughter and
     * 🛵 OFD action buttons in the Performance drill table.
     *
     * Body:
     *   { line_item_id: int, trigger: 'slaughter'|'ofd', force?: bool }
     *
     * force=true bypasses the already-sent dedupe ONLY — every other
     * gate still applies. Use carefully: a re-send replaces the
     * customer's last-known ETA in the drift calc and can cause a
     * yo-yo if the ETA hasn't actually moved.
     */
    public function sendWaNow(Request $request, \App\Services\QurbaniWaAutoSender $sender)
    {
        $validated = $request->validate([
            'line_item_id' => 'required|integer',
            'trigger'      => 'required|string|in:slaughter,ofd',
            'force'        => 'nullable|boolean',
        ]);
        $lineItemId = (int) $validated['line_item_id'];
        $trigger    = (string) $validated['trigger'];
        $force      = (bool) ($validated['force'] ?? false);

        try {
            if ($trigger === 'slaughter') {
                $result = $sender->sendSlaughterForLineItem($lineItemId, $force);
            } else {
                $result = $sender->sendOfdForLineItem($lineItemId, $force);
            }
            return response()->json([
                'success' => (bool) ($result['ok'] ?? false),
                'reason'  => $result['reason'] ?? 'unknown',
                'message' => $result['message'] ?? '',
                'phone'   => $result['phone'] ?? null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Performance sendWaNow failed', [
                'line_item_id' => $lineItemId,
                'trigger'      => $trigger,
                'err'          => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'reason'  => 'exception',
                'message' => 'Send failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /qurbani/api/performance/day-state
     *
     * Action keys:
     *   - activate (with optional `day` value, default = current day)
     *   - close    (sets `current_day` to current+1 and active = 0)
     *   - reset    (clears state — used during testing)
     */
    public function setDayState(Request $request)
    {
        $action = strtolower((string) $request->get('action', ''));
        $current = $this->loadDayState();
        $now = now();
        switch ($action) {
            case 'activate':
                $day = $request->get('day', $current['current_day'] ?: 1);
                ConfigModel::set('qurbani_ops_active', '1', 'Operational day flag — when 1, late/at-risk math is meaningful.');
                ConfigModel::set('qurbani_ops_current_day', (string) $day, 'Active operational day number.');
                ConfigModel::set('qurbani_ops_active_since', $now->toDateTimeString(), 'Datetime the current day was activated.');
                break;
            case 'close':
                $next = max(1, ((int) ($current['current_day'] ?? 0)) + 1);
                ConfigModel::set('qurbani_ops_active', '0', 'Operational day flag — when 1, late/at-risk math is meaningful.');
                ConfigModel::set('qurbani_ops_current_day', (string) $next, 'Active operational day number.');
                ConfigModel::set('qurbani_ops_active_since', '', 'Datetime the current day was activated.');
                break;
            case 'reset':
                ConfigModel::set('qurbani_ops_active', '0', 'Operational day flag — when 1, late/at-risk math is meaningful.');
                ConfigModel::set('qurbani_ops_current_day', '0', 'Active operational day number.');
                ConfigModel::set('qurbani_ops_active_since', '', 'Datetime the current day was activated.');
                break;
            default:
                return response()->json(['success' => false, 'message' => "Unknown action: {$action}"], 400);
        }
        return response()->json(['success' => true, 'day_state' => $this->loadDayState()]);
    }

    /**
     * Read the day-state config bundle.
     */
    private function loadDayState(): array
    {
        $active     = ConfigModel::get('qurbani_ops_active', '0');
        $currentDay = ConfigModel::get('qurbani_ops_current_day', '0');
        $since      = ConfigModel::get('qurbani_ops_active_since', '');
        return [
            'active'        => (int) $active,
            'current_day'   => (int) $currentDay,
            'active_since'  => $since ?: null,
        ];
    }

    /**
     * May-2026 — Promise-drift aggregator for the Performance summary.
     *
     * For every delivered line item in scope (day filter respected),
     * computes drift between actual delivered_at and the customer-
     * facing promise. Promise source:
     *   1. EARLIEST t_ops_qurbani_wa_log.delivery_time_used where
     *      trigger ∈ (ofd, ofd_delay_update) AND status='sent' AND
     *      delivery_time_used IS NOT NULL — the first time the
     *      customer was actually told a delivery time.
     *   2. Falls back to qurbani_estimated_delivery_at when no WA
     *      message ever went out (test mode / no phone / template off
     *      / WA tables not deployed). Counted under no_promise=0 but
     *      INCLUDED in the buckets so the KPI matches what the rider-
     *      route Delivered redesign shows.
     *
     * Buckets use the same grace + very-late thresholds as the
     * rider-route enrichDeliveredPromiseDrift() helper so the page
     * and per-rider view agree on every "on promise / late / very
     * late" classification.
     *
     * Returns: ['on', 'late', 'very_late', 'early', 'no_promise',
     *           'count', 'avg'].
     */
    private function loadDeliveredPromiseStats(?string $day, int $graceMin, int $veryLateMin): array
    {
        $out = [
            'on'         => 0,
            'late'       => 0,
            'very_late'  => 0,
            'early'      => 0,
            'no_promise' => 0,
            'count'      => 0,
            'avg'        => 0,
        ];
        $waLogAvailable = Schema::hasTable('t_ops_qurbani_wa_log');

        $q = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->where(function ($q2) {
                $q2->whereNull('o.order_status')
                   ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            })
            ->where('li.qurbani_item_status', 'delivered')
            ->whereNotNull('li.qurbani_delivered_at');
        if ($day) {
            $q->where('li.qurbani_day', $day);
        }

        $selects = [
            'li.id as line_item_id',
            'li.qurbani_delivered_at',
            'li.qurbani_estimated_delivery_at',
        ];
        if ($waLogAvailable) {
            // Earliest sent OFD per line item (matches the rider-
            // route helper's "earliest is the original promise" rule).
            $q->leftJoin(DB::raw(
                "(SELECT l.line_item_id, "
                . "l.delivery_time_used AS first_messaged_eta_at "
                . "FROM t_ops_qurbani_wa_log l "
                . "INNER JOIN ("
                . "  SELECT line_item_id, MIN(id) AS mn "
                . "  FROM t_ops_qurbani_wa_log "
                . "  WHERE trigger_event IN ('ofd','ofd_delay_update') "
                . "    AND status = 'sent' "
                . "    AND delivery_time_used IS NOT NULL "
                . "  GROUP BY line_item_id"
                . ") mn ON mn.line_item_id = l.line_item_id AND mn.mn = l.id "
                . "WHERE l.trigger_event IN ('ofd','ofd_delay_update') "
                . "  AND l.status = 'sent' "
                . "  AND l.delivery_time_used IS NOT NULL"
                . ") as wf"
            ), 'wf.line_item_id', '=', 'li.id');
            $selects[] = 'wf.first_messaged_eta_at';
        }

        $rows = $q->select($selects)->get();
        $sum = 0;
        $countWithDrift = 0;
        foreach ($rows as $r) {
            $promiseEta = null;
            $hasWaPromise = !empty($r->first_messaged_eta_at);
            if ($hasWaPromise) {
                $promiseEta = $r->first_messaged_eta_at;
            } elseif (!empty($r->qurbani_estimated_delivery_at)) {
                $promiseEta = $r->qurbani_estimated_delivery_at;
                $out['no_promise']++;   // no WA promise sent — fell back to system ETA
            } else {
                continue;               // genuinely no promise on record — exclude
            }
            try {
                $driftMin = (int) round(
                    Carbon::parse($promiseEta)->diffInMinutes(Carbon::parse($r->qurbani_delivered_at), false)
                );
            } catch (\Throwable $e) {
                continue;
            }
            if ($driftMin > $veryLateMin)      $out['very_late']++;
            elseif ($driftMin > $graceMin)     $out['late']++;
            elseif ($driftMin < -$graceMin)    $out['early']++;
            else                               $out['on']++;
            $sum += $driftMin;
            $countWithDrift++;
        }
        $out['count'] = $countWithDrift;
        $out['avg'] = $countWithDrift > 0 ? (int) round($sum / $countWithDrift) : 0;
        return $out;
    }
}
