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

<!-- View Order Modal -->
<div id="viewOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Order Details</h3>
            <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div id="viewOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<script>
// Global state management
window.openQtyState = {
    hierarchy: ['product_type', 'product_name', 'orders'], // Will be loaded from API
    currentLevel: 0,
    breadcrumbs: [],
    filters: {},
    dateRange: 0, // 0 = all time (open orders only)
    cachedData: new Map(),
    excludedStatuses: [] // Will be loaded from API
};

// Permission flag - set after loading settings
window.canEditSettings = false;

// Default hierarchy
window.defaultHierarchy = ['product_type', 'product_name', 'orders'];

// All available order statuses - will be loaded dynamically from API
window.allOrderStatuses = [];

// Default excluded statuses (closed orders) - will be updated from API if available
window.defaultExcludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];

// Load global settings from API
async function loadGlobalSettings() {
    try {
        const response = await fetch('/orders/open-quantities/settings', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Load global settings
            window.openQtyState.hierarchy = data.settings.hierarchy_levels || window.defaultHierarchy;
            window.openQtyState.excludedStatuses = data.settings.excluded_statuses || window.defaultExcludedStatuses;
            window.canEditSettings = data.can_edit || false;
            
            // ⭐ Load all statuses dynamically from API (instead of hardcoded)
            if (data.all_statuses && data.all_statuses.length > 0) {
                window.allOrderStatuses = data.all_statuses;
                console.log('Loaded', data.all_statuses.length, 'statuses from database');
                
                // Update default excluded statuses to include final statuses
                const finalStatuses = data.all_statuses
                    .filter(s => s.is_final)
                    .map(s => s.code);
                if (finalStatuses.length > 0) {
                    window.defaultExcludedStatuses = finalStatuses;
                }
            } else {
                // Fallback to basic defaults if no statuses from API
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
            }
            
            console.log('Global settings loaded:', {
                hierarchy: window.openQtyState.hierarchy,
                excludedStatuses: window.openQtyState.excludedStatuses,
                canEdit: window.canEditSettings,
                totalStatuses: window.allOrderStatuses.length
            });
            
            // Update UI based on permissions
            updateUIPermissions();
            updateStatusFilterIndicator();
            updateHierarchyDisplay();
            loadData();
        } else {
            console.error('Failed to load settings:', data.message);
            // Fall back to defaults
            window.openQtyState.hierarchy = [...window.defaultHierarchy];
            window.openQtyState.excludedStatuses = [...window.defaultExcludedStatuses];
            updateStatusFilterIndicator();
            updateHierarchyDisplay();
            loadData();
        }
    } catch (error) {
        console.error('Error loading global settings:', error);
        // Fall back to defaults
        window.openQtyState.hierarchy = [...window.defaultHierarchy];
        window.openQtyState.excludedStatuses = [...window.defaultExcludedStatuses];
        updateStatusFilterIndicator();
        updateHierarchyDisplay();
        loadData();
    }
}

// Update UI permissions based on user role
function updateUIPermissions() {
    if (!window.canEditSettings) {
        // Disable hierarchy editing
        const addLevelBtn = document.querySelector('.add-level-btn');
        if (addLevelBtn) {
            addLevelBtn.style.opacity = '0.5';
            addLevelBtn.style.cursor = 'not-allowed';
            addLevelBtn.title = 'Only Taimur role can modify hierarchy levels';
        }
        
        // Disable status settings
        const settingsBtn = document.querySelector('button[onclick="openStatusSettings()"]');
        if (settingsBtn) {
            settingsBtn.disabled = false; // Keep it enabled for viewing
            settingsBtn.title = 'View status filters (only Taimur role can modify)';
        }
        
        console.log('UI permissions: Read-only mode (non-Taimur user)');
    } else {
        console.log('UI permissions: Edit mode (Taimur role)');
    }
}

// ⭐ AUTO-REFRESH: Poll for data updates every 5 seconds (same as Open Orders)
let autoRefreshInterval = null;

function startAutoRefresh() {
    // Stop existing polling if any
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    // Poll every 5 seconds for updates (matches Open Orders behavior)
    autoRefreshInterval = setInterval(() => {
        // Silent refresh - no loading spinner
        const params = new URLSearchParams();
        params.append('level', window.openQtyState.currentLevel);
        
        // Add parent filters
        Object.entries(window.openQtyState.filters).forEach(([key, value]) => {
            params.append('filters[' + key + ']', value);
        });
        
        // Silently fetch and update if there are changes
        fetch(`/orders/open-quantities/data?${params}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                // Only update if data has changed (compare counts)
                const currentCount = document.querySelectorAll('#table-body tr').length;
                const newCount = data.data.length;
                
                if (currentCount !== newCount || hasDataChanged(data.data)) {
                    renderTable(data.data, data.summary);
                    renderSummaryCards(data.summary);
                    console.log('🔄 Auto-refreshed Open Quantities data');
                }
            }
        })
        .catch(error => {
            // Silent fail during background refresh
            console.log('Auto-refresh error (non-critical):', error.message);
        });
    }, 5000); // 5 seconds - same as Open Orders
    
    console.log('✅ Auto-refresh started (polls every 5 seconds)');
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
        console.log('⏸️ Auto-refresh stopped');
    }
}

// Helper function to detect if data has actually changed
function hasDataChanged(newData) {
    const currentRows = document.querySelectorAll('#table-body tr');
    if (currentRows.length !== newData.length) return true;
    
    // Simple check: compare total quantities of first few items
    for (let i = 0; i < Math.min(3, newData.length); i++) {
        const row = currentRows[i];
        const item = newData[i];
        const currentQty = row ? parseFloat(row.querySelector('[class*="total_quantity"]')?.textContent?.replace(/,/g, '') || 0) : 0;
        const newQty = parseFloat(item.total_quantity || 0);
        
        if (Math.abs(currentQty - newQty) > 0.01) {
            return true; // Quantity changed
        }
    }
    
    return false; // No significant changes detected
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Load settings from API (not localStorage)
    loadGlobalSettings();
    
    // Start auto-refresh polling (same as Open Orders)
    startAutoRefresh();
    
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
        
        // Note: hierarchy and excluded_statuses are now read from database by backend
        // We only send level and filters
        const params = new URLSearchParams({
            level: window.openQtyState.currentLevel,
            filters: JSON.stringify(window.openQtyState.filters),
            date_range: window.openQtyState.dateRange
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
        // Show bulk action controls for orders level
        const bulkControlsHtml = `
            <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 16px; padding: 12px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
                <label style="display: flex; align-items: center; gap: 6px; font-size: 14px; color: #374151; cursor: pointer; font-weight: 500;">
                    <input type="checkbox" id="selectAllOrders" style="width: 18px; height: 18px; cursor: pointer; accent-color: #10b981;" onchange="toggleSelectAllOrders()">
                    <span>Select All</span>
                </label>
                <div style="border-left: 2px solid #d1d5db; height: 24px;"></div>
                <button onclick="markSelectedOrdersAsPrepared()" style="padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onmouseover="this.style.background='#059669'; this.style.transform='translateY(-1px)'" onmouseout="this.style.background='#10b981'; this.style.transform='translateY(0)'">
                    ✓ Mark as Prepared
                </button>
                <button onclick="clearSelectedOrdersStatus()" style="padding: 8px 16px; background: #6b7280; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onmouseover="this.style.background='#4b5563'; this.style.transform='translateY(-1px)'" onmouseout="this.style.background='#6b7280'; this.style.transform='translateY(0)'">
                    ✗ Clear Status
                </button>
                <span id="selectedOrdersCount" style="margin-left: auto; font-size: 13px; color: #6b7280; font-weight: 500;"></span>
            </div>
        `;
        
        // Insert bulk controls before the table
        const tableWrapper = table.closest('.table-wrapper') || table.parentElement;
        let bulkControlsDiv = document.getElementById('bulk-order-controls');
        if (!bulkControlsDiv) {
            bulkControlsDiv = document.createElement('div');
            bulkControlsDiv.id = 'bulk-order-controls';
            tableWrapper.insertBefore(bulkControlsDiv, table);
        }
        bulkControlsDiv.innerHTML = bulkControlsHtml;
        bulkControlsDiv.style.display = 'block'; // Ensure it's visible
        
        thead.innerHTML = `
            <th style="width: 40px; text-align: center;">
                <input type="checkbox" id="selectAllOrdersHeader" style="width: 18px; height: 18px; cursor: pointer; accent-color: #10b981;" onchange="toggleSelectAllOrders()">
            </th>
            <th>Order Number</th>
            <th>Customer Name</th>
            <th>Status</th>
            <th class="text-right" style="min-width: 80px;">
                <div>Total</div>
                <div style="font-size: 10px; font-weight: normal; color: #6b7280;">Qty</div>
            </th>
            <th class="text-right" style="min-width: 70px;">
                <div style="color: #059669;">Lean</div>
            </th>
            <th class="text-right" style="min-width: 70px;">
                <div style="color: #dc2626;">Non-Lean</div>
            </th>
            <th class="text-right" style="min-width: 80px;">
                <div>Processing</div>
            </th>
            <th class="text-right">Date</th>
            <th class="text-right">Action</th>
        `;
    } else {
        // Hide bulk controls for non-orders levels
        const bulkControlsDiv = document.getElementById('bulk-order-controls');
        if (bulkControlsDiv) {
            bulkControlsDiv.innerHTML = '';
            bulkControlsDiv.style.display = 'none';
        }
        
        thead.innerHTML = `
            <th>Category</th>
            <th class="text-right" style="min-width: 80px;">
                <div>Total</div>
                <div style="font-size: 10px; font-weight: normal; color: #6b7280;">Qty</div>
            </th>
            <th class="text-right" style="min-width: 70px;">
                <div style="color: #059669;">Lean</div>
            </th>
            <th class="text-right" style="min-width: 70px;">
                <div style="color: #dc2626;">Non-Lean</div>
            </th>
            <th class="text-right" style="min-width: 80px;">
                <div>Processing</div>
            </th>
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
            
            const totalQty = parseFloat(item.total_quantity || 0);
            const leanQty = parseFloat(item.lean_quantity || 0);
            const nonLeanQty = parseFloat(item.non_lean_quantity || 0);
            const processingQty = parseFloat(item.processing_quantity || 0);
            // ⭐ Prepared items are now excluded from query - no need to track preparing_quantity
            
            return `
                <tr>
                    <td style="text-align: center;">
                        <input type="checkbox" class="orderCheckbox" data-order-id="${item.order_id}" style="width: 18px; height: 18px; cursor: pointer; accent-color: #10b981;" onchange="updateSelectedOrdersCount()">
                    </td>
                    <td>
                        <span class="text-blue-600 hover:text-blue-800 font-semibold cursor-pointer" onclick="viewOrderDetails(${item.order_id})" title="Click to view order details">
                            ${escapeHtml(item.group_name)}
                        </span>
                    </td>
                    <td>
                        ${customerName}
                    </td>
                    <td>
                        ${getStatusChip(item.order_status)}
                    </td>
                    <td class="text-right">
                        <div style="font-size: 16px; font-weight: 700; color: #111827;">${totalQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        <div style="font-size: 16px; font-weight: 700; color: #059669;">${leanQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        <div style="font-size: 16px; font-weight: 700; color: #dc2626;">${nonLeanQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        <div style="font-size: 14px; font-weight: 600; color: #1e40af;">${processingQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        ${item.order_date ? new Date(item.order_date).toLocaleDateString() : '-'}
                    </td>
                    <td class="text-right">
                        <button onclick="viewOrderDetails(${item.order_id})" class="action-btn secondary" style="padding: 0.375rem 0.75rem; font-size: 13px;">
                            View Order
                        </button>
                    </td>
                </tr>
            `;
        } else {
            // Category levels: show drill-down
            const canDrillDown = summary.has_next_level && item.group_name;
            const totalQty = parseFloat(item.total_quantity || 0);
            const leanQty = parseFloat(item.lean_quantity || 0);
            const nonLeanQty = parseFloat(item.non_lean_quantity || 0);
            const processingQty = parseFloat(item.processing_quantity || 0);
            // ⭐ Prepared items are now excluded from query - no need to track preparing_quantity
            
            return `
                <tr>
                    <td>
                        ${canDrillDown ? 
                            `<span class="drill-btn" onclick="drillDown('${escapeHtml(item.group_name)}', ${item.product_ids ? '`' + (String(item.product_ids)).replace(/`/g,'\\`') + '`' : (item.product_id !== undefined ? (item.product_id ?? 'null') : 'null')})">
                                <span class="icon">▶</span>
                                <strong>${escapeHtml(item.group_name || 'Unknown')}</strong>
                            </span>` :
                            `<strong>${escapeHtml(item.group_name || 'Unknown')}</strong>`
                        }
                    </td>
                    <td class="text-right">
                        <div style="font-size: 18px; font-weight: 700; color: #111827;">${totalQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        <div style="font-size: 18px; font-weight: 700; color: #059669;">${leanQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        <div style="font-size: 18px; font-weight: 700; color: #dc2626;">${nonLeanQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        <div style="font-size: 15px; font-weight: 600; color: #1e40af;">${processingQty.toLocaleString()}</div>
                    </td>
                    <td class="text-right">
                        ${item.order_count ? item.order_count.toLocaleString() : '-'}
                    </td>
                    <td class="text-right">
                        ${canDrillDown ? 
                            `<button onclick="drillDown('${escapeHtml(item.group_name)}', ${item.product_ids ? '`' + (String(item.product_ids)).replace(/`/g,'\\`') + '`' : (item.product_id !== undefined ? (item.product_id ?? 'null') : 'null')})" class="action-btn secondary" style="padding: 0.375rem 0.75rem; font-size: 13px;">
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
function drillDown(groupName, productIdsOrSingle = null) {
    const currentField = window.openQtyState.hierarchy[window.openQtyState.currentLevel];
    
    // Add to breadcrumbs
    window.openQtyState.breadcrumbs.push({
        field: currentField,
        value: groupName,
        label: groupName,
        product_id: Array.isArray(productIdsOrSingle) ? null : productIdsOrSingle,
        product_ids: Array.isArray(productIdsOrSingle) ? productIdsOrSingle : (typeof productIdsOrSingle === 'string' && productIdsOrSingle.includes(',') ? productIdsOrSingle.split(',').map(id => parseInt(id)) : null)
    });
    
    // Update filters
    window.openQtyState.filters[currentField] = groupName;
    // If we're drilling down from product level, also carry product_ids to keep filters consistent
    if (currentField === 'product_name') {
        const ids = (Array.isArray(productIdsOrSingle) ? productIdsOrSingle : (typeof productIdsOrSingle === 'string' ? productIdsOrSingle.split(',').map(id => parseInt(id)) : (productIdsOrSingle ? [productIdsOrSingle] : [])));
        if (ids && ids.length > 0) {
            window.openQtyState.filters['product_ids'] = ids.join(',');
        }
    }
    
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
        if (bc.field === 'product_name') {
            if (bc.product_ids && bc.product_ids.length > 0) {
                window.openQtyState.filters['product_ids'] = bc.product_ids.join(',');
            } else if (bc.product_id) {
                window.openQtyState.filters['product_id'] = bc.product_id;
            }
        }
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

// Save hierarchy to database (global setting - Taimur role only)
async function saveHierarchy() {
    if (!window.canEditSettings) {
        alert('Only Taimur role can modify these settings.');
        return false;
    }
    
    try {
        const response = await fetch('/orders/open-quantities/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                hierarchy_levels: window.openQtyState.hierarchy
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            console.log('Hierarchy saved successfully:', window.openQtyState.hierarchy);
            // Show subtle success message
            showToast('Settings saved successfully for all users!', 'success');
            return true;
        } else {
            console.error('Failed to save hierarchy:', data.message);
            alert('Failed to save settings: ' + data.message);
            return false;
        }
    } catch (e) {
        console.error('Failed to save hierarchy:', e);
        alert('Error saving settings. Please try again.');
        return false;
    }
}

function resetHierarchy() {
    if (!window.canEditSettings) {
        alert('Only Taimur role can modify these settings.');
        return;
    }
    
    window.openQtyState.hierarchy = [...window.defaultHierarchy];
    window.openQtyState.currentLevel = 0;
    window.openQtyState.breadcrumbs = [];
    window.openQtyState.filters = {};
    saveHierarchy();
    updateHierarchyDisplay();
    loadData();
}

// Simple toast notification
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        background: ${type === 'success' ? '#10b981' : '#3b82f6'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        font-size: 14px;
        font-weight: 500;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
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

// Add hierarchy level (Taimur role only)
function addHierarchyLevel(field) {
    if (!window.canEditSettings) {
        alert('Only Taimur role can modify hierarchy levels.');
        document.getElementById('add-level-dropdown').style.display = 'none';
        return;
    }
    
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

// Remove hierarchy level (Taimur role only)
function removeHierarchyLevel(index) {
    if (!window.canEditSettings) {
        alert('Only Taimur role can modify hierarchy levels.');
        return;
    }
    
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

// Handle drag start (Taimur role only)
function handleDragStart(event) {
    if (!window.canEditSettings) {
        event.preventDefault();
        alert('Only Taimur role can reorder hierarchy levels.');
        return;
    }
    
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

// ==================== BULK ORDER OPERATIONS ====================

// Toggle select all orders
function toggleSelectAllOrders() {
    const selectAll = document.getElementById('selectAllOrders') || document.getElementById('selectAllOrdersHeader');
    const checkboxes = document.querySelectorAll('.orderCheckbox');
    const isChecked = selectAll.checked;
    
    checkboxes.forEach(cb => {
        cb.checked = isChecked;
    });
    
    // Sync both checkboxes
    if (document.getElementById('selectAllOrders')) {
        document.getElementById('selectAllOrders').checked = isChecked;
    }
    if (document.getElementById('selectAllOrdersHeader')) {
        document.getElementById('selectAllOrdersHeader').checked = isChecked;
    }
    
    updateSelectedOrdersCount();
}

// Update selected orders count display
function updateSelectedOrdersCount() {
    const checkboxes = document.querySelectorAll('.orderCheckbox:checked');
    const count = checkboxes.length;
    const countDisplay = document.getElementById('selectedOrdersCount');
    
    if (countDisplay) {
        if (count > 0) {
            countDisplay.textContent = `${count} order${count !== 1 ? 's' : ''} selected`;
            countDisplay.style.color = '#10b981';
            countDisplay.style.fontWeight = '600';
        } else {
            countDisplay.textContent = '';
        }
    }
    
    // Update "select all" checkbox state
    const totalCheckboxes = document.querySelectorAll('.orderCheckbox').length;
    const selectAllCheckboxes = [
        document.getElementById('selectAllOrders'),
        document.getElementById('selectAllOrdersHeader')
    ].filter(Boolean);
    
    selectAllCheckboxes.forEach(cb => {
        cb.checked = count === totalCheckboxes && count > 0;
        cb.indeterminate = count > 0 && count < totalCheckboxes;
    });
}

// Get selected order IDs
function getSelectedOrderIds() {
    const checkboxes = document.querySelectorAll('.orderCheckbox:checked');
    const ids = [];
    checkboxes.forEach(cb => {
        const orderId = cb.getAttribute('data-order-id');
        if (orderId) {
            ids.push(parseInt(orderId));
        }
    });
    return ids;
}

// Mark selected orders as prepared (marks all line items in those orders)
function markSelectedOrdersAsPrepared() {
    const selectedIds = getSelectedOrderIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one order');
        return;
    }
    
    if (!confirm(`Mark all items in ${selectedIds.length} order(s) as prepared?`)) {
        return;
    }
    
    // Show loading state
    const button = event.target;
    const originalText = button.innerHTML;
    button.innerHTML = '⏳ Updating...';
    button.disabled = true;
    
    // Call API to bulk update (we'll update all line items for selected orders)
    fetch('/orders/bulk-mark-prepared', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            order_ids: selectedIds,
            preparation_status: 'preparing'
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => {
                throw new Error(err.message || `HTTP error! status: ${response.status}`);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert(`Updated ${data.total_updated} item(s) in ${data.orders_updated} order(s) to Prepared status`);
            // Reload data to show updated status
            loadData();
            // Clear selections
            document.querySelectorAll('.orderCheckbox').forEach(cb => cb.checked = false);
            updateSelectedOrdersCount();
        } else {
            alert('Failed to update: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error updating orders:', error);
        alert('Error updating orders: ' + error.message);
    })
    .finally(() => {
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

// Clear selected orders preparation status
function clearSelectedOrdersStatus() {
    const selectedIds = getSelectedOrderIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one order');
        return;
    }
    
    if (!confirm(`Clear preparation status for all items in ${selectedIds.length} order(s)?`)) {
        return;
    }
    
    // Show loading state
    const button = event.target;
    const originalText = button.innerHTML;
    button.innerHTML = '⏳ Clearing...';
    button.disabled = true;
    
    // Call API to bulk clear status
    fetch('/orders/bulk-mark-prepared', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            order_ids: selectedIds,
            preparation_status: null
        })
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => {
                throw new Error(err.message || `HTTP error! status: ${response.status}`);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert(`Cleared status for ${data.total_updated} item(s) in ${data.orders_updated} order(s)`);
            // Reload data to show updated status
            loadData();
            // Clear selections
            document.querySelectorAll('.orderCheckbox').forEach(cb => cb.checked = false);
            updateSelectedOrdersCount();
        } else {
            alert('Failed to clear status: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error clearing status:', error);
        alert('Error clearing status: ' + error.message);
    })
    .finally(() => {
        button.innerHTML = originalText;
        button.disabled = false;
    });
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

// Save status settings (Taimur role only)
async function saveStatusSettings() {
    if (!window.canEditSettings) {
        alert('Only Taimur role can modify these settings.');
        closeStatusSettings();
        return;
    }
    
    const checkboxes = document.querySelectorAll('.status-checkbox');
    const excluded = [];
    
    checkboxes.forEach(cb => {
        if (!cb.checked) {
            excluded.push(cb.dataset.status);
        }
    });
    
    try {
        const response = await fetch('/orders/open-quantities/settings', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                excluded_statuses: excluded
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Save to state
            window.openQtyState.excludedStatuses = excluded;
            
            // Update indicator
            updateStatusFilterIndicator();
            
            // Close modal
            closeStatusSettings();
            
            // Reload data
            window.openQtyState.cachedData.clear();
            loadData();
            
            // Show success message
            showToast('Status filters saved successfully for all users!', 'success');
            console.log('Status filter settings saved:', excluded);
        } else {
            alert('Failed to save status filters: ' + data.message);
        }
    } catch (error) {
        console.error('Error saving status filters:', error);
        alert('Error saving status filters. Please try again.');
    }
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

// ==================== ORDER DETAILS MODAL FUNCTIONS ====================

// Close modal function
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

// Format date helper
function formatDate(dateString) {
    if (!dateString) return '';
    try {
        let cleanDate = dateString;
        
        if (dateString.includes('T')) {
            const [datePart, timePart] = dateString.split('T');
            const [year, month, day] = datePart.split('-');
            const timeOnly = timePart.split('.')[0];
            const [hour, minute] = timeOnly.split(':');
            
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const monthName = monthNames[parseInt(month, 10) - 1];
            cleanDate = day + ' ' + monthName + ' ' + year + ', ' + hour + ':' + minute;
        } else if (dateString.includes(' ')) {
            const [datePart, timePart] = dateString.split(' ');
            const [year, month, day] = datePart.split('-');
            const [hour, minute] = timePart.split(':');
            
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const monthName = monthNames[parseInt(month, 10) - 1];
            
            cleanDate = day + ' ' + monthName + ' ' + year + ', ' + hour + ':' + minute;
        }
        
        return cleanDate;
    } catch (error) {
        console.error('Date parsing error:', error, 'for date:', dateString);
        return dateString;
    }
}

// Format currency helper
function formatCurrency(amount, currency = 'PKR') {
    const num = isNaN(parseFloat(amount)) ? 0 : parseFloat(amount);
    return currency + ' ' + num.toFixed(2);
}

// Normalize payment method for display
function normalizePaymentMethodDisplay(paymentMethod) {
    if (!paymentMethod) return 'Cash';
    
    const method = paymentMethod.toLowerCase().trim();
    
    const displayMap = {
        'cash': 'Cash',
        'cash_on_delivery': 'Cash on Delivery',
        'cod': 'Cash on Delivery',
        'bank_transfer': 'Bank Transfer',
        'direct_bank_transfer': 'Bank Transfer',
        'bacs': 'Bank Transfer',
        'card': 'Card Payment',
        'credit_card': 'Card Payment',
        'debit_card': 'Card Payment',
        'online': 'Online Payment',
        'online_payment': 'Online Payment',
        'paypal': 'Online Payment',
        'stripe': 'Online Payment',
        'razorpay': 'Online Payment'
    };
    
    if (!displayMap[method]) {
        if (method.includes('bank') || method.includes('transfer')) {
            return 'Bank Transfer';
        } else if (method.includes('cash') || method.includes('cod')) {
            return 'Cash';
        } else if (method.includes('card') || method.includes('visa') || method.includes('master')) {
            return 'Card Payment';
        } else if (method.includes('online') || method.includes('paypal') || method.includes('stripe')) {
            return 'Online Payment';
        }
    }
    
    return displayMap[method] || 'Cash';
}

// View Order Details (simplified - view only, no edit capabilities)
function viewOrderDetails(orderId) {
    console.log('View order details clicked for order:', orderId);
    const modal = document.getElementById('viewOrderModal');
    const content = document.getElementById('viewOrderContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch order details via AJAX
    fetch('/orders/' + orderId, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const order = data.order;
            
            // Attach discounts to order if they're at root level
            if (!order.discounts && data.discounts) {
                order.discounts = data.discounts;
            }
            
            // Attach verified_location to order if it's at root level
            if (!order.verified_location && data.verified_location) {
                order.verified_location = data.verified_location;
            }
            
            // Build HTML
            let html = '<div>';
            
            // Invoice Header
            html += '<div style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb; margin-bottom: 20px;">';
            html += '<div>';
            html += '<h2 style="font-size: 24px; font-weight: bold; color: #111827; margin: 0;">Invoice #' + (order.order_number || order.id) + '</h2>';
            html += '<p style="font-size: 14px; color: #6b7280; margin: 8px 0 0 0;">Date: ' + formatDate(order.order_date) + '</p>';
            html += '</div>';
            html += '<div style="text-align: right;">';
            const sourceStyle = order.external_source === 'shopify' ? 'background-color: #dcfce7; color: #166534;' : 'background-color: #fed7aa; color: #9a3412;';
            html += '<span style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 20px; font-size: 14px; font-weight: 500; ' + sourceStyle + '">';
            html += (order.external_source || 'manual').toUpperCase();
            html += '</span>';
            html += '<p style="font-size: 24px; font-weight: bold; color: #2563eb; margin: 8px 0 0 0;">' + formatCurrency(order.total_price, order.currency) + '</p>';
            html += '</div>';
            html += '</div>';
            
            // Order Details Section
            html += '<div style="padding: 20px; background-color: #f9fafb; border-radius: 8px; margin: 20px 0;">';
            html += '<h3 style="margin: 0 0 16px 0; color: #111827;">Order Details</h3>';
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';
            html += '<div>';
            
            // Build customer name
            var customerName = order.name || 'N/A';
            if ((!order.name || order.name === 'N/A') && (order.address_first_name || order.address_last_name)) {
                customerName = ((order.address_first_name || '') + ' ' + (order.address_last_name || '')).trim();
            }
            
            // Build address
            var addressParts = [];
            if (order.address_line1) addressParts.push(order.address_line1);
            if (order.address_line2) addressParts.push(order.address_line2);
            if (order.address_city) addressParts.push(order.address_city);
            if (order.address_province) addressParts.push(order.address_province);
            var fullAddress = addressParts.length > 0 ? addressParts.join(', ') : 'N/A';
            
            html += '<p><strong>Customer:</strong> ' + escapeHtml(customerName) + '</p>';
            html += '<p><strong>Address:</strong> ' + escapeHtml(fullAddress) + '</p>';
            html += '<p><strong>Phone:</strong> ' + escapeHtml(order.address_phone || order.customer_phone || 'N/A') + '</p>';
            
            // Verified location if available
            if (order.verified_location) {
                html += '<div style="margin-top: 12px; padding: 10px; background-color: #f0fdf4; border-radius: 6px; border: 1px solid #10b981;">';
                html += '<strong style="color: #059669; font-size: 13px;">✅ Verified Location</strong>';
                if (order.verified_location.url) {
                    html += '<p style="margin: 4px 0; font-size: 12px;">';
                    html += '<a href="' + order.verified_location.url + '" target="_blank" style="color: #3b82f6; text-decoration: none; font-weight: 500;">';
                    html += '📍 Open in Google Maps';
                    html += '</a>';
                    html += '</p>';
                } else if (order.verified_location.latitude && order.verified_location.longitude) {
                    html += '<p style="margin: 4px 0; font-size: 11px; font-family: monospace; color: #059669;">';
                    html += order.verified_location.latitude + ', ' + order.verified_location.longitude;
                    html += '</p>';
                    if (order.verified_location.google_maps_url) {
                        html += '<p style="margin: 4px 0; font-size: 12px;">';
                        html += '<a href="' + order.verified_location.google_maps_url + '" target="_blank" style="color: #3b82f6; text-decoration: none; font-weight: 500;">';
                        html += '📍 Open in Google Maps';
                        html += '</a>';
                        html += '</p>';
                    }
                }
                html += '</div>';
            }
            
            html += '</div>';
            html += '<div>';
            html += '<p><strong>Status:</strong> ' + escapeHtml(order.order_status || 'N/A') + '</p>';
            html += '<p><strong>Payment Method:</strong> ' + normalizePaymentMethodDisplay(order.payment_method) + '</p>';
            html += '<p><strong>Total:</strong> ' + formatCurrency(order.total_price, order.currency) + '</p>';
            html += '<p><strong>Items:</strong> ' + (order.line_items ? order.line_items.length : 0) + '</p>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            
            // Line Items Section
            var items = (order.line_items && Array.isArray(order.line_items)) ? order.line_items : [];
            html += '<div style="padding: 20px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; margin: 0 0 20px 0;">';
            html += '<h3 style="margin: 0 0 16px 0; color: #111827;">Line Items</h3>';
            
            if (items.length > 0) {
                html += '<div style="overflow-x: auto;">';
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr>';
                html += '<th style="text-align:left; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Item</th>';
                html += '<th style="text-align:right; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Qty</th>';
                html += '<th style="text-align:right; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Unit</th>';
                html += '<th style="text-align:right; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Total</th>';
                html += '</tr></thead>';
                html += '<tbody>';
                var itemsSubtotal = 0;
                for (var i = 0; i < items.length; i++) {
                    var it = items[i] || {};
                    var name = (it.name || it.title || 'Item');
                    var qty = parseFloat(it.quantity || 0);
                    var unit = parseFloat((it.unit_price != null ? it.unit_price : (it.price != null ? it.price : 0)));
                    var lineTotal = parseFloat(it.line_total || 0);
                    if (!lineTotal || lineTotal === 0) {
                        lineTotal = qty * unit;
                    }
                    if (!isFinite(qty)) qty = 0;
                    if (!isFinite(unit)) unit = 0;
                    if (!isFinite(lineTotal)) lineTotal = 0;
                    itemsSubtotal += lineTotal;
                    
                    html += '<tr>';
                    html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6;">' + escapeHtml(name) + '</td>';
                    html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right;">' + qty + '</td>';
                    html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right;">' + formatCurrency(unit, order.currency) + '</td>';
                    html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right; font-weight:600;">' + formatCurrency(lineTotal, order.currency) + '</td>';
                    html += '</tr>';
                }
                html += '</tbody>';
                html += '<tfoot>';
                html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Subtotal</td><td style="padding: 8px; text-align:right; font-weight:600;">' + formatCurrency(itemsSubtotal, order.currency) + '</td></tr>';
                
                // Show discount breakdown if available
                if (order.discounts && order.discounts.length > 0) {
                    order.discounts.forEach(function(discount) {
                        html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">' + escapeHtml(discount.discount_title) + '</td><td style="padding: 8px; text-align:right;">-' + formatCurrency(discount.discount_amount, order.currency) + '</td></tr>';
                    });
                } else if (order.discount_total) {
                    const discountLabel = order.coupon_code ? 'Discount (' + escapeHtml(order.coupon_code) + ')' : 'Discount';
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">' + discountLabel + '</td><td style="padding: 8px; text-align:right;">-' + formatCurrency(order.discount_total, order.currency) + '</td></tr>';
                }
                
                if (order.shipping_total) {
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Shipping</td><td style="padding: 8px; text-align:right;">' + formatCurrency(order.shipping_total, order.currency) + '</td></tr>';
                }
                if (order.tip_amount && order.tip_amount > 0) {
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Tip</td><td style="padding: 8px; text-align:right;">' + formatCurrency(order.tip_amount, order.currency) + '</td></tr>';
                }
                html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#111827; font-weight:700;">Total</td><td style="padding: 8px; text-align:right; font-weight:700;">' + formatCurrency(order.total_price, order.currency) + '</td></tr>';
                html += '</tfoot>';
                html += '</table>';
                html += '</div>';
            } else {
                html += '<div style="text-align:center; color:#6b7280; padding: 10px 0;">No line items</div>';
            }
            html += '</div>';

            // Order Notes Section (if notes exist)
            if (order.note && order.note.trim() !== '') {
                html += '<div style="padding: 20px; background-color: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; margin: 20px 0 0 0;">';
                html += '<h3 style="margin: 0 0 12px 0; color: #111827; font-size: 16px;">Order Notes</h3>';
                html += '<div style="background-color: white; padding: 12px; border-radius: 6px; border-left: 4px solid #3b82f6;">';
                html += '<p style="margin: 0; color: #374151; line-height: 1.5; white-space: pre-wrap;">' + escapeHtml(order.note) + '</p>';
                html += '</div>';
                html += '</div>';
            }

            // Packet Tracking Section (if packet data exists)
            if (order.expected_packets || order.actual_packets) {
                html += '<div style="padding: 20px; background-color: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; margin: 20px 0 0 0;">';
                html += '<h3 style="margin: 0 0 12px 0; color: #92400e; font-size: 16px; display: flex; align-items: center; gap: 8px;"><span>📦</span> Packet Tracking</h3>';
                html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';
                html += '<div style="background-color: white; padding: 12px; border-radius: 6px;">';
                html += '<div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">Expected Packets (Manager)</div>';
                html += '<div style="font-size: 24px; font-weight: 700; color: #111827;">' + (order.expected_packets || '-') + '</div>';
                html += '</div>';
                html += '<div style="background-color: white; padding: 12px; border-radius: 6px;">';
                html += '<div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">Actual Packets Delivered (Rider)</div>';
                html += '<div style="font-size: 24px; font-weight: 700; color: #111827;">' + (order.actual_packets || '-') + '</div>';
                if (order.expected_packets && order.actual_packets) {
                    if (order.expected_packets != order.actual_packets) {
                        html += '<div style="margin-top: 8px; padding: 6px 10px; background-color: #fee2e2; color: #dc2626; border-radius: 4px; font-size: 12px; font-weight: 600;">⚠️ Mismatch Detected</div>';
                    } else {
                        html += '<div style="margin-top: 8px; padding: 6px 10px; background-color: #d1fae5; color: #059669; border-radius: 4px; font-size: 12px; font-weight: 600;">✅ Verified</div>';
                    }
                }
                html += '</div>';
                html += '</div>';
                html += '</div>';
            }

            html += '</div>';
            
            content.innerHTML = html;
        } else {
            content.innerHTML = `
                <div class="text-center py-8">
                    <div class="text-red-600 mb-4">
                        <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Error Loading Order</h3>
                    <p class="text-gray-500">Unable to load order details</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching order details:', error);
        content.innerHTML = `
            <div class="text-center py-8">
                <div class="text-red-600 mb-4">
                    <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Network Error</h3>
                <p class="text-gray-500">Unable to connect to server. Please try again.</p>
            </div>
        `;
    });
}
</script>
@endpush

