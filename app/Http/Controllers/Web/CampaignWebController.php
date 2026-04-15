<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsAppService;
use App\Models\CRM\CustomerModel;
use Carbon\Carbon;

class CampaignWebController extends Controller
{
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

    public function preview(Request $request)
    {
        $filters = $request->input('filters', []);
        $query = $this->buildFilterQuery($filters);
        return response()->json(['success' => true, 'count' => $query->count()]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'wa_template_name' => 'required|string|max:255',
            'filters' => 'required|array',
        ]);

        $filters = $request->input('filters', []);
        $query = $this->buildFilterQuery($filters);
        $customerIds = $query->pluck('id')->toArray();

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
            $rows = array_map(fn($cId) => [
                'campaign_id' => $campaignId,
                'customer_id' => $cId,
                'status' => 'pending',
                'created_at' => now(),
            ], $customerIds);

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
                'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized',
                'c.city', 'c.total_orders', 'c.total_spent', 'c.last_order_date'
            );

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
        ]);

        $customerIds = $request->input('customer_ids');
        $language = $campaign->wa_template_language ?: 'en';

        $customers = DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
            ->where('cc.campaign_id', $id)
            ->where('cc.status', 'pending')
            ->whereIn('cc.customer_id', $customerIds)
            ->select('cc.customer_id', 'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized')
            ->get();

        if ($customers->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No pending customers found'], 422);
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

                $response = $whatsapp->sendTemplateMessage($formattedPhone, $templateName, $language, $resolvedParams);

                if ($response['success'] ?? false) {
                    DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $id)->where('customer_id', $customer->customer_id)
                        ->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => Auth::id()]);
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
        $sentCustomers = DB::table('t_crm_campaign_customers')->where('campaign_id', $id)->where('status', 'sent')->get();

        $totalSent = $sentCustomers->count();
        $customersWhoOrdered = 0;
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

            $cust = DB::table('t_crm_prod_customer')->where('id', $cc->customer_id)->select('first_name', 'last_name')->first();
            $customerDetails[] = [
                'name' => $cust ? trim($cust->first_name . ' ' . $cust->last_name) : 'Unknown',
                'ordered' => $orderCount > 0,
                'order_count' => $orderCount,
                'revenue' => $revenue,
            ];
        }

        return response()->json([
            'success' => true,
            'stats' => [
                'total_sent' => $totalSent,
                'customers_who_ordered' => $customersWhoOrdered,
                'conversion_rate' => $totalSent > 0 ? round(($customersWhoOrdered / $totalSent) * 100, 1) : 0,
                'total_revenue' => round($totalRevenue, 2),
                'tracking_window_days' => $trackingDays,
                'customer_details' => $customerDetails,
            ],
        ]);
    }

    private function buildFilterQuery(array $filters)
    {
        $query = CustomerModel::query()->whereNull('merged_into_customer_id');

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'LIKE', "%{$s}%")
                  ->orWhere('last_name', 'LIKE', "%{$s}%")
                  ->orWhere('phone', 'LIKE', "%{$s}%")
                  ->orWhere('phone_normalized', 'LIKE', "%{$s}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$s}%"]);
            });
        }
        if (!empty($filters['city'])) $query->where('city', $filters['city']);
        if (!empty($filters['activity'])) {
            if ($filters['activity'] === '30day') $query->where('last_order_date', '>=', now()->subDays(30));
            elseif ($filters['activity'] === '90day') $query->where('last_order_date', '>=', now()->subDays(90));
        }
        if (isset($filters['min_spend']) && $filters['min_spend'] !== '' && $filters['min_spend'] !== null) {
            $query->where('total_spent', '>=', (float) $filters['min_spend']);
        }
        if (isset($filters['max_spend']) && $filters['max_spend'] !== '' && $filters['max_spend'] !== null) {
            $query->where('total_spent', '<=', (float) $filters['max_spend']);
        }

        $sortBy = $filters['sort_by'] ?? 'last_order_date';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        if (!in_array($sortBy, ['last_order_date', 'total_spent', 'created_at'])) $sortBy = 'last_order_date';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query;
    }
}
