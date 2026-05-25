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
