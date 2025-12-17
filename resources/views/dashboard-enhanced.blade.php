@extends('layouts.app')

@section('title', 'Analytics Dashboard')

@section('content')
<div class="kt-container-fixed">
    <!-- Header Section -->
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-medium leading-none text-mono">
                Business Analytics Dashboard
            </h1>
            <div class="flex items-center gap-2 text-sm font-normal text-secondary-foreground">
                Comprehensive insights across multiple time dimensions
            </div>
        </div>
        
        <!-- Controls -->
        <div class="flex items-center gap-2.5">
            <button id="refreshBtn" class="kt-btn kt-btn-outline kt-btn-sm">
                <i class="ki-filled ki-arrows-circle text-base"></i>
                Refresh
            </button>
            <button id="clearCacheBtn" class="kt-btn kt-btn-outline kt-btn-sm text-orange-600">
                <i class="ki-filled ki-trash text-base"></i>
                Clear Cache
            </button>
        </div>
    </div>

    <!-- Loading Indicator -->
    <div id="dashboardLoading" class="hidden">
        <div class="flex items-center justify-center py-10">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <span class="ml-3 text-gray-600">Loading dashboard data...</span>
        </div>
    </div>

    <!-- =========================================================================
         TOP CARDS SECTION - Always Visible Financial & Customer Overview
         ========================================================================= -->
    <div class="mb-6">
        <!-- Month Filter for Top Cards -->
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-base font-semibold text-gray-700">Overview</h2>
            <select id="topCardsMonthSelector" class="select select-sm w-36">
                <!-- Will be populated by JS -->
            </select>
        </div>
        
        <!-- Top Cards Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
            <!-- Invoices -->
            <div class="kt-card cursor-pointer hover:shadow-lg transition-shadow" onclick="showDrilldown('invoices')">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-green-100 rounded-lg">
                            <i class="ki-filled ki-receipt text-green-600 text-lg"></i>
                        </div>
                        <span id="topInvoiceCount" class="text-xs text-gray-500">0</span>
                    </div>
                    <p class="text-xs font-medium text-gray-500 mb-1">Invoices</p>
                    <p id="topInvoices" class="text-lg font-bold text-gray-900">PKR 0</p>
                </div>
            </div>

            <!-- Expenses -->
            <div class="kt-card cursor-pointer hover:shadow-lg transition-shadow" onclick="showDrilldown('expenses')">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-red-100 rounded-lg">
                            <i class="ki-filled ki-minus-circle text-red-600 text-lg"></i>
                        </div>
                        <span id="topExpenseCount" class="text-xs text-gray-500">0</span>
                    </div>
                    <p class="text-xs font-medium text-gray-500 mb-1">Expenses</p>
                    <p id="topExpenses" class="text-lg font-bold text-gray-900">PKR 0</p>
                </div>
            </div>

            <!-- Vendor Payments -->
            <div class="kt-card cursor-pointer hover:shadow-lg transition-shadow" onclick="showDrilldown('vendor')">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-orange-100 rounded-lg">
                            <i class="ki-filled ki-truck text-orange-600 text-lg"></i>
                        </div>
                        <span id="topVendorCount" class="text-xs text-gray-500">0</span>
                    </div>
                    <p class="text-xs font-medium text-gray-500 mb-1">Vendor Payments</p>
                    <p id="topVendorPayments" class="text-lg font-bold text-gray-900">PKR 0</p>
                </div>
            </div>

            <!-- Profit -->
            <div class="kt-card">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-emerald-100 rounded-lg">
                            <i class="ki-filled ki-chart-line-up text-emerald-600 text-lg"></i>
                        </div>
                    </div>
                    <p class="text-xs font-medium text-gray-500 mb-1">Profit</p>
                    <p id="topProfit" class="text-lg font-bold text-emerald-600">PKR 0</p>
                </div>
            </div>

            <!-- Active Customers -->
            <div class="kt-card">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-purple-100 rounded-lg">
                            <i class="ki-filled ki-people text-purple-600 text-lg"></i>
                        </div>
                        <span id="topNewCustomers" class="text-xs text-green-600 font-medium">+0 new</span>
                    </div>
                    <p class="text-xs font-medium text-gray-500 mb-1">Active (90d)</p>
                    <p id="topActiveCustomers" class="text-lg font-bold text-gray-900">0</p>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         TAB NAVIGATION
         ========================================================================= -->
    <div class="mb-5">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-6">
                <button id="monthlyTab" class="dashboard-tab active border-b-2 border-blue-500 py-2 px-1 text-sm font-medium text-blue-600">
                    <i class="ki-filled ki-calendar-tick mr-1"></i> Monthly
                </button>
                <button id="dailyTab" class="dashboard-tab border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <i class="ki-filled ki-calendar mr-1"></i> Daily
                </button>
                <button id="customersTab" class="dashboard-tab border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <i class="ki-filled ki-people mr-1"></i> Customers
                </button>
                <button id="productsTab" class="dashboard-tab border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <i class="ki-filled ki-package mr-1"></i> Products
                </button>
                <button id="statsTab" class="dashboard-tab border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <i class="ki-filled ki-chart-pie mr-1"></i> General Stats
                </button>
            </nav>
        </div>
    </div>

    <!-- =========================================================================
         MONTHLY ANALYTICS TAB
         ========================================================================= -->
    <div id="monthlyContent" class="dashboard-content">
        <!-- Month Selector & Order Source Toggle -->
        <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
            <h2 class="text-lg font-semibold text-gray-900">Monthly Trends</h2>
            <div class="flex items-center gap-3">
                <!-- Order Source Filter -->
                <div class="flex items-center bg-gray-100 rounded-lg p-1">
                    <button id="sourceAll" class="source-btn active px-3 py-1 text-xs font-medium rounded-md bg-white shadow">All</button>
                    <button id="sourceShopify" class="source-btn px-3 py-1 text-xs font-medium rounded-md text-gray-600">Shopify</button>
                    <button id="sourceManual" class="source-btn px-3 py-1 text-xs font-medium rounded-md text-gray-600">Manual</button>
                </div>
            <select id="monthsSelector" class="select select-sm">
                <option value="3">Last 3 Months</option>
                <option value="6" selected>Last 6 Months</option>
                <option value="12">Last 12 Months</option>
            </select>
            </div>
        </div>

        <!-- Monthly KPI Cards with Source Split -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Total Revenue</p>
                            <p id="monthlyTotalRevenue" class="text-2xl font-bold text-gray-900">PKR 0</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded">Shopify: <span id="monthlyShopifyRevenue">0</span></span>
                        <span class="text-xs px-2 py-0.5 bg-gray-100 text-gray-700 rounded">Manual: <span id="monthlyManualRevenue">0</span></span>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Total Orders</p>
                            <p id="monthlyTotalOrders" class="text-2xl font-bold text-gray-900">0</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded">Shopify: <span id="monthlyShopifyOrders">0</span></span>
                        <span class="text-xs px-2 py-0.5 bg-gray-100 text-gray-700 rounded">Manual: <span id="monthlyManualOrders">0</span></span>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Unique Customers</p>
                            <p id="monthlyTotalCustomers" class="text-2xl font-bold text-gray-900">0</p>
                    <p class="text-xs text-gray-500 mt-2">Across all months selected</p>
                        </div>
                        </div>
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Avg Order Value</p>
                    <p id="monthlyAvgOrder" class="text-2xl font-bold text-gray-900">PKR 0</p>
                    <p class="text-xs text-gray-500 mt-2">Per order average</p>
                </div>
            </div>
        </div>

        <!-- Monthly Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Revenue by Month</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="monthlyRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Orders by Month</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="monthlyOrdersChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Source Split Chart -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Order Source Split</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="monthlySourceChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Month-over-Month Growth</h3>
                    <div id="momGrowthTable" class="overflow-auto max-h-72">
                        <!-- Will be populated by JS -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         DAILY ANALYTICS TAB
         ========================================================================= -->
    <div id="dailyContent" class="dashboard-content hidden">
        <!-- Month/Year Selector -->
        <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
            <h2 class="text-lg font-semibold text-gray-900">Daily Trends</h2>
            <div class="flex gap-2">
                <select id="yearSelector" class="select select-sm">
                    <option value="2024">2024</option>
                    <option value="2025" selected>2025</option>
                </select>
                <select id="monthSelector" class="select select-sm">
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
            </div>
        </div>

        <!-- Daily KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Month Revenue</p>
                            <p id="dailyTotalRevenue" class="text-2xl font-bold text-gray-900">PKR 0</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded">Shopify: <span id="dailyShopifyRevenue">0</span></span>
                        <span class="text-xs px-2 py-0.5 bg-gray-100 text-gray-700 rounded">Manual: <span id="dailyManualRevenue">0</span></span>
                        </div>
                        </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Month Orders</p>
                    <p id="dailyTotalOrders" class="text-2xl font-bold text-gray-900">0</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded">Shopify: <span id="dailyShopifyOrders">0</span></span>
                        <span class="text-xs px-2 py-0.5 bg-gray-100 text-gray-700 rounded">Manual: <span id="dailyManualOrders">0</span></span>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Unique Customers</p>
                    <p id="dailyTotalCustomers" class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                        </div>
            <div class="kt-card">
                <div class="p-5">
                    <p class="text-sm font-medium text-gray-600 mb-1">Avg Order Value</p>
                    <p id="dailyAvgOrder" class="text-2xl font-bold text-gray-900">PKR 0</p>
                    </div>
                </div>
            </div>

        <!-- Daily Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Revenue by Day</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="dailyRevenueChart"></canvas>
                        </div>
                        </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Orders by Day</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="dailyOrdersChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Performance -->
            <div class="kt-card">
            <div class="p-5">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Performance by Day of Week</h3>
                <div class="relative" style="height: 250px;">
                    <canvas id="weeklyDayChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         CUSTOMERS ANALYTICS TAB
         ========================================================================= -->
    <div id="customersContent" class="dashboard-content hidden">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-lg font-semibold text-gray-900">Customer Analytics</h2>
            <select id="customerMonthSelector" class="select select-sm w-36">
                <!-- Will be populated by JS -->
            </select>
        </div>

        <!-- Customer Classification Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="kt-card bg-gradient-to-br from-blue-50 to-blue-100">
                <div class="p-5">
                    <p class="text-sm font-medium text-blue-700 mb-1">Total Customers</p>
                    <p id="custTotal" class="text-3xl font-bold text-blue-900">0</p>
                </div>
            </div>
            <div class="kt-card bg-gradient-to-br from-green-50 to-green-100">
                <div class="p-5">
                    <p class="text-sm font-medium text-green-700 mb-1">New This Month</p>
                    <p id="custNew" class="text-3xl font-bold text-green-900">0</p>
                </div>
            </div>
            <div class="kt-card bg-gradient-to-br from-purple-50 to-purple-100">
                <div class="p-5">
                    <p class="text-sm font-medium text-purple-700 mb-1">Returning</p>
                    <p id="custReturning" class="text-3xl font-bold text-purple-900">0</p>
                </div>
            </div>
            <div class="kt-card bg-gradient-to-br from-orange-50 to-orange-100">
                <div class="p-5">
                    <p class="text-sm font-medium text-orange-700 mb-1">Repeat Rate</p>
                    <p id="custRepeatRate" class="text-3xl font-bold text-orange-900">0%</p>
                </div>
            </div>
        </div>

        <!-- Activity Segments -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Activity Segments</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="activitySegmentChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Customer Cohort Retention</h3>
                    <div id="cohortTable" class="overflow-auto max-h-72">
                        <!-- Will be populated by JS -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Geographic Distribution -->
        <div class="kt-card">
            <div class="p-5">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Geographic Distribution</h3>
                <div id="geographicList" class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <!-- Will be populated by JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         PRODUCTS ANALYTICS TAB
         ========================================================================= -->
    <div id="productsContent" class="dashboard-content hidden">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-lg font-semibold text-gray-900">Product Analytics</h2>
            <div class="flex items-center gap-3">
                <select id="productMonthSelector" class="select select-sm w-36">
                    <!-- Will be populated by JS -->
                </select>
                <select id="categoryLevelSelector" class="select select-sm w-32">
                    <option value="1">Level 1</option>
                    <option value="2">Level 2</option>
                    <option value="3">Level 3</option>
                </select>
            </div>
        </div>

        <!-- Product Category Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Revenue by Category</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="categoryRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Quantity by Category</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="categoryQuantityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category Detail Table -->
        <div class="kt-card">
            <div class="p-5">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Category Breakdown</h3>
                <div class="overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left py-3 px-4 font-medium text-gray-700">Category</th>
                                <th class="text-right py-3 px-4 font-medium text-gray-700">Orders</th>
                                <th class="text-right py-3 px-4 font-medium text-gray-700">Quantity</th>
                                <th class="text-right py-3 px-4 font-medium text-gray-700">Revenue</th>
                                <th class="text-right py-3 px-4 font-medium text-gray-700">Shopify</th>
                                <th class="text-right py-3 px-4 font-medium text-gray-700">Manual</th>
                            </tr>
                        </thead>
                        <tbody id="categoryTableBody">
                            <!-- Will be populated by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         GENERAL STATISTICS TAB
         ========================================================================= -->
    <div id="statsContent" class="dashboard-content hidden">
        <!-- Customer Statistics -->
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Customer Analytics</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="kt-card">
                    <div class="p-5">
                                <p class="text-sm font-medium text-gray-600">Total Customers</p>
                                <p id="totalCustomers" class="text-2xl font-bold text-gray-900">0</p>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-5">
                                <p class="text-sm font-medium text-gray-600">30-Day Active</p>
                                <p id="active30Days" class="text-2xl font-bold text-gray-900">0</p>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-5">
                                <p class="text-sm font-medium text-gray-600">90-Day Active</p>
                                <p id="active90Days" class="text-2xl font-bold text-gray-900">0</p>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-5">
                                <p class="text-sm font-medium text-gray-600">Repeat Customers</p>
                                <p id="repeatCustomers" class="text-2xl font-bold text-gray-900">0</p>
                                <p id="conversionRate" class="text-xs text-gray-500">0% conversion</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Statistics -->
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Order Analytics</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="kt-card">
                    <div class="p-5">
                                <p class="text-sm font-medium text-gray-600">Total Orders</p>
                                <p id="totalOrders" class="text-2xl font-bold text-gray-900">0</p>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-5">
                        <p class="text-sm font-medium text-gray-600">Delivered Orders</p>
                                <p id="completedOrders" class="text-2xl font-bold text-gray-900">0</p>
                                <p id="completionRate" class="text-xs text-gray-500">0% completion</p>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-5">
                                <p class="text-sm font-medium text-gray-600">Pending Orders</p>
                                <p id="pendingOrders" class="text-2xl font-bold text-gray-900">0</p>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-5">
                                <p class="text-sm font-medium text-gray-600">Avg Order Value</p>
                                <p id="avgOrderValue" class="text-2xl font-bold text-gray-900">PKR 0</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue & Geographic -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Revenue Overview</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600">Total Revenue</span>
                            <span id="totalRevenue" class="text-lg font-bold text-gray-900">PKR 0</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600">Customer Lifetime Value</span>
                            <span id="avgLifetimeValue" class="text-lg font-bold text-gray-900">PKR 0</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600">Total Products</span>
                            <span id="totalProducts" class="text-lg font-bold text-gray-900">0</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Top Cities</h3>
                    <div id="statsGeographicList" class="space-y-2">
                        <!-- Dynamic content -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     ORDER DETAIL MODAL (Drilldown)
     ========================================================================= -->
<div id="orderDetailModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeOrderModal()"></div>
        <div class="relative inline-block w-full max-w-4xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modalTitle" class="text-lg font-semibold text-gray-900">Orders for Date</h3>
                <button onclick="closeOrderModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="ki-filled ki-cross text-xl"></i>
                </button>
            </div>
            <div id="modalContent" class="max-h-96 overflow-auto">
                <!-- Will be populated by JS -->
            </div>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Enhanced Dashboard JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const dashboard = new EnhancedDashboard();
    dashboard.init();
});

class EnhancedDashboard {
    constructor() {
        this.charts = {};
        this.currentTab = 'monthly';
        this.currentMonth = new Date().getMonth() + 1;
        this.currentYear = new Date().getFullYear();
        this.monthlyData = [];
        this.dailyData = [];
    }

    init() {
        this.populateMonthSelectors();
        this.setupEventListeners();
        this.setCurrentMonthYear();
        
        // Load data with slight delays to prevent server overload
        // Top cards load immediately (lightweight)
        this.loadTopCards();
        
        // Monthly data loads after a short delay
        setTimeout(() => {
            this.loadMonthlyData();
        }, 500);
    }

    populateMonthSelectors() {
        const selectors = ['topCardsMonthSelector', 'customerMonthSelector', 'productMonthSelector'];
        const months = [];
        let date = new Date();
        
        for (let i = 0; i < 12; i++) {
            months.push({
                value: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
                label: date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
            });
            date.setMonth(date.getMonth() - 1);
        }
        
        selectors.forEach(id => {
            const select = document.getElementById(id);
            if (select) {
                select.innerHTML = months.map((m, i) => 
                    `<option value="${m.value}" ${i === 0 ? 'selected' : ''}>${m.label}</option>`
                ).join('');
            }
        });
    }

    setupEventListeners() {
        // Tab switching
        document.getElementById('monthlyTab').addEventListener('click', () => this.switchTab('monthly'));
        document.getElementById('dailyTab').addEventListener('click', () => this.switchTab('daily'));
        document.getElementById('customersTab').addEventListener('click', () => this.switchTab('customers'));
        document.getElementById('productsTab').addEventListener('click', () => this.switchTab('products'));
        document.getElementById('statsTab').addEventListener('click', () => this.switchTab('stats'));

        // Top cards month selector
        document.getElementById('topCardsMonthSelector').addEventListener('change', () => this.loadTopCards());

        // Monthly controls
        document.getElementById('monthsSelector').addEventListener('change', () => this.loadMonthlyData());

        // Daily controls
        document.getElementById('yearSelector').addEventListener('change', () => this.loadDailyData());
        document.getElementById('monthSelector').addEventListener('change', () => this.loadDailyData());

        // Customer controls
        document.getElementById('customerMonthSelector').addEventListener('change', () => this.loadCustomerAnalysis());

        // Product controls
        document.getElementById('productMonthSelector').addEventListener('change', () => this.loadProductCategories());
        document.getElementById('categoryLevelSelector').addEventListener('change', () => this.loadProductCategories());

        // Refresh & Clear Cache
        document.getElementById('refreshBtn').addEventListener('click', () => this.refreshCurrentTab());
        document.getElementById('clearCacheBtn').addEventListener('click', () => this.clearCache());
    }

    setCurrentMonthYear() {
        document.getElementById('yearSelector').value = this.currentYear;
        document.getElementById('monthSelector').value = this.currentMonth;
    }

    switchTab(tab) {
        // Update tab buttons
        document.querySelectorAll('.dashboard-tab').forEach(btn => {
            btn.classList.remove('active', 'border-blue-500', 'text-blue-600');
            btn.classList.add('border-transparent', 'text-gray-500');
        });

        // Hide all content
        document.querySelectorAll('.dashboard-content').forEach(content => {
            content.classList.add('hidden');
        });

        // Show selected tab
        document.getElementById(`${tab}Tab`).classList.add('active', 'border-blue-500', 'text-blue-600');
        document.getElementById(`${tab}Tab`).classList.remove('border-transparent', 'text-gray-500');
        document.getElementById(`${tab}Content`).classList.remove('hidden');

        this.currentTab = tab;

        // Load data for the tab
        switch (tab) {
            case 'monthly':
                this.loadMonthlyData();
                break;
            case 'daily':
                this.loadDailyData();
                break;
            case 'customers':
                this.loadCustomerAnalysis();
                break;
            case 'products':
                this.loadProductCategories();
                break;
            case 'stats':
                this.loadGeneralStats();
                break;
        }
    }

    async loadTopCards() {
        try {
            const month = document.getElementById('topCardsMonthSelector').value;
            const response = await fetch(`/dashboard/top-cards?month=${month}`);
            const data = await response.json();
            
            if (data.success) {
                const d = data.data;
                document.getElementById('topInvoices').textContent = `PKR ${this.formatNumber(d.invoices)}`;
                document.getElementById('topInvoiceCount').textContent = `${d.invoice_count} invoices`;
                document.getElementById('topExpenses').textContent = `PKR ${this.formatNumber(d.expenses)}`;
                document.getElementById('topExpenseCount').textContent = `${d.expense_count} expenses`;
                document.getElementById('topVendorPayments').textContent = `PKR ${this.formatNumber(d.vendor_payments)}`;
                document.getElementById('topVendorCount').textContent = `${d.vendor_payment_count} payments`;
                document.getElementById('topProfit').textContent = `PKR ${this.formatNumber(d.profit)}`;
                document.getElementById('topActiveCustomers').textContent = this.formatNumber(d.active_customers_90d);
                document.getElementById('topNewCustomers').textContent = `+${d.new_customers_this_month} new`;
            }
        } catch (error) {
            console.error('Error loading top cards:', error);
        }
    }

    async loadMonthlyData() {
        this.showLoading(true);
        try {
            const months = document.getElementById('monthsSelector').value;
            
            // Load analytics and growth separately to avoid timeout if one fails
            try {
                const analyticsRes = await this.fetchWithTimeout(`/dashboard/monthly-analytics?months=${months}`, 30000);
                const analytics = await analyticsRes.json();
                console.log('Monthly analytics response:', analytics);
                if (analytics.success && analytics.data) {
                    this.monthlyData = analytics.data;
                    this.updateMonthlyKPIs(analytics.data);
                    this.createMonthlyCharts(analytics.data);
                } else if (analytics.error) {
                    console.error('Server error:', analytics.error);
                    this.showError('monthlyTotalRevenue', 'Error: ' + analytics.error.substring(0, 50));
                }
            } catch (e) {
                console.warn('Monthly analytics loading failed:', e.message);
                this.showError('monthlyTotalRevenue', 'Failed to load: ' + e.message);
            }
            
            try {
                const growthRes = await this.fetchWithTimeout(`/dashboard/mom-growth?months=6`, 30000);
                const growth = await growthRes.json();
                if (growth.success && growth.data) {
                    this.updateMoMGrowthTable(growth.data);
                }
            } catch (e) {
                console.warn('MoM growth loading failed:', e.message);
            }
        } catch (error) {
            console.error('Error loading monthly data:', error);
        } finally {
            this.showLoading(false);
        }
    }
    
    // Helper function for fetch with timeout
    async fetchWithTimeout(url, timeout = 30000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        
        try {
            const response = await fetch(url, { signal: controller.signal });
            clearTimeout(timeoutId);
            return response;
        } catch (error) {
            clearTimeout(timeoutId);
            throw error;
        }
    }
    
    showError(elementId, message) {
        const el = document.getElementById(elementId);
        if (el) el.textContent = message;
    }

    async loadDailyData() {
        this.showLoading(true);
        try {
            const year = document.getElementById('yearSelector').value;
            const month = document.getElementById('monthSelector').value;
            const [dailyRes, weeklyRes] = await Promise.all([
                fetch(`/dashboard/daily-analytics?year=${year}&month=${month}`),
                fetch('/dashboard/weekly-performance')
            ]);
            
            const daily = await dailyRes.json();
            const weekly = await weeklyRes.json();
            
            if (daily.success) {
                this.dailyData = daily.data;
                this.updateDailyKPIs(daily.data);
                this.createDailyCharts(daily.data);
            }
            
            if (weekly.success) {
                this.createWeeklyChart(weekly.data);
            }
        } catch (error) {
            console.error('Error loading daily data:', error);
        } finally {
            this.showLoading(false);
        }
    }

    async loadCustomerAnalysis() {
        this.showLoading(true);
        try {
            const month = document.getElementById('customerMonthSelector').value;
            const [analysisRes, cohortRes, statsRes] = await Promise.all([
                fetch(`/dashboard/customer-analysis?month=${month}`),
                fetch('/dashboard/customer-cohort?months=12'),
                fetch('/dashboard/general-stats')
            ]);
            
            const analysis = await analysisRes.json();
            const cohort = await cohortRes.json();
            const stats = await statsRes.json();
            
            if (analysis.success) {
                this.updateCustomerClassification(analysis.data);
                this.createActivitySegmentChart(analysis.data.activity_segments);
            }
            
            if (cohort.success) {
                this.updateCohortTable(cohort.data);
            }
            
            if (stats.success) {
                this.updateGeographicList(stats.data.geographic);
            }
        } catch (error) {
            console.error('Error loading customer analysis:', error);
        } finally {
            this.showLoading(false);
        }
    }

    async loadProductCategories() {
        this.showLoading(true);
        try {
            const month = document.getElementById('productMonthSelector').value;
            const level = document.getElementById('categoryLevelSelector').value;
            const response = await fetch(`/dashboard/product-categories?month=${month}&level=${level}`);
            const data = await response.json();
            
            if (data.success) {
                this.createCategoryCharts(data.data);
                this.updateCategoryTable(data.data);
            }
        } catch (error) {
            console.error('Error loading product categories:', error);
        } finally {
            this.showLoading(false);
        }
    }

    async loadGeneralStats() {
        this.showLoading(true);
        try {
            const response = await fetch('/dashboard/general-stats');
            const data = await response.json();
            
            if (data.success) {
                this.updateGeneralStats(data.data);
            }
        } catch (error) {
            console.error('Error loading general stats:', error);
        } finally {
            this.showLoading(false);
        }
    }

    // =========================================================================
    // UPDATE METHODS
    // =========================================================================

    updateMonthlyKPIs(data) {
        const totalRevenue = data.reduce((sum, item) => sum + item.revenue, 0);
        const totalOrders = data.reduce((sum, item) => sum + item.orders, 0);
        const totalCustomers = data.reduce((sum, item) => sum + item.customers, 0);
        const shopifyRevenue = data.reduce((sum, item) => sum + (item.shopify_revenue || 0), 0);
        const manualRevenue = data.reduce((sum, item) => sum + (item.manual_revenue || 0), 0);
        const shopifyOrders = data.reduce((sum, item) => sum + (item.shopify_orders || 0), 0);
        const manualOrders = data.reduce((sum, item) => sum + (item.manual_orders || 0), 0);

        document.getElementById('monthlyTotalRevenue').textContent = `PKR ${this.formatNumber(totalRevenue)}`;
        document.getElementById('monthlyTotalOrders').textContent = this.formatNumber(totalOrders);
        document.getElementById('monthlyTotalCustomers').textContent = this.formatNumber(totalCustomers);
        document.getElementById('monthlyAvgOrder').textContent = totalOrders > 0 ? `PKR ${this.formatNumber(totalRevenue / totalOrders)}` : 'PKR 0';
        document.getElementById('monthlyShopifyRevenue').textContent = this.formatNumber(shopifyRevenue);
        document.getElementById('monthlyManualRevenue').textContent = this.formatNumber(manualRevenue);
        document.getElementById('monthlyShopifyOrders').textContent = this.formatNumber(shopifyOrders);
        document.getElementById('monthlyManualOrders').textContent = this.formatNumber(manualOrders);
    }

    updateDailyKPIs(data) {
        const dailyData = data.data;
        const totalRevenue = dailyData.reduce((sum, item) => sum + item.revenue, 0);
        const totalOrders = dailyData.reduce((sum, item) => sum + item.orders, 0);
        const totalCustomers = dailyData.reduce((sum, item) => sum + item.customers, 0);
        const shopifyRevenue = dailyData.reduce((sum, item) => sum + (item.shopify_revenue || 0), 0);
        const manualRevenue = dailyData.reduce((sum, item) => sum + (item.manual_revenue || 0), 0);
        const shopifyOrders = dailyData.reduce((sum, item) => sum + (item.shopify_orders || 0), 0);
        const manualOrders = dailyData.reduce((sum, item) => sum + (item.manual_orders || 0), 0);

        document.getElementById('dailyTotalRevenue').textContent = `PKR ${this.formatNumber(totalRevenue)}`;
        document.getElementById('dailyTotalOrders').textContent = this.formatNumber(totalOrders);
        document.getElementById('dailyTotalCustomers').textContent = this.formatNumber(totalCustomers);
        document.getElementById('dailyAvgOrder').textContent = totalOrders > 0 ? `PKR ${this.formatNumber(totalRevenue / totalOrders)}` : 'PKR 0';
        document.getElementById('dailyShopifyRevenue').textContent = this.formatNumber(shopifyRevenue);
        document.getElementById('dailyManualRevenue').textContent = this.formatNumber(manualRevenue);
        document.getElementById('dailyShopifyOrders').textContent = this.formatNumber(shopifyOrders);
        document.getElementById('dailyManualOrders').textContent = this.formatNumber(manualOrders);
    }

    updateCustomerClassification(data) {
        const c = data.classification;
        document.getElementById('custTotal').textContent = this.formatNumber(c.total_customers);
        document.getElementById('custNew').textContent = this.formatNumber(c.new_customers);
        document.getElementById('custReturning').textContent = this.formatNumber(c.returning_customers);
        const repeatRate = c.total_customers > 0 ? Math.round((c.returning_customers / c.total_customers) * 100) : 0;
        document.getElementById('custRepeatRate').textContent = `${repeatRate}%`;
    }

    updateGeneralStats(data) {
        document.getElementById('totalCustomers').textContent = this.formatNumber(data.customers.total);
        document.getElementById('active30Days').textContent = this.formatNumber(data.customers.active_30_days);
        document.getElementById('active90Days').textContent = this.formatNumber(data.customers.active_90_days);
        document.getElementById('repeatCustomers').textContent = this.formatNumber(data.customers.repeat_customers);
        document.getElementById('conversionRate').textContent = `${data.customers.conversion_rate}% conversion`;

        document.getElementById('totalOrders').textContent = this.formatNumber(data.orders.total);
        document.getElementById('completedOrders').textContent = this.formatNumber(data.orders.delivered);
        document.getElementById('pendingOrders').textContent = this.formatNumber(data.orders.pending);
        document.getElementById('completionRate').textContent = `${data.orders.completion_rate}% completion`;

        document.getElementById('totalRevenue').textContent = `PKR ${this.formatNumber(data.revenue.total)}`;
        document.getElementById('avgOrderValue').textContent = `PKR ${this.formatNumber(data.revenue.avg_order_value)}`;
        document.getElementById('avgLifetimeValue').textContent = `PKR ${this.formatNumber(data.customers.avg_lifetime_value)}`;

        document.getElementById('totalProducts').textContent = this.formatNumber(data.products.total);

        this.updateStatsGeographicList(data.geographic);
    }

    updateGeographicList(cities) {
        const container = document.getElementById('geographicList');
        container.innerHTML = cities.map(city => `
            <div class="flex justify-between items-center py-2 px-3 bg-gray-50 rounded-lg">
                <span class="text-sm font-medium text-gray-900">${city.city}</span>
                <span class="text-sm text-gray-600">${city.count}</span>
            </div>
        `).join('');
    }

    updateStatsGeographicList(cities) {
        const container = document.getElementById('statsGeographicList');
        container.innerHTML = cities.map(city => `
            <div class="flex justify-between items-center py-2 px-3 bg-gray-50 rounded-lg">
                <span class="text-sm font-medium text-gray-900">${city.city}</span>
                <span class="text-sm text-gray-600">${city.count} customers</span>
            </div>
        `).join('');
    }

    updateMoMGrowthTable(data) {
        const container = document.getElementById('momGrowthTable');
        container.innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left py-2 px-3 font-medium text-gray-600">Month</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Revenue</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Growth</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.map(row => `
                        <tr class="border-t border-gray-100">
                            <td class="py-2 px-3 text-gray-900">${row.month_name}</td>
                            <td class="py-2 px-3 text-right text-gray-900">PKR ${this.formatNumber(row.current_revenue)}</td>
                            <td class="py-2 px-3 text-right">
                                <span class="${row.revenue_growth_pct >= 0 ? 'text-green-600' : 'text-red-600'}">
                                    ${row.revenue_growth_pct >= 0 ? '+' : ''}${row.revenue_growth_pct}%
                                </span>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    updateCohortTable(data) {
        const container = document.getElementById('cohortTable');
        container.innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left py-2 px-3 font-medium text-gray-600">Cohort</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Size</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">90d Retention</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Avg LTV</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.slice(0, 10).map(row => `
                        <tr class="border-t border-gray-100">
                            <td class="py-2 px-3 text-gray-900">${row.cohort_month}</td>
                            <td class="py-2 px-3 text-right text-gray-900">${row.cohort_size}</td>
                            <td class="py-2 px-3 text-right">
                                <span class="${row.retention_rate_90d >= 50 ? 'text-green-600' : 'text-orange-600'}">
                                    ${row.retention_rate_90d}%
                                </span>
                            </td>
                            <td class="py-2 px-3 text-right text-gray-900">PKR ${this.formatNumber(row.avg_lifetime_value)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    updateCategoryTable(data) {
        const container = document.getElementById('categoryTableBody');
        container.innerHTML = data.map(row => `
            <tr class="border-t border-gray-100 hover:bg-gray-50">
                <td class="py-3 px-4 text-gray-900 font-medium">${row.category}</td>
                <td class="py-3 px-4 text-right text-gray-900">${this.formatNumber(row.order_count)}</td>
                <td class="py-3 px-4 text-right text-gray-900">${this.formatNumber(row.total_quantity)}</td>
                <td class="py-3 px-4 text-right text-gray-900 font-medium">PKR ${this.formatNumber(row.total_revenue)}</td>
                <td class="py-3 px-4 text-right">
                    <span class="text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded">${this.formatNumber(row.shopify_orders)}</span>
                </td>
                <td class="py-3 px-4 text-right">
                    <span class="text-xs px-2 py-0.5 bg-gray-100 text-gray-700 rounded">${this.formatNumber(row.manual_orders)}</span>
                </td>
            </tr>
        `).join('');
    }

    // =========================================================================
    // CHART CREATION
    // =========================================================================

    createMonthlyCharts(data) {
        this.destroyChart('monthlyRevenue');
        this.destroyChart('monthlyOrders');
        this.destroyChart('monthlySource');

        // Revenue Chart with Shopify/Manual split
        this.charts.monthlyRevenue = new Chart(document.getElementById('monthlyRevenueChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [
                    {
                        label: 'Manual Revenue',
                        data: data.map(item => item.manual_revenue || item.revenue),
                        backgroundColor: 'rgba(107, 114, 128, 0.8)',
                        borderRadius: 4,
                    },
                    {
                        label: 'Shopify Revenue',
                        data: data.map(item => item.shopify_revenue || 0),
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    }
                ]
            },
            options: { ...this.getStackedChartOptions('PKR'), plugins: { legend: { display: true, position: 'top' } } }
        });

        // Orders Chart with Shopify/Manual split
        this.charts.monthlyOrders = new Chart(document.getElementById('monthlyOrdersChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [
                    {
                        label: 'Manual Orders',
                        data: data.map(item => item.manual_orders || item.orders),
                        backgroundColor: 'rgba(107, 114, 128, 0.8)',
                        borderRadius: 4,
                    },
                    {
                        label: 'Shopify Orders',
                        data: data.map(item => item.shopify_orders || 0),
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    }
                ]
            },
            options: { ...this.getStackedChartOptions(), plugins: { legend: { display: true, position: 'top' } } }
        });

        // Source Split Pie Chart
        const totalShopify = data.reduce((sum, item) => sum + (item.shopify_orders || 0), 0);
        const totalManual = data.reduce((sum, item) => sum + (item.manual_orders || item.orders), 0);
        
        this.charts.monthlySource = new Chart(document.getElementById('monthlySourceChart'), {
            type: 'doughnut',
            data: {
                labels: ['Shopify Converted', 'Manual'],
                datasets: [{
                    data: [totalShopify, totalManual],
                    backgroundColor: ['rgba(59, 130, 246, 0.8)', 'rgba(107, 114, 128, 0.8)'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    createDailyCharts(data) {
        this.destroyChart('dailyRevenue');
        this.destroyChart('dailyOrders');

        const dailyData = data.data;

        // Revenue Chart
        this.charts.dailyRevenue = new Chart(document.getElementById('dailyRevenueChart'), {
            type: 'line',
            data: {
                labels: dailyData.map(item => item.day_name),
                datasets: [{
                    label: 'Revenue (PKR)',
                    data: dailyData.map(item => item.revenue),
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: this.getChartOptions('PKR')
        });

        // Orders Chart
        this.charts.dailyOrders = new Chart(document.getElementById('dailyOrdersChart'), {
            type: 'bar',
            data: {
                labels: dailyData.map(item => item.day_name),
                datasets: [{
                    label: 'Orders',
                    data: dailyData.map(item => item.orders),
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderRadius: 4
                }]
            },
            options: this.getChartOptions()
        });
    }

    createWeeklyChart(data) {
        this.destroyChart('weeklyDay');

        this.charts.weeklyDay = new Chart(document.getElementById('weeklyDayChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.day_name),
                datasets: [{
                    label: 'Avg Orders/Day',
                    data: data.map(item => item.avg_orders_per_day),
                    backgroundColor: 'rgba(147, 51, 234, 0.8)',
                    borderRadius: 4
                }]
            },
            options: this.getChartOptions()
        });
    }

    createActivitySegmentChart(segments) {
        this.destroyChart('activitySegment');

        const labels = Object.keys(segments);
        const values = Object.values(segments);

        this.charts.activitySegment = new Chart(document.getElementById('activitySegmentChart'), {
            type: 'doughnut',
            data: {
                labels: labels.map(l => l.replace('_', ' ')),
                datasets: [{
                    data: values,
                    backgroundColor: [
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(147, 51, 234, 0.8)',
                        'rgba(249, 115, 22, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(107, 114, 128, 0.8)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right' } }
            }
        });
    }

    createCategoryCharts(data) {
        this.destroyChart('categoryRevenue');
        this.destroyChart('categoryQuantity');

        const topData = data.slice(0, 10);

        this.charts.categoryRevenue = new Chart(document.getElementById('categoryRevenueChart'), {
            type: 'bar',
            data: {
                labels: topData.map(item => item.category),
                datasets: [{
                    label: 'Revenue',
                    data: topData.map(item => item.total_revenue),
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderRadius: 4
                }]
            },
            options: { ...this.getChartOptions('PKR'), indexAxis: 'y' }
        });

        this.charts.categoryQuantity = new Chart(document.getElementById('categoryQuantityChart'), {
            type: 'bar',
            data: {
                labels: topData.map(item => item.category),
                datasets: [{
                    label: 'Quantity',
                    data: topData.map(item => item.total_quantity),
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderRadius: 4
                }]
            },
            options: { ...this.getChartOptions(), indexAxis: 'y' }
        });
    }

    getChartOptions(prefix = '') {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return prefix ? `${prefix} ${value.toLocaleString()}` : value.toLocaleString();
                        }
                    }
                }
            }
        };
    }

    getStackedChartOptions(prefix = '') {
        return {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return prefix ? `${prefix} ${value.toLocaleString()}` : value.toLocaleString();
                        }
                    }
                }
            }
        };
    }

    destroyChart(chartKey) {
        if (this.charts[chartKey]) {
            this.charts[chartKey].destroy();
            delete this.charts[chartKey];
        }
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    async clearCache() {
        try {
            const response = await fetch('/dashboard/clear-cache', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
            const data = await response.json();
            if (data.success) {
                alert('Cache cleared successfully!');
                this.refreshCurrentTab();
            }
        } catch (error) {
            console.error('Error clearing cache:', error);
        }
    }

    refreshCurrentTab() {
        this.loadTopCards();
        switch (this.currentTab) {
            case 'monthly': this.loadMonthlyData(); break;
            case 'daily': this.loadDailyData(); break;
            case 'customers': this.loadCustomerAnalysis(); break;
            case 'products': this.loadProductCategories(); break;
            case 'stats': this.loadGeneralStats(); break;
        }
    }

    showLoading(show) {
        const loading = document.getElementById('dashboardLoading');
        if (show) {
            loading.classList.remove('hidden');
        } else {
            loading.classList.add('hidden');
        }
    }

    formatNumber(number) {
        return new Intl.NumberFormat().format(Math.round(number || 0));
    }
}

// =========================================================================
// MODAL FUNCTIONS
// =========================================================================

async function showOrdersForDate(date, source = null) {
    const modal = document.getElementById('orderDetailModal');
    const content = document.getElementById('modalContent');
    const title = document.getElementById('modalTitle');
    
    title.textContent = `Orders for ${date}`;
    content.innerHTML = '<div class="text-center py-8"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div></div>';
    modal.classList.remove('hidden');
    
    try {
        const url = source ? `/dashboard/orders-for-date?date=${date}&source=${source}` : `/dashboard/orders-for-date?date=${date}`;
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            content.innerHTML = `
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left py-2 px-3">Order #</th>
                            <th class="text-left py-2 px-3">Customer</th>
                            <th class="text-right py-2 px-3">Amount</th>
                            <th class="text-center py-2 px-3">Status</th>
                            <th class="text-center py-2 px-3">Items</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${data.data.map(order => `
                            <tr class="border-t border-gray-100">
                                <td class="py-2 px-3 font-medium">${order.order_number}</td>
                                <td class="py-2 px-3">${order.customer_name}</td>
                                <td class="py-2 px-3 text-right">PKR ${order.total_price.toLocaleString()}</td>
                                <td class="py-2 px-3 text-center">
                                    <span class="px-2 py-0.5 text-xs rounded ${order.order_status === 'delivered' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'}">${order.order_status}</span>
                                </td>
                                <td class="py-2 px-3 text-center">${order.items_count}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } else {
            content.innerHTML = '<div class="text-center py-8 text-gray-500">No orders found for this date.</div>';
        }
    } catch (error) {
        content.innerHTML = '<div class="text-center py-8 text-red-500">Error loading orders.</div>';
    }
}

function closeOrderModal() {
    document.getElementById('orderDetailModal').classList.add('hidden');
}

function showDrilldown(type) {
    // Placeholder for drill-down functionality
    console.log('Drill-down clicked:', type);
}
</script>

<style>
.dashboard-tab.active {
    border-bottom-color: rgb(59 130 246) !important;
    color: rgb(59 130 246) !important;
}
.source-btn.active {
    background: white;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
</style>
@endsection
