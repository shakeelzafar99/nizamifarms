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
        $timeRange = $request->get('range', '30'); // Default to 30 days
        
        // Get dashboard KPIs
        $kpis = $this->analyticsService->getDashboardKPIs($timeRange);
        
        return view('dashboard', compact('user', 'kpis', 'timeRange'));
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
