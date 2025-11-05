@extends('layouts.app')

@section('title', 'Open Order Quantities')

@push('custom_css')
<style>
/* Modern Open Quantities Page Styling */
.open-qty-container {
    min-height: 100vh;
    background: #f9fafb;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    line-height: 1.5;
}

/* Header Styling */
.open-qty-header {
    position: sticky;
    top: 0;
    z-index: 30;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.summary-card {
    background: white;
    border-radius: 16px;
    padding: 1.25rem;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: all 0.2s;
}

.summary-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

.summary-card .icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.75rem;
    font-size: 24px;
}

.summary-card .value {
    font-size: 28px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 0.25rem;
}

.summary-card .label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
}

/* Hierarchy Configurator */
.hierarchy-config {
    background: white;
    border-radius: 16px;
    padding: 1.25rem;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-bottom: 1.5rem;
}

.hierarchy-pills {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    align-items: center;
}

.hierarchy-pill-wrapper {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.hierarchy-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 0.875rem;
    border-radius: 9999px;
    background: #f3f4f6;
    color: #374151;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    border: 2px solid transparent;
    position: relative;
    cursor: move;
    user-select: none;
}

.hierarchy-pill.dragging {
    opacity: 0.5;
    transform: scale(0.95);
}

.hierarchy-pill.drag-over {
    border-color: #3b82f6;
    background: #eff6ff;
    transform: scale(1.05);
}

.hierarchy-pill:hover {
    background: #e5e7eb;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.hierarchy-pill.active {
    background: #3b82f6;
    color: white;
    border-color: #2563eb;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.hierarchy-pill .pill-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.1);
    color: inherit;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s;
    margin-left: 0.25rem;
}

.hierarchy-pill .pill-remove:hover {
    background: rgba(0, 0, 0, 0.2);
    transform: scale(1.1);
}

.hierarchy-pill.active .pill-remove {
    background: rgba(255, 255, 255, 0.2);
}

.hierarchy-pill.active .pill-remove:hover {
    background: rgba(255, 255, 255, 0.3);
}

.hierarchy-reorder-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: grab;
    opacity: 0.5;
    transition: opacity 0.15s;
    font-size: 16px;
    color: #6b7280;
    padding: 0 4px;
}

.hierarchy-reorder-btn:hover {
    opacity: 1;
}

.hierarchy-reorder-btn:active {
    cursor: grabbing;
}

.hierarchy-arrow {
    color: #9ca3af;
    font-size: 18px;
    font-weight: 600;
}

.add-level-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.625rem 0.875rem;
    border-radius: 9999px;
    background: white;
    color: #3b82f6;
    font-size: 14px;
    font-weight: 500;
    border: 2px dashed #3b82f6;
    cursor: pointer;
    transition: all 0.2s;
}

.add-level-btn:hover {
    background: #eff6ff;
    border-color: #2563eb;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
}

.add-level-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    margin-top: 0.5rem;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 50;
    min-width: 200px;
    padding: 0.5rem;
}

.add-level-option {
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    color: #374151;
    transition: all 0.15s;
}

.add-level-option:hover {
    background: #f3f4f6;
    color: #111827;
}

.add-level-option.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.add-level-option.disabled:hover {
    background: transparent;
}

/* Breadcrumb Navigation */
.breadcrumb-nav {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: #f9fafb;
    border-radius: 12px;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.breadcrumb-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 14px;
}

.breadcrumb-link {
    color: #3b82f6;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.15s;
}

.breadcrumb-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

.breadcrumb-current {
    color: #6b7280;
    font-weight: 600;
}

.breadcrumb-separator {
    color: #9ca3af;
}

/* Data Table */
.data-table-wrapper {
    background: white;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    overflow: hidden;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #fafbfc;
    border-bottom: 2px solid #e5e7eb;
}

.data-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.data-table thead th.text-right {
    text-align: right;
}

.data-table tbody tr {
    border-bottom: 1px solid #f3f4f6;
    transition: all 0.15s;
}

.data-table tbody tr:hover {
    background: #f0f9ff;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
}

.data-table tbody tr:nth-child(even) {
    background: #f9fafb;
}

.data-table tbody tr:nth-child(even):hover {
    background: #f0f9ff;
}

.data-table tbody td {
    padding: 14px 16px;
    font-size: 15px;
    color: #374151;
}

.data-table tbody td.text-right {
    text-align: right;
}

/* Drill-down button */
.drill-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #3b82f6;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}

.drill-btn:hover {
    color: #2563eb;
}

.drill-btn .icon {
    font-size: 18px;
    transition: transform 0.2s;
}

.drill-btn.expanded .icon {
    transform: rotate(90deg);
}

/* Quantity Badge */
.qty-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-weight: 600;
    font-size: 14px;
}

.qty-badge.high {
    background: #dcfce7;
    color: #166534;
}

.qty-badge.medium {
    background: #fef3c7;
    color: #92400e;
}

.qty-badge.low {
    background: #fee2e2;
    color: #991b1b;
}

/* Progress Bar */
.progress-bar {
    width: 100%;
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #60a5fa);
    border-radius: 3px;
    transition: width 0.3s ease;
}

/* Loading & Empty States */
.loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 2rem;
    color: #6b7280;
}

.loading-state .spinner {
    width: 48px;
    height: 48px;
    border: 4px solid #e5e7eb;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-bottom: 1rem;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.empty-state {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state .icon-wrapper {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
}

.empty-state .icon-wrapper svg {
    width: 40px;
    height: 40px;
    color: #9ca3af;
}

.empty-state h3 {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 0.5rem;
}

.empty-state p {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 1.5rem;
}

/* Filters */
.filters-row {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 1rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.filter-label {
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.filter-select {
    padding: 0.5rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    transition: all 0.15s;
}

.filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    ring: 2px solid rgba(59, 130, 246, 0.1);
}

/* Action Buttons */
.action-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.15s;
    cursor: pointer;
    border: none;
}

.action-btn.primary {
    background: #3b82f6;
    color: white;
}

.action-btn.primary:hover {
    background: #2563eb;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.action-btn.secondary {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.action-btn.secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

/* Responsive */
@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }
    
    .hierarchy-pills {
        justify-content: center;
    }
    
    .data-table {
        font-size: 13px;
    }
    
    .data-table thead th,
    .data-table tbody td {
        padding: 10px 12px;
    }
}
</style>
@endpush

@section('content')
<div class="open-qty-container">
    <!-- Compact Header -->
    <div class="open-qty-header">
        <div class="max-w-7xl mx-auto px-4 lg:px-6 py-2.5">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-lg font-bold text-gray-900">Open Order Quantities</h1>
                    <p class="text-xs text-gray-500">Hierarchical breakdown of quantities in open orders</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="openStatusSettings()" class="action-btn secondary" title="Configure which order statuses to include" style="padding: 6px 10px; font-size: 13px;">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                        </svg>
                        Settings
                        <span id="status-filter-indicator" class="ml-1 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" style="display: none;"></span>
                    </button>
                    <button onclick="refreshData()" class="action-btn secondary" style="padding: 6px 10px; font-size: 13px;">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Refresh
                    </button>
                    <button onclick="exportData()" class="action-btn primary" style="padding: 6px 10px; font-size: 13px;">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 lg:px-6 py-3">
        
        <!-- Compact Combined Section: Cards + Hierarchy -->
        <div class="flex items-center gap-4 mb-3" style="background: #f9fafb; padding: 12px; border-radius: 12px; border: 1px solid #e5e7eb;">
            <!-- Summary Cards (Horizontal Compact) -->
            <div class="flex gap-3 flex-shrink-0">
                <div class="flex items-center gap-2 bg-white px-3 py-2 rounded-lg border border-gray-200">
                    <div class="text-2xl">📦</div>
                    <div>
                        <div class="text-lg font-bold text-gray-900" id="total-orders">-</div>
                        <div class="text-xs text-gray-500">Orders</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 bg-white px-3 py-2 rounded-lg border border-gray-200">
                    <div class="text-2xl">📊</div>
                    <div>
                        <div class="text-lg font-bold text-gray-900" id="total-quantity">-</div>
                        <div class="text-xs text-gray-500">Quantity</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 bg-white px-3 py-2 rounded-lg border border-gray-200">
                    <div class="text-2xl">🏷️</div>
                    <div>
                        <div class="text-lg font-bold text-gray-900" id="category-count">-</div>
                        <div class="text-xs text-gray-500">Categories</div>
                    </div>
                </div>
            </div>

            <!-- Hierarchy Configuration (Inline) -->
            <div class="flex-1 flex items-center gap-2 bg-white px-3 py-2 rounded-lg border border-gray-200">
                <span class="text-xs font-semibold text-gray-600 whitespace-nowrap">Levels:</span>
                <div class="flex-1 flex flex-wrap items-center gap-2" id="hierarchy-display">
                    <!-- Will be populated by JavaScript -->
                </div>
                <button onclick="resetHierarchy()" class="text-xs text-gray-500 hover:text-blue-600 whitespace-nowrap ml-2">
                    🔄
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-row">
            <div class="filter-group">
                <label class="filter-label">Search</label>
                <input type="text" id="search-filter" placeholder="Search categories..." class="filter-select" style="min-width: 300px;">
            </div>
        </div>

        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb-nav" id="breadcrumb-nav" style="display: none;">
            <!-- Dynamically populated -->
        </div>

        <!-- Data Table -->
        <div class="data-table-wrapper">
            <div id="loading-state" class="loading-state">
                <div class="spinner"></div>
                <p>Loading quantity data...</p>
            </div>

            <div id="empty-state" class="empty-state" style="display: none;">
                <div class="icon-wrapper">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                </div>
                <h3 id="empty-state-title">No Data Found</h3>
                <p id="empty-state-message">There are no open orders with the selected filters.</p>
                <button onclick="resetFilters()" class="action-btn primary" style="margin-top: 1rem;">
                    Reset Filters
                </button>
                <p style="margin-top: 1rem; font-size: 12px; color: #9ca3af;">
                    Tip: Check browser console (F12) for detailed error information.
                </p>
            </div>

            <table class="data-table" id="data-table" style="display: none;">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-right">Quantity</th>
                        <th class="text-right">% of Total</th>
                        <th class="text-right">Orders</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <!-- Dynamically populated -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Status Settings Modal -->
<div id="status-settings-modal" style="display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); z-index: 9999; align-items: center; justify-content: center;" onclick="closeStatusSettings()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between p-6 border-b border-gray-200">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Order Status Filter</h2>
                <p class="text-sm text-gray-500 mt-1">Select which statuses to include in open orders</p>
            </div>
            <button onclick="closeStatusSettings()" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <div class="p-6">
            <div class="mb-4">
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-semibold text-gray-700">Available Order Statuses:</label>
                    <div class="flex gap-2">
                        <button onclick="selectAllStatuses()" class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                            Select All
                        </button>
                        <span class="text-gray-300">|</span>
                        <button onclick="deselectAllStatuses()" class="text-xs text-gray-600 hover:text-gray-700 font-medium">
                            Deselect All
                        </button>
                    </div>
                </div>
                <div id="status-checkboxes" class="space-y-2 max-h-64 overflow-y-auto">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
                <p class="text-xs text-blue-800">
                    <strong>💡 Tip:</strong> Unchecked statuses will be excluded from all calculations. Your preferences are saved automatically.
                </p>
            </div>
        </div>
        
        <div class="flex items-center justify-between px-6 py-4 bg-gray-50 border-t border-gray-200 rounded-b-2xl">
            <button onclick="resetStatusDefaults()" class="text-sm text-gray-600 hover:text-gray-800 font-medium">
                🔄 Reset to Defaults
            </button>
            <div class="flex gap-2">
                <button onclick="closeStatusSettings()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button onclick="saveStatusSettings()" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors">
                    Apply Settings
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<script>
// Global state management
window.openQtyState = {
    hierarchy: ['product_type', 'product_name', 'orders'], // Will be loaded from localStorage
    currentLevel: 0,
    breadcrumbs: [],
    filters: {},
    dateRange: 0, // 0 = all time (open orders only)
    cachedData: new Map(),
    excludedStatuses: [] // Will be loaded from localStorage
};

// Default hierarchy
window.defaultHierarchy = ['product_type', 'product_name', 'orders'];

// All available order statuses
window.allOrderStatuses = [
    { code: 'new', name: 'New', color: 'amber' },
    { code: 'pending', name: 'Pending', color: 'yellow' },
    { code: 'on_hold', name: 'On Hold', color: 'orange' },
    { code: 'processing', name: 'Processing', color: 'blue' },
    { code: 'out_for_delivery', name: 'Out for Delivery', color: 'violet' },
    { code: 'delivered', name: 'Delivered', color: 'green' },
    { code: 'completed', name: 'Completed', color: 'green' },
    { code: 'cancelled', name: 'Cancelled', color: 'red' },
    { code: 'refunded', name: 'Refunded', color: 'purple' }
];

// Default excluded statuses (closed orders)
window.defaultExcludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Load excluded statuses from localStorage
    const savedStatuses = localStorage.getItem('openQtyExcludedStatuses');
    if (savedStatuses) {
        try {
            window.openQtyState.excludedStatuses = JSON.parse(savedStatuses);
        } catch (e) {
            window.openQtyState.excludedStatuses = [...window.defaultExcludedStatuses];
        }
    } else {
        window.openQtyState.excludedStatuses = [...window.defaultExcludedStatuses];
    }
    
    // Load hierarchy from localStorage
    const savedHierarchy = localStorage.getItem('openQtyHierarchy');
    if (savedHierarchy) {
        try {
            const parsed = JSON.parse(savedHierarchy);
            // Validate that it's an array and contains 'orders' at the end
            if (Array.isArray(parsed) && parsed.length >= 2 && parsed[parsed.length - 1] === 'orders') {
                window.openQtyState.hierarchy = parsed;
            } else {
                window.openQtyState.hierarchy = [...window.defaultHierarchy];
            }
        } catch (e) {
            window.openQtyState.hierarchy = [...window.defaultHierarchy];
        }
    } else {
        window.openQtyState.hierarchy = [...window.defaultHierarchy];
    }
    
    updateStatusFilterIndicator();
    updateHierarchyDisplay();
    loadData();
    
    // Setup search debouncing
    let searchTimeout;
    document.getElementById('search-filter').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            filterTable(this.value);
        }, 300);
    });
});

// Load data from API
async function loadData() {
    try {
        showLoading();
        
        const params = new URLSearchParams({
            hierarchy: JSON.stringify(window.openQtyState.hierarchy),
            level: window.openQtyState.currentLevel,
            filters: JSON.stringify(window.openQtyState.filters),
            date_range: window.openQtyState.dateRange,
            excluded_statuses: JSON.stringify(window.openQtyState.excludedStatuses)
        });

        const response = await fetch(`/orders/open-quantities/data?${params}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const result = await response.json();
        
        // Debug logging
        console.log('API Response:', result);

        if (result.success) {
            console.log('Data received:', result.data.length, 'categories');
            console.log('Summary:', result.summary);
            updateSummaryCards(result.summary);
            renderTable(result.data, result.summary);
            updateBreadcrumbs();
        } else {
            console.error('API Error:', result.message);
            showError(result.message || 'Failed to load data');
        }
    } catch (error) {
        console.error('Load data error:', error);
        showError('Failed to load quantity data: ' + error.message);
    }
}

// Update summary cards
function updateSummaryCards(summary) {
    document.getElementById('total-orders').textContent = summary.total_orders.toLocaleString();
    document.getElementById('total-quantity').textContent = summary.total_quantity.toLocaleString();
    document.getElementById('category-count').textContent = summary.category_count.toLocaleString();
}

// Render data table
function renderTable(data, summary) {
    const tbody = document.getElementById('table-body');
    const table = document.getElementById('data-table');
    const thead = table.querySelector('thead tr');
    const emptyState = document.getElementById('empty-state');
    const loadingState = document.getElementById('loading-state');

    loadingState.style.display = 'none';

    if (!data || data.length === 0) {
        table.style.display = 'none';
        emptyState.style.display = 'block';
        return;
    }

    emptyState.style.display = 'none';
    table.style.display = 'table';

    // Check if we're at the orders level
    const isOrdersLevel = summary.current_field === 'orders';
    
    // Update table headers based on level
    if (isOrdersLevel) {
        thead.innerHTML = `
            <th>Order Number</th>
            <th>Customer Name</th>
            <th>Status</th>
            <th class="text-right">Quantity</th>
            <th class="text-right">Date</th>
            <th class="text-right">Action</th>
        `;
    } else {
        thead.innerHTML = `
            <th>Category</th>
            <th class="text-right">Quantity</th>
            <th class="text-right">% of Total</th>
            <th class="text-right">Orders</th>
            <th class="text-right">Action</th>
        `;
    }

    tbody.innerHTML = data.map(item => {
        if (isOrdersLevel) {
            // Orders level: show order details with customer name
            const customerName = item.customer_full_name && item.customer_full_name.trim() 
                ? escapeHtml(item.customer_full_name.trim()) 
                : '<span class="text-gray-400 italic">No customer</span>';
            
            return `
                <tr>
                    <td>
                        <a href="/orders/${item.order_id}/invoice" class="text-blue-600 hover:text-blue-800 font-semibold">
                            ${escapeHtml(item.group_name)}
                        </a>
                    </td>
                    <td>
                        ${customerName}
                    </td>
                    <td>
                        ${getStatusChip(item.order_status)}
                    </td>
                    <td class="text-right">
                        <strong>${parseFloat(item.total_quantity).toLocaleString()}</strong>
                    </td>
                    <td class="text-right">
                        ${item.order_date ? new Date(item.order_date).toLocaleDateString() : '-'}
                    </td>
                    <td class="text-right">
                        <a href="/orders/${item.order_id}/invoice" class="action-btn secondary" style="padding: 0.375rem 0.75rem; font-size: 13px;">
                            View Order
                        </a>
                    </td>
                </tr>
            `;
        } else {
            // Category levels: show drill-down
            const qtyLevel = item.percentage > 30 ? 'high' : item.percentage > 10 ? 'medium' : 'low';
            const canDrillDown = summary.has_next_level && item.group_name;
            
            return `
                <tr>
                    <td>
                        ${canDrillDown ? 
                            `<span class="drill-btn" onclick="drillDown('${escapeHtml(item.group_name)}')">
                                <span class="icon">▶</span>
                                <strong>${escapeHtml(item.group_name || 'Unknown')}</strong>
                            </span>` :
                            `<strong>${escapeHtml(item.group_name || 'Unknown')}</strong>`
                        }
                    </td>
                    <td class="text-right">
                        <span class="qty-badge ${qtyLevel}">
                            ${parseFloat(item.total_quantity).toLocaleString()}
                        </span>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${item.percentage}%"></div>
                        </div>
                    </td>
                    <td class="text-right">
                        <strong>${item.percentage}%</strong>
                    </td>
                    <td class="text-right">
                        ${item.order_count ? item.order_count.toLocaleString() : '-'}
                    </td>
                    <td class="text-right">
                        ${canDrillDown ? 
                            `<button onclick="drillDown('${escapeHtml(item.group_name)}')" class="action-btn secondary" style="padding: 0.375rem 0.75rem; font-size: 13px;">
                                View Details
                            </button>` :
                            '<span class="text-gray-400">—</span>'
                        }
                    </td>
                </tr>
            `;
        }
    }).join('');
}

// Drill down to next level
function drillDown(groupName) {
    const currentField = window.openQtyState.hierarchy[window.openQtyState.currentLevel];
    
    // Add to breadcrumbs
    window.openQtyState.breadcrumbs.push({
        field: currentField,
        value: groupName,
        label: groupName
    });
    
    // Update filters
    window.openQtyState.filters[currentField] = groupName;
    
    // Move to next level
    window.openQtyState.currentLevel++;
    
    // Update hierarchy display to highlight current level
    updateHierarchyDisplay();
    
    // Reload data
    loadData();
}

// Navigate breadcrumb
function navigateBreadcrumb(level) {
    // Remove breadcrumbs after clicked level
    window.openQtyState.breadcrumbs = window.openQtyState.breadcrumbs.slice(0, level);
    
    // Reset filters
    window.openQtyState.filters = {};
    window.openQtyState.breadcrumbs.forEach(bc => {
        window.openQtyState.filters[bc.field] = bc.value;
    });
    
    // Update level
    window.openQtyState.currentLevel = level;
    
    // Update hierarchy display
    updateHierarchyDisplay();
    
    // Reload
    loadData();
}

// Update breadcrumbs
function updateBreadcrumbs() {
    const nav = document.getElementById('breadcrumb-nav');
    
    if (window.openQtyState.breadcrumbs.length === 0) {
        nav.style.display = 'none';
        return;
    }
    
    nav.style.display = 'flex';
    nav.innerHTML = `
        <div class="breadcrumb-item">
            <a href="#" onclick="event.preventDefault(); navigateBreadcrumb(0);" class="breadcrumb-link">All</a>
        </div>
        ${window.openQtyState.breadcrumbs.map((bc, idx) => `
            <div class="breadcrumb-item">
                <span class="breadcrumb-separator">›</span>
                ${idx === window.openQtyState.breadcrumbs.length - 1 ?
                    `<span class="breadcrumb-current">${escapeHtml(bc.label)}</span>` :
                    `<a href="#" onclick="event.preventDefault(); navigateBreadcrumb(${idx + 1});" class="breadcrumb-link">${escapeHtml(bc.label)}</a>`
                }
            </div>
        `).join('')}
    `;
}

// Apply filters (simplified - no date range needed for open orders)
function applyFilters() {
    loadData();
}

// Filter table (client-side search)
function filterTable(searchTerm) {
    const rows = document.querySelectorAll('#table-body tr');
    const term = searchTerm.toLowerCase();
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
}

// Reset filters
function resetFilters() {
    document.getElementById('search-filter').value = '';
    window.openQtyState.filters = {};
    window.openQtyState.breadcrumbs = [];
    window.openQtyState.currentLevel = 0;
    loadData();
}

// Reset hierarchy
// Save hierarchy to localStorage
function saveHierarchy() {
    try {
        localStorage.setItem('openQtyHierarchy', JSON.stringify(window.openQtyState.hierarchy));
        console.log('Hierarchy saved:', window.openQtyState.hierarchy);
    } catch (e) {
        console.error('Failed to save hierarchy:', e);
    }
}

function resetHierarchy() {
    window.openQtyState.hierarchy = [...window.defaultHierarchy];
    window.openQtyState.currentLevel = 0;
    window.openQtyState.breadcrumbs = [];
    window.openQtyState.filters = {};
    saveHierarchy();
    updateHierarchyDisplay();
    loadData();
}


// Update hierarchy display with interactive controls
function updateHierarchyDisplay() {
    const labels = {
        'product_type': 'Category',
        'attribute_1': '{{ $labels["1"] ?? "Level 1" }}',
        'attribute_2': '{{ $labels["2"] ?? "Level 2" }}',
        'attribute_3': '{{ $labels["3"] ?? "Level 3" }}',
        'product_name': 'Product',
        'orders': 'Orders'
    };
    
    const display = document.getElementById('hierarchy-display');
    const hierarchy = window.openQtyState.hierarchy;
    
    let html = '';
    
    hierarchy.forEach((field, idx) => {
        const isActive = idx === window.openQtyState.currentLevel;
        const isLast = idx === hierarchy.length - 1;
        const canRemove = hierarchy.length > 2 && field !== 'orders'; // Must keep at least 2 levels
        const canDrag = field !== 'orders'; // Can't drag the "orders" level
        
        // Drag handle (only if draggable)
        const dragHandleHtml = canDrag ? `
            <div class="hierarchy-reorder-btn" title="Drag to reorder">
                ⋮⋮
            </div>
        ` : '<div style="width: 20px;"></div>';
        
        // Pill with remove button (draggable if not "orders" level)
        const pillHtml = `
            <span class="hierarchy-pill ${isActive ? 'active' : ''}" 
                  ${canDrag ? `draggable="true" data-index="${idx}" ondragstart="handleDragStart(event)" ondragend="handleDragEnd(event)" ondragover="handleDragOver(event)" ondrop="handleDrop(event)"` : ''}>
                ${labels[field] || field}
                ${canRemove ? `<span class="pill-remove" onclick="event.stopPropagation(); removeHierarchyLevel(${idx})" title="Remove level">×</span>` : ''}
            </span>
        `;
        
        // Arrow
        const arrowHtml = isLast ? '' : '<span class="hierarchy-arrow">→</span>';
        
        html += `<div class="hierarchy-pill-wrapper">${dragHandleHtml}${pillHtml}</div>${arrowHtml}`;
    });
    
    // Add level button with dropdown
    html += `
        <div style="position: relative;">
            <button class="add-level-btn" onclick="toggleAddLevelDropdown(event)">
                <span style="font-size: 16px;">+</span>
                <span>Add Level</span>
            </button>
            <div id="add-level-dropdown" class="add-level-dropdown" style="display: none;">
                ${getAvailableLevelOptions()}
            </div>
        </div>
    `;
    
    display.innerHTML = html;
}

// Get available level options
function getAvailableLevelOptions() {
    const allOptions = {
        'product_type': 'Category',
        'attribute_1': '{{ $labels["1"] ?? "Level 1" }}',
        'attribute_2': '{{ $labels["2"] ?? "Level 2" }}',
        'attribute_3': '{{ $labels["3"] ?? "Level 3" }}',
        'product_name': 'Product'
    };
    
    const currentHierarchy = window.openQtyState.hierarchy.filter(f => f !== 'orders');
    
    return Object.entries(allOptions).map(([field, label]) => {
        const isUsed = currentHierarchy.includes(field);
        const className = isUsed ? 'add-level-option disabled' : 'add-level-option';
        const onclick = isUsed ? '' : `onclick="addHierarchyLevel('${field}')"`;
        return `<div class="${className}" ${onclick}>${label}${isUsed ? ' ✓' : ''}</div>`;
    }).join('');
}

// Toggle add level dropdown
function toggleAddLevelDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('add-level-dropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('add-level-dropdown');
    if (dropdown && !event.target.closest('.add-level-btn')) {
        dropdown.style.display = 'none';
    }
});

// Add hierarchy level
function addHierarchyLevel(field) {
    if (!window.openQtyState.hierarchy.includes(field)) {
        // Insert before 'orders' (always last)
        const withoutOrders = window.openQtyState.hierarchy.filter(f => f !== 'orders');
        window.openQtyState.hierarchy = [...withoutOrders, field, 'orders'];
        
        // Reset to top level
        window.openQtyState.currentLevel = 0;
        window.openQtyState.breadcrumbs = [];
        window.openQtyState.filters = {};
        
        saveHierarchy();
        updateHierarchyDisplay();
        document.getElementById('add-level-dropdown').style.display = 'none';
        loadData();
    }
}

// Remove hierarchy level
function removeHierarchyLevel(index) {
    if (window.openQtyState.hierarchy.length > 2) {
        window.openQtyState.hierarchy.splice(index, 1);
        
        // Reset to top level
        window.openQtyState.currentLevel = 0;
        window.openQtyState.breadcrumbs = [];
        window.openQtyState.filters = {};
        
        saveHierarchy();
        updateHierarchyDisplay();
        loadData();
    }
}

// ==================== DRAG AND DROP FUNCTIONS ====================

let draggedIndex = null;

// Handle drag start
function handleDragStart(event) {
    draggedIndex = parseInt(event.target.dataset.index);
    event.target.classList.add('dragging');
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/html', event.target.innerHTML);
}

// Handle drag end
function handleDragEnd(event) {
    event.target.classList.remove('dragging');
    
    // Remove drag-over class from all pills
    document.querySelectorAll('.hierarchy-pill').forEach(pill => {
        pill.classList.remove('drag-over');
    });
}

// Handle drag over
function handleDragOver(event) {
    if (event.preventDefault) {
        event.preventDefault(); // Necessary to allow drop
    }
    
    event.dataTransfer.dropEffect = 'move';
    
    const target = event.target.closest('.hierarchy-pill');
    if (target && target.hasAttribute('draggable')) {
        // Add visual feedback
        document.querySelectorAll('.hierarchy-pill').forEach(pill => {
            pill.classList.remove('drag-over');
        });
        target.classList.add('drag-over');
    }
    
    return false;
}

// Handle drop
function handleDrop(event) {
    if (event.stopPropagation) {
        event.stopPropagation(); // Stops browser from redirecting
    }
    
    const target = event.target.closest('.hierarchy-pill');
    if (!target || !target.hasAttribute('draggable')) {
        return false;
    }
    
    const targetIndex = parseInt(target.dataset.index);
    
    // Don't do anything if dropping on itself
    if (draggedIndex === targetIndex) {
        return false;
    }
    
    // Reorder the hierarchy
    const hierarchy = [...window.openQtyState.hierarchy];
    const draggedItem = hierarchy[draggedIndex];
    
    // Remove from old position
    hierarchy.splice(draggedIndex, 1);
    
    // Insert at new position (adjust if moving down)
    const insertIndex = draggedIndex < targetIndex ? targetIndex : targetIndex;
    hierarchy.splice(insertIndex, 0, draggedItem);
    
    // Update state
    window.openQtyState.hierarchy = hierarchy;
    
    // Reset to top level
    window.openQtyState.currentLevel = 0;
    window.openQtyState.breadcrumbs = [];
    window.openQtyState.filters = {};
    
    saveHierarchy();
    updateHierarchyDisplay();
    loadData();
    
    return false;
}

// Move hierarchy level up (kept for backward compatibility, can be removed)
function moveHierarchyUp(index) {
    if (index > 0) {
        const temp = window.openQtyState.hierarchy[index];
        window.openQtyState.hierarchy[index] = window.openQtyState.hierarchy[index - 1];
        window.openQtyState.hierarchy[index - 1] = temp;
        
        // Reset to top level
        window.openQtyState.currentLevel = 0;
        window.openQtyState.breadcrumbs = [];
        window.openQtyState.filters = {};
        
        saveHierarchy();
        updateHierarchyDisplay();
        loadData();
    }
}

// Move hierarchy level down (kept for backward compatibility, can be removed)
function moveHierarchyDown(index) {
    if (index < window.openQtyState.hierarchy.length - 1 && window.openQtyState.hierarchy[index] !== 'orders') {
        const temp = window.openQtyState.hierarchy[index];
        window.openQtyState.hierarchy[index] = window.openQtyState.hierarchy[index + 1];
        window.openQtyState.hierarchy[index + 1] = temp;
        
        // Reset to top level
        window.openQtyState.currentLevel = 0;
        window.openQtyState.breadcrumbs = [];
        window.openQtyState.filters = {};
        
        saveHierarchy();
        updateHierarchyDisplay();
        loadData();
    }
}

// Refresh data
function refreshData() {
    window.openQtyState.cachedData.clear();
    loadData();
}

// Export data (placeholder)
function exportData() {
    alert('Export functionality coming soon!');
    // TODO: Implement CSV/Excel export
}

// UI helpers
function showLoading() {
    document.getElementById('loading-state').style.display = 'flex';
    document.getElementById('data-table').style.display = 'none';
    document.getElementById('empty-state').style.display = 'none';
}

function showError(message) {
    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('data-table').style.display = 'none';
    const emptyState = document.getElementById('empty-state');
    emptyState.style.display = 'block';
    
    // Update error message
    const titleEl = document.getElementById('empty-state-title');
    const messageEl = document.getElementById('empty-state-message');
    if (message && message.includes('Failed')) {
        titleEl.textContent = 'Error Loading Data';
        messageEl.textContent = message;
    }
    
    console.error('Error:', message);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Get status chip HTML
function getStatusChip(status) {
    const statusColors = {
        'new': 'bg-amber-100 text-amber-800',
        'on_hold': 'bg-orange-100 text-orange-800',
        'processing': 'bg-blue-100 text-blue-800',
        'out_for_delivery': 'bg-violet-100 text-violet-800',
        'delivered': 'bg-green-100 text-green-800',
        'cancelled': 'bg-red-100 text-red-800',
        'refunded': 'bg-purple-100 text-purple-800',
        'completed': 'bg-green-100 text-green-800',
        'pending': 'bg-yellow-100 text-yellow-800'
    };
    
    const colorClass = statusColors[status] || 'bg-gray-100 text-gray-800';
    const displayStatus = (status || 'unknown').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    
    return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colorClass}">${displayStatus}</span>`;
}

// ==================== STATUS SETTINGS FUNCTIONS ====================

// Open status settings modal
function openStatusSettings() {
    const modal = document.getElementById('status-settings-modal');
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    populateStatusCheckboxes();
}

// Close status settings modal
function closeStatusSettings() {
    const modal = document.getElementById('status-settings-modal');
    modal.style.display = 'none';
}

// Populate status checkboxes
function populateStatusCheckboxes() {
    const container = document.getElementById('status-checkboxes');
    const excluded = window.openQtyState.excludedStatuses;
    
    container.innerHTML = window.allOrderStatuses.map(status => {
        const isChecked = !excluded.includes(status.code);
        const colorClasses = {
            'amber': 'border-amber-200 bg-amber-50',
            'yellow': 'border-yellow-200 bg-yellow-50',
            'orange': 'border-orange-200 bg-orange-50',
            'blue': 'border-blue-200 bg-blue-50',
            'violet': 'border-violet-200 bg-violet-50',
            'green': 'border-green-200 bg-green-50',
            'red': 'border-red-200 bg-red-50',
            'purple': 'border-purple-200 bg-purple-50'
        };
        
        const colorClass = colorClasses[status.color] || 'border-gray-200 bg-gray-50';
        
        return `
            <label class="flex items-center justify-between p-3 border ${colorClass} rounded-lg cursor-pointer hover:shadow-sm transition-all">
                <div class="flex items-center gap-3">
                    <input type="checkbox" 
                           class="status-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500" 
                           data-status="${status.code}"
                           ${isChecked ? 'checked' : ''}>
                    <span class="font-medium text-gray-900">${status.name}</span>
                </div>
                ${getStatusChip(status.code)}
            </label>
        `;
    }).join('');
}

// Select all statuses
function selectAllStatuses() {
    document.querySelectorAll('.status-checkbox').forEach(cb => cb.checked = true);
}

// Deselect all statuses
function deselectAllStatuses() {
    document.querySelectorAll('.status-checkbox').forEach(cb => cb.checked = false);
}

// Reset to default excluded statuses
function resetStatusDefaults() {
    window.openQtyState.excludedStatuses = [...window.defaultExcludedStatuses];
    populateStatusCheckboxes();
}

// Save status settings
function saveStatusSettings() {
    const checkboxes = document.querySelectorAll('.status-checkbox');
    const excluded = [];
    
    checkboxes.forEach(cb => {
        if (!cb.checked) {
            excluded.push(cb.dataset.status);
        }
    });
    
    // Save to state and localStorage
    window.openQtyState.excludedStatuses = excluded;
    localStorage.setItem('openQtyExcludedStatuses', JSON.stringify(excluded));
    
    // Update indicator
    updateStatusFilterIndicator();
    
    // Close modal
    closeStatusSettings();
    
    // Reload data
    window.openQtyState.cachedData.clear();
    loadData();
    
    // Show success message
    console.log('Status filter settings saved:', excluded);
}

// Update status filter indicator
function updateStatusFilterIndicator() {
    const indicator = document.getElementById('status-filter-indicator');
    const excluded = window.openQtyState.excludedStatuses;
    const defaultCount = window.defaultExcludedStatuses.length;
    const currentCount = excluded.length;
    
    if (currentCount !== defaultCount || 
        !window.defaultExcludedStatuses.every(s => excluded.includes(s))) {
        // Custom filter active
        const includedCount = window.allOrderStatuses.length - currentCount;
        indicator.textContent = `${includedCount}/${window.allOrderStatuses.length}`;
        indicator.style.display = 'inline-block';
        indicator.title = `${includedCount} statuses selected`;
    } else {
        // Default filter
        indicator.style.display = 'none';
    }
}
</script>
@endpush

