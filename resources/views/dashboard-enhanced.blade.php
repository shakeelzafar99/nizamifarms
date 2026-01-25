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
            <!-- Invoices - With Online/Cash Breakdown -->
            <div class="kt-card cursor-pointer hover:shadow-lg transition-shadow lg:col-span-2" onclick="showDrilldown('invoices')">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-green-100 rounded-lg">
                            <i class="ki-filled ki-receipt text-green-600 text-lg"></i>
                        </div>
                        <span id="topInvoiceCount" class="text-xs text-gray-500">0 invoices</span>
                    </div>
                    <p class="text-xs font-medium text-gray-500 mb-1">Invoices (All Delivered)</p>
                    <p id="topInvoices" class="text-lg font-bold text-gray-900 mb-2">PKR 0</p>
                    
                    <!-- Invoice Breakdown -->
                    <div class="grid grid-cols-2 gap-2 pt-2 border-t border-gray-100">
                        <!-- Online -->
                        <div class="text-xs">
                            <div class="flex items-center gap-1 text-blue-700 font-medium mb-1">
                                <i class="ki-filled ki-wifi text-xs"></i> Online
                            </div>
                            <p id="topOnlineTotal" class="font-semibold text-gray-900">PKR 0</p>
                            <div class="mt-1 space-y-0.5">
                                <div class="flex justify-between text-gray-500">
                                    <span>Approved:</span>
                                    <span id="topOnlineApproved" class="text-green-600">0</span>
                                </div>
                                <div class="flex justify-between text-gray-500">
                                    <span>Pending L1:</span>
                                    <span id="topOnlinePendingL1" class="text-amber-600">0</span>
                                </div>
                                <div class="flex justify-between text-gray-500">
                                    <span>Pending L2:</span>
                                    <span id="topOnlinePendingL2" class="text-orange-600">0</span>
                                </div>
                            </div>
                        </div>
                        <!-- Cash -->
                        <div class="text-xs">
                            <div class="flex items-center gap-1 text-emerald-700 font-medium mb-1">
                                <i class="ki-filled ki-dollar text-xs"></i> Cash
                            </div>
                            <p id="topCashTotal" class="font-semibold text-gray-900">PKR 0</p>
                            <div class="mt-1 space-y-0.5">
                                <div class="flex justify-between text-gray-500">
                                    <span>Approved:</span>
                                    <span id="topCashApproved" class="text-green-600">0</span>
                                </div>
                                <div class="flex justify-between text-gray-500">
                                    <span>Pending:</span>
                                    <span id="topCashPending" class="text-amber-600">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
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

            <!-- Active Customers - Clickable for popup -->
            <div class="kt-card cursor-pointer hover:shadow-lg transition-shadow" onclick="showActiveCustomersPopup()">
                <div class="p-4">
                    <div class="flex items-center justify-between mb-2">
                        <div class="p-2 bg-purple-100 rounded-lg">
                            <i class="ki-filled ki-people text-purple-600 text-lg"></i>
                        </div>
                        <span id="topNewCustomers" class="text-xs text-green-600 font-medium">+0 new</span>
                    </div>
                    <p class="text-xs font-medium text-gray-500 mb-1">Active (90d)</p>
                    <p id="topActiveCustomers" class="text-lg font-bold text-gray-900">0</p>
                    <p class="text-xs text-gray-400 mt-1">Click for details</p>
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
        <!-- Month Selector & View Toggle -->
        <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
            <h2 class="text-lg font-semibold text-gray-900">Monthly Trends (Invoices)</h2>
            <div class="flex items-center gap-3">
                <!-- View Toggle: Total vs Online/Cash vs Shopify/Manual -->
                <div class="flex items-center bg-gray-100 rounded-lg p-1">
                    <button id="viewTotal" class="view-btn active px-3 py-1 text-xs font-medium rounded-md bg-white shadow">Total</button>
                    <button id="viewOnlineCash" class="view-btn px-3 py-1 text-xs font-medium rounded-md text-gray-600">Online/Cash</button>
                    <button id="viewShopifyManual" class="view-btn px-3 py-1 text-xs font-medium rounded-md text-gray-600">Shopify/Manual</button>
                </div>
            <select id="monthsSelector" class="select select-sm">
                <option value="3">Last 3 Months</option>
                <option value="6" selected>Last 6 Months</option>
                <option value="12">Last 12 Months</option>
            </select>
            </div>
        </div>

        <!-- Monthly KPI Cards - Hidden for JS compatibility -->
        <div class="hidden">
            <span id="monthlyTotalRevenue">0</span>
            <span id="monthlyOnlineRevenue">0</span>
            <span id="monthlyShopifyRevenue">0</span>
            <span id="monthlyManualRevenue">0</span>
            <span id="monthlyCashRevenue">0</span>
            <span id="monthlyTotalOrders">0</span>
            <span id="monthlyShopifyOrders">0</span>
            <span id="monthlyManualOrders">0</span>
            <span id="monthlyTotalCustomers">0</span>
            <span id="monthlyAvgOrder">0</span>
        </div>

        <!-- Monthly Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Invoice Revenue by Month</h3>
                    </div>
                    <div class="relative" style="height: 280px;">
                        <canvas id="monthlyRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Invoice Count by Month</h3>
                    </div>
                    <div class="relative" style="height: 280px;">
                        <canvas id="monthlyOrdersChart"></canvas>
                </div>
            </div>
            </div>
        </div>

        <!-- Payment Mode Split by Month & MoM Growth -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Online vs Cash Revenue by Month</h3>
                        <button onclick="openFullView('monthlySourceChart', 'Online vs Cash Revenue')" class="text-xs text-blue-600 hover:text-blue-800">
                            <i class="ki-filled ki-maximize"></i> Full View
                        </button>
                    </div>
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

        <!-- New vs Returning Customers -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">New vs Returning Customers (Orders)</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="monthlyCustomerTypeChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">New vs Returning Customers (Revenue)</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="monthlyCustomerRevenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Category Summary -->
            <div class="kt-card">
                <div class="p-5">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Product Category Summary by Month</h3>
                <div id="monthlyCategoryTable" class="overflow-auto max-h-80">
                        <!-- Will be populated by JS -->
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         DAILY ANALYTICS TAB
         ========================================================================= -->
    <div id="dailyContent" class="dashboard-content hidden">
        <!-- Header - Uses the global topCardsMonthSelector -->
        <div class="flex flex-wrap justify-between items-center gap-4 mb-4">
            <h2 class="text-lg font-semibold text-gray-900">Daily Trends (Delivered Orders)</h2>
            <span class="text-sm text-gray-500">Viewing: <span id="dailyMonthLabel" class="font-medium text-gray-700">Jan 2026</span></span>
        </div>

        <!-- Month Summary Cards - Compact Row -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
            <div class="kt-card p-3">
                <p class="text-xs text-gray-500 mb-1">Revenue</p>
                <p id="dailyTotalRevenue" class="text-sm font-bold text-gray-900">PKR 0</p>
                        </div>
            <div class="kt-card p-3">
                <p class="text-xs text-gray-500 mb-1">Orders</p>
                <p id="dailyTotalOrders" class="text-sm font-bold text-gray-900">0</p>
                        </div>
            <div class="kt-card p-3">
                <p class="text-xs text-gray-500 mb-1">Qty</p>
                <p id="dailyTotalQty" class="text-sm font-bold text-gray-900">0</p>
            </div>
            <div class="kt-card p-3">
                <p class="text-xs text-gray-500 mb-1">Customers</p>
                <p id="dailyTotalCustomers" class="text-sm font-bold text-gray-900">0</p>
                    </div>
            <div class="kt-card p-3">
                <p class="text-xs text-gray-500 mb-1">Avg Order</p>
                <p id="dailyAvgOrder" class="text-sm font-bold text-gray-900">PKR 0</p>
                </div>
            <div class="kt-card p-3 bg-green-50">
                <p class="text-xs text-green-700 mb-1">New Cust.</p>
                <p id="dailyNewCustomerOrders" class="text-sm font-bold text-green-800">0</p>
            </div>
            <div class="kt-card p-3 bg-blue-50">
                <p class="text-xs text-blue-700 mb-1">Returning</p>
                <p id="dailyReturningCustomerOrders" class="text-sm font-bold text-blue-800">0</p>
            </div>
        </div>

        <!-- Daily Charts - Main focus -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Revenue by Delivery Date</h3>
                        <button onclick="openFullView('dailyRevenueChart', 'Daily Revenue')" class="text-xs text-blue-600 hover:text-blue-800">
                            <i class="ki-filled ki-maximize"></i> Full View
                        </button>
                    </div>
                    <div class="relative" style="height: 280px;">
                        <canvas id="dailyRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Orders by Delivery Date</h3>
                        <button onclick="openFullView('dailyOrdersChart', 'Daily Orders')" class="text-xs text-blue-600 hover:text-blue-800">
                            <i class="ki-filled ki-maximize"></i> Full View
                        </button>
                    </div>
                    <div class="relative" style="height: 280px;">
                        <canvas id="dailyOrdersChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Qty per Day & New vs Returning Customers -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="kt-card">
                <div class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-semibold text-gray-900">Qty by Delivery Date</h3>
                        <button onclick="openFullView('dailyQtyChart', 'Daily Quantity')" class="text-xs text-blue-600 hover:text-blue-800">
                            <i class="ki-filled ki-maximize"></i> Full View
                        </button>
                    </div>
                    <div class="relative" style="height: 280px;">
                        <canvas id="dailyQtyChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-base font-semibold text-gray-900">New vs Returning Customers</h3>
                        <button onclick="openFullView('dailyCustomerTypeChart', 'Customer Type')" class="text-xs text-blue-600 hover:text-blue-800">
                            <i class="ki-filled ki-maximize"></i> Full View
                        </button>
                    </div>
                    <div class="relative" style="height: 280px;">
                        <canvas id="dailyCustomerTypeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Performance & Order Splits -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Weekly Performance Chart -->
            <div class="kt-card">
            <div class="p-5">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Performance by Day of Week</h3>
                    <div class="relative" style="height: 220px;">
                    <canvas id="weeklyDayChart"></canvas>
                </div>
            </div>
            </div>
            <!-- Order Split Badges -->
            <div class="kt-card lg:col-span-2">
                <div class="p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">Order Breakdown</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="text-center p-3 bg-purple-50 rounded-lg">
                            <p class="text-xs text-purple-600 mb-1">Shopify</p>
                            <p id="dailyShopifyOrders" class="text-lg font-bold text-purple-800">0</p>
                            <p class="text-xs text-purple-600">orders</p>
                        </div>
                        <div class="text-center p-3 bg-gray-50 rounded-lg">
                            <p class="text-xs text-gray-600 mb-1">Manual</p>
                            <p id="dailyManualOrders" class="text-lg font-bold text-gray-800">0</p>
                            <p class="text-xs text-gray-600">orders</p>
                        </div>
                        <div class="text-center p-3 bg-blue-50 rounded-lg">
                            <p class="text-xs text-blue-600 mb-1">Online</p>
                            <p id="dailyOnlineOrders" class="text-lg font-bold text-blue-800">0</p>
                            <p class="text-xs text-blue-600">orders</p>
                        </div>
                        <div class="text-center p-3 bg-emerald-50 rounded-lg">
                            <p class="text-xs text-emerald-600 mb-1">Cash</p>
                            <p id="dailyCashOrders" class="text-lg font-bold text-emerald-800">0</p>
                            <p class="text-xs text-emerald-600">orders</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product Category Summary -->
        <div class="kt-card">
            <div class="p-5">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Product Category Summary</h3>
                <div id="dailyCategoryTable" class="overflow-auto max-h-64">
                    <!-- Will be populated by JS -->
                </div>
            </div>
        </div>
        
        <!-- Hidden elements -->
        <div class="hidden">
            <span id="dailyShopifyRevenue">0</span>
            <span id="dailyManualRevenue">0</span>
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
<div id="orderDetailModal" class="fixed inset-0 hidden overflow-y-auto" style="z-index: 99999;">
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

<!-- =========================================================================
     FULL VIEW CHART MODAL
     ========================================================================= -->
<div id="fullViewModal" class="fixed inset-0 hidden overflow-y-auto" style="z-index: 99999;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeFullView()"></div>
        <div class="relative inline-block w-full max-w-6xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 id="fullViewTitle" class="text-lg font-semibold text-gray-900">Chart Full View</h3>
                <div class="flex items-center gap-3">
                    <button onclick="showChartDetails()" class="text-sm px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200">
                        <i class="ki-filled ki-document"></i> View Details
                    </button>
                    <button onclick="closeFullView()" class="text-gray-400 hover:text-gray-600">
                        <i class="ki-filled ki-cross text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="relative" style="height: 500px;">
                <canvas id="fullViewChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     CHART DETAILS MODAL (Raw Data Table)
     ========================================================================= -->
<div id="chartDetailsModal" class="fixed inset-0 hidden overflow-y-auto" style="z-index: 99999;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeChartDetails()"></div>
        <div class="relative inline-block w-full max-w-5xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 id="chartDetailsTitle" class="text-lg font-semibold text-gray-900">Chart Data Details</h3>
                <button onclick="closeChartDetails()" class="text-gray-400 hover:text-gray-600">
                    <i class="ki-filled ki-cross text-xl"></i>
                </button>
            </div>
            <div id="chartDetailsContent" class="max-h-[500px] overflow-auto">
                <!-- Will be populated by JS -->
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     ACTIVE CUSTOMERS POPUP MODAL
     ========================================================================= -->
<div id="activeCustomersModal" class="fixed inset-0 hidden overflow-y-auto" style="z-index: 99999;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeActiveCustomersPopup()"></div>
        <div class="relative inline-block w-full max-w-5xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Active Customers (Last 90 Days)</h3>
                <button onclick="closeActiveCustomersPopup()" class="text-gray-400 hover:text-gray-600">
                    <i class="ki-filled ki-cross text-xl"></i>
                </button>
            </div>
            <!-- Filter Tabs -->
            <div class="flex gap-2 mb-4">
                <button id="customerFilterAll" onclick="filterActiveCustomers('all')" class="px-4 py-2 text-sm font-medium rounded-lg bg-blue-100 text-blue-700">
                    All <span id="customerCountAll" class="ml-1 text-xs">(0)</span>
                </button>
                <button id="customerFilterNew" onclick="filterActiveCustomers('new')" class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-600 hover:bg-green-100 hover:text-green-700">
                    New <span id="customerCountNew" class="ml-1 text-xs">(0)</span>
                </button>
                <button id="customerFilterReturning" onclick="filterActiveCustomers('returning')" class="px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-600 hover:bg-blue-100 hover:text-blue-700">
                    Returning <span id="customerCountReturning" class="ml-1 text-xs">(0)</span>
                </button>
            </div>
            <div id="activeCustomersContent" class="max-h-[450px] overflow-auto">
                <!-- Will be populated by JS -->
            </div>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Enhanced Dashboard JavaScript
class EnhancedDashboard {
    constructor() {
        this.charts = {};
        this.currentTab = 'monthly';
        this.currentMonth = new Date().getMonth() + 1;
        this.currentYear = new Date().getFullYear();
        this.monthlyData = [];
        this.monthlyLedgerData = [];
        this.dailyData = [];
        this.viewMode = 'total'; // 'total', 'online_cash', or 'shopify_manual'
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
        // Only use topCardsMonthSelector as the global selector now
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
        
        // Set initial daily month label
        const dailyLabel = document.getElementById('dailyMonthLabel');
        if (dailyLabel && months.length > 0) {
            dailyLabel.textContent = months[0].label;
        }
    }

    setupEventListeners() {
        // Tab switching
        document.getElementById('monthlyTab').addEventListener('click', () => this.switchTab('monthly'));
        document.getElementById('dailyTab').addEventListener('click', () => this.switchTab('daily'));
        document.getElementById('customersTab').addEventListener('click', () => this.switchTab('customers'));
        document.getElementById('productsTab').addEventListener('click', () => this.switchTab('products'));
        document.getElementById('statsTab').addEventListener('click', () => this.switchTab('stats'));

        // Top cards month selector - Also controls Daily tab
        document.getElementById('topCardsMonthSelector').addEventListener('change', () => {
            this.loadTopCards();
            // Update daily month label
            const select = document.getElementById('topCardsMonthSelector');
            const label = document.getElementById('dailyMonthLabel');
            if (label && select.selectedOptions.length > 0) {
                label.textContent = select.selectedOptions[0].text;
            }
            // If on daily tab, reload daily data
            if (this.currentTab === 'daily') {
                this.loadDailyData();
            }
        });

        // Monthly controls
        document.getElementById('monthsSelector').addEventListener('change', () => this.loadMonthlyData());
        
        // View toggle (Total vs Online/Cash vs Shopify/Manual)
        document.getElementById('viewTotal').addEventListener('click', () => this.setViewMode('total'));
        document.getElementById('viewOnlineCash').addEventListener('click', () => this.setViewMode('online_cash'));
        document.getElementById('viewShopifyManual').addEventListener('click', () => this.setViewMode('shopify_manual'));

        // Customer controls
        document.getElementById('customerMonthSelector').addEventListener('change', () => this.loadCustomerAnalysis());

        // Product controls
        document.getElementById('productMonthSelector').addEventListener('change', () => this.loadProductCategories());
        document.getElementById('categoryLevelSelector').addEventListener('change', () => this.loadProductCategories());

        // Refresh & Clear Cache
        document.getElementById('refreshBtn').addEventListener('click', () => this.refreshCurrentTab());
        document.getElementById('clearCacheBtn').addEventListener('click', () => this.clearCache());
    }
    
    setViewMode(mode) {
        this.viewMode = mode;
        
        // Update button styles
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.remove('active', 'bg-white', 'shadow');
            btn.classList.add('text-gray-600');
        });
        
        // Select the right button based on mode
        let activeBtn;
        if (mode === 'total') activeBtn = document.getElementById('viewTotal');
        else if (mode === 'online_cash') activeBtn = document.getElementById('viewOnlineCash');
        else activeBtn = document.getElementById('viewShopifyManual');
        
        activeBtn.classList.add('active', 'bg-white', 'shadow');
        activeBtn.classList.remove('text-gray-600');
        
        // Recreate charts with the selected view mode
        if (this.monthlyLedgerData) {
            this.createMonthlyCharts(this.monthlyLedgerData);
        }
    }

    setCurrentMonthYear() {
        // The month selectors are now populated dynamically with current month as default
        // No need to set them manually as they're auto-selected in populateMonthSelectors
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
                
                // Total Invoices (ALL delivered, excluding reversed)
                document.getElementById('topInvoices').textContent = `PKR ${this.formatNumber(d.invoices)}`;
                document.getElementById('topInvoiceCount').textContent = `${d.invoice_count} invoices`;
                
                // Online breakdown
                document.getElementById('topOnlineTotal').textContent = `PKR ${this.formatNumber(d.online_total || 0)}`;
                document.getElementById('topOnlineApproved').textContent = `${d.online_approved_count || 0} (${this.formatNumber(d.online_approved_total || 0)})`;
                document.getElementById('topOnlinePendingL1').textContent = `${d.online_pending_l1_count || 0} (${this.formatNumber(d.online_pending_l1_total || 0)})`;
                document.getElementById('topOnlinePendingL2').textContent = `${d.online_pending_l2_count || 0} (${this.formatNumber(d.online_pending_l2_total || 0)})`;
                
                // Cash breakdown
                document.getElementById('topCashTotal').textContent = `PKR ${this.formatNumber(d.cash_total || 0)}`;
                document.getElementById('topCashApproved').textContent = `${d.cash_approved_count || 0} (${this.formatNumber(d.cash_approved_total || 0)})`;
                document.getElementById('topCashPending').textContent = `${d.cash_pending_count || 0} (${this.formatNumber(d.cash_pending_total || 0)})`;
                
                // Expenses
                document.getElementById('topExpenses').textContent = `PKR ${this.formatNumber(d.expenses)}`;
                document.getElementById('topExpenseCount').textContent = `${d.expense_count} expenses`;
                
                // Vendor Payments
                document.getElementById('topVendorPayments').textContent = `PKR ${this.formatNumber(d.vendor_payments)}`;
                document.getElementById('topVendorCount').textContent = `${d.vendor_payment_count} payments`;
                
                // Profit
                document.getElementById('topProfit').textContent = `PKR ${this.formatNumber(d.profit)}`;
                
                // Active Customers - 90 day stats (always from today, not selected month)
                document.getElementById('topActiveCustomers').textContent = this.formatNumber(d.active_customers_90d);
                document.getElementById('topNewCustomers').textContent = `+${d.new_customers_90d || 0} new`;
                
                // Store for popup
                this.topCardsData = d;
            }
        } catch (error) {
            console.error('Error loading top cards:', error);
        }
    }

    async loadMonthlyData() {
        this.showLoading(true);
        try {
            const months = document.getElementById('monthsSelector').value;
            
            // Load LEDGER analytics (invoices, expenses, vendor payments) - matches top cards
            try {
                const [analyticsRes, categoryRes] = await Promise.all([
                    this.fetchWithTimeout(`/dashboard/monthly-ledger-analytics?months=${months}`, 30000),
                    this.fetchWithTimeout(`/dashboard/monthly-product-categories?months=${months}`, 30000)
                ]);
                const analytics = await analyticsRes.json();
                const categories = await categoryRes.json();
                
                console.log('Monthly ledger analytics response:', analytics);
                if (analytics.success && analytics.data) {
                    this.monthlyLedgerData = analytics.data;
                    this.updateMonthlyKPIs(analytics.data);
                    this.createMonthlyCharts(analytics.data);
                } else if (analytics.error) {
                    console.error('Server error:', analytics.error);
                    this.showError('monthlyTotalRevenue', 'Error: ' + analytics.error.substring(0, 50));
                }
                
                if (categories.success && categories.data) {
                    this.updateMonthlyCategoryTable(categories.data);
                }
            } catch (e) {
                console.warn('Monthly ledger analytics loading failed:', e.message);
                this.showError('monthlyTotalRevenue', 'Failed to load: ' + e.message);
            }
            
            // Load MoM growth from ledger data
            try {
                const growthRes = await this.fetchWithTimeout(`/dashboard/monthly-ledger-analytics?months=7`, 30000);
                const growth = await growthRes.json();
                if (growth.success && growth.data) {
                    this.updateMoMGrowthTableFromLedger(growth.data);
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
            // Use topCardsMonthSelector as the global selector (format: YYYY-MM)
            const monthValue = document.getElementById('topCardsMonthSelector').value;
            const [year, month] = monthValue.split('-');
            
            // Update the daily month label
            const select = document.getElementById('topCardsMonthSelector');
            const label = document.getElementById('dailyMonthLabel');
            if (label && select.selectedOptions.length > 0) {
                label.textContent = select.selectedOptions[0].text;
            }
            
            const [dailyRes, weeklyRes, categoryRes] = await Promise.all([
                fetch(`/dashboard/daily-analytics?year=${year}&month=${month}`),
                fetch('/dashboard/weekly-performance'),
                fetch(`/dashboard/daily-product-categories?year=${year}&month=${month}`)
            ]);
            
            const daily = await dailyRes.json();
            const weekly = await weeklyRes.json();
            const categories = await categoryRes.json();
            
            if (daily.success) {
                this.dailyData = daily.data;
                this.updateDailyKPIs(daily.data);
                this.createDailyCharts(daily.data);
            }
            
            if (weekly.success) {
                this.createWeeklyChart(weekly.data);
            }
            
            if (categories.success) {
                this.updateDailyCategoryTable(categories.data, monthValue);
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
        // Ledger data uses invoice_total, online_total, cash_total, etc.
        const totalInvoices = data.reduce((sum, item) => sum + (item.invoice_total || 0), 0);
        const totalInvoiceCount = data.reduce((sum, item) => sum + (item.invoice_count || 0), 0);
        const onlineTotal = data.reduce((sum, item) => sum + (item.online_total || 0), 0);
        const cashTotal = data.reduce((sum, item) => sum + (item.cash_total || 0), 0);

        document.getElementById('monthlyTotalRevenue').textContent = `PKR ${this.formatNumber(totalInvoices)}`;
        document.getElementById('monthlyTotalOrders').textContent = this.formatNumber(totalInvoiceCount);
        document.getElementById('monthlyOnlineRevenue').textContent = this.formatNumber(onlineTotal);
        document.getElementById('monthlyCashRevenue').textContent = this.formatNumber(cashTotal);
    }

    updateDailyKPIs(data) {
        const dailyData = data.data;
        const totalRevenue = dailyData.reduce((sum, item) => sum + item.revenue, 0);
        const totalOrders = dailyData.reduce((sum, item) => sum + item.orders, 0);
        const totalCustomers = dailyData.reduce((sum, item) => sum + item.customers, 0);
        const totalQty = dailyData.reduce((sum, item) => sum + (item.total_qty || 0), 0);
        const shopifyRevenue = dailyData.reduce((sum, item) => sum + (item.shopify_revenue || 0), 0);
        const manualRevenue = dailyData.reduce((sum, item) => sum + (item.manual_revenue || 0), 0);
        const shopifyOrders = dailyData.reduce((sum, item) => sum + (item.shopify_orders || 0), 0);
        const manualOrders = dailyData.reduce((sum, item) => sum + (item.manual_orders || 0), 0);
        const onlineOrders = dailyData.reduce((sum, item) => sum + (item.online_count || 0), 0);
        const cashOrders = dailyData.reduce((sum, item) => sum + (item.cash_count || 0), 0);
        const newCustomerOrders = dailyData.reduce((sum, item) => sum + (item.new_customer_orders || 0), 0);
        const returningCustomerOrders = dailyData.reduce((sum, item) => sum + (item.returning_customer_orders || 0), 0);

        document.getElementById('dailyTotalRevenue').textContent = `PKR ${this.formatNumber(totalRevenue)}`;
        document.getElementById('dailyTotalOrders').textContent = this.formatNumber(totalOrders);
        document.getElementById('dailyTotalCustomers').textContent = this.formatNumber(totalCustomers);
        document.getElementById('dailyTotalQty').textContent = this.formatNumber(totalQty);
        document.getElementById('dailyAvgOrder').textContent = totalOrders > 0 ? `PKR ${this.formatNumber(Math.round(totalRevenue / totalOrders))}` : 'PKR 0';
        document.getElementById('dailyShopifyRevenue').textContent = this.formatNumber(shopifyRevenue);
        document.getElementById('dailyManualRevenue').textContent = this.formatNumber(manualRevenue);
        document.getElementById('dailyShopifyOrders').textContent = this.formatNumber(shopifyOrders);
        document.getElementById('dailyManualOrders').textContent = this.formatNumber(manualOrders);
        document.getElementById('dailyOnlineOrders').textContent = this.formatNumber(onlineOrders);
        document.getElementById('dailyCashOrders').textContent = this.formatNumber(cashOrders);
        document.getElementById('dailyNewCustomerOrders').textContent = this.formatNumber(newCustomerOrders);
        document.getElementById('dailyReturningCustomerOrders').textContent = this.formatNumber(returningCustomerOrders);
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

    // MoM Growth table from ledger data - matches Invoice card totals
    updateMoMGrowthTableFromLedger(data) {
        const container = document.getElementById('momGrowthTable');
        
        // Sort by month (descending) and calculate growth
        const sorted = [...data].sort((a, b) => b.month.localeCompare(a.month));
        const rows = [];
        
        for (let i = 0; i < sorted.length && i < 6; i++) {
            const current = sorted[i];
            const previous = sorted[i + 1];
            
            const growthPct = previous && previous.invoice_total > 0 
                ? (((current.invoice_total - previous.invoice_total) / previous.invoice_total) * 100).toFixed(1)
                : 0;
            
            rows.push({
                month_name: current.month_name,
                invoice_total: current.invoice_total,
                growth_pct: parseFloat(growthPct)
            });
        }
        
        container.innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left py-2 px-3 font-medium text-gray-600">Month</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Invoice Total</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Growth</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.map(row => `
                        <tr class="border-t border-gray-100">
                            <td class="py-2 px-3 text-gray-900">${row.month_name}</td>
                            <td class="py-2 px-3 text-right text-gray-900">PKR ${this.formatNumber(row.invoice_total)}</td>
                            <td class="py-2 px-3 text-right">
                                <span class="${row.growth_pct >= 0 ? 'text-green-600' : 'text-red-600'}">
                                    ${row.growth_pct >= 0 ? '+' : ''}${row.growth_pct}%
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

    updateMonthlyCategoryTable(data) {
        const container = document.getElementById('monthlyCategoryTable');
        if (!container) return;
        
        // Handle empty or invalid data
        if (!data || !Array.isArray(data) || data.length === 0) {
            container.innerHTML = '<div class="text-center py-8 text-gray-500">No product category data available</div>';
            return;
        }
        
        // Get all unique categories and months (with null checks)
        const allCategories = new Set();
        const months = data
            .filter(m => m && m.month_name)
            .map(m => m.month_name)
            .reverse(); // oldest first
        
        if (months.length === 0) {
            container.innerHTML = '<div class="text-center py-8 text-gray-500">No monthly data available</div>';
            return;
        }
        
        data.forEach(monthData => {
            if (monthData && monthData.categories) {
                monthData.categories.forEach(cat => {
                    if (cat && cat.category) allCategories.add(cat.category);
                });
            }
        });
        
        if (allCategories.size === 0) {
            container.innerHTML = '<div class="text-center py-8 text-gray-500">No categories found</div>';
            return;
        }
        
        // Build category-month matrix
        const categoryByMonth = {};
        allCategories.forEach(cat => {
            categoryByMonth[cat] = { total_qty: 0, total_revenue: 0, months: {} };
        });
        
        data.forEach(monthData => {
            if (monthData && monthData.categories && monthData.month_name) {
                monthData.categories.forEach(cat => {
                    if (cat && cat.category && categoryByMonth[cat.category]) {
                        categoryByMonth[cat.category].months[monthData.month_name] = {
                            qty: cat.qty || 0,
                            revenue: cat.revenue || 0
                        };
                        categoryByMonth[cat.category].total_qty += cat.qty || 0;
                        categoryByMonth[cat.category].total_revenue += cat.revenue || 0;
                    }
                });
            }
        });
        
        // Sort categories by total revenue
        const sortedCategories = Object.entries(categoryByMonth)
            .sort((a, b) => b[1].total_revenue - a[1].total_revenue)
            .slice(0, 15);
        
        // Build table with monthly columns
        container.innerHTML = `
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left py-2 px-3 font-medium text-gray-600 sticky left-0 bg-gray-50 z-10 min-w-[120px]">Category</th>
                        ${months.map(m => `
                            <th class="text-right py-2 px-2 font-medium text-gray-600 min-w-[80px]" colspan="1">${m && m.includes(' ') ? m.split(' ')[0] : m}</th>
                        `).join('')}
                        <th class="text-right py-2 px-3 font-medium text-gray-900 bg-gray-100 min-w-[80px]">Total Qty</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-900 bg-gray-100 min-w-[100px]">Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    ${sortedCategories.map(([category, catData]) => `
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="py-2 px-3 text-gray-900 font-medium sticky left-0 bg-white z-10">${category}</td>
                            ${months.map(m => {
                                const monthData = catData.months[m];
                                const qty = monthData ? monthData.qty : 0;
                                return `<td class="py-2 px-2 text-right text-gray-600 text-xs">${qty > 0 ? this.formatNumber(qty) : '-'}</td>`;
                            }).join('')}
                            <td class="py-2 px-3 text-right text-gray-900 bg-gray-50">${this.formatNumber(catData.total_qty)}</td>
                            <td class="py-2 px-3 text-right text-gray-900 font-medium bg-gray-50">PKR ${this.formatNumber(catData.total_revenue)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            </div>
        `;
    }

    updateDailyCategoryTable(data, monthKey) {
        const container = document.getElementById('dailyCategoryTable');
        if (!container) return;
        
        // Aggregate all categories for the month
        const categoryTotals = {};
        data.forEach(dayData => {
            if (dayData.categories) {
                dayData.categories.forEach(cat => {
                    if (!categoryTotals[cat.category]) {
                        categoryTotals[cat.category] = { qty: 0, revenue: 0 };
                    }
                    categoryTotals[cat.category].qty += cat.qty || 0;
                    categoryTotals[cat.category].revenue += cat.revenue || 0;
                });
            }
        });
        
        const sortedCategories = Object.entries(categoryTotals)
            .sort((a, b) => b[1].revenue - a[1].revenue)
            .slice(0, 15);
        
        container.innerHTML = `
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left py-2 px-3 font-medium text-gray-600">Category</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Qty</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    ${sortedCategories.map(([category, totals]) => `
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="py-2 px-3 text-gray-900">${category}</td>
                            <td class="py-2 px-3 text-right text-gray-900">${this.formatNumber(totals.qty)}</td>
                            <td class="py-2 px-3 text-right text-gray-900 font-medium">PKR ${this.formatNumber(totals.revenue)}</td>
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

        const viewMode = this.viewMode; // 'total', 'online_cash', or 'shopify_manual'

        // Revenue Chart - from ledger (invoices)
        if (viewMode === 'online_cash') {
            // Online vs Cash stacked
        this.charts.monthlyRevenue = new Chart(document.getElementById('monthlyRevenueChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [
                    {
                            label: 'Cash',
                            data: data.map(item => item.cash_total || 0),
                            backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderRadius: 4,
                    },
                    {
                            label: 'Online',
                            data: data.map(item => item.online_total || 0),
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    }
                ]
            },
            options: { ...this.getStackedChartOptions('PKR'), plugins: { legend: { display: true, position: 'top' } } }
        });
        } else if (viewMode === 'shopify_manual') {
            // Shopify vs Manual stacked
            this.charts.monthlyRevenue = new Chart(document.getElementById('monthlyRevenueChart'), {
                type: 'bar',
                data: {
                    labels: data.map(item => item.month_name),
                    datasets: [
                        {
                            label: 'Manual',
                            data: data.map(item => item.manual_total || 0),
                            backgroundColor: 'rgba(107, 114, 128, 0.8)',
                            borderRadius: 4,
                        },
                        {
                            label: 'Shopify',
                            data: data.map(item => item.shopify_total || 0),
                            backgroundColor: 'rgba(139, 92, 246, 0.8)',
                            borderRadius: 4,
                        }
                    ]
                },
                options: { ...this.getStackedChartOptions('PKR'), plugins: { legend: { display: true, position: 'top' } } }
            });
        } else {
            // Total view: Single bar with total invoice revenue
            this.charts.monthlyRevenue = new Chart(document.getElementById('monthlyRevenueChart'), {
                type: 'bar',
                data: {
                    labels: data.map(item => item.month_name),
                    datasets: [{
                        label: 'Invoice Revenue',
                        data: data.map(item => item.invoice_total || 0),
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderRadius: 4,
                    }]
                },
                options: this.getChartOptions('PKR')
            });
        }

        // Invoice Count Chart
        if (viewMode === 'online_cash') {
            // Online vs Cash counts
        this.charts.monthlyOrders = new Chart(document.getElementById('monthlyOrdersChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [
                    {
                            label: 'Cash Invoices',
                            data: data.map(item => item.cash_count || 0),
                            backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderRadius: 4,
                    },
                    {
                            label: 'Online Invoices',
                            data: data.map(item => item.online_count || 0),
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    }
                ]
            },
            options: { ...this.getStackedChartOptions(), plugins: { legend: { display: true, position: 'top' } } }
        });
        } else if (viewMode === 'shopify_manual') {
            // Shopify vs Manual counts
            this.charts.monthlyOrders = new Chart(document.getElementById('monthlyOrdersChart'), {
                type: 'bar',
                data: {
                    labels: data.map(item => item.month_name),
                    datasets: [
                        {
                            label: 'Manual Invoices',
                            data: data.map(item => item.manual_count || 0),
                            backgroundColor: 'rgba(107, 114, 128, 0.8)',
                            borderRadius: 4,
                        },
                        {
                            label: 'Shopify Invoices',
                            data: data.map(item => item.shopify_count || 0),
                            backgroundColor: 'rgba(139, 92, 246, 0.8)',
                            borderRadius: 4,
                        }
                    ]
                },
                options: { ...this.getStackedChartOptions(), plugins: { legend: { display: true, position: 'top' } } }
            });
        } else {
            // Total view: Single bar with total invoice count
            this.charts.monthlyOrders = new Chart(document.getElementById('monthlyOrdersChart'), {
                type: 'bar',
                data: {
                    labels: data.map(item => item.month_name),
                    datasets: [{
                        label: 'Total Invoices',
                        data: data.map(item => item.invoice_count || 0),
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    }]
                },
                options: this.getChartOptions()
            });
        }

        // Online vs Cash Revenue by Month - Stacked Bar Chart (not pie)
        this.charts.monthlySource = new Chart(document.getElementById('monthlySourceChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [
                    {
                        label: 'Cash',
                        data: data.map(item => item.cash_total || 0),
                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderRadius: 4,
                    },
                    {
                        label: 'Online',
                        data: data.map(item => item.online_total || 0),
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    }
                ]
            },
            options: { 
                ...this.getStackedChartOptions('PKR'), 
                plugins: { legend: { display: true, position: 'top' } },
                onClick: (event, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const monthData = data[index];
                        this.showMonthDetails(monthData, 'payment_mode');
                    }
                }
            }
        });

        // New vs Returning Customer Charts
        this.destroyChart('monthlyCustomerType');
        this.destroyChart('monthlyCustomerRevenue');

        // Customer Type Orders Chart (stacked bar)
        this.charts.monthlyCustomerType = new Chart(document.getElementById('monthlyCustomerTypeChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [
                    {
                        label: 'Returning',
                        data: data.map(item => item.returning_customer_orders || 0),
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    },
                    {
                        label: 'New',
                        data: data.map(item => item.new_customer_orders || 0),
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderRadius: 4,
                    }
                ]
            },
            options: { ...this.getStackedChartOptions(), plugins: { legend: { display: true, position: 'top' } } }
        });

        // Customer Type Revenue Chart (stacked bar)
        this.charts.monthlyCustomerRevenue = new Chart(document.getElementById('monthlyCustomerRevenueChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [
                    {
                        label: 'Returning',
                        data: data.map(item => item.returning_customer_revenue || 0),
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4,
                    },
                    {
                        label: 'New',
                        data: data.map(item => item.new_customer_revenue || 0),
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderRadius: 4,
                    }
                ]
            },
            options: { ...this.getStackedChartOptions('PKR'), plugins: { legend: { display: true, position: 'top' } } }
        });
    }

    createDailyCharts(data) {
        this.destroyChart('dailyRevenue');
        this.destroyChart('dailyOrders');
        this.destroyChart('dailyQty');
        this.destroyChart('dailyCustomerType');

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

        // Qty Chart
        this.charts.dailyQty = new Chart(document.getElementById('dailyQtyChart'), {
            type: 'bar',
            data: {
                labels: dailyData.map(item => item.day_name),
                datasets: [{
                    label: 'Quantity',
                    data: dailyData.map(item => item.total_qty || 0),
                    backgroundColor: 'rgba(249, 115, 22, 0.8)',
                    borderRadius: 4
                }]
            },
            options: this.getChartOptions()
        });

        // New vs Returning Customer Type Chart
        this.charts.dailyCustomerType = new Chart(document.getElementById('dailyCustomerTypeChart'), {
            type: 'bar',
            data: {
                labels: dailyData.map(item => item.day_name),
                datasets: [
                    {
                        label: 'Returning',
                        data: dailyData.map(item => item.returning_customer_orders || 0),
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderRadius: 4
                    },
                    {
                        label: 'New',
                        data: dailyData.map(item => item.new_customer_orders || 0),
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderRadius: 4
                    }
                ]
            },
            options: { ...this.getStackedChartOptions(), plugins: { legend: { display: true, position: 'top' } } }
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

    // =========================================================================
    // FULL VIEW & DRILL-DOWN METHODS
    // =========================================================================

    openFullView(chartId, title) {
        console.log('EnhancedDashboard.openFullView called with:', chartId, title);
        const modal = document.getElementById('fullViewModal');
        console.log('Modal element found:', modal);
        const titleEl = document.getElementById('fullViewTitle');
        titleEl.textContent = title;
        
        // Store current chart context for details view
        this.currentFullViewChart = chartId;
        this.currentFullViewTitle = title;
        
        console.log('Removing hidden class from modal');
        modal.classList.remove('hidden');
        console.log('Modal hidden class removed, classList:', modal.classList);
        
        // Create full view chart based on the source chart
        this.destroyChart('fullView');
        const chartKey = chartId.replace('Chart', '');
        const sourceChart = this.charts[chartKey];
        
        console.log('Opening full view for:', chartKey, 'Source chart:', sourceChart ? 'found' : 'not found', 'Available:', Object.keys(this.charts));
        
        if (sourceChart) {
            try {
                // Get the chart type and data directly from the chart instance
                const chartType = sourceChart.config.type;
                const chartData = sourceChart.data;
                const chartOptions = sourceChart.options;
                
                // Create a clean config object
                const config = {
                    type: chartType,
                    data: {
                        labels: [...chartData.labels],
                        datasets: chartData.datasets.map(ds => ({
                            ...ds,
                            data: [...ds.data]
                        }))
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true, position: 'top' }
                        },
                        scales: chartOptions.scales ? JSON.parse(JSON.stringify(chartOptions.scales)) : undefined
                    }
                };
                
                // Enhance for full view - show day of week on daily charts
                if (chartId.includes('daily') && this.dailyData && this.dailyData.data) {
                    config.data.labels = this.dailyData.data.map(item => 
                        `${item.day_name} (${item.day_of_week ? item.day_of_week.substring(0, 3) : ''})`
                    );
                }
                
                const fullViewCanvas = document.getElementById('fullViewChart');
                if (fullViewCanvas) {
                    this.charts.fullView = new Chart(fullViewCanvas, config);
                }
            } catch (e) {
                console.error('Error creating full view chart:', e);
                console.error('Chart config:', sourceChart.config);
            }
        } else {
            console.warn('No source chart found for:', chartKey, 'Available charts:', Object.keys(this.charts));
        }
    }

    closeFullView() {
        document.getElementById('fullViewModal').classList.add('hidden');
        this.destroyChart('fullView');
    }

    showChartDetails() {
        const modal = document.getElementById('chartDetailsModal');
        const titleEl = document.getElementById('chartDetailsTitle');
        const content = document.getElementById('chartDetailsContent');
        
        titleEl.textContent = `${this.currentFullViewTitle} - Raw Data`;
        
        // Determine which data to show based on current chart
        let tableHtml = '';
        
        if (this.currentFullViewChart && this.currentFullViewChart.includes('daily') && this.dailyData) {
            // Daily data
            tableHtml = this.buildDailyDataTable(this.dailyData.data);
        } else if (this.monthlyLedgerData) {
            // Monthly data
            tableHtml = this.buildMonthlyDataTable(this.monthlyLedgerData);
        }
        
        content.innerHTML = tableHtml;
        modal.classList.remove('hidden');
    }

    closeChartDetails() {
        document.getElementById('chartDetailsModal').classList.add('hidden');
    }

    buildDailyDataTable(data) {
        return `
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left py-2 px-3 font-medium text-gray-600">Date</th>
                        <th class="text-left py-2 px-3 font-medium text-gray-600">Day</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Revenue</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Orders</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Qty</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Online</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Cash</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">New</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Returning</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.filter(d => d.orders > 0).map(row => `
                        <tr class="border-t border-gray-100 hover:bg-gray-50 cursor-pointer" onclick="showOrdersForDate('${row.date}')">
                            <td class="py-2 px-3 text-gray-900">${row.day_name}</td>
                            <td class="py-2 px-3 text-gray-600">${row.day_of_week}</td>
                            <td class="py-2 px-3 text-right text-gray-900 font-medium">PKR ${this.formatNumber(row.revenue)}</td>
                            <td class="py-2 px-3 text-right text-gray-900">${row.orders}</td>
                            <td class="py-2 px-3 text-right text-gray-900">${this.formatNumber(row.total_qty || 0)}</td>
                            <td class="py-2 px-3 text-right text-blue-600">${row.online_count || 0}</td>
                            <td class="py-2 px-3 text-right text-green-600">${row.cash_count || 0}</td>
                            <td class="py-2 px-3 text-right text-emerald-600">${row.new_customer_orders || 0}</td>
                            <td class="py-2 px-3 text-right text-blue-600">${row.returning_customer_orders || 0}</td>
                        </tr>
                    `).join('')}
                </tbody>
                <tfoot class="bg-gray-100 font-medium">
                    <tr>
                        <td class="py-2 px-3" colspan="2">Total</td>
                        <td class="py-2 px-3 text-right">PKR ${this.formatNumber(data.reduce((s,r) => s + r.revenue, 0))}</td>
                        <td class="py-2 px-3 text-right">${data.reduce((s,r) => s + r.orders, 0)}</td>
                        <td class="py-2 px-3 text-right">${this.formatNumber(data.reduce((s,r) => s + (r.total_qty || 0), 0))}</td>
                        <td class="py-2 px-3 text-right text-blue-600">${data.reduce((s,r) => s + (r.online_count || 0), 0)}</td>
                        <td class="py-2 px-3 text-right text-green-600">${data.reduce((s,r) => s + (r.cash_count || 0), 0)}</td>
                        <td class="py-2 px-3 text-right text-emerald-600">${data.reduce((s,r) => s + (r.new_customer_orders || 0), 0)}</td>
                        <td class="py-2 px-3 text-right text-blue-600">${data.reduce((s,r) => s + (r.returning_customer_orders || 0), 0)}</td>
                    </tr>
                </tfoot>
            </table>
        `;
    }

    buildMonthlyDataTable(data) {
        return `
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left py-2 px-3 font-medium text-gray-600">Month</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Revenue</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Invoices</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Online</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Cash</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Shopify</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Manual</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">New</th>
                        <th class="text-right py-2 px-3 font-medium text-gray-600">Returning</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.map(row => `
                        <tr class="border-t border-gray-100 hover:bg-gray-50">
                            <td class="py-2 px-3 text-gray-900 font-medium">${row.month_name}</td>
                            <td class="py-2 px-3 text-right text-gray-900 font-medium">PKR ${this.formatNumber(row.invoice_total)}</td>
                            <td class="py-2 px-3 text-right text-gray-900">${row.invoice_count}</td>
                            <td class="py-2 px-3 text-right text-blue-600">PKR ${this.formatNumber(row.online_total)} (${row.online_count})</td>
                            <td class="py-2 px-3 text-right text-green-600">PKR ${this.formatNumber(row.cash_total)} (${row.cash_count})</td>
                            <td class="py-2 px-3 text-right text-purple-600">PKR ${this.formatNumber(row.shopify_total)} (${row.shopify_count})</td>
                            <td class="py-2 px-3 text-right text-gray-600">PKR ${this.formatNumber(row.manual_total)} (${row.manual_count})</td>
                            <td class="py-2 px-3 text-right text-emerald-600">${row.new_customer_orders || 0}</td>
                            <td class="py-2 px-3 text-right text-blue-600">${row.returning_customer_orders || 0}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    showMonthDetails(monthData, type) {
        const modal = document.getElementById('chartDetailsModal');
        const titleEl = document.getElementById('chartDetailsTitle');
        const content = document.getElementById('chartDetailsContent');
        
        titleEl.textContent = `${monthData.month_name} - ${type === 'payment_mode' ? 'Payment Mode' : 'Details'}`;
        
        content.innerHTML = `
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <p class="text-sm text-blue-700 mb-1">Online Revenue</p>
                    <p class="text-2xl font-bold text-blue-900">PKR ${this.formatNumber(monthData.online_total)}</p>
                    <p class="text-sm text-blue-600">${monthData.online_count} invoices</p>
                </div>
                <div class="bg-emerald-50 p-4 rounded-lg">
                    <p class="text-sm text-emerald-700 mb-1">Cash Revenue</p>
                    <p class="text-2xl font-bold text-emerald-900">PKR ${this.formatNumber(monthData.cash_total)}</p>
                    <p class="text-sm text-emerald-600">${monthData.cash_count} invoices</p>
                </div>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <p class="text-sm text-gray-700 mb-1">Total Revenue</p>
                <p class="text-2xl font-bold text-gray-900">PKR ${this.formatNumber(monthData.invoice_total)}</p>
                <p class="text-sm text-gray-600">${monthData.invoice_count} total invoices</p>
            </div>
        `;
        
        modal.classList.remove('hidden');
    }

    // =========================================================================
    // ACTIVE CUSTOMERS POPUP
    // =========================================================================

    async showActiveCustomersPopup() {
        console.log('EnhancedDashboard.showActiveCustomersPopup called');
        const modal = document.getElementById('activeCustomersModal');
        const content = document.getElementById('activeCustomersContent');
        console.log('Active customers modal element:', modal);
        
        // Update counts from stored data
        if (this.topCardsData) {
            document.getElementById('customerCountAll').textContent = `(${this.topCardsData.active_customers_90d || 0})`;
            document.getElementById('customerCountNew').textContent = `(${this.topCardsData.new_customers_90d || 0})`;
            document.getElementById('customerCountReturning').textContent = `(${this.topCardsData.returning_customers_90d || 0})`;
        }
        
        console.log('Showing active customers modal');
        modal.classList.remove('hidden');
        content.innerHTML = '<div class="text-center py-8"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div><p class="mt-2 text-gray-500">Loading customers...</p></div>';
        
        this.filterActiveCustomers('all');
    }

    async filterActiveCustomers(filter) {
        const content = document.getElementById('activeCustomersContent');
        
        // Update filter button styles
        ['All', 'New', 'Returning'].forEach(f => {
            const btn = document.getElementById(`customerFilter${f}`);
            if (f.toLowerCase() === filter) {
                btn.className = 'px-4 py-2 text-sm font-medium rounded-lg ' + 
                    (f === 'New' ? 'bg-green-100 text-green-700' : f === 'Returning' ? 'bg-blue-100 text-blue-700' : 'bg-blue-100 text-blue-700');
            } else {
                btn.className = 'px-4 py-2 text-sm font-medium rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200';
            }
        });
        
        try {
            const response = await fetch(`/dashboard/active-customers-list?filter=${filter}`);
            const data = await response.json();
            
            if (data.success && data.data.length > 0) {
                content.innerHTML = `
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 sticky top-0">
                            <tr>
                                <th class="text-left py-2 px-3 font-medium text-gray-600">Name</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600">Phone</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600">City</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600">Type</th>
                                <th class="text-right py-2 px-3 font-medium text-gray-600">Orders</th>
                                <th class="text-right py-2 px-3 font-medium text-gray-600">Spent</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600">First Order</th>
                                <th class="text-left py-2 px-3 font-medium text-gray-600">Last Order</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.data.map(c => `
                                <tr class="border-t border-gray-100 hover:bg-gray-50">
                                    <td class="py-2 px-3 text-gray-900 font-medium">${c.name || '-'}</td>
                                    <td class="py-2 px-3 text-gray-600">${c.phone || '-'}</td>
                                    <td class="py-2 px-3 text-gray-600">${c.city || '-'}</td>
                                    <td class="py-2 px-3">
                                        <span class="text-xs px-2 py-0.5 rounded ${c.type === 'new' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'}">
                                            ${c.type === 'new' ? 'New' : 'Returning'}
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 text-right text-gray-900">${c.total_orders}</td>
                                    <td class="py-2 px-3 text-right text-gray-900 font-medium">PKR ${this.formatNumber(c.total_spent)}</td>
                                    <td class="py-2 px-3 text-gray-600">${c.first_order_date || '-'}</td>
                                    <td class="py-2 px-3 text-gray-600">${c.last_order_date || '-'}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            } else {
                content.innerHTML = '<div class="text-center py-8 text-gray-500">No customers found</div>';
            }
        } catch (error) {
            console.error('Error loading customers:', error);
            content.innerHTML = '<div class="text-center py-8 text-red-500">Error loading customers</div>';
        }
    }

    closeActiveCustomersPopup() {
        document.getElementById('activeCustomersModal').classList.add('hidden');
    }
}

// Make dashboard globally accessible
let dashboard;
document.addEventListener('DOMContentLoaded', function() {
    dashboard = new EnhancedDashboard();
    dashboard.init();
});

// Global wrapper functions for onclick handlers
function showActiveCustomersPopup() {
    console.log('showActiveCustomersPopup called');
    if (dashboard) {
        dashboard.showActiveCustomersPopup();
    } else {
        console.error('Dashboard not initialized');
    }
}

function closeActiveCustomersPopup() {
    if (dashboard) {
        dashboard.closeActiveCustomersPopup();
    }
}

function filterActiveCustomers(filter) {
    if (dashboard) {
        dashboard.filterActiveCustomers(filter);
    }
}

function openFullView(chartId, title) {
    console.log('openFullView called with:', chartId, title);
    if (dashboard) {
        dashboard.openFullView(chartId, title);
    } else {
        console.error('Dashboard not initialized');
    }
}

function closeFullView() {
    if (dashboard) {
        dashboard.closeFullView();
    }
}

function showChartDetails() {
    if (dashboard) {
        dashboard.showChartDetails();
    }
}

function closeChartDetails() {
    if (dashboard) {
        dashboard.closeChartDetails();
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

async function showDrilldown(type) {
    console.log('showDrilldown called with type:', type);
    const modal = document.getElementById('orderDetailModal');
    const content = document.getElementById('modalContent');
    const title = document.getElementById('modalTitle');
    const month = document.getElementById('topCardsMonthSelector').value;
    console.log('Modal element:', modal, 'Month:', month);
    
    // Set title based on type
    const titles = {
        'invoices': 'Delivered Orders',
        'expenses': 'Expense Transactions',
        'vendor': 'Vendor Payment Transactions'
    };
    title.textContent = titles[type] || 'Transactions';
    
    // Map type to API parameter
    const typeMap = {
        'invoices': 'invoice',
        'expenses': 'expense',
        'vendor': 'vendor_payment'
    };
    const apiType = typeMap[type] || type;
    
    content.innerHTML = '<div class="text-center py-8"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div><p class="mt-2 text-gray-600">Loading transactions...</p></div>';
    modal.classList.remove('hidden');
    
    try {
        const response = await fetch(`/dashboard/ledger-transactions?month=${month}&type=${apiType}`);
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            const totalAmount = data.data.reduce((sum, txn) => sum + txn.amount, 0);
            
            // Different table structure for invoices vs expenses/vendor payments
            if (type === 'invoices') {
                content.innerHTML = `
                    <div class="mb-4 p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                        <span class="text-sm text-gray-600">${data.data.length} orders</span>
                        <span class="text-lg font-bold text-gray-900">Total: PKR ${totalAmount.toLocaleString()}</span>
                    </div>
                    <div class="max-h-96 overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="text-left py-2 px-3 font-medium text-gray-600">Order #</th>
                                    <th class="text-left py-2 px-3 font-medium text-gray-600">Customer</th>
                                    <th class="text-left py-2 px-3 font-medium text-gray-600">Order Date</th>
                                    <th class="text-left py-2 px-3 font-medium text-gray-600">Delivery Date</th>
                                    <th class="text-center py-2 px-3 font-medium text-gray-600">Payment</th>
                                    <th class="text-center py-2 px-3 font-medium text-gray-600">Status</th>
                                    <th class="text-right py-2 px-3 font-medium text-gray-600">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.data.map(txn => `
                                    <tr class="border-t border-gray-100 hover:bg-gray-50 cursor-pointer" onclick="window.open('/orders/${txn.id}', '_blank')">
                                        <td class="py-2 px-3 text-blue-600 font-medium">${txn.order_number || '-'}</td>
                                        <td class="py-2 px-3 text-gray-900">
                                            <div>${txn.customer_name || '-'}</div>
                                            <div class="text-xs text-gray-500">${txn.customer_phone || ''}</div>
                                        </td>
                                        <td class="py-2 px-3 text-gray-600 whitespace-nowrap">${txn.order_date || '-'}</td>
                                        <td class="py-2 px-3 text-gray-600 whitespace-nowrap">${txn.delivery_date || '-'}</td>
                                        <td class="py-2 px-3 text-center">
                                            <span class="px-2 py-0.5 text-xs rounded ${txn.payment_method?.toLowerCase().includes('online') || txn.payment_method?.toLowerCase().includes('card') ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'}">${txn.payment_method || 'Cash'}</span>
                                        </td>
                                        <td class="py-2 px-3 text-center">
                                            <span class="px-2 py-0.5 text-xs rounded ${getStatusColor(txn.approval_status)}">${formatStatus(txn.approval_status)}</span>
                                        </td>
                                        <td class="py-2 px-3 text-right font-medium text-gray-900">PKR ${txn.amount.toLocaleString()}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                // Expenses and Vendor Payments table
                content.innerHTML = `
                    <div class="mb-4 p-3 bg-gray-50 rounded-lg flex justify-between items-center">
                        <span class="text-sm text-gray-600">${data.data.length} transactions</span>
                        <span class="text-lg font-bold text-gray-900">Total: PKR ${totalAmount.toLocaleString()}</span>
                    </div>
                    <div class="max-h-96 overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="text-left py-2 px-3 font-medium text-gray-600">Date</th>
                                    <th class="text-left py-2 px-3 font-medium text-gray-600">Description</th>
                                    <th class="text-center py-2 px-3 font-medium text-gray-600">Mode</th>
                                    <th class="text-right py-2 px-3 font-medium text-gray-600">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.data.map(txn => `
                                    <tr class="border-t border-gray-100 hover:bg-gray-50">
                                        <td class="py-2 px-3 text-gray-600 whitespace-nowrap">${txn.date}</td>
                                        <td class="py-2 px-3 text-gray-900">${txn.description || '-'}</td>
                                        <td class="py-2 px-3 text-center">
                                            <span class="px-2 py-0.5 text-xs rounded ${txn.mode?.toLowerCase() === 'online' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700'}">${txn.mode || 'Cash'}</span>
                                        </td>
                                        <td class="py-2 px-3 text-right font-medium text-gray-900">PKR ${txn.amount.toLocaleString()}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
        } else {
            content.innerHTML = '<div class="text-center py-8 text-gray-500">No transactions found for this period.</div>';
        }
    } catch (error) {
        console.error('Error loading transactions:', error);
        content.innerHTML = '<div class="text-center py-8 text-red-500">Error loading transactions.</div>';
    }
}

function getStatusColor(status) {
    const colors = {
        'approved': 'bg-green-100 text-green-700',
        'pending': 'bg-amber-100 text-amber-700',
        'pending_l1': 'bg-amber-100 text-amber-700',
        'pending_l2': 'bg-orange-100 text-orange-700',
        'rejected': 'bg-red-100 text-red-700',
        'reversed': 'bg-gray-100 text-gray-700'
    };
    return colors[status] || 'bg-gray-100 text-gray-700';
}

function formatStatus(status) {
    const labels = {
        'approved': 'Approved',
        'pending': 'Pending',
        'pending_l1': 'Pending L1',
        'pending_l2': 'Pending L2',
        'rejected': 'Rejected',
        'reversed': 'Reversed'
    };
    return labels[status] || status || '-';
}

// Ensure all functions are globally accessible
window.showDrilldown = showDrilldown;
window.showOrdersForDate = showOrdersForDate;
window.closeOrderModal = closeOrderModal;
window.getStatusColor = getStatusColor;
window.formatStatus = formatStatus;
window.showActiveCustomersPopup = showActiveCustomersPopup;
window.closeActiveCustomersPopup = closeActiveCustomersPopup;
window.filterActiveCustomers = filterActiveCustomers;
window.openFullView = openFullView;
window.closeFullView = closeFullView;
window.showChartDetails = showChartDetails;
window.closeChartDetails = closeChartDetails;

// Test function to directly show modals
window.testModal = function(modalId) {
    const modal = document.getElementById(modalId);
    console.log('Testing modal:', modalId, 'Element:', modal);
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'block';
        console.log('Modal should now be visible, classList:', modal.classList, 'display:', modal.style.display);
    } else {
        console.error('Modal not found:', modalId);
    }
};

console.log('Dashboard functions loaded and attached to window');
console.log('Available modals:', {
    fullView: document.getElementById('fullViewModal'),
    orderDetail: document.getElementById('orderDetailModal'),
    activeCustomers: document.getElementById('activeCustomersModal'),
    chartDetails: document.getElementById('chartDetailsModal')
});
</script>

<style>
.dashboard-tab.active {
    border-bottom-color: rgb(59 130 246) !important;
    color: rgb(59 130 246) !important;
}
.source-btn.active, .view-btn.active {
    background: white;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
</style>
@endsection
