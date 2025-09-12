{{-- resources/views/auth/login.blade.php --}}

@extends('layouts.app')

@section('title', 'Orders')

@push('styles')
<style>
/* Enhanced Orders Page Styles */
.orders-table-container {
    /* Custom scrollbar for better UX */
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
}

.orders-table-container::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.orders-table-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.orders-table-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
    transition: background 0.2s ease;
}

.orders-table-container::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Enhanced table row hover effects */
.orders-table-container tbody tr:hover {
    background-color: #f8fafc !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    position: relative;
    z-index: 1;
}

/* Improved sticky header */
.orders-table-container thead {
    position: sticky;
    top: 0;
    z-index: 20;
    background: white;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* Better table spacing */
.orders-table-container {
    border-radius: 12px;
    overflow: hidden;
}

.orders-table-container table {
    border-collapse: separate;
    border-spacing: 0;
}

/* Improved table cell spacing */
.orders-table-container td {
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}

.orders-table-container tbody tr:last-child td {
    border-bottom: none;
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

<!-- Enhanced Layout with Sticky Elements -->
<div class="min-h-screen bg-gray-50">
    <!-- Sticky Top Bar -->
    <div class="sticky top-0 z-30 bg-white border-b border-gray-200 shadow-sm">
<div class="kt-container-fixed">
            <div class="flex items-center justify-between py-4">
                <!-- Left: Title + Tabs + Quick Stats -->
                <div class="flex items-center gap-6">
                    <h1 class="text-xl font-semibold text-gray-900">Orders</h1>
                    
                    <!-- Compact Tabs -->
                    <div class="flex bg-gray-100 rounded-lg p-1">
                        <a href="{{ url('/orders') }}?source=other" 
                           class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'other' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                            Invoices
                            <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $otherCount }}</span>
                        </a>
                        <a href="{{ url('/orders') }}?source=shopify" 
                           class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'shopify' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                            Shopify
                            <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $shopifyCount }}</span>
                        </a>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div id="quick-stats" class="text-sm text-gray-500 font-medium">
                        <span id="results-count">Showing {{ $orders->total() }} orders</span>
                    </div>
                </div>

                <!-- Right: Inline Filters + Actions -->
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <!-- Compact Search -->
                    <div class="relative">
                        <input type="text" 
                               id="orderSearch" 
                               placeholder="Search orders..." 
                               class="w-64 xl:w-64 lg:w-56 md:w-48 sm:w-40 pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ki-filled ki-magnifier text-gray-400"></i>
                        </div>
                    </div>
                    
                    <!-- Compact Status Filter -->
                    <select id="statusFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="on-hold">On Hold</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="refunded">Refunded</option>
                        <option value="failed">Failed</option>
                    </select>
                    
                    <!-- Compact Date Filter -->
                    <input type="date" 
                           id="dateFilter" 
                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    
                    <!-- Clear Button -->
                    <button onclick="clearFilters()" 
                            class="px-2.5 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-50 rounded-lg text-sm border border-gray-200 transition-colors" title="Clear all filters">
                        <i class="ki-filled ki-cross text-xs"></i>
                    </button>
                    
                    <!-- Action Buttons -->
                    <div class="flex gap-2 ml-2 pl-2 border-l border-gray-200 shrink-0">
                        <button onclick="openColumnSettings()" 
                                class="flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all-smooth btn-enhanced text-sm" title="Columns">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="12" y1="8" x2="12" y2="21"/></svg>
                            Columns
                        </button>
                        <button onclick="openImportModal()" 
                                class="flex items-center px-3 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-all-smooth btn-enhanced text-sm" title="Import">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Import
                        </button>
                        <button onclick="createNewOrder()" 
                                class="flex items-center px-3 py-2 rounded-lg transition-all-smooth text-sm border border-emerald-500 text-emerald-700 hover:bg-emerald-50 font-medium" title="Create Order">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Create Order
                        </button>
                    </div>
                    </div>
            </div>
        </div>
            </div>

    <!-- Main Content Container -->
    <div class="kt-container-fixed pt-8 pb-40">

        <!-- Enhanced Table Container with Proper Spacing -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <!-- Table with Sticky Header -->
            <div class="orders-table-container relative pb-24" style="max-height: calc(100vh - 320px); overflow-y: auto;">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-white z-20 shadow-sm border-b-2 border-gray-200">
                        <tr id="table-header" class="bg-gradient-to-r from-gray-50 to-blue-50">
                            <!-- Dynamic headers will be generated by JavaScript -->
                                </tr>
                            </thead>
                    <tbody id="table-body" class="bg-white divide-y divide-gray-100">
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
    
    <!-- Enhanced Bottom Pagination Bar -->
    <div class="fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-sm border-t border-gray-200 shadow-lg z-40">
        <div class="kt-container-fixed">
            <div class="flex items-center justify-between py-4">
                <!-- Left: Compact Info -->
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <span>Show</span>
                        <select id="per-page-selector" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm">
                            <option value="10" {{ $orders->perPage() == 10 ? 'selected' : '' }}>10</option>
                            <option value="25" {{ $orders->perPage() == 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ $orders->perPage() == 50 ? 'selected' : '' }}>50</option>
                            <option value="100" {{ $orders->perPage() == 100 ? 'selected' : '' }}>100</option>
                        </select>
                        <span>entries</span>
                    </div>
                    <div class="text-sm font-medium text-gray-800">
                        <span id="pagination-info">{{ $orders->firstItem() ?? 0 }}-{{ $orders->lastItem() ?? 0 }} of {{ number_format($orders->total()) }}</span>
                    </div>
                </div>
                
                <!-- Right: Pagination Controls -->
                <div class="flex items-center gap-2">
                    @if($orders->onFirstPage())
                        <button class="px-3 py-1.5 text-sm pagination-btn" disabled>
                            <i class="ki-filled ki-left mr-1"></i> Previous
                        </button>
                    @else
                        <a href="{{ $orders->previousPageUrl() }}" class="px-3 py-1.5 text-sm pagination-btn">
                            <i class="ki-filled ki-left mr-1"></i> Previous
                        </a>
                    @endif
                    
                    <div class="flex items-center gap-1">
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
                                <span class="px-3 py-1.5 text-sm pagination-btn active">{{ $page }}</span>
                            @else
                                <a href="{{ $orders->url($page) }}" class="px-3 py-1.5 text-sm pagination-btn">{{ $page }}</a>
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
                        <a href="{{ $orders->nextPageUrl() }}" class="px-3 py-1.5 text-sm pagination-btn">
                            Next <i class="ki-filled ki-right ml-1"></i>
                        </a>
                    @else
                        <button class="px-3 py-1.5 text-sm pagination-btn" disabled>
                            Next <i class="ki-filled ki-right ml-1"></i>
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
            <button onclick="closeModal('editOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
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
            html += '<p><strong>Customer:</strong> ' + (order.name || 'N/A') + '</p>';
            html += '<p><strong>Email:</strong> ' + (order.contact_email || 'N/A') + '</p>';
            html += '<p><strong>Phone:</strong> ' + ((order.customer_phone || order.address_phone || '').toString() || 'N/A') + '</p>';
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
                    var lineTotal = parseFloat((it.line_total != null ? it.line_total : (unit * qty)) || 0);
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
                html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Subtotal</td><td style="padding: 8px; text-align:right; font-weight:600;">' + formatCurrency(itemsSubtotal, order.currency) + '</td></tr>';
                if (order.discount_total) {
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Discount</td><td style="padding: 8px; text-align:right;">-' + formatCurrency(order.discount_total, order.currency) + '</td></tr>';
                }
                if (order.shipping_total) {
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Shipping</td><td style="padding: 8px; text-align:right;">' + formatCurrency(order.shipping_total, order.currency) + '</td></tr>';
                }
                if (order.total_tax) {
                    html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Tax</td><td style="padding: 8px; text-align:right;">' + formatCurrency(order.total_tax, order.currency) + '</td></tr>';
                }
                html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#111827; font-weight:700;">Total</td><td style="padding: 8px; text-align:right; font-weight:700;">' + formatCurrency(order.total_price, order.currency) + '</td></tr>';
                html += '</tfoot>';
                html += '</table>';
                html += '</div>';
            } else {
                html += '<div style="text-align:center; color:#6b7280; padding: 10px 0;">No line items</div>';
            }
            html += '</div>';

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

// Edit Order Details
function editOrderDetails(orderId) {
    console.log('Edit order details clicked for order:', orderId);
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
                    </div>
                </div>
            </div>

            <!-- Line Items Section -->
            <div style="background-color: #fefefe; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0;">Line Items</h4>
                    <button type="button" onclick="addLineItem()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
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
                                <input type="number" step="0.001" name="items[${index}][quantity]" value="${item.quantity || 1}" min="0.001"
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
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Subtotal</label>
                        <input type="number" step="0.01" name="subtotal_price" value="${order.subtotal_price || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" readonly>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Total Tax</label>
                        <input type="number" step="0.01" name="total_tax" value="${order.total_tax || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateOrderTotal()">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Total Price</label>
                        <input type="number" step="0.01" name="total_price" value="${order.total_price || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-weight: 600;" readonly>
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
                        style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    Save Changes
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
                   onkeyup="searchProducts(this, ${lineItemIndex})" 
                   onfocus="showProductDropdown(${lineItemIndex})"
                   placeholder="Type to search products...">
            <div id="productDropdown_${lineItemIndex}" class="product-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
            <input type="hidden" name="items[${lineItemIndex}][id]" value="">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
            <input type="number" step="0.001" name="items[${lineItemIndex}][quantity]" value="1" min="0.001"
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
        const price = parseFloat(item.querySelector(`input[name*="[price]"]`).value) || 0;
        subtotal += quantity * price;
    });
    
    const subtotalInput = document.querySelector('input[name="subtotal_price"]');
    if (subtotalInput) {
        subtotalInput.value = subtotal.toFixed(2);
        updateOrderTotal();
    }
}

function updateOrderTotal() {
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]').value) || 0;
    const tax = parseFloat(document.querySelector('input[name="total_tax"]').value) || 0;
    const total = subtotal + tax;
    
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

function saveOrderChanges(orderId) {
    const form = document.getElementById('editOrderForm');
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    submitBtn.textContent = 'Saving...';
    submitBtn.disabled = true;
    
    const data = {};
    formData.forEach((value, key) => data[key] = value);
    
    // For now, just show success message - you can implement actual save later
    setTimeout(() => {
        alert('Order updated successfully! (Demo - actual save not implemented yet)');
        closeModal('editOrderModal');
        submitBtn.textContent = 'Save Changes';
        submitBtn.disabled = false;
    }, 1000);
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
});

// Debug function to reset columns (can be called from browser console)
window.resetOrderColumns = function() {
    localStorage.removeItem('orderTableColumns');
    location.reload();
};

// Product search functionality
let productSearchTimeout = null;

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

function showProductResults(products, index) {
    const dropdown = document.getElementById(`productDropdown_${index}`);
    if (!dropdown) return;
    
    if (products.length === 0) {
        dropdown.innerHTML = '<div style="padding: 8px; color: #6b7280; font-size: 12px;">No products found</div>';
    } else {
        dropdown.innerHTML = products.map(product => `
            <div onclick="selectProduct(${index}, '${product.id}', '${product.name.replace(/'/g, "\\'")}', ${product.price})" 
                 style="padding: 8px; cursor: pointer; border-bottom: 1px solid #f3f4f6; hover:background-color: #f9fafb;"
                 onmouseover="this.style.backgroundColor='#f9fafb'" 
                 onmouseout="this.style.backgroundColor='white'">
                <div style="font-weight: 500; font-size: 13px;">${product.name}</div>
                <div style="font-size: 11px; color: #6b7280;">
                    ${product.sku ? 'SKU: ' + product.sku + ' | ' : ''}Price: PKR ${product.price} | Stock: ${product.inventory || 0}
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
    if (priceInput) priceInput.value = price;
    
    // Update the line total
    updateLineTotal(index);
    
    // Hide dropdown
    hideProductDropdown(index);
}

// Update order total calculations
function updateOrderTotal() {
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
    const discount = parseFloat(document.querySelector('input[name="discount_total"]')?.value) || 0;
    const shipping = parseFloat(document.querySelector('input[name="shipping_total"]')?.value) || 0;
    const tax = parseFloat(document.querySelector('input[name="total_tax"]')?.value) || 0;
    
    const total = subtotal - discount + shipping + tax;
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

// Update subtotal from line items
function updateSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('.line-item').forEach(item => {
        const lineTotal = parseFloat(item.querySelector('input[name*="[line_total]"]')?.value) || 0;
        subtotal += lineTotal;
    });
    
    const subtotalInput = document.querySelector('input[name="subtotal_price"]');
    if (subtotalInput) {
        subtotalInput.value = subtotal.toFixed(2);
    }
    
    updateOrderTotal();
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
    total_tax: { label: 'Tax', width: 'w-[100px]', key: 'total_tax' },
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
    actions: { label: 'Actions', width: 'w-[120px]', key: 'actions', fixed: true }
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
    { id: 'total_tax', visible: false },
    { id: 'total_weight', visible: false },
    { id: 'coupon_code', visible: false },
    { id: 'note', visible: false },
    { id: 'created_at', visible: false },
    { id: 'updated_at', visible: false }
];

// Current column settings
let currentColumns = JSON.parse(localStorage.getItem('orderTableColumns')) || defaultColumns;

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

function renderOrdersTable() {
    const tableHead = document.querySelector('#ordersTable thead tr');
    const tbody = document.querySelector('#ordersTable tbody');
    
    if (!tableHead || !tbody) {
        console.error('Table elements not found');
        return;
    }
    
    // Clear existing content
    tableHead.innerHTML = '';
    tbody.innerHTML = '';
    
    // Create header
    currentColumns.forEach(column => {
        if (column.visible) {
            const columnConfig = availableColumns[column.id];
            if (columnConfig) {
                const th = document.createElement('th');
                th.className = `px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider ${columnConfig.width}`;
                th.innerHTML = columnConfig.label;
                tableHead.appendChild(th);
            }
        }
    });
    
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
            row.className = `hover:bg-gray-50/50 transition-all duration-200 ${index % 2 === 0 ? 'bg-white' : 'bg-gray-50/20'}`;
            
            currentColumns.forEach(column => {
                if (column.visible) {
                    try {
                        const td = document.createElement('td');
                        td.className = 'px-6 py-5 align-middle';
                        const cellContent = getCellContent(order, column.id);
                        td.innerHTML = cellContent;
                        row.appendChild(td);
                    } catch (cellError) {
                        console.error(`Error rendering cell ${column.id}:`, cellError);
                        const td = document.createElement('td');
                        td.className = 'px-6 py-5 align-middle';
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
            // Handle ISO format: "2025-09-09T17:41:03.000000Z"
            let cleanDate = dateStr;
            
            // Remove timezone info if present
            if (cleanDate.includes('T')) {
                cleanDate = cleanDate.split('T')[0];
            }
            
            // Parse and format
            const date = new Date(cleanDate);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
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
        case 'total_tax':
            return formatCurrency(order.total_tax);
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
                th.className = `px-6 py-5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider ${columnConfig.width}`;
                th.style.background = 'linear-gradient(135deg, #fafbfc 0%, #f8fafc 100%)';
                th.style.borderBottom = '2px solid #e2e8f0';
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
            row.className = `hover:bg-gray-50/50 transition-all duration-200 ${index % 2 === 0 ? 'bg-white' : 'bg-gray-50/20'}`;
            
            currentColumns.forEach(column => {
                if (column.visible) {
                    try {
                        const td = document.createElement('td');
                        td.className = 'px-6 py-5 align-middle';
                        const cellContent = getCellContent(order, column.id);
                        td.innerHTML = cellContent;
                        row.appendChild(td);
                    } catch (cellError) {
                        console.error(`Error rendering cell ${column.id}:`, cellError);
                        const td = document.createElement('td');
                        td.className = 'px-6 py-5 align-middle';
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
            // Handle ISO format: "2025-09-09T17:41:03.000000Z"
            let cleanDate = dateStr;
            
            // Remove timezone info if present
            if (cleanDate.includes('T')) {
                cleanDate = cleanDate.split('T')[0];
            }
            
            // Parse and format
            const date = new Date(cleanDate);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
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
        case 'total_tax':
            return formatCurrency(order.total_tax);
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
            return `
                <div class="flex items-center justify-center gap-1.5">
                    <button onclick="viewOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 hover:border-blue-300 hover:shadow-sm transition-all duration-200 group" title="View Order Details">
                        <svg class="w-4 h-4 group-hover:scale-110 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                    </button>
                    <button onclick="editOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-amber-600 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 hover:border-amber-300 hover:shadow-sm transition-all duration-200 group" title="Edit Order">
                        <svg class="w-4 h-4 group-hover:scale-110 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                    </button>
                    <button onclick="window.open('/orders/${order.id}/invoice', '_blank')" class="inline-flex items-center justify-center w-8 h-8 text-emerald-600 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 hover:border-emerald-300 hover:shadow-sm transition-all duration-200 group" title="View Invoice (PDF)">
                        <svg class="w-4 h-4 group-hover:scale-110 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </button>
                </div>
            `;
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
            } else {
                // Reset to current page data if search is too short
                window.filteredOrders = [...window.ordersData];
                renderOrdersWithFilters(window.filteredOrders);
                updateResultsCount();
            }
        }, 500);
    });
    
    // Filter functionality - make API call for full dataset
    statusFilter.addEventListener('change', function() {
        fetchFilteredOrders();
    });
    
    dateFilter.addEventListener('change', function() {
        fetchFilteredOrders();
    });
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
            // Fallback to current page data
            window.filteredOrders = [...window.ordersData];
            renderOrdersWithFilters(window.filteredOrders);
            updateResultsCount();
        }
    })
    .catch(error => {
        console.error('Filter request failed:', error);
        // Fallback to current page data
        window.filteredOrders = [...window.ordersData];
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
        if (filteredCount === totalCount) {
            infoElement.textContent = `${filteredCount} orders`;
        } else {
            infoElement.textContent = `${filteredCount} of ${totalCount} orders`;
        }
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
    
    // Fetch fresh unfiltered data from server
    fetchFilteredOrders();
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
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Order Date</label>
                            <input type="date" name="order_date" required value="${new Date().toISOString().split('T')[0]}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
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
                            <input type="number" step="0.01" name="discount_total" value="0" onchange="updateOrderTotal()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Shipping</label>
                            <input type="number" step="0.01" name="shipping_total" value="0" onchange="updateOrderTotal()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Tax</label>
                            <input type="number" step="0.01" name="total_tax" value="0" onchange="updateOrderTotal()" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
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
                    <button type="button" onclick="addLineItem()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
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
    
    // Collect line items
    const items = [];
    document.querySelectorAll('.line-item').forEach((item, index) => {
        const name = item.querySelector(`input[name*="[name]"]`)?.value;
        const quantity = parseFloat(item.querySelector(`input[name*="[quantity]"]`)?.value) || 0;
        const unitPrice = parseFloat(item.querySelector(`input[name*="[unit_price]"]`)?.value) || 0;
        
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
        alert('Please add at least one line item');
        return;
    }
    
    // Prepare data
    const orderData = {
        customer_id: formData.get('customer_id'),
        order_status: formData.get('order_status'),
        order_date: formData.get('order_date'),
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        discount_total: parseFloat(formData.get('discount_total')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_tax: parseFloat(formData.get('total_tax')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
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

// Update order total calculations
function updateOrderTotal() {
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
    const discount = parseFloat(document.querySelector('input[name="discount_total"]')?.value) || 0;
    const shipping = parseFloat(document.querySelector('input[name="shipping_total"]')?.value) || 0;
    const tax = parseFloat(document.querySelector('input[name="total_tax"]')?.value) || 0;
    
    const total = subtotal - discount + shipping + tax;
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

// Update subtotal from line items
function updateSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('.line-item').forEach(item => {
        const lineTotal = parseFloat(item.querySelector('input[name*="[line_total]"]')?.value) || 0;
        subtotal += lineTotal;
    });
    
    const subtotalInput = document.querySelector('input[name="subtotal_price"]');
    if (subtotalInput) {
        subtotalInput.value = subtotal.toFixed(2);
    }
    
    updateOrderTotal();
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

    customerSearchTimeout = setTimeout(() => {
        fetch(`/api/customers/search?q=${encodeURIComponent(query)}&limit=10`, {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            const customers = (data && data.success) ? data.customers : [];
            showCustomerResults(customers);
        })
        .catch(() => { /* silent */ });
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

</script>
@endpush