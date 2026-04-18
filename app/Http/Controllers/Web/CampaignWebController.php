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
        return response()->json([
            'success' => true,
            'count' => count($result['customer_ids']),
            'group_counts' => $result['group_counts'],
            'excluded_count' => $result['excluded_count'],
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

        if (!empty($toInsert)) {
            $hasTagsColumn = Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
            $rows = array_map(function ($cId) use ($id, $tagsById, $hasTagsColumn) {
                $row = [
                    'campaign_id' => (int) $id,
                    'customer_id' => $cId,
                    'status' => 'pending',
                    'created_at' => now(),
                ];
                if ($hasTagsColumn) {
                    $row['match_tags'] = $this->filterService->matchTagsJson($tagsById, (int) $cId);
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

        $counts = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->selectRaw("COUNT(*) as total, SUM(status='sent') as sent, SUM(status='pending') as pending, SUM(status='failed') as failed, SUM(status='skipped') as skipped")
            ->first();

        return response()->json([
            'success' => true,
            'added' => count($toInsert),
            'already_in_campaign' => count($candidateIds) - count($toInsert),
            'matched' => count($candidateIds),
            'excluded_count' => $result['excluded_count'],
            'group_counts' => $result['group_counts'],
            'counts' => $counts,
        ]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'wa_template_name' => 'required|string|max:255',
            'filters' => 'required|array',
        ]);

        $filters = $request->input('filters', []);
        $result = $this->filterService->buildCustomerIdSet($filters);
        $customerIds = $result['customer_ids'];
        $tagsById = $result['tags_by_id'] ?? [];

        $campaignId = DB::table('t_crm_campaigns')->insertGetId([
            'name' => $request->input('name'),
            'status' => 'active',
            'filters_json' => json_encode($filters),
            'message_template' => $request->input('notes', ''),
            'wa_template_name' => $request->input('wa_template_name'),
            'wa_template_language' => $request->input('wa_template_language', 'en'),
            'tracking_type' => $request->input('tracking_type', 'general'),
            'tracking_window_days' => $request->input('tracking_window_days', 30),
            'total_customers' => count($customerIds),
            'sent_count' => 0,
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!empty($customerIds)) {
            $hasTagsColumn = Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
            $rows = array_map(function ($cId) use ($campaignId, $tagsById, $hasTagsColumn) {
                $row = [
                    'campaign_id' => $campaignId,
                    'customer_id' => $cId,
                    'status' => 'pending',
                    'created_at' => now(),
                ];
                if ($hasTagsColumn) {
                    $row['match_tags'] = $this->filterService->matchTagsJson($tagsById, (int) $cId);
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
            'total_customers' => count($customerIds),
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

        if (Schema::hasColumn('t_crm_campaign_customers', 'match_tags')) {
            $customersQuery->addSelect('cc.match_tags');
        }

        if ($statusFilter !== 'all') {
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
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped
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

        $whatsapp = app(WhatsAppService::class);
        $results = ['sent' => 0, 'failed' => 0, 'errors' => []];

        foreach ($customers as $customer) {
            $phone = $customer->phone_normalized ?: $customer->phone;
            if (!$phone) {
                DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $id)->where('customer_id', $customer->customer_id)
                    ->update(['status' => 'failed', 'error_message' => 'No phone number']);
                $results['failed']++;
                continue;
            }

            try {
                $formattedPhone = $whatsapp->formatPhone($phone);
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
            ->selectRaw("COUNT(*) as total, SUM(status='sent') as sent, SUM(status='pending') as pending, SUM(status='failed') as failed, SUM(status='skipped') as skipped")
            ->first();

        return response()->json(['success' => true, 'results' => $results, 'counts' => $counts]);
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
        DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)->where('customer_id', $customerId)->where('status', 'pending')
            ->update(['status' => 'skipped']);

        $counts = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $id)
            ->selectRaw("COUNT(*) as total, SUM(status='sent') as sent, SUM(status='pending') as pending, SUM(status='failed') as failed, SUM(status='skipped') as skipped")
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
