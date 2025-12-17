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
            $data = DB::table('t_crm_prod_order')
                ->select(
                    DB::raw("DATE_FORMAT(order_date, '%Y-%m') as month_key"),
                    DB::raw("DATE_FORMAT(order_date, '%b %Y') as month_name"),
                    DB::raw('SUM(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as total_revenue'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('COUNT(DISTINCT customer_id) as unique_customers'),
                    DB::raw('AVG(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE NULL END) as avg_order_value'),
                    // Shopify classification based on order_number prefix
                    DB::raw('SUM(CASE WHEN order_number LIKE "SH%" THEN 1 ELSE 0 END) as shopify_converted_orders'),
                    DB::raw('SUM(CASE WHEN order_number NOT LIKE "SH%" OR order_number IS NULL THEN 1 ELSE 0 END) as manual_orders'),
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
     */
    public function getDailyAnalytics($year, $month)
    {
        $cacheKey = "daily_analytics_{$year}_{$month}";
        
        return Cache::remember($cacheKey, 600, function () use ($year, $month) {
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            
            // Query production orders table directly
            $dbData = DB::table('t_crm_prod_order')
                ->select(
                    DB::raw('DATE(order_date) as date_key'),
                    DB::raw('SUM(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as total_revenue'),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('COUNT(DISTINCT customer_id) as unique_customers'),
                    DB::raw('SUM(CASE WHEN order_number LIKE "SH%" THEN 1 ELSE 0 END) as shopify_converted_orders'),
                    DB::raw('SUM(CASE WHEN order_number NOT LIKE "SH%" OR order_number IS NULL THEN 1 ELSE 0 END) as manual_orders'),
                    DB::raw('SUM(CASE WHEN order_number LIKE "SH%" AND order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as shopify_revenue'),
                    DB::raw('SUM(CASE WHEN (order_number NOT LIKE "SH%" OR order_number IS NULL) AND order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as manual_revenue')
                )
                ->where('order_date', '>=', $startDate->format('Y-m-d'))
                ->where('order_date', '<=', $endDate->format('Y-m-d'))
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->groupBy(DB::raw('DATE(order_date)'))
                ->get()
                ->keyBy('date_key');
            
            // Build complete day range with data
            $dailyData = [];
            $currentDay = $startDate->copy();
            
            while ($currentDay <= $endDate) {
                $dateKey = $currentDay->format('Y-m-d');
                $row = $dbData->get($dateKey);
                
                $dailyData[] = [
                    'date' => $dateKey,
                    'day_name' => $currentDay->format('M j'),
                    'day_of_week' => $currentDay->format('l'),
                    'revenue' => round($row->total_revenue ?? 0, 2),
                    'orders' => (int) ($row->order_count ?? 0),
                    'customers' => (int) ($row->unique_customers ?? 0),
                    'shopify_orders' => (int) ($row->shopify_converted_orders ?? 0),
                    'manual_orders' => (int) ($row->manual_orders ?? 0),
                    'shopify_revenue' => round($row->shopify_revenue ?? 0, 2),
                    'manual_revenue' => round($row->manual_revenue ?? 0, 2),
                    'avg_order_value' => ($row->order_count ?? 0) > 0 ? round(($row->total_revenue ?? 0) / $row->order_count, 2) : 0,
                ];
                
                $currentDay->addDay();
            }
            
            return [
                'month_name' => $startDate->format('F Y'),
                'data' => $dailyData
            ];
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
        Cache::forget('top_cards_stats');
        Cache::forget('financial_summary');
        Cache::forget('order_source_summary');
        Cache::forget('customer_segments');
        Cache::forget('product_categories');
        Cache::forget('weekly_performance');
        
        for ($i = 1; $i <= 24; $i++) {
            Cache::forget("monthly_analytics_{$i}");
        }
        
        // Clear daily analytics (current year + last year)
        $currentYear = date('Y');
        for ($year = $currentYear - 1; $year <= $currentYear; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                Cache::forget("daily_analytics_{$year}_{$month}");
            }
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
            
            // Active customers (90 days)
            $activeCustomers90Days = CustomerModel::where('last_order_date', '>=', $now->copy()->subDays(90))
                ->where('is_active', true)
                ->count();
            
            // New customers this month
            $newCustomersThisMonth = CustomerModel::whereBetween('first_order_date', [$currentMonthStart, $currentMonthEnd])
                ->count();
            
            return [
                'month_key' => $monthKey,
                'month_name' => $currentMonthStart->format('F Y'),
                
                // Financial cards
                'invoices' => $financialData['invoice_total'] ?? 0,
                'invoice_count' => $financialData['invoice_count'] ?? 0,
                'expenses' => $financialData['expense_total'] ?? 0,
                'expense_count' => $financialData['expense_count'] ?? 0,
                'vendor_payments' => $financialData['vendor_payment_total'] ?? 0,
                'vendor_payment_count' => $financialData['vendor_payment_count'] ?? 0,
                'profit' => $financialData['monthly_profit'] ?? 0,
                
                // Orders
                'orders_this_month' => $ordersThisMonth,
                
                // Customers
                'active_customers_90d' => $activeCustomers90Days,
                'new_customers_this_month' => $newCustomersThisMonth,
            ];
        });
    }

    /**
     * Get financial summary from ledger (uses v_financial_monthly_summary view)
     */
    public function getFinancialSummary($monthKey = null)
    {
        $monthKey = $monthKey ?? Carbon::now()->format('Y-m');
        
        // Try to use the database view first
        try {
            $viewData = DB::table('v_financial_monthly_summary')
                ->where('month_key', $monthKey)
                ->first();
            
            if ($viewData) {
                return [
                    'month_key' => $viewData->month_key,
                    'month_name' => $viewData->month_name,
                    'invoice_total' => round($viewData->invoice_total, 2),
                    'invoice_count' => (int) $viewData->invoice_count,
                    'expense_total' => round($viewData->expense_total, 2),
                    'expense_count' => (int) $viewData->expense_count,
                    'vendor_payment_total' => round($viewData->vendor_payment_total, 2),
                    'vendor_payment_count' => (int) $viewData->vendor_payment_count,
                    'vendor_purchase_total' => round($viewData->vendor_purchase_total, 2),
                    'vendor_purchase_count' => (int) $viewData->vendor_purchase_count,
                    'deposit_total' => round($viewData->deposit_total, 2),
                    'deposit_count' => (int) $viewData->deposit_count,
                    'monthly_profit' => round($viewData->monthly_profit, 2),
                ];
            }
        } catch (\Exception $e) {
            Log::debug('Financial summary view not available: ' . $e->getMessage());
        }
        
        // Fallback: Calculate from ledger table directly
        $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        
        $invoices = LedgerModel::whereBetween('transaction_date', [$startDate, $endDate])
            ->where('transaction_type', LedgerModel::TYPE_INVOICE)
            ->where('approval_status', 'approved')
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->first();
        
        $expenses = LedgerModel::whereBetween('transaction_date', [$startDate, $endDate])
            ->where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', 'approved')
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->first();
        
        $vendorPayments = LedgerModel::whereBetween('transaction_date', [$startDate, $endDate])
            ->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->where('approval_status', 'approved')
            ->selectRaw('SUM(amount) as total, COUNT(*) as count')
            ->first();
        
        return [
            'month_key' => $monthKey,
            'month_name' => $startDate->format('F Y'),
            'invoice_total' => round($invoices->total ?? 0, 2),
            'invoice_count' => (int) ($invoices->count ?? 0),
            'expense_total' => round($expenses->total ?? 0, 2),
            'expense_count' => (int) ($expenses->count ?? 0),
            'vendor_payment_total' => round($vendorPayments->total ?? 0, 2),
            'vendor_payment_count' => (int) ($vendorPayments->count ?? 0),
            'vendor_purchase_total' => 0,
            'vendor_purchase_count' => 0,
            'deposit_total' => 0,
            'deposit_count' => 0,
            'monthly_profit' => round(($invoices->total ?? 0) - ($expenses->total ?? 0) - ($vendorPayments->total ?? 0), 2),
        ];
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
     * Uses v_customer_monthly_classification and v_customer_activity_segments views
     */
    public function getCustomerAnalysis($monthKey = null)
    {
        $monthKey = $monthKey ?? Carbon::now()->format('Y-m');
        $cacheKey = "customer_analysis_{$monthKey}";
        
        return Cache::remember($cacheKey, 300, function () use ($monthKey) {
            $now = Carbon::now();
            $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            
            // Get total active customers
            $totalCustomers = DB::table('t_crm_prod_customer')
                ->where('is_active', true)
                ->count();
            
            // Get new customers this month
            $newCustomers = DB::table('t_crm_prod_customer')
                ->whereBetween('first_order_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d 23:59:59')])
                ->count();
            
            // Get returning customers (ordered this month but first order was before this month)
            $returningCustomers = DB::table('t_crm_prod_order')
                ->join('t_crm_prod_customer', 't_crm_prod_order.customer_id', '=', 't_crm_prod_customer.id')
                ->whereBetween('t_crm_prod_order.order_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d 23:59:59')])
                ->where('t_crm_prod_customer.first_order_date', '<', $startDate->format('Y-m-d'))
                ->where(function($q) {
                    $q->where('t_crm_prod_order.external_source', '!=', 'shopify')
                      ->orWhereNull('t_crm_prod_order.external_source');
                })
                ->distinct('t_crm_prod_order.customer_id')
                ->count('t_crm_prod_order.customer_id');
            
            // Get order stats for this month
            $orderStats = DB::table('t_crm_prod_order')
                ->whereBetween('order_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d 23:59:59')])
                ->whereIn('order_status', ['delivered', 'completed', 'processing'])
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->selectRaw('COUNT(*) as total_orders, SUM(total_price) as total_spend')
                ->first();
            
            // Activity segments - using simple queries
            $active7d = DB::table('t_crm_prod_customer')
                ->where('last_order_date', '>=', $now->copy()->subDays(7)->format('Y-m-d'))
                ->count();
            
            $active30d = DB::table('t_crm_prod_customer')
                ->where('last_order_date', '>=', $now->copy()->subDays(30)->format('Y-m-d'))
                ->count();
            
            $active90d = DB::table('t_crm_prod_customer')
                ->where('last_order_date', '>=', $now->copy()->subDays(90)->format('Y-m-d'))
                ->count();
            
            // Frequency segments
            $highFrequency = DB::table('t_crm_prod_customer')
                ->where('total_orders', '>=', 10)
                ->where('is_active', true)
                ->count();
            
            $mediumFrequency = DB::table('t_crm_prod_customer')
                ->whereBetween('total_orders', [3, 9])
                ->where('is_active', true)
                ->count();
            
            $lowFrequency = DB::table('t_crm_prod_customer')
                ->whereBetween('total_orders', [1, 2])
                ->where('is_active', true)
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
        $cacheKey = "product_category_{$monthKey}_{$categoryLevel}";
        
        return Cache::remember($cacheKey, 300, function () use ($monthKey, $categoryLevel) {
            // Map category level to product attribute column
            $categoryColumn = "attribute_{$categoryLevel}";
            
            $startDate = Carbon::parse($monthKey . '-01')->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
            
            // Query order line items with product info
            $data = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                ->whereBetween('o.order_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d 23:59:59')])
                ->whereIn('o.order_status', ['delivered', 'completed', 'processing'])
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->select(
                    DB::raw("COALESCE(p.{$categoryColumn}, 'Uncategorized') as category"),
                    DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    DB::raw('COUNT(DISTINCT o.customer_id) as unique_customers'),
                    DB::raw('SUM(li.quantity) as total_quantity'),
                    DB::raw('SUM(li.total_price) as total_revenue'),
                    DB::raw('SUM(CASE WHEN o.order_number LIKE "SH%" THEN 1 ELSE 0 END) as shopify_orders'),
                    DB::raw('SUM(CASE WHEN o.order_number NOT LIKE "SH%" OR o.order_number IS NULL THEN 1 ELSE 0 END) as manual_orders'),
                    DB::raw('SUM(CASE WHEN o.order_number LIKE "SH%" THEN li.total_price ELSE 0 END) as shopify_revenue'),
                    DB::raw('SUM(CASE WHEN o.order_number NOT LIKE "SH%" OR o.order_number IS NULL THEN li.total_price ELSE 0 END) as manual_revenue')
                )
                ->groupBy(DB::raw("COALESCE(p.{$categoryColumn}, 'Uncategorized')"))
                ->orderByRaw('SUM(li.total_price) DESC')
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
     */
    public function getWeeklyDayPerformance()
    {
        $cacheKey = "weekly_day_performance";
        
        return Cache::remember($cacheKey, 600, function () {
            $data = DB::table('t_crm_prod_order')
                ->select(
                    DB::raw('DAYOFWEEK(order_date) as day_of_week'),
                    DB::raw('DAYNAME(order_date) as day_name'),
                    DB::raw('COUNT(*) as total_orders'),
                    DB::raw('COUNT(DISTINCT customer_id) as total_customers'),
                    DB::raw('SUM(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE 0 END) as total_revenue'),
                    DB::raw('AVG(CASE WHEN order_status IN ("delivered", "completed", "processing") THEN total_price ELSE NULL END) as avg_order_value'),
                    DB::raw('SUM(CASE WHEN order_number LIKE "SH%" THEN 1 ELSE 0 END) as shopify_orders'),
                    DB::raw('SUM(CASE WHEN order_number NOT LIKE "SH%" OR order_number IS NULL THEN 1 ELSE 0 END) as manual_orders'),
                    DB::raw('COUNT(DISTINCT DATE(order_date)) as days_count')
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
        $cacheKey = "customer_cohort_{$months}";
        
        return Cache::remember($cacheKey, 600, function () use ($months) {
            $startDate = Carbon::now()->subMonths($months)->startOfMonth();
            
            $data = DB::table('t_crm_prod_customer')
                ->select(
                    DB::raw("DATE_FORMAT(first_order_date, '%Y-%m') as cohort_month"),
                    DB::raw('COUNT(*) as cohort_size'),
                    DB::raw('SUM(CASE WHEN last_order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as active_30d'),
                    DB::raw('SUM(CASE WHEN last_order_date >= DATE_SUB(NOW(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) as active_60d'),
                    DB::raw('SUM(CASE WHEN last_order_date >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as active_90d'),
                    DB::raw('AVG(total_orders) as avg_orders'),
                    DB::raw('AVG(total_spent) as avg_lifetime_value')
                )
                ->where('first_order_date', '>=', $startDate->format('Y-m-d'))
                ->whereNotNull('first_order_date')
                ->groupBy(DB::raw("DATE_FORMAT(first_order_date, '%Y-%m')"))
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
}
