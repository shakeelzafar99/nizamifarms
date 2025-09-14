@extends('layouts.app')

@section('title', 'Analytics Dashboard')

@section('content')
<div class="kt-container-fixed">
    <!-- Header Section -->
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
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
        </div>
    </div>

    <!-- Loading Indicator -->
    <div id="dashboardLoading" class="hidden">
        <div class="flex items-center justify-center py-20">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <span class="ml-3 text-gray-600">Loading dashboard data...</span>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="mb-7.5">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8">
                <button id="monthlyTab" class="dashboard-tab active border-b-2 border-blue-500 py-2 px-1 text-sm font-medium text-blue-600">
                    Monthly View
                </button>
                <button id="dailyTab" class="dashboard-tab border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    Daily View
                </button>
                <button id="statsTab" class="dashboard-tab border-b-2 border-transparent py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    General Statistics
                </button>
            </nav>
        </div>
    </div>

    <!-- Monthly Analytics Tab -->
    <div id="monthlyContent" class="dashboard-content">
        <!-- Month Selector -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-lg font-semibold text-gray-900">Monthly Trends</h2>
            <select id="monthsSelector" class="select select-sm">
                <option value="6">Last 6 Months</option>
                <option value="12" selected>Last 12 Months</option>
                <option value="24">Last 24 Months</option>
            </select>
        </div>

        <!-- Monthly KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="kt-card">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Revenue</p>
                            <p id="monthlyTotalRevenue" class="text-2xl font-bold text-gray-900">PKR 0</p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="ki-filled ki-chart-line-up text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Orders</p>
                            <p id="monthlyTotalOrders" class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="ki-filled ki-package text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">New Customers</p>
                            <p id="monthlyTotalCustomers" class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="ki-filled ki-people text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="kt-card">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Revenue by Month</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="monthlyRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Orders by Month</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="monthlyOrdersChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Growth Chart -->
        <div class="kt-card mb-8">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Customer Growth by Month</h3>
                <div class="relative" style="height: 300px;">
                    <canvas id="monthlyCustomersChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Analytics Tab -->
    <div id="dailyContent" class="dashboard-content hidden">
        <!-- Month/Year Selector -->
        <div class="flex justify-between items-center mb-6">
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="kt-card">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Month Revenue</p>
                            <p id="dailyTotalRevenue" class="text-2xl font-bold text-gray-900">PKR 0</p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="ki-filled ki-chart-line-up text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Month Orders</p>
                            <p id="dailyTotalOrders" class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="ki-filled ki-package text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">New Customers</p>
                            <p id="dailyTotalCustomers" class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="ki-filled ki-people text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="kt-card">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Revenue by Day</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="dailyRevenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="kt-card">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Orders by Day</h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="dailyOrdersChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer Growth Chart -->
        <div class="kt-card mb-8">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">New Customers by Day</h3>
                <div class="relative" style="height: 300px;">
                    <canvas id="dailyCustomersChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- General Statistics Tab -->
    <div id="statsContent" class="dashboard-content hidden">
        <!-- Customer Statistics -->
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Customer Analytics</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Customers</p>
                                <p id="totalCustomers" class="text-2xl font-bold text-gray-900">0</p>
                            </div>
                            <div class="p-3 bg-blue-100 rounded-full">
                                <i class="ki-filled ki-people text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">30-Day Active</p>
                                <p id="active30Days" class="text-2xl font-bold text-gray-900">0</p>
                            </div>
                            <div class="p-3 bg-green-100 rounded-full">
                                <i class="ki-filled ki-calendar text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">90-Day Active</p>
                                <p id="active90Days" class="text-2xl font-bold text-gray-900">0</p>
                            </div>
                            <div class="p-3 bg-orange-100 rounded-full">
                                <i class="ki-filled ki-timer text-orange-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Repeat Customers</p>
                                <p id="repeatCustomers" class="text-2xl font-bold text-gray-900">0</p>
                                <p id="conversionRate" class="text-xs text-gray-500">0% conversion</p>
                            </div>
                            <div class="p-3 bg-purple-100 rounded-full">
                                <i class="ki-filled ki-heart text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Statistics -->
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-6">Order Analytics</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Orders</p>
                                <p id="totalOrders" class="text-2xl font-bold text-gray-900">0</p>
                            </div>
                            <div class="p-3 bg-blue-100 rounded-full">
                                <i class="ki-filled ki-package text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Completed Orders</p>
                                <p id="completedOrders" class="text-2xl font-bold text-gray-900">0</p>
                                <p id="completionRate" class="text-xs text-gray-500">0% completion</p>
                            </div>
                            <div class="p-3 bg-green-100 rounded-full">
                                <i class="ki-filled ki-check-circle text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Pending Orders</p>
                                <p id="pendingOrders" class="text-2xl font-bold text-gray-900">0</p>
                            </div>
                            <div class="p-3 bg-yellow-100 rounded-full">
                                <i class="ki-filled ki-time text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="kt-card">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Avg Order Value</p>
                                <p id="avgOrderValue" class="text-2xl font-bold text-gray-900">PKR 0</p>
                            </div>
                            <div class="p-3 bg-indigo-100 rounded-full">
                                <i class="ki-filled ki-chart-line-up text-indigo-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue & Product Statistics -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="kt-card">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Revenue Overview</h3>
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
            
            <!-- Geographic Distribution -->
            <div class="kt-card">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Top Cities</h3>
                    <div id="geographicList" class="space-y-3">
                        <!-- Dynamic content -->
                    </div>
                </div>
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
    }

    init() {
        this.setupEventListeners();
        this.setCurrentMonthYear();
        this.loadMonthlyData();
    }

    setupEventListeners() {
        // Tab switching
        document.getElementById('monthlyTab').addEventListener('click', () => this.switchTab('monthly'));
        document.getElementById('dailyTab').addEventListener('click', () => this.switchTab('daily'));
        document.getElementById('statsTab').addEventListener('click', () => this.switchTab('stats'));

        // Monthly controls
        document.getElementById('monthsSelector').addEventListener('change', () => this.loadMonthlyData());

        // Daily controls
        document.getElementById('yearSelector').addEventListener('change', () => this.loadDailyData());
        document.getElementById('monthSelector').addEventListener('change', () => this.loadDailyData());

        // Refresh button
        document.getElementById('refreshBtn').addEventListener('click', () => this.refreshCurrentTab());
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
            case 'stats':
                this.loadGeneralStats();
                break;
        }
    }

    async loadMonthlyData() {
        this.showLoading(true);
        try {
            const months = document.getElementById('monthsSelector').value;
            const response = await fetch(`/dashboard/monthly-analytics?months=${months}`);
            const data = await response.json();
            
            if (data.success) {
                this.updateMonthlyKPIs(data.data);
                this.createMonthlyCharts(data.data);
            }
        } catch (error) {
            console.error('Error loading monthly data:', error);
        } finally {
            this.showLoading(false);
        }
    }

    async loadDailyData() {
        this.showLoading(true);
        try {
            const year = document.getElementById('yearSelector').value;
            const month = document.getElementById('monthSelector').value;
            const response = await fetch(`/dashboard/daily-analytics?year=${year}&month=${month}`);
            const data = await response.json();
            
            if (data.success) {
                this.updateDailyKPIs(data.data);
                this.createDailyCharts(data.data);
            }
        } catch (error) {
            console.error('Error loading daily data:', error);
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

    updateMonthlyKPIs(data) {
        const totalRevenue = data.reduce((sum, item) => sum + item.revenue, 0);
        const totalOrders = data.reduce((sum, item) => sum + item.orders, 0);
        const totalCustomers = data.reduce((sum, item) => sum + item.customers, 0);

        document.getElementById('monthlyTotalRevenue').textContent = `PKR ${this.formatNumber(totalRevenue)}`;
        document.getElementById('monthlyTotalOrders').textContent = this.formatNumber(totalOrders);
        document.getElementById('monthlyTotalCustomers').textContent = this.formatNumber(totalCustomers);
    }

    updateDailyKPIs(data) {
        const totalRevenue = data.data.reduce((sum, item) => sum + item.revenue, 0);
        const totalOrders = data.data.reduce((sum, item) => sum + item.orders, 0);
        const totalCustomers = data.data.reduce((sum, item) => sum + item.customers, 0);

        document.getElementById('dailyTotalRevenue').textContent = `PKR ${this.formatNumber(totalRevenue)}`;
        document.getElementById('dailyTotalOrders').textContent = this.formatNumber(totalOrders);
        document.getElementById('dailyTotalCustomers').textContent = this.formatNumber(totalCustomers);
    }

    updateGeneralStats(data) {
        // Customer stats
        document.getElementById('totalCustomers').textContent = this.formatNumber(data.customers.total);
        document.getElementById('active30Days').textContent = this.formatNumber(data.customers.active_30_days);
        document.getElementById('active90Days').textContent = this.formatNumber(data.customers.active_90_days);
        document.getElementById('repeatCustomers').textContent = this.formatNumber(data.customers.repeat_customers);
        document.getElementById('conversionRate').textContent = `${data.customers.conversion_rate}% conversion`;

        // Order stats
        document.getElementById('totalOrders').textContent = this.formatNumber(data.orders.total);
        document.getElementById('completedOrders').textContent = this.formatNumber(data.orders.completed);
        document.getElementById('pendingOrders').textContent = this.formatNumber(data.orders.pending);
        document.getElementById('completionRate').textContent = `${data.orders.completion_rate}% completion`;

        // Revenue stats
        document.getElementById('totalRevenue').textContent = `PKR ${this.formatNumber(data.revenue.total)}`;
        document.getElementById('avgOrderValue').textContent = `PKR ${this.formatNumber(data.revenue.avg_order_value)}`;
        document.getElementById('avgLifetimeValue').textContent = `PKR ${this.formatNumber(data.customers.avg_lifetime_value)}`;

        // Product stats
        document.getElementById('totalProducts').textContent = this.formatNumber(data.products.total);

        // Geographic distribution
        this.updateGeographicList(data.geographic);
    }

    updateGeographicList(cities) {
        const container = document.getElementById('geographicList');
        container.innerHTML = cities.map(city => `
            <div class="flex justify-between items-center py-2 px-3 bg-gray-50 rounded-lg">
                <span class="text-sm font-medium text-gray-900">${city.city}</span>
                <span class="text-sm text-gray-600">${city.count} customers</span>
            </div>
        `).join('');
    }

    createMonthlyCharts(data) {
        this.destroyChart('monthlyRevenueChart');
        this.destroyChart('monthlyOrdersChart');
        this.destroyChart('monthlyCustomersChart');

        // Revenue Chart
        this.charts.monthlyRevenue = new Chart(document.getElementById('monthlyRevenueChart'), {
            type: 'line',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [{
                    label: 'Revenue (PKR)',
                    data: data.map(item => item.revenue),
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: this.getChartOptions('PKR')
        });

        // Orders Chart
        this.charts.monthlyOrders = new Chart(document.getElementById('monthlyOrdersChart'), {
            type: 'bar',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [{
                    label: 'Orders',
                    data: data.map(item => item.orders),
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 1
                }]
            },
            options: this.getChartOptions()
        });

        // Customers Chart
        this.charts.monthlyCustomers = new Chart(document.getElementById('monthlyCustomersChart'), {
            type: 'line',
            data: {
                labels: data.map(item => item.month_name),
                datasets: [{
                    label: 'New Customers',
                    data: data.map(item => item.customers),
                    borderColor: 'rgb(147, 51, 234)',
                    backgroundColor: 'rgba(147, 51, 234, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: this.getChartOptions()
        });
    }

    createDailyCharts(data) {
        this.destroyChart('dailyRevenueChart');
        this.destroyChart('dailyOrdersChart');
        this.destroyChart('dailyCustomersChart');

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
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 1
                }]
            },
            options: this.getChartOptions()
        });

        // Customers Chart
        this.charts.dailyCustomers = new Chart(document.getElementById('dailyCustomersChart'), {
            type: 'bar',
            data: {
                labels: dailyData.map(item => item.day_name),
                datasets: [{
                    label: 'New Customers',
                    data: dailyData.map(item => item.customers),
                    backgroundColor: 'rgba(147, 51, 234, 0.8)',
                    borderColor: 'rgb(147, 51, 234)',
                    borderWidth: 1
                }]
            },
            options: this.getChartOptions()
        });
    }

    getChartOptions(prefix = '') {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
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

    destroyChart(chartId) {
        const chartKey = chartId.replace('Chart', '').replace('monthly', '').replace('daily', '').toLowerCase();
        Object.keys(this.charts).forEach(key => {
            if (key.toLowerCase().includes(chartKey)) {
                if (this.charts[key]) {
                    this.charts[key].destroy();
                    delete this.charts[key];
                }
            }
        });
    }

    refreshCurrentTab() {
        switch (this.currentTab) {
            case 'monthly':
                this.loadMonthlyData();
                break;
            case 'daily':
                this.loadDailyData();
                break;
            case 'stats':
                this.loadGeneralStats();
                break;
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
        return new Intl.NumberFormat().format(Math.round(number));
    }
}
</script>

<style>
.dashboard-tab.active {
    border-bottom-color: rgb(59 130 246) !important;
    color: rgb(59 130 246) !important;
}
</style>
@endsection
