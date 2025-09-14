<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\DashboardAnalyticsService;

class DashboardController extends Controller
{
    protected $analyticsService;

    public function __construct(DashboardAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Display the dashboard with analytics
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Use enhanced dashboard with multi-level analytics
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
        $months = $request->get('months', 12); // Default to 12 months
        $data = $this->analyticsService->getMonthlyAnalytics($months);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
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
}
