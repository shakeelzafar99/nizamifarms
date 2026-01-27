<?php

namespace App\Services;

use App\Models\CRM\OrderModel;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\ProductModel;
use App\Models\FIN\LedgerModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardAnalyticsService
{
    /**
     * Get all dashboard KPIs with caching
     */
    public function getDashboardKPIs($timeRange = '30')
    {
        $cacheKey = "dashboard_kpis_{$timeRange}";
        
        return Cache::remember($cacheKey, 300, function () use ($timeRange) { // 5 minutes cache
            $startDate = $this->getStartDate($timeRange);
            $endDate = Carbon::now();
            
            return [
                'revenue' => $this->getRevenueKPIs($startDate, $endDate),
                'orders' => $this->getOrderKPIs($startDate, $endDate),
                'customers' => $this->getCustomerKPIs($startDate, $endDate),
                'products' => $this->getProductKPIs($startDate, $endDate),
            ];
        });
    }

    /**
     * Valid order statuses for revenue calculation
     * IMPORTANT: 'delivered' is the actual delivery status used in the app
     */
    protected const VALID_REVENUE_STATUSES = ['delivered', 'completed', 'processing'];

    /**
     * Get revenue-related KPIs (excluding Shopify)
     */
    public function getRevenueKPIs($startDate, $endDate)
    {
        // Current period revenue (excluding Shopify)
        $currentRevenue = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereIn('order_status', self::VALID_REVENUE_STATUSES)
            ->sum('total_price');

        // Previous period for comparison
        $daysDiff = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($daysDiff);
        $previousEnd = $startDate->copy()->subDay();
        
        $previousRevenue = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$previousStart, $previousEnd])
            ->whereIn('order_status', self::VALID_REVENUE_STATUSES)
            ->sum('total_price');

        // Today's revenue
        $todayRevenue = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereDate('order_date', Carbon::today())
            ->whereIn('order_status', self::VALID_REVENUE_STATUSES)
            ->sum('total_price');

        // Average Order Value
        $avgOrderValue = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereIn('order_status', self::VALID_REVENUE_STATUSES)
            ->avg('total_price');

        return [
            'current' => round($currentRevenue, 2),
            'previous' => round($previousRevenue, 2),
            'today' => round($todayRevenue, 2),
            'growth' => $previousRevenue > 0 ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1) : 0,
            'avg_order_value' => round($avgOrderValue ?? 0, 2),
        ];
    }

    /**
     * Get order-related KPIs (excluding Shopify)
     */
    public function getOrderKPIs($startDate, $endDate)
    {
        // Current period orders
        $currentOrders = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$startDate, $endDate])
            ->count();

        // Previous period for comparison
        $daysDiff = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($daysDiff);
        $previousEnd = $startDate->copy()->subDay();
        
        $previousOrders = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$previousStart, $previousEnd])
            ->count();

        // Today's orders
        $todayOrders = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereDate('order_date', Carbon::today())
            ->count();

        // Order status distribution
        $statusDistribution = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$startDate, $endDate])
            ->select('order_status', DB::raw('count(*) as count'))
            ->groupBy('order_status')
            ->pluck('count', 'order_status')
            ->toArray();

        // Source distribution (excluding Shopify)
        $sourceDistribution = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$startDate, $endDate])
            ->select('external_source', DB::raw('count(*) as count'))
            ->groupBy('external_source')
            ->pluck('count', 'external_source')
            ->toArray();

        return [
            'current' => $currentOrders,
            'previous' => $previousOrders,
            'today' => $todayOrders,
            'growth' => $previousOrders > 0 ? round((($currentOrders - $previousOrders) / $previousOrders) * 100, 1) : 0,
            'status_distribution' => $statusDistribution,
            'source_distribution' => $sourceDistribution,
        ];
    }

    /**
     * Get comprehensive customer analytics
     */
    public function getCustomerKPIs($startDate, $endDate)
    {
        // Total customers
        $totalCustomers = CustomerModel::where('is_active', true)->count();

        // New customers in current period
        $newCustomers = CustomerModel::whereBetween('created_at', [$startDate, $endDate])
            ->where('is_active', true)
            ->count();

        // Previous period new customers for comparison
        $daysDiff = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($daysDiff);
        $previousEnd = $startDate->copy()->subDay();
        
        $previousNewCustomers = CustomerModel::whereBetween('created_at', [$previousStart, $previousEnd])
            ->where('is_active', true)
            ->count();

        // 90-day active customers (customers with orders in last 90 days)
        $ninetyDaysAgo = Carbon::now()->subDays(90);
        $activeCustomers90Days = CustomerModel::where('last_order_date', '>=', $ninetyDaysAgo)
            ->where('is_active', true)
            ->count();

        // 30-day active customers
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $activeCustomers30Days = CustomerModel::where('last_order_date', '>=', $thirtyDaysAgo)
            ->where('is_active', true)
            ->count();

        // 7-day active customers
        $sevenDaysAgo = Carbon::now()->subDays(7);
        $activeCustomers7Days = CustomerModel::where('last_order_date', '>=', $sevenDaysAgo)
            ->where('is_active', true)
            ->count();

        // Customer retention rate (customers who made repeat orders)
        $repeatCustomers = CustomerModel::where('total_orders', '>', 1)
            ->where('is_active', true)
            ->count();
        
        $retentionRate = $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 1) : 0;

        // Top customers by spending
        $topCustomers = CustomerModel::where('is_active', true)
            ->where('total_spent', '>', 0)
            ->orderBy('total_spent', 'desc')
            ->limit(5)
            ->select('id', 'first_name', 'last_name', 'total_spent', 'total_orders')
            ->get()
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => trim($customer->first_name . ' ' . $customer->last_name),
                    'total_spent' => $customer->total_spent,
                    'total_orders' => $customer->total_orders,
                ];
            });

        // Geographic distribution (top 10 cities)
        $topCities = CustomerModel::where('is_active', true)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city', DB::raw('count(*) as count'))
            ->groupBy('city')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->pluck('count', 'city')
            ->toArray();

        return [
            'total' => $totalCustomers,
            'new_customers' => $newCustomers,
            'previous_new_customers' => $previousNewCustomers,
            'new_customer_growth' => $previousNewCustomers > 0 ? round((($newCustomers - $previousNewCustomers) / $previousNewCustomers) * 100, 1) : 0,
            'active_90_days' => $activeCustomers90Days,
            'active_30_days' => $activeCustomers30Days,
            'active_7_days' => $activeCustomers7Days,
            'retention_rate' => $retentionRate,
            'repeat_customers' => $repeatCustomers,
            'top_customers' => $topCustomers,
            'geographic_distribution' => $topCities,
        ];
    }

    /**
     * Get product performance KPIs
     */
    public function getProductKPIs($startDate, $endDate)
    {
        // Top products by revenue (excluding Shopify orders)
        $topProductsByRevenue = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
            ->where(function($query) {
                $query->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
            })
            ->whereBetween('o.order_date', [$startDate, $endDate])
            ->whereIn('o.order_status', self::VALID_REVENUE_STATUSES)
            ->select('li.name', DB::raw('SUM(li.quantity * li.unit_price) as revenue'), DB::raw('SUM(li.quantity) as total_quantity'))
            ->groupBy('li.name')
            ->orderBy('revenue', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->name,
                    'revenue' => round($item->revenue, 2),
                    'quantity' => $item->total_quantity,
                ];
            });

        // Top products by quantity
        $topProductsByQuantity = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
            ->where(function($query) {
                $query->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
            })
            ->whereBetween('o.order_date', [$startDate, $endDate])
            ->whereIn('o.order_status', self::VALID_REVENUE_STATUSES)
            ->select('li.name', DB::raw('SUM(li.quantity) as total_quantity'), DB::raw('SUM(li.quantity * li.unit_price) as revenue'))
            ->groupBy('li.name')
            ->orderBy('total_quantity', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->name,
                    'quantity' => $item->total_quantity,
                    'revenue' => round($item->revenue, 2),
                ];
            });

        // Total active products
        $totalProducts = ProductModel::where('is_active', true)->count();

        return [
            'total_products' => $totalProducts,
            'top_by_revenue' => $topProductsByRevenue,
            'top_by_quantity' => $topProductsByQuantity,
        ];
    }

    /**
     * Get chart data for revenue trends
     */
    public function getRevenueChartData($timeRange = '30')
    {
        $startDate = $this->getStartDate($timeRange);
        $endDate = Carbon::now();

        $data = OrderModel::where(function($query) {
                $query->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
            })
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereIn('order_status', self::VALID_REVENUE_STATUSES)
            ->select(
                DB::raw('DATE(order_date) as date'),
                DB::raw('SUM(total_price) as revenue'),
                DB::raw('COUNT(*) as orders')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'labels' => $data->pluck('date')->map(function ($date) {
                return Carbon::parse($date)->format('M j');
            }),
            'revenue' => $data->pluck('revenue'),
            'orders' => $data->pluck('orders'),
        ];
    }

    /**
     * Get customer growth chart data
     */
    public function getCustomerGrowthChartData($timeRange = '30')
    {
        $startDate = $this->getStartDate($timeRange);
        $endDate = Carbon::now();

        $data = CustomerModel::whereBetween('created_at', [$startDate, $endDate])
            ->where('is_active', true)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as new_customers')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Fill missing dates with 0
        $period = Carbon::parse($startDate)->toPeriod($endDate, '1 day');
        $result = [];
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $found = $data->firstWhere('date', $dateStr);
            $result[] = [
                'date' => $dateStr,
                'new_customers' => $found ? $found->new_customers : 0,
            ];
        }

        return [
            'labels' => collect($result)->pluck('date')->map(function ($date) {
                return Carbon::parse($date)->format('M j');
            }),
            'new_customers' => collect($result)->pluck('new_customers'),
        ];
    }

    /**
     * Get start date based on time range
     */
    private function getStartDate($timeRange)
    {
        switch ($timeRange) {
            case '1':
                return Carbon::today();
            case '7':
                return Carbon::now()->subDays(7);
            case '30':
                return Carbon::now()->subDays(30);
            case '90':
                return Carbon::now()->subDays(90);
            default:
                return Carbon::now()->subDays(30);
        }
    }

    /**
     * Get monthly analytics based on DELIVERED ORDERS
     * Primary source: Orders with delivered status from order_status_history
     * Payment mode from ORDER's payment_method field (not ledger)
     * Customer type: New (first_order in this month) vs Returning
     */
    public function getMonthlyLedgerAnalytics($months = 12)
    {
        $cacheKey = "monthly_delivered_orders_v7_{$months}";
        
        return Cache::remember($cacheKey, 600, function () use ($months) {
            $endDate = Carbon::now()->endOfMonth();
            $startDate = $endDate->copy()->subMonths($months)->startOfMonth();
            
            // PRIMARY SOURCE: Delivered orders grouped by delivery date
            // Payment method from order, customer type from customer table
            $data = DB::table('t_crm_prod_order as o')
                ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
                ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
                ->select(
                    DB::raw("DATE_FORMAT(h.delivered_at, '%Y-%m') as month_key"),
                    DB::raw("DATE_FORMAT(h.delivered_at, '%b %Y') as month_name"),
                    // Delivered order totals
                    DB::raw("SUM(o.total_price) as invoice_total"),
                    DB::raw("COUNT(DISTINCT o.id) as invoice_count"),
                    // Unique customers
                    DB::raw("COUNT(DISTINCT o.customer_id) as unique_customers"),
                    // Online/Cash split from ORDER's payment_method field
                    DB::raw("SUM(CASE WHEN o.payment_method IN ('online', 'Online', 'ONLINE', 'card', 'Card') THEN o.total_price ELSE 0 END) as online_total"),
                    DB::raw("SUM(CASE WHEN o.payment_method IN ('online', 'Online', 'ONLINE', 'card', 'Card') THEN 1 ELSE 0 END) as online_count"),
                    DB::raw("SUM(CASE WHEN o.payment_method NOT IN ('online', 'Online', 'ONLINE', 'card', 'Card') OR o.payment_method IS NULL THEN o.total_price ELSE 0 END) as cash_total"),
                    DB::raw("SUM(CASE WHEN o.payment_method NOT IN ('online', 'Online', 'ONLINE', 'card', 'Card') OR o.payment_method IS NULL THEN 1 ELSE 0 END) as cash_count"),
                    // Shopify/Manual split (based on order_number)
                    DB::raw("SUM(CASE WHEN o.order_number LIKE 'SH%' OR o.order_number LIKE 'sh%' THEN o.total_price ELSE 0 END) as shopify_total"),
                    DB::raw("SUM(CASE WHEN o.order_number LIKE 'SH%' OR o.order_number LIKE 'sh%' THEN 1 ELSE 0 END) as shopify_count"),
                    DB::raw("SUM(CASE WHEN o.order_number NOT LIKE 'SH%' AND o.order_number NOT LIKE 'sh%' THEN o.total_price ELSE 0 END) as manual_total"),
                    DB::raw("SUM(CASE WHEN o.order_number NOT LIKE 'SH%' AND o.order_number NOT LIKE 'sh%' THEN 1 ELSE 0 END) as manual_count"),
                    // New vs Returning customers (based on first_delivery_date - pre-computed for performance)
                    // New = customer's first delivery was in this month
                    DB::raw("SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, '%Y-%m') = DATE_FORMAT(h.delivered_at, '%Y-%m') THEN o.total_price ELSE 0 END) as new_customer_revenue"),
                    DB::raw("SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, '%Y-%m') = DATE_FORMAT(h.delivered_at, '%Y-%m') THEN 1 ELSE 0 END) as new_customer_orders"),
                    DB::raw("SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, '%Y-%m') != DATE_FORMAT(h.delivered_at, '%Y-%m') OR c.first_delivery_date IS NULL THEN o.total_price ELSE 0 END) as returning_customer_revenue"),
                    DB::raw("SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, '%Y-%m') != DATE_FORMAT(h.delivered_at, '%Y-%m') OR c.first_delivery_date IS NULL THEN 1 ELSE 0 END) as returning_customer_orders")
                )
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                ->groupBy(DB::raw("DATE_FORMAT(h.delivered_at, '%Y-%m')"), DB::raw("DATE_FORMAT(h.delivered_at, '%b %Y')"))
                ->orderBy('month_key')
                ->get();
            
            // Get expenses, vendor purchases, and vendor payments (these use transaction_date)
            $financialData = DB::table('t_fin_ledger')
                ->select(
                    DB::raw("DATE_FORMAT(transaction_date, '%Y-%m') as month_key"),
                    DB::raw("SUM(CASE WHEN transaction_type = 'expense' AND approval_status = 'approved' THEN amount ELSE 0 END) as expense_total"),
                    DB::raw("SUM(CASE WHEN transaction_type = 'expense' AND approval_status = 'approved' THEN 1 ELSE 0 END) as expense_count"),
                    DB::raw("SUM(CASE WHEN transaction_type = 'vendor_purchase' AND approval_status = 'approved' THEN amount ELSE 0 END) as vendor_purchase_total"),
                    DB::raw("SUM(CASE WHEN transaction_type = 'vendor_purchase' AND approval_status = 'approved' THEN 1 ELSE 0 END) as vendor_purchase_count"),
                    DB::raw("SUM(CASE WHEN transaction_type = 'vendor_payment' AND approval_status = 'approved' THEN amount ELSE 0 END) as vendor_payment_total"),
                    DB::raw("SUM(CASE WHEN transaction_type = 'vendor_payment' AND approval_status = 'approved' THEN 1 ELSE 0 END) as vendor_payment_count")
                )
                ->whereIn('transaction_type', ['expense', 'vendor_purchase', 'vendor_payment'])
                ->whereBetween('transaction_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
                ->get()
                ->keyBy('month_key');
            
            return $data->map(function ($row) use ($financialData) {
                $invoiceTotal = round($row->invoice_total ?? 0, 2);
                $monthKey = $row->month_key;
                
                // Get expenses, vendor purchases, and vendor payments for this month
                $expenseTotal = round($financialData[$monthKey]->expense_total ?? 0, 2);
                $expenseCount = (int) ($financialData[$monthKey]->expense_count ?? 0);
                $vendorPurchaseTotal = round($financialData[$monthKey]->vendor_purchase_total ?? 0, 2);
                $vendorPurchaseCount = (int) ($financialData[$monthKey]->vendor_purchase_count ?? 0);
                $vendorPaymentTotal = round($financialData[$monthKey]->vendor_payment_total ?? 0, 2);
                $vendorPaymentCount = (int) ($financialData[$monthKey]->vendor_payment_count ?? 0);
                
                return [
                    'month' => $row->month_key,
                    'month_name' => $row->month_name,
                    'invoice_total' => $invoiceTotal,
                    'invoice_count' => (int) ($row->invoice_count ?? 0),
                    'unique_customers' => (int) ($row->unique_customers ?? 0),
                    'online_total' => round($row->online_total ?? 0, 2),
                    'online_count' => (int) ($row->online_count ?? 0),
                    'cash_total' => round($row->cash_total ?? 0, 2),
                    'cash_count' => (int) ($row->cash_count ?? 0),
                    'shopify_total' => round($row->shopify_total ?? 0, 2),
                    'shopify_count' => (int) ($row->shopify_count ?? 0),
                    'manual_total' => round($row->manual_total ?? 0, 2),
                    'manual_count' => (int) ($row->manual_count ?? 0),
                    'new_customer_revenue' => round($row->new_customer_revenue ?? 0, 2),
                    'new_customer_orders' => (int) ($row->new_customer_orders ?? 0),
                    'returning_customer_revenue' => round($row->returning_customer_revenue ?? 0, 2),
                    'returning_customer_orders' => (int) ($row->returning_customer_orders ?? 0),
                    'expense_total' => $expenseTotal,
                    'expense_count' => $expenseCount,
                    'vendor_purchase_total' => $vendorPurchaseTotal,
                    'vendor_purchase_count' => $vendorPurchaseCount,
                    'vendor_payment_total' => $vendorPaymentTotal,
                    'vendor_payment_count' => $vendorPaymentCount,
                    // Profit = Invoices - Expenses - Vendor Purchases (NOT payments)
                    'profit' => round($invoiceTotal - $expenseTotal - $vendorPurchaseTotal, 2),
                ];
            })->values()->toArray();
        });
    }

    /**
     * Get monthly product category summary (Level 1 - attribute_1)
     * Groups delivered orders by product category
     */
    public function getMonthlyProductCategorySummary($months = 12)
    {
        $cacheKey = "monthly_product_category_v4_{$months}";
        
        return Cache::remember($cacheKey, 600, function () use ($months) {
            $endDate = Carbon::now()->endOfMonth();
            $startDate = $endDate->copy()->subMonths($months)->startOfMonth();
            
            // Join via SKU which is reliable across all orders
            $data = DB::table('t_crm_prod_order as o')
                ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
                ->join('t_crm_prod_order_line_item as li', 'o.id', '=', 'li.order_id')
                ->leftJoin('t_crm_prod_product_variant as v', 'li.sku', '=', 'v.sku')
                ->leftJoin('t_crm_prod_product as p', 'v.product_id', '=', 'p.id')
                ->select(
                    DB::raw("DATE_FORMAT(h.delivered_at, '%Y-%m') as month_key"),
                    DB::raw("DATE_FORMAT(h.delivered_at, '%b %Y') as month_name"),
                    DB::raw("COALESCE(p.attribute_1, 'Uncategorized') as category"),
                    DB::raw("SUM(li.quantity) as total_qty"),
                    DB::raw("SUM(li.line_total) as total_revenue")
                )
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                ->groupBy(DB::raw("DATE_FORMAT(h.delivered_at, '%Y-%m')"), DB::raw("DATE_FORMAT(h.delivered_at, '%b %Y')"), DB::raw("COALESCE(p.attribute_1, 'Uncategorized')"))
                ->orderBy('month_key')
                ->orderByDesc('total_revenue')
                ->get();
            
            // Group by month
            return $data->groupBy('month_key')->map(function ($items, $monthKey) {
                $firstItem = $items->first();
                return [
                    'month' => $monthKey,
                    'month_name' => $firstItem->month_name ?? Carbon::parse($monthKey . '-01')->format('M Y'),
                    'categories' => $items->map(function ($item) {
                        return [
                            'category' => $item->category,
                            'qty' => round($item->total_qty ?? 0, 2),
                            'revenue' => round($item->total_revenue ?? 0, 2),
                        ];
                    })->values()->toArray()
                ];
            })->values()->toArray();
        });
    }

    /**
     * Get monthly analytics data for the last N months
     * Uses the v_monthly_order_summary view when available, falls back to direct query
     */
    public function getMonthlyAnalytics($months = 12)
    {
        $cacheKey = "monthly_analytics_{$months}";
        
        return Cache::remember($cacheKey, 600, function () use ($months) { // 10 minutes cache
            $endDate = Carbon::now();
            $startDate = $endDate->copy()->subMonths($months);
            
            // Query production orders table directly for speed
            // This avoids the slow JOIN in v_monthly_order_summary
            // NOTE: Only counts delivered/completed/processing orders (actual delivered orders)
            $data = DB::table('t_crm_prod_order')
                ->select(
                    DB::raw("DATE_FORMAT(order_date, '%Y-%m') as month_key"),
                    DB::raw("DATE_FORMAT(order_date, '%b %Y') as month_name"),
                    DB::raw('SUM(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as total_revenue'),
                    // Order count - only delivered/completed/processing orders
                    DB::raw('SUM(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN 1 ELSE 0 END) as order_count'),
                    // Unique customers from delivered orders only
                    DB::raw('COUNT(DISTINCT CASE WHEN order_status IN ("delivered", "completed", "processing") THEN customer_id END) as unique_customers'),
                    DB::raw('AVG(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE NULL END) as avg_order_value'),
                    // Shopify classification based on order_number prefix - only delivered orders
                    DB::raw('SUM(CASE WHEN order_number LIKE "SH%" AND order_status IN ("delivered", "completed", "processing") THEN 1 ELSE 0 END) as shopify_converted_orders'),
                    DB::raw('SUM(CASE WHEN (order_number NOT LIKE "SH%" OR order_number IS NULL) AND order_status IN ("delivered", "completed", "processing") THEN 1 ELSE 0 END) as manual_orders'),
                    DB::raw('SUM(CASE WHEN order_number LIKE "SH%" AND order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as shopify_revenue'),
                    DB::raw('SUM(CASE WHEN (order_number NOT LIKE "SH%" OR order_number IS NULL) AND order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as manual_revenue')
                )
                ->where('order_date', '>=', $startDate->format('Y-m-d'))
                ->where('order_date', '<=', $endDate->format('Y-m-d'))
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->groupBy(DB::raw("DATE_FORMAT(order_date, '%Y-%m')"), DB::raw("DATE_FORMAT(order_date, '%b %Y')"))
                ->orderBy('month_key')
                ->get();
            
            return $data->map(function ($row) {
                return [
                    'month' => $row->month_key,
                    'month_name' => $row->month_name,
                    'revenue' => round($row->total_revenue ?? 0, 2),
                    'orders' => (int) ($row->order_count ?? 0),
                    'customers' => (int) ($row->unique_customers ?? 0),
                    'shopify_orders' => (int) ($row->shopify_converted_orders ?? 0),
                    'manual_orders' => (int) ($row->manual_orders ?? 0),
                    'shopify_revenue' => round($row->shopify_revenue ?? 0, 2),
                    'manual_revenue' => round($row->manual_revenue ?? 0, 2),
                    'avg_order_value' => round($row->avg_order_value ?? 0, 2),
                ];
            })->values()->toArray();
        });
    }

    /**
     * Get daily analytics data for a specific month
     * Queries production orders table directly for speed
     * Payment method from ORDER's payment_method field
     * Includes new vs returning customer split and qty per day
     */
    public function getDailyAnalytics($year, $month)
    {
        $cacheKey = "daily_analytics_delivery_v5_{$year}_{$month}";
        
        return Cache::remember($cacheKey, 600, function () use ($year, $month) {
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            
            // Query delivered orders by DELIVERY DATE (from status history)
            // Payment method from order, customer type from customer table
            $dbData = DB::table('t_crm_prod_order as o')
                ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
                ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
                ->select(
                    DB::raw('DATE(h.delivered_at) as date_key'),
                    DB::raw('SUM(o.total_price) as total_revenue'),
                    DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    DB::raw('COUNT(DISTINCT o.customer_id) as unique_customers'),
                    // Shopify/Manual split (based on order_number)
                    DB::raw('SUM(CASE WHEN o.order_number LIKE "SH%" OR o.order_number LIKE "sh%" THEN 1 ELSE 0 END) as shopify_converted_orders'),
                    DB::raw('SUM(CASE WHEN o.order_number NOT LIKE "SH%" AND o.order_number NOT LIKE "sh%" THEN 1 ELSE 0 END) as manual_orders'),
                    DB::raw('SUM(CASE WHEN o.order_number LIKE "SH%" OR o.order_number LIKE "sh%" THEN o.total_price ELSE 0 END) as shopify_revenue'),
                    DB::raw('SUM(CASE WHEN o.order_number NOT LIKE "SH%" AND o.order_number NOT LIKE "sh%" THEN o.total_price ELSE 0 END) as manual_revenue'),
                    // Online/Cash split from ORDER's payment_method field
                    DB::raw('SUM(CASE WHEN o.payment_method IN ("online", "Online", "ONLINE", "card", "Card") THEN o.total_price ELSE 0 END) as online_total'),
                    DB::raw('SUM(CASE WHEN o.payment_method IN ("online", "Online", "ONLINE", "card", "Card") THEN 1 ELSE 0 END) as online_count'),
                    DB::raw('SUM(CASE WHEN o.payment_method NOT IN ("online", "Online", "ONLINE", "card", "Card") OR o.payment_method IS NULL THEN o.total_price ELSE 0 END) as cash_total'),
                    DB::raw('SUM(CASE WHEN o.payment_method NOT IN ("online", "Online", "ONLINE", "card", "Card") OR o.payment_method IS NULL THEN 1 ELSE 0 END) as cash_count'),
                    // New vs Returning customers (based on first_delivery_date - pre-computed for performance)
                    DB::raw('SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, "%Y-%m") = DATE_FORMAT(h.delivered_at, "%Y-%m") THEN o.total_price ELSE 0 END) as new_customer_revenue'),
                    DB::raw('SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, "%Y-%m") = DATE_FORMAT(h.delivered_at, "%Y-%m") THEN 1 ELSE 0 END) as new_customer_orders'),
                    DB::raw('SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, "%Y-%m") != DATE_FORMAT(h.delivered_at, "%Y-%m") OR c.first_delivery_date IS NULL THEN o.total_price ELSE 0 END) as returning_customer_revenue'),
                    DB::raw('SUM(CASE WHEN DATE_FORMAT(c.first_delivery_date, "%Y-%m") != DATE_FORMAT(h.delivered_at, "%Y-%m") OR c.first_delivery_date IS NULL THEN 1 ELSE 0 END) as returning_customer_orders')
                )
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                ->groupBy(DB::raw('DATE(h.delivered_at)'))
                ->get()
                ->keyBy('date_key');
            
            // Get quantity per day from line items
            $qtyData = DB::table('t_crm_prod_order as o')
                ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
                ->join('t_crm_prod_order_line_item as li', 'o.id', '=', 'li.order_id')
                ->select(
                    DB::raw('DATE(h.delivered_at) as date_key'),
                    DB::raw('SUM(li.quantity) as total_qty')
                )
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                ->groupBy(DB::raw('DATE(h.delivered_at)'))
                ->get()
                ->keyBy('date_key');
            
            // Build complete day range with data
            $dailyData = [];
            $currentDay = $startDate->copy();
            
            while ($currentDay <= $endDate) {
                $dateKey = $currentDay->format('Y-m-d');
                $row = $dbData->get($dateKey);
                $qty = $qtyData->get($dateKey);
                
                $dailyData[] = [
                    'date' => $dateKey,
                    'day_name' => $currentDay->format('M j'),
                    'day_of_week' => $currentDay->format('l'),
                    'revenue' => round($row->total_revenue ?? 0, 2),
                    'orders' => (int) ($row->order_count ?? 0),
                    'customers' => (int) ($row->unique_customers ?? 0),
                    'total_qty' => round($qty->total_qty ?? 0, 2),
                    'shopify_orders' => (int) ($row->shopify_converted_orders ?? 0),
                    'manual_orders' => (int) ($row->manual_orders ?? 0),
                    'shopify_revenue' => round($row->shopify_revenue ?? 0, 2),
                    'manual_revenue' => round($row->manual_revenue ?? 0, 2),
                    'online_total' => round($row->online_total ?? 0, 2),
                    'online_count' => (int) ($row->online_count ?? 0),
                    'cash_total' => round($row->cash_total ?? 0, 2),
                    'cash_count' => (int) ($row->cash_count ?? 0),
                    'new_customer_revenue' => round($row->new_customer_revenue ?? 0, 2),
                    'new_customer_orders' => (int) ($row->new_customer_orders ?? 0),
                    'returning_customer_revenue' => round($row->returning_customer_revenue ?? 0, 2),
                    'returning_customer_orders' => (int) ($row->returning_customer_orders ?? 0),
                    'avg_order_value' => ($row->order_count ?? 0) > 0 ? round(($row->total_revenue ?? 0) / $row->order_count, 2) : 0,
                ];
                
                $currentDay->addDay();
            }
            
            // Get unique monthly customer counts (single count for the whole month)
            $monthlyUniqueCustomers = DB::selectOne("
                SELECT 
                    COUNT(DISTINCT o.customer_id) as total_unique_customers,
                    COUNT(DISTINCT CASE WHEN DATE_FORMAT(c.first_delivery_date, '%Y-%m') = ? THEN o.customer_id END) as unique_new_customers,
                    COUNT(DISTINCT CASE WHEN DATE_FORMAT(c.first_delivery_date, '%Y-%m') != ? OR c.first_delivery_date IS NULL THEN o.customer_id END) as unique_returning_customers
                FROM t_crm_prod_order o
                INNER JOIN (SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = 'delivered' GROUP BY order_id) h 
                    ON o.id = h.order_id
                LEFT JOIN t_crm_prod_customer c ON o.customer_id = c.id
                WHERE h.delivered_at BETWEEN ? AND ?
                AND (o.external_source IS NULL OR o.external_source != 'shopify')
                AND o.order_status NOT IN ('reversed', 'cancelled')
            ", [
                $startDate->format('Y-m'),
                $startDate->format('Y-m'),
                $startDate->format('Y-m-d 00:00:00'),
                $endDate->format('Y-m-d 23:59:59')
            ]);
            
            return [
                'month_name' => $startDate->format('F Y'),
                'month_key' => $startDate->format('Y-m'),
                'unique_customers' => (int) ($monthlyUniqueCustomers->total_unique_customers ?? 0),
                'unique_new_customers' => (int) ($monthlyUniqueCustomers->unique_new_customers ?? 0),
                'unique_returning_customers' => (int) ($monthlyUniqueCustomers->unique_returning_customers ?? 0),
                'data' => $dailyData
            ];
        });
    }

    /**
     * Get daily product category summary (Level 1 - attribute_1)
     * Groups delivered orders by product category for each day
     * Uses variant join (via variant_id or SKU) to get product category
     */
    public function getDailyProductCategorySummary($year, $month)
    {
        $cacheKey = "daily_product_category_v2_{$year}_{$month}";
        
        return Cache::remember($cacheKey, 600, function () use ($year, $month) {
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            
            // Join via variant (using variant_id first, then fallback to SKU matching)
            $data = DB::table('t_crm_prod_order as o')
                ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
                ->join('t_crm_prod_order_line_item as li', 'o.id', '=', 'li.order_id')
                ->leftJoin('t_crm_prod_product_variant as v', function($join) {
                    $join->on('li.variant_id', '=', 'v.id')
                         ->orOn('li.sku', '=', 'v.sku');
                })
                ->leftJoin('t_crm_prod_product as p', 'v.product_id', '=', 'p.id')
                ->select(
                    DB::raw('DATE(h.delivered_at) as date_key'),
                    DB::raw('COALESCE(p.attribute_1, "Uncategorized") as category'),
                    DB::raw('SUM(li.quantity) as total_qty'),
                    DB::raw('SUM(li.line_total) as total_revenue')
                )
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                ->groupBy(DB::raw('DATE(h.delivered_at)'), DB::raw('COALESCE(p.attribute_1, "Uncategorized")'))
                ->orderBy('date_key')
                ->orderByDesc('total_revenue')
                ->get();
            
            // Group by date
            return $data->groupBy('date_key')->map(function ($items, $dateKey) {
                return [
                    'date' => $dateKey,
                    'categories' => $items->map(function ($item) {
                        return [
                            'category' => $item->category,
                            'qty' => round($item->total_qty ?? 0, 2),
                            'revenue' => round($item->total_revenue ?? 0, 2),
                        ];
                    })->values()->toArray()
                ];
            })->values()->toArray();
        });
    }

    /**
     * Get general statistics and KPIs
     */
    public function getGeneralStats()
    {
        $cacheKey = "general_stats";
        
        return Cache::remember($cacheKey, 300, function () {
            $now = Carbon::now();
            
            // Customer stats
            $totalCustomers = CustomerModel::count();
            $active30Days = CustomerModel::where('last_order_date', '>=', $now->copy()->subDays(30))->count();
            $active90Days = CustomerModel::where('last_order_date', '>=', $now->copy()->subDays(90))->count();
            $active7Days = CustomerModel::where('last_order_date', '>=', $now->copy()->subDays(7))->count();
            
            // New customers this month
            $newCustomersThisMonth = CustomerModel::whereYear('first_order_date', $now->year)
                ->whereMonth('first_order_date', $now->month)
                ->count();
            
            // Order stats (excluding Shopify)
            $totalOrders = OrderModel::where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->count();
            
            $deliveredOrders = OrderModel::where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->whereIn('order_status', ['delivered', 'completed'])
                ->count();
            
            $pendingOrders = OrderModel::where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->where('order_status', 'pending')
                ->count();

            $processingOrders = OrderModel::where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->where('order_status', 'processing')
                ->count();
            
            // Revenue stats
            $totalRevenue = OrderModel::where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->whereIn('order_status', self::VALID_REVENUE_STATUSES)
                ->sum('total_price');
            
            $avgOrderValue = OrderModel::where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->whereIn('order_status', self::VALID_REVENUE_STATUSES)
                ->avg('total_price');
            
            // Product stats
            $totalProducts = ProductModel::where('is_active', true)->count();
            
            // Customer lifetime value (average total spent)
            $avgCustomerValue = CustomerModel::where('total_spent', '>', 0)->avg('total_spent');
            
            // Conversion rate (customers who made more than one order)
            $repeatCustomers = CustomerModel::where('total_orders', '>', 1)->count();
            $conversionRate = $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 1) : 0;
            
            // Geographic distribution
            $topCities = CustomerModel::whereNotNull('city')
                ->where('city', '!=', '')
                ->select('city', DB::raw('count(*) as count'))
                ->groupBy('city')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get();
            
            return [
                'customers' => [
                    'total' => $totalCustomers,
                    'active_30_days' => $active30Days,
                    'active_90_days' => $active90Days,
                    'active_7_days' => $active7Days,
                    'new_this_month' => $newCustomersThisMonth,
                    'repeat_customers' => $repeatCustomers,
                    'conversion_rate' => $conversionRate,
                    'avg_lifetime_value' => round($avgCustomerValue ?? 0, 2)
                ],
                'orders' => [
                    'total' => $totalOrders,
                    'delivered' => $deliveredOrders,
                    'completed' => $deliveredOrders, // Keep for backward compatibility
                    'pending' => $pendingOrders,
                    'processing' => $processingOrders,
                    'completion_rate' => $totalOrders > 0 ? round(($deliveredOrders / $totalOrders) * 100, 1) : 0
                ],
                'revenue' => [
                    'total' => round($totalRevenue, 2),
                    'avg_order_value' => round($avgOrderValue ?? 0, 2)
                ],
                'products' => [
                    'total' => $totalProducts
                ],
                'geographic' => $topCities
            ];
        });
    }

    /**
     * Clear dashboard cache
     */
    public function clearCache()
    {
        $timeRanges = ['1', '7', '30', '90'];
        foreach ($timeRanges as $range) {
            Cache::forget("dashboard_kpis_{$range}");
        }
        
        // Clear new cache keys
        Cache::forget('general_stats');
        Cache::forget('order_source_summary');
        Cache::forget('customer_segments');
        Cache::forget('product_categories');
        Cache::forget('weekly_performance');
        
        for ($i = 1; $i <= 24; $i++) {
            Cache::forget("monthly_analytics_{$i}");
            Cache::forget("monthly_delivered_orders_v5_{$i}");
            Cache::forget("monthly_product_category_v2_{$i}");
            Cache::forget("monthly_product_category_v3_{$i}");
        }
        
        // Clear daily analytics (current year + last year)
        $currentYear = date('Y');
        for ($year = $currentYear - 1; $year <= $currentYear; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                Cache::forget("daily_analytics_{$year}_{$month}");
                Cache::forget("daily_analytics_delivery_v2_{$year}_{$month}");
                Cache::forget("daily_product_category_v2_{$year}_{$month}");
            }
        }
        
        // Clear month-specific cache keys (top_cards and financial_summary)
        $now = Carbon::now();
        for ($i = 0; $i < 24; $i++) {
            $monthKey = $now->copy()->subMonths($i)->format('Y-m');
            Cache::forget("top_cards_stats_{$monthKey}");
            Cache::forget("financial_summary_{$monthKey}");
            Cache::forget("product_category_delivery_v2_{$monthKey}_1");
            Cache::forget("product_category_delivery_v2_{$monthKey}_2");
            Cache::forget("product_category_delivery_v3_{$monthKey}_1");
            Cache::forget("product_category_delivery_v3_{$monthKey}_2");
        }
        
        // Clear monthly product category cache
        for ($i = 1; $i <= 24; $i++) {
            Cache::forget("monthly_product_category_v4_{$i}");
        }
    }

    // =========================================================================
    // ENHANCED ANALYTICS METHODS (using database views)
    // =========================================================================

    /**
     * Get top cards statistics for the dashboard header
     * Includes: Invoices, Orders, Expenses, Vendor Payments, Profit, Active Customers
     */
    public function getTopCardsStats($monthKey = null)
    {
        $monthKey = $monthKey ?? Carbon::now()->format('Y-m');
        $cacheKey = "top_cards_stats_{$monthKey}";
        
        return Cache::remember($cacheKey, 300, function () use ($monthKey) {
            $now = Carbon::now();
            $currentMonthStart = Carbon::parse($monthKey . '-01')->startOfMonth();
            $currentMonthEnd = $currentMonthStart->copy()->endOfMonth();
            
            // Financial data from ledger
            $financialData = $this->getFinancialSummary($monthKey);
            
            // Orders this month (excluding Shopify)
            $ordersThisMonth = OrderModel::where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->whereBetween('order_date', [$currentMonthStart, $currentMonthEnd])
                ->count();
            
            // Active customers (90 days from today, regardless of selected month)
            $ninetyDaysAgo = $now->copy()->subDays(90);
            $activeCustomers90Days = CustomerModel::where('last_order_date', '>=', $ninetyDaysAgo)
                ->where('is_active', true)
                ->count();
            
            // New customers in the last 90 days (for the Active 90d card badge)
            $newCustomers90Days = CustomerModel::where('first_order_date', '>=', $ninetyDaysAgo)
                ->count();
            
            // New customers for the selected month (for monthly breakdown)
            $newCustomersThisMonth = CustomerModel::whereBetween('first_order_date', [$currentMonthStart, $currentMonthEnd])
                ->count();
            
            // Returning customers in the last 90 days (first_order_date before 90 days ago)
            $returningCustomers90Days = CustomerModel::where('last_order_date', '>=', $ninetyDaysAgo)
                ->where('first_order_date', '<', $ninetyDaysAgo)
                ->where('is_active', true)
                ->count();
            
            return [
                'month_key' => $monthKey,
                'month_name' => $currentMonthStart->format('F Y'),
                
                // Total Invoices (ALL delivered, excluding reversed)
                'invoices' => $financialData['invoice_total'] ?? 0,
                'invoice_count' => $financialData['invoice_count'] ?? 0,
                
                // Online Invoices Breakdown
                'online_total' => $financialData['online_total'] ?? 0,
                'online_count' => $financialData['online_count'] ?? 0,
                'online_approved_total' => $financialData['online_approved_total'] ?? 0,
                'online_approved_count' => $financialData['online_approved_count'] ?? 0,
                'online_pending_l1_total' => $financialData['online_pending_l1_total'] ?? 0,
                'online_pending_l1_count' => $financialData['online_pending_l1_count'] ?? 0,
                'online_pending_l2_total' => $financialData['online_pending_l2_total'] ?? 0,
                'online_pending_l2_count' => $financialData['online_pending_l2_count'] ?? 0,
                
                // Cash Invoices Breakdown
                'cash_total' => $financialData['cash_total'] ?? 0,
                'cash_count' => $financialData['cash_count'] ?? 0,
                'cash_approved_total' => $financialData['cash_approved_total'] ?? 0,
                'cash_approved_count' => $financialData['cash_approved_count'] ?? 0,
                'cash_pending_total' => $financialData['cash_pending_total'] ?? 0,
                'cash_pending_count' => $financialData['cash_pending_count'] ?? 0,
                
                // Expenses (approved only)
                'expenses' => $financialData['expense_total'] ?? 0,
                'expense_count' => $financialData['expense_count'] ?? 0,
                
                // Vendor Purchases (for profit calculation)
                'vendor_purchases' => $financialData['vendor_purchase_total'] ?? 0,
                'vendor_purchase_count' => $financialData['vendor_purchase_count'] ?? 0,
                
                // Vendor Payments (approved only)
                'vendor_payments' => $financialData['vendor_payment_total'] ?? 0,
                'vendor_payment_count' => $financialData['vendor_payment_count'] ?? 0,
                
                // Profit = Total Invoices - Expenses - Vendor Purchases (NOT payments)
                'profit' => $financialData['monthly_profit'] ?? 0,
                
                // Orders
                'orders_this_month' => $ordersThisMonth,
                
                // Customers - 90 day stats (independent of selected month)
                'active_customers_90d' => $activeCustomers90Days,
                'new_customers_90d' => $newCustomers90Days,
                'returning_customers_90d' => $returningCustomers90Days,
                // Also keep monthly stats for reference
                'new_customers_this_month' => $newCustomersThisMonth,
            ];
        });
    }

    /**
     * Get active customers list for popup (last 90 days)
     * Returns both new and returning customers with their details
     */
    public function getActiveCustomersList($filter = 'all')
    {
        $ninetyDaysAgo = Carbon::now()->subDays(90);
        
        $query = CustomerModel::where('last_order_date', '>=', $ninetyDaysAgo)
            ->where('is_active', true)
            ->select('id', 'first_name', 'last_name', 'phone', 'city', 'first_order_date', 'last_order_date', 'total_orders', 'total_spent');
        
        if ($filter === 'new') {
            $query->where('first_order_date', '>=', $ninetyDaysAgo);
        } elseif ($filter === 'returning') {
            $query->where('first_order_date', '<', $ninetyDaysAgo);
        }
        
        $customers = $query->orderBy('last_order_date', 'desc')
            ->limit(500)
            ->get();
        
        return $customers->map(function ($c) use ($ninetyDaysAgo) {
            $isNew = Carbon::parse($c->first_order_date) >= $ninetyDaysAgo;
                return [
                'id' => $c->id,
                'name' => trim($c->first_name . ' ' . $c->last_name),
                'phone' => $c->phone,
                'city' => $c->city,
                'first_order_date' => $c->first_order_date ? Carbon::parse($c->first_order_date)->format('M j, Y') : null,
                'last_order_date' => $c->last_order_date ? Carbon::parse($c->last_order_date)->format('M j, Y') : null,
                'total_orders' => $c->total_orders,
                'total_spent' => $c->total_spent,
                'type' => $isNew ? 'new' : 'returning',
            ];
        })->toArray();
    }

    /**
     * Get financial summary
     * Shows ALL delivered invoices with breakdown by payment mode
     * USES DELIVERY DATE (from order status history) - queries orders directly, not ledger
     * This matches the daily tab calculations exactly
     */
    public function getFinancialSummary($monthKey = null)
    {
        $monthKey = $monthKey ?? Carbon::now()->format('Y-m');
        $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        
        // Get ALL delivered invoices DIRECTLY from orders (not ledger to avoid duplications)
        // USES DELIVERY DATE from order status history
        // Payment method comes from ORDER's payment_method field
        $invoiceData = DB::table('t_crm_prod_order as o')
            ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
            ->leftJoin('t_fin_ledger as l', function($join) {
                $join->on('l.order_id', '=', 'o.id')
                     ->where('l.transaction_type', '=', LedgerModel::TYPE_INVOICE)
                     ->where('l.approval_status', '!=', LedgerModel::STATUS_REVERSED);
            })
            ->where(function($q) {
                $q->where('o.external_source', '!=', 'shopify')
                  ->orWhereNull('o.external_source');
            })
            ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
            ->selectRaw("
                o.payment_method,
                COALESCE(l.approval_status, 'pending') as approval_status,
                SUM(o.total_price) as total,
                COUNT(DISTINCT o.id) as count
            ")
            ->groupBy('o.payment_method', DB::raw("COALESCE(l.approval_status, 'pending')"))
            ->get();
        
        // Calculate totals and breakdowns
        $invoiceTotal = 0;
        $invoiceCount = 0;
        
        // Online breakdown
        $onlineTotal = 0;
        $onlineCount = 0;
        $onlineApprovedTotal = 0;
        $onlineApprovedCount = 0;
        $onlinePendingL1Total = 0;
        $onlinePendingL1Count = 0;
        $onlinePendingL2Total = 0;
        $onlinePendingL2Count = 0;
        
        // Cash breakdown
        $cashTotal = 0;
        $cashCount = 0;
        $cashApprovedTotal = 0;
        $cashApprovedCount = 0;
        $cashPendingTotal = 0;
        $cashPendingCount = 0;
        
        $onlinePaymentMethods = ['online', 'Online', 'ONLINE', 'card', 'Card'];
        
        foreach ($invoiceData as $row) {
            $invoiceTotal += $row->total;
            $invoiceCount += $row->count;
            
            $isOnline = in_array($row->payment_method, $onlinePaymentMethods);
            
            if ($isOnline) {
                $onlineTotal += $row->total;
                $onlineCount += $row->count;
                
                if ($row->approval_status === LedgerModel::STATUS_APPROVED) {
                    $onlineApprovedTotal += $row->total;
                    $onlineApprovedCount += $row->count;
                } elseif ($row->approval_status === LedgerModel::STATUS_PENDING_L2) {
                    $onlinePendingL2Total += $row->total;
                    $onlinePendingL2Count += $row->count;
                } else {
                    // pending, pending_l1 - treat as L1
                    $onlinePendingL1Total += $row->total;
                    $onlinePendingL1Count += $row->count;
                }
            } else {
                // Cash mode
                $cashTotal += $row->total;
                $cashCount += $row->count;
                
                if ($row->approval_status === LedgerModel::STATUS_APPROVED) {
                    $cashApprovedTotal += $row->total;
                    $cashApprovedCount += $row->count;
                } else {
                    $cashPendingTotal += $row->total;
                    $cashPendingCount += $row->count;
                }
            }
        }
        
        // Expenses - only approved (use whereDate for DATE column comparison)
        $expenses = LedgerModel::whereDate('transaction_date', '>=', $startDate->format('Y-m-d'))
            ->whereDate('transaction_date', '<=', $endDate->format('Y-m-d'))
            ->where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->first();
        
        // Vendor Payments - only approved (use whereDate for DATE column comparison)
        $vendorPayments = LedgerModel::whereDate('transaction_date', '>=', $startDate->format('Y-m-d'))
            ->whereDate('transaction_date', '<=', $endDate->format('Y-m-d'))
            ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->first();
        
        // Vendor Purchases - only approved (use whereDate for DATE column comparison)
        $vendorPurchases = LedgerModel::whereDate('transaction_date', '>=', $startDate->format('Y-m-d'))
            ->whereDate('transaction_date', '<=', $endDate->format('Y-m-d'))
            ->where('transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->first();
        
        return [
            'month_key' => $monthKey,
            'month_name' => $startDate->format('F Y'),
            
            // Total invoices (ALL delivered, excluding reversed)
            'invoice_total' => round($invoiceTotal, 2),
            'invoice_count' => $invoiceCount,
            
            // Online invoices breakdown
            'online_total' => round($onlineTotal, 2),
            'online_count' => $onlineCount,
            'online_approved_total' => round($onlineApprovedTotal, 2),
            'online_approved_count' => $onlineApprovedCount,
            'online_pending_l1_total' => round($onlinePendingL1Total, 2),
            'online_pending_l1_count' => $onlinePendingL1Count,
            'online_pending_l2_total' => round($onlinePendingL2Total, 2),
            'online_pending_l2_count' => $onlinePendingL2Count,
            
            // Cash invoices breakdown
            'cash_total' => round($cashTotal, 2),
            'cash_count' => $cashCount,
            'cash_approved_total' => round($cashApprovedTotal, 2),
            'cash_approved_count' => $cashApprovedCount,
            'cash_pending_total' => round($cashPendingTotal, 2),
            'cash_pending_count' => $cashPendingCount,
            
            // Expenses
            'expense_total' => round($expenses->total ?? 0, 2),
            'expense_count' => (int) ($expenses->count ?? 0),
            
            // Vendor Purchases (for profit calculation)
            'vendor_purchase_total' => round($vendorPurchases->total ?? 0, 2),
            'vendor_purchase_count' => (int) ($vendorPurchases->count ?? 0),
            
            // Vendor Payments (separate from purchases)
            'vendor_payment_total' => round($vendorPayments->total ?? 0, 2),
            'vendor_payment_count' => (int) ($vendorPayments->count ?? 0),
            
            // Profit: Total Invoices - Expenses - Vendor Purchases (NOT payments)
            'monthly_profit' => round($invoiceTotal - ($expenses->total ?? 0) - ($vendorPurchases->total ?? 0), 2),
        ];
    }

    /**
     * Get ledger transactions for a specific month and type
     * Used for drilldown popups (expenses, vendor payments, invoices)
     */
    public function getLedgerTransactionsForMonth($monthKey, $type = 'invoice', $limit = null)
    {
        $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        
        if ($type === 'invoice') {
            // Get delivered orders with delivery details
            $query = DB::table('t_crm_prod_order as o')
                ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
                ->leftJoin('t_fin_ledger as l', function($join) {
                    $join->on('l.order_id', '=', 'o.id')
                         ->where('l.transaction_type', '=', LedgerModel::TYPE_INVOICE)
                         ->where('l.approval_status', '!=', LedgerModel::STATUS_REVERSED);
                })
                ->leftJoin('t_crm_prod_customer as c', 'o.customer_id', '=', 'c.id')
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                ->select(
                    'o.id',
                    'o.order_number',
                    'o.order_date',
                    'h.delivered_at as delivery_date',
                    'o.total_price as amount',
                    'o.payment_method',
                    DB::raw("COALESCE(l.approval_status, 'pending') as approval_status"),
                    DB::raw("CONCAT(c.first_name, ' ', c.last_name) as customer_name"),
                    'c.phone as customer_phone'
                )
                ->orderBy('h.delivered_at', 'desc');
            
            if ($limit) {
                $query->limit($limit);
            }
            
            return $query->get()
                ->map(function ($row) {
                    return [
                        'id' => $row->id,
                        'order_number' => $row->order_number,
                        'order_date' => Carbon::parse($row->order_date)->format('M j, Y'),
                        'delivery_date' => Carbon::parse($row->delivery_date)->format('M j, Y'),
                        'amount' => round($row->amount, 2),
                        'payment_method' => ucfirst($row->payment_method ?? 'cash'),
                        'approval_status' => $row->approval_status,
                        'customer_name' => $row->customer_name,
                        'customer_phone' => $row->customer_phone,
                    ];
                })->toArray();
        }
        
        // For expenses, vendor purchases, and vendor payments, use transaction_date from ledger
        if ($type === 'expense') {
            $transactionType = LedgerModel::TYPE_EXPENSE;
        } elseif ($type === 'vendor_purchase') {
            $transactionType = LedgerModel::TYPE_VENDOR_PURCHASE;
        } else {
            $transactionType = LedgerModel::TYPE_VENDOR_PAYMENT;
        }
        
        // Use date strings for comparison (transaction_date is a DATE column)
        // Join with users table to get created_by user name
        $query = DB::table('t_fin_ledger as l')
            ->leftJoin('t_sys_user as u', 'l.created_by', '=', 'u.id')
            ->whereDate('l.transaction_date', '>=', $startDate->format('Y-m-d'))
            ->whereDate('l.transaction_date', '<=', $endDate->format('Y-m-d'))
            ->where('l.transaction_type', $transactionType)
            ->where('l.approval_status', LedgerModel::STATUS_APPROVED)
            ->orderBy('l.transaction_date', 'desc')
            ->select('l.id', 'l.description', 'l.amount', 'l.mode', 'l.transaction_date', 'l.created_at', 'u.fullname as created_by_name');
        
        if ($limit) {
            $query->limit($limit);
        }
        
        return $query->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'description' => $row->description,
                    'amount' => round($row->amount, 2),
                    'mode' => ucfirst($row->mode ?? 'cash'),
                    'date' => Carbon::parse($row->transaction_date)->format('M j, Y'),
                    'created_by' => $row->created_by_name ?? 'Unknown',
                ];
            })->toArray();
    }

    /**
     * Get order source summary (Shopify Converted vs Manual)
     * Uses v_order_source_summary view
     */
    public function getOrderSourceSummary($monthKey = null)
    {
        $monthKey = $monthKey ?? Carbon::now()->format('Y-m');
        $cacheKey = "order_source_summary_{$monthKey}";
        
        return Cache::remember($cacheKey, 300, function () use ($monthKey) {
            // Try to use the database view
            try {
                $viewData = DB::table('v_order_source_summary')
                    ->where('month_key', $monthKey)
                    ->first();
                
                if ($viewData) {
                    return [
                        'month_key' => $viewData->month_key,
                        'shopify_converted_count' => (int) $viewData->shopify_converted_count,
                        'manual_count' => (int) $viewData->manual_count,
                        'total_count' => (int) $viewData->total_count,
                        'shopify_revenue' => round($viewData->shopify_revenue, 2),
                        'manual_revenue' => round($viewData->manual_revenue, 2),
                        'total_revenue' => round($viewData->total_revenue, 2),
                        'shopify_percentage' => (float) $viewData->shopify_percentage,
                        'manual_percentage' => (float) $viewData->manual_percentage,
                        'shopify_customers' => (int) $viewData->shopify_customers,
                        'manual_customers' => (int) $viewData->manual_customers,
                    ];
                }
            } catch (\Exception $e) {
                Log::debug('Order source summary view not available: ' . $e->getMessage());
            }
            
            // Fallback: Return zeros if view doesn't exist
            return [
                'month_key' => $monthKey,
                'shopify_converted_count' => 0,
                'manual_count' => 0,
                'total_count' => 0,
                'shopify_revenue' => 0,
                'manual_revenue' => 0,
                'total_revenue' => 0,
                'shopify_percentage' => 0,
                'manual_percentage' => 100,
                'shopify_customers' => 0,
                'manual_customers' => 0,
            ];
        });
    }

    /**
     * Get customer analysis data
     * Uses delivery dates for month-specific stats to be consistent with other dashboard metrics
     */
    public function getCustomerAnalysis($monthKey = null)
    {
        $monthKey = $monthKey ?? Carbon::now()->format('Y-m');
        $cacheKey = "customer_analysis_v2_{$monthKey}";
        
        return Cache::remember($cacheKey, 300, function () use ($monthKey) {
            $now = Carbon::now();
            $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            
            // Get total customers (non-merged)
            $totalCustomers = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->count();
            
            // New customers: those whose first_delivery_date (pre-computed, includes history) is in this month
            // Uses pre-computed column for consistency with charts
            $monthFormat = $startDate->format('Y-m');
            $newCustomersResult = DB::selectOne("
                SELECT COUNT(DISTINCT o.customer_id) as count
                FROM t_crm_prod_order o
                INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
                INNER JOIN t_crm_prod_customer c ON o.customer_id = c.id
                WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
                AND o.order_status NOT IN ('reversed', 'cancelled')
                AND c.merged_into_customer_id IS NULL
                AND DATE_FORMAT(c.first_delivery_date, '%Y-%m') = ?
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $monthFormat]);
            $newCustomers = $newCustomersResult->count ?? 0;
            
            // Returning customers: had delivered orders this month, but their first_delivery_date is before this month
            $returningCustomersResult = DB::selectOne("
                SELECT COUNT(DISTINCT o.customer_id) as count
                FROM t_crm_prod_order o
                INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
                INNER JOIN t_crm_prod_customer c ON o.customer_id = c.id
                WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
                AND o.order_status NOT IN ('reversed', 'cancelled')
                AND c.merged_into_customer_id IS NULL
                AND (DATE_FORMAT(c.first_delivery_date, '%Y-%m') != ? OR c.first_delivery_date IS NULL)
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $monthFormat]);
            $returningCustomers = $returningCustomersResult->count ?? 0;
            
            // Get order stats for this month (based on delivery date)
            $orderStats = DB::selectOne("
                SELECT COUNT(*) as total_orders, COALESCE(SUM(o.total_price), 0) as total_spend
                FROM t_crm_prod_order o
                INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
                WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
                AND o.order_status != 'reversed'
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            
            // Activity segments - using simple queries (always from today)
            $active7d = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->where('last_order_date', '>=', $now->copy()->subDays(7)->format('Y-m-d'))
                ->count();
            
            $active30d = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->where('last_order_date', '>=', $now->copy()->subDays(30)->format('Y-m-d'))
                ->count();
            
            $active90d = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->where('last_order_date', '>=', $now->copy()->subDays(90)->format('Y-m-d'))
                ->count();
            
            // Frequency segments
            $highFrequency = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->where('total_orders', '>=', 10)
                ->count();
            
            $mediumFrequency = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->whereBetween('total_orders', [3, 9])
                ->count();
            
            $lowFrequency = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->whereBetween('total_orders', [1, 2])
                ->count();
            
            return [
                'month_key' => $monthKey,
                'classification' => [
                    'total_customers' => (int) $totalCustomers,
                    'new_customers' => (int) $newCustomers,
                    'returning_customers' => (int) $returningCustomers,
                    'total_orders' => (int) ($orderStats->total_orders ?? 0),
                    'total_spend' => round($orderStats->total_spend ?? 0, 2),
                ],
                'activity_segments' => [
                    'ACTIVE_7D' => (int) $active7d,
                    'ACTIVE_30D' => (int) $active30d,
                    'ACTIVE_90D' => (int) $active90d,
                ],
                'frequency_segments' => [
                    'HIGH' => (int) $highFrequency,
                    'MEDIUM' => (int) $mediumFrequency,
                    'LOW' => (int) $lowFrequency,
                ],
            ];
        });
    }

    /**
     * Get product category breakdown
     * Queries order line items and products directly
     */
    public function getProductCategorySummary($monthKey = null, $categoryLevel = 1)
    {
        $monthKey = $monthKey ?? Carbon::now()->format('Y-m');
        $cacheKey = "product_category_delivery_v3_{$monthKey}_{$categoryLevel}";
        
        return Cache::remember($cacheKey, 300, function () use ($monthKey, $categoryLevel) {
            // Map category level to product attribute column
            $categoryColumn = "attribute_{$categoryLevel}";
            
            $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            
            // Query order line items with product info - USES DELIVERY DATE
            // Join via SKU which is reliable across all orders
            $data = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->join(DB::raw('(SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = "delivered" GROUP BY order_id) as h'), 'o.id', '=', 'h.order_id')
                ->leftJoin('t_crm_prod_product_variant as v', 'li.sku', '=', 'v.sku')
                ->leftJoin('t_crm_prod_product as p', 'v.product_id', '=', 'p.id')
                ->whereBetween('h.delivered_at', [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')])
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->select(
                    DB::raw("COALESCE(p.{$categoryColumn}, 'Uncategorized') as category"),
                    DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    DB::raw('COUNT(DISTINCT o.customer_id) as unique_customers'),
                    DB::raw('SUM(li.quantity) as total_quantity'),
                    DB::raw('SUM(li.line_total) as total_revenue'),
                    DB::raw('SUM(CASE WHEN o.order_number LIKE "SH%" OR o.order_number LIKE "sh%" THEN 1 ELSE 0 END) as shopify_orders'),
                    DB::raw('SUM(CASE WHEN o.order_number NOT LIKE "SH%" AND o.order_number NOT LIKE "sh%" THEN 1 ELSE 0 END) as manual_orders'),
                    DB::raw('SUM(CASE WHEN o.order_number LIKE "SH%" OR o.order_number LIKE "sh%" THEN li.line_total ELSE 0 END) as shopify_revenue'),
                    DB::raw('SUM(CASE WHEN o.order_number NOT LIKE "SH%" AND o.order_number NOT LIKE "sh%" THEN li.line_total ELSE 0 END) as manual_revenue')
                )
                ->groupBy(DB::raw("COALESCE(p.{$categoryColumn}, 'Uncategorized')"))
                ->orderByRaw('SUM(li.line_total) DESC')
                ->limit(20)
                ->get();
            
            return $data->map(function ($row) {
                return [
                    'category' => $row->category ?? 'Uncategorized',
                    'order_count' => (int) ($row->order_count ?? 0),
                    'unique_customers' => (int) ($row->unique_customers ?? 0),
                    'total_quantity' => (int) ($row->total_quantity ?? 0),
                    'total_revenue' => round($row->total_revenue ?? 0, 2),
                    'shopify_orders' => (int) ($row->shopify_orders ?? 0),
                    'manual_orders' => (int) ($row->manual_orders ?? 0),
                    'shopify_revenue' => round($row->shopify_revenue ?? 0, 2),
                    'manual_revenue' => round($row->manual_revenue ?? 0, 2),
                ];
            })->values()->toArray();
        });
    }

    /**
     * Get weekly day performance (best performing days of week)
     * Queries production orders table directly for speed
     * NOTE: Only counts delivered/completed/processing orders
     */
    public function getWeeklyDayPerformance()
    {
        $cacheKey = "weekly_day_performance";
        
        return Cache::remember($cacheKey, 600, function () {
            $data = DB::table('t_crm_prod_order')
                ->select(
                    DB::raw('DAYOFWEEK(order_date) as day_of_week'),
                    DB::raw('DAYNAME(order_date) as day_name'),
                    // Only count delivered orders
                    DB::raw('SUM(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN 1 ELSE 0 END) as total_orders'),
                    DB::raw('COUNT(DISTINCT CASE WHEN order_status IN ("delivered", "completed", "processing") THEN customer_id END) as total_customers'),
                    DB::raw('SUM(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as total_revenue'),
                    DB::raw('AVG(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE NULL END) as avg_order_value'),
                    DB::raw('SUM(CASE WHEN order_number LIKE "SH%" AND order_status IN ("delivered", "completed", "processing") THEN 1 ELSE 0 END) as shopify_orders'),
                    DB::raw('SUM(CASE WHEN (order_number NOT LIKE "SH%" OR order_number IS NULL) AND order_status IN ("delivered", "completed", "processing") THEN 1 ELSE 0 END) as manual_orders'),
                    DB::raw('COUNT(DISTINCT CASE WHEN order_status IN ("delivered", "completed", "processing") THEN DATE(order_date) END) as days_count')
                )
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->groupBy(DB::raw('DAYOFWEEK(order_date)'), DB::raw('DAYNAME(order_date)'))
                ->orderBy('day_of_week')
                ->get();
            
            return $data->map(function ($row) {
                return [
                    'day_of_week' => (int) $row->day_of_week,
                    'day_name' => $row->day_name,
                    'total_orders' => (int) ($row->total_orders ?? 0),
                    'total_customers' => (int) ($row->total_customers ?? 0),
                    'total_revenue' => round($row->total_revenue ?? 0, 2),
                    'avg_order_value' => round($row->avg_order_value ?? 0, 2),
                    'shopify_orders' => (int) ($row->shopify_orders ?? 0),
                    'manual_orders' => (int) ($row->manual_orders ?? 0),
                    'days_count' => (int) ($row->days_count ?? 0),
                    'avg_orders_per_day' => ($row->days_count ?? 0) > 0 ? round(($row->total_orders ?? 0) / $row->days_count, 1) : 0,
                ];
            })->values()->toArray();
        });
    }

    /**
     * Get month over month growth data
     * Uses v_month_over_month_growth view
     */
    public function getMonthOverMonthGrowth($months = 6)
    {
        $cacheKey = "mom_growth_{$months}";
        
        return Cache::remember($cacheKey, 600, function () use ($months) {
            // Get monthly data and calculate growth
            $monthlyData = $this->getMonthlyAnalytics($months + 1);
            $result = [];
            
            // Sort by month (descending) for comparison
            $sorted = collect($monthlyData)->sortByDesc('month')->values();
            
            for ($i = 0; $i < $sorted->count() && $i < $months; $i++) {
                $current = $sorted[$i] ?? null;
                $previous = $sorted[$i + 1] ?? null;
                
                if ($current) {
                    $revenueGrowth = ($previous && $previous['revenue'] > 0) 
                        ? round((($current['revenue'] - $previous['revenue']) / $previous['revenue']) * 100, 1) 
                        : 0;
                    $orderGrowth = ($previous && $previous['orders'] > 0) 
                        ? round((($current['orders'] - $previous['orders']) / $previous['orders']) * 100, 1) 
                        : 0;
                    $customerGrowth = ($previous && $previous['customers'] > 0) 
                        ? round((($current['customers'] - $previous['customers']) / $previous['customers']) * 100, 1) 
                        : 0;
                    
                    $result[] = [
                        'month_key' => $current['month'],
                        'month_name' => $current['month_name'],
                        'current_orders' => $current['orders'],
                        'current_revenue' => $current['revenue'],
                        'current_customers' => $current['customers'],
                        'previous_orders' => $previous['orders'] ?? 0,
                        'previous_revenue' => $previous['revenue'] ?? 0,
                        'previous_customers' => $previous['customers'] ?? 0,
                        'order_growth_pct' => $orderGrowth,
                        'revenue_growth_pct' => $revenueGrowth,
                        'customer_growth_pct' => $customerGrowth,
                        'current_shopify' => $current['shopify_orders'] ?? 0,
                        'current_manual' => $current['manual_orders'] ?? 0,
                    ];
                }
            }
            
            return $result;
        });
    }

    /**
     * Get customer cohort analysis
     * Queries customer table directly for speed
     */
    public function getCustomerCohort($months = 12)
    {
        $cacheKey = "customer_cohort_delivery_v2_{$months}";
        
        return Cache::remember($cacheKey, 600, function () use ($months) {
            $startDate = Carbon::now()->subMonths($months)->startOfMonth();
            
            // Use first_delivery_date for cohort (consistent with other dashboard metrics)
            // Use last_delivery_date for active status (consistent with dormancy bands)
            $data = DB::table('t_crm_prod_customer')
                ->select(
                    DB::raw("DATE_FORMAT(first_delivery_date, '%Y-%m') as cohort_month"),
                    DB::raw('COUNT(*) as cohort_size'),
                    DB::raw('SUM(CASE WHEN last_delivery_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as active_30d'),
                    DB::raw('SUM(CASE WHEN last_delivery_date >= DATE_SUB(NOW(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) as active_60d'),
                    DB::raw('SUM(CASE WHEN last_delivery_date >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as active_90d'),
                    DB::raw('AVG(total_orders) as avg_orders'),
                    DB::raw('AVG(total_spent) as avg_lifetime_value')
                )
                ->where('first_delivery_date', '>=', $startDate->format('Y-m-d'))
                ->whereNotNull('first_delivery_date')
                ->whereNull('merged_into_customer_id')
                ->groupBy(DB::raw("DATE_FORMAT(first_delivery_date, '%Y-%m')"))
                ->orderByDesc('cohort_month')
                ->limit($months)
                ->get();
            
            return $data->map(function ($row) {
                $retentionRate = $row->cohort_size > 0 ? round(($row->active_90d / $row->cohort_size) * 100, 1) : 0;
                return [
                    'cohort_month' => $row->cohort_month,
                    'cohort_size' => (int) ($row->cohort_size ?? 0),
                    'active_30d' => (int) ($row->active_30d ?? 0),
                    'active_60d' => (int) ($row->active_60d ?? 0),
                    'active_90d' => (int) ($row->active_90d ?? 0),
                    'retention_rate_90d' => $retentionRate,
                    'avg_orders' => round($row->avg_orders ?? 0, 1),
                    'avg_lifetime_value' => round($row->avg_lifetime_value ?? 0, 2),
                ];
            })->values()->toArray();
        });
    }

    /**
     * Get orders for a specific date (for drill-down)
     */
    public function getOrdersForDate($date, $source = null)
    {
        $query = OrderModel::with(['customer:id,first_name,last_name,phone_normalized', 'lineItems:order_id,name,quantity,line_total'])
            ->whereDate('order_date', $date)
            ->where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            });
        
        // Filter by source if specified
        if ($source === 'shopify') {
            $query->where(function($q) {
                $q->where('order_number', 'LIKE', 'SH%')
                  ->orWhere('order_number', 'LIKE', 'sh%');
            });
        } elseif ($source === 'manual') {
            $query->where(function($q) {
                $q->where('order_number', 'NOT LIKE', 'SH%')
                  ->where('order_number', 'NOT LIKE', 'sh%');
            });
        }
        
        return $query->orderBy('order_date', 'desc')
            ->select('id', 'order_number', 'order_date', 'customer_id', 'total_price', 'order_status', 'payment_method')
            ->limit(100)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_date' => $order->order_date->format('Y-m-d H:i'),
                    'customer_name' => $order->customer ? trim($order->customer->first_name . ' ' . $order->customer->last_name) : 'Unknown',
                    'customer_phone' => $order->customer->phone_normalized ?? '',
                    'total_price' => round($order->total_price, 2),
                    'order_status' => $order->order_status,
                    'payment_method' => $order->payment_method,
                    'items_count' => $order->lineItems->count(),
                    'items' => $order->lineItems->map(function ($item) {
                        return [
                            'name' => $item->name,
                            'quantity' => $item->quantity,
                            'total' => round($item->line_total, 2),
                        ];
                    }),
                ];
            });
    }

    /**
     * Get month over month growth using LEDGER data (not orders)
     * This ensures consistency with the Invoice card
     */
    public function getMonthOverMonthLedgerGrowth($months = 6)
    {
        $cacheKey = "mom_ledger_growth_{$months}";
        
        return Cache::remember($cacheKey, 600, function () use ($months) {
            $ledgerData = $this->getMonthlyLedgerAnalytics($months + 1);
            $result = [];
            
            // Sort by month (descending) for comparison
            $sorted = collect($ledgerData)->sortByDesc('month')->values();
            
            for ($i = 0; $i < $sorted->count() && $i < $months; $i++) {
                $current = $sorted[$i] ?? null;
                $previous = $sorted[$i + 1] ?? null;
                
                if ($current) {
                    $revenueGrowth = ($previous && $previous['invoice_total'] > 0) 
                        ? round((($current['invoice_total'] - $previous['invoice_total']) / $previous['invoice_total']) * 100, 1) 
                        : 0;
                    
                    $result[] = [
                        'month_key' => $current['month'],
                        'month_name' => $current['month_name'],
                        'current_revenue' => $current['invoice_total'],
                        'current_invoices' => $current['invoice_count'],
                        'previous_revenue' => $previous['invoice_total'] ?? 0,
                        'previous_invoices' => $previous['invoice_count'] ?? 0,
                        'revenue_growth_pct' => $revenueGrowth,
                        'current_online' => $current['online_total'] ?? 0,
                        'current_cash' => $current['cash_total'] ?? 0,
                        'profit' => $current['profit'] ?? 0,
                    ];
                }
            }
            
            return $result;
            });
    }
    
    /**
     * Get customer dormancy bands (days since last order)
     * Includes ALL customers - both with orders and never ordered
     * Bands: 0-30, 31-60, 61-90, 91-120, 121-180, >180, Never Ordered
     */
    public function getCustomerDormancyBands()
    {
        $cacheKey = 'dashboard:customer_dormancy_bands_v5';
        
        return Cache::remember($cacheKey, 300, function () {
            // Use last_delivery_date for dormancy (consistent with cohort and other metrics)
            // This shows when customers last received a delivered order
            $results = DB::select("
                SELECT 
                    CASE 
                        WHEN last_delivery_date IS NULL THEN 'never'
                        WHEN DATEDIFF(CURDATE(), DATE(last_delivery_date)) BETWEEN 0 AND 30 THEN '0-30'
                        WHEN DATEDIFF(CURDATE(), DATE(last_delivery_date)) BETWEEN 31 AND 60 THEN '31-60'
                        WHEN DATEDIFF(CURDATE(), DATE(last_delivery_date)) BETWEEN 61 AND 90 THEN '61-90'
                        WHEN DATEDIFF(CURDATE(), DATE(last_delivery_date)) BETWEEN 91 AND 120 THEN '91-120'
                        WHEN DATEDIFF(CURDATE(), DATE(last_delivery_date)) BETWEEN 121 AND 180 THEN '121-180'
                        ELSE '>180'
                    END as band,
                    COUNT(*) as customer_count
                FROM t_crm_prod_customer
                WHERE merged_into_customer_id IS NULL
                GROUP BY band
            ");
            
            // Map to expected format with correct order
            $bandConfig = [
                '0-30' => '0-30 days',
                '31-60' => '31-60 days',
                '61-90' => '61-90 days',
                '91-120' => '91-120 days',
                '121-180' => '121-180 days',
                '>180' => '180+ days',
                'never' => 'Never Ordered'
            ];
            
            // Initialize all bands with 0
            $bandCounts = [];
            foreach ($bandConfig as $key => $label) {
                $bandCounts[$key] = ['band' => $key, 'label' => $label, 'count' => 0];
            }
            
            // Fill in counts from query results
            foreach ($results as $row) {
                if (isset($bandCounts[$row->band])) {
                    $bandCounts[$row->band]['count'] = (int)$row->customer_count;
                }
            }
            
            return array_values($bandCounts);
        });
    }
    
    /**
     * Get customer list for specific dormancy band
     */
    public function getCustomerDormancyList($band)
    {
        $today = Carbon::today();
        
        // Handle "never ordered" customers
        if ($band === 'never') {
            return CustomerModel::whereNull('last_order_date')
                ->whereNull('merged_into_customer_id')
                ->select('id', 'first_name', 'last_name', 'phone', 'total_orders', 'total_spent', 'last_order_date', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(500)
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'name' => trim($customer->first_name . ' ' . $customer->last_name),
                        'phone' => $customer->phone,
                        'total_orders' => 0,
                        'total_spent' => 0,
                        'last_order_date' => 'Never',
                    ];
                })
                ->toArray();
        }
        
        // Define band ranges
        $bandRanges = [
            '0-30' => ['min' => 0, 'max' => 30],
            '31-60' => ['min' => 31, 'max' => 60],
            '61-90' => ['min' => 61, 'max' => 90],
            '91-120' => ['min' => 91, 'max' => 120],
            '121-180' => ['min' => 121, 'max' => 180],
            '>180' => ['min' => 181, 'max' => 999999],
        ];
        
        $range = $bandRanges[$band] ?? ['min' => 0, 'max' => 30];
        
        // Calculate date range
        $minDate = $today->copy()->subDays($range['max'])->format('Y-m-d');
        $maxDate = $today->copy()->subDays($range['min'])->format('Y-m-d');
        
        // For '>180' band, use a very old date as min
        if ($band === '>180') {
            $minDate = '2000-01-01';
            $maxDate = $today->copy()->subDays(181)->format('Y-m-d');
        }
        
        return CustomerModel::whereNotNull('last_order_date')
            ->whereNull('merged_into_customer_id')
            ->whereDate('last_order_date', '>=', $minDate)
            ->whereDate('last_order_date', '<=', $maxDate)
            ->select('id', 'first_name', 'last_name', 'phone', 'total_orders', 'total_spent', 'last_order_date')
            ->orderBy('last_order_date', 'desc')
            ->limit(500)
            ->get()
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'name' => trim($customer->first_name . ' ' . $customer->last_name),
                    'phone' => $customer->phone,
                    'total_orders' => $customer->total_orders ?? 0,
                    'total_spent' => round($customer->total_spent ?? 0, 2),
                    'last_order_date' => $customer->last_order_date ? Carbon::parse($customer->last_order_date)->format('M j, Y') : null,
                ];
            })
            ->toArray();
    }
    
    /**
     * Get customer list by type (total, new, returning) for a specific month
     * Uses delivery dates (from status history) to match the card counts
     */
    public function getCustomerListByType($type, $monthKey = null)
    {
        $startDate = $monthKey ? Carbon::parse($monthKey . '-01') : Carbon::now()->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        
        if ($type === 'total') {
            // All customers who had delivered orders in the selected month
            $customerIds = DB::select("
                SELECT DISTINCT o.customer_id
                FROM t_crm_prod_order o
                INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
                INNER JOIN t_crm_prod_customer c ON o.customer_id = c.id
                WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
                AND o.order_status NOT IN ('reversed', 'cancelled')
                AND c.merged_into_customer_id IS NULL
                AND (o.external_source IS NULL OR o.external_source != 'shopify')
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
            
            $ids = array_map(fn($row) => $row->customer_id, $customerIds);
            
            if (empty($ids)) {
                return [];
            }
            
            return CustomerModel::whereIn('id', $ids)
                ->select('id', 'first_name', 'last_name', 'phone', 'total_orders', 'total_spent', 'first_order_date', 'last_order_date')
                ->orderBy('last_order_date', 'desc')
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'name' => trim($customer->first_name . ' ' . $customer->last_name),
                        'phone' => $customer->phone,
                        'total_orders' => $customer->total_orders ?? 0,
                        'total_spent' => round($customer->total_spent ?? 0, 2),
                        'first_order_date' => $customer->first_order_date ? Carbon::parse($customer->first_order_date)->format('M j, Y') : null,
                        'last_order_date' => $customer->last_order_date ? Carbon::parse($customer->last_order_date)->format('M j, Y') : null,
                    ];
                })
                ->toArray();
        }
        
        if ($type === 'new') {
            // New customers: first_delivery_date (pre-computed, includes history) is in this month
            $monthFormat = $startDate->format('Y-m');
            $customerIds = DB::select("
                SELECT DISTINCT o.customer_id
                FROM t_crm_prod_order o
                INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
                INNER JOIN t_crm_prod_customer c ON o.customer_id = c.id
                WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
                AND o.order_status NOT IN ('reversed', 'cancelled')
                AND c.merged_into_customer_id IS NULL
                AND (o.external_source IS NULL OR o.external_source != 'shopify')
                AND DATE_FORMAT(c.first_delivery_date, '%Y-%m') = ?
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $monthFormat]);
            
            $ids = array_map(fn($row) => $row->customer_id, $customerIds);
            
            if (empty($ids)) {
                return [];
            }
            
            return CustomerModel::whereIn('id', $ids)
                ->select('id', 'first_name', 'last_name', 'phone', 'total_orders', 'total_spent', 'first_order_date', 'last_order_date')
                ->orderBy('last_order_date', 'desc')
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'name' => trim($customer->first_name . ' ' . $customer->last_name),
                        'phone' => $customer->phone,
                        'total_orders' => $customer->total_orders ?? 0,
                        'total_spent' => round($customer->total_spent ?? 0, 2),
                        'first_order_date' => $customer->first_order_date ? Carbon::parse($customer->first_order_date)->format('M j, Y') : null,
                        'last_order_date' => $customer->last_order_date ? Carbon::parse($customer->last_order_date)->format('M j, Y') : null,
                    ];
                })
                ->toArray();
        }
        
        if ($type === 'returning') {
            // Returning customers: had DELIVERED order in this month but first_delivery_date is before this month
            $monthFormat = $startDate->format('Y-m');
            $customerIds = DB::select("
                SELECT DISTINCT o.customer_id
                FROM t_crm_prod_order o
                INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
                INNER JOIN t_crm_prod_customer c ON o.customer_id = c.id
                WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
                AND o.order_status NOT IN ('reversed', 'cancelled')
                AND c.merged_into_customer_id IS NULL
                AND (o.external_source IS NULL OR o.external_source != 'shopify')
                AND (DATE_FORMAT(c.first_delivery_date, '%Y-%m') != ? OR c.first_delivery_date IS NULL)
            ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $monthFormat]);
            
            $ids = array_map(fn($row) => $row->customer_id, $customerIds);
            
            if (empty($ids)) {
                return [];
            }
            
            return CustomerModel::whereIn('id', $ids)
                ->select('id', 'first_name', 'last_name', 'phone', 'total_orders', 'total_spent', 'first_order_date', 'last_order_date')
                ->orderBy('last_order_date', 'desc')
                ->get()
                ->map(function ($customer) {
                    return [
                        'id' => $customer->id,
                        'name' => trim($customer->first_name . ' ' . $customer->last_name),
                        'phone' => $customer->phone,
                        'total_orders' => $customer->total_orders ?? 0,
                        'total_spent' => round($customer->total_spent ?? 0, 2),
                        'first_order_date' => $customer->first_order_date ? Carbon::parse($customer->first_order_date)->format('M j, Y') : null,
                        'last_order_date' => $customer->last_order_date ? Carbon::parse($customer->last_order_date)->format('M j, Y') : null,
                    ];
                })
                ->toArray();
        }
        
        return [];
    }
    
    /**
     * Get month-specific customer stats for the top card
     * Uses pre-computed first_delivery_date for consistency with charts
     * Includes historical orders context
     */
    public function getMonthCustomerStats($monthKey = null)
    {
        $startDate = $monthKey ? Carbon::parse($monthKey . '-01') : Carbon::now()->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        $monthFormat = $startDate->format('Y-m');
        
        // Customers who had delivered orders in this month (unique customers)
        // Using raw SQL for clarity and reliability
        $monthCustomersResult = DB::selectOne("
            SELECT COUNT(DISTINCT o.customer_id) as count
            FROM t_crm_prod_order o
            INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
            WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
            AND o.order_status NOT IN ('reversed', 'cancelled')
        ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
        
        $monthCustomers = $monthCustomersResult->count ?? 0;
        
        // New customers: those whose first_delivery_date (pre-computed, includes history) is in this month
        // This is consistent with the bar chart which also uses first_delivery_date
        $newCustomersResult = DB::selectOne("
            SELECT COUNT(DISTINCT o.customer_id) as count
            FROM t_crm_prod_order o
            INNER JOIN t_crm_order_status_history h ON o.id = h.order_id AND h.status_code = 'delivered'
            INNER JOIN t_crm_prod_customer c ON o.customer_id = c.id
            WHERE DATE(h.changed_at) >= ? AND DATE(h.changed_at) <= ?
            AND o.order_status NOT IN ('reversed', 'cancelled')
            AND c.merged_into_customer_id IS NULL
            AND DATE_FORMAT(c.first_delivery_date, '%Y-%m') = ?
        ", [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $monthFormat]);
        
        $newCustomers = $newCustomersResult->count ?? 0;
        
        return [
            'month_customers' => $monthCustomers,
            'new_customers' => $newCustomers
        ];
    }
}
