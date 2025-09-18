{{-- resources/views/auth/login.blade.php --}}

@extends('layouts.app')

@section('title', 'Orders')

@push('styles')
<style>
/* Modern Professional Orders Page Styles */
.orders-table-container {
    /* Custom scrollbar for better UX */
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f8fafc;
}

.orders-table-container::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.orders-table-container::-webkit-scrollbar-track {
    background: #f8fafc;
    border-radius: 6px;
}

.orders-table-container::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #cbd5e1, #94a3b8);
    border-radius: 6px;
    transition: all 0.2s ease;
}

.orders-table-container::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #94a3b8, #64748b);
}

/* Modern table row hover effects */
.orders-table-container tbody tr {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.orders-table-container tbody tr:hover {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05), 0 2px 4px rgba(0, 0, 0, 0.08);
    transform: translateY(-1px);
    position: relative;
    z-index: 1;
}

/* Professional sticky header */
.orders-table-container thead {
    position: sticky;
    top: 0;
    z-index: 20;
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    backdrop-filter: blur(10px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.orders-table-container thead th {
    padding: 16px 20px;
    font-weight: 600;
    font-size: 13px;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}

/* Modern table styling */
.orders-table-container table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

/* Enhanced table cell styling */
.orders-table-container td {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    font-size: 14px;
    line-height: 1.5;
}

.orders-table-container tbody tr:last-child td {
    border-bottom: none;
}

/* Professional text styles */
.table-text-primary {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.4;
}

.table-text-secondary {
    font-size: 13px;
    color: #64748b;
    line-height: 1.3;
}

.table-text-small {
    font-size: 12px;
    color: #94a3b8;
    line-height: 1.2;
}

/* Status badges with modern styling */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.status-pending {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border: 1px solid #f59e0b;
}

.status-completed {
    background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    color: #065f46;
    border: 1px solid #10b981;
}

.status-processing {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1e40af;
    border: 1px solid #3b82f6;
}

.status-cancelled {
    background: linear-gradient(135deg, #fee2e2, #fecaca);
    color: #991b1b;
    border: 1px solid #ef4444;
}

/* Better text sizing and spacing */
.table-text-primary {
    font-size: 14px;
    font-weight: 500;
    color: #1f2937;
    line-height: 1.4;
}

.table-text-secondary {
    font-size: 13px;
    color: #6b7280;
    line-height: 1.3;
}

.table-text-small {
    font-size: 12px;
    color: #9ca3af;
    line-height: 1.2;
}

/* Smooth transitions for all interactive elements */
.transition-all-smooth {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Enhanced focus states */
input:focus, select:focus, button:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Loading animation enhancement */
@keyframes pulse-subtle {
    0%, 100% { opacity: 0.8; }
    50% { opacity: 0.4; }
}

.loading-pulse {
    animation: pulse-subtle 2s ease-in-out infinite;
}

/* Status badge improvements */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    text-transform: capitalize;
}

/* Mobile responsive adjustments */
@media (max-width: 768px) {
    .sticky-header-mobile {
        position: sticky;
        top: 0;
        z-index: 30;
    }
    
    .compact-mobile-filters {
        flex-direction: column;
        gap: 12px;
    }
    
    .mobile-pagination {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        border-top: 1px solid #e5e7eb;
        padding: 12px 16px;
        z-index: 40;
    }
}

/* Enhanced button hover effects */
.btn-enhanced:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.btn-enhanced:active {
    transform: translateY(0);
}

/* Table cell content improvements */
.table-cell-content {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Improved pagination styling */
.pagination-btn {
    transition: all 0.15s ease;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    font-weight: 500;
}

.pagination-btn:hover:not(:disabled) {
    background: #f8fafc;
    border-color: #cbd5e1;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.pagination-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: #f9fafb;
}

.pagination-btn.active {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
    border-color: #3b82f6;
    box-shadow: 0 1px 3px rgba(59, 130, 246, 0.3);
    font-weight: 600;
}

/* Enhanced backdrop blur for bottom bar */
.bg-white\/95 {
    background-color: rgba(255, 255, 255, 0.95);
}

/* Improved table container shadow */
.orders-table-container {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
}

/* Floating Create Order button - always visible top-right */
.floating-create-order {
    position: fixed;
    top: 112px; /* ensure below global header */
    right: 24px;
    z-index: 200; /* above sticky headers/toolbars */
}
@media (max-width: 1024px) {
    .floating-create-order { top: 96px; right: 16px; }
}
@media (max-width: 768px) {
    /* Hide floating button on small screens to avoid overlay; header button remains */
    .floating-create-order { display: none; }
}

/* Ensure Create Order button is always visible and readable */
.create-order-btn {
    background-color: #10b981; /* emerald */
    color: #ffffff !important;
    border: 1px solid #10b981;
}
.create-order-btn:hover {
    background-color: #059669; /* darker emerald */
}
</style>
@endpush

@section('content')

@if(session('success'))

<div class="kt-container-fixed">
    <div class="kt-alert kt-alert-success mb-5" id="alert_1">

        <div class="kt-alert-title"><span>{{ session('success') }}</span></div>
        <div class="kt-alert-toolbar">
            <div class="kt-alert-actions">
                <button class="kt-alert-close" data-kt-dismiss="#alert_1">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="lucide lucide-x"
                        aria-hidden="true">
                        <path d="M18 6 6 18"></path>
                        <path d="m6 6 12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>




@endif

<!-- Shopify-Inspired Layout -->
<div class="min-h-screen bg-gray-100">
    
    <!-- Shopify-Inspired Header -->
    <div class="bg-white border-b border-gray-200">
        <div class="kt-container-fixed">
            <!-- Compact Header Section -->
            <div class="py-1">
                <div class="flex items-center justify-between mb-1">
                    <h1 class="text-xl font-semibold text-gray-900">{{ $source === 'shopify' ? 'Shopify Orders' : 'Orders' }}</h1>
                </div>
                
                <!-- Tabs and Actions Row -->
                <div class="flex items-center justify-between">
                    <div class="flex space-x-1 bg-gray-100 rounded-lg p-1">
                        @if($source === 'shopify')
                            <!-- Shopify page tabs -->
                            <button onclick="switchShopifyTab('all')" 
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ ($tab ?? 'all') === 'all' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}" 
                               id="tab-all">
                                All Orders
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $shopifyCount }}</span>
                            </button>
                            <button onclick="switchShopifyTab('approvals')" 
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ ($tab ?? 'all') === 'approvals' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}" 
                               id="tab-approvals">
                                Approvals
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $approvalsCount }}</span>
                            </button>
                        @else
                            <!-- Main invoices page tabs -->
                            <a href="#" onclick="return switchToInvoices()" 
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'other' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                                Invoices
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $otherCount }}</span>
                            </a>
                            <button onclick="switchToShopifyApprovals()" 
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'shopify' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                                Shopify Approvals
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $shopifyCount }}</span>
                            </button>
                        @endif
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex items-center gap-3">
                        <button onclick="createNewOrder()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 shadow-sm">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Create order
                        </button>
                        
                        <button onclick="openColumnSettings()" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h2a2 2 0 002-2z"></path>
                            </svg>
                            Columns
                        </button>
                        
                        <button onclick="openImportModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 min-w-[100px]">
                            <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                            </svg>
                            <span class="whitespace-nowrap">Import</span>
                        </button>
                    </div>
                </div>

                <!-- Shopify-Style Search and Filters -->
                <div class="mt-1 space-y-1">
                    <!-- Main Search Bar -->
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" 
                               id="orderSearch" 
                               placeholder="Search orders, customers, or order numbers..." 
                               class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    
                    <!-- Filter Row -->
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center space-x-2">
                            <span class="text-sm font-medium text-gray-700">Filter:</span>
                            
                            <select id="statusFilter" class="block text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 bg-white">
                                <option value="">All status</option>
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="on-hold">On Hold</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                                <option value="refunded">Refunded</option>
                                <option value="failed">Failed</option>
                            </select>
                            
                            <input type="date" 
                                   id="dateFilter" 
                                   class="block text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 bg-white">
                            
                            <button onclick="clearFilters()" 
                                    class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded border border-gray-300" 
                                    title="Clear filters">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
            </div>

    <!-- Shopify-Style Table Container -->
    <div class="kt-container-fixed pt-0 pb-1">
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm">
            <div class="orders-table-container relative" style="height: calc(100vh - 160px); overflow-y: auto;">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0 z-20">
                        <tr id="table-header">
                            <!-- Dynamic headers will be generated by JavaScript -->
                        </tr>
                    </thead>
                    <tbody id="table-body" class="bg-white divide-y divide-gray-200">
                        <!-- Dynamic rows will be generated by JavaScript -->
                    </tbody>
                </table>

                <!-- Loading State -->
                <div id="loading-state" class="hidden">
                    <div class="flex items-center justify-center py-12">
                        <div class="flex items-center space-x-3">
                            <div class="animate-spin rounded-full h-6 w-6 border-2 border-blue-600 border-t-transparent"></div>
                            <span class="text-gray-600 font-medium">Loading orders...</span>
                    </div>
                    </div>
                </div>
                
                <!-- Empty State -->
                <div id="no-results-state" class="hidden">
                    <div class="flex flex-col items-center justify-center py-12 text-gray-500">
                        <svg class="w-12 h-12 mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <p class="text-lg font-medium text-gray-900 mb-2">No orders found</p>
                        <p class="text-sm text-gray-500 mb-4">Try adjusting your search or filter criteria</p>
                        <button onclick="clearFilters()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Clear Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
            <!-- Shopify-Style Pagination -->
            <div class="bg-white border-t border-gray-200 px-6 py-3">
                <div class="flex items-center justify-between">
                    <!-- Left: Show entries -->
                    <div class="flex items-center text-sm text-gray-700">
                        <span class="mr-2">Show</span>
                        <select id="per-page-selector" class="border-gray-300 rounded text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="10" {{ $orders->perPage() == 10 ? 'selected' : '' }}>10</option>
                            <option value="25" {{ $orders->perPage() == 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ $orders->perPage() == 50 ? 'selected' : '' }}>50</option>
                            <option value="100" {{ $orders->perPage() == 100 ? 'selected' : '' }}>100</option>
                        </select>
                        <span class="ml-2">entries</span>
                        <span class="ml-4 font-medium" id="pagination-info">{{ $orders->firstItem() ?? 0 }}-{{ $orders->lastItem() ?? 0 }} of {{ number_format($orders->total()) }}</span>
                    </div>
                
                    <!-- Right: Pagination Navigation -->
                    <div class="flex items-center space-x-1" id="pager-wrap">
                        @if($orders->onFirstPage())
                            <button class="px-3 py-2 text-sm text-gray-400 bg-white border border-gray-300 rounded-md cursor-not-allowed" disabled>
                                Previous
                            </button>
                        @else
                            <a href="{{ $orders->previousPageUrl() }}" class="px-3 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                Previous
                            </a>
                        @endif
                    
                    <div class="flex items-center gap-1" id="numeric-pager">
                        @php
                            $current = $orders->currentPage();
                            $last = $orders->lastPage();
                            $start = max(1, $current - 2);
                            $end = min($last, $current + 2);
                        @endphp
                        
                        @if($current > 3)
                            <a href="{{ $orders->url(1) }}" class="px-3 py-1.5 text-sm pagination-btn">1</a>
                            @if($current > 4)
                                <span class="px-2 py-1.5 text-sm text-gray-400">...</span>
                            @endif
                        @endif
                        
                        @for($page = $start; $page <= $end; $page++)
                            @if ($page == $current)
                                <span class="px-3 py-2 text-sm bg-blue-600 text-white rounded-md font-medium">{{ $page }}</span>
                            @else
                                <a href="{{ $orders->url($page) }}" class="px-3 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">{{ $page }}</a>
                            @endif
                        @endfor
                        
                        @if($current < $last - 2)
                            @if($current < $last - 3)
                                <span class="px-2 py-1.5 text-sm text-gray-400">...</span>
                            @endif
                            <a href="{{ $orders->url($last) }}" class="px-3 py-1.5 text-sm pagination-btn">{{ $last }}</a>
                        @endif
                    </div>
                    
                        @if($orders->hasMorePages())
                            <a href="{{ $orders->nextPageUrl() }}" class="px-3 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                Next
                            </a>
                        @else
                            <button class="px-3 py-2 text-sm text-gray-400 bg-white border border-gray-300 rounded-md cursor-not-allowed" disabled>
                                Next
                            </button>
                        @endif
                </div>
            </div>
        </div>
                        </div>

                        <!-- <div class="flex items-center gap-2 order-2 md:order-1">
                            Show
                            <select class="hidden" data-kt-datatable-size="true" data-kt-select="" name="perpage" data-kt-select-initialized="true">
                                <option value="5" data-kt-select-option-initialized="true">5</option>
                                <option value="10" data-kt-select-option-initialized="true">10</option>
                                <option value="20" data-kt-select-option-initialized="true">20</option>
                                <option value="30" data-kt-select-option-initialized="true">30</option>
                                <option value="50" data-kt-select-option-initialized="true">50</option>
                            </select>
                            <div data-kt-select-wrapper="" class="kt-select-wrapper w-16">
                                <div data-kt-select-display="" class="kt-select-display kt-select" tabindex="0" role="button" data-selected="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select an option">10</div>
                                <div data-kt-select-dropdown="" class="kt-select-dropdown hidden " style="z-index: 105;">
                                    <ul role="listbox" aria-label="Select an option" class="kt-select-options " data-kt-select-options="true">
                                        <li data-kt-select-option="" data-value="5" data-text="5" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">5</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="10" data-text="10" class="kt-select-option selected" role="option" aria-selected="true">
                                            <div class="kt-select-option-text" data-kt-text-container="true">10</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="20" data-text="20" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">20</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="30" data-text="30" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">30</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="50" data-text="50" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">50</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            per page
                        </div>
                        <div class="flex items-center gap-4 order-1 md:order-2">
                            <span data-kt-datatable-info="true">1-10 of 30</span>
                            <div class="kt-datatable-pagination" data-kt-datatable-pagination="true"><button class="kt-datatable-pagination-button kt-datatable-pagination-prev disabled" disabled="">
                                    <svg class="rtl:transform rtl:rotate-180 size-3.5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M8.86501 16.7882V12.8481H21.1459C21.3724 12.8481 21.5897 12.7581 21.7498 12.5979C21.91 12.4378 22 12.2205 22 11.994C22 11.7675 21.91 11.5503 21.7498 11.3901C21.5897 11.2299 21.3724 11.1399 21.1459 11.1399H8.86501V7.2112C8.86628 7.10375 8.83517 6.9984 8.77573 6.90887C8.7163 6.81934 8.63129 6.74978 8.53177 6.70923C8.43225 6.66869 8.32283 6.65904 8.21775 6.68155C8.11267 6.70405 8.0168 6.75766 7.94262 6.83541L2.15981 11.6182C2.1092 11.668 2.06901 11.7274 2.04157 11.7929C2.01413 11.8584 2 11.9287 2 11.9997C2 12.0707 2.01413 12.141 2.04157 12.2065C2.06901 12.272 2.1092 12.3314 2.15981 12.3812L7.94262 17.164C8.0168 17.2417 8.11267 17.2953 8.21775 17.3178C8.32283 17.3403 8.43225 17.3307 8.53177 17.2902C8.63129 17.2496 8.7163 17.18 8.77573 17.0905C8.83517 17.001 8.86628 16.8956 8.86501 16.7882Z" fill="currentColor"></path>
                                    </svg>
                                </button><button class="kt-datatable-pagination-button active disabled" disabled="">1</button><button class="kt-datatable-pagination-button">2</button><button class="kt-datatable-pagination-button">3</button><button class="kt-datatable-pagination-button kt-datatable-pagination-next">
                                    <svg class="rtl:transform rtl:rotate-180 size-3.5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M15.135 7.21144V11.1516H2.85407C2.62756 11.1516 2.41032 11.2415 2.25015 11.4017C2.08998 11.5619 2 11.7791 2 12.0056C2 12.2321 2.08998 12.4494 2.25015 12.6096C2.41032 12.7697 2.62756 12.8597 2.85407 12.8597H15.135V16.7884C15.1337 16.8959 15.1648 17.0012 15.2243 17.0908C15.2837 17.1803 15.3687 17.2499 15.4682 17.2904C15.5677 17.3309 15.6772 17.3406 15.7822 17.3181C15.8873 17.2956 15.9832 17.242 16.0574 17.1642L21.8402 12.3814C21.8908 12.3316 21.931 12.2722 21.9584 12.2067C21.9859 12.1412 22 12.0709 22 11.9999C22 11.9289 21.9859 11.8586 21.9584 11.7931C21.931 11.7276 21.8908 11.6683 21.8402 11.6185L16.0574 6.83565C15.9832 6.75791 15.8873 6.70429 15.7822 6.68179C15.6772 6.65929 15.5677 6.66893 15.4682 6.70948C15.3687 6.75002 15.2837 6.81959 15.2243 6.90911C15.1648 6.99864 15.1337 7.10399 15.135 7.21144Z" fill="currentColor"></path>
                                    </svg>
                                </button></div>
                        </div>
                    </div> -->


                    </div>
                </div>
            </div>
        </div>
    </div>

</div>




</div>

<!-- View Order Modal -->
<div id="viewOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Invoice Details</h3>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button id="viewInvoiceBtn" onclick="viewInvoice()" style="background-color: #2563eb; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                        <polyline points="14,2 14,8 20,8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10,9 9,9 8,9"/>
                    </svg>
                    View Invoice
                </button>
                <div style="display: flex; gap: 8px;">
                    <button onclick="downloadInvoicePdf()" 
                            style="background: #dc2626; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 6px;">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10,9 9,9 8,9"/>
                        </svg>
                        📄 PDF
                    </button>
                    <button onclick="downloadInvoiceImage()" 
                            style="background: #059669; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 6px;">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7,10 12,15 17,10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        📷 Image
                    </button>
                </div>
                <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div id="viewOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Edit Order Modal -->
<div id="editOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Edit Invoice</h3>
            <div style="display: flex; align-items: center; gap: 8px;">
                <a id="popoutOrderBtn" href="#" onclick="openEditInTab()" 
                        style="background: none; border: 1px solid #d1d5db; border-radius: 4px; padding: 6px 12px; cursor: pointer; color: #374151; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                        title="Open in new tab">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                        <polyline points="15,3 21,3 21,9"></polyline>
                        <line x1="10" y1="14" x2="21" y2="3"></line>
                    </svg>
                    Pop Out
                </a>
                <button onclick="closeModal('editOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div id="editOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Column Settings Modal -->
<div id="columnSettingsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999; overflow-y: auto;">
    <div style="display: flex; min-height: 100%; align-items: center; justify-content: center; padding: 20px;">
        <div style="background: white; border-radius: 8px; width: 100%; max-width: 600px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
            <!-- Modal Header (Fixed) -->
            <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
                <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Customize Table Columns</h3>
                <button onclick="closeModal('columnSettingsModal')" style="padding: 4px; border: none; background: none; cursor: pointer; color: #6b7280;">
                    <svg style="width: 20px; height: 20px;" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Modal Content (Scrollable) -->
            <div style="padding: 20px; overflow-y: auto; flex: 1;">
                <p style="color: #6b7280; margin-bottom: 20px; font-size: 14px;">
                    Drag and drop to reorder columns. Toggle visibility using checkboxes.
                </p>
                
                <div id="columnList" style="background: #f9fafb; border-radius: 8px; padding: 16px; max-height: 400px; overflow-y: auto;">
                    <!-- Column items will be generated here -->
                </div>
            </div>
            
            <!-- Modal Footer (Fixed) -->
            <div style="padding: 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
                <button onclick="resetColumns()" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    Reset to Default
                </button>
                <div style="display: flex; gap: 12px;">
                    <button onclick="closeModal('columnSettingsModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button onclick="applyColumnSettings()" style="padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        Apply Changes
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Import Orders Modal -->
<div id="importOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Import Historical Orders</h3>
            <button onclick="closeModal('importOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 20px;">
            <form id="importOrderForm" action="{{ route('orders.importOrders') }}" method="POST">
                @csrf
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Select Source</label>
                    <select name="source" required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                        <option value="">Choose a source...</option>
                        <option value="Shopify">Shopify</option>
                        <option value="WooCommerce">WooCommerce</option>
                    </select>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">From Date</label>
                        <input type="date" name="from_date" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">To Date</label>
                        <input type="date" name="to_date" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" required>
                    </div>
                </div>

                <div style="background-color: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: start;">
                        <div style="color: #3b82f6; margin-right: 8px;">
                            <svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div>
                            <h4 style="font-size: 14px; font-weight: 500; color: #1e40af; margin: 0 0 4px 0;">Import Information</h4>
                            <p style="font-size: 12px; color: #1e40af; margin: 0;">This will fetch and import orders from the selected platform within the specified date range. Existing orders will be updated if found.</p>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" onclick="closeModal('importOrderModal')" 
                            style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button type="submit" 
                            style="padding: 10px 20px; background-color: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                        </svg>
                        Import Orders
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
<script>
// Modal functions
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Format date helper
function formatDate(dateString) {
    if (!dateString) return '';
    try {
        // Handle ISO format: "2025-09-09T17:41:03.000000Z"
        let cleanDate = dateString;
        
        if (dateString.includes('T')) {
            // Extract date and time parts
            const [datePart, timePart] = dateString.split('T');
            const [year, month, day] = datePart.split('-');
            const timeOnly = timePart.split('.')[0]; // Remove microseconds
            const [hour, minute] = timeOnly.split(':');
            
            // Format as: "09 Sep 2025, 17:41"
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const monthName = monthNames[parseInt(month, 10) - 1];
            cleanDate = day + ' ' + monthName + ' ' + year + ', ' + hour + ':' + minute;
        } else if (dateString.includes(' ')) {
            // Handle format: "2025-09-09 17:41:03"
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

// Add formatDateLocal function for consistency with customer page
window.formatDateLocal = function(dateString) {
    if (!dateString) return 'N/A';
    
    try {
        // Handle different date formats
        let date;
        if (dateString.includes('T')) {
            // ISO format
            date = new Date(dateString);
        } else if (dateString.includes(' ')) {
            // MySQL datetime format
            const [datePart, timePart] = dateString.split(' ');
            const [year, month, day] = datePart.split('-');
            const [hour, minute, second] = timePart.split(':');
            date = new Date(year, month - 1, day, hour, minute, second);
        } else {
            // Fallback
            date = new Date(dateString);
        }
        
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (error) {
        console.error('Date formatting error:', error);
        return dateString;
    }
};

// Format currency helper
function formatCurrency(amount, currency = 'PKR') {
    const num = isNaN(parseFloat(amount)) ? 0 : parseFloat(amount);
    return currency + ' ' + num.toFixed(2);
}

// View Order Details
function viewOrderDetails(orderId) {
    console.log('View order details clicked for order:', orderId);
    currentOrderId = orderId; // Store the order ID for invoice viewing
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
            console.log('Order data:', order);
            
            // Build HTML using string concatenation to avoid Blade conflicts
            let html = '<div>';
            
            // Invoice Header
            html += '<div style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb; margin-bottom: 20px;">';
            html += '<div>';
            html += '<h2 style="font-size: 24px; font-weight: bold; color: #111827; margin: 0;">Invoice #' + (order.order_number || order.id) + '</h2>';
            html += '<p style="font-size: 14px; color: #6b7280; margin: 8px 0 0 0;">Date: ' + formatDate(order.created_at) + '</p>';
            html += '</div>';
            html += '<div style="text-align: right;">';
            const sourceStyle = order.external_source === 'shopify' ? 'background-color: #dcfce7; color: #166534;' : 'background-color: #fed7aa; color: #9a3412;';
            html += '<span style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 20px; font-size: 14px; font-weight: 500; ' + sourceStyle + '">';
            html += (order.external_source || 'manual').toUpperCase();
            html += '</span>';
            html += '<p style="font-size: 24px; font-weight: bold; color: #2563eb; margin: 8px 0 0 0;">' + formatCurrency(order.total_price, order.currency) + '</p>';
            html += '</div>';
            html += '</div>';

            
            // Add basic order details (avoiding template literal conflicts)
            html += '<div style="padding: 20px; background-color: #f9fafb; border-radius: 8px; margin: 20px 0;">';
            html += '<h3 style="margin: 0 0 16px 0; color: #111827;">Order Details</h3>';
            html += '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">';
            html += '<div>';
            // Build customer name from address fields if order.name is not available
            var customerName = order.name || 'N/A';
            if ((!order.name || order.name === 'N/A') && (order.address_first_name || order.address_last_name)) {
                customerName = ((order.address_first_name || '') + ' ' + (order.address_last_name || '')).trim();
            }
            
            // Build address from address fields (excluding postal code and country)
            var addressParts = [];
            if (order.address_line1) addressParts.push(order.address_line1);
            if (order.address_line2) addressParts.push(order.address_line2);
            if (order.address_city) addressParts.push(order.address_city);
            if (order.address_province) addressParts.push(order.address_province);
            var fullAddress = addressParts.length > 0 ? addressParts.join(', ') : 'N/A';
            
            // Make customer name clickable if customer_id exists
            var customerDisplay = customerName;
            if (order.customer_id && order.customer_id !== 'N/A' && order.customer_id !== null) {
                customerDisplay = '<span onclick="openCustomerInNewTab(' + order.customer_id + ')" class="text-blue-600 hover:text-blue-800 hover:underline font-medium cursor-pointer" title="View customer details">' + customerName + '</span>';
            }
            
            html += '<p><strong>Customer:</strong> ' + customerDisplay + '</p>';
            html += '<p><strong>Address:</strong> ' + fullAddress + '</p>';
            html += '<p><strong>Phone:</strong> ' + (order.address_phone || order.customer_phone || 'N/A') + '</p>';
            html += '</div>';
            html += '<div>';
            html += '<p><strong>Status:</strong> ' + (order.order_status || 'N/A') + '</p>';
            html += '<p><strong>Total:</strong> ' + formatCurrency(order.total_price, order.currency) + '</p>';
            html += '<p><strong>Items:</strong> ' + (order.line_items ? order.line_items.length : 0) + '</p>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            
            // Line Items (read-only)
            var items = (order.line_items && Array.isArray(order.line_items)) ? order.line_items : [];
            html += '<div style="padding: 20px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; margin: 0 0 20px 0;">';
            html += '<h3 style="margin: 0 0 16px 0; color: #111827;">Line Items</h3>';
            if (items.length > 0) {
                html += '<div style="overflow-x: auto;">';
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr>' +
                        '<th style="text-align:left; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Item</th>' +
                        '<th style="text-align:right; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Qty</th>' +
                        '<th style="text-align:right; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Unit</th>' +
                        '<th style="text-align:right; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Total</th>' +
                        '</tr></thead>';
                html += '<tbody>';
                var itemsSubtotal = 0;
                for (var i = 0; i < items.length; i++) {
                    var it = items[i] || {};
                    var name = (it.name || it.title || 'Item');
                    var qty = parseFloat(it.quantity || 0);
                    var unit = parseFloat((it.unit_price != null ? it.unit_price : (it.price != null ? it.price : 0)));
                    // Use the same calculation logic as the invoice: prefer line_total, fallback to qty * unit_price
                    var lineTotal = parseFloat(it.line_total || 0);
                    if (!lineTotal || lineTotal === 0) {
                        lineTotal = qty * unit;
                    }
                    if (!isFinite(qty)) qty = 0;
                    if (!isFinite(unit)) unit = 0;
                    if (!isFinite(lineTotal)) lineTotal = 0;
                    itemsSubtotal += lineTotal;
                    html += '<tr>' +
                        '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6;">' + name + '</td>' +
                        '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right;">' + qty + '</td>' +
                        '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right;">' + formatCurrency(unit, order.currency) + '</td>' +
                        '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right; font-weight:600;">' + formatCurrency(lineTotal, order.currency) + '</td>' +
                    '</tr>';
                }
                html += '</tbody>';
                html += '<tfoot>';
                // Use calculated subtotal from line items only (exclude shipping/fees)
                html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Subtotal</td><td style="padding: 8px; text-align:right; font-weight:600;">' + formatCurrency(itemsSubtotal, order.currency) + '</td></tr>';
                if (order.discount_total) {
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Discount</td><td style="padding: 8px; text-align:right;">-' + formatCurrency(order.discount_total, order.currency) + '</td></tr>';
                }
                if (order.shipping_total) {
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Shipping</td><td style="padding: 8px; text-align:right;">' + formatCurrency(order.shipping_total, order.currency) + '</td></tr>';
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
                html += '<p style="margin: 0; color: #374151; line-height: 1.5; white-space: pre-wrap;">' + (order.note || '') + '</p>';
                html += '</div>';
                html += '</div>';
            }

            html += '</div>';
            html += '</div>';
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

// View Invoice
let currentOrderId = null;

function viewInvoice() {
    if (currentOrderId) {
        window.open('/orders/' + currentOrderId + '/invoice', '_blank');
    } else {
        console.error('No order ID available for invoice');
    }
}

function downloadInvoicePdf() {
    if (currentOrderId) {
        // Open invoice page with auto PDF download enabled
        window.open('/orders/' + currentOrderId + '/invoice?auto_pdf=1', '_blank');
    } else {
        console.error('No order ID available for PDF download');
    }
}

function downloadInvoiceImage() {
    if (currentOrderId) {
        // Direct image download
        window.open('/orders/' + currentOrderId + '/invoice?download_image=1', '_blank');
    } else {
        console.error('No order ID available for image download');
    }
}

// Open customer details in new tab
function openCustomerInNewTab(customerId) {
    // Open customers page in new tab and trigger the customer modal
    const customerUrl = '/customers?view_customer=' + customerId;
    window.open(customerUrl, '_blank');
}

// Edit Order Details
function convertOrder(orderId) {
    if (!confirm('Are you sure you want to convert this Shopify order to a webapp invoice? This will create a duplicate order with source "webapp" and mark the original as converted.')) {
        return;
    }
    
    fetch(`/orders/${orderId}/convert`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Order converted successfully! New invoice order #${data.converted_order.order_number} created.`);
            // Refresh the page to show updated data
            window.location.reload();
        } else {
            alert('Error converting order: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error converting order:', error);
        alert('Error converting order. Please try again.');
    });
}

function ignoreOrder(orderId) {
    if (!confirm('Are you sure you want to ignore this Shopify order? This will mark it as ignored and no invoice will be created.')) {
        return;
    }
    
    fetch(`/orders/${orderId}/ignore`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Order marked as ignored successfully!');
            // Refresh the page to show updated data
            window.location.reload();
        } else {
            alert('Error ignoring order: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error ignoring order:', error);
        alert('Error ignoring order. Please try again.');
    });
}

function editOrderDetails(orderId) {
    console.log('Edit order details clicked for order:', orderId);
    // Ensure the pop-out in-tab handler has the order id
    try { currentOrderId = orderId; } catch (e) {}
    const modal = document.getElementById('editOrderModal');
    const content = document.getElementById('editOrderContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch order details for editing
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
            loadEditForm(order);
        } else {
            showEditError('Error loading order: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error fetching order for editing:', error);
        showEditError('Network error. Please try again.');
    });
}

function loadEditForm(order) {
    const content = document.getElementById('editOrderContent');
    content.innerHTML = `
        <form id="editOrderForm" style="padding: 0;">
            <input type="hidden" name="order_id" value="${order.id}">
            
            <!-- Order Information -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Information</h4>
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Order Number</label>
                            <input type="text" name="order_number" value="${order.order_number || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Order Status</label>
                            <select name="order_status" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                <option value="pending" ${order.order_status === 'pending' ? 'selected' : ''}>Pending</option>
                                <option value="processing" ${order.order_status === 'processing' ? 'selected' : ''}>Processing</option>
                                <option value="completed" ${order.order_status === 'completed' ? 'selected' : ''}>Completed</option>
                                <option value="on-hold" ${order.order_status === 'on-hold' ? 'selected' : ''}>On Hold</option>
                                <option value="cancelled" ${order.order_status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                                <option value="refunded" ${order.order_status === 'refunded' ? 'selected' : ''}>Refunded</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Order Date & Time</label>
                            <input type="datetime-local" name="order_date" required value="${order.order_date ? order.order_date.replace(' ', 'T').slice(0, 16) : getCurrentLocalDateTime()}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Contact Email</label>
                            <input type="email" name="contact_email" value="${order.contact_email || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Customer Name</label>
                            <input type="text" name="customer_name" value="${order.name || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                    </div>
                </div>

                <!-- Customer Information -->
                <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Customer Details</h4>
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">First Name</label>
                                <input type="text" name="address_first_name" value="${order.address_first_name || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Last Name</label>
                                <input type="text" name="address_last_name" value="${order.address_last_name || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Email</label>
                            <input type="email" name="address_email" value="${order.address_email || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Phone</label>
                            <input type="text" name="address_phone" value="${order.address_phone || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Address Line 1</label>
                                <input type="text" name="address_line1" value="${order.address_line1 || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Address Line 2</label>
                                <input type="text" name="address_line2" value="${order.address_line2 || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">City</label>
                                <input type="text" name="address_city" value="${order.address_city || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Province</label>
                                <input type="text" name="address_province" value="${order.address_province || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Postal Code</label>
                                <input type="text" name="address_postal_code" value="${order.address_postal_code || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Country</label>
                                <input type="text" name="address_country" value="${order.address_country || 'Pakistan'}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Payment Method</label>
                                <select name="payment_method" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                    <option value="">Select Payment Method</option>
                                    <option value="cash_on_delivery" ${order.payment_method === 'cash_on_delivery' ? 'selected' : ''}>Cash on Delivery</option>
                                    <option value="bank_transfer" ${order.payment_method === 'bank_transfer' ? 'selected' : ''}>Bank Transfer</option>
                                    <option value="card" ${order.payment_method === 'card' ? 'selected' : ''}>Card Payment</option>
                                    <option value="online" ${order.payment_method === 'online' ? 'selected' : ''}>Online Payment</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notes Section -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Order Notes</label>
                <textarea name="note" rows="3" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="Order notes...">${order.note || ''}</textarea>
            </div>

            <!-- Line Items Section -->
            <div style="background-color: #fefefe; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0;">Line Items</h4>
                    <button type="button" onclick="(window.addLineItem||addLineItem)()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                        + Add Item
                    </button>
                </div>
                <div id="lineItemsContainer" style="padding: 16px;">
                    ${order.line_items && order.line_items.length > 0 ? 
                        order.line_items.map((item, index) => `
                        <div class="line-item" data-index="${index}" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Item Name</label>
                                <input type="text" name="items[${index}][name]" value="${item.name || item.title || ''}" 
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <input type="hidden" name="items[${index}][id]" value="${item.id || ''}">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
                                <input type="number" step="0.01" name="items[${index}][quantity]" value="${item.quantity || 1}" min="0.01"
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${index})">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
                                <input type="number" step="0.01" name="items[${index}][unit_price]" value="${item.unit_price || item.price || 0}" 
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${index})">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
                                <span class="line-total" style="display: block; padding: 6px 8px; background-color: #e5e7eb; border-radius: 4px; font-size: 14px; font-weight: 500;">${formatCurrency(item.line_total || ((item.unit_price || item.price || 0) * (item.quantity || 0)), order.currency)}</span>
                            </div>
                            <div>
                                <button type="button" onclick="removeLineItem(${index})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    ×
                                </button>
                            </div>
                        </div>
                        `).join('') : 
                        '<div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>'
                    }
                </div>
            </div>

            <!-- Order Totals -->
            <div style="background-color: #eff6ff; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Totals</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Subtotal</label>
                        <input type="number" step="0.01" name="subtotal_price" value="${order.subtotal_price || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" readonly>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Discount</label>
                        <div style="display: flex; gap: 8px;">
                            <div style="flex: 1; position: relative;">
                                <input type="text" id="couponSearch" name="coupon_code" value="${order.coupon_code || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" 
                                       placeholder="Search coupon code..." onkeyup="searchCoupons(this.value)" onfocus="showCouponDropdown()" onblur="hideCouponDropdown()">
                                <div id="couponDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                            </div>
                            <input type="number" step="0.01" name="discount_total" value="${order.discount_total || 0}" 
                                   style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateOrderTotal()" placeholder="Discount amount">
                        </div>
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Shipping</label>
                        <input type="number" step="0.01" name="shipping_total" value="${order.shipping_total || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateOrderTotal()" placeholder="Enter shipping cost">
                    </div>
                </div>
                <div style="padding-top: 12px; border-top: 1px solid #e5e7eb;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Total Price</label>
                        <input type="number" step="0.01" name="total_price" value="${order.total_price || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-weight: 600; background-color: #f3f4f6;" readonly>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <button type="button" onclick="closeModal('editOrderModal')" 
                        style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" 
                        style="padding: 10px 20px; background-color: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    Save
                </button>
                <button type="button" onclick="saveAndCloseOrder(${order.id})" 
                        style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    Save & Close
                </button>
            </div>
        </form>
    `;
    
    // Add form submission handler
    document.getElementById('editOrderForm').addEventListener('submit', function(e) {
        e.preventDefault();
        saveOrderChanges(order.id);
    });
}

function showEditError(message) {
    const content = document.getElementById('editOrderContent');
    content.innerHTML = `
        <div class="text-center py-8">
            <div class="text-red-600 mb-4">
                <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Error</h3>
            <p class="text-gray-500">${message}</p>
        </div>
    `;
}

function showSuccessMessage(message, duration = 3000) {
    // Remove any existing success message
    const existingMessage = document.querySelector('.order-success-message');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    // Create success message element
    const successDiv = document.createElement('div');
    successDiv.className = 'order-success-message fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 transition-all duration-300';
    successDiv.innerHTML = `
        <div class="flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            ${message}
        </div>
    `;
    
    document.body.appendChild(successDiv);
    
    // Auto-remove after duration
    setTimeout(() => {
        if (successDiv) {
            successDiv.style.opacity = '0';
            successDiv.style.transform = 'translateX(100%)';
            setTimeout(() => successDiv.remove(), 300);
        }
    }, duration);
}

// Line item management functions
let lineItemIndex = 1000; // Start high to avoid conflicts with existing items

function addLineItem() {
    const container = document.getElementById('lineItemsContainer');
    const emptyMessage = container.querySelector('div[style*="text-align: center"]');
    if (emptyMessage) {
        emptyMessage.remove();
    }
    
    const newItem = document.createElement('div');
    newItem.className = 'line-item';
    newItem.setAttribute('data-index', lineItemIndex);
    newItem.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;';
    
    newItem.innerHTML = `
        <div style="position: relative;">
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Item Name</label>
            <input type="text" name="items[${lineItemIndex}][name]" value="" 
                   style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                   onkeyup="handleProductSearch(this, ${lineItemIndex}, event)" 
                   onkeydown="handleProductKeydown(this, ${lineItemIndex}, event)"
                   onfocus="showProductDropdown(${lineItemIndex})"
                   onblur="hideProductDropdown(${lineItemIndex})"
                   placeholder="Type to search products..."
                   autocomplete="off">
            <div id="productDropdown_${lineItemIndex}" class="product-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
            <input type="hidden" name="items[${lineItemIndex}][id]" value="">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
            <input type="number" step="0.01" name="items[${lineItemIndex}][quantity]" value="1" min="0.01"
                   style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${lineItemIndex})">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
            <input type="number" step="0.01" name="items[${lineItemIndex}][unit_price]" value="0" 
                   style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${lineItemIndex})">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
            <span class="line-total" style="display: block; padding: 6px 8px; background-color: #e5e7eb; border-radius: 4px; font-size: 14px; font-weight: 500;">PKR 0.00</span>
        </div>
        <div>
            <button type="button" onclick="removeLineItem(${lineItemIndex})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                ×
            </button>
        </div>
    `;
    
    container.appendChild(newItem);
    lineItemIndex++;
    updateSubtotal();
}

function removeLineItem(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (item) {
        item.remove();
        updateSubtotal();
        
        // Check if no items left
        const container = document.getElementById('lineItemsContainer');
        const items = container.querySelectorAll('.line-item');
        if (items.length === 0) {
            container.innerHTML = '<div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>';
        }
    }
}

function updateLineTotal(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (item) {
        const quantity = parseFloat(item.querySelector(`input[name="items[${index}][quantity]"]`).value) || 0;
        const price = parseFloat(item.querySelector(`input[name="items[${index}][unit_price]"]`).value) || 0;
        const total = quantity * price;
        
        const totalSpan = item.querySelector('.line-total');
        totalSpan.textContent = formatCurrency(total, 'PKR');
        
        updateSubtotal();
    }
}

function updateSubtotal() {
    let subtotal = 0;
    const items = document.querySelectorAll('.line-item');
    
    items.forEach(item => {
        const index = item.getAttribute('data-index');
        const quantity = parseFloat(item.querySelector(`input[name*="[quantity]"]`).value) || 0;
        const price = parseFloat(item.querySelector(`input[name*="[unit_price]"]`).value) || 0;
        subtotal += quantity * price;
    });
    
    const subtotalInput = document.querySelector('input[name="subtotal_price"]');
    if (subtotalInput) {
        subtotalInput.value = subtotal.toFixed(2);
        updateOrderTotal();
    }
}

function saveOrderChanges(orderId) {
    const form = document.getElementById('editOrderForm');
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    submitBtn.textContent = 'Saving...';
    submitBtn.disabled = true;
    
    // Collect line items
    const items = [];
    document.querySelectorAll('.line-item').forEach((item) => {
        const name = item.querySelector('input[name*="[name]"]')?.value;
        const quantity = parseFloat(item.querySelector('input[name*="[quantity]"]')?.value) || 0;
        const unitPrice = parseFloat(item.querySelector('input[name*="[unit_price]"]')?.value) || 0;
        
        if (name && quantity > 0 && unitPrice >= 0) {
            items.push({
                name: name,
                quantity: quantity,
                unit_price: unitPrice,
                line_total: quantity * unitPrice
            });
        }
    });
    
    // Prepare data for update (matching the existing update endpoint structure)
    const rawOrderDate = formData.get('order_date');
    const formattedOrderDate = rawOrderDate ? rawOrderDate.replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00';
    
    const orderData = {
        order_status: formData.get('order_status'),
        order_date: formattedOrderDate,
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        discount_total: parseFloat(formData.get('discount_total')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        coupon_code: formData.get('coupon_code'),
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        items: items,
        // Address fields
        address_first_name: formData.get('address_first_name'),
        address_last_name: formData.get('address_last_name'),
        address_email: formData.get('address_email'),
        address_phone: formData.get('address_phone'),
        address_line1: formData.get('address_line1'),
        address_line2: formData.get('address_line2'),
        address_city: formData.get('address_city'),
        address_province: formData.get('address_province'),
        address_postal_code: formData.get('address_postal_code'),
        address_country: formData.get('address_country')
    };
    
    // Submit to existing update endpoint
    fetch(`/orders/${orderId}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify(orderData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccessMessage('Order updated successfully!');
            submitBtn.textContent = 'Save';
            submitBtn.disabled = false;
            // Keep modal open for regular save - no page reload
        } else {
            alert('Error updating order: ' + (data.message || 'Unknown error'));
            submitBtn.textContent = 'Save';
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error updating order:', error);
        alert('Error updating order. Please try again.');
        submitBtn.textContent = 'Save Changes';
        submitBtn.disabled = false;
    });
}

function saveAndCloseOrder(orderId) {
    const form = document.getElementById('editOrderForm');
    const formData = new FormData(form);
    const saveAndCloseBtn = event.target;
    
    saveAndCloseBtn.textContent = 'Saving...';
    saveAndCloseBtn.disabled = true;
    
    // Collect line items (reuse same logic as saveOrderChanges)
    const items = [];
    document.querySelectorAll('.line-item').forEach((item) => {
        const name = item.querySelector('input[name*="[name]"]')?.value;
        const quantity = parseFloat(item.querySelector('input[name*="[quantity]"]')?.value) || 0;
        const unitPrice = parseFloat(item.querySelector('input[name*="[unit_price]"]')?.value) || 0;
        
        if (name && quantity > 0 && unitPrice >= 0) {
            items.push({
                name: name,
                quantity: quantity,
                unit_price: unitPrice,
                line_total: quantity * unitPrice
            });
        }
    });
    
    // Prepare data for update (same as saveOrderChanges)
    const rawOrderDate = formData.get('order_date');
    const formattedOrderDate = rawOrderDate ? rawOrderDate.replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00';
    
    const orderData = {
        order_status: formData.get('order_status'),
        order_date: formattedOrderDate,
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        discount_total: parseFloat(formData.get('discount_total')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        coupon_code: formData.get('coupon_code'),
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        items: items,
        // Address fields
        address_first_name: formData.get('address_first_name'),
        address_last_name: formData.get('address_last_name'),
        address_email: formData.get('address_email'),
        address_phone: formData.get('address_phone'),
        address_line1: formData.get('address_line1'),
        address_line2: formData.get('address_line2'),
        address_city: formData.get('address_city'),
        address_province: formData.get('address_province'),
        address_postal_code: formData.get('address_postal_code'),
        address_country: formData.get('address_country')
    };
    
    // Submit to existing update endpoint
    fetch(`/orders/${orderId}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify(orderData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccessMessage('Order updated successfully!');
            closeModal('editOrderModal');
            // If this editor is running in its own tab, close the tab; otherwise just refresh
            if (window.opener && !window.opener.closed) {
                window.opener.location.reload();
                window.close();
            } else {
                window.location.reload();
            }
        } else {
            alert('Error updating order: ' + (data.message || 'Unknown error'));
            saveAndCloseBtn.textContent = 'Save & Close';
            saveAndCloseBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error updating order:', error);
        alert('Error updating order. Please try again.');
        saveAndCloseBtn.textContent = 'Save & Close';
        saveAndCloseBtn.disabled = false;
    });
}

function popoutOrder() {
    const modalContent = document.getElementById('editOrderContent');
    const modalTitle = document.querySelector('#editOrderModal h3').textContent;
    
    if (!modalContent) {
        alert('No order data to open in new tab');
        return;
    }
    
    // Create a simple popup with basic functionality
    const newWindow = window.open('', '_blank', 'width=1000,height=800,scrollbars=yes,resizable=yes');
    
    if (!newWindow) {
        alert('Popup blocked. Please allow popups for this site.');
        return;
    }
    
    // Create a functional HTML page with editing capabilities
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const currentOrigin = window.location.origin;
    
    // Build the complete HTML as a string first
    let htmlContent = '<!DOCTYPE html>';
    htmlContent += '<html lang="en">';
    htmlContent += '<head>';
    htmlContent += '<meta charset="UTF-8">';
    htmlContent += '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    htmlContent += '<meta name="csrf-token" content="' + csrfToken + '">';
    htmlContent += '<title>' + modalTitle + '</title>';
    htmlContent += '<style>';
    htmlContent += 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 20px; background-color: #f9fafb; }';
    htmlContent += '.container { max-width: 900px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; }';
    htmlContent += '.header { padding: 20px; border-bottom: 1px solid #e5e7eb; background: #f8fafc; }';
    htmlContent += '.content { padding: 20px; }';
    htmlContent += '.order-success-message { position: fixed; top: 20px; right: 20px; background-color: #10b981; color: white; padding: 12px 16px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; transition: all 0.3s ease; }';
    htmlContent += '</style>';
    htmlContent += '</head>';
    htmlContent += '<body>';
    htmlContent += '<div class="container">';
    htmlContent += '<div class="header">';
    htmlContent += '<h1 style="margin: 0; font-size: 24px; font-weight: 600;">' + modalTitle + '</h1>';
    htmlContent += '</div>';
    htmlContent += '<div class="content">';
    htmlContent += modalContent.innerHTML;
    htmlContent += '</div>';
    htmlContent += '</div>';
    htmlContent += '</body>';
    htmlContent += '</html>';
    
    newWindow.document.write(htmlContent);
    newWindow.document.close();
    
    // Early guard: intercept clicks on "+ Add Item" buttons before inline onclick executes
    try {
        const doc = newWindow.document;
        doc.addEventListener('click', function(ev) {
            const btn = ev.target && (ev.target.matches('button[onclick*="addLineItem"]') ? ev.target : (ev.target.closest ? ev.target.closest('button[onclick*="addLineItem"]') : null));
            if (btn) {
                // Prevent inline onclick from firing
                ev.preventDefault();
                ev.stopPropagation();
                if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
                // Defer to allow DOM to settle, then call (will be bound in onload below)
                setTimeout(() => {
                    if (typeof newWindow.addLineItem === 'function') {
                        newWindow.addLineItem();
                    } else {
                        // Fallback minimal handler: add an empty line and let user fill
                        const container = doc.getElementById('lineItemsContainer');
                        if (container) {
                            const div = doc.createElement('div');
                            div.className = 'line-item';
                            const idx = Date.now();
                            div.setAttribute('data-index', idx);
                            div.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;';
                            div.innerHTML = `
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Item Name</label>
                                    <input type="text" name="items[${idx}][name]" placeholder="Product name..." style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
                                    <input type="number" step="0.01" name="items[${idx}][quantity]" value="1" min="0.01" style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
                                    <input type="number" step="0.01" name="items[${idx}][unit_price]" value="0" style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
                                    <span class="line-total" style="display: block; padding: 6px 8px; background-color: #e5e7eb; border-radius: 4px; font-size: 14px; font-weight: 500;">PKR 0.00</span>
                                </div>
                                <div>
                                    <button type="button" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">Remove</button>
                                </div>`;
                            container.appendChild(div);
                        }
                    }
                }, 0);
            }
        }, true);
    } catch (e) {}

    // Add functionality to the new window after it loads
    newWindow.onload = function() {
        // Copy essential functions to the new window
        newWindow.formatCurrency = formatCurrency;
        newWindow.getCurrentLocalDateTime = getCurrentLocalDateTime;
        
        // Copy enhanced product search functions
        newWindow.searchProducts = searchProducts;
        newWindow.handleProductSearch = handleProductSearch;
        newWindow.handleProductKeydown = handleProductKeydown;
        newWindow.updateDropdownHighlight = updateDropdownHighlight;
        newWindow.showProductResults = showProductResults;
        newWindow.selectProduct = selectProduct;
        newWindow.hideProductDropdown = hideProductDropdown;
        newWindow.autoAddNextLineItem = autoAddNextLineItem;
        newWindow.showProductDropdown = showProductDropdown;
        
        // Copy modal and UI functions
        newWindow.closeModal = function(modalId) {
            // In pop-out window, just close the window instead of hiding modal
            if (modalId) {
                newWindow.close();
            }
        };
        newWindow.searchCoupons = searchCoupons;
        newWindow.showCouponDropdown = showCouponDropdown;
        newWindow.hideCouponDropdown = hideCouponDropdown;
        
        // Copy global variables for keyboard navigation
        newWindow.currentDropdownIndex = -1;
        newWindow.currentLineItemIndex = -1;
        newWindow.currentProducts = [];
        newWindow.couponSearchTimeout = null;
        newWindow.updateLineTotal = updateLineTotal;
        newWindow.updateOrderSubtotal = updateOrderSubtotal;
        newWindow.showSuccessMessage = showSuccessMessage;
        newWindow.updateOrderTotal = updateOrderTotal;
        
        // Line item management functions
        newWindow.lineItemIndex = 1000; // Initialize line item index
        
        newWindow.addLineItem = function() {
            const container = newWindow.document.getElementById('lineItemsContainer');
            const emptyMessage = container.querySelector('div[style*="text-align: center"]');
            if (emptyMessage) {
                emptyMessage.remove();
            }
            
            const newItem = newWindow.document.createElement('div');
            newItem.className = 'line-item';
            newItem.setAttribute('data-index', newWindow.lineItemIndex);
            newItem.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;';
            
            newItem.innerHTML = `
                <div style="position: relative;">
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Item Name</label>
                    <input type="text" name="items[${newWindow.lineItemIndex}][name]" value="" 
                           style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                           onkeyup="handleProductSearch(this, ${newWindow.lineItemIndex}, event)" 
                           onkeydown="handleProductKeydown(this, ${newWindow.lineItemIndex}, event)"
                           onfocus="showProductDropdown(${newWindow.lineItemIndex})"
                           onblur="hideProductDropdown(${newWindow.lineItemIndex})"
                           placeholder="Type to search products..."
                           autocomplete="off">
                    <div id="productDropdown_${newWindow.lineItemIndex}" class="product-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                    <input type="hidden" name="items[${newWindow.lineItemIndex}][id]" value="">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
                    <input type="number" step="0.01" name="items[${newWindow.lineItemIndex}][quantity]" value="1" min="0.01"
                           style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${newWindow.lineItemIndex})">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
                    <input type="number" step="0.01" name="items[${newWindow.lineItemIndex}][unit_price]" value="0" 
                           style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${newWindow.lineItemIndex})">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
                    <span class="line-total" style="display: block; padding: 6px 8px; background-color: #e5e7eb; border-radius: 4px; font-size: 14px; font-weight: 500;">PKR 0.00</span>
                </div>
                <div>
                    <button type="button" onclick="removeLineItem(${newWindow.lineItemIndex})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                        Remove
                    </button>
                </div>
            `;
            
            container.appendChild(newItem);
            newWindow.lineItemIndex++;
        };
        try { newWindow.window.addLineItem = newWindow.addLineItem; } catch (e) {}
        
        newWindow.removeLineItem = function(index) {
            const item = newWindow.document.querySelector(`.line-item[data-index="${index}"]`);
            if (item) {
                item.remove();
                newWindow.updateOrderSubtotal();
                
                // Check if no items left
                const container = newWindow.document.getElementById('lineItemsContainer');
                const items = container.querySelectorAll('.line-item');
                if (items.length === 0) {
                    container.innerHTML = '<div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>';
                }
            }
        };
        try { newWindow.window.removeLineItem = newWindow.removeLineItem; } catch (e) {}

        // Ensure existing "+ Add Item" buttons in copied HTML work in the popout
        try {
            const addItemButtons = newWindow.document.querySelectorAll('button[onclick*="addLineItem"]');
            newWindow.console && newWindow.console.log && newWindow.console.log('Popout: found add buttons', addItemButtons.length);
            addItemButtons.forEach((btn) => {
                btn.addEventListener('click', (ev) => { ev.preventDefault(); newWindow.addLineItem(); });
            });
        } catch (e) {}
        
        // Override save functions for popup behavior
        newWindow.saveOrderChanges = function(orderId) {
            const form = newWindow.document.getElementById('editOrderForm');
            if (!form) return;
            
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            
            if (submitBtn) {
                submitBtn.textContent = 'Saving...';
                submitBtn.disabled = true;
            }
            
            // Collect line items
            const items = [];
            newWindow.document.querySelectorAll('.line-item').forEach((item) => {
                const name = item.querySelector('input[name*="[name]"]')?.value;
                const quantity = parseFloat(item.querySelector('input[name*="[quantity]"]')?.value) || 0;
                const unitPrice = parseFloat(item.querySelector('input[name*="[unit_price]"]')?.value) || 0;
                
                if (name && quantity > 0 && unitPrice >= 0) {
                    items.push({
                        name: name,
                        quantity: quantity,
                        unit_price: unitPrice,
                        line_total: quantity * unitPrice
                    });
                }
            });
            
            const rawOrderDate = formData.get('order_date');
            const formattedOrderDate = rawOrderDate ? rawOrderDate.replace('T', ' ') + ':00' : newWindow.getCurrentLocalDateTime().replace('T', ' ') + ':00';
            
            const orderData = {
                order_status: formData.get('order_status'),
                order_date: formattedOrderDate,
                contact_email: formData.get('contact_email'),
                subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
                discount_total: parseFloat(formData.get('discount_total')) || 0,
                shipping_total: parseFloat(formData.get('shipping_total')) || 0,
                total_price: parseFloat(formData.get('total_price')) || 0,
                coupon_code: formData.get('coupon_code'),
                payment_method: formData.get('payment_method'),
                note: formData.get('note'),
                items: items,
                address_first_name: formData.get('address_first_name'),
                address_last_name: formData.get('address_last_name'),
                address_email: formData.get('address_email'),
                address_phone: formData.get('address_phone'),
                address_line1: formData.get('address_line1'),
                address_line2: formData.get('address_line2'),
                address_city: formData.get('address_city'),
                address_province: formData.get('address_province'),
                address_postal_code: formData.get('address_postal_code'),
                address_country: formData.get('address_country')
            };
            
            fetch(currentOrigin + '/orders/' + orderId, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify(orderData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    newWindow.showSuccessMessage('Order updated successfully!');
                    if (submitBtn) {
                        submitBtn.textContent = 'Save';
                        submitBtn.disabled = false;
                    }
                } else {
                    alert('Error updating order: ' + (data.message || 'Unknown error'));
                    if (submitBtn) {
                        submitBtn.textContent = 'Save';
                        submitBtn.disabled = false;
                    }
                }
            })
            .catch(error => {
                console.error('Error updating order:', error);
                alert('Error updating order. Please try again.');
                if (submitBtn) {
                    submitBtn.textContent = 'Save';
                    submitBtn.disabled = false;
                }
            });
        };
        
        newWindow.saveAndCloseOrder = function(orderId) {
            // Same as saveOrderChanges but closes window on success
            newWindow.saveOrderChanges(orderId);
            // For save and close, we'll close after a short delay
            setTimeout(() => {
                if (window.opener) {
                    window.opener.location.reload();
                }
                newWindow.close();
            }, 2000);
        };
    };
}

// Open edit in a full Orders tab (loads entire app and auto-opens edit modal)
function openEditInTab() {
    if (currentOrderId) {
        const url = '/orders?edit_order_id=' + encodeURIComponent(String(currentOrderId));
        window.open(url, '_blank');
    }
}

// Import modal functions
function openImportModal() {
    const modal = document.getElementById('importOrderModal');
    modal.style.display = 'block';
    
    // Set default dates (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
    
    document.querySelector('input[name="to_date"]').value = today.toISOString().split('T')[0];
    document.querySelector('input[name="from_date"]').value = thirtyDaysAgo.toISOString().split('T')[0];
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('viewOrderModal');
        closeModal('editOrderModal');
        closeModal('importOrderModal');
    }
});

// Close modals when clicking outside - this is now handled by onclick in the backdrop divs

// Debug: Log when script loads
console.log('Order management script loaded');

// Check for preloaded customer on page load
document.addEventListener('DOMContentLoaded', function() {
    const preloadCustomerId = localStorage.getItem('preloadCustomerId');
    if (preloadCustomerId) {
        // Remove from localStorage
        localStorage.removeItem('preloadCustomerId');
        
        // Open create order modal with preloaded customer
        createNewOrderWithCustomer(preloadCustomerId);
    }

    // If opened with an edit_order_id in query string, auto-open edit modal
    try {
        const params = new URLSearchParams(window.location.search);
        const editId = params.get('edit_order_id');
        if (editId) {
            editOrderDetails(editId);
        }
    } catch (e) {}
});

// Debug function to reset columns (can be called from browser console)
window.resetOrderColumns = function() {
    localStorage.removeItem('orderTableColumns');
    location.reload();
};

// Product search functionality
let productSearchTimeout = null;

// Global variables for keyboard navigation
let currentDropdownIndex = -1;
let currentLineItemIndex = -1;
let currentProducts = [];

function searchProducts(input, index) {
    clearTimeout(productSearchTimeout);
    const query = input.value.trim();
    
    if (query.length < 2) {
        hideProductDropdown(index);
        return;
    }
    
    productSearchTimeout = setTimeout(() => {
        fetch(`/api/products/search?q=${encodeURIComponent(query)}&limit=10`, {
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
                showProductResults(data.products, index);
            }
        })
        .catch(error => {
            console.error('Product search error:', error);
        });
    }, 300);
}

// Enhanced search with keyboard navigation support
function handleProductSearch(input, index, event) {
    // Don't search on arrow key presses
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown' || event.key === 'Enter' || event.key === 'Escape') {
        return;
    }
    
    currentLineItemIndex = index;
    searchProducts(input, index);
}

// Handle keyboard navigation in product dropdown
function handleProductKeydown(input, index, event) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (!dropdown || dropdown.style.display === 'none') {
        return;
    }
    
    const items = dropdown.querySelectorAll('[data-product-index]');
    
    switch(event.key) {
        case 'ArrowDown':
            event.preventDefault();
            currentDropdownIndex = Math.min(currentDropdownIndex + 1, items.length - 1);
            updateDropdownHighlight(items);
            break;
            
        case 'ArrowUp':
            event.preventDefault();
            currentDropdownIndex = Math.max(currentDropdownIndex - 1, -1);
            updateDropdownHighlight(items);
            break;
            
        case 'Enter':
            event.preventDefault();
            if (currentDropdownIndex >= 0 && items[currentDropdownIndex]) {
                items[currentDropdownIndex].click();
            }
            break;
            
        case 'Escape':
            event.preventDefault();
            hideProductDropdown(index);
            break;
    }
}

// Update visual highlighting in dropdown
function updateDropdownHighlight(items) {
    items.forEach((item, idx) => {
        if (idx === currentDropdownIndex) {
            item.style.backgroundColor = '#3b82f6';
            item.style.color = 'white';
            item.scrollIntoView({ block: 'nearest' });
        } else {
            item.style.backgroundColor = 'white';
            item.style.color = 'inherit';
        }
    });
}

function showProductResults(products, index) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (!dropdown) return;
    
    // Reset dropdown navigation
    currentDropdownIndex = -1;
    currentProducts = products;
    
    if (products.length === 0) {
        dropdown.innerHTML = '<div style="padding: 8px; color: #6b7280; font-size: 12px;">No products found</div>';
    } else {
        dropdown.innerHTML = products.map((product, idx) => `
            <div onclick="selectProduct(${index}, '${product.id}', '${product.name.replace(/'/g, "\\'")}', ${product.price})" 
                 data-product-index="${idx}"
                 style="padding: 8px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background-color 0.1s;"
                 onmouseover="this.style.backgroundColor='#f9fafb'; currentDropdownIndex=${idx};" 
                 onmouseout="this.style.backgroundColor='white';">
                <div style="font-weight: 500; font-size: 13px;">${product.name}</div>
                <div style="font-size: 11px; color: #6b7280;">
                    ${product.sku ? 'SKU: ' + product.sku + ' | ' : ''}Price: PKR ${product.price}${product.sku ? '' : ' | Stock: ' + (product.inventory || 0)}
                </div>
            </div>
        `).join('');
    }
    
    dropdown.style.display = 'block';
}

function selectProduct(index, productId, productName, price) {
    // Fill in the product details
    const nameInput = document.querySelector(`input[name="items[${index}][name]"]`);
    const priceInput = document.querySelector(`input[name="items[${index}][unit_price]"]`);
    
    if (nameInput) nameInput.value = productName;
    if (priceInput) {
        priceInput.value = price;
        // Make price readonly when selected from product dropdown
        priceInput.readOnly = true;
        priceInput.style.backgroundColor = '#f3f4f6';
        priceInput.style.cursor = 'not-allowed';
        priceInput.setAttribute('data-from-product', 'true');
        priceInput.title = 'Price is set from product catalog and cannot be edited';
    }
    
    // Update the line total
    updateLineTotal(index);
    
    // Hide dropdown
    hideProductDropdown(index);
    
    // Auto-add new line item after a short delay to allow current selection to complete
    setTimeout(() => {
        autoAddNextLineItem();
    }, 100);
}

// Auto-add a new line item if the current one is the last and has content
function autoAddNextLineItem() {
    const container = document.getElementById('lineItemsContainer');
    if (!container) return;
    
    const lineItems = container.querySelectorAll('.line-item');
    if (lineItems.length === 0) return;
    
    const lastItem = lineItems[lineItems.length - 1];
    const lastNameInput = lastItem.querySelector('input[name*="[name]"]');
    
    // Check if the last line item has a product selected
    if (lastNameInput && lastNameInput.value.trim()) {
        // Add new line item using the existing function
        if (typeof addLineItem === 'function') {
            addLineItem();
            
            // Focus on the new line item's name input
            setTimeout(() => {
                const newLineItems = container.querySelectorAll('.line-item');
                if (newLineItems.length > lineItems.length) {
                    const newItem = newLineItems[newLineItems.length - 1];
                    const newNameInput = newItem.querySelector('input[name*="[name]"]');
                    if (newNameInput) {
                        newNameInput.focus();
                    }
                }
            }, 50);
        }
    }
}

// Get current local datetime in format suitable for datetime-local input
function getCurrentLocalDateTime() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Update order total calculations
function updateOrderTotal() {
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
    const discount = parseFloat(document.querySelector('input[name="discount_total"]')?.value) || 0;
    const shipping = parseFloat(document.querySelector('input[name="shipping_total"]')?.value) || 0;
    
    const total = subtotal - discount + shipping;
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

// Coupon search functionality
let couponSearchTimeout;
function searchCoupons(query) {
    clearTimeout(couponSearchTimeout);
    
    couponSearchTimeout = setTimeout(() => {
        if (query.length < 1) {
            document.getElementById('couponDropdown').style.display = 'none';
            return;
        }
        
        fetch(`/coupons/search?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                const dropdown = document.getElementById('couponDropdown');
                
                if (data.success && data.data.length > 0) {
                    dropdown.innerHTML = data.data.map(coupon => `
                        <div style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6;" 
                             onclick="selectCoupon('${coupon.code}', ${coupon.value}, '${coupon.value_type}', ${coupon.minimum_amount || 0})"
                             onmouseover="this.style.backgroundColor='#f3f4f6'" 
                             onmouseout="this.style.backgroundColor='white'">
                            <div style="font-weight: 500; color: #374151;">${coupon.display}</div>
                            ${coupon.minimum_amount ? `<div style="font-size: 12px; color: #6b7280;">Min order: PKR ${coupon.minimum_amount}</div>` : ''}
                        </div>
                    `).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.innerHTML = '<div style="padding: 8px 12px; color: #6b7280;">No coupons found</div>';
                    dropdown.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error searching coupons:', error);
                document.getElementById('couponDropdown').style.display = 'none';
            });
    }, 300);
}

function showCouponDropdown() {
    const query = document.getElementById('couponSearch').value;
    if (query.length > 0) {
        searchCoupons(query);
    }
}

function hideCouponDropdown() {
    setTimeout(() => {
        document.getElementById('couponDropdown').style.display = 'none';
    }, 200);
}

function selectCoupon(code, value, valueType, minimumAmount) {
    // Set coupon code
    document.getElementById('couponSearch').value = code;
    document.querySelector('input[name="coupon_code"]').value = code;
    
    // Calculate discount based on subtotal
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
    let discountAmount = 0;
    
    if (subtotal >= minimumAmount) {
        if (valueType === 'percentage') {
            discountAmount = (subtotal * value) / 100;
        } else {
            discountAmount = value;
        }
    }
    
    // Set discount amount
    document.querySelector('input[name="discount_total"]').value = discountAmount.toFixed(2);
    
    // Update total
    updateOrderTotal();
    
    // Hide dropdown
    document.getElementById('couponDropdown').style.display = 'none';
}

// New order coupon search functionality
let newOrderCouponSearchTimeout;
function searchNewOrderCoupons(query) {
    clearTimeout(newOrderCouponSearchTimeout);
    
    newOrderCouponSearchTimeout = setTimeout(() => {
        if (query.length < 1) {
            document.getElementById('newOrderCouponDropdown').style.display = 'none';
            return;
        }
        
        fetch(`/coupons/search?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                const dropdown = document.getElementById('newOrderCouponDropdown');
                
                if (data.success && data.data.length > 0) {
                    dropdown.innerHTML = data.data.map(coupon => `
                        <div style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6;" 
                             onclick="selectNewOrderCoupon('${coupon.code}', ${coupon.value}, '${coupon.value_type}', ${coupon.minimum_amount || 0})"
                             onmouseover="this.style.backgroundColor='#f3f4f6'" 
                             onmouseout="this.style.backgroundColor='white'">
                            <div style="font-weight: 500; color: #374151;">${coupon.display}</div>
                            ${coupon.minimum_amount ? `<div style="font-size: 12px; color: #6b7280;">Min order: PKR ${coupon.minimum_amount}</div>` : ''}
                        </div>
                    `).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.innerHTML = '<div style="padding: 8px 12px; color: #6b7280;">No coupons found</div>';
                    dropdown.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error searching coupons:', error);
                document.getElementById('newOrderCouponDropdown').style.display = 'none';
            });
    }, 300);
}

function showNewOrderCouponDropdown() {
    const query = document.getElementById('newOrderCouponSearch').value;
    if (query.length > 0) {
        searchNewOrderCoupons(query);
    }
}

function hideNewOrderCouponDropdown() {
    setTimeout(() => {
        document.getElementById('newOrderCouponDropdown').style.display = 'none';
    }, 200);
}

function selectNewOrderCoupon(code, value, valueType, minimumAmount) {
    // Set coupon code
    document.getElementById('newOrderCouponSearch').value = code;
    document.querySelector('input[name="coupon_code"]').value = code;
    
    // Calculate discount based on subtotal
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
    let discountAmount = 0;
    
    if (subtotal >= minimumAmount) {
        if (valueType === 'percentage') {
            discountAmount = (subtotal * value) / 100;
        } else {
            discountAmount = value;
        }
    }
    
    // Set discount amount
    document.querySelector('input[name="discount_total"]').value = discountAmount.toFixed(2);
    
    // Update total
    updateOrderTotal();
    
    // Hide dropdown
    document.getElementById('newOrderCouponDropdown').style.display = 'none';
}

function showProductDropdown(index) {
    // Hide other dropdowns
    document.querySelectorAll('.product-dropdown').forEach(dropdown => {
        if (dropdown.id !== `productDropdown_${index}`) {
            dropdown.style.display = 'none';
        }
    });
}

function hideProductDropdown(index) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (dropdown) {
        setTimeout(() => {
            dropdown.style.display = 'none';
        }, 200);
    }
}

// Create new order with preloaded customer
function createNewOrderWithCustomer(customerId) {
    // Fetch customer data by ID (reuses customers show endpoint that returns JSON)
    fetch(`/customers/${customerId}`)
    .then(response => response.json())
    .then(data => {
        if (data && data.success && data.customer) {
            const customer = data.customer;
            const fullName = [customer.first_name || '', customer.last_name || ''].join(' ').trim() || (customer.name || '');
            
            // Open create order modal
            createNewOrder();
            
            // Switch to existing customer mode and preload data
            setTimeout(() => {
                selectCustomerMode('existing');
                document.getElementById('customerSearch').value = fullName;
                document.getElementById('selectedCustomerId').value = customer.id;
                
                // Auto-fill contact email
                const emailInput = document.querySelector('input[name="contact_email"]');
                if (emailInput && customer.email) {
                    emailInput.value = customer.email;
                }
                
                // Hide the customer selection buttons since customer is already selected
                const existingBtn = document.getElementById('existingCustomerBtn');
                const newBtn = document.getElementById('newCustomerBtn');
                if (existingBtn && newBtn) {
                    existingBtn.style.display = 'none';
                    newBtn.style.display = 'none';
                }
                
                // Add a note showing which customer was preloaded
                const customerSection = document.querySelector('#existingCustomerSection');
                if (customerSection) {
                    const note = document.createElement('div');
                    note.style.cssText = 'margin-top: 8px; padding: 8px; background-color: #ecfdf5; border: 1px solid #d1fae5; border-radius: 4px; font-size: 12px; color: #065f46;';
                    note.innerHTML = `✓ Customer pre-selected from customers page: ${fullName}`;
                    customerSection.appendChild(note);
                }
            }, 100);
        } else {
            // Fallback to regular create order
            createNewOrder();
        }
    })
    .catch(error => {
        console.error('Error loading customer:', error);
        // Fallback to regular create order
        createNewOrder();
    });
}

// DUPLICATE FUNCTION REMOVED - Complete version exists later in the file

// ==================== COLUMN CUSTOMIZATION SYSTEM ====================

const availableColumns = {
    id: { label: 'ID', width: 'w-[60px]', key: 'id' },
    order_number: { label: 'Order #', width: 'min-w-[100px]', key: 'order_number' },
    order_date: { label: 'Order Date', width: 'min-w-[130px]', key: 'order_date' },
    order_status: { label: 'Status', width: 'w-[100px]', key: 'order_status' },
    external_source: { label: 'Source', width: 'w-[100px]', key: 'external_source' },
    external_id: { label: 'External ID', width: 'w-[100px]', key: 'external_id' },
    
    // Customer Info
    customer_name: { label: 'Customer Name', width: 'w-[150px]', key: 'customer_name' },
    contact_email: { label: 'Contact Email', width: 'w-[180px]', key: 'contact_email' },
    customer_phone: { label: 'Customer Phone', width: 'w-[130px]', key: 'customer_phone' },
    
    // Address Info
    address_first_name: { label: 'Address First Name', width: 'w-[150px]', key: 'address_first_name' },
    address_last_name: { label: 'Address Last Name', width: 'w-[150px]', key: 'address_last_name' },
    address_full_name: { label: 'Address Name', width: 'w-[180px]', key: 'address_full_name' },
    address_email: { label: 'Address Email', width: 'w-[180px]', key: 'address_email' },
    address_phone: { label: 'Address Phone', width: 'w-[130px]', key: 'address_phone' },
    address1: { label: 'Address Line 1', width: 'w-[200px]', key: 'address1' },
    address2: { label: 'Address Line 2', width: 'w-[200px]', key: 'address2' },
    address_city: { label: 'City', width: 'w-[120px]', key: 'address_city' },
    address_province: { label: 'Province', width: 'w-[120px]', key: 'address_province' },
    address_country: { label: 'Country', width: 'w-[120px]', key: 'address_country' },
    postal_code: { label: 'Postal Code', width: 'w-[100px]', key: 'postal_code' },
    
    // Financial Info
    currency: { label: 'Currency', width: 'w-[80px]', key: 'currency' },
    subtotal_price: { label: 'Subtotal', width: 'w-[100px]', key: 'subtotal_price' },
    discount_total: { label: 'Discount', width: 'w-[100px]', key: 'discount_total' },
    shipping_total: { label: 'Shipping', width: 'w-[100px]', key: 'shipping_total' },
    total_price: { label: 'Total', width: 'w-[120px]', key: 'total_price' },
    total_weight: { label: 'Weight', width: 'w-[100px]', key: 'total_weight' },
    
    // Payment & Other Info
    payment_method: { label: 'Payment Method', width: 'w-[120px]', key: 'payment_method' },
    coupon_code: { label: 'Coupon Code', width: 'w-[100px]', key: 'coupon_code' },
    note: { label: 'Note', width: 'w-[150px]', key: 'note' },
    created_at: { label: 'Created At', width: 'w-[130px]', key: 'created_at' },
    updated_at: { label: 'Updated At', width: 'w-[130px]', key: 'updated_at' },
    
    // Line Items Count
    line_items_count: { label: 'Items', width: 'w-[80px]', key: 'line_items_count' },
    
    // Actions column
    actions: { label: '{{ $source === "shopify" && ($tab ?? "all") === "approvals" ? "Approve / Ignore" : "Actions" }}', width: 'w-[160px]', key: 'actions', fixed: true }
};

// DUPLICATE SECTION REMOVED - Proper definitions exist later in file

const defaultColumns = [
    { id: 'id', visible: true },
    { id: 'order_number', visible: true },
    { id: 'order_date', visible: true },
    { id: 'order_status', visible: true },
    { id: 'customer_name', visible: true },
    { id: 'contact_email', visible: true },
    { id: 'line_items_count', visible: true },
    { id: 'total_price', visible: true },
    { id: 'payment_method', visible: true },
    { id: 'external_source', visible: true },
    { id: 'actions', visible: true },
    
    // Hidden by default but available
    { id: 'external_id', visible: false },
    { id: 'customer_phone', visible: false },
    
    // Address Fields
    { id: 'address_first_name', visible: false },
    { id: 'address_last_name', visible: false },
    { id: 'address_full_name', visible: false },
    { id: 'address_email', visible: false },
    { id: 'address_phone', visible: false },
    { id: 'address1', visible: false },
    { id: 'address2', visible: false },
    { id: 'address_city', visible: false },
    { id: 'address_province', visible: false },
    { id: 'postal_code', visible: false },
    { id: 'address_country', visible: false },
    { id: 'currency', visible: false },
    { id: 'subtotal_price', visible: false },
    { id: 'discount_total', visible: false },
    { id: 'shipping_total', visible: false },
    { id: 'total_weight', visible: false },
    { id: 'coupon_code', visible: false },
    { id: 'note', visible: false },
    { id: 'created_at', visible: false },
    { id: 'updated_at', visible: false }
];

// Current column settings
let currentColumns = JSON.parse(localStorage.getItem('orderTableColumns')) || defaultColumns;

/* ⚠️ DUPLICATE INITIALIZATION BLOCK - COMMENTED OUT TO PREVENT CONFLICTS
   This block is duplicated below around line 1966 and causes JavaScript errors
   
// Ensure Actions column is always present and visible
function ensureActionsColumn() {
    const hasActions = currentColumns.find(col => col.id === 'actions');
    if (!hasActions) {
        currentColumns.push({ id: 'actions', visible: true });
    } else {
        // Make sure it's visible
        hasActions.visible = true;
    }
}

// Ensure all address fields are present in currentColumns
function ensureAddressFields() {
    const addressFields = [
        'address_first_name', 'address_last_name', 'address_full_name',
        'address1', 'address2', 'postal_code'
    ];
    
    addressFields.forEach(fieldId => {
        const hasField = currentColumns.find(col => col.id === fieldId);
        if (!hasField) {
            // Add missing address field
            currentColumns.push({ id: fieldId, visible: false });
        }
    });
}

// Initialize columns
ensureActionsColumn();
ensureAddressFields();

// Save the updated columns to localStorage
localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));

// Debug: Log current columns after initialization
console.log('Current columns after initialization:', currentColumns);

// Orders data passed from Laravel
window.ordersData = @json($orders->items());
// Track current source/tab dynamically for correct actions rendering
window.currentSource = '{{ $source }}';
window.currentTab = '{{ $tab ?? "all" }}';


// Initialize table on page load
document.addEventListener('DOMContentLoaded', function() {
    renderOrdersTable();
});
*/ 

function openColumnSettings() {
    renderColumnSettings();
    document.getElementById('columnSettingsModal').style.display = 'block';
}

function renderColumnSettings() {
    const columnList = document.getElementById('columnList');
    columnList.innerHTML = '';
    
    currentColumns.forEach((column, index) => {
        const columnConfig = availableColumns[column.id];
        if (!columnConfig) return;
        
        const item = document.createElement('div');
        item.className = 'column-item';
        item.draggable = true;
        item.dataset.columnId = column.id;
        item.style.cssText = `
            display: flex; 
            align-items: center; 
            padding: 12px; 
            margin-bottom: 8px; 
            background: white; 
            border: 1px solid #e5e7eb; 
            border-radius: 6px; 
            cursor: ${columnConfig.fixed ? 'default' : 'grab'};
            user-select: none;
        `;
        
        item.innerHTML = `
            <div style="display: flex; align-items: center; width: 100%;">
                <div style="margin-right: 12px; color: #6b7280; cursor: ${columnConfig.fixed ? 'default' : 'grab'};">
                    ${columnConfig.fixed ? '🔒' : '☰'}
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 500; color: #374151;">${columnConfig.label}</div>
                    <div style="font-size: 12px; color: #6b7280;">${column.id}</div>
                </div>
                <label style="display: flex; align-items: center; cursor: pointer;">
                    <input type="checkbox" ${column.visible ? 'checked' : ''} 
                           onchange="toggleColumnVisibility('${column.id}', this.checked)"
                           style="margin-right: 8px;">
                    <span style="font-size: 12px; color: #6b7280;">Show</span>
                </label>
            </div>
        `;
        
        // Add drag and drop only for non-fixed columns
        if (!columnConfig.fixed) {
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragover', handleDragOver);
            item.addEventListener('drop', handleDrop);
            item.addEventListener('dragend', handleDragEnd);
        }
        
        columnList.appendChild(item);
    });
}

// Drag and drop handlers
let draggedItem = null;

function handleDragStart(e) {
    draggedItem = this;
    this.style.opacity = '0.5';
}

function handleDragOver(e) {
    e.preventDefault();
}

function handleDrop(e) {
    e.preventDefault();
    if (this !== draggedItem) {
        const allItems = Array.from(this.parentNode.children);
        const draggedIndex = allItems.indexOf(draggedItem);
        const targetIndex = allItems.indexOf(this);
        
        if (draggedIndex < targetIndex) {
            this.parentNode.insertBefore(draggedItem, this.nextSibling);
        } else {
            this.parentNode.insertBefore(draggedItem, this);
        }
        
        // Update the column order
        reorderColumns();
    }
}

function handleDragEnd(e) {
    this.style.opacity = '';
    draggedItem = null;
}

function reorderColumns() {
    const columnList = document.getElementById('columnList');
    const items = Array.from(columnList.children);
    
    const newOrder = items.map(item => {
        const columnId = item.dataset.columnId;
        const checkbox = item.querySelector('input[type="checkbox"]');
        return {
            id: columnId,
            visible: checkbox.checked
        };
    });
    
    currentColumns = newOrder;
    localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
}

function toggleColumnVisibility(columnId, isVisible) {
    const column = currentColumns.find(col => col.id === columnId);
    if (column) {
        column.visible = isVisible;
        localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
    }
}

function saveColumnSettings() {
    localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
    document.getElementById('columnSettingsModal').style.display = 'none';
    renderOrdersTable();
}

function resetColumnSettings() {
    localStorage.removeItem('orderTableColumns');
    currentColumns = [...defaultColumns];
    ensureActionsColumn();
    ensureAddressFields();
    localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
    renderColumnSettings();
}

// ⚠️ DEPRECATED: This function has been replaced by the modular renderTableHeader() and renderTableBody() system
// Keeping this as a wrapper for backward compatibility, but it now calls the newer modular functions
function renderOrdersTable() {
    // Call the newer, more maintainable modular system
    renderTableHeader();
    renderTableBody();
}

/* ⚠️ DEPRECATED: This function has been replaced by the newer getCellContent() function below (around line 2227)
   This older version has been commented out to avoid conflicts and duplicated code
   The newer version has better error handling, debug logging, and more features
function getCellContent(order, columnId) {
    const formatDate = (dateStr) => {
        if (!dateStr) return '<span class="text-gray-400">-</span>';
        try {
            // Parse the date string
            const date = new Date(dateStr);
            
            // Format with both date and time
            const formatted = date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
            return formatted;
        } catch (error) {
            console.error('Date formatting error:', error);
            return '<span class="text-red-400">Invalid Date</span>';
        }
    };

    const formatCurrency = (amount) => {
        if (!amount && amount !== 0) return '0.00';
        return parseFloat(amount).toFixed(2);
    };

    switch (columnId) {
        case 'id':
            return order.id || '';
        case 'order_number':
            return order.order_number || '';
        case 'order_date':
            return formatDate(order.order_date);
        case 'order_status':
            const status = order.order_status || 'pending';
            const statusConfig = {
                'pending': { bg: 'bg-yellow-50', border: 'border-yellow-200', text: 'text-yellow-700', icon: '⏳' },
                'processing': { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-700', icon: '⚡' },
                'completed': { bg: 'bg-green-50', border: 'border-green-200', text: 'text-green-700', icon: '✓' },
                'cancelled': { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-700', icon: '✕' },
                'refunded': { bg: 'bg-purple-50', border: 'border-purple-200', text: 'text-purple-700', icon: '↩' },
                'on-hold': { bg: 'bg-orange-50', border: 'border-orange-200', text: 'text-orange-700', icon: '⏸' }
            };
            const config = statusConfig[status] || { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700', icon: '?' };
            return `<span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${config.bg} ${config.border} ${config.text}">
                        <span class="mr-1 text-xs">${config.icon}</span>
                        ${status.charAt(0).toUpperCase() + status.slice(1)}
                    </span>`;
        case 'external_source':
            const source = order.external_source || 'manual';
            const sourceColors = {
                'shopify': 'bg-green-50 border-green-200 text-green-700',
                'woocommerce': 'bg-purple-50 border-purple-200 text-purple-700',
                'webapp': 'bg-blue-50 border-blue-200 text-blue-700',
                'manual': 'bg-orange-50 border-orange-200 text-orange-700'
            };
            const sourceColor = sourceColors[source] || 'bg-gray-50 border-gray-200 text-gray-700';
            return `<span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${sourceColor}">${source.charAt(0).toUpperCase() + source.slice(1)}</span>`;
        case 'external_id':
            return order.external_id || '';
            
        // Customer Info
        case 'customer_name':
            // Priority: order.name (from address) -> customer.full_name -> address fields
            let customerName = '';
            if (order.name && order.name.trim()) {
                customerName = order.name.trim();
            } else if (order.customer && order.customer.full_name && order.customer.full_name.trim()) {
                customerName = order.customer.full_name.trim();
            } else {
                // Fallback to address fields
                const firstName = order.address_first_name || '';
                const lastName = order.address_last_name || '';
                customerName = (firstName + ' ' + lastName).trim();
            }
            return customerName ? `<div class="table-text-primary">${customerName}</div>` : '<span class="table-text-small">N/A</span>';
        case 'contact_email':
            return order.contact_email || '';
        case 'customer_phone':
            const phone = order.customer_phone || order.address_phone || '';
            return phone ? `<div class="table-text-secondary">${phone}</div>` : '<span class="table-text-small">N/A</span>';
            
        // Address Info
        case 'address_first_name':
            return order.address_first_name || '';
        case 'address_last_name':
            return order.address_last_name || '';
        case 'address_full_name':
            const addrFirstName = order.address_first_name || '';
            const addrLastName = order.address_last_name || '';
            const addrFullName = (addrFirstName + ' ' + addrLastName).trim();
            return addrFullName || '';
        case 'address_email':
            return order.address_email || '';
        case 'address_phone':
            return order.address_phone || '';
        case 'address1':
            return order.address_line1 || '';
        case 'address2':
            return order.address_line2 || '';
        case 'address_city':
            return order.address_city || '';
        case 'address_province':
            return order.address_province || '';
        case 'address_country':
            return order.address_country || '';
        case 'postal_code':
            return order.postal_code || '';
            
        // Financial Info
        case 'currency':
            return order.currency || 'PKR';
        case 'subtotal_price':
            return formatCurrency(order.subtotal_price);
        case 'discount_total':
            return formatCurrency(order.discount_total);
        case 'shipping_total':
            return formatCurrency(order.shipping_total);
        case 'total_price':
            const totalPrice = formatCurrency(order.total_price);
            return `<div class="table-text-primary font-semibold">PKR ${totalPrice}</div>`;
        case 'total_weight':
            return order.total_weight || '0';
            
        // Payment & Other Info
        case 'payment_method':
            return order.payment_method || '';
        case 'coupon_code':
            return order.coupon_code || '';
        case 'note':
            return order.note || '';
        case 'created_at':
            return formatDate(order.created_at);
        case 'updated_at':
            return formatDate(order.updated_at);
            
        // Line Items Count
        case 'line_items_count':
            const itemCount = order.line_items ? order.line_items.length : (order.line_items_count || 0);
            return `<span onclick="viewOrderDetails($${'{'}order.id${'}'}" class="inline-flex items-center px-2 py-1 bg-blue-50 border border-blue-200 text-blue-700 rounded-md text-sm font-medium cursor-pointer hover:bg-blue-100 transition-colors">
                        $${'{'}itemCount${'}'} item$${'{'}itemCount !== 1 ? 's' : ''${'}'}
                    </span>`;
            
        // Actions column
        case 'actions':
            return `
                <div class="flex items-center space-x-2">
                    <button onclick="viewOrderDetails($${'{'}order.id${'}'}" 
                            class="inline-flex items-center p-1.5 border border-gray-300 rounded-md text-gray-600 hover:text-gray-700 hover:bg-gray-50 transition-colors duration-150" 
                            title="View Details">
                        <i class="ki-filled ki-eye text-sm"></i>
                    </button>
                    <button onclick="editOrderDetails($${'{'}order.id${'}'}" 
                            class="inline-flex items-center p-1.5 border border-blue-300 rounded-md text-blue-600 hover:text-blue-700 hover:bg-blue-50 transition-colors duration-150" 
                            title="Edit Order">
                        <i class="ki-filled ki-notepad-edit text-sm"></i>
                    </button>
                    <button onclick="window.open('/orders/$${'{'}order.id${'}'}/invoice', '_blank')" 
                            class="inline-flex items-center p-1.5 border border-green-300 rounded-md text-green-600 hover:text-green-700 hover:bg-green-50 transition-colors duration-150" 
                            title="View Invoice">
                        <i class="ki-filled ki-file-sheet text-sm"></i>
                    </button>
                </div>
            `;
            
        default:
            return '';
    }
} // END DEPRECATED getCellContent function */

// DEPRECATED: The following functions are part of the older system and have been commented out
// to avoid conflicts. The newer implementations are used instead.
// Ensure Actions column is always present and visible (active)
function ensureActionsColumn() {
    const hasActions = currentColumns.find(function(col) { return col.id === 'actions'; });
    if (!hasActions) {
        currentColumns.push({ id: 'actions', visible: true });
    } else {
        hasActions.visible = true;
    }
}

// Ensure all address fields are present in currentColumns (legacy copy)
function ensureAddressFields() {
    const addressFields = [
        'address_first_name', 'address_last_name', 'address_full_name',
        'address1', 'address2', 'postal_code'
    ];
    
    addressFields.forEach(fieldId => {
        const hasField = currentColumns.find(col => col.id === fieldId);
        if (!hasField) {
            // Add missing address field
            currentColumns.push({ id: fieldId, visible: false });
        }
    });
}

// Initialize columns
ensureActionsColumn();
ensureAddressFields();

// Save the updated columns to localStorage
localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));

// Debug: Log current columns after initialization
console.log('Current columns after initialization:', currentColumns);

// Orders data passed from Laravel
window.ordersData = @json($orders->items());


// Initialize table on page load
document.addEventListener('DOMContentLoaded', function() {
    renderOrdersTable();
});

function openColumnSettings() {
    renderColumnSettings();
    document.getElementById('columnSettingsModal').style.display = 'block';
}

function renderColumnSettings() {
    const columnList = document.getElementById('columnList');
    columnList.innerHTML = '';
    
    currentColumns.forEach((column, index) => {
        const columnConfig = availableColumns[column.id];
        if (!columnConfig) return;
        
        const item = document.createElement('div');
        item.className = 'column-item';
        item.draggable = true;
        item.dataset.columnId = column.id;
        item.style.cssText = `
            display: flex; 
            align-items: center; 
            padding: 12px; 
            margin-bottom: 8px; 
            background: white; 
            border: 1px solid #e5e7eb; 
            border-radius: 6px; 
            cursor: ${columnConfig.fixed ? 'default' : 'grab'};
            user-select: none;
        `;
        
        item.innerHTML = `
            <div style="display: flex; align-items: center; width: 100%;">
                ${!columnConfig.fixed ? '<div style="margin-right: 12px; color: #9ca3af;"><svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg></div>' : ''}
                <input type="checkbox" ${column.visible ? 'checked' : ''} ${columnConfig.fixed ? 'disabled' : ''} 
                       onchange="toggleColumnVisibility('${column.id}')" 
                       style="margin-right: 12px;">
                <label style="flex: 1; font-weight: 500; color: ${columnConfig.fixed ? '#9ca3af' : '#374151'};">
                    ${columnConfig.label} ${columnConfig.fixed ? '(Fixed)' : ''}
                </label>
            </div>
        `;
        
        if (!columnConfig.fixed) {
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragover', handleDragOver);
            item.addEventListener('drop', handleDrop);
            item.addEventListener('dragend', handleDragEnd);
        }
        
        columnList.appendChild(item);
    });
}

function toggleColumnVisibility(columnId) {
    const column = currentColumns.find(col => col.id === columnId);
    if (column && !availableColumns[columnId].fixed) {
        column.visible = !column.visible;
    }
}

let draggedElement = null;

function handleDragStart(e) {
    draggedElement = e.target;
    e.target.style.opacity = '0.5';
}

function handleDragOver(e) {
    e.preventDefault();
}

function handleDrop(e) {
    e.preventDefault();
    if (draggedElement !== e.target && !availableColumns[e.target.dataset.columnId]?.fixed) {
        const draggedId = draggedElement.dataset.columnId;
        const targetId = e.target.dataset.columnId;
        
        const draggedIndex = currentColumns.findIndex(col => col.id === draggedId);
        const targetIndex = currentColumns.findIndex(col => col.id === targetId);
        
        if (draggedIndex !== -1 && targetIndex !== -1) {
            const draggedColumn = currentColumns.splice(draggedIndex, 1)[0];
            currentColumns.splice(targetIndex, 0, draggedColumn);
            renderColumnSettings();
        }
    }
}

function handleDragEnd(e) {
    e.target.style.opacity = '1';
    draggedElement = null;
}

function resetColumns() {
    currentColumns = JSON.parse(JSON.stringify(defaultColumns));
    renderColumnSettings();
}

function applyColumnSettings() {
    localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
    renderOrdersTable();
    closeModal('columnSettingsModal');
}

function renderOrdersTable() {
    renderTableHeader();
    renderTableBody();
}

function renderTableHeader() {
    const header = document.getElementById('table-header');
    header.innerHTML = '';
    
    currentColumns.forEach(column => {
        if (column.visible) {
            const columnConfig = availableColumns[column.id];
            if (columnConfig) {
                const th = document.createElement('th');
                th.className = `px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider ${columnConfig.width}`;
                th.textContent = columnConfig.label;
                header.appendChild(th);
            }
        }
    });
}

function renderTableBody() {
    const tbody = document.getElementById('table-body');
    tbody.innerHTML = '';
    
    
    if (!window.ordersData || window.ordersData.length === 0) {
        // Show a message in the table
        const row = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 10;
        td.className = 'text-center py-8 text-gray-500';
        td.innerHTML = 'No orders found';
        row.appendChild(td);
        tbody.appendChild(row);
        return;
    }
    
    window.ordersData.forEach((order, index) => {
        try {
            const row = document.createElement('tr');
            row.className = 'hover:bg-gray-50 transition-colors duration-150';
            
            currentColumns.forEach(column => {
                if (column.visible) {
                    try {
                        const td = document.createElement('td');
                        td.className = 'px-6 py-4 whitespace-nowrap text-sm';
                        const cellContent = getCellContent(order, column.id);
                        td.innerHTML = cellContent;
                        row.appendChild(td);
                    } catch (cellError) {
                        console.error(`Error rendering cell ${column.id}:`, cellError);
                        const td = document.createElement('td');
                        td.className = 'px-6 py-4 whitespace-nowrap text-sm';
                        td.innerHTML = '<span class="text-red-500">Error</span>';
                        row.appendChild(td);
                    }
                }
            });
            
            tbody.appendChild(row);
        } catch (rowError) {
            console.error(`Error rendering row ${index}:`, rowError, order);
        }
    });
}

function getCellContent(order, columnId) {
    const formatDate = (dateStr) => {
        if (!dateStr) return '<span class="text-gray-400">-</span>';
        try {
            // Parse the date string
            const date = new Date(dateStr);
            
            // Format with both date and time
            const formatted = date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
            return formatted;
        } catch (error) {
            console.error('Date parsing error:', error, 'for date:', dateStr);
            return `<span class="text-sm text-gray-600">${dateStr}</span>`;
        }
    };
    
    const formatCurrency = (amount, currency = 'PKR') => {
        if (!amount) return '0.00';
        return parseFloat(amount).toFixed(2);
    };
    
    try {
        switch (columnId) {
        // Basic Info
        case 'id':
            return order.id;
        case 'order_number':
            return order.order_number || '';
        case 'order_date':
            return formatDate(order.order_date);
        case 'order_status':
            const status = order.order_status || 'pending';
            const statusConfig = {
                'pending': { bg: 'bg-yellow-50', border: 'border-yellow-200', text: 'text-yellow-700', icon: '⏳' },
                'processing': { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-700', icon: '⚡' },
                'completed': { bg: 'bg-green-50', border: 'border-green-200', text: 'text-green-700', icon: '✓' },
                'cancelled': { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-700', icon: '✕' },
                'refunded': { bg: 'bg-purple-50', border: 'border-purple-200', text: 'text-purple-700', icon: '↩' },
                'on-hold': { bg: 'bg-orange-50', border: 'border-orange-200', text: 'text-orange-700', icon: '⏸' }
            };
            const config = statusConfig[status] || { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700', icon: '?' };
            return `<span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${config.bg} ${config.border} ${config.text}">
                        <span class="mr-1 text-xs">${config.icon}</span>
                        ${status.charAt(0).toUpperCase() + status.slice(1)}
                    </span>`;
        case 'external_source':
            const source = order.external_source || 'manual';
            const sourceColors = {
                'shopify': 'bg-green-50 border-green-200 text-green-700',
                'woocommerce': 'bg-purple-50 border-purple-200 text-purple-700',
                'manual': 'bg-orange-50 border-orange-200 text-orange-700'
            };
            const sourceColor = sourceColors[source] || 'bg-gray-50 border-gray-200 text-gray-700';
            return `<span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${sourceColor}">${source.charAt(0).toUpperCase() + source.slice(1)}</span>`;
        case 'external_id':
            return order.external_id || '';
            
        // Customer Info
        case 'customer_name':
            // Priority: order.name (from address) -> customer.full_name -> address fields
            let customerName = '';
            if (order.name && order.name.trim()) {
                customerName = order.name.trim();
            } else if (order.customer && order.customer.full_name && order.customer.full_name.trim()) {
                customerName = order.customer.full_name.trim();
            } else {
                // Fallback to address fields
                const firstName = order.address_first_name || '';
                const lastName = order.address_last_name || '';
                customerName = (firstName + ' ' + lastName).trim();
            }
            return customerName ? `<div class="table-text-primary">${customerName}</div>` : '<span class="table-text-small">N/A</span>';
        case 'contact_email':
            return order.contact_email || '';
        case 'customer_phone':
            const phone = order.customer_phone || order.address_phone || '';
            return phone ? `<div class="table-text-secondary">${phone}</div>` : '<span class="table-text-small">N/A</span>';
            
        // Address Info
        case 'address_first_name':
            // Show full name from address fields
            const addrFirstName = order.address_first_name || '';
            const addrLastName = order.address_last_name || '';
            const addrFullName = (addrFirstName + ' ' + addrLastName).trim();
            return addrFullName || '';
        case 'address_email':
            return order.address_email || '';
        case 'address_phone':
            return order.address_phone || '';
        case 'address_city':
            return order.address_city || '';
        case 'address_province':
            return order.address_province || '';
        case 'address_country':
            return order.address_country || '';
        case 'address_last_name':
            return order.address_last_name || '';
        case 'address_full_name':
            const fullAddrFirstName = order.address_first_name || '';
            const fullAddrLastName = order.address_last_name || '';
            const fullAddrName = (fullAddrFirstName + ' ' + fullAddrLastName).trim();
            return fullAddrName || '';
        case 'address1':
            return order.address_line1 || '';
        case 'address2':
            return order.address_line2 || '';
        case 'postal_code':
            return order.postal_code || '';
            
        // Financial Info
        case 'currency':
            return order.currency || 'PKR';
        case 'subtotal_price':
            return formatCurrency(order.subtotal_price);
        case 'discount_total':
            return formatCurrency(order.discount_total);
        case 'shipping_total':
            return formatCurrency(order.shipping_total);
        case 'total_price':
            const totalPrice = formatCurrency(order.total_price);
            return `<div class="table-text-primary font-semibold">PKR ${totalPrice}</div>`;
        case 'total_weight':
            return order.total_weight || '0';
            
        // Payment & Other Info
        case 'payment_method':
            return order.payment_method || '';
        case 'coupon_code':
            return order.coupon_code || '';
        case 'note':
            const note = order.note || '';
            return note.length > 30 ? note.substring(0, 30) + '...' : note;
            
        // Line Items
        case 'line_items_count':
            const itemCount = order.line_items ? order.line_items.length : 0;
            return `<div class="text-center">
                        <span class="text-sm font-medium text-blue-600 cursor-pointer hover:text-blue-800 hover:underline transition-colors" onclick="viewOrderDetails(${order.id})" title="Click to view order details">
                            ${itemCount}
                        </span>
                        <div class="text-xs text-gray-400 mt-0.5">items</div>
                    </div>`;
            
        // Timestamps
        case 'created_at':
            return formatDate(order.created_at);
        case 'updated_at':
            return formatDate(order.updated_at);
            
        // Actions
        case 'actions':
            // If we're on Shopify Approvals tab specifically, restrict to Approve/Ignore/View
            // When loaded via AJAX we rely on window.currentSource/currentTab
            const isShopifyApprovals = (
                (typeof window !== 'undefined' && window.currentSource === 'shopify' && window.currentTab === 'approvals') ||
                ('{{ $source }}' === 'shopify' && '{{ $tab ?? "all" }}' === 'approvals')
            );
            if (isShopifyApprovals) {
                return `
                    <div class="flex items-center justify-center gap-1.5">
                        <button onclick="convertOrder(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-emerald-600 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 hover:border-emerald-300 hover:shadow-sm transition-all duration-200 group" title="Approve (Convert)">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </button>
                        <button onclick="ignoreOrder(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-rose-600 bg-rose-50 border border-rose-200 rounded-lg hover:bg-rose-100 hover:border-rose-300 hover:shadow-sm transition-all duration-200 group" title="Ignore">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                        <button onclick="viewOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 hover:border-blue-300 hover:shadow-sm transition-all duration-200 group" title="View Order">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>`;
            }
            // Default full actions for non-shopify tab
            return `
                <div class="flex items-center justify-center gap-1.5">
                    <button onclick="viewOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 hover:border-blue-300 hover:shadow-sm transition-all duration-200 group" title="View Order Details">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </button>
                    <button onclick="editOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-amber-600 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 hover:border-amber-300 hover:shadow-sm transition-all duration-200 group" title="Edit Order">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <button onclick="window.open('/orders/${order.id}/invoice', '_blank')" class="inline-flex items-center justify-center w-8 h-8 text-emerald-600 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 hover:border-emerald-300 hover:shadow-sm transition-all duration-200 group" title="View Invoice (PDF)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </button>
                </div>`;
        default:
            return '';
        }
    } catch (error) {
        console.error(`Error in getCellContent for column ${columnId}:`, error, order);
        return `<span class="text-red-500">Error</span>`;
    }
}

// ==================== END COLUMN CUSTOMIZATION ====================

// ==================== SEARCH AND FILTER FUNCTIONALITY ====================

window.allOrders = []; // Store all orders for filtering
window.filteredOrders = []; // Store filtered results

// Initialize search and filters
document.addEventListener('DOMContentLoaded', function() {
    // Store original data from current page only
    window.allOrders = [...window.ordersData];
    window.filteredOrders = [...window.ordersData];
    
    // Set up search with debouncing
    const searchInput = document.getElementById('orderSearch');
    const statusFilter = document.getElementById('statusFilter');
    const dateFilter = document.getElementById('dateFilter');
    
    let searchTimeout;
    
    // Search functionality - make API call for full dataset
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const searchTerm = searchInput.value.trim();
            if (searchTerm.length > 2) {
                fetchFilteredOrders();
            } else if (searchTerm.length === 0) {
                // Auto-clear when search box is empty
                clearFilters();
            } else {
                // Reset to current page data if search is too short but not empty
                window.filteredOrders = [...window.ordersData];
                renderOrdersWithFilters(window.filteredOrders);
                updateResultsCount();
            }
        }, 300); // Reduced timeout for better responsiveness
    });
    
    // Filter functionality - make API call for full dataset
    statusFilter.addEventListener('change', function() {
        fetchFilteredOrders();
    });
    
    dateFilter.addEventListener('change', function() {
        fetchFilteredOrders();
    });
    
    // Per-page selector functionality
    const perPageSelector = document.getElementById('per-page-selector');
    if (perPageSelector) {
        perPageSelector.addEventListener('change', function() {
            const perPage = this.value;
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('per_page', perPage);
            currentUrl.searchParams.set('page', '1'); // Reset to first page
            window.location.href = currentUrl.toString();
        });
    }
});

// Fetch filtered orders from backend
function fetchFilteredOrders() {
    const searchTerm = document.getElementById('orderSearch').value.trim();
    const statusFilter = document.getElementById('statusFilter').value;
    const dateFilter = document.getElementById('dateFilter').value;
    
    // Show loading state
    showLoadingState();
    
    // Build query parameters
    const params = new URLSearchParams();
    if (searchTerm) params.append('search', searchTerm);
    if (statusFilter) params.append('status', statusFilter);
    if (dateFilter) params.append('date', dateFilter);
    
    // Get current source (shopify/other)
    const currentUrl = new URL(window.location);
    const source = currentUrl.searchParams.get('source') || 'other';
    params.append('source', source);
    
    // Make API call
    fetch(`/orders/filter?${params.toString()}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.filteredOrders = data.orders;
            renderOrdersWithFilters(window.filteredOrders);
            updateResultsCount();
        } else {
            console.error('Filter error:', data.message);
            // Show empty state instead of fallback data when search fails
            window.filteredOrders = [];
            renderOrdersWithFilters(window.filteredOrders);
            updateResultsCount();
        }
    })
    .catch(error => {
        console.error('Filter request failed:', error);
        // Show empty state instead of fallback data when search fails
        window.filteredOrders = [];
        renderOrdersWithFilters(window.filteredOrders);
        updateResultsCount();
    })
    .finally(() => {
        hideLoadingState();
    });
}

// Apply all filters and search (legacy function - keeping for compatibility)
function applyFilters() {
    // Show loading state
    showLoadingState();
    
    // Small delay to show loading animation
    setTimeout(() => {
        const searchTerm = document.getElementById('orderSearch').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const dateFilter = document.getElementById('dateFilter').value;
        
        window.filteredOrders = window.allOrders.filter(order => {
        // Search filter
        const matchesSearch = !searchTerm || 
            (order.customer && order.customer.name && order.customer.name.toLowerCase().includes(searchTerm)) ||
            (order.order_number && order.order_number.toLowerCase().includes(searchTerm)) ||
            (order.customer && order.customer.phone && order.customer.phone.includes(searchTerm)) ||
            (order.customer && order.customer.email && order.customer.email.toLowerCase().includes(searchTerm));
        
        // Status filter
        const matchesStatus = !statusFilter || 
            (order.order_status && order.order_status.toLowerCase() === statusFilter);
        
        // Date filter
        const matchesDate = !dateFilter || 
            (order.order_date && order.order_date.startsWith(dateFilter));
        
        return matchesSearch && matchesStatus && matchesDate;
    });
    
        // Update the display
        renderOrdersWithFilters(window.filteredOrders);
        updateResultsCount();
        hideLoadingState();
    }, 100);
}

// Update results count display
function updateResultsCount() {
    const totalCount = window.allOrders.length;
    const filteredCount = window.filteredOrders.length;
    
    // Update our custom results count element
    const resultsElement = document.getElementById('results-count');
    if (resultsElement) {
        if (filteredCount === totalCount) {
            resultsElement.textContent = `Showing ${filteredCount} orders`;
        } else {
            resultsElement.textContent = `Showing ${filteredCount} of ${totalCount} orders`;
        }
    }
    
    // Also update pagination info if it exists
    const infoElement = document.querySelector('[data-kt-datatable-info="true"]');
    if (infoElement) {
        infoElement.textContent = `${filteredCount} orders`;
    }
}

// Render table with filtered data
function renderOrdersWithFilters(data) {
    // Update the global ordersData
    window.ordersData = data;
    
    if (data.length === 0) {
        showEmptyState();
    } else {
        hideEmptyState();
        renderOrdersTable(); // Re-render the table
    }
}

// Clear all filters
function clearFilters() {
    document.getElementById('orderSearch').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('dateFilter').value = '';
    
    // Reset to original page data without API call
    window.filteredOrders = [...window.allOrders];
    renderOrdersWithFilters(window.filteredOrders);
    updateResultsCount();
}

// Loading state functions
function showLoadingState() {
    document.getElementById('table-body').style.display = 'none';
    document.getElementById('no-results-state').classList.add('hidden');
    document.getElementById('loading-state').classList.remove('hidden');
}

function hideLoadingState() {
    document.getElementById('loading-state').classList.add('hidden');
    document.getElementById('table-body').style.display = '';
}

function showEmptyState() {
    document.getElementById('table-body').style.display = 'none';
    document.getElementById('loading-state').classList.add('hidden');
    document.getElementById('no-results-state').classList.remove('hidden');
}

function hideEmptyState() {
    document.getElementById('no-results-state').classList.add('hidden');
    document.getElementById('table-body').style.display = '';
}

// ==================== END SEARCH AND FILTER ====================

// Create new order functionality
function createNewOrder() {
    // Reset and open the edit modal for creating new order
    const modal = document.getElementById('editOrderModal');
    const content = document.getElementById('editOrderContent');
    
    // Set up form for new order
    content.innerHTML = `
        <form id="editOrderForm">
            <!-- Customer Section -->
            <div style="background-color: #fefefe; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0;">Customer Information</h4>
                </div>
                <div style="padding: 16px;">
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Customer Selection</label>
                        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                            <button type="button" id="existingCustomerBtn" onclick="selectCustomerMode('existing')" style="padding: 6px 12px; border: 1px solid #d1d5db; background-color: #f9fafb; color: #374151; border-radius: 4px; font-size: 12px; cursor: pointer;">Existing Customer</button>
                            <button type="button" id="newCustomerBtn" onclick="selectCustomerMode('new')" style="padding: 6px 12px; border: 1px solid #10b981; background-color: #10b981; color: white; border-radius: 4px; font-size: 12px; cursor: pointer;">New Customer</button>
                        </div>
                        
                        <!-- Existing Customer Search -->
                        <div id="existingCustomerSection" style="display: none;">
                            <div style="position: relative;">
                                <input type="text" id="customerSearch" placeholder="Search customers by name, phone, or email..." 
                                       style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                                       onkeyup="searchCustomers(this)" onfocus="showCustomerDropdown()">
                                <div id="customerDropdown" class="customer-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                                <input type="hidden" name="customer_id" id="selectedCustomerId">
                            </div>
                        </div>
                        
                        <!-- New Customer Fields -->
                        <div id="newCustomerSection">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">First Name</label>
                                    <input type="text" name="customer_first_name" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Last Name</label>
                                    <input type="text" name="customer_last_name" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Phone Number *</label>
                                <input type="text" name="customer_phone" placeholder="+92345000681 or 03455000681" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Company</label>
                                <input type="text" name="customer_company" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Address Line 1</label>
                                    <input type="text" name="customer_address1" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Address Line 2</label>
                                    <input type="text" name="customer_address2" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">City</label>
                                    <input type="text" name="customer_city" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Province</label>
                                    <input type="text" name="customer_province" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Postal Code</label>
                                    <input type="text" name="customer_postal_code" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                <div>
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Information</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Order Status</label>
                            <select name="order_status" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="on-hold">On Hold</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Order Date & Time</label>
                            <input type="datetime-local" name="order_date" required value="${getCurrentLocalDateTime()}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Contact Email</label>
                            <input type="email" name="contact_email" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Payment Method</label>
                            <select name="payment_method" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <option value="">Select Payment Method</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="card">Card</option>
                                <option value="online">Online Payment</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Pricing</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Subtotal</label>
                            <input type="number" step="0.01" name="subtotal_price" value="0" readonly style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background-color: #f3f4f6;">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Discount</label>
                            <div style="display: flex; gap: 8px;">
                                <div style="flex: 1; position: relative;">
                                    <input type="text" id="newOrderCouponSearch" name="coupon_code" value="" 
                                           style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" 
                                           placeholder="Search coupon code..." onkeyup="searchNewOrderCoupons(this.value)" onfocus="showNewOrderCouponDropdown()" onblur="hideNewOrderCouponDropdown()">
                                    <div id="newOrderCouponDropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                                </div>
                                <input type="number" step="0.01" name="discount_total" value="0" onchange="updateOrderTotal()" placeholder="Discount amount" style="flex: 1; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                            </div>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Shipping</label>
                            <input type="number" step="0.01" name="shipping_total" value="0" onchange="updateOrderTotal()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
                            <input type="number" step="0.01" name="total_price" value="0" readonly style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background-color: #f3f4f6; font-weight: 600;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Items Section -->
            <div style="background-color: #fefefe; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0;">Line Items</h4>
                    <button type="button" onclick="(window.addLineItem||addLineItem)()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                        + Add Item
                    </button>
                </div>
                <div id="lineItemsContainer" style="padding: 16px;">
                    <div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>
                </div>
            </div>

            <!-- Notes Section -->
            <div style="margin-bottom: 24px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Notes</label>
                <textarea name="note" rows="3" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="Order notes..."></textarea>
            </div>

            <!-- Form Actions -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <button type="button" onclick="closeModal('editOrderModal')" style="padding: 10px 20px; border: 1px solid #d1d5db; background-color: white; color: #374151; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    Cancel
                </button>
                <button type="submit" style="padding: 10px 20px; background-color: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                    Create Order
                </button>
            </div>
        </form>
    `;
    
    // Reset line item index for new order
    lineItemIndex = 0;
    
    // Load default shipping price
    loadDefaultShippingPrice('editOrderModal');
    
    // Set up form submission for new order
    document.getElementById('editOrderForm').onsubmit = function(e) {
        e.preventDefault();
        saveNewOrder();
    };
    
    modal.style.display = 'block';
}

// DUPLICATE FUNCTION REMOVED - Original exists at line 1402

// Save new order
function saveNewOrder() {
    const form = document.getElementById('editOrderForm');
    const formData = new FormData(form);
    
    // Collect line items (ignore empty ones)
    const items = [];
    document.querySelectorAll('.line-item').forEach((item, index) => {
        const name = item.querySelector(`input[name*="[name]"]`)?.value?.trim();
        const quantity = parseFloat(item.querySelector(`input[name*="[quantity]"]`)?.value) || 0;
        const unitPrice = parseFloat(item.querySelector(`input[name*="[unit_price]"]`)?.value) || 0;
        
        // Only add items that have a name and valid quantity/price
        if (name && quantity > 0 && unitPrice >= 0) {
            items.push({
                name: name,
                quantity: quantity,
                unit_price: unitPrice,
                line_total: quantity * unitPrice
            });
        }
    });
    
    if (items.length === 0) {
        alert('Please add at least one line item with valid details');
        return;
    }
    
    // Prepare data
    const orderData = {
        customer_id: formData.get('customer_id'),
        order_status: formData.get('order_status'),
        order_date: formData.get('order_date') ? formData.get('order_date').replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00',
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        discount_total: parseFloat(formData.get('discount_total')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        coupon_code: formData.get('coupon_code'),
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        items: items,
        // Customer creation fields
        customer_phone: formData.get('customer_phone'),
        customer_first_name: formData.get('customer_first_name'),
        customer_last_name: formData.get('customer_last_name'),
        customer_company: formData.get('customer_company'),
        customer_address1: formData.get('customer_address1'),
        customer_address2: formData.get('customer_address2'),
        customer_city: formData.get('customer_city'),
        customer_province: formData.get('customer_province'),
        customer_postal_code: formData.get('customer_postal_code'),
        customer_country: formData.get('customer_country')
    };
    
    // Submit to server
    fetch('/orders', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify(orderData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Order created successfully!');
            closeModal('editOrderModal');
            // Refresh the page to show the new order
            location.reload();
        } else {
            alert('Error creating order: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error creating order');
    });
}

// Load default shipping price for order forms
function loadDefaultShippingPrice(modalId = null) {
    fetch('/api/shipping/price')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.shipping_price) {
                let shippingInput;
                if (modalId) {
                    shippingInput = document.querySelector(`#${modalId} input[name="shipping_total"]`);
                } else {
                    shippingInput = document.querySelector('input[name="shipping_total"]');
                }
                
                if (shippingInput) {
                    shippingInput.value = data.shipping_price;
                    // Try to update total if function exists
                    if (typeof updateOrderTotal === 'function') {
                        updateOrderTotal();
                    }
                }
            }
        })
        .catch(error => {
            console.log('Could not load default shipping price:', error);
        });
}

// ==================== CUSTOMER SELECTION HELPERS (single source of truth) ====================
let customerSearchTimeout;

function selectCustomerMode(mode) {
    const existingSection = document.getElementById('existingCustomerSection');
    const newSection = document.getElementById('newCustomerSection');
    const existingBtn = document.getElementById('existingCustomerBtn');
    const newBtn = document.getElementById('newCustomerBtn');

    if (!existingSection || !newSection || !existingBtn || !newBtn) return;

    if (mode === 'existing') {
        existingSection.style.display = '';
        newSection.style.display = 'none';
        existingBtn.style.backgroundColor = '#10b981';
        existingBtn.style.color = '#ffffff';
        existingBtn.style.borderColor = '#10b981';
        newBtn.style.backgroundColor = '#f9fafb';
        newBtn.style.color = '#374151';
        newBtn.style.borderColor = '#d1d5db';
    } else {
        existingSection.style.display = 'none';
        newSection.style.display = '';
        newBtn.style.backgroundColor = '#10b981';
        newBtn.style.color = '#ffffff';
        newBtn.style.borderColor = '#10b981';
        existingBtn.style.backgroundColor = '#f9fafb';
        existingBtn.style.color = '#374151';
        existingBtn.style.borderColor = '#d1d5db';
    }
}

function showCustomerDropdown() {
    const dd = document.getElementById('customerDropdown');
    if (dd) dd.style.display = 'block';
}

function hideCustomerDropdown() {
    const dd = document.getElementById('customerDropdown');
    if (dd) dd.style.display = 'none';
}

function searchCustomers(inputEl) {
    const query = (inputEl && inputEl.value) ? inputEl.value.trim() : '';
    clearTimeout(customerSearchTimeout);
    if (!query) { hideCustomerDropdown(); return; }

    customerSearchTimeout = setTimeout(function() {
        fetch('/api/customers/search?q=' + encodeURIComponent(query) + '&limit=10', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            const customers = (data && data.success && data.customers) ? data.customers : [];
            showCustomerResults(customers);
        })
        .catch(function() {});
    }, 250);
}

function showCustomerResults(customers) {
    const dd = document.getElementById('customerDropdown');
    if (!dd) return;
    if (!customers || customers.length === 0) { dd.innerHTML = '<div style="padding:8px;color:#6b7280;font-size:12px;">No customers found</div>'; showCustomerDropdown(); return; }

    let html = '';
    customers.forEach(c => {
        const display = [c.name || '', c.phone || '', c.email || ''].filter(Boolean).join(' • ');
        const addressData = {
            address1: c.address_line1 || c.address1 || '',
            address2: c.address_line2 || c.address2 || '',
            city: c.city || c.address_city || '',
            province: c.province || c.address_province || '',
            postal_code: c.postal_code || '',
        };
        const payload = encodeURIComponent(JSON.stringify(addressData));
        html += `<div style="padding:8px 10px; cursor:pointer; font-size:13px; border-bottom:1px solid #f3f4f6;" onclick="selectCustomer('${c.id}','${(c.name||'').replace(/'/g, "\'")}', '${payload}')">${display}</div>`;
    });
    dd.innerHTML = html;
    showCustomerDropdown();
}

function selectCustomer(customerId, customerName, encodedAddress) {
    const addressData = encodedAddress ? JSON.parse(decodeURIComponent(encodedAddress)) : {};
    const searchInput = document.getElementById('customerSearch');
    const hiddenId = document.getElementById('selectedCustomerId');
    if (searchInput) searchInput.value = customerName || '';
    if (hiddenId) hiddenId.value = customerId || '';
    hideCustomerDropdown();

    // Optionally pre-fill new customer fields if visible
    const fields = [
        ['input[name="customer_address1"]', 'address1'],
        ['input[name="customer_address2"]', 'address2'],
        ['input[name="customer_city"]', 'city'],
        ['input[name="customer_province"]', 'province'],
        ['input[name="customer_postal_code"]', 'postal_code']
    ];
    fields.forEach(([sel, key]) => {
        const el = document.querySelector(sel);
        if (el && addressData[key]) el.value = addressData[key];
    });
}
// ==================== END CUSTOMER SELECTION HELPERS ====================

// Remove any stray duplicate "+ Create Order" label rendered outside the toolbar
document.addEventListener('DOMContentLoaded', function() {
    const containers = document.querySelectorAll('.kt-container-fixed');
    containers.forEach(container => {
        const suspects = Array.from(container.querySelectorAll('span, a, button, div'));
        suspects.forEach(el => {
            const t = (el.textContent || '').trim();
            if ((t === '+ Create Order' || t === 'Create Order') && !el.closest('[title="Create Order"]')) {
                // Hide only if it's not the actual toolbar button
                if (!el.className || !/border-emerald-500|create-order-btn/.test(el.className)) {
                    el.style.display = 'none';
                }
            }
        });
    });
});

// Shopify tab switching function
function switchShopifyTab(tab) {
    const tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.classList.add('opacity-60');

    fetch(`/orders/filter?source=shopify&tab=${encodeURIComponent(tab)}`)
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) return;

            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('source', 'shopify');
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);

            // Update tab styles
            const allTab = document.getElementById('tab-all');
            const approvalsTab = document.getElementById('tab-approvals');
            if (allTab && approvalsTab) {
                if (tab === 'all') {
                    allTab.className = 'px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 bg-white text-blue-600 shadow-sm';
                    approvalsTab.className = 'px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50';
                } else {
                    approvalsTab.className = 'px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 bg-white text-blue-600 shadow-sm';
                    allTab.className = 'px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50';
                }
            }

            // Rebuild table with fresh dataset and mark current tab
            rebuildTableWithOrders(data.orders, 'shopify', tab);
            // Update badges and pagination for this dataset
            refreshPaginationInfo({
                shopify_all_count: data.shopify_all_count,
                shopify_approvals_count: data.shopify_approvals_count,
                other_count: data.other_count
            });
        })
        .catch(err => console.error('Failed to switch Shopify tab:', err))
        .finally(() => {
            if (tableContainer) tableContainer.classList.remove('opacity-60');
        });
}

// Filter table content based on selected tab
function filterTableByTab(tab) {
    const tbody = document.getElementById('table-body');
    const rows = tbody.querySelectorAll('tr');
    
    rows.forEach(row => {
        if (tab === 'all') {
            // Show all Shopify orders
            row.style.display = '';
        } else if (tab === 'approvals') {
            // Show only unconverted orders - look for convertOrder function calls (approval buttons)
            const actionsCell = row.querySelector('td:last-child');
            if (
                actionsCell &&
                (
                    actionsCell.innerHTML.includes('convertOrder(') ||
                    actionsCell.innerHTML.includes('title="Approve') ||
                    actionsCell.innerHTML.toLowerCase().includes('approve (convert)')
                )
            ) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    });
}

// Function to switch to Shopify Approvals from main orders page
function switchToShopifyApprovals() {
    // Show loading state
    const tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) {
        tableContainer.innerHTML = '<div class="flex items-center justify-center h-64"><div class="text-gray-500">Loading Shopify approvals...</div></div>';
    }
    
    // Load Shopify approvals data via AJAX
    fetch('/orders/filter?source=shopify&tab=approvals')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the page to show Shopify approvals
                updatePageForShopifyApprovals(data.orders, {
                    shopify_all_count: data.shopify_all_count,
                    shopify_approvals_count: data.shopify_approvals_count,
                    other_count: data.other_count
                });
                
                // Update URL without page reload
                const url = new URL(window.location);
                url.searchParams.set('source', 'shopify');
                url.searchParams.set('tab', 'approvals');
                window.history.pushState({}, '', url);
            } else {
                console.error('Failed to load Shopify approvals:', data.message);
                if (tableContainer) {
                    tableContainer.innerHTML = '<div class="flex items-center justify-center h-64"><div class="text-red-500">Failed to load data</div></div>';
                }
            }
        })
        .catch(error => {
            console.error('Error loading Shopify approvals:', error);
            if (tableContainer) {
                tableContainer.innerHTML = '<div class="flex items-center justify-center h-64"><div class="text-red-500">Error loading data</div></div>';
            }
        });
}

// Return to the non-Shopify Invoices list from the dynamic Shopify view
function switchToInvoices() {
    const tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.classList.add('opacity-60');

    fetch('/orders/filter?source=other')
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success) return false;

            // Update URL for clarity
            const url = new URL(window.location);
            url.searchParams.set('source', 'other');
            url.searchParams.delete('tab');
            window.history.pushState({}, '', url);

            // Update title and tabs
            const pageTitle = document.querySelector('h1');
            if (pageTitle) pageTitle.textContent = 'Orders';

            const tabsContainer = document.querySelector('.flex.space-x-1.bg-gray-100');
            if (tabsContainer) {
                tabsContainer.innerHTML = `
                    <button class=\"px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 bg-white text-blue-600 shadow-sm\">
                        Invoices
                        <span class=\"ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold\" id=\"badge-invoices\">-</span>
                    </button>
                    <button onclick=\"switchToShopifyApprovals()\" class=\"px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50\">
                        Shopify Approvals
                        <span class=\"ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold\" id=\"badge-approvals\">-</span>
                    </button>
                `;
            }

            // Render invoices dataset
            rebuildTableWithOrders(data.orders, 'other', 'all');
            refreshPaginationInfo({
                shopify_all_count: data.shopify_all_count,
                shopify_approvals_count: data.shopify_approvals_count,
                other_count: data.other_count
            });
        })
        .catch(err => console.error('Failed to load invoices:', err))
        .finally(() => {
            if (tableContainer) tableContainer.classList.remove('opacity-60');
        });

    return false;
}

// Function to update page content for Shopify approvals
function updatePageForShopifyApprovals(orders, counts) {
    // Update page title
    const pageTitle = document.querySelector('h1');
    if (pageTitle) {
        pageTitle.textContent = 'Shopify Orders';
    }
    
    // Update tabs to show Shopify tabs
    const tabsContainer = document.querySelector('.flex.space-x-1.bg-gray-100');
    if (tabsContainer) {
        tabsContainer.innerHTML = `
            <a href="#" onclick="return switchToInvoices()" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50">
                Invoices
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-invoices">${counts && counts.other_count != null ? counts.other_count : '-'}</span>
            </a>
            <button class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 bg-white text-blue-600 shadow-sm">
                Shopify Approvals
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-approvals">${counts && counts.shopify_approvals_count != null ? counts.shopify_approvals_count : orders.length}</span>
            </button>
        `;
    }
    
    // Rebuild the table with Shopify approvals data
    rebuildTableWithOrders(orders, 'shopify', 'approvals');
    // Update pagination: hide if <= per-page
    refreshPaginationInfo(counts);
}

// Function to rebuild table with new orders data
function rebuildTableWithOrders(orders, source, tab) {
    const tableContainer = document.querySelector('.orders-table-container');
    if (!tableContainer) return;

    // If a table already exists, reuse its structure and just re-render data
    if (!tableContainer.querySelector('table')) {
        const tableHTML = `
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 sticky top-0 z-20">
                    <tr id="table-header"></tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="table-body"></tbody>
            </table>
        `;
        tableContainer.innerHTML = tableHTML;
    }

    // Set runtime context so rendering logic can detect Shopify Approvals
    window.currentSource = source;
    window.currentTab = tab;

    // Feed data into existing render pipeline
    window.ordersData = orders || [];
    window.allOrders = [...window.ordersData];
    window.filteredOrders = [...window.ordersData];

    // Render using existing helpers to avoid duplication
    renderOrdersTable();
    refreshPaginationInfo();
}

// Update badges and pagination info if response provided counts
function refreshPaginationInfo(counts) {
    try {
        const perPageSelect = document.getElementById('per-page-selector');
        const perPage = perPageSelect ? parseInt(perPageSelect.value, 10) : 25;
        const total = (window.ordersData || []).length;
        const info = document.getElementById('pagination-info');
        if (info) info.textContent = `1-${Math.min(total, perPage)} of ${total}`;

        // Hide numbered pagination when single page
        const pager = document.getElementById('numeric-pager');
        if (pager) {
            if (total <= perPage) {
                pager.classList.add('hidden');
            } else {
                pager.classList.remove('hidden');
            }
        }

        // Update badges when counts provided
        if (counts) {
            const bAll = document.getElementById('badge-all');
            const bApp = document.getElementById('badge-approvals');
            const bInv = document.getElementById('badge-invoices');
            if (bAll && counts.shopify_all_count != null) bAll.textContent = counts.shopify_all_count;
            if (bApp && counts.shopify_approvals_count != null) bApp.textContent = counts.shopify_approvals_count;
            if (bInv && counts.other_count != null) bInv.textContent = counts.other_count;
        }
    } catch (e) { console.warn('refreshPaginationInfo failed', e); }
}

</script>
@endpush