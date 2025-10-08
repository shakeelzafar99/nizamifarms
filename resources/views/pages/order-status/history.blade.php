@extends('layouts.app')

@section('title', 'Order Status History')

@push('custom_css')
<style>
.history-container {
    background: #f8fafc;
    min-height: 100vh;
}

.history-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.history-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: translateY(-1px);
}

.search-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}

.search-input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.order-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 12px;
    background: white;
    transition: all 0.2s ease;
    cursor: pointer;
}

.order-item:hover {
    border-color: #2563eb;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.1);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid;
}

.status-badge.yellow { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.status-badge.orange { background: #fed7aa; color: #c2410c; border-color: #fb923c; }
.status-badge.blue { background: #dbeafe; color: #1d4ed8; border-color: #60a5fa; }
.status-badge.purple { background: #e9d5ff; color: #7c3aed; border-color: #a78bfa; }
.status-badge.green { background: #d1fae5; color: #065f46; border-color: #34d399; }
.status-badge.red { background: #fee2e2; color: #dc2626; border-color: #f87171; }
.status-badge.gray { background: #f3f4f6; color: #374151; border-color: #d1d5db; }

.loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    color: #6b7280;
}

.spinner {
    width: 20px;
    height: 20px;
    border: 2px solid #e5e7eb;
    border-top: 2px solid #2563eb;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-right: 12px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.filter-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
}

.filter-tab {
    padding: 8px 16px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background: white;
    color: #374151;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 14px;
    font-weight: 500;
}

.filter-tab.active {
    background: #2563eb;
    color: white;
    border-color: #2563eb;
}

.filter-tab:hover:not(.active) {
    background: #f3f4f6;
    border-color: #9ca3af;
}
</style>
@endpush

@section('content')
<div class="history-container">
    <div class="container-fixed">
        <!-- Header Section -->
        <div class="flex flex-wrap items-center lg:items-end justify-between gap-6 py-6">
            <div class="flex flex-col justify-center gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="ki-filled ki-time text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold leading-tight text-gray-900">Order Status History</h1>
                        <div class="flex items-center gap-2 text-sm font-medium text-gray-600 mt-1">
                            <i class="ki-filled ki-information-2 text-indigo-500"></i>
                            Track status changes for all orders
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <a href="/order-status" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i class="ki-filled ki-setting-2 mr-2"></i>
                    Manage Statuses
                </a>
                <button onclick="refreshOrders()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i class="ki-filled ki-arrows-circle mr-2"></i>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="history-card p-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search Orders</label>
                    <input type="text" id="searchInput" placeholder="Search by order number, customer name..." 
                           class="search-input" onkeyup="filterOrders()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Status</label>
                    <select id="statusFilter" class="search-input" onchange="filterOrders()">
                        <option value="">All Statuses</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="setTimeFilter('all')">All Time</button>
                <button class="filter-tab" onclick="setTimeFilter('today')">Today</button>
                <button class="filter-tab" onclick="setTimeFilter('week')">This Week</button>
                <button class="filter-tab" onclick="setTimeFilter('month')">This Month</button>
            </div>
        </div>

        <!-- Orders List -->
        <div class="history-card p-6">
            <div id="ordersContainer">
                <div class="loading">
                    <div class="spinner"></div>
                    Loading orders...
                </div>
            </div>
            
            <!-- Pagination -->
            <div id="paginationContainer" class="mt-6" style="display: none;">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Showing <span id="showingFrom">0</span> to <span id="showingTo">0</span> of <span id="totalOrders">0</span> orders
                    </div>
                    <div class="flex gap-2">
                        <button id="prevBtn" onclick="changePage(-1)" class="px-3 py-1 border border-gray-300 rounded text-sm" disabled>Previous</button>
                        <button id="nextBtn" onclick="changePage(1)" class="px-3 py-1 border border-gray-300 rounded text-sm" disabled>Next</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('page_js')
<script>
let allOrders = [];
let filteredOrders = [];
let currentPage = 1;
let ordersPerPage = 20;
let currentTimeFilter = 'all';

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    loadOrders();
    loadStatusFilter();
});

// Load orders from API
async function loadOrders() {
    try {
        const response = await fetch('/orders/filter?per_page=1000&source=other', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        }); // Get non-Shopify orders for history
        const data = await response.json();
        
        if (data.success && data.orders) {
            allOrders = data.orders.data || data.orders;
            // Filter out orders without proper status (these need history records)
            allOrders = allOrders.filter(order => order.order_status && order.order_status !== '');
            
            // Fetch current status change date for each order from status history
            for (let order of allOrders) {
                if (order.current_status_history && order.current_status_history.changed_at) {
                    order.status_changed_at = order.current_status_history.changed_at;
                } else {
                    // Fallback to order_date if status history not available
                    order.status_changed_at = order.order_date || order.created_at;
                }
            }
            
            filteredOrders = [...allOrders];
            renderOrders();
        } else {
            showError('Failed to load orders. Please ensure status history records exist for your orders.');
        }
    } catch (error) {
        console.error('Error loading orders:', error);
        showError('Error loading orders. Please check if status history has been populated.');
    }
}

// Load available statuses for filter
async function loadStatusFilter() {
    try {
        const response = await fetch('/order-status/api/statuses', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        });
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('statusFilter');
            select.innerHTML = '<option value="">All Statuses</option>';
            
            data.data.forEach(status => {
                const option = document.createElement('option');
                option.value = status.status_code;
                option.textContent = `${status.icon} ${status.status_name}`;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading statuses:', error);
    }
}

// Filter orders based on search and filters
function filterOrders() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    
    filteredOrders = allOrders.filter(order => {
        // Search filter
        const matchesSearch = !searchTerm || 
            (order.order_number && order.order_number.toLowerCase().includes(searchTerm)) ||
            (order.customer_name && order.customer_name.toLowerCase().includes(searchTerm)) ||
            (order.contact_email && order.contact_email.toLowerCase().includes(searchTerm));
        
        // Status filter
        const matchesStatus = !statusFilter || order.order_status === statusFilter;
        
        // Time filter
        const matchesTime = filterByTime(order);
        
        return matchesSearch && matchesStatus && matchesTime;
    });
    
    currentPage = 1;
    renderOrders();
}

// Filter by time period
function filterByTime(order) {
    if (currentTimeFilter === 'all') return true;
    
    const orderDate = new Date(order.order_date || order.created_at);
    const now = new Date();
    
    switch (currentTimeFilter) {
        case 'today':
            return orderDate.toDateString() === now.toDateString();
        case 'week':
            const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
            return orderDate >= weekAgo;
        case 'month':
            const monthAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
            return orderDate >= monthAgo;
        default:
            return true;
    }
}

// Set time filter
function setTimeFilter(filter) {
    currentTimeFilter = filter;
    
    // Update active tab
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    event.target.classList.add('active');
    
    filterOrders();
}

// Render orders list
function renderOrders() {
    const container = document.getElementById('ordersContainer');
    
    if (filteredOrders.length === 0) {
        container.innerHTML = `
            <div class="text-center py-12">
                <div class="text-gray-400 mb-4">
                    <i class="ki-filled ki-file-deleted text-4xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Orders Found</h3>
                <p class="text-gray-500">Try adjusting your search or filter criteria.</p>
            </div>
        `;
        document.getElementById('paginationContainer').style.display = 'none';
        return;
    }
    
    // Calculate pagination
    const startIndex = (currentPage - 1) * ordersPerPage;
    const endIndex = Math.min(startIndex + ordersPerPage, filteredOrders.length);
    const pageOrders = filteredOrders.slice(startIndex, endIndex);
    
    // Render orders
    container.innerHTML = pageOrders.map(order => `
        <div class="order-item" onclick="viewOrderHistory(${order.id})">
            <div class="flex-1">
                <div class="flex items-center gap-4 mb-2">
                    <h3 class="font-semibold text-gray-900">#${order.order_number || order.id}</h3>
                    <span class="status-badge ${getStatusColor(order.order_status)}">
                        ${getStatusIcon(order.order_status)} ${order.order_status || 'Unknown'}
                    </span>
                </div>
                <div class="flex items-center gap-6 text-sm text-gray-600">
                    <span><i class="ki-filled ki-user mr-1"></i>${order.customer_name || 'Unknown Customer'}</span>
                    <span><i class="ki-filled ki-calendar mr-1"></i>${formatDate(order.status_changed_at || order.order_date || order.created_at)}</span>
                    <span><i class="ki-filled ki-dollar mr-1"></i>$${parseFloat(order.total_price || 0).toFixed(2)}</span>
                </div>
            </div>
            <div class="text-right">
                <button class="inline-flex items-center px-3 py-1 bg-indigo-100 text-indigo-700 rounded-md text-sm font-medium hover:bg-indigo-200 transition-colors">
                    <i class="ki-filled ki-eye mr-1"></i>
                    View History
                </button>
            </div>
        </div>
    `).join('');
    
    // Update pagination
    updatePagination();
}

// Update pagination controls
function updatePagination() {
    const container = document.getElementById('paginationContainer');
    const totalPages = Math.ceil(filteredOrders.length / ordersPerPage);
    
    if (totalPages <= 1) {
        container.style.display = 'none';
        return;
    }
    
    container.style.display = 'block';
    
    const startIndex = (currentPage - 1) * ordersPerPage;
    const endIndex = Math.min(startIndex + ordersPerPage, filteredOrders.length);
    
    document.getElementById('showingFrom').textContent = startIndex + 1;
    document.getElementById('showingTo').textContent = endIndex;
    document.getElementById('totalOrders').textContent = filteredOrders.length;
    
    document.getElementById('prevBtn').disabled = currentPage === 1;
    document.getElementById('nextBtn').disabled = currentPage === totalPages;
}

// Change page
function changePage(direction) {
    const totalPages = Math.ceil(filteredOrders.length / ordersPerPage);
    const newPage = currentPage + direction;
    
    if (newPage >= 1 && newPage <= totalPages) {
        currentPage = newPage;
        renderOrders();
    }
}

// View order history
function viewOrderHistory(orderId) {
    window.location.href = `/order-status/history/${orderId}`;
}

// Refresh orders
function refreshOrders() {
    document.getElementById('ordersContainer').innerHTML = `
        <div class="loading">
            <div class="spinner"></div>
            Loading orders...
        </div>
    `;
    loadOrders();
}

// Utility functions
function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    try {
        return new Date(dateStr).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return dateStr;
    }
}

function getStatusColor(status) {
    const colorMap = {
        'new': 'yellow',
        'on_hold': 'orange',
        'processing': 'blue',
        'out_for_delivery': 'purple',
        'delivered': 'green',
        'cancelled': 'red',
        'refunded': 'purple'
    };
    return colorMap[status] || 'gray';
}

function getStatusIcon(status) {
    const iconMap = {
        'new': '⏳',
        'on_hold': '⏸',
        'processing': '⚡',
        'out_for_delivery': '🚚',
        'delivered': '✓',
        'cancelled': '✕',
        'refunded': '↩'
    };
    return iconMap[status] || '?';
}

function showError(message) {
    document.getElementById('ordersContainer').innerHTML = `
        <div class="text-center py-12">
            <div class="text-red-400 mb-4">
                <i class="ki-filled ki-warning text-4xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Error</h3>
            <p class="text-gray-500">${message}</p>
        </div>
    `;
}
</script>
@endpush
