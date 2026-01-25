<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\DashboardAnalyticsService;
use Carbon\Carbon;

class DashboardController extends Controller
{
    protected $analyticsService;

    public function __construct(DashboardAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Display the enhanced dashboard with analytics
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        // Now using enhanced dashboard as the main dashboard
        return view('dashboard-enhanced', compact('user'));
    }
    
    /**
     * Display enhanced dashboard (separate route to avoid breaking main dashboard)
     */
    public function enhanced(Request $request)
    {
        $user = Auth::user();
        return view('dashboard-enhanced', compact('user'));
    }

    /**
     * Get dashboard KPIs via AJAX
     */
    public function getKPIs(Request $request)
    {
        $timeRange = $request->get('range', '30');
        $kpis = $this->analyticsService->getDashboardKPIs($timeRange);
        
        return response()->json([
            'success' => true,
            'data' => $kpis
        ]);
    }

    /**
     * Get revenue chart data
     */
    public function getRevenueChart(Request $request)
    {
        $timeRange = $request->get('range', '30');
        $chartData = $this->analyticsService->getRevenueChartData($timeRange);
        
        return response()->json([
            'success' => true,
            'data' => $chartData
        ]);
    }

    /**
     * Get customer growth chart data
     */
    public function getCustomerGrowthChart(Request $request)
    {
        $timeRange = $request->get('range', '30');
        $chartData = $this->analyticsService->getCustomerGrowthChartData($timeRange);
        
        return response()->json([
            'success' => true,
            'data' => $chartData
        ]);
    }

    /**
     * Get monthly analytics data
     */
    public function getMonthlyAnalytics(Request $request)
    {
        try {
            $months = $request->get('months', 12); // Default to 12 months
            $data = $this->analyticsService->getMonthlyAnalytics($months);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Monthly analytics error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get daily analytics data for a specific month
     */
    public function getDailyAnalytics(Request $request)
    {
        $year = $request->get('year', date('Y'));
        $month = $request->get('month', date('n'));
        $data = $this->analyticsService->getDailyAnalytics($year, $month);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get general statistics
     */
    public function getGeneralStats(Request $request)
    {
        $data = $this->analyticsService->getGeneralStats();
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Clear dashboard cache
     */
    public function clearCache()
    {
        $this->analyticsService->clearCache();
        
        return response()->json([
            'success' => true,
            'message' => 'Dashboard cache cleared successfully'
        ]);
    }

    // =========================================================================
    // ENHANCED ANALYTICS ENDPOINTS
    // =========================================================================

    /**
     * Get top cards statistics for dashboard header
     * Returns: Invoices, Orders, Expenses, Vendor Payments, Profit, Active Customers
     */
    public function getTopCards(Request $request)
    {
        $monthKey = $request->get('month', Carbon::now()->format('Y-m'));
        $data = $this->analyticsService->getTopCardsStats($monthKey);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get financial summary (Invoices, Expenses, Vendor Payments, Profit)
     */
    public function getFinancialSummary(Request $request)
    {
        $monthKey = $request->get('month', Carbon::now()->format('Y-m'));
        $data = $this->analyticsService->getFinancialSummary($monthKey);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get order source summary (Shopify Converted vs Manual)
     */
    public function getOrderSourceSummary(Request $request)
    {
        $monthKey = $request->get('month', Carbon::now()->format('Y-m'));
        $data = $this->analyticsService->getOrderSourceSummary($monthKey);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get customer analysis data (New vs Returning, Activity Segments)
     */
    public function getCustomerAnalysis(Request $request)
    {
        try {
            $monthKey = $request->get('month', Carbon::now()->format('Y-m'));
            $data = $this->analyticsService->getCustomerAnalysis($monthKey);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Customer analysis error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get product category breakdown
     */
    public function getProductCategories(Request $request)
    {
        try {
            $monthKey = $request->get('month', Carbon::now()->format('Y-m'));
            $level = $request->get('level', 1);
            $data = $this->analyticsService->getProductCategorySummary($monthKey, $level);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Product categories error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get weekly day performance (best selling days)
     */
    public function getWeeklyPerformance(Request $request)
    {
        try {
            $data = $this->analyticsService->getWeeklyDayPerformance();
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Weekly performance error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get month over month growth data
     */
    public function getMonthOverMonthGrowth(Request $request)
    {
        try {
            $months = $request->get('months', 6);
            $data = $this->analyticsService->getMonthOverMonthGrowth($months);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('MoM growth error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get customer cohort analysis
     */
    public function getCustomerCohort(Request $request)
    {
        try {
            $months = $request->get('months', 12);
            $data = $this->analyticsService->getCustomerCohort($months);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Customer cohort error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get orders for a specific date (drill-down)
     */
    public function getOrdersForDate(Request $request)
    {
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));
        $source = $request->get('source'); // 'shopify', 'manual', or null for all
        $data = $this->analyticsService->getOrdersForDate($date, $source);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get monthly ledger analytics (invoices, expenses, vendor payments from ledger)
     * This ensures graphs match the top cards exactly
     */
    public function getMonthlyLedgerAnalytics(Request $request)
    {
        try {
            $months = $request->get('months', 12);
            $data = $this->analyticsService->getMonthlyLedgerAnalytics($months);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Monthly ledger analytics error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get ledger transactions for drilldown (expenses, vendor payments, invoices)
     */
    public function getLedgerTransactions(Request $request)
    {
        try {
            $monthKey = $request->get('month', Carbon::now()->format('Y-m'));
            $type = $request->get('type', 'invoice'); // invoice, expense, vendor_payment
            $data = $this->analyticsService->getLedgerTransactionsForMonth($monthKey, $type);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Ledger transactions error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get monthly product category summary (Level 1)
     */
    public function getMonthlyProductCategories(Request $request)
    {
        try {
            $months = $request->get('months', 12);
            $data = $this->analyticsService->getMonthlyProductCategorySummary($months);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Monthly product categories error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get daily product category summary (Level 1)
     */
    public function getDailyProductCategories(Request $request)
    {
        try {
            $year = $request->get('year', date('Y'));
            $month = $request->get('month', date('n'));
            $data = $this->analyticsService->getDailyProductCategorySummary($year, $month);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Daily product categories error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * Get active customers list for popup (last 90 days)
     */
    public function getActiveCustomersList(Request $request)
    {
        try {
            $filter = $request->get('filter', 'all'); // all, new, returning
            $data = $this->analyticsService->getActiveCustomersList($filter);
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Active customers list error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ]);
        }
    }
}
