@extends('layouts.app')

@section('title', 'Error Logs Viewer')

@section('content')
<div class="kt-container-fixed">
    <!-- Header Section -->
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-medium leading-none text-mono">
                Error Logs Viewer
            </h1>
            <div class="flex items-center gap-2 text-sm font-normal text-secondary-foreground">
                Monitor and analyze application errors and API issues
            </div>
        </div>
        
        <!-- Controls -->
        <div class="flex items-center gap-2.5">
            <button id="exportBtn" class="kt-btn kt-btn-outline kt-btn-sm">
                <i class="ki-filled ki-file-down text-base"></i>
                Export
            </button>
            <button id="refreshBtn" class="kt-btn kt-btn-outline kt-btn-sm">
                <i class="ki-filled ki-arrows-circle text-base"></i>
                Refresh
            </button>
        </div>
    </div>

    <!-- Log File Info -->
    <div class="kt-card mb-7.5">
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-blue-100 rounded-full">
                        <i class="ki-filled ki-file-sheet text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Log File Size</p>
                        <p id="logFileSize" class="text-lg font-bold text-gray-900">{{ $logInfo['size_formatted'] ?? '0 B' }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-orange-100 rounded-full">
                        <i class="ki-filled ki-time text-orange-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Last Modified</p>
                        <p id="logLastModified" class="text-lg font-bold text-gray-900">
                            {{ $logInfo['last_modified_formatted'] ?? 'N/A' }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-red-100 rounded-full">
                        <i class="ki-filled ki-danger text-red-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Errors</p>
                        <p id="totalErrors" class="text-lg font-bold text-gray-900">0</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-purple-100 rounded-full">
                        <i class="ki-filled ki-cloud text-purple-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">API Errors</p>
                        <p id="apiErrors" class="text-lg font-bold text-gray-900">0</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="kt-card mb-7.5">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Filters</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                    <input type="date" id="dateFrom" class="form-input w-full">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                    <input type="date" id="dateTo" class="form-input w-full">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Error Level</label>
                    <select id="errorLevel" class="form-select w-full">
                        <option value="">All Levels</option>
                        <option value="ERROR">Error</option>
                        <option value="CRITICAL">Critical</option>
                        <option value="ALERT">Alert</option>
                        <option value="EMERGENCY">Emergency</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select id="errorCategory" class="form-select w-full">
                        <option value="">All Categories</option>
                        <option value="Shopify API">Shopify API</option>
                        <option value="WooCommerce API">WooCommerce API</option>
                        <option value="API/HTTP">API/HTTP</option>
                        <option value="Database">Database</option>
                        <option value="Routing">Routing</option>
                        <option value="Assets">Assets</option>
                        <option value="View/Template">View/Template</option>
                        <option value="Authentication">Authentication</option>
                        <option value="General">General</option>
                    </select>
                </div>
            </div>
            <div class="flex items-center gap-4 mt-4">
                <label class="flex items-center">
                    <input type="checkbox" id="apiOnlyFilter" class="form-checkbox">
                    <span class="ml-2 text-sm text-gray-700">API-related errors only</span>
                </label>
                <div class="flex-1">
                    <input type="text" id="searchFilter" placeholder="Search in error messages..." 
                           class="form-input w-full">
                </div>
                <button id="applyFiltersBtn" class="kt-btn kt-btn-primary">
                    <i class="ki-filled ki-filter text-base"></i>
                    Apply Filters
                </button>
                <button id="clearFiltersBtn" class="kt-btn kt-btn-outline">
                    Clear
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-7.5">
        <!-- Error Categories -->
        <div class="kt-card">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Errors by Category</h3>
                <div id="categoriesChart" style="height: 200px;">
                    <canvas id="categoriesChartCanvas"></canvas>
                </div>
            </div>
        </div>

        <!-- Errors by Date (Last 7 Days) -->
        <div class="kt-card">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Errors by Date (Last 7 Days)</h3>
                <div id="dateChart" style="height: 200px;">
                    <canvas id="dateChartCanvas"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Indicator -->
    <div id="logsLoading" class="hidden">
        <div class="flex items-center justify-center py-20">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            <span class="ml-3 text-gray-600">Loading logs...</span>
        </div>
    </div>

    <!-- Error Logs Table -->
    <div id="logsContainer" class="kt-card">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Error Logs</h3>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-600">Show:</span>
                    <select id="perPageSelect" class="form-select text-sm">
                        <option value="25">25</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="text-sm text-gray-600">per page</span>
                </div>
            </div>
            
            <!-- Logs Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Date & Time
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Level
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Category
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Message
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody" class="bg-white divide-y divide-gray-200">
                        <!-- Dynamic content -->
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="paginationContainer" class="flex items-center justify-between mt-6">
                <div class="text-sm text-gray-700">
                    Showing <span id="showingFrom">0</span> to <span id="showingTo">0</span> of <span id="totalLogs">0</span> results
                </div>
                <div class="flex items-center space-x-2">
                    <button id="prevPageBtn" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                        Previous
                    </button>
                    <div id="pageNumbers" class="flex space-x-1">
                        <!-- Dynamic page numbers -->
                    </div>
                    <button id="nextPageBtn" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Detail Modal -->
<div id="logDetailModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900">Error Details</h3>
                <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600">
                    <i class="ki-filled ki-cross text-xl"></i>
                </button>
            </div>
            <div id="logDetailContent" class="space-y-4">
                <!-- Dynamic content -->
            </div>
        </div>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Log Viewer JavaScript
document.addEventListener('DOMContentLoaded', function() {
    const logViewer = new LogViewer();
    logViewer.init();
});

class LogViewer {
    constructor() {
        this.currentPage = 1;
        this.perPage = 50;
        this.filters = {};
        this.charts = {};
    }

    init() {
        this.setupEventListeners();
        this.setDefaultDates();
        this.loadLogs();
    }

    setupEventListeners() {
        // Filter controls
        document.getElementById('applyFiltersBtn').addEventListener('click', () => this.applyFilters());
        document.getElementById('clearFiltersBtn').addEventListener('click', () => this.clearFilters());
        document.getElementById('refreshBtn').addEventListener('click', () => this.refreshLogs());
        document.getElementById('exportBtn').addEventListener('click', () => this.exportLogs());

        // Pagination controls
        document.getElementById('prevPageBtn').addEventListener('click', () => this.previousPage());
        document.getElementById('nextPageBtn').addEventListener('click', () => this.nextPage());
        document.getElementById('perPageSelect').addEventListener('change', (e) => {
            this.perPage = parseInt(e.target.value);
            this.currentPage = 1;
            this.loadLogs();
        });

        // Modal controls
        document.getElementById('closeModalBtn').addEventListener('click', () => this.closeModal());

        // Search on Enter
        document.getElementById('searchFilter').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.applyFilters();
            }
        });
    }

    setDefaultDates() {
        const today = new Date();
        const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
        
        document.getElementById('dateTo').value = today.toISOString().split('T')[0];
        document.getElementById('dateFrom').value = weekAgo.toISOString().split('T')[0];
    }

    applyFilters() {
        this.filters = {
            date_from: document.getElementById('dateFrom').value,
            date_to: document.getElementById('dateTo').value,
            level: document.getElementById('errorLevel').value,
            category: document.getElementById('errorCategory').value,
            api_only: document.getElementById('apiOnlyFilter').checked ? 'true' : 'false',
            search: document.getElementById('searchFilter').value
        };
        
        this.currentPage = 1;
        this.loadLogs();
    }

    clearFilters() {
        document.getElementById('dateFrom').value = '';
        document.getElementById('dateTo').value = '';
        document.getElementById('errorLevel').value = '';
        document.getElementById('errorCategory').value = '';
        document.getElementById('apiOnlyFilter').checked = false;
        document.getElementById('searchFilter').value = '';
        
        this.filters = {};
        this.currentPage = 1;
        this.setDefaultDates();
        this.loadLogs();
    }

    async loadLogs() {
        this.showLoading(true);
        
        try {
            const params = new URLSearchParams({
                ...this.filters,
                page: this.currentPage,
                per_page: this.perPage
            });

            const response = await fetch(`/logs/data?${params}`);
            const data = await response.json();
            
            if (data.success) {
                this.updateLogsTable(data.data);
                this.updateSummary(data.data.summary);
                this.updateCharts(data.data.summary);
            }
        } catch (error) {
            console.error('Error loading logs:', error);
        } finally {
            this.showLoading(false);
        }
    }

    updateLogsTable(data) {
        const tbody = document.getElementById('logsTableBody');
        tbody.innerHTML = '';

        if (data.logs.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                        No error logs found for the selected criteria.
                    </td>
                </tr>
            `;
            return;
        }

        data.logs.forEach(log => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-gray-50';
            
            const levelColor = this.getLevelColor(log.level);
            const categoryBadge = this.getCategoryBadge(log.category);
            
            row.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    <div>${log.date}</div>
                    <div class="text-xs text-gray-500">${log.time}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-medium rounded-full ${levelColor}">
                        ${log.level}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    ${categoryBadge}
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">
                    <div class="max-w-xs truncate">${this.escapeHtml(log.message)}</div>
                    ${log.is_api_related ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 mt-1"><i class="ki-filled ki-cloud text-xs mr-1"></i>API</span>' : ''}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="logViewer.showLogDetail(${data.logs.indexOf(log)})" 
                            class="text-blue-600 hover:text-blue-900">
                        <i class="ki-filled ki-eye text-base"></i>
                    </button>
                </td>
            `;
            
            tbody.appendChild(row);
        });

        // Store logs data for modal
        this.currentLogs = data.logs;

        // Update pagination
        this.updatePagination(data);
    }

    updateSummary(summary) {
        document.getElementById('totalErrors').textContent = this.formatNumber(summary.total_errors);
        document.getElementById('apiErrors').textContent = this.formatNumber(summary.api_errors);
    }

    updateCharts(summary) {
        this.createCategoriesChart(summary.by_category);
        this.createDateChart(summary.by_date);
    }

    createCategoriesChart(categoryData) {
        const ctx = document.getElementById('categoriesChartCanvas');
        
        if (this.charts.categories) {
            this.charts.categories.destroy();
        }

        const labels = Object.keys(categoryData);
        const data = Object.values(categoryData);
        
        if (labels.length === 0) {
            ctx.getContext('2d').clearRect(0, 0, ctx.width, ctx.height);
            return;
        }

        this.charts.categories = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: [
                        '#ef4444', '#f97316', '#eab308', '#22c55e', 
                        '#3b82f6', '#8b5cf6', '#ec4899', '#6b7280', '#14b8a6'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    createDateChart(dateData) {
        const ctx = document.getElementById('dateChartCanvas');
        
        if (this.charts.date) {
            this.charts.date.destroy();
        }

        if (!dateData || dateData.length === 0) {
            ctx.getContext('2d').clearRect(0, 0, ctx.width, ctx.height);
            return;
        }

        this.charts.date = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: dateData.map(item => item.formatted_date),
                datasets: [{
                    label: 'Errors',
                    data: dateData.map(item => item.count),
                    backgroundColor: 'rgba(239, 68, 68, 0.8)',
                    borderColor: 'rgb(239, 68, 68)',
                    borderWidth: 1
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
    }

    updatePagination(data) {
        document.getElementById('showingFrom').textContent = ((data.current_page - 1) * data.per_page) + 1;
        document.getElementById('showingTo').textContent = Math.min(data.current_page * data.per_page, data.total);
        document.getElementById('totalLogs').textContent = data.total;

        // Update pagination buttons
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');
        
        prevBtn.disabled = data.current_page <= 1;
        nextBtn.disabled = data.current_page >= data.total_pages;

        // Update page numbers
        this.updatePageNumbers(data.current_page, data.total_pages);
    }

    updatePageNumbers(currentPage, totalPages) {
        const container = document.getElementById('pageNumbers');
        container.innerHTML = '';

        // Show max 5 page numbers
        let start = Math.max(1, currentPage - 2);
        let end = Math.min(totalPages, start + 4);
        
        if (end - start < 4) {
            start = Math.max(1, end - 4);
        }

        for (let i = start; i <= end; i++) {
            const button = document.createElement('button');
            button.textContent = i;
            button.className = `px-3 py-1 border rounded-md text-sm font-medium ${
                i === currentPage 
                    ? 'bg-blue-500 text-white border-blue-500' 
                    : 'border-gray-300 text-gray-500 hover:bg-gray-50'
            }`;
            button.addEventListener('click', () => this.goToPage(i));
            container.appendChild(button);
        }
    }

    goToPage(page) {
        this.currentPage = page;
        this.loadLogs();
    }

    previousPage() {
        if (this.currentPage > 1) {
            this.currentPage--;
            this.loadLogs();
        }
    }

    nextPage() {
        this.currentPage++;
        this.loadLogs();
    }

    showLogDetail(index) {
        const log = this.currentLogs[index];
        const modal = document.getElementById('logDetailModal');
        const content = document.getElementById('logDetailContent');
        
        content.innerHTML = `
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Date & Time</label>
                    <p class="text-sm text-gray-900">${log.date} ${log.time}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Level</label>
                    <span class="px-2 py-1 text-xs font-medium rounded-full ${this.getLevelColor(log.level)}">
                        ${log.level}
                    </span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Category</label>
                    ${this.getCategoryBadge(log.category)}
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">API Related</label>
                    <span class="text-sm ${log.is_api_related ? 'text-green-600' : 'text-gray-500'}">
                        ${log.is_api_related ? 'Yes' : 'No'}
                    </span>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Error Message</label>
                <div class="bg-gray-50 p-3 rounded-md text-sm text-gray-900 whitespace-pre-wrap">${this.escapeHtml(log.message)}</div>
            </div>
            ${log.stacktrace && log.stacktrace.length > 0 ? `
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Stack Trace</label>
                    <div class="bg-red-50 p-3 rounded-md text-xs text-red-800 font-mono max-h-60 overflow-y-auto">
                        ${log.stacktrace.map(line => this.escapeHtml(line)).join('\n')}
                    </div>
                </div>
            ` : ''}
        `;
        
        modal.classList.remove('hidden');
    }

    closeModal() {
        document.getElementById('logDetailModal').classList.add('hidden');
    }

    async exportLogs() {
        try {
            const params = new URLSearchParams(this.filters);
            const response = await fetch(`/logs/export?${params}`);
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `error_logs_${new Date().toISOString().split('T')[0]}.json`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            }
        } catch (error) {
            console.error('Error exporting logs:', error);
        }
    }

    refreshLogs() {
        this.loadLogs();
    }

    showLoading(show) {
        const loading = document.getElementById('logsLoading');
        const container = document.getElementById('logsContainer');
        
        if (show) {
            loading.classList.remove('hidden');
            container.classList.add('hidden');
        } else {
            loading.classList.add('hidden');
            container.classList.remove('hidden');
        }
    }

    getLevelColor(level) {
        const colors = {
            'ERROR': 'bg-red-100 text-red-800',
            'CRITICAL': 'bg-red-200 text-red-900',
            'ALERT': 'bg-orange-100 text-orange-800',
            'EMERGENCY': 'bg-red-300 text-red-900'
        };
        return colors[level] || 'bg-gray-100 text-gray-800';
    }

    getCategoryBadge(category) {
        const colors = {
            'Shopify API': 'bg-green-100 text-green-800',
            'WooCommerce API': 'bg-blue-100 text-blue-800',
            'API/HTTP': 'bg-purple-100 text-purple-800',
            'Database': 'bg-yellow-100 text-yellow-800',
            'Routing': 'bg-indigo-100 text-indigo-800',
            'Assets': 'bg-gray-100 text-gray-800',
            'View/Template': 'bg-pink-100 text-pink-800',
            'Authentication': 'bg-orange-100 text-orange-800',
            'General': 'bg-gray-100 text-gray-800'
        };
        
        const colorClass = colors[category] || 'bg-gray-100 text-gray-800';
        return `<span class="px-2 py-1 text-xs font-medium rounded-full ${colorClass}">${category}</span>`;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    formatNumber(number) {
        return new Intl.NumberFormat().format(number);
    }
}

// Make logViewer globally accessible for onclick handlers
let logViewer;
document.addEventListener('DOMContentLoaded', function() {
    logViewer = new LogViewer();
    logViewer.init();
});
</script>

<style>
.form-input, .form-select {
    @apply block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm;
}

.form-checkbox {
    @apply h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded;
}

#logDetailModal {
    backdrop-filter: blur(4px);
}

.max-w-xs {
    max-width: 20rem;
}
</style>
@endsection
