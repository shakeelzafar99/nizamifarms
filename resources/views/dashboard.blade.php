@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="kt-container-fixed">
    <!-- Header Section -->
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-medium leading-none text-mono">
                Analytics Dashboard
            </h1>
            <div class="flex items-center gap-2 text-sm font-normal text-secondary-foreground">
                Real-time business insights and KPIs
            </div>
        </div>
        
        <!-- Time Range Selector -->
        <div class="flex items-center gap-2.5">
            <select id="timeRangeSelector" class="select select-sm">
                <option value="1" {{ $timeRange == '1' ? 'selected' : '' }}>Today</option>
                <option value="7" {{ $timeRange == '7' ? 'selected' : '' }}>Last 7 Days</option>
                <option value="30" {{ $timeRange == '30' ? 'selected' : '' }}>Last 30 Days</option>
                <option value="90" {{ $timeRange == '90' ? 'selected' : '' }}>Last 90 Days</option>
            </select>
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

    <!-- KPI Cards Section -->
    <div id="kpiSection" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 lg:gap-7.5 mb-7.5">
        <!-- Revenue Card -->
        <div class="kt-card">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-dollar text-blue-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-600">Total Revenue</span>
                    </div>
                    <span id="revenueGrowth" class="text-xs font-medium px-2 py-1 rounded-full">
                        +0%
                    </span>
                </div>
                <div class="flex items-end gap-2">
                    <span id="revenueAmount" class="text-2xl font-bold text-gray-900">
                        PKR 0
                    </span>
                </div>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">Today: </span>
                    <span id="todayRevenue" class="text-xs font-medium text-gray-700">PKR 0</span>
                </div>
            </div>
        </div>

        <!-- Orders Card -->
        <div class="kt-card">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-package text-green-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-600">Total Orders</span>
                    </div>
                    <span id="ordersGrowth" class="text-xs font-medium px-2 py-1 rounded-full">
                        +0%
                    </span>
                </div>
                <div class="flex items-end gap-2">
                    <span id="ordersCount" class="text-2xl font-bold text-gray-900">
                        0
                    </span>
                </div>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">Today: </span>
                    <span id="todayOrders" class="text-xs font-medium text-gray-700">0</span>
                </div>
            </div>
        </div>

        <!-- Customers Card -->
        <div class="kt-card">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-people text-purple-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-600">Total Customers</span>
                    </div>
                    <span id="customersGrowth" class="text-xs font-medium px-2 py-1 rounded-full">
                        +0%
                    </span>
                </div>
                <div class="flex items-end gap-2">
                    <span id="customersCount" class="text-2xl font-bold text-gray-900">
                        0
                    </span>
                </div>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">New: </span>
                    <span id="newCustomers" class="text-xs font-medium text-gray-700">0</span>
                </div>
            </div>
        </div>

        <!-- Average Order Value Card -->
        <div class="kt-card">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="ki-filled ki-chart-line-up text-orange-600 text-lg"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-600">Avg Order Value</span>
                    </div>
                </div>
                <div class="flex items-end gap-2">
                    <span id="avgOrderValue" class="text-2xl font-bold text-gray-900">
                        PKR 0
                    </span>
                </div>
                <div class="mt-2">
                    <span class="text-xs text-gray-500">Per order average</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 lg:gap-7.5 mb-7.5">
        <!-- Revenue Chart -->
        <div class="kt-card">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Revenue Trends</h3>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                        <span class="text-sm text-gray-600">Revenue</span>
                    </div>
                </div>
                <div class="relative" style="height: 300px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Customer Growth Chart -->
        <div class="kt-card">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900">Customer Growth</h3>
                    <div class="flex items-center gap-2">
                        <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                        <span class="text-sm text-gray-600">New Customers</span>
                    </div>
                </div>
                <div class="relative" style="height: 300px;">
                    <canvas id="customerGrowthChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary KPIs and Analytics -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 lg:gap-7.5 mb-7.5">
        <!-- Customer Analytics -->
        <div class="kt-card">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Customer Analytics</h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">90-Day Active</span>
                        <span id="active90Days" class="font-semibold text-gray-900">0</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">30-Day Active</span>
                        <span id="active30Days" class="font-semibold text-gray-900">0</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">7-Day Active</span>
                        <span id="active7Days" class="font-semibold text-gray-900">0</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Retention Rate</span>
                        <span id="retentionRate" class="font-semibold text-green-600">0%</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Repeat Customers</span>
                        <span id="repeatCustomers" class="font-semibold text-gray-900">0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Status Distribution -->
        <div class="kt-card">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Order Status</h3>
                <div class="relative" style="height: 200px;">
                    <canvas id="orderStatusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Source Distribution -->
        <div class="kt-card">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Order Sources</h3>
                <div class="relative" style="height: 200px;">
                    <canvas id="sourceDistributionChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Performers Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 lg:gap-7.5 mb-7.5">
        <!-- Top Customers -->
        <div class="kt-card">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Top Customers</h3>
                <div id="topCustomersList" class="space-y-3">
                    <!-- Dynamic content -->
                </div>
            </div>
        </div>

        <!-- Top Products -->
        <div class="kt-card">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Top Products by Revenue</h3>
                <div id="topProductsList" class="space-y-3">
                    <!-- Dynamic content -->
                </div>
            </div>
        </div>
    </div>

    <!-- Geographic Distribution -->
    <div class="kt-card mb-7.5">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Customer Geographic Distribution</h3>
            <div id="geographicDistribution" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <!-- Dynamic content -->
            </div>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Dashboard JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Initialize dashboard
    const dashboard = new Dashboard();
    dashboard.init();
});

class Dashboard {
    constructor() {
        this.timeRange = '{{ $timeRange }}';
        this.charts = {};
        this.kpis = @json($kpis);
    }

    init() {
        this.setupEventListeners();
        this.updateKPIs(this.kpis);
        this.initializeCharts();
        this.loadChartData();
    }

    setupEventListeners() {
        // Time range selector
        document.getElementById('timeRangeSelector').addEventListener('change', (e) => {
            this.timeRange = e.target.value;
            this.loadDashboardData();
        });

        // Refresh button
        document.getElementById('refreshBtn').addEventListener('click', () => {
            this.loadDashboardData();
        });
    }

    async loadDashboardData() {
        this.showLoading(true);
        
        try {
            // Load KPIs
            const kpisResponse = await fetch(`/dashboard/kpis?range=${this.timeRange}`);
            const kpisData = await kpisResponse.json();
            
            if (kpisData.success) {
                this.updateKPIs(kpisData.data);
                await this.loadChartData();
            }
        } catch (error) {
            console.error('Error loading dashboard data:', error);
        } finally {
            this.showLoading(false);
        }
    }

    updateKPIs(data) {
        // Revenue KPIs
        document.getElementById('revenueAmount').textContent = `PKR ${this.formatNumber(data.revenue.current)}`;
        document.getElementById('todayRevenue').textContent = `PKR ${this.formatNumber(data.revenue.today)}`;
        this.updateGrowthBadge('revenueGrowth', data.revenue.growth);

        // Orders KPIs
        document.getElementById('ordersCount').textContent = this.formatNumber(data.orders.current);
        document.getElementById('todayOrders').textContent = data.orders.today;
        this.updateGrowthBadge('ordersGrowth', data.orders.growth);

        // Customers KPIs
        document.getElementById('customersCount').textContent = this.formatNumber(data.customers.total);
        document.getElementById('newCustomers').textContent = data.customers.new_customers;
        this.updateGrowthBadge('customersGrowth', data.customers.new_customer_growth);

        // Average Order Value
        document.getElementById('avgOrderValue').textContent = `PKR ${this.formatNumber(data.revenue.avg_order_value)}`;

        // Customer Analytics
        document.getElementById('active90Days').textContent = this.formatNumber(data.customers.active_90_days);
        document.getElementById('active30Days').textContent = this.formatNumber(data.customers.active_30_days);
        document.getElementById('active7Days').textContent = this.formatNumber(data.customers.active_7_days);
        document.getElementById('retentionRate').textContent = `${data.customers.retention_rate}%`;
        document.getElementById('repeatCustomers').textContent = this.formatNumber(data.customers.repeat_customers);

        // Update lists
        this.updateTopCustomers(data.customers.top_customers);
        this.updateTopProducts(data.products.top_by_revenue);
        this.updateGeographicDistribution(data.customers.geographic_distribution);

        // Update donut charts
        this.updateOrderStatusChart(data.orders.status_distribution);
        this.updateSourceDistributionChart(data.orders.source_distribution);
    }

    updateGrowthBadge(elementId, growth) {
        const element = document.getElementById(elementId);
        const isPositive = growth >= 0;
        const sign = isPositive ? '+' : '';
        
        element.textContent = `${sign}${growth}%`;
        element.className = `text-xs font-medium px-2 py-1 rounded-full ${
            isPositive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
        }`;
    }

    async loadChartData() {
        try {
            // Load revenue chart
            const revenueResponse = await fetch(`/dashboard/revenue-chart?range=${this.timeRange}`);
            const revenueData = await revenueResponse.json();
            if (revenueData.success) {
                this.updateRevenueChart(revenueData.data);
            }

            // Load customer growth chart
            const customerResponse = await fetch(`/dashboard/customer-growth-chart?range=${this.timeRange}`);
            const customerData = await customerResponse.json();
            if (customerData.success) {
                this.updateCustomerGrowthChart(customerData.data);
            }
        } catch (error) {
            console.error('Error loading chart data:', error);
        }
    }

    initializeCharts() {
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        this.charts.revenue = new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Revenue',
                    data: [],
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
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
                            callback: (value) => `PKR ${this.formatNumber(value)}`
                        }
                    }
                }
            }
        });

        // Customer Growth Chart
        const customerCtx = document.getElementById('customerGrowthChart').getContext('2d');
        this.charts.customerGrowth = new Chart(customerCtx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'New Customers',
                    data: [],
                    backgroundColor: '#059669',
                    borderRadius: 4
                }]
            },
            options: {
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
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Order Status Chart
        const statusCtx = document.getElementById('orderStatusChart').getContext('2d');
        this.charts.orderStatus = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: ['#2563eb', '#059669', '#d97706', '#dc2626', '#6b7280']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Source Distribution Chart
        const sourceCtx = document.getElementById('sourceDistributionChart').getContext('2d');
        this.charts.sourceDistribution = new Chart(sourceCtx, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: ['#7c3aed', '#2563eb', '#059669']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });
    }

    updateRevenueChart(data) {
        this.charts.revenue.data.labels = data.labels;
        this.charts.revenue.data.datasets[0].data = data.revenue;
        this.charts.revenue.update();
    }

    updateCustomerGrowthChart(data) {
        this.charts.customerGrowth.data.labels = data.labels;
        this.charts.customerGrowth.data.datasets[0].data = data.new_customers;
        this.charts.customerGrowth.update();
    }

    updateOrderStatusChart(data) {
        const labels = Object.keys(data).map(key => key.charAt(0).toUpperCase() + key.slice(1));
        const values = Object.values(data);
        
        this.charts.orderStatus.data.labels = labels;
        this.charts.orderStatus.data.datasets[0].data = values;
        this.charts.orderStatus.update();
    }

    updateSourceDistributionChart(data) {
        const sourceLabels = {
            'woocommerce': 'WooCommerce',
            'webapp': 'Manual Orders',
            null: 'Direct'
        };
        
        const labels = Object.keys(data).map(key => sourceLabels[key] || key);
        const values = Object.values(data);
        
        this.charts.sourceDistribution.data.labels = labels;
        this.charts.sourceDistribution.data.datasets[0].data = values;
        this.charts.sourceDistribution.update();
    }

    updateTopCustomers(customers) {
        const container = document.getElementById('topCustomersList');
        if (!customers || customers.length === 0) {
            container.innerHTML = '<p class="text-gray-500 text-sm">No customer data available</p>';
            return;
        }

        container.innerHTML = customers.map(customer => `
            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                <div>
                    <p class="font-medium text-gray-900">${customer.name}</p>
                    <p class="text-sm text-gray-500">${customer.total_orders} orders</p>
                </div>
                <span class="font-semibold text-green-600">PKR ${this.formatNumber(customer.total_spent)}</span>
            </div>
        `).join('');
    }

    updateTopProducts(products) {
        const container = document.getElementById('topProductsList');
        if (!products || products.length === 0) {
            container.innerHTML = '<p class="text-gray-500 text-sm">No product data available</p>';
            return;
        }

        container.innerHTML = products.map(product => `
            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                <div>
                    <p class="font-medium text-gray-900">${product.name}</p>
                    <p class="text-sm text-gray-500">${product.quantity} sold</p>
                </div>
                <span class="font-semibold text-blue-600">PKR ${this.formatNumber(product.revenue)}</span>
            </div>
        `).join('');
    }

    updateGeographicDistribution(cities) {
        const container = document.getElementById('geographicDistribution');
        if (!cities || Object.keys(cities).length === 0) {
            container.innerHTML = '<p class="text-gray-500 text-sm col-span-full">No geographic data available</p>';
            return;
        }

        container.innerHTML = Object.entries(cities).map(([city, count]) => `
            <div class="text-center p-3 bg-gray-50 rounded-lg">
                <p class="font-semibold text-gray-900">${count}</p>
                <p class="text-sm text-gray-600">${city}</p>
            </div>
        `).join('');
    }

    showLoading(show) {
        const loading = document.getElementById('dashboardLoading');
        const content = document.getElementById('kpiSection');
        
        if (show) {
            loading.classList.remove('hidden');
            content.style.opacity = '0.5';
        } else {
            loading.classList.add('hidden');
            content.style.opacity = '1';
        }
    }

    formatNumber(num) {
        if (!num) return '0';
        return new Intl.NumberFormat('en-US').format(num);
    }
}
</script>
@endsection
