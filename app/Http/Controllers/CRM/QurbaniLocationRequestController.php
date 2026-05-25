<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\QurbaniLocationRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * QurbaniLocationRequestController — Phase 6 (May-2026)
 * =====================================================
 *
 * HTTP surface that backs the "Request Location" feature on:
 *   - Qurbani Orders web page (toolbar button + 2 modals).
 *   - Qurbani Open Orders mobile screen (individual send only).
 *
 * Endpoint map (all under /qurbani/api/loc-request):
 *   GET   /eligible           — list customers we could request from
 *                                (matches filter, missing verified pin).
 *   POST  /send-bulk          — queue a batch of requests.
 *   POST  /bulk/{batchId}/start — drain queued rows synchronously
 *                                  (returns counters per chunk).
 *   POST  /send-one           — queue + send a single request inline
 *                                (used by per-card button + mobile).
 *   GET   /summary            — counters for the toolbar badge.
 *   GET   /batch/{batchId}    — per-batch progress detail.
 *   GET   /pending-review     — replied-but-not-saved rows for the
 *                                 Reviewer drawer.
 *   POST  /save/{id}          — push one row's location to the customer.
 *   POST  /save-all           — bulk save filtered rows.
 *   POST  /dismiss/{id}       — mark a reply as junk (won't save).
 *   GET   /statuses           — per-customer latest request status for
 *                                 the order cards on the Orders page.
 */
class QurbaniLocationRequestController extends Controller
{
    protected QurbaniLocationRequestService $svc;

    public function __construct(QurbaniLocationRequestService $svc)
    {
        $this->svc = $svc;
    }

    // -----------------------------------------------------------------
    // ELIGIBLE — what the bulk-send modal shows.
    // -----------------------------------------------------------------

    /**
     * Returns the candidate customers for a bulk send. Filters mirror
     * the existing Qurbani Orders filter panel.
     *
     * Eligibility rule (intentionally strict, matching the user spec):
     *   - Customer is on a non-cancelled, non-deleted qurbani order
     *     in the requested filter window.
     *   - Customer has NO lat/lng pin (has_verified_location = false).
     *     A customer with only a verified_location_url is treated as
     *     unverified, matching getOrderItems()'s has_verified_location
     *     semantics in QurbaniWebController.
     *
     * Each row also carries that customer's most recent prior request
     * status, so the staff can spot "already sent yesterday, no reply"
     * and re-tick to re-send.
     */
    public function eligible(Request $request)
    {
        $filters = $this->parseFilters($request);

        try {
            $rows = $this->buildEligibleQuery($filters)
                ->limit(5000)  // sanity cap, applied BEFORE customer-level collapse below
                ->get();

            // Collapse to ONE ROW PER CUSTOMER.
            // -------------------------------------------------------
            // Same customer can show up many times in $rows: one row
            // per qualifying line item, so a customer with 2 hissas +
            // 1 goat across 2 orders ends up as 3 rows. The UI used
            // to show all 3 (visually misleading — looked like we'd
            // send 3 WhatsApps) and queueBulk() de-duped under the
            // hood, sending only 1.
            //
            // We now collapse here so:
            //   - the count, the table, and the actual sends agree
            //     on "this is N customers, we will send N WhatsApps".
            //   - the per-customer row carries aggregated context
            //     (distinct regions / sub-regions / days / slots they
            //     appear in) for at-a-glance disambiguation.
            //   - sendBulk() picks the most-recent line item per
            //     customer for the request row's context fields, so
            //     the Reviewer drawer always has SOMETHING to group
            //     by even when a customer spans multiple regions.
            $collapsed = [];
            foreach ($rows as $r) {
                $cid = (int) $r->customer_id;
                if (!isset($collapsed[$cid])) {
                    $collapsed[$cid] = [
                        'customer_id'    => $cid,
                        'customer_name'  => $r->customer_name,
                        'phone'          => $r->phone,
                        'order_ids'      => [],
                        'order_numbers'  => [],
                        'line_item_ids'  => [],
                        'regions'        => [],
                        'sub_regions'    => [],
                        'days'           => [],
                        'slots'          => [],
                        'delivery_types' => [],
                        'categories'     => [],
                    ];
                }
                $bag = &$collapsed[$cid];
                if ($r->order_id     && !in_array($r->order_id, $bag['order_ids'], true))         $bag['order_ids'][]     = (int) $r->order_id;
                if ($r->order_number && !in_array($r->order_number, $bag['order_numbers'], true)) $bag['order_numbers'][] = $r->order_number;
                if ($r->line_item_id && !in_array($r->line_item_id, $bag['line_item_ids'], true)) $bag['line_item_ids'][] = (int) $r->line_item_id;
                if ($r->qurbani_region && !in_array($r->qurbani_region, $bag['regions'], true))       $bag['regions'][]        = $r->qurbani_region;
                if ($r->qurbani_sub_region && !in_array($r->qurbani_sub_region, $bag['sub_regions'], true)) $bag['sub_regions'][] = $r->qurbani_sub_region;
                if ($r->qurbani_day  && !in_array($r->qurbani_day, $bag['days'], true))           $bag['days'][]   = $r->qurbani_day;
                if ($r->qurbani_slot && !in_array($r->qurbani_slot, $bag['slots'], true))         $bag['slots'][]  = $r->qurbani_slot;
                if ($r->qurbani_delivery_type && !in_array($r->qurbani_delivery_type, $bag['delivery_types'], true)) $bag['delivery_types'][] = $r->qurbani_delivery_type;
                if ($r->category_level_2 && !in_array($r->category_level_2, $bag['categories'], true)) $bag['categories'][] = $r->category_level_2;
                unset($bag);
            }

            $customerIds = array_keys($collapsed);
            $latest = $this->svc->latestForCustomers($customerIds);

            $items = [];
            foreach ($collapsed as $cid => $c) {
                $c['orders_count']      = count($c['order_ids']);
                $c['line_items_count']  = count($c['line_item_ids']);
                $c['last_request']      = $latest[$cid] ?? null;
                $items[] = $c;
            }

            // Stable sort: customers with NO prior request first
            // (most actionable), then those messaged longest ago.
            usort($items, function ($a, $b) {
                $la = $a['last_request']['sent_at'] ?? null;
                $lb = $b['last_request']['sent_at'] ?? null;
                if (!$la && !$lb) return strcmp($a['customer_name'] ?? '', $b['customer_name'] ?? '');
                if (!$la) return -1;
                if (!$lb) return 1;
                return strcmp($la, $lb); // older sent_at first
            });

            // May-2026 — stats block alongside the eligible list so the
            // bulk modal can show "X verified / Y unverified / Z awaiting
            // reply" without a second round-trip. Only the verified-vs-
            // unverified split needs server work (eligible is already
            // unverified-only); the awaiting/never-asked/replied
            // breakdown is computed client-side from the items array
            // (each item carries its last_request status).
            //
            // We re-run the eligibility query WITHOUT the
            // "missing-pin" predicate to count the verified customers
            // in the same filter set. Cheaper than a separate
            // join-and-aggregate because the same filters are applied.
            $totalCustomers = (int) $this->buildEligibleQuery($filters, includeVerified: true)
                ->distinct()
                ->count(DB::raw('cust.id'));
            $unverifiedCustomers = count($items);
            $verifiedCustomers = max(0, $totalCustomers - $unverifiedCustomers);

            return response()->json([
                'success' => true,
                'count'   => count($items),
                'items'   => $items,
                'filters' => $filters,
                'stats'   => [
                    'total_customers'      => $totalCustomers,
                    'verified_customers'   => $verifiedCustomers,
                    'unverified_customers' => $unverifiedCustomers,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('QurbaniLocReq: eligible failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------
    // SEND — bulk + single.
    // -----------------------------------------------------------------

    /**
     * Queue a batch. Body:
     *   { customer_ids: [int,...],
     *     // OR a filter — if customer_ids is empty we run eligible()
     *     // and queue all of them (used by "Send All Matching" button).
     *     batch_label?: string,
     *     ...filter fields
     *   }
     *
     * Does NOT send — returns the batch_id so the client can poll
     * /bulk/{batchId}/start in chunks to actually fire the WhatsApp
     * calls without blocking the HTTP request for too long.
     */
    public function sendBulk(Request $request)
    {
        try {
            $userId = auth()->id();
            $customerIds = (array) $request->input('customer_ids', []);
            $filters = $this->parseFilters($request);

            // Build the row payloads.
            // We always re-run the eligible query (keyed by customer_id)
            // and use it as the source of truth for the qurbani_* context
            // fields, so the client can send just [customer_id, ...] without
            // having to round-trip the full filter context per row.
            $eligibleQ = $this->buildEligibleQuery($filters);
            if (!empty($customerIds)) {
                $eligibleQ->whereIn('cust.id', array_map('intval', $customerIds));
            }
            $eligibleRows = $eligibleQ->get();

            if ($eligibleRows->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No eligible customers match those filters/selection.',
                ], 422);
            }

            $payload = $eligibleRows->map(function ($r) {
                return [
                    'customer_id'           => (int) $r->customer_id,
                    'order_id'              => (int) $r->order_id,
                    'line_item_id'          => (int) $r->line_item_id,
                    'wa_phone'              => $r->phone,
                    'qurbani_day'           => $r->qurbani_day,
                    'qurbani_slot'          => $r->qurbani_slot,
                    'qurbani_region'        => $r->qurbani_region,
                    'qurbani_sub_region'    => $r->qurbani_sub_region,
                    'qurbani_delivery_type' => $r->qurbani_delivery_type,
                    'category_level_2'      => $r->category_level_2,
                ];
            })->all();

            $label = $request->input('batch_label')
                ?: $this->buildBatchLabel($filters, count($payload));

            $res = $this->svc->queueBulk($payload, $label, $userId);

            return response()->json([
                'success'     => true,
                'batch_id'    => $res['batch_id'],
                'batch_label' => $res['batch_label'],
                'queued'      => $res['queued'],
                'skipped_no_phone'  => $res['skipped_no_phone'],
                'skipped_duplicate' => $res['skipped_duplicate'],
            ]);
        } catch (\Throwable $e) {
            Log::error('QurbaniLocReq: sendBulk failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Drain queued rows for a batch synchronously. Client polls this
     * with the same batch_id repeatedly until "remaining" == 0. Each
     * call processes at most config.sync_http_cap rows (default 100)
     * so a slow batch can't blow the request timeout.
     */
    public function startBatch(Request $request, string $batchId)
    {
        try {
            $cap = (int) (config('qurbani.location_request.sync_http_cap', 100));
            $counters = $this->svc->processQueued($batchId, $cap);
            $detail = $this->svc->batchDetails($batchId);

            $remaining = $detail['queued'] + $detail['sending'];

            return response()->json([
                'success'   => true,
                'chunk'     => $counters,
                'batch'     => $detail,
                'remaining' => $remaining,
                'done'      => $remaining === 0,
            ]);
        } catch (\Throwable $e) {
            Log::error('QurbaniLocReq: startBatch failed', [
                'batch_id' => $batchId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Per-card / mobile single send. Queue ONE row and immediately
     * drain it (we don't bother batching one customer).
     */
    public function sendOne(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_id'  => 'required|integer',
                'order_id'     => 'nullable|integer',
                'line_item_id' => 'nullable|integer',
            ]);

            $userId = auth()->id() ?? ($request->user() ? $request->user()->id : null);

            // Fetch the line item's filter context so the new row carries
            // it — this powers the per-batch filtering on the Reviewer
            // drawer even for individual sends. We pull region/sub-region
            // from the line item (matching the Orders page semantics) and
            // fall back to the order header when missing.
            //
            // category_level_2 lives on t_crm_prod_product.attribute_2
            // (NOT on the line item) — mirrors the JOIN that
            // buildEligibleQuery() and QurbaniWebController do.
            $ctx = null;
            if (!empty($validated['line_item_id'])) {
                $ctx = DB::table('t_crm_prod_order_line_item as li')
                    ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
                    ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                    ->where('li.id', $validated['line_item_id'])
                    ->select(
                        'li.qurbani_day', 'li.qurbani_slot',
                        'li.qurbani_delivery_type',
                        'p.attribute_2 as category_level_2',
                        DB::raw('COALESCE(NULLIF(li.qurbani_region, \'\'), o.qurbani_region) as qurbani_region'),
                        DB::raw('COALESCE(NULLIF(li.qurbani_sub_region, \'\'), o.qurbani_sub_region) as qurbani_sub_region')
                    )
                    ->first();
            }

            $params = [
                'customer_id'           => (int) $validated['customer_id'],
                'order_id'              => $validated['order_id'] ?? null,
                'line_item_id'          => $validated['line_item_id'] ?? null,
                'qurbani_day'           => $ctx->qurbani_day ?? null,
                'qurbani_slot'          => $ctx->qurbani_slot ?? null,
                'qurbani_region'        => $ctx->qurbani_region ?? null,
                'qurbani_sub_region'    => $ctx->qurbani_sub_region ?? null,
                'qurbani_delivery_type' => $ctx->qurbani_delivery_type ?? null,
                'category_level_2'      => $ctx->category_level_2 ?? null,
                'sent_by'               => $userId,
            ];

            $newId = $this->svc->queueOne($params);

            // Drain just this one row.
            $counters = $this->svc->processQueued(null, 1);

            // Re-read the row so we can tell the caller exactly what
            // happened (sent / failed / skipped).
            $row = DB::table(QurbaniLocationRequestService::TABLE)
                ->where('id', $newId)
                ->first();

            return response()->json([
                'success'       => $row && $row->status === 'sent',
                'request_id'    => $newId,
                'status'        => $row->status ?? 'unknown',
                'error_message' => $row->error_message ?? null,
                'counters'      => $counters,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('QurbaniLocReq: sendOne failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------
    // SUMMARY / DETAIL / STATUSES — read endpoints.
    // -----------------------------------------------------------------

    public function summary(Request $request)
    {
        $days = (int) $request->query('days', 30);
        return response()->json([
            'success' => true,
            'data'    => $this->svc->summary($days),
        ]);
    }

    public function batchDetail(Request $request, string $batchId)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->svc->batchDetails($batchId),
        ]);
    }

    public function pendingReview(Request $request)
    {
        $filter = [
            'batch_id' => $request->query('batch_id'),
            'region'   => $request->query('region'),
            'day'      => $request->query('day'),
            'days'     => $request->query('days'),
        ];
        $rows = $this->svc->pendingReview(array_filter($filter), 500);
        return response()->json([
            'success' => true,
            'count'   => count($rows),
            'items'   => $rows,
        ]);
    }

    /**
     * Per-customer latest-request-status — used inline on the order
     * cards so the UI can render "Sent 12m ago, no reply" / "Reply
     * pending review" badges next to the Request Location button.
     */
    public function statuses(Request $request)
    {
        $ids = (array) $request->input('customer_ids', []);
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return response()->json(['success' => true, 'data' => []]);
        }
        return response()->json([
            'success' => true,
            'data'    => $this->svc->latestForCustomers($ids),
        ]);
    }

    // -----------------------------------------------------------------
    // SAVE / DISMISS — Reviewer drawer.
    // -----------------------------------------------------------------

    public function save(Request $request, int $id)
    {
        try {
            $userId = auth()->id();
            $force  = (bool) $request->input('force', false);
            $res = $this->svc->saveToCustomer($id, $userId, $force);
            return response()->json([
                'success'        => $res['saved'],
                'skipped_reason' => $res['skipped_reason'],
                'customer_id'    => $res['customer_id'],
            ]);
        } catch (\Throwable $e) {
            Log::error('QurbaniLocReq: save failed', [
                'id' => $id, 'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function saveAll(Request $request)
    {
        try {
            $userId = auth()->id();
            $force  = (bool) $request->input('force', false);
            $filter = [];
            if ($b = $request->input('batch_id')) { $filter['batch_id'] = $b; }
            if ($ids = (array) $request->input('ids', [])) {
                $filter['ids'] = array_map('intval', array_filter($ids));
            }
            if (empty($filter)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Must specify batch_id or ids',
                ], 422);
            }
            $res = $this->svc->saveAllReplied($filter, $userId, $force);
            return response()->json([
                'success' => true,
                'saved'   => $res['saved'],
                'skipped' => $res['skipped'],
                'details' => $res['details'],
            ]);
        } catch (\Throwable $e) {
            Log::error('QurbaniLocReq: saveAll failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function dismiss(Request $request, int $id)
    {
        try {
            $userId = auth()->id();
            $reason = $request->input('reason');
            $ok = $this->svc->dismissReply($id, $userId, $reason);
            return response()->json(['success' => $ok]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // -----------------------------------------------------------------
    // INTERNAL HELPERS
    // -----------------------------------------------------------------

    protected function parseFilters(Request $request): array
    {
        return [
            'day'           => $request->query('day') ?: $request->input('day'),
            'slot'          => $request->query('slot') ?: $request->input('slot'),
            'region'        => $request->query('region') ?: $request->input('region'),
            'sub_region'    => $request->query('sub_region') ?: $request->input('sub_region'),
            'delivery_type' => $request->query('delivery_type') ?: $request->input('delivery_type'),
            'category'      => $request->query('category') ?: $request->input('category'),
            'include_delivered' => (bool) ($request->query('include_delivered')
                ?? $request->input('include_delivered', false)),
        ];
    }

    /**
     * Core eligibility query — REUSED by /eligible and /send-bulk so
     * the two are guaranteed to agree on which customers will receive
     * the template.
     *
     * Inclusion rules:
     *   - Order is a qurbani order (line item has qurbani_day).
     *   - Order is NOT cancelled. We mirror QurbaniWebController's
     *     "LOWER(o.order_status) <> 'cancelled'" guard so the eligible
     *     set matches what staff sees on the Orders page.
     *   - Customer has NO lat/lng pin (has_verified_location semantics
     *     from QurbaniWebController::getOrderItems — a customer with
     *     only a verified_location_url is still "unverified" here).
     *   - Customer has a phone number (otherwise we can't WhatsApp them).
     *   - Line item is not delivered (unless include_delivered=true).
     *
     * We use `li.qurbani_region` / `li.qurbani_sub_region` to match
     * the existing Orders-page filtering (line 1256 of
     * QurbaniWebController::getOrderItems).
     */
    protected function buildEligibleQuery(array $filters, bool $includeVerified = false)
    {
        // category_level_2 lives on t_crm_prod_product.attribute_2 —
        // the existing Orders page joins through product to filter by
        // category, so we mirror that join here for consistency.
        $q = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o',     'o.id',  '=', 'li.order_id')
            ->join('t_crm_prod_customer as cust', 'cust.id', '=', 'o.customer_id')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
            ->whereNotNull('li.qurbani_day')
            ->where(function ($w) {
                $w->whereNull('o.order_status')
                  ->orWhereRaw("LOWER(o.order_status) <> 'cancelled'");
            })
            ->whereNotNull('cust.phone')
            ->where('cust.phone', '<>', '');

        // The unverified-only predicate is the difference between the
        // "send candidate" set (the modal's table) and the "total in
        // filter" set used to compute the verified/unverified split for
        // the stats strip. Toggled off when stats() asks for the
        // unscoped totals.
        if (!$includeVerified) {
            $q->where(function ($w) {
                $w->whereNull('cust.latitude')
                  ->orWhereNull('cust.longitude')
                  ->orWhere('cust.latitude', '=', 0)
                  ->orWhere('cust.longitude', '=', 0);
            });
        }

        if (empty($filters['include_delivered'])) {
            $q->where(function ($w) {
                $w->whereNull('li.qurbani_item_status')
                  ->orWhere('li.qurbani_item_status', '<>', 'delivered');
            });
        }
        if (!empty($filters['day']))           { $q->where('li.qurbani_day', $filters['day']); }
        if (!empty($filters['slot']))          { $q->where('li.qurbani_slot', $filters['slot']); }
        if (!empty($filters['delivery_type'])) { $q->where('li.qurbani_delivery_type', $filters['delivery_type']); }
        if (!empty($filters['category']))      { $q->where('p.attribute_2', $filters['category']); }
        if (!empty($filters['region']))        { $q->where('li.qurbani_region', $filters['region']); }
        if (!empty($filters['sub_region']))    { $q->where('li.qurbani_sub_region', $filters['sub_region']); }

        return $q->select(
            'cust.id as customer_id',
            DB::raw("TRIM(CONCAT(COALESCE(cust.first_name,''), ' ', COALESCE(cust.last_name,''))) as customer_name"),
            'cust.phone',
            'o.id as order_id',
            'o.order_number',
            'li.id as line_item_id',
            'li.qurbani_day',
            'li.qurbani_slot',
            'li.qurbani_region',
            'li.qurbani_sub_region',
            'li.qurbani_delivery_type',
            'p.attribute_2 as category_level_2',
            'li.qurbani_type'
        );
    }

    protected function buildBatchLabel(array $filters, int $count): string
    {
        $bits = [];
        if (!empty($filters['region']))     { $bits[] = $filters['region']; }
        if (!empty($filters['sub_region'])) { $bits[] = $filters['sub_region']; }
        if (!empty($filters['day']))        { $bits[] = $filters['day']; }
        if (!empty($filters['slot']))       { $bits[] = $filters['slot']; }
        if (!empty($filters['category']))   { $bits[] = $filters['category']; }
        $ctx = implode(' / ', $bits) ?: 'All';
        return sprintf('Bulk: %s (%d)', $ctx, $count);
    }
}
