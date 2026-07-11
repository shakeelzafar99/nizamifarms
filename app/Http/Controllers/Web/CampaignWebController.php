<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\WhatsAppService;
use App\Services\CampaignFilterService;
use App\Models\CRM\CustomerModel;
use Carbon\Carbon;

class CampaignWebController extends Controller
{
    protected CampaignFilterService $filterService;

    public function __construct(CampaignFilterService $filterService)
    {
        $this->filterService = $filterService;
    }

    public function index()
    {
        return view('pages.campaigns.index');
    }

    public function getCampaigns()
    {
        $campaigns = DB::table('t_crm_campaigns')
            ->select(
                't_crm_campaigns.*',
                DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'pending') as pending_count"),
                DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'skipped') as skipped_count"),
                DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'failed') as failed_count")
            )
            ->orderByRaw("FIELD(status, 'active', 'ended')")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'campaigns' => $campaigns]);
    }

    public function getTemplates()
    {
        $templates = DB::table('t_wa_templates')
            ->where('status', 'approved')
            ->orderBy('display_name')
            ->get();

        return response()->json(['success' => true, 'templates' => $templates]);
    }

    public function getCities()
    {
        $cities = DB::table('t_crm_prod_customer')
            ->whereNull('merged_into_customer_id')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city', DB::raw('COUNT(*) as count'))
            ->groupBy('city')
            ->orderBy('count', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'cities' => $cities]);
    }

    /**
     * Distinct years that have at least one Qurbani order, combining the
     * current orders table and the history table (same definition used
     * by the Qurbani Dashboard so filter results line up with what the
     * user sees there).
     */
    public function getQurbaniYears()
    {
        $years = collect();

        // Years from current orders (qurbani_day set OR line item with attribute_1=qurbani)
        $current = DB::table('t_crm_prod_order as o')
            ->where(function ($q) {
                $q->whereNotNull('o.qurbani_day')
                  ->orWhereExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item as li')
                          ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                          ->whereColumn('li.order_id', 'o.id')
                          ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                  });
            })
            ->select(DB::raw('DISTINCT YEAR(o.order_date) as year'))
            ->pluck('year');
        $years = $years->merge($current);

        // Years from history table (name/sku match), if the table exists
        if (Schema::hasTable('t_crm_history_order') && Schema::hasTable('t_crm_history_order_line_item')) {
            $hist = DB::table('t_crm_history_order as ho')
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_history_order_line_item as hli')
                        ->whereColumn('hli.order_id', 'ho.id')
                        ->where(function ($q) {
                            $q->whereRaw("LOWER(hli.name) LIKE '%qurbani%'")
                              ->orWhereRaw("LOWER(hli.name) LIKE '%hissa%'")
                              ->orWhereRaw("LOWER(COALESCE(hli.sku,'')) LIKE 'qur%'");
                        });
                })
                ->select(DB::raw('DISTINCT YEAR(ho.order_date) as year'))
                ->pluck('year');
            $years = $years->merge($hist);
        }

        $years = $years->filter()->unique()->sortDesc()->values();

        return response()->json(['success' => true, 'years' => $years]);
    }

    public function preview(Request $request)
    {
        $filters = $request->input('filters', []);
        $result = $this->filterService->buildCustomerIdSet($filters);

        // Optional template-dedup preview. When the user has already picked
        // a template in the Create Campaign modal, the frontend passes it
        // here so we can show "100 of 500 matched already received this
        // template in the last N days and will be skipped" before they
        // actually hit "Create". Nothing is written here — this is purely
        // informational and the dedup is applied again (authoritatively)
        // inside create()/addCustomers().
        $template   = trim((string) $request->input('wa_template_name', ''));
        $windowDays = (int) $request->input('dedup_window_days', 0);
        $alreadySentCount = 0;
        $alreadySentIds   = [];
        if ($template !== '' && $windowDays > 0 && !empty($result['customer_ids'])) {
            $alreadySentIds = $this->filterService->customersRecentlySentTemplate(
                $result['customer_ids'],
                $template,
                $windowDays
            );
            $alreadySentCount = count($alreadySentIds);
        }

        return response()->json([
            'success' => true,
            'count' => count($result['customer_ids']),
            'group_counts' => $result['group_counts'],
            'excluded_count' => $result['excluded_count'],
            'already_sent_count' => $alreadySentCount,
            // Net customers that will actually be queued as 'pending' if
            // the campaign is created/extended right now.
            'net_to_send' => max(0, count($result['customer_ids']) - $alreadySentCount),
            'dedup_window_days' => $windowDays,
            'wa_template_name' => $template ?: null,
        ]);
    }


    /**
     * Add more customers to an existing active campaign from a filter payload.
     * Dedups against customers already in the campaign — only truly new
     * customers are inserted as 'pending'. Returns the number actually added,
     * plus the updated counts.
     */
    public function addCustomers(Request $request, $id)
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }
        if ($campaign->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Cannot add customers to an ended campaign'], 422);
        }

        $request->validate(['filters' => 'required|array']);

        $filters = $request->input('filters', []);
        $result = $this->filterService->buildCustomerIdSet($filters);
        $candidateIds = $result['customer_ids'];
        $tagsById = $result['tags_by_id'] ?? [];

        if (empty($candidateIds)) {
            return response()->json([
                'success' => true,
                'added' => 0,
                'already_in_campaign' => 0,
                'matched' => 0,
                'excluded_count' => $result['excluded_count'],
                'group_counts' => $result['group_counts'],
            ]);
        }

        // Dedup against customers already in this campaign (any status).
        $existingIds = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->pluck('customer_id')
            ->map(fn($v) => (int)$v)
            ->all();
        $existingSet = array_flip($existingIds);

        $toInsert = [];
        foreach ($candidateIds as $cId) {
            if (!isset($existingSet[$cId])) {
                $toInsert[] = $cId;
            }
        }

        // Template dedup for "Add more customers" — same rule as create():
        // new candidates who already received this campaign's template
        // in the window are inserted as status='skipped' with the
        // 'Excluded:' error_message prefix so they land in the Excluded
        // tab rather than Pending. Operator-Skipped stays separate.
        $request->validate(['dedup_window_days' => 'nullable|integer|min:0|max:365']);
        $windowDays = (int) $request->input('dedup_window_days', 0);
        $templateName = (string) ($campaign->wa_template_name ?: '');
        $alreadySentIds = $this->filterService->customersRecentlySentTemplate($toInsert, $templateName, $windowDays);
        $alreadySentSet = array_flip($alreadySentIds);
        $excludedByDedup = count($alreadySentIds);
        $excludedReason = ($windowDays > 0 && $templateName !== '')
            ? 'Excluded: already received template "' . $templateName . '" in last ' . $windowDays . ' day' . ($windowDays === 1 ? '' : 's')
            : 'Excluded: recent template send';

        if (!empty($toInsert)) {
            $hasTagsColumn = Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
            $hasErrColumn  = Schema::hasColumn('t_crm_campaign_customers', 'error_message');
            // See create() — keep the row shape uniform or MySQL throws
            // 21S01 on the first row whose key-set differs from row 0.
            $rows = array_map(function ($cId) use ($id, $tagsById, $hasTagsColumn, $hasErrColumn, $alreadySentSet, $excludedReason) {
                $isExcluded = isset($alreadySentSet[(int) $cId]);
                $row = [
                    'campaign_id' => (int) $id,
                    'customer_id' => $cId,
                    'status' => $isExcluded ? 'skipped' : 'pending',
                    'created_at' => now(),
                ];
                if ($hasTagsColumn) {
                    $row['match_tags'] = $this->filterService->matchTagsJson($tagsById, (int) $cId);
                }
                if ($hasErrColumn) {
                    $row['error_message'] = $isExcluded ? $excludedReason : null;
                }
                return $row;
            }, $toInsert);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('t_crm_campaign_customers')->insert($chunk);
            }

            DB::table('t_crm_campaigns')
                ->where('id', $id)
                ->increment('total_customers', count($toInsert), ['updated_at' => now()]);
        }

        // Match the split we return everywhere else so the Add More
        // modal can show "X newly added (including Y excluded)".
        $counts = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
            ")
            ->first();

        return response()->json([
            'success' => true,
            // "added" now means rows actually inserted into the campaign
            // (pending + newly-excluded). The split lives in the two
            // fields below.
            'added' => count($toInsert),
            'added_pending' => count($toInsert) - $excludedByDedup,
            'already_in_campaign' => count($candidateIds) - count($toInsert),
            'matched' => count($candidateIds),
            'excluded_count' => $result['excluded_count'],
            'group_counts' => $result['group_counts'],
            'counts' => $counts,
            'excluded_by_dedup' => $excludedByDedup,
            'dedup_window_days' => $windowDays,
        ]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'wa_template_name' => 'required|string|max:255',
            'filters' => 'required|array',
            'dedup_window_days' => 'nullable|integer|min:0|max:365',
        ]);

        $filters = $request->input('filters', []);
        $result = $this->filterService->buildCustomerIdSet($filters);
        $customerIds = $result['customer_ids'];
        $tagsById = $result['tags_by_id'] ?? [];

        // Template dedup — optional but defaulted to 30 days in the UI.
        // Customers who already received this exact template in the
        // window are inserted as status='skipped' with a distinguishing
        // error_message prefix ('Excluded:'). The campaign detail view
        // surfaces them under a dedicated **Excluded** tab so operators
        // can still audit "who matched my filter but got skipped" —
        // without them polluting Pending or the operator-Skipped tab.
        $templateName = (string) $request->input('wa_template_name');
        $windowDays   = (int) $request->input('dedup_window_days', 0);
        $alreadySentIds = $this->filterService->customersRecentlySentTemplate($customerIds, $templateName, $windowDays);
        $alreadySentSet = array_flip($alreadySentIds);
        $excludedByDedup = count($alreadySentIds);

        // The "Excluded:" prefix is the contract between insert and
        // detail(): anything else in skipped is treated as operator-
        // skipped. Keep this string stable unless you update both sides.
        $excludedReason = ($windowDays > 0 && $templateName !== '')
            ? 'Excluded: already received template "' . $templateName . '" in last ' . $windowDays . ' day' . ($windowDays === 1 ? '' : 's')
            : 'Excluded: recent template send';

        $campaignId = DB::table('t_crm_campaigns')->insertGetId([
            'name' => $request->input('name'),
            'status' => 'active',
            'filters_json' => json_encode($filters),
            'message_template' => $request->input('notes', ''),
            'wa_template_name' => $templateName,
            'wa_template_language' => $request->input('wa_template_language', 'en'),
            'tracking_type' => $request->input('tracking_type', 'general'),
            'tracking_window_days' => $request->input('tracking_window_days', 30),
            // Persisting the dedup window lets sendBulk() and the
            // "Refresh Dedup" action re-check later — the operator's
            // original intent follows the campaign for its whole life.
            // 0 disables the guard entirely (same as today's opt-out).
            'dedup_window_days' => $windowDays,
            // total_customers reflects every row this campaign knows
            // about (Pending + Excluded). That way the stats on the
            // campaigns list still match what you see inside.
            'total_customers' => count($customerIds),
            'sent_count' => 0,
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!empty($customerIds)) {
            $hasTagsColumn = Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
            $hasErrColumn  = Schema::hasColumn('t_crm_campaign_customers', 'error_message');
            // Every row in a batch insert MUST carry the same set of
            // keys — Laravel emits one column list built from the first
            // row. Mixing rows that have 'error_message' with rows that
            // don't triggers MySQL 21S01 on the first excluded row. So
            // we always include the key (null when not excluded) when
            // the column exists.
            $rows = array_map(function ($cId) use ($campaignId, $tagsById, $hasTagsColumn, $hasErrColumn, $alreadySentSet, $excludedReason) {
                $isExcluded = isset($alreadySentSet[(int) $cId]);
                $row = [
                    'campaign_id' => $campaignId,
                    'customer_id' => $cId,
                    'status' => $isExcluded ? 'skipped' : 'pending',
                    'created_at' => now(),
                ];
                if ($hasTagsColumn) {
                    $row['match_tags'] = $this->filterService->matchTagsJson($tagsById, (int) $cId);
                }
                if ($hasErrColumn) {
                    $row['error_message'] = $isExcluded ? $excludedReason : null;
                }
                return $row;
            }, $customerIds);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('t_crm_campaign_customers')->insert($chunk);
            }
        }

        return response()->json([
            'success' => true,
            'campaign_id' => $campaignId,
            // Full campaign size = pending + excluded. Matches the list-
            // view badge and the detail 'Total' stat.
            'total_customers' => count($customerIds),
            'pending_count' => count($customerIds) - $excludedByDedup,
            'excluded_by_dedup' => $excludedByDedup,
            'matched' => count($customerIds),
            'dedup_window_days' => $windowDays,
        ]);
    }

    public function detail(Request $request, $id)
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        $statusFilter = $request->get('status', 'pending');

        $customersQuery = DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
            ->where('cc.campaign_id', $id)
            ->select(
                'cc.id as campaign_customer_id', 'cc.customer_id',
                'cc.status as campaign_status', 'cc.sent_at', 'cc.sent_by', 'cc.error_message',
                'cc.replied_at',
                'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized',
                'c.city', 'c.total_orders', 'c.total_spent', 'c.last_order_date'
            );

        // Derived Shopify-customer flag for the badge in the campaigns UI.
        // One EXISTS sub-query per returned row; hits the existing
        // customer_id index and short-circuits on first match. Same access
        // pattern as the qurbani_year filter — no new burden.
        $customersQuery->selectRaw('(' . $this->filterService->shopifyExistsExpr('c') . ') as is_shopify');

        if (Schema::hasColumn('t_crm_campaign_customers', 'match_tags')) {
            $customersQuery->addSelect('cc.match_tags');
        }

        // "skipped" and "excluded" both live under status='skipped' — we
        // split them via the 'Excluded:' error_message prefix set at
        // insert time by create()/addCustomers(). This keeps the DB
        // ENUM untouched (no migration) while surfacing a clean
        // operator vs dedup distinction in the UI.
        if ($statusFilter === 'excluded') {
            $customersQuery
                ->where('cc.status', 'skipped')
                ->where('cc.error_message', 'LIKE', 'Excluded:%');
        } elseif ($statusFilter === 'skipped') {
            $customersQuery
                ->where('cc.status', 'skipped')
                ->where(function ($q) {
                    $q->whereNull('cc.error_message')
                      ->orWhere('cc.error_message', 'NOT LIKE', 'Excluded:%');
                });
        } elseif ($statusFilter !== 'all') {
            $customersQuery->where('cc.status', $statusFilter);
        }

        $customersQuery->orderByRaw("FIELD(cc.status, 'pending', 'failed', 'sent', 'skipped')");

        $sortBy = json_decode($campaign->filters_json, true)['sort_by'] ?? 'last_order_date';
        $sortDir = json_decode($campaign->filters_json, true)['sort_dir'] ?? 'desc';
        if (in_array($sortBy, ['last_order_date', 'total_spent', 'created_at'])) {
            $customersQuery->orderBy("c.{$sortBy}", $sortDir);
        }

        $customers = $customersQuery->get();

        $counts = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status = 'skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
            ")
            ->first();

        return response()->json([
            'success' => true,
            'campaign' => $campaign,
            'customers' => $customers,
            'counts' => $counts,
        ]);
    }

    public function sendBulk(Request $request, $id)
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->where('status', 'active')->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found or ended'], 404);
        }

        $templateName = $campaign->wa_template_name;
        if (!$templateName) {
            return response()->json(['success' => false, 'message' => 'No WhatsApp template configured'], 422);
        }

        $request->validate([
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'integer',
            'include_failed' => 'nullable|boolean',
        ]);

        $customerIds = $request->input('customer_ids');
        $language = $campaign->wa_template_language ?: 'en';
        // When include_failed=true (retry flow), we accept failed rows too and reset them
        // back to pending on success. Default is pending-only for the normal send path.
        $includeFailed = (bool) $request->input('include_failed', false);
        $eligibleStatuses = $includeFailed ? ['pending', 'failed'] : ['pending'];

        // Look up the template's expected variable_count once so we can size body_params
        // exactly right per customer. Templates with 0 variables (e.g. marketing broadcasts)
        // must receive NO body params, otherwise Meta returns error #132000.
        $templateMeta = DB::table('t_wa_templates')->where('name', $templateName)->first();
        $expectedVarCount = $templateMeta ? (int) $templateMeta->variable_count : null;

        $customers = DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
            ->where('cc.campaign_id', $id)
            ->whereIn('cc.status', $eligibleStatuses)
            ->whereIn('cc.customer_id', $customerIds)
            ->select('cc.customer_id', 'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized', 'cc.status as prev_status')
            ->get();

        if ($customers->isEmpty()) {
            $label = $includeFailed ? 'pending or failed' : 'pending';
            return response()->json(['success' => false, 'message' => "No {$label} customers found"], 422);
        }

        // Send-time dedup guard. Even though we dedup at create/add time,
        // an older campaign can still have 'pending' customers who have
        // since received the same template via a newer campaign. Without
        // this re-check, hitting Send Bulk would message them again.
        //
        // We run ONE query for the whole batch (cheap) and auto-move
        // matching rows to status='skipped' with the 'Excluded:' prefix,
        // so they surface in the Excluded tab consistently with create
        // time and can be audited. Operator doesn't need to remember to
        // click Refresh Dedup first.
        //
        // Controlled by the campaign's own dedup_window_days — if that
        // is 0 (opt-out or legacy campaign) we short-circuit and behave
        // exactly like before.
        $dedupWindow = (int) ($campaign->dedup_window_days ?? 0);
        $sendTimeExcluded = 0;
        if ($dedupWindow > 0 && $templateName !== '') {
            $batchIds = $customers->pluck('customer_id')->map(fn($v) => (int) $v)->all();
            $nowExcludedIds = $this->filterService->customersRecentlySentTemplate(
                $batchIds,
                $templateName,
                $dedupWindow
            );
            if (!empty($nowExcludedIds)) {
                $excludedSet = array_flip($nowExcludedIds);
                $reason = 'Excluded: already received template "' . $templateName . '" in last '
                    . $dedupWindow . ' day' . ($dedupWindow === 1 ? '' : 's');

                // Persist the auto-exclusion before filtering the
                // working set so the UI reflects it on next refresh.
                DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $id)
                    ->whereIn('customer_id', $nowExcludedIds)
                    ->whereIn('status', $eligibleStatuses)
                    ->update(['status' => 'skipped', 'error_message' => $reason, 'sent_at' => null]);

                $customers = $customers->reject(fn($c) => isset($excludedSet[(int) $c->customer_id]))->values();
                $sendTimeExcluded = count($nowExcludedIds);

                \Log::info('Campaign bulk send: dedup guard auto-excluded customers', [
                    'campaign_id' => $id,
                    'template' => $templateName,
                    'window_days' => $dedupWindow,
                    'excluded' => $sendTimeExcluded,
                ]);
            }
        }

        if ($customers->isEmpty()) {
            // Everybody in the selection was auto-excluded by the guard.
            // Return success:true with excluded count so the UI can show
            // a friendly "N customers moved to Excluded (already received
            // the template recently); nothing was sent" message.
            $counts = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                    SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                ")
                ->first();
            return response()->json([
                'success' => true,
                'results' => ['sent' => 0, 'failed' => 0, 'excluded' => $sendTimeExcluded, 'errors' => []],
                'counts' => $counts,
            ]);
        }

        $whatsapp = app(WhatsAppService::class);
        $results = ['sent' => 0, 'failed' => 0, 'excluded' => $sendTimeExcluded, 'errors' => []];

        // NF: small inter-message delay to keep Meta happy on larger batches.
        // Meta's WhatsApp Business Cloud API tolerates short bursts but can
        // throttle (error 131056 / 80007) when many templates are fired in
        // quick succession. 200ms paces us to ~5 req/s which is well inside
        // Meta's published guidance for template sends. We only sleep when
        // there's more than one customer in this sub-batch (single sends
        // and first-in-batch run immediately).
        $total = count($customers);
        $paceMicros = $total > 1 ? 200_000 : 0;
        $index = 0;

        \Log::info('Campaign bulk send: starting batch', [
            'campaign_id' => $id,
            'template' => $templateName,
            'batch_size' => $total,
            'include_failed' => $includeFailed,
        ]);

        foreach ($customers as $customer) {
            // Pace between API calls (skip the delay before the very first one).
            if ($paceMicros && $index > 0) {
                usleep($paceMicros);
            }
            $index++;

            $phone = $customer->phone_normalized ?: $customer->phone;
            if (!$phone) {
                DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $id)->where('customer_id', $customer->customer_id)
                    ->update(['status' => 'failed', 'error_message' => 'No phone number']);
                $results['failed']++;
                continue;
            }

            try {
                // Dial-resolve (known-number override; no-op for PK numbers).
                $formattedPhone = $whatsapp->resolveDialPhone((string) $phone);
                $customerName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                $bodyParams = $request->input('body_params', []);
                $resolvedParams = array_map(fn($p) => $p === '{{customer_name}}' ? $customerName : $p, $bodyParams);

                // Normalise the parameter array to match the template's real variable_count.
                // This protects us when the caller sends a {{customer_name}} placeholder for
                // every template (old default) even if the template itself declares 0 or N vars.
                if ($expectedVarCount !== null) {
                    if (count($resolvedParams) > $expectedVarCount) {
                        $resolvedParams = array_slice($resolvedParams, 0, $expectedVarCount);
                    }
                    while (count($resolvedParams) < $expectedVarCount) {
                        $resolvedParams[] = $customerName;
                    }
                }

                $response = $whatsapp->sendTemplateMessage($formattedPhone, $templateName, $language, $resolvedParams);

                if ($response['success'] ?? false) {
                    DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $id)->where('customer_id', $customer->customer_id)
                        ->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => Auth::id(), 'error_message' => null]);
                    // Only bump sent_count when we're transitioning from a non-sent state,
                    // so retrying a failed row still increments (retry = failed -> sent).
                    DB::table('t_crm_campaigns')->where('id', $id)->increment('sent_count');
                    $results['sent']++;

                    $conversation = $whatsapp->findOrCreateConversation($formattedPhone);
                    if (!$conversation->customer_id) {
                        $conversation->update(['customer_id' => $customer->customer_id]);
                    }
                    $whatsapp->saveOutboundMessage($conversation->id, $response, 'template', "Campaign: {$campaign->name}", Auth::id(), $templateName, $resolvedParams);
                } else {
                    $error = $response['error'] ?? 'API send failed';
                    DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $id)->where('customer_id', $customer->customer_id)
                        ->update(['status' => 'failed', 'error_message' => mb_substr($error, 0, 500)]);
                    $results['failed']++;
                    $results['errors'][] = ['customer_id' => $customer->customer_id, 'name' => $customerName, 'error' => $error];
                }
            } catch (\Exception $e) {
                $error = $e->getMessage();
                DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $id)->where('customer_id', $customer->customer_id)
                    ->update(['status' => 'failed', 'error_message' => mb_substr($error, 0, 500)]);
                $results['failed']++;
            }
        }

        $counts = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
            ")
            ->first();

        \Log::info('Campaign bulk send: batch finished', [
            'campaign_id' => $id,
            'template' => $templateName,
            'sent' => $results['sent'],
            'failed' => $results['failed'],
        ]);

        return response()->json(['success' => true, 'results' => $results, 'counts' => $counts]);
    }

    /**
     * Manual "Refresh Dedup" action. Re-evaluates every pending row in
     * this campaign against the current WhatsApp send history using the
     * campaign's stored dedup_window_days + wa_template_name, and moves
     * newly-matching rows into status='skipped' with the 'Excluded:'
     * prefix so they surface in the Excluded tab.
     *
     * Rationale: create()/addCustomers() only dedup at insert time. A
     * pending customer may later receive the same template via a newer
     * campaign, so without this the stale pending list would still
     * message them when the operator hits Send Bulk. sendBulk() has its
     * own guard for safety, but surfacing this manually before sending
     * lets operators see the up-to-date numbers and re-decide who to
     * target.
     *
     * Idempotent + safe on campaigns without dedup configured
     * (window=0 or no template) — returns moved=0 and a hint message.
     */
    public function refreshDedup(Request $request, $id)
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        $templateName = (string) ($campaign->wa_template_name ?? '');
        $windowDays   = (int) ($campaign->dedup_window_days ?? 0);

        if ($windowDays <= 0 || $templateName === '') {
            return response()->json([
                'success' => true,
                'moved'   => 0,
                'message' => 'Dedup is not enabled for this campaign (window or template not set).',
                'dedup_window_days' => $windowDays,
                'wa_template_name'  => $templateName ?: null,
            ]);
        }

        // Only consider still-pending rows. 'sent' is already done, 'failed'
        // keeps its retry option, 'skipped'/excluded are already resolved.
        $pendingIds = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->where('status', 'pending')
            ->pluck('customer_id')
            ->map(fn($v) => (int) $v)
            ->all();

        $moved = 0;
        if (!empty($pendingIds)) {
            $nowExcludedIds = $this->filterService->customersRecentlySentTemplate(
                $pendingIds,
                $templateName,
                $windowDays
            );

            if (!empty($nowExcludedIds)) {
                $reason = 'Excluded: already received template "' . $templateName . '" in last '
                    . $windowDays . ' day' . ($windowDays === 1 ? '' : 's');

                $moved = DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $id)
                    ->whereIn('customer_id', $nowExcludedIds)
                    ->where('status', 'pending')
                    ->update(['status' => 'skipped', 'error_message' => $reason]);
            }
        }

        $counts = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
            ")
            ->first();

        \Log::info('Campaign dedup refresh', [
            'campaign_id' => $id,
            'template'    => $templateName,
            'window_days' => $windowDays,
            'pending_scanned' => count($pendingIds),
            'moved_to_excluded' => $moved,
        ]);

        return response()->json([
            'success' => true,
            'moved'   => $moved,
            'pending_scanned' => count($pendingIds),
            'dedup_window_days' => $windowDays,
            'wa_template_name'  => $templateName,
            'counts' => $counts,
        ]);
    }

    public function end(Request $request, $id)
    {
        DB::table('t_crm_campaigns')->where('id', $id)->update([
            'status' => 'ended', 'ended_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true]);
    }

    public function skip(Request $request, $id, $customerId)
    {
        // Operator-skipped rows deliberately leave error_message NULL
        // so the campaign detail view's 'Excluded:' LIKE check excludes
        // them — they land in the Skipped tab, not the Excluded tab.
        DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)->where('customer_id', $customerId)->where('status', 'pending')
            ->update(['status' => 'skipped']);

        $counts = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
            ")
            ->first();

        return response()->json(['success' => true, 'counts' => $counts]);
    }

    public function stats(Request $request, $id)
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
        if (!$campaign) {
            return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
        }

        $trackingDays = $campaign->tracking_window_days ?: 30;
        $sentCustomers = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->where('status', 'sent')
            ->get();

        $totalSent = $sentCustomers->count();
        $customersWhoOrdered = 0;
        $customersWhoReplied = 0;
        $totalRevenue = 0;
        $customerDetails = [];

        foreach ($sentCustomers as $cc) {
            $orders = DB::table('t_crm_prod_order')
                ->where('customer_id', $cc->customer_id)
                ->where('order_date', '>', $cc->sent_at)
                ->where('order_date', '<=', Carbon::parse($cc->sent_at)->addDays($trackingDays))
                ->whereIn('order_status', ['delivered', 'completed'])
                ->get();

            $orderCount = $orders->count();
            $revenue = $orders->sum('total_price');
            if ($orderCount > 0) {
                $customersWhoOrdered++;
                $totalRevenue += $revenue;
            }

            $replied = !empty($cc->replied_at);
            if ($replied) $customersWhoReplied++;

            $cust = DB::table('t_crm_prod_customer')->where('id', $cc->customer_id)->select('first_name', 'last_name')->first();
            $customerDetails[] = [
                'customer_id' => (int) $cc->customer_id,
                'name' => $cust ? trim($cust->first_name . ' ' . $cust->last_name) : 'Unknown',
                'ordered' => $orderCount > 0,
                'order_count' => $orderCount,
                'revenue' => $revenue,
                'replied' => $replied,
                'replied_at' => $cc->replied_at,
            ];
        }

        return response()->json([
            'success' => true,
            'stats' => [
                'total_sent' => $totalSent,
                'customers_who_ordered' => $customersWhoOrdered,
                'conversion_rate' => $totalSent > 0 ? round(($customersWhoOrdered / $totalSent) * 100, 1) : 0,
                'customers_who_replied' => $customersWhoReplied,
                'reply_rate' => $totalSent > 0 ? round(($customersWhoReplied / $totalSent) * 100, 1) : 0,
                'total_revenue' => round($totalRevenue, 2),
                'tracking_window_days' => $trackingDays,
                'customer_details' => $customerDetails,
            ],
        ]);
    }

    // All filter logic now lives in App\Services\CampaignFilterService so the
    // mobile RiderController stays in lockstep with the web controller.
}
