<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\FIN\ConfigModel;
use App\Services\QurbaniSlotParser;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ],
            'per_slot' => $perSlot,
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

        $q = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('t_sys_user as r', 'r.id', '=', 'li.qurbani_assigned_rider_user_id')
            ->where(function ($q2) {
                $q2->whereNull('o.order_status')
                   ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            });
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
            default:
                return response()->json(['success' => false, 'message' => "Unknown metric: {$metric}"], 400);
        }

        $rows = $q->select([
                'li.id as line_item_id',
                'li.order_id',
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
                'li.qurbani_dispatched_at',
                'r.fullname as rider_name',
            ])
            ->orderBy('li.qurbani_slot_end_minute')
            ->orderBy('o.order_number')
            ->limit(500)
            ->get();

        // Enrich each row with slot_compare so the table can show
        // the same ETA/delivered vs slot chip used elsewhere.
        $rows = $rows->map(function ($r) use ($graceMin) {
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
            return [
                'line_item_id'                  => (int) $r->line_item_id,
                'order_id'                      => (int) $r->order_id,
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
                'qurbani_dispatched_at'         => $r->qurbani_dispatched_at,
                'rider_name'                    => $r->rider_name,
                'slot_compare'                  => $slotCompare,
            ];
        })->all();

        return response()->json([
            'success' => true,
            'metric'  => $metric,
            'count'   => count($rows),
            'rows'    => $rows,
        ]);
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
}
