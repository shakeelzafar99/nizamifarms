<?php

namespace App\Services;

use App\Models\CRM\OrderModel;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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
     * Get revenue-related KPIs (excluding Shopify)
     */
    public function getRevenueKPIs($startDate, $endDate)
    {
        // Current period revenue (excluding Shopify)
        $currentRevenue = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereIn('order_status', ['completed', 'processing'])
            ->sum('total_price');

        // Previous period for comparison
        $daysDiff = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($daysDiff);
        $previousEnd = $startDate->copy()->subDay();
        
        $previousRevenue = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereBetween('order_date', [$previousStart, $previousEnd])
            ->whereIn('order_status', ['completed', 'processing'])
            ->sum('total_price');

        // Today's revenue
        $todayRevenue = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereDate('order_date', Carbon::today())
            ->whereIn('order_status', ['completed', 'processing'])
            ->sum('total_price');

        // Average Order Value
        $avgOrderValue = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereIn('order_status', ['completed', 'processing'])
            ->avg('total_price');

        return [
            'current' => round($currentRevenue, 2),
            'previous' => round($previousRevenue, 2),
            'today' => round($todayRevenue, 2),
            'growth' => $previousRevenue > 0 ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1) : 0,
            'avg_order_value' => round($avgOrderValue, 2),
        ];
    }

    /**
     * Get order-related KPIs (excluding Shopify)
     */
    public function getOrderKPIs($startDate, $endDate)
    {
        // Current period orders
        $currentOrders = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereBetween('order_date', [$startDate, $endDate])
            ->count();

        // Previous period for comparison
        $daysDiff = $startDate->diffInDays($endDate);
        $previousStart = $startDate->copy()->subDays($daysDiff);
        $previousEnd = $startDate->copy()->subDay();
        
        $previousOrders = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereBetween('order_date', [$previousStart, $previousEnd])
            ->count();

        // Today's orders
        $todayOrders = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereDate('order_date', Carbon::today())
            ->count();

        // Order status distribution
        $statusDistribution = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereBetween('order_date', [$startDate, $endDate])
            ->select('order_status', DB::raw('count(*) as count'))
            ->groupBy('order_status')
            ->pluck('count', 'order_status')
            ->toArray();

        // Source distribution (excluding Shopify)
        $sourceDistribution = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
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
            ->whereIn('o.order_status', ['completed', 'processing'])
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
            ->whereIn('o.order_status', ['completed', 'processing'])
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

        $data = OrderModel::where('external_source', '!=', 'shopify')
            ->orWhereNull('external_source')
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereIn('order_status', ['completed', 'processing'])
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
     * Clear dashboard cache
     */
    public function clearCache()
    {
        $timeRanges = ['1', '7', '30', '90'];
        foreach ($timeRanges as $range) {
            Cache::forget("dashboard_kpis_{$range}");
        }
    }
}
