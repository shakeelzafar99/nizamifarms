{{-- resources/views/auth/login.blade.php --}}

@extends('layouts.app')

@section('title', 'Orders')

@push('custom_css')
<style>
/* Sticky Action Toolbar - Always Visible */
.sticky-action-toolbar {
    position: sticky;
    top: 0;
    right: 0;
    z-index: 30;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    padding: 12px 16px;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08), 0 4px 24px rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(226, 232, 240, 0.8);
    backdrop-filter: blur(10px);
    margin-bottom: 16px;
}

/* Action Button Base Styles */
.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 18px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 10px;
    border: 1.5px solid;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    white-space: nowrap;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.action-btn:active {
    transform: translateY(0);
}

/* Primary Button (Create Order) */
.action-btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border-color: #2563eb;
    color: #ffffff;
}

.action-btn-primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 16px rgba(37, 99, 235, 0.3);
}

/* Secondary Button (Columns) */
.action-btn-secondary {
    background: #ffffff;
    border-color: #d1d5db;
    color: #374151;
}

.action-btn-secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

/* Purple Button (Bulk Status) */
.action-btn-purple {
    background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
    border-color: #c084fc;
    color: #7c3aed;
}

.action-btn-purple:hover {
    background: linear-gradient(135deg, #e9d5ff 0%, #d8b4fe 100%);
    box-shadow: 0 4px 16px rgba(168, 85, 247, 0.25);
}

/* Cyan Button (Bulk Assign Rider) */
.action-btn-cyan {
    background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
    border-color: #22d3ee;
    color: #0e7490;
}

.action-btn-cyan:hover {
    background: linear-gradient(135deg, #a5f3fc 0%, #67e8f9 100%);
    box-shadow: 0 4px 16px rgba(6, 182, 212, 0.25);
}

/* Responsive adjustments */
@media (max-width: 1024px) {
    .sticky-action-toolbar {
        gap: 8px;
    }
    
    .action-btn {
        padding: 8px 14px;
        font-size: 13px;
    }
    
    .action-btn svg {
        width: 14px;
        height: 14px;
    }
}

@media (max-width: 768px) {
    .sticky-action-toolbar {
        position: relative;
        top: auto;
    }
}

/* Status Cards Styles */
.status-card.active .border-gray-200 {
    border-color: #3b82f6 !important;
    border-width: 2px !important;
}

.status-card:hover .border-gray-200 {
    border-color: #d1d5db !important;
}

/* Show status cards only for open orders tab */
#openOrdersStatusCards {
    display: {{ $source === 'other' && ($tab ?? 'all') === 'open' ? 'block' : 'none' }};
}

/* Show riders cards only for riders tab */
#ridersCards {
    display: {{ $source === 'other' && ($tab ?? 'all') === 'riders' ? 'block' : 'none' }};
}

/* Responsive status cards */
@media (max-width: 768px) {
    #statusCardsContainer {
        flex-direction: column;
    }
    
    .status-card {
        width: 100%;
    }
    
    .status-card > div {
        min-width: auto !important;
    }
}
</style>
<style>
/* Modern Professional Orders Page Styles */
.orders-table-container {
    /* Custom scrollbar for better UX */
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f8fafc;
    font-size: 15px;
    line-height: 1.5;
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

/* Zebra stripes for better readability */
.orders-table-container tbody tr:nth-child(even) {
    background-color: #f9fafb;
}

/* Modern table row hover effects */
.orders-table-container tbody tr {
    transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
}

.orders-table-container tbody tr:hover {
    background: #f0f9ff !important;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
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
    padding: 12px 16px;
    font-weight: 600;
    font-size: 13px;
    color: #6b7280;
    text-transform: none;
    letter-spacing: -0.01em;
    border-bottom: 2px solid #e5e7eb;
    border-right: 1px solid #f3f4f6;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    background: #fafbfc;
}

.orders-table-container thead th:last-child {
    border-right: none;
}

/* Modern table styling */
.orders-table-container table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

/* Enhanced table cell styling - Modern design */
.orders-table-container td {
    padding: 10px 16px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
    font-size: 15px;
    line-height: 1.5;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 0;
    color: #111827;
    border-right: 1px solid #f3f4f6;
}

.orders-table-container td:last-child {
    border-right: none;
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

.table-cell-address-compact {
    max-width: 140px;
    line-height: 1.2;
}

.table-cell-address-compact:hover {
    background-color: #f9fafb;
    border-radius: 4px;
    padding: 2px 4px;
    margin: -2px -4px;
}

/* Precise customer name clickable area */
.customer-name-link {
    display: inline;
    padding: 0;
    margin: 0;
    border: none;
    background: none;
}
.customer-name-link:hover {
    text-decoration: underline;
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

/* Specific cell content handling */
.table-cell-note {
    white-space: normal !important;
    word-wrap: break-word;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    line-height: 1.4;
    max-width: 200px;
}

.table-cell-id {
    font-family: monospace;
    font-size: 12px;
    text-align: center;
    font-weight: 500;
}

.table-cell-order-number {
    font-family: monospace;
    font-weight: 600;
    font-size: 13px;
    color: #1f2937;
}

.table-cell-customer-name {
    white-space: normal !important;
    font-weight: 500;
    color: #1f2937;
}

.table-cell-customer-phone {
    font-family: monospace;
    font-size: 12px;
    white-space: normal !important;
}
.table-cell-address {
    white-space: normal !important;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    max-width: 200px;
    font-size: 12px;
    color: #637381;
}

.table-cell-date {
    font-size: 13px;
    color: #202223;
    font-weight: 400;
}

.table-cell-total {
    font-weight: 600;
    color: #202223;
    font-size: 14px;
}

/* Keep Actions column visible */
.sticky-actions {
    position: sticky;
    right: 0;
    background: #ffffff;
    z-index: 30; /* above row cells */
    box-shadow: -8px 0 8px -8px rgba(0,0,0,0.08);
    border-left: 1px solid #e1e5e9;
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

/* Three-dot action menu */
.action-menu {
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 0.25rem;
    min-width: 10rem;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    z-index: 50;
    display: none;
}

.action-menu.show {
    display: block;
}

.action-menu-item {
    display: flex;
    align-items: center;
    padding: 0.5rem 0.75rem;
    font-size: 14px;
    color: #374151;
    cursor: pointer;
    transition: background-color 0.15s;
}

.action-menu-item:hover {
    background-color: #f3f4f6;
}

.action-menu-item:first-child {
    border-radius: 0.5rem 0.5rem 0 0;
}

.action-menu-item:last-child {
    border-radius: 0 0 0.5rem 0.5rem;
}

.action-menu-item svg {
    width: 1rem;
    height: 1rem;
    margin-right: 0.5rem;
    color: #6b7280;
}

/* Floating bulk action bar */
.bulk-action-bar {
    position: fixed;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 1rem;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 1rem;
    z-index: 40;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.bulk-action-bar.show {
    transform: translateX(-50%) translateY(0);
}

.bulk-action-bar button {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
}

.bulk-action-bar button:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: translateY(-1px);
}

/* Toast notifications */
.toast-container {
    position: fixed;
    top: 1rem;
    right: 1rem;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.toast {
    min-width: 20rem;
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    padding: 1rem;
    display: flex;
    align-items: start;
    gap: 0.75rem;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.toast.success {
    border-left: 4px solid #10b981;
}

.toast.error {
    border-left: 4px solid #ef4444;
}

.toast-icon {
    flex-shrink: 0;
    width: 1.25rem;
    height: 1.25rem;
}

.toast-content {
    flex: 1;
}

.toast-title {
    font-weight: 600;
    font-size: 14px;
    color: #111827;
    margin-bottom: 0.25rem;
}

.toast-message {
    font-size: 13px;
    color: #6b7280;
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

/* ⭐ SMART SYNC: Toast animations */
@keyframes slideIn {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOut {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(400px);
        opacity: 0;
    }
}

@keyframes spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
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

<!-- Modern Orders Layout -->
<div class="min-h-screen bg-gray-50">
    
    <!-- Modern Sticky Header with Blur -->
    <div class="sticky top-0 z-30 bg-white/95 backdrop-blur-md border-b border-gray-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 lg:px-6">
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
                                <div class="text-2xl">[ALL]</div>
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
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'other' && ($tab ?? 'all') === 'all' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                                Invoices
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $otherCount }}</span>
                            </a>
                            <button onclick="switchToOpenOrders()" 
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'other' && ($tab ?? 'all') === 'open' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                                Open Orders
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $openCount }}</span>
                            </button>
                            <button onclick="switchToRiders()" 
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'other' && ($tab ?? 'all') === 'riders' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                                Riders
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-riders">{{ $openCount }}</span>
                            </button>
                            @if($canViewShopify ?? false)
                            <button onclick="switchToShopifyApprovals()" 
                               class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 {{ $source === 'shopify' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800 hover:bg-gray-50' }}">
                                Shopify Approvals
                                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold">{{ $shopifyCount }}</span>
                            </button>
                            @endif
                        @endif
                    </div>
                    
                    <!-- Sticky Action Buttons Toolbar -->
                    <div class="sticky-action-toolbar">
                        @if($user->hasPermission('create_orders'))
                        <button onclick="createNewOrder()" class="action-btn action-btn-primary">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            Create order
                        </button>
                        @endif
                        
                        <button onclick="openColumnSettings()" class="action-btn action-btn-secondary">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h2a2 2 0 002-2z"></path>
                            </svg>
                            Columns
                        </button>
                        
                        @if($source !== 'shopify')
                        <button onclick="openBulkStatusModal()" class="action-btn action-btn-purple">
                            <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>
                            Bulk Status
                        </button>
                        <button onclick="openBulkRiderModal()" class="action-btn action-btn-cyan">
                            <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                            Bulk Assign Rider
                        </button>
                        @endif
                    </div>
                </div>

                <!-- Open Orders Status Cards (only show for open orders tab) -->
                <!-- Status Cards Section - Always present but hidden by default -->
                <div class="mt-4 mb-6" id="openOrdersStatusCards" style="display: {{ ($source === 'other' && ($tab ?? 'all') === 'open') ? 'block' : 'none' }};">
                    <div class="flex flex-wrap gap-3" id="statusCardsContainer">
                        <!-- Status cards will be loaded here via JavaScript -->
                        <div class="flex items-center justify-center py-8 text-gray-500">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Loading status cards...
                        </div>
                    </div>
                </div>

                <!-- Riders Cards (only show for riders tab) -->
                <div class="mt-4 mb-6" id="ridersCards" style="display: {{ ($source === 'other' && ($tab ?? 'all') === 'riders') ? 'block' : 'none' }};">
                    <div class="flex flex-wrap gap-3" id="ridersCardsContainer">
                        <!-- Rider cards will be loaded here via JavaScript -->
                        <div class="flex items-center justify-center py-8 text-gray-500">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Loading rider cards...
                        </div>
                    </div>
                </div>
                <!-- Modern Search and Filters -->
                <div class="mt-3 space-y-2">
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
                               class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-[15px] shadow-sm transition-shadow">
                    </div>
                    
                    <!-- Filter Row with Better Spacing -->
                    <div class="flex flex-wrap items-center gap-2 pb-2">
                        <span class="text-[15px] font-medium text-gray-600">Filters:</span>
                        
                        <select id="statusFilter" class="px-3 py-2 text-[15px] border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm transition-shadow">
                                <option value="">All status</option>
                    </select>
                            
                            <div class="flex items-center gap-2">
                                <label for="dateFilter" class="text-sm text-gray-500">Order date</label>
                                <input type="date" 
                                       id="dateFilter" 
                                   class="px-3 py-2 text-[15px] border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm transition-shadow">
                            </div>
                            <div class="flex items-center gap-2">
                                <label for="deliveryDateFilter" class="text-sm text-gray-500">Delivery date</label>
                                <input type="date" 
                                       id="deliveryDateFilter" 
                                   class="px-3 py-2 text-[15px] border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm transition-shadow">
                            </div>
                            
                            <button onclick="clearFilters()" 
                                class="inline-flex items-center px-3 py-2 text-[15px] font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg border border-gray-300 shadow-sm transition-all" 
                                    title="Clear filters">
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            Clear
                            </button>
                    </div>
                </div>
            </div>
        </div>
            </div>

    <!-- Modern Orders Table Container -->
    <div class="max-w-7xl mx-auto px-4 lg:px-6 pt-4 pb-6">
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden shadow-sm">
            <div class="orders-table-container relative" style="height: calc(100vh - 180px); overflow: auto;">
                <table class="min-w-full divide-y divide-gray-200" style="width: max-content; min-width: 100%;">
                    <colgroup id="table-colgroup"></colgroup>
                    <thead class="bg-gray-50 sticky top-0 z-20">
                        <tr id="table-header">
                            <!-- Dynamic headers will be generated by JavaScript -->
                                </tr>
                            </thead>
                    <tbody id="table-body" class="bg-white divide-y divide-gray-200">
                        <!-- Dynamic rows will be generated by JavaScript -->
                            </tbody>
                        </table>

                <!-- Modern Skeleton Loading State -->
                <div id="loading-state" class="hidden">
                    <div class="p-4">
                        <!-- Skeleton rows -->
                        <div class="space-y-3 animate-pulse">
                            <!-- Repeat 5 skeleton rows -->
                            <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
                                <div class="h-4 w-4 bg-gray-200 rounded"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                                <div class="h-4 w-32 bg-gray-200 rounded"></div>
                                <div class="h-4 w-40 bg-gray-200 rounded"></div>
                                <div class="h-4 w-28 bg-gray-200 rounded"></div>
                                <div class="h-4 w-20 bg-gray-200 rounded"></div>
                                <div class="flex-1"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                    </div>
                            <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
                                <div class="h-4 w-4 bg-gray-200 rounded"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                                <div class="h-4 w-32 bg-gray-200 rounded"></div>
                                <div class="h-4 w-40 bg-gray-200 rounded"></div>
                                <div class="h-4 w-28 bg-gray-200 rounded"></div>
                                <div class="h-4 w-20 bg-gray-200 rounded"></div>
                                <div class="flex-1"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                            </div>
                            <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
                                <div class="h-4 w-4 bg-gray-200 rounded"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                                <div class="h-4 w-32 bg-gray-200 rounded"></div>
                                <div class="h-4 w-40 bg-gray-200 rounded"></div>
                                <div class="h-4 w-28 bg-gray-200 rounded"></div>
                                <div class="h-4 w-20 bg-gray-200 rounded"></div>
                                <div class="flex-1"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                            </div>
                            <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
                                <div class="h-4 w-4 bg-gray-200 rounded"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                                <div class="h-4 w-32 bg-gray-200 rounded"></div>
                                <div class="h-4 w-40 bg-gray-200 rounded"></div>
                                <div class="h-4 w-28 bg-gray-200 rounded"></div>
                                <div class="h-4 w-20 bg-gray-200 rounded"></div>
                                <div class="flex-1"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                            </div>
                            <div class="flex items-center gap-4 p-3 bg-gray-50 rounded-lg">
                                <div class="h-4 w-4 bg-gray-200 rounded"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                                <div class="h-4 w-32 bg-gray-200 rounded"></div>
                                <div class="h-4 w-40 bg-gray-200 rounded"></div>
                                <div class="h-4 w-28 bg-gray-200 rounded"></div>
                                <div class="h-4 w-20 bg-gray-200 rounded"></div>
                                <div class="flex-1"></div>
                                <div class="h-4 w-24 bg-gray-200 rounded"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modern Empty State Card -->
                <div id="no-results-state" class="hidden">
                    <div class="flex flex-col items-center justify-center py-16 px-4">
                        <!-- Icon with background -->
                        <div class="mb-4 p-4 bg-gray-100 rounded-full">
                            <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        </div>
                        <!-- Message -->
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">No orders found</h3>
                        <p class="text-[15px] text-gray-500 mb-6 text-center max-w-sm">
                            We couldn't find any orders matching your criteria. Try adjusting your filters or search terms.
                        </p>
                        <!-- Action button -->
                        <button onclick="clearFilters()" class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white text-[15px] font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 shadow-sm transition-all">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            Reset Filters
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

<!-- Toast Notification Container -->
<div id="toast-container" class="toast-container"></div>

<!-- Floating Bulk Action Bar -->
<div id="bulk-action-bar" class="bulk-action-bar">
    <div class="flex items-center gap-3">
        <span id="bulk-count" class="font-semibold text-white">0 selected</span>
        <div class="h-6 w-px bg-white/30"></div>
        <button onclick="clearAllSelections()" class="inline-flex items-center">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            Clear All
        </button>
        <div class="h-6 w-px bg-white/30"></div>
        <button onclick="bulkAssignRider()" class="inline-flex items-center">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            Assign Rider
        </button>
        <button onclick="bulkChangeStatus()" class="inline-flex items-center">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            Change Status
        </button>
        <button onclick="exportSelected()" class="inline-flex items-center">
            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Export
        </button>
    </div>
</div>

</div>

<!-- View Order Modal -->
<div id="viewOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Invoice Details</h3>
            <div class="modal-header-buttons" style="display: flex; align-items: center; gap: 12px;">
                <!-- Primary Actions -->
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
                <button onclick="editOrderFromView()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    Edit Order
                </button>
                
                <!-- Secondary Actions Group -->
                <div style="display: flex; gap: 6px; padding-left: 8px; border-left: 1px solid #e5e7eb;">
                    <button onclick="downloadInvoicePdf()" 
                            style="background: #dc2626; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                            title="Download PDF">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                        </svg>
                        PDF
                    </button>
                    <button onclick="downloadInvoiceImage()" 
                            style="background: #059669; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                            title="Print Invoice as Image">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M6 9V2h12v7"/>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                            <rect x="6" y="14" width="12" height="8"/>
                        </svg>
                        Print Invoice
                    </button>
                    <button onclick="openEditInTabFromView()" 
                            style="background: #7c3aed; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                            title="Open in new tab">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15,3 21,3 21,9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                        Pop Out
                    </button>
                </div>
                
                <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            </div>
        </div>
        
        <!-- Modal Body -->
        <div id="viewOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
            <div id="viewOrderRiderBar" style="display:none;margin-bottom:12px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <div>
                        <div style="font-size:12px;color:#6b7280;">Assigned Rider</div>
                        <div id="viewOrderRiderName" style="font-size:14px;font-weight:600;color:#111827;">Unassigned</div>
                    </div>
                    <div>
                        <button id="viewOrderAssignRiderBtn" style="padding:8px 10px;background:#0ea5e9;color:#fff;border:0;border-radius:6px;cursor:pointer;">Assign / Change</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Order Modal -->
<div id="editOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Edit Invoice</h3>
            <div style="display: flex; align-items: center; gap: 12px;">
                <!-- Primary Actions -->
                <button onclick="viewInvoiceFromEdit()" style="background-color: #2563eb; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/>
                        <polyline points="14,2 14,8 20,8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10,9 9,9 8,9"/>
                    </svg>
                    View Invoice
                </button>
                
                <!-- Secondary Actions Group -->
                <div style="display: flex; gap: 6px; padding-left: 8px; border-left: 1px solid #e5e7eb;">
                    <button onclick="downloadInvoicePdf()" 
                            style="background: #dc2626; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                            title="Download PDF">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                        </svg>
                        PDF
                    </button>
                    <button onclick="downloadInvoiceImage()" 
                            style="background: #059669; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                            title="Print Invoice as Image">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M6 9V2h12v7"/>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                            <rect x="6" y="14" width="12" height="8"/>
                        </svg>
                        Print Invoice
                    </button>
                <a id="popoutOrderBtn" href="#" onclick="openEditInTab()" 
                            style="background: #7c3aed; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px; text-decoration: none;"
                        title="Open in new tab">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                        <polyline points="15,3 21,3 21,9"></polyline>
                        <line x1="10" y1="14" x2="21" y2="3"></line>
                    </svg>
                    Pop Out
                </a>
                </div>
                
                <button onclick="closeModal('editOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            </div>
        </div>
        <!-- Modal Body -->
        <div id="editOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
                <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Rider Assignment</h4>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
                        <select id="editRiderSelect" style="flex:1;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"><option value="">Loading...</option></select>
                        <button type="button" id="editRiderAssignBtn" style="padding:8px 10px;background:#2563eb;color:#fff;border:0;border-radius:6px;cursor:pointer;">Assign</button>
                    </div>
                    <div id="editOrderRiderTimeline" style="max-height:150px;overflow-y:auto;font-size:13px;"></div>
                </div>
        </div>
    </div>
</div>

<!-- Customer Details Modal -->
<div id="customerDetailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 20px; font-weight: 600; margin: 0;">Customer Details</h3>
                <button onclick="closeModal('customerDetailsModal')" 
                        style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="customerDetailsContent" style="padding: 24px;">
            <!-- Content will be populated by JavaScript -->
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
                    <strong>Visible columns</strong> appear in the table. Drag to reorder them. <strong>Hidden columns</strong> can be enabled by checking the box, and will move to the visible section.
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

@include('pages.orders.partials.import-modal')

@endsection

@push('demo1_js')
<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Pop-out mode enhancements */
.popout-mode-active {
    overflow: hidden !important;
}
.popout-modal-fullscreen {
    animation: popoutFadeIn 0.3s ease-out;
}

@keyframes popoutFadeIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* Enhanced line item styling for better product name handling */
.line-item input[name*="[name]"] {
    font-weight: 500;
    color: #374151;
    /* Allow full product name to be visible */
    white-space: normal;
    overflow: visible;
}

.line-item input[name*="[name]"]:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    z-index: 10;
    position: relative;
}

/* Compact and centered quantity and price fields */
.line-item input[name*="[quantity]"] {
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    padding: 6px 2px;
    color: #1f2937;
}

.line-item input[name*="[unit_price]"] {
    text-align: right;
    font-size: 13px;
    font-weight: 500;
    padding: 6px 4px;
    color: #059669;
}

/* Total field styling */
.line-item .line-total {
    font-weight: 600;
    color: #1f2937;
    background-color: #f3f4f6;
    border: 1px solid #d1d5db;
    text-align: right;
    padding: 6px 8px;
}

/* Delete button styling */
.line-item button[onclick*="removeLineItem"] {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    font-size: 16px;
    line-height: 1;
}

/* Responsive adjustments for smaller screens */
@media (max-width: 768px) {
    .line-item {
        grid-template-columns: 2fr 60px 80px 90px 28px !important;
        gap: 8px !important;
    }
    
    .line-item input[name*="[quantity]"],
    .line-item input[name*="[unit_price]"] {
        font-size: 12px;
        padding: 5px 2px;
    }
    
    .line-item .line-total {
        font-size: 12px;
        padding: 5px 4px;
    }
}
</style>
<script>
// Modal functions
function closeModal(modalId) {
    // If in pop-out mode, close the entire tab instead of just hiding the modal
    if (window.isPopoutMode && modalId === 'editOrderModal') {
        // Refresh the parent window if it exists
        if (window.opener && !window.opener.closed) {
            try {
                window.opener.location.reload();
            } catch (e) {
                // Ignore cross-origin errors
            }
        }
        // Close the pop-out tab
        window.close();
        return;
    }
    
    // Normal modal close behavior
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        
        // Reset pop-out styling if it was applied
        if (modalId === 'editOrderModal' && window.isPopoutMode) {
            resetModalStyling(modal);
            window.isPopoutMode = false;
        }
    }
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

// Normalize payment method for display
function normalizePaymentMethodDisplay(paymentMethod) {
    if (!paymentMethod) return 'Cash';
    
    const method = paymentMethod.toLowerCase().trim();
    
    // Mapping for display
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
    
    // Check for partial matches if exact match not found
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
    
    return displayMap[method] || 'Cash'; // Default to Cash if unknown
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
            
            // Attach discounts to order if they're at root level (for backward compat)
            if (!order.discounts && data.discounts) {
                order.discounts = data.discounts;
            }
            
            // Attach verified_location to order if it's at root level
            if (!order.verified_location && data.verified_location) {
                order.verified_location = data.verified_location;
            }
            
            console.log('Order data:', order);
            console.log('Order discounts:', order.discounts);
            console.log('Verified location:', order.verified_location);
            
            // Update modal header buttons based on order type
            updateViewModalButtons(order);
            
            // Build HTML using string concatenation to avoid Blade conflicts
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
                customerDisplay = '<span onclick="openCustomerInNewTab(' + order.customer_id + ')" class="customer-name-link text-blue-600 hover:text-blue-800 hover:underline font-medium cursor-pointer" title="View customer details">' + customerName + '</span>';
            }
            html += '<p><strong>Customer:</strong> ' + customerDisplay + '</p>';
            html += '<p><strong>Address:</strong> ' + fullAddress + '</p>';
            html += '<p><strong>Phone:</strong> ' + (order.address_phone || order.customer_phone || 'N/A') + '</p>';
            
            // Add verified location if available
            if (order.verified_location) {
                html += '<div style="margin-top: 12px; padding: 10px; background-color: #f0fdf4; border-radius: 6px; border: 1px solid #10b981;">';
                html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">';
                html += '<strong style="color: #059669; font-size: 13px;">✅ Verified Location</strong>';
                if (order.customer_id) {
                    html += '<button onclick="updateVerifiedLocation(' + order.customer_id + ')" style="padding: 4px 8px; background-color: #3b82f6; color: white; border: none; border-radius: 4px; font-size: 11px; cursor: pointer; font-weight: 500;">Update</button>';
                }
                html += '</div>';
                
                if (order.verified_location.url) {
                    html += '<p style="margin: 4px 0; font-size: 12px;">';
                    html += '<a href="' + order.verified_location.url + '" target="_blank" style="color: #3b82f6; text-decoration: none; font-weight: 500;">';
                    html += '<i class="fas fa-external-link-alt"></i> Open in Google Maps';
                    html += '</a>';
                    html += '</p>';
                } else if (order.verified_location.latitude && order.verified_location.longitude) {
                    html += '<p style="margin: 4px 0; font-size: 11px; font-family: monospace; color: #059669;">';
                    html += order.verified_location.latitude + ', ' + order.verified_location.longitude;
                    html += '</p>';
                    html += '<p style="margin: 4px 0; font-size: 12px;">';
                    html += '<a href="' + order.verified_location.google_maps_url + '" target="_blank" style="color: #3b82f6; text-decoration: none; font-weight: 500;">';
                    html += '<i class="fas fa-external-link-alt"></i> Open in Google Maps';
                    html += '</a>';
                    html += '</p>';
                }
                
                if (order.verified_location.saved_by) {
                    html += '<p style="margin: 6px 0 0 0; padding-top: 6px; border-top: 1px solid #bbf7d0; font-size: 10px; color: #059669;">';
                    html += '<i class="fas fa-user"></i> ' + order.verified_location.saved_by;
                    if (order.verified_location.saved_at) {
                        html += ' • ' + new Date(order.verified_location.saved_at).toLocaleString();
                    }
                    html += '</p>';
                }
                
                html += '</div>';
            } else if (order.customer_id) {
                html += '<div style="margin-top: 12px; padding: 10px; background-color: #eff6ff; border-radius: 6px; border: 1px solid #3b82f6; text-align: center;">';
                html += '<button onclick="setVerifiedLocation(' + order.customer_id + ')" style="padding: 6px 12px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer;">';
                html += '<i class="fas fa-map-marker-alt"></i> Set Verified Location';
                html += '</button>';
                html += '</div>';
            }
            
            html += '</div>';
            html += '<div>';
            html += '<p><strong>Status:</strong> ' + (order.order_status || 'N/A') + '</p>';
            html += '<p><strong>Payment Method:</strong> ' + normalizePaymentMethodDisplay(order.payment_method) + '</p>';
            html += '<p><strong>Total:</strong> ' + formatCurrency(order.total_price, order.currency) + '</p>';
            html += '<p><strong>Items:</strong> ' + (order.line_items ? order.line_items.length : 0) + '</p>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            // Line Items with preparation status (only for open orders, not Shopify)
            var items = (order.line_items && Array.isArray(order.line_items)) ? order.line_items : [];
            var isOpenOrder = !['delivered', 'completed', 'cancelled', 'refunded'].includes(order.order_status);
            var isShopifyOrder = order.external_source === 'shopify';
            var showPreparationControls = isOpenOrder && !isShopifyOrder;
            
            html += '<div style="padding: 20px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; margin: 0 0 20px 0;">';
            html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">';
            html += '<h3 style="margin: 0; color: #111827;">Line Items</h3>';
            
            // Only show preparation controls for open non-Shopify orders
            if (showPreparationControls) {
                html += '<div style="display: flex; gap: 8px; align-items: center;">';
                html += '<label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #6b7280; cursor: pointer;">';
                html += '<input type="checkbox" id="selectAllLineItems" style="cursor: pointer;" onchange="toggleSelectAllLineItems()">';
                html += '<span>Select All</span>';
                html += '</label>';
                html += '<button onclick="markSelectedAsPreparing()" style="padding: 6px 12px; background: #10b981; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background=\'#059669\'" onmouseout="this.style.background=\'#10b981\'">Mark as Prepared</button>';
                html += '<button onclick="clearSelectedPreparingStatus()" style="padding: 6px 12px; background: #6b7280; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background=\'#4b5563\'" onmouseout="this.style.background=\'#6b7280\'">Clear Status</button>';
                html += '</div>';
            }
            
            html += '</div>';
            if (items.length > 0) {
                html += '<div style="overflow-x: auto;">';
                html += '<table id="lineItemsTable" style="width: 100%; border-collapse: collapse;">';
                html += '<thead><tr>';
                
                // Only show Select and Status columns for open non-Shopify orders
                if (showPreparationControls) {
                    html += '<th style="text-align:center; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px; width: 40px;">Select</th>';
                }
                
                html += '<th style="text-align:left; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Item</th>';
                
                if (showPreparationControls) {
                    html += '<th style="text-align:center; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px; width: 100px;">Status</th>';
                }
                
                html += '<th style="text-align:right; padding: 8px; border-bottom: 1px solid #e5e7eb; color:#6b7280; font-size:12px;">Qty</th>' +
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
                    
                    html += '<tr>';
                    
                    // Only show checkbox for open non-Shopify orders
                    if (showPreparationControls) {
                        html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align: center;"><input type="checkbox" class="lineItemCheckbox" data-item-id="' + (it.id || '') + '" style="cursor: pointer;"></td>';
                    }
                    
                    html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6;">' + name + '</td>';
                    
                    // Only show status badge for open non-Shopify orders
                    if (showPreparationControls) {
                        var statusBadge = '';
                        if (it.preparation_status === 'preparing') {
                            statusBadge = '<span style="display: inline-block; padding: 4px 8px; background: #d1fae5; color: #065f46; border-radius: 4px; font-size: 11px; font-weight: 600;">Prepared</span>';
                        } else {
                            statusBadge = '<span style="display: inline-block; padding: 4px 8px; background: #f3f4f6; color: #6b7280; border-radius: 4px; font-size: 11px; font-weight: 600;">Not Started</span>';
                        }
                        html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align: center;">' + statusBadge + '</td>';
                    }
                    
                    html += '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right;">' + qty + '</td>' +
                            '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right;">' + formatCurrency(unit, order.currency) + '</td>' +
                            '<td style="padding: 8px; border-bottom: 1px solid #f3f4f6; text-align:right; font-weight:600;">' + formatCurrency(lineTotal, order.currency) + '</td>' +
                        '</tr>';
                }
                html += '</tbody>';
                html += '<tfoot>';
                // Use calculated subtotal from line items only (exclude shipping/fees)
                html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">Subtotal</td><td style="padding: 8px; text-align:right; font-weight:600;">' + formatCurrency(itemsSubtotal, order.currency) + '</td></tr>';
                // Show discount breakdown if available
                if (order.discounts && order.discounts.length > 0) {
                    // Multiple discounts - show each one
                    order.discounts.forEach(function(discount) {
                        html += '<tr><td></td><td></td><td style="padding: 8px; text-align:right; color:#6b7280;">' + discount.discount_title + '</td><td style="padding: 8px; text-align:right;">-' + formatCurrency(discount.discount_amount, order.currency) + '</td></tr>';
                    });
                } else if (order.discount_total) {
                    // Single discount - show as before (backward compatible)
                    const discountLabel = order.coupon_code ? 'Discount (' + order.coupon_code + ')' : 'Discount';
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
                html += '<p style="margin: 0; color: #374151; line-height: 1.5; white-space: pre-wrap;">' + (order.note || '') + '</p>';
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

            // Delivery Location Section (if available)
            if (data.delivery_location) {
                var loc = data.delivery_location;
                html += '<div style="padding: 20px; background-color: #dbeafe; border: 1px solid #3b82f6; border-radius: 8px; margin: 20px 0 0 0;">';
                html += '<h3 style="margin: 0 0 12px 0; color: #1e40af; font-size: 16px; display: flex; align-items: center; gap: 8px;"><span>📍</span> Delivery Location</h3>';
                html += '<div style="background-color: white; padding: 16px; border-radius: 6px;">';
                html += '<div style="display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: start;">';
                html += '<div style="font-size: 14px; color: #6b7280;">Coordinates:</div>';
                html += '<div style="font-size: 14px; color: #111827; font-family: monospace;">' + loc.latitude + ', ' + loc.longitude + '</div>';
                html += '<div style="font-size: 14px; color: #6b7280;">Delivered At:</div>';
                html += '<div style="font-size: 14px; color: #111827;">' + formatDate(loc.delivered_at) + '</div>';
                html += '</div>';
                html += '<a href="' + loc.google_maps_url + '" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; padding: 8px 16px; background-color: #3b82f6; color: white; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;">';
                html += '<span>🗺️</span> View on Google Maps';
                html += '</a>';
                html += '</div>';
                html += '</div>';
            }

            // Rider Assignment Section
            html += '<div style="padding: 20px; background-color: #f0f9ff; border: 1px solid #0891b2; border-radius: 8px; margin: 20px 0 0 0;">';
            html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">';
            html += '<div style="flex:1;">';
            html += '<h3 style="margin: 0 0 4px 0; color: #111827; font-size: 16px;">Assigned Rider</h3>';
            html += '<div id="viewOrderRiderName" style="font-size:14px;font-weight:600;color:#0891b2;">Loading...</div>';
            html += '</div>';
            html += '<button id="viewOrderAssignRiderBtn" style="padding:10px 16px;background:#0ea5e9;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:500;font-size:14px;">Assign / Change</button>';
            html += '</div>';
            html += '</div>';
            
            // Status Timeline Section
            html += '<div style="padding: 20px; background-color: #f9fafb; border-radius: 8px; margin: 20px 0 0 0;">';
            html += '<h3 style="margin: 0 0 16px 0; color: #111827; font-size: 16px;">Status History</h3>';
            html += '<div id="viewOrderTimeline" style="max-height: 250px; overflow-y: auto;">';
            html += '<div style="text-align:center;color:#6b7280;font-size:13px;padding:20px;">Loading timeline...</div>';
            html += '</div>';
            html += '</div>';

            html += '</div>';
            html += '</div>';
            html += '</div>';
            
            content.innerHTML = html;
            
            // Load status timeline after content is rendered
            try {
                loadViewOrderTimeline(orderId);
            } catch (e) {
                console.warn('Failed to load view timeline', e);
            }
            
            // Wire up rider bar after content is loaded
            try {
                const riderNameEl = document.getElementById('viewOrderRiderName');
                const btn = document.getElementById('viewOrderAssignRiderBtn');
                if (riderNameEl && btn) {
                    const rname = order.rider_name || (order.assigned_rider && (order.assigned_rider.fullname || order.assigned_rider.name)) || (order.assigned_rider_user_id ? ('User #' + order.assigned_rider_user_id) : 'Unassigned');
                    riderNameEl.textContent = rname;
                    btn.onclick = function(){ openQuickRiderAssign(order.id, order.assigned_rider_user_id || null, rname); };
                }
            } catch(e) { console.warn('view rider bar update failed', e); }
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

// Line Item Status Management Functions
function toggleSelectAllLineItems() {
    const selectAll = document.getElementById('selectAllLineItems');
    const checkboxes = document.querySelectorAll('.lineItemCheckbox');
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
    });
}

function getSelectedLineItemIds() {
    const checkboxes = document.querySelectorAll('.lineItemCheckbox:checked');
    const ids = [];
    checkboxes.forEach(cb => {
        const itemId = cb.getAttribute('data-item-id');
        if (itemId) {
            ids.push(parseInt(itemId));
        }
    });
    return ids;
}

function markSelectedAsPreparing() {
    const selectedIds = getSelectedLineItemIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one line item');
        return;
    }
    
    if (!currentOrderId) {
        alert('Order ID not found');
        return;
    }
    
    // Show loading state
    const button = event.target;
    const originalText = button.innerHTML;
    button.innerHTML = '⏳ Updating...';
    button.disabled = true;
    
    // Call API to update status
    fetch(`/orders/${currentOrderId}/line-items/bulk-update-status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            line_item_ids: selectedIds,
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
            alert(`Updated ${data.updated_count} item(s) to Prepared status`);
            // Reload order details to show updated status
            viewOrderDetails(currentOrderId);
        } else {
            alert('Failed to update: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error updating line items:', error);
        alert('Failed to update line items: ' + error.message);
    })
    .finally(() => {
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

function clearSelectedPreparingStatus() {
    const selectedIds = getSelectedLineItemIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one line item');
        return;
    }
    
    if (!currentOrderId) {
        alert('Order ID not found');
        return;
    }
    
    // Show loading state
    const button = event.target;
    const originalText = button.innerHTML;
    button.innerHTML = '⏳ Updating...';
    button.disabled = true;
    
    // Call API to clear status
    fetch(`/orders/${currentOrderId}/line-items/bulk-update-status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            line_item_ids: selectedIds,
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
            alert(`Cleared status for ${data.updated_count} item(s)`);
            // Reload order details to show updated status
            viewOrderDetails(currentOrderId);
        } else {
            alert('Failed to update: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error updating line items:', error);
        alert('Failed to update line items: ' + error.message);
    })
    .finally(() => {
        button.innerHTML = originalText;
        button.disabled = false;
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
        // Show loading state on button
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = 'â³ Generating PDF...';
        button.disabled = true;
        
        // Use the same approach as PNG but for PDF - open invoice page with auto-PDF generation
        window.open('/orders/' + currentOrderId + '/invoice?print_pdf=1', '_blank');
        
        // Reset button after download attempt
        setTimeout(() => {
            button.innerHTML = originalText;
            button.disabled = false;
        }, 3000);
        
    } else {
        console.error('No order ID available for PDF download');
    }
}

function downloadInvoiceImage() {
    if (currentOrderId) {
        // Show loading state on button
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = 'â³ Printing Invoice...';
        button.disabled = true;
        
        // Open web invoice and auto-generate a PNG from the DOM for exact visual match
        // Use 'view_and_download_png=1' instead of 'auto_png=1' to keep page open
        window.open('/orders/' + currentOrderId + '/invoice?view_and_download_png=1', '_blank');
        
        // Reset button after download attempt
        setTimeout(() => {
            button.innerHTML = originalText;
            button.disabled = false;
        }, 3000);
    } else {
        console.error('No order ID available for image download');
    }
}

function printInvoicePdf() {
    if (currentOrderId) {
        // Open invoice page in new tab - it has download options
        window.open('/orders/' + currentOrderId + '/invoice', '_blank');
    } else {
        console.error('No order ID available for PDF printing');
    }
}
// Edit order from view modal
function editOrderFromView() {
    if (currentOrderId) {
        // Close view modal first
        closeModal('viewOrderModal');
        // Open edit modal using existing function
        editOrderDetails(currentOrderId);
    } else {
        console.error('No order ID available for editing');
    }
}

// Open edit in new tab from view modal
function openEditInTabFromView() {
    if (currentOrderId) {
        // Use existing function to open edit in new tab
        openEditInTab();
    } else {
        console.error('No order ID available for pop-out');
    }
}

// View invoice from edit modal
function viewInvoiceFromEdit() {
    if (currentOrderId) {
        // Use existing function to view invoice
        viewInvoice();
    } else {
        console.error('No order ID available for viewing invoice');
    }
}

// Update view modal buttons based on order type
function updateViewModalButtons(order) {
    const modal = document.getElementById('viewOrderModal');
    if (!modal) return;
    
    const isShopifyOrder = order.external_source === 'shopify';
    const isConverted = order.converted && order.converted !== 0 && order.converted !== 3;
    const isIgnored = order.converted === 2;
    
    // Find the button container
    const buttonContainer = modal.querySelector('.modal-header-buttons') || 
                           modal.querySelector('[style*="display: flex; align-items: center; gap: 12px;"]');
    
    if (!buttonContainer) return;
    
    if (isShopifyOrder) {
        if (isConverted || isIgnored) {
            // Already processed Shopify order - show only close button
            buttonContainer.innerHTML = `
                <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            `;
        } else {
            // Pending Shopify order - show approve/ignore actions
            buttonContainer.innerHTML = `
                <!-- Primary Actions for Shopify Approval -->
                <button onclick="convertOrder(${order.id})" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Approve
                </button>
                <button onclick="ignoreOrder(${order.id})" style="background-color: #ef4444; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Ignore
                </button>
                
                <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            `;
        }
    } else {
        // Non-Shopify order - show full functionality (restore original buttons)
        buttonContainer.innerHTML = `
            <!-- Primary Actions -->
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
            <button onclick="editOrderFromView()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Edit Order
            </button>
            
            <!-- Secondary Actions Group -->
            <div style="display: flex; gap: 6px; padding-left: 8px; border-left: 1px solid #e5e7eb;">
                <button onclick="downloadInvoicePdf()" 
                        style="background: #dc2626; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                        title="Download PDF">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14,2 14,8 20,8"/>
                    </svg>
                    PDF
                </button>
                <button onclick="downloadInvoiceImage()" 
                        style="background: #059669; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                        title="Print Invoice as Image">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M6 9V2h12v7"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Print Invoice
                </button>
                <button onclick="openEditInTabFromView()" 
                        style="background: #7c3aed; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; display: flex; align-items: center; gap: 4px;"
                        title="Open in new tab">
                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                        <polyline points="15,3 21,3 21,9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    Pop Out
                </button>
            </div>
            
            <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        `;
    }
}

// Open customer details in new tab
function openCustomerInNewTab(customerId) {
    // Open customers page in new tab and trigger the customer modal
    const customerUrl = '/customers?view_customer=' + customerId;
    window.open(customerUrl, '_blank');
}
// Open customer details modal (inline)
function openCustomerDetails(customerId) {
    console.log('Opening customer details for ID:', customerId);
    const modal = document.getElementById('customerDetailsModal');
    const content = document.getElementById('customerDetailsContent');
    
    if (!modal || !content) {
        // Fallback: open in new tab if modal doesn't exist
        openCustomerInNewTab(customerId);
        return;
    }
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    // Fetch customer details
    fetch(`/customers/${customerId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.customer) {
            const customer = data.customer;
            const fullName = [customer.first_name || '', customer.last_name || ''].join(' ').trim() || customer.name || 'N/A';
            
            content.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
                    <div>
                        <h4 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 15px;">Personal Information</h4>
                        <div style="space-y: 10px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">FULL NAME</label>
                                <div style="font-size: 16px; font-weight: 600; color: #111827;">${fullName}</div>
                            </div>
                            <div style="margin-top: 15px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">EMAIL</label>
                                <div style="font-size: 14px; color: #374151;">${customer.email || 'N/A'}</div>
                            </div>
                            <div style="margin-top: 15px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">PHONE (ORIGINAL)</label>
                                <div style="font-size: 14px; color: #374151;">${customer.phone_original || customer.phone || 'N/A'}</div>
                            </div>
                            <div style="margin-top: 15px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">PHONE (NORMALIZED)</label>
                                <div style="font-size: 14px; color: #374151;">${customer.phone || 'N/A'}</div>
                            </div>
                            <div style="margin-top: 15px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">COMPANY</label>
                                <div style="font-size: 14px; color: #374151;">${customer.company || 'N/A'}</div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h4 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 15px;">Address Information</h4>
                        <div style="space-y: 10px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">ADDRESS</label>
                                <div style="font-size: 14px; color: #374151;">
                                    ${customer.address1 || 'N/A'}${customer.address2 ? ', ' + customer.address2 : ''}
                                </div>
                            </div>
                            <div style="margin-top: 15px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">CITY</label>
                                <div style="font-size: 14px; color: #374151;">${customer.city || 'N/A'}</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="border-top: 1px solid #e5e7eb; padding-top: 20px;">
                    <h4 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 15px;">Order Statistics</h4>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                        <div style="text-align: center;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">TOTAL ORDERS</label>
                            <div style="font-size: 24px; font-weight: 700; color: #2563eb;">${customer.total_orders || 0}</div>
                        </div>
                        <div style="text-align: center;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">TOTAL SPENT</label>
                            <div style="font-size: 24px; font-weight: 700; color: #059669;">PKR ${parseFloat(customer.total_spent || 0).toFixed(2)}</div>
                        </div>
                        <div style="text-align: center;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">LAST ORDER</label>
                            <div style="font-size: 16px; font-weight: 600; color: #374151;">${customer.last_order_date || 'N/A'}</div>
                        </div>
                    </div>
                </div>
                
                ${customer.notes ? `
                <div style="border-top: 1px solid #e5e7eb; padding-top: 20px; margin-top: 20px;">
                    <h4 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 10px;">Notes</h4>
                    <div style="background-color: #f9fafb; padding: 15px; border-radius: 8px; font-size: 14px; color: #374151;">
                        ${customer.notes}
                    </div>
                </div>
                ` : ''}
            `;
        } else {
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
        }
    })
    .catch(error => {
        console.error('Error fetching customer details:', error);
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
    });
}

// Edit Order Details
function convertOrder(orderId) {
    if (!confirm('Are you sure you want to convert this Shopify order to a webapp invoice? This will match SKUs with your products and recalculate prices based on your rates.')) {
        return;
    }
    
    // Show loading state
    const approveButtons = document.querySelectorAll(`button[onclick="convertOrder(${orderId})"]`);
    approveButtons.forEach(btn => {
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" class="opacity-75"></path></svg> Converting...';
    });
    
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
            let message = `Order converted successfully! New invoice order #${data.converted_order.order_number} created.`;
            
            // Show price changes if any
            if (data.price_changes && data.price_changes.length > 0) {
                message += '\n\nPrice Changes:';
                data.price_changes.forEach(change => {
                    message += `\nâ€¢ ${change.name} (${change.sku}): PKR ${change.original_price} â†’ PKR ${change.new_price} (Qty: ${change.quantity})`;
                });
            }
            
            // Show warnings if any
            if (data.warnings && data.warnings.length > 0) {
                message += '\n\nWarnings:';
                data.warnings.forEach(warning => {
                    message += `\nâ€¢ ${warning}`;
                });
            }
            
            alert(message);
            // Refresh the page to show updated data
            window.location.reload();
        } else {
            // Show detailed error information
            let errorMessage = 'Error converting order:\n' + (data.message || 'Unknown error');
            
            if (data.errors && data.errors.length > 0) {
                errorMessage += '\n\nDetails:';
                data.errors.forEach(error => {
                    errorMessage += `\nâ€¢ ${error}`;
                });
                errorMessage += '\n\nPlease fix these issues in your products and try again.';
            }
            
            alert(errorMessage);
            
            // Restore button state
            approveButtons.forEach(btn => {
                btn.disabled = false;
                btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            });
        }
    })
    .catch(error => {
        console.error('Error converting order:', error);
        alert('Error converting order. Please try again.');
        
        // Restore button state
        approveButtons.forEach(btn => {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        });
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
// Reset modal styling (for completeness, though not typically needed in pop-out mode)
function resetModalStyling(modal) {
    if (!modal) return;
    
    // Remove body class
    document.body.classList.remove('popout-mode-active');
    
    const modalContainer = modal.querySelector('div');
    if (modalContainer) {
        // Reset to original modal styling
        modalContainer.style.cssText = `
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        `;
        
        // Remove animation class
        modalContainer.classList.remove('popout-modal-fullscreen');
    }
    
    // Show the pop-out button again
    const popoutBtn = modal.querySelector('#popoutOrderBtn');
    if (popoutBtn) {
        popoutBtn.style.display = '';
    }
    
    // Remove pop-out indicator
    const indicator = modal.querySelector('.popout-indicator');
    if (indicator) {
        indicator.remove();
    }
    
    // Reset page title
    if (document.title && document.title.includes('[Pop-out]')) {
        document.title = document.title.replace('[Pop-out] ', '');
    }
}
// Apply full-screen styling for pop-out mode
function applyPopoutStyling(modal) {
    if (!modal) return;
    
    // Get the modal container (the inner div)
    const modalContainer = modal.querySelector('div');
    if (!modalContainer) return;
    
    // Hide body overflow to prevent scrolling behind the modal
    document.body.classList.add('popout-mode-active');
    
    // Apply full-screen styling to cover entire application
    modalContainer.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        max-width: none;
        max-height: none;
        transform: none;
        background: white;
        border-radius: 0;
        box-shadow: none;
        overflow-y: auto;
        z-index: 10000;
    `;
    
    // Add animation class
    modalContainer.classList.add('popout-modal-fullscreen');
    
    // Hide the pop-out button since we're already in pop-out mode
    const popoutBtn = modal.querySelector('#popoutOrderBtn');
    if (popoutBtn) {
        popoutBtn.style.display = 'none';
    }
    
    // Adjust the modal header for full-screen appearance
    const modalHeader = modal.querySelector('div[style*="padding: 20px"]');
    if (modalHeader) {
        modalHeader.style.cssText += `
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 2px solid #e5e7eb;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 10001;
        `;
    }
    
    // Add a subtle indicator that this is pop-out mode
    const modalTitle = modal.querySelector('h3');
    if (modalTitle && !modalTitle.querySelector('.popout-indicator')) {
        const indicator = document.createElement('span');
        indicator.className = 'popout-indicator';
        indicator.style.cssText = `
            display: inline-flex;
            align-items: center;
            margin-left: 8px;
            padding: 3px 8px;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af;
            font-size: 10px;
            font-weight: 600;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #93c5fd;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        `;
        indicator.innerHTML = '🔗 Pop-out Mode';
        modalTitle.appendChild(indicator);
    }
    
    // Page title will be set by individual functions to show just the order number
}
function editOrderDetails(orderId) {
    console.log('Edit order details clicked for order:', orderId);
    // Ensure the pop-out in-tab handler has the order id
    try { currentOrderId = orderId; } catch (e) {}
    const modal = document.getElementById('editOrderModal');
    const content = document.getElementById('editOrderContent');
    
    // Apply full-screen styling if in pop-out mode
    if (window.isPopoutMode) {
        applyPopoutStyling(modal);
    }
    
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
            
            // Attach verified_location to order if it's at root level
            if (!order.verified_location && data.verified_location) {
                order.verified_location = data.verified_location;
            }
            
            // Store order globally for ledger adjustment detection
            window.currentOrder = order;
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
    // Update tab title if in pop-out mode
    if (window.isPopoutMode) {
        const orderNumber = order.order_number || `NF-${String(order.id).padStart(4, '0')}`;
        document.title = `${orderNumber}`;
    }
    
    const content = document.getElementById('editOrderContent');
    content.innerHTML = `
        <form id="editOrderForm" style="padding: 0;">
            <input type="hidden" name="order_id" value="${order.id}">
            <input type="hidden" name="customer_id" id="editCustomerId" value="${order.customer_id || ''}">
            
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
                            <select name="order_status" id="editOrderStatus" required style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                <option value="">Loading statuses...</option>
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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h4 style="font-weight: 600; color: #374151; margin: 0;">Customer Details</h4>
                        <button type="button" onclick="showCustomerSelector()" 
                                style="padding: 6px 12px; background-color: #2563eb; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer; font-weight: 500;">
                            Change Customer
                        </button>
                    </div>
                    
                    <!-- Customer Selector (Hidden by default) -->
                    <div id="editCustomerSelector" style="display: none; margin-bottom: 16px; padding: 12px; background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px;">
                        <div style="margin-bottom: 8px;">
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #1e40af; margin-bottom: 4px;">Search and Select Customer</label>
                            <div style="position: relative;">
                                <input type="text" id="editCustomerSearch" placeholder="Search customers by name, phone, or email..." 
                                       style="width: 100%; padding: 8px; border: 1px solid #3b82f6; border-radius: 4px; font-size: 14px;"
                                       onkeyup="searchCustomersForEdit(this)" onfocus="showEditCustomerDropdown()">
                                <div id="editCustomerDropdown" class="customer-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                            </div>
                        </div>
                        <button type="button" onclick="hideCustomerSelector()" 
                                style="padding: 4px 10px; background-color: #e5e7eb; color: #374151; border: none; border-radius: 4px; font-size: 11px; cursor: pointer;">
                            Cancel
                        </button>
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">First Name</label>
                                <input type="text" name="address_first_name" id="editAddressFirstName" value="${order.address_first_name || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Last Name</label>
                                <input type="text" name="address_last_name" id="editAddressLastName" value="${order.address_last_name || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Email</label>
                            <input type="email" name="address_email" id="editAddressEmail" value="${order.address_email || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Phone</label>
                            <input type="text" name="address_phone" id="editAddressPhone" value="${order.address_phone || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Address Line 1</label>
                                <input type="text" name="address_line1" id="editAddressLine1" value="${order.address_line1 || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Address Line 2</label>
                                <input type="text" name="address_line2" id="editAddressLine2" value="${order.address_line2 || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">City</label>
                                <input type="text" name="address_city" id="editAddressCity" value="${order.address_city || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Country</label>
                                <input type="text" name="address_country" id="editAddressCountry" value="${order.address_country || 'Pakistan'}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Payment Method</label>
                                <select name="payment_method" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                                    <option value="">Select Payment Method</option>
                                    <option value="cash_on_delivery" ${(order.payment_method || '').toLowerCase().includes('cash') || order.payment_method === 'cash_on_delivery' ? 'selected' : ''}>Cash on Delivery</option>
                                    <option value="bank_transfer" ${(order.payment_method || '').toLowerCase().includes('bank') || order.payment_method === 'bank_transfer' ? 'selected' : ''}>Bank Transfer</option>
                                    <option value="card" ${(order.payment_method || '').toLowerCase().includes('card') || order.payment_method === 'card' ? 'selected' : ''}>Card Payment</option>
                                    <option value="online" ${(order.payment_method || '').toLowerCase().includes('online') || order.payment_method === 'online' ? 'selected' : ''}>Online Payment</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Customer Notes Display (if available) -->
            <div id="editCustomerNotesDisplay" style="margin-bottom: 20px; display: none;"></div>
            
            <!-- Notes Section -->
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Order Notes</label>
                <textarea name="note" rows="3" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="Order notes...">${order.note || ''}</textarea>
            </div>

            <!-- Packet Tracking Section (Optional) -->
            <div style="background-color: #fef3c7; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fbbf24;">
                <h4 style="font-weight: 600; color: #92400e; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                    <span>📦</span> Packet Tracking (Optional)
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">
                            Expected Packets
                            <span style="font-size: 12px; color: #6b7280; font-weight: normal;">(Manager/Admin)</span>
                        </label>
                        <input type="number" name="expected_packets" value="${order.expected_packets || ''}" min="0" step="1"
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" 
                               placeholder="Enter number of packets">
                        <p style="font-size: 12px; color: #6b7280; margin-top: 4px;">Number of packets you're sending with this order</p>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">
                            Actual Packets Delivered
                            <span style="font-size: 12px; color: #6b7280; font-weight: normal;">(Rider)</span>
                        </label>
                        <input type="number" name="actual_packets" value="${order.actual_packets || ''}" min="0" step="1"
                               style="width: 100%; padding: 8px 12px; border: 1px solid #f3f4f6; border-radius: 6px; font-size: 14px; background-color: #f9fafb; cursor: not-allowed;" 
                               placeholder="Entered by rider on delivery" readonly>
                        <p style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            ${order.actual_packets ? 
                                (order.expected_packets && order.actual_packets != order.expected_packets ? 
                                    `⚠️ <span style="color: #dc2626; font-weight: 500;">Mismatch detected!</span>` : 
                                    `✅ <span style="color: #059669; font-weight: 500;">Verified</span>`) : 
                                'Rider will enter this on delivery'}
                        </p>
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
                    ${order.line_items && order.line_items.length > 0 ? 
                        order.line_items.map((item, index) => `
                        <div class="line-item" data-index="${index}" data-product-name="${(item.name || item.title || '').replace(/"/g, '&quot;')}" style="display: grid; grid-template-columns: 3fr 70px 90px 110px 32px; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Item Name <span style="margin-left: 8px; font-size: 11px; color: #6b7280; font-weight: normal;">🔒 Locked (delete to change)</span></label>
                                <input type="text" name="items[${index}][name]" value="${item.name || item.title || ''}" 
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background-color: #f3f4f6; cursor: not-allowed; color: #6b7280;" readonly>
                                <input type="hidden" name="items[${index}][id]" value="${item.id || ''}">
                            </div>
                            <div style="position: relative;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">
                                    Quantity
                                    <span id="weightFactorFeedback_${index}" style="display: none; margin-left: 6px; padding: 2px 6px; background: #dbeafe; border-radius: 3px; font-size: 9px; color: #0369a1; font-weight: 500; white-space: nowrap;"></span>
                                </label>
                                <input type="number" step="0.01" name="items[${index}][quantity]" value="${item.quantity || 1}" min="0.01"
                                       data-db-original-value="${item.quantity || 1}"
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" 
                                       onfocus="showWeightFactorFeedbackOnFocus(${index})"
                                       onblur="applyWeightFactorToQuantity(${index})" 
                                       onchange="updateLineTotal(${index})">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price <span style="margin-left: 8px; font-size: 11px; color: #6b7280; font-weight: normal;">🔒 Locked</span></label>
                                <input type="number" step="0.01" name="items[${index}][unit_price]" value="${item.unit_price || item.price || 0}" 
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px; background-color: #f3f4f6; cursor: not-allowed;" 
                                       readonly title="Price is set from product catalog and cannot be edited" onchange="updateLineTotal(${index})">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
                                <span class="line-total" style="display: block; padding: 6px 8px; background-color: #e5e7eb; border-radius: 4px; font-size: 14px; font-weight: 500;">${formatCurrency(item.line_total || ((item.unit_price || item.price || 0) * (item.quantity || 0)), order.currency)}</span>
                            </div>
                            <div>
                                <button type="button" onclick="removeLineItem(${index})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; line-height: 1;">
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
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Discounts</label>
                        <div id="discountsContainer" style="display: flex; flex-direction: column; gap: 8px;">
                            <!-- Discount rows will be populated here -->
                        </div>
                        <button type="button" onclick="addDiscountRow()" 
                                style="margin-top: 8px; padding: 6px 12px; background: #10b981; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                            + Add Discount
                        </button>
                        <div style="margin-top: 8px; padding: 8px; background: #f3f4f6; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600; color: #374151;">Total Discount:</span>
                            <span id="totalDiscountDisplay" style="font-weight: 700; color: #ef4444; font-size: 16px;">Rs. 0.00</span>
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

            <!-- Status Timeline Section -->
            <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Status History</h4>
                <div id="editOrderTimeline" style="max-height: 200px; overflow-y: auto;">
                    <div style="text-align:center;color:#6b7280;font-size:13px;padding:20px;">Loading timeline...</div>
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
    
    // Initialize discounts from order data
    try {
        initializeDiscountsFromOrder(order);
    } catch (e) {
        console.warn('Failed to initialize discounts', e);
    }
    
    // Immediately load status options for the dropdown, independent of totals/line items
    try {
        loadEditOrderStatuses(order.order_status);
    } catch (e) {
        console.warn('Failed to trigger status load', e);
    }
    
    // Load status timeline
    try {
        loadEditOrderTimeline(order.id);
    } catch (e) {
        console.warn('Failed to load timeline', e);
    }
    
    try {
        const rSel = document.getElementById('editRiderSelect');
        const rBtn = document.getElementById('editRiderAssignBtn');
        const rTimeline = document.getElementById('editOrderRiderTimeline');
        if (rSel && rBtn) {
            rSel.innerHTML = '<option value="">Loading...</option>';
            fetch('/riders/active', { headers: { 'Accept': 'application/json' }})
                .then(r=>r.json())
                .then(j=>{
                    if (j && j.success) {
                        const opts = (j.data||[]).map(r=>`<option value="${r.id}" ${r.id == (order.assigned_rider_user_id||'') ? 'selected' : ''}>${r.fullname}</option>`).join('');
                        rSel.innerHTML = '<option value="">-- Unassign rider --</option>' + opts;
                    } else {
                        rSel.innerHTML = '<option value="">No riders found</option>';
                    }
                }).catch(()=>{ rSel.innerHTML = '<option value="">Load failed</option>'; });
            rBtn.onclick = async function(){
                const val = rSel.value;
                const selectedRiderName = val ? rSel.selectedOptions[0].text : 'Unassigned';
                
                // Helper function to actually assign the rider
                const assignRider = async function(confirmed = false) {
                    try {
                        const payload = { 
                            rider_user_id: val ? parseInt(val,10) : null 
                        };
                        
                        // Add confirmation flag if this is a retry after confirmation
                        if (confirmed) {
                            payload.confirmed = true;
                        }
                        
                        rBtn.textContent = 'Assigning...'; 
                        rBtn.disabled = true;
                        
                        const response = await fetch(`/orders/${order.id}/rider/assign`, { 
                            method:'POST', 
                            headers:{ 
                                'Accept':'application/json',
                                'Content-Type':'application/json',
                                'X-CSRF-TOKEN':document.querySelector('meta[name=\'csrf-token\']').getAttribute('content') 
                            }, 
                            body: JSON.stringify(payload) 
                        });
                        const result = await response.json();
                        
                        // Check if confirmation is required (ledger will be updated)
                        if (!result.success && result.requires_confirmation && result.confirmation_data) {
                            const data = result.confirmation_data;
                            const confirmMsg = 
                                `⚠️ LEDGER WILL BE UPDATED\n\n` +
                                `This order has been posted to the ledger.\n` +
                                `Changing the rider will reverse the old ledger entry and create a new one.\n\n` +
                                `Order: ${data.order_number}\n` +
                                `Amount: Rs. ${parseFloat(data.amount).toFixed(2)}\n\n` +
                                `Old Rider: ${data.old_rider_name}\n` +
                                `New Rider: ${data.new_rider_name}\n\n` +
                                `The ledger will be moved from ${data.old_rider_name}'s account to ${data.new_rider_name}'s account.\n\n` +
                                `Do you want to proceed?`;
                            
                            rBtn.textContent = 'Assign'; 
                            rBtn.disabled = false;
                            
                            if (confirm(confirmMsg)) {
                                // User confirmed - retry with confirmation flag
                                await assignRider(true);
                            }
                            return;
                        }
                        
                        // Check for other errors
                        if (!result.success) {
                            throw new Error(result.message || 'Failed');
                        }
                        
                        // Success!
                        location.reload();
                        
                    } catch(error) {
                        alert('Assign rider failed: ' + error.message);
                        rBtn.textContent = 'Assign'; 
                        rBtn.disabled = false;
                    }
                };
                
                // Start the assignment process
                await assignRider(false);
            };
        }
        // Load rider assignment history
        if (rTimeline) {
            rTimeline.innerHTML = '<div style="color:#6b7280;padding:8px;text-align:center;">Loading history...</div>';
            fetch(`/orders/${order.id}/rider/timeline`, { headers: { 'Accept':'application/json' }})
                .then(r=>r.json())
                .then(j=>{
                    if (j && j.success && j.data && j.data.length) {
                        rTimeline.innerHTML = j.data.map(h=>`<div style="padding:6px 8px;border-left:3px solid ${h.is_current?'#10b981':'#d1d5db'};margin-bottom:4px;background:${h.is_current?'#ecfdf5':'#f9fafb'};"><div style="font-weight:500;color:#111827;">${h.rider_name||'Unassigned'} ${h.is_current?'<span style="color:#10b981;font-size:11px;">(Current)</span>':''}</div><div style="font-size:11px;color:#6b7280;">${h.assigned_at} by ${h.assigned_by_name||'System'}</div></div>`).join('');
                    } else {
                        rTimeline.innerHTML = '<div style="color:#9ca3af;padding:8px;text-align:center;font-size:12px;">No assignment history</div>';
                    }
                }).catch(()=>{ rTimeline.innerHTML = '<div style="color:#ef4444;padding:8px;text-align:center;font-size:12px;">Failed to load</div>'; });
        }
    } catch(e) { console.warn('edit rider UI wiring failed', e); }
    
    // Fetch and display customer notes if order has a customer_id
    if (order.customer_id) {
        fetch('/customers/' + order.customer_id)
            .then(response => response.json())
            .then(data => {
                if (data && data.success && data.customer && data.customer.notes && data.customer.notes.trim() !== '') {
                    const notesDisplay = document.getElementById('editCustomerNotesDisplay');
                    if (notesDisplay) {
                        notesDisplay.innerHTML = '<div style="padding: 14px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #fbbf24; border-radius: 8px;">' +
                            '<div style="display: flex; align-items: center; margin-bottom: 8px;">' +
                                '<span style="font-size: 20px; margin-right: 8px;">⚠️</span>' +
                                '<strong style="color: #92400e; font-size: 15px;">Customer Instructions / Notes:</strong>' +
                            '</div>' +
                            '<div style="color: #78350f; font-size: 13px; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word;">' + (data.customer.notes || '') + '</div>' +
                        '</div>';
                        notesDisplay.style.display = 'block';
                    }
                }
            })
            .catch(error => {
                console.warn('Failed to fetch customer notes:', error);
            });
    }
    
    // ========================================
    // WEIGHT FACTOR FUNCTIONALITY (EDIT MODE ONLY)
    // ========================================
    // Initialize weight factors for line items
    initializeWeightFactors(order);
    
    // Show weight factor feedback on form load for items that have weight factors
    setTimeout(() => {
        if (order.line_items) {
            order.line_items.forEach((item, index) => {
                showWeightFactorFeedbackOnLoad(index);
            });
        }
    }, 500); // Wait for weight factors to be loaded
}

// Global storage for weight factors
window.lineItemWeightFactors = {};
window.originalQuantityInputs = {};

function initializeWeightFactors(order) {
    if (!order.line_items || order.line_items.length === 0) {
        return;
    }
    
    // Extract unique product names from line items
    const productNames = [...new Set(order.line_items.map(item => item.name || item.title).filter(Boolean))];
    
    if (productNames.length === 0) {
        return;
    }
    
    // Fetch weight factors for these products
    fetch('/api/products/weight-factors', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify({ product_names: productNames })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.weight_factors) {
            window.lineItemWeightFactors = data.weight_factors;
            console.log('Weight factors loaded:', window.lineItemWeightFactors);
        }
    })
    .catch(err => {
        console.warn('Could not load weight factors:', err);
    });
}

function showWeightFactorFeedbackOnLoad(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (!item) return;
    
    const quantityInput = item.querySelector(`input[name="items[${index}][quantity]"]`);
    const feedbackSpan = document.getElementById(`weightFactorFeedback_${index}`);
    const productName = item.getAttribute('data-product-name');
    
    if (!quantityInput || !productName || !feedbackSpan) return;
    
    // Get the weight factor for this product
    const weightFactor = window.lineItemWeightFactors[productName] || 1;
    
    // Only show if weight factor exists and is not 1
    if (weightFactor !== 1 && weightFactor > 0) {
        feedbackSpan.style.display = 'inline';
        feedbackSpan.innerHTML = `⚖️ WF: ${weightFactor}`;
    }
}

function showWeightFactorFeedbackOnFocus(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (!item) return;
    
    const quantityInput = item.querySelector(`input[name="items[${index}][quantity]"]`);
    const feedbackSpan = document.getElementById(`weightFactorFeedback_${index}`);
    const productName = item.getAttribute('data-product-name');
    
    if (!quantityInput || !productName || !feedbackSpan) return;
    
    // Get the weight factor for this product
    const weightFactor = window.lineItemWeightFactors[productName] || 1;
    
    // Only show if weight factor exists and is not 1
    if (weightFactor !== 1 && weightFactor > 0) {
        const alreadyAdjusted = quantityInput.getAttribute('data-adjusted') === 'true';
        const userEntered = quantityInput.getAttribute('data-user-entered') || '';
        const currentValue = parseFloat(quantityInput.value) || 0;
        
        // Show appropriate message based on state
        if (alreadyAdjusted && userEntered && currentValue > 0) {
            feedbackSpan.style.display = 'inline';
            feedbackSpan.innerHTML = `⚖️ ${userEntered}→${currentValue} (÷${weightFactor})`;
        } else {
            feedbackSpan.style.display = 'inline';
            feedbackSpan.innerHTML = `⚖️ WF: ${weightFactor}`;
        }
    }
}

function applyWeightFactorToQuantity(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (!item) return;
    
    const quantityInput = item.querySelector(`input[name="items[${index}][quantity]"]`);
    const feedbackSpan = document.getElementById(`weightFactorFeedback_${index}`);
    const productName = item.getAttribute('data-product-name');
    
    if (!quantityInput || !productName) return;
    
    // Get the weight factor for this product
    const weightFactor = window.lineItemWeightFactors[productName] || 1;
    
    // Skip if no weight factor
    if (weightFactor === 1 || weightFactor <= 0) {
        if (feedbackSpan) {
            feedbackSpan.style.display = 'none';
        }
        updateLineTotal(index);
        return;
    }
    
    const currentValue = parseFloat(quantityInput.value) || 0;
    if (currentValue <= 0) {
        updateLineTotal(index);
        return;
    }
    
    // Check if this was already adjusted
    const alreadyAdjusted = quantityInput.getAttribute('data-adjusted') === 'true';
    const storedAdjustedValue = parseFloat(quantityInput.getAttribute('data-adjusted-value') || 0);
    
    // Determine if user entered a new value different from the adjusted one
    const userChangedFromAdjusted = alreadyAdjusted && Math.abs(currentValue - storedAdjustedValue) > 0.001;
    
    if (userChangedFromAdjusted || !alreadyAdjusted) {
        // Store what the USER ENTERED before we change it
        const userEnteredValue = currentValue;
        
        // Apply weight factor
        const adjustedValue = parseFloat((currentValue / weightFactor).toFixed(2));
        
        if (Math.abs(currentValue - adjustedValue) > 0.001) {
            // Update the input field
            quantityInput.value = adjustedValue;
            
            // Mark as adjusted and store both values
            quantityInput.setAttribute('data-adjusted', 'true');
            quantityInput.setAttribute('data-adjusted-value', adjustedValue);
            quantityInput.setAttribute('data-user-entered', userEnteredValue);
            
            // Show compact inline feedback
            if (feedbackSpan) {
                feedbackSpan.style.display = 'inline';
                feedbackSpan.innerHTML = `⚖️ ${userEnteredValue}→${adjustedValue} (÷${weightFactor})`;
            }
        }
    } else {
        // Already adjusted and value hasn't changed - keep showing the feedback
        if (feedbackSpan && alreadyAdjusted) {
            const userEntered = quantityInput.getAttribute('data-user-entered') || '';
            const adjustedValue = parseFloat(quantityInput.value) || 0;
            if (userEntered) {
                feedbackSpan.style.display = 'inline';
                feedbackSpan.innerHTML = `⚖️ ${userEntered}→${adjustedValue} (÷${weightFactor})`;
            }
        }
    }
    
    // Trigger line total update
    updateLineTotal(index);
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
    newItem.style.cssText = 'display: grid; grid-template-columns: 3fr 70px 90px 110px 32px; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;';
    
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
                   style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" 
                   onchange="updateLineTotal(${lineItemIndex}); freezeProductName(${lineItemIndex})">
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
            <button type="button" onclick="removeLineItem(${lineItemIndex})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; line-height: 1;">
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
        // Get quantity from input (already adjusted by applyWeightFactorToQuantity if applicable)
        const quantity = parseFloat(item.querySelector(`input[name="items[${index}][quantity]"]`).value) || 0;
        const price = parseFloat(item.querySelector(`input[name="items[${index}][unit_price]"]`).value) || 0;
        
        // Calculate total with proper precision
        const total = Math.round(quantity * price * 100) / 100;
        
        const totalSpan = item.querySelector('.line-total');
        totalSpan.textContent = formatCurrency(total, 'PKR');
        
        updateSubtotal();
    }
}

function freezeProductName(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (!item) return;
    
    const productInput = item.querySelector(`input[name="items[${index}][name]"]`);
    const productIdInput = item.querySelector(`input[name="items[${index}][id]"]`);
    
    // Only freeze if product is selected (has product_id and name)
    if (productInput && productInput.value && productIdInput && productIdInput.value) {
        // Check if already frozen
        if (productInput.readOnly) return;
        
        // Freeze the product input
        productInput.readOnly = true;
        productInput.style.backgroundColor = '#f3f4f6';
        productInput.style.cursor = 'not-allowed';
        productInput.style.color = '#6b7280';
        
        // Disable the dropdown
        productInput.onkeyup = null;
        productInput.onkeydown = null;
        productInput.onfocus = null;
        
        // Add a visual indicator
        const label = productInput.previousElementSibling;
        if (label && !label.querySelector('.frozen-indicator')) {
            const indicator = document.createElement('span');
            indicator.className = 'frozen-indicator';
            indicator.style.cssText = 'margin-left: 8px; font-size: 11px; color: #6b7280; font-weight: normal;';
            indicator.innerHTML = '🔒 Locked (delete to change)';
            label.appendChild(indicator);
        }
    }
}

// Freeze all existing line items (for edit mode)
function freezeAllExistingLineItems() {
    const items = document.querySelectorAll('.line-item');
    items.forEach(item => {
        const index = item.getAttribute('data-index');
        if (!index) return;
        
        // Check if this is an existing line item (has product_id)
        const productIdInput = item.querySelector(`input[name*="[id]"]`);
        const productInput = item.querySelector(`input[name*="[name]"]`);
        
        // If line item has a product_id and product name, it's existing - freeze it
        if (productIdInput && productIdInput.value && productInput && productInput.value) {
            freezeProductName(index);
        }
    });
}

// Auto-freeze existing line items when modal/page loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(freezeAllExistingLineItems, 500);
    });
} else {
    setTimeout(freezeAllExistingLineItems, 500);
}

// Also observe for dynamically added content (edit modal)
const observeLineItems = function() {
    const container = document.getElementById('lineItemsContainer');
    if (container && !container.hasAttribute('data-observer-attached')) {
        container.setAttribute('data-observer-attached', 'true');
        
        const observer = new MutationObserver(function(mutations) {
            let shouldFreeze = false;
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    mutation.addedNodes.forEach(node => {
                        if (node.classList && node.classList.contains('line-item')) {
                            shouldFreeze = true;
                        }
                    });
                }
            });
            if (shouldFreeze) {
                setTimeout(freezeAllExistingLineItems, 100);
            }
        });
        
        observer.observe(container, { childList: true });
    }
};

// Try to attach observer immediately and also after delays
observeLineItems();
setTimeout(observeLineItems, 1000);
setTimeout(observeLineItems, 3000);
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
        
        // Load dynamic statuses for the edit form (only if order exists)
        if (typeof order !== 'undefined' && order && order.order_status) {
            loadEditOrderStatuses(order.order_status);
        }
    }
}
// Cache statuses so we don't refetch repeatedly
window.__orderStatusesCache = window.__orderStatusesCache || null;

function normalizeLegacyStatus(code) {
    if (!code) return code;
    const map = {
        'pending': 'new',
        'on-hold': 'on_hold',
        'completed': 'delivered',
        'fulfilled': 'delivered',
        'approved': 'processing',
        'confirmed': 'processing'
    };
    return map[code] || code;
}

// Load available statuses for edit order form
async function loadEditOrderStatuses(currentStatus) {
    try {
        const statusSelect = document.getElementById('editOrderStatus');
        if (!statusSelect) return;

        const normalized = normalizeLegacyStatus(currentStatus);

        if (!window.__orderStatusesCache) {
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
            if (data && data.success) {
                window.__orderStatusesCache = data.data;
            }
        }

        const list = window.__orderStatusesCache;
        if (Array.isArray(list) && list.length) {
            statusSelect.innerHTML = list.map(status => 
                `<option value="${status.status_code}" ${status.status_code === normalized ? 'selected' : ''}>${status.icon} ${status.status_name}</option>`
            ).join('');
            // If nothing selected and no normalized current status, default to 'new'
            if (!statusSelect.value && list.find(s => s.status_code === 'new')) {
                statusSelect.value = 'new';
            }
            // If current status not in master (legacy), insert it at top so user sees it
            if (!list.find(s => s.status_code === normalized) && currentStatus) {
                const opt = document.createElement('option');
                opt.value = currentStatus;
                opt.textContent = currentStatus.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
                opt.selected = true;
                statusSelect.insertBefore(opt, statusSelect.firstChild);
            }
        } else {
            throw new Error('Statuses cache empty');
        }
    } catch (error) {
        console.error('Error loading statuses:', error);
        // Fallback to basic statuses
        const statusSelect = document.getElementById('editOrderStatus');
        if (statusSelect) {
            const normalized = normalizeLegacyStatus(currentStatus);
            statusSelect.innerHTML = `
                <option value="new" ${normalized === 'new' ? 'selected' : ''}>â³ New</option>
                <option value="processing" ${normalized === 'processing' ? 'selected' : ''}>âš¡ Processing</option>
                <option value="out_for_delivery" ${normalized === 'out_for_delivery' ? 'selected' : ''}>ðŸšš Out for Delivery</option>
                <option value="delivered" ${normalized === 'delivered' ? 'selected' : ''}>âœ… Delivered</option>
                <option value="on_hold" ${normalized === 'on_hold' ? 'selected' : ''}>â¸ On Hold</option>
                <option value="cancelled" ${normalized === 'cancelled' ? 'selected' : ''}>âœ• Cancelled</option>
                <option value="refunded" ${normalized === 'refunded' ? 'selected' : ''}>â†© Refunded</option>
            `;
        }
    }
}

// Load status timeline for quick modal
async function loadQuickStatusTimeline(orderId) {
    try {
        const timelineContainer = document.getElementById('quickStatusTimeline');
        if (!timelineContainer) return;
        
        const response = await fetch(`/order-status/api/orders/${orderId}/timeline`, {
            headers: { 
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        console.log('Quick Timeline API response for order', orderId, ':', data); // Debug log
        console.log('Response status:', response.status, 'Response OK:', response.ok); // Debug response
        
        if (data.success && data.data && data.data.length > 0) {
            const timelineHtml = data.data.map((item, index) => {
                const date = new Date(item.changed_at);
                const timeDisplay = formatExactTime(date);
                const isFirst = index === 0;
                
                return `
                    <div style="display:flex;align-items:start;gap:8px;margin-bottom:${index === data.data.length - 1 ? '0' : '12px'};">
                        <div style="width:8px;height:8px;border-radius:50%;background:${getStatusColor(item.color_class)};margin-top:4px;flex-shrink:0;${isFirst ? 'box-shadow:0 0 0 2px rgba(34,197,94,0.2);' : ''}"></div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:4px;margin-bottom:2px;">
                                <span style="font-size:12px;font-weight:${isFirst ? '600' : '500'};color:#374151;">${item.icon} ${item.status_name}</span>
                                ${isFirst ? '<span style="font-size:10px;background:#dcfce7;color:#166534;padding:1px 4px;border-radius:3px;">Current</span>' : ''}
                            </div>
                            <div style="font-size:11px;color:#6b7280;margin-bottom:1px;">${timeDisplay}</div>
                            ${item.notes && item.notes !== 'Status changed to ' + item.status_code ? `<div style="font-size:11px;color:#9ca3af;font-style:italic;">${item.notes}</div>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            timelineContainer.innerHTML = timelineHtml;
        } else {
            console.log('No timeline data available:', data); // Debug log
            timelineContainer.innerHTML = '<div style="text-align:center;color:#6b7280;font-size:12px;padding:16px;">No status history available</div>';
        }
    } catch (error) {
        console.error('Failed to load timeline:', error);
        const timelineContainer = document.getElementById('quickStatusTimeline');
        if (timelineContainer) {
            timelineContainer.innerHTML = '<div style="text-align:center;color:#ef4444;font-size:12px;padding:16px;">Failed to load timeline</div>';
        }
    }
}

// Helper function to get status color
function getStatusColor(colorClass) {
    const colors = {
        'green': '#22c55e',
        'blue': '#3b82f6', 
        'yellow': '#eab308',
        'orange': '#f97316',
        'red': '#ef4444',
        'purple': '#a855f7',
        'gray': '#6b7280'
    };
    return colors[colorClass] || '#6b7280';
}

// Helper function to format exact date and time
function formatExactTime(date) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[date.getMonth()];
    const day = date.getDate();
    const year = date.getFullYear();
    let hours = date.getHours();
    const minutes = date.getMinutes();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; // the hour '0' should be '12'
    const minutesStr = minutes < 10 ? '0' + minutes : minutes;
    
    return `${month} ${day}, ${year} ${hours}:${minutesStr} ${ampm}`;
}

// Legacy function kept for compatibility (if needed elsewhere)
function getTimeAgo(date) {
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString();
}
function openQuickRiderAssign(orderId, currentRiderId, currentRiderName) {
    try {
        let modal = document.getElementById('quickRiderModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'quickRiderModal';
            modal.style.cssText = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);z-index:9999;';
            modal.innerHTML = `<div style="background:#fff;border-radius:10px;min-width:420px;max-width:560px;padding:16px;border:1px solid #e5e7eb;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <h3 style="margin:0;font-weight:600;color:#111827;font-size:16px;">Assign Rider</h3>
                    <button onclick="document.getElementById('quickRiderModal').remove()" style="background:none;border:0;font-size:24px;color:#6b7280;cursor:pointer;line-height:1;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:background 0.15s;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='none'">&times;</button>
                </div>
                <div style="display:flex;gap:16px;">
                    <div style="flex:1;display:flex;flex-direction:column;gap:12px;">
                        <div style="padding:8px;background:#f3f4f6;border-radius:6px;border:1px solid #e5e7eb;">
                            <div style="font-size:12px;color:#6b7280;margin-bottom:2px;">Current Rider</div>
                            <div id="quickRiderCurrent" style="font-size:14px;font-weight:500;color:#111827;">${currentRiderName || 'Unassigned'}</div>
                        </div>
                        <select id="quickRiderSelectStandalone" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"><option value="">Loading riders...</option></select>
                        <div style="display:flex;gap:8px;justify-content:flex-end;">
                            <button onclick="document.getElementById('quickRiderModal').remove()" style="padding:8px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;">Cancel</button>
                            <button id="quickRiderSaveBtn" style="padding:8px 12px;background:#2563eb;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:500;">Assign Rider</button>
                        </div>
                    </div>
                    <div style="flex:1;border-left:1px solid #e5e7eb;padding-left:16px;">
                        <h4 style="margin:0 0 8px 0;font-size:14px;font-weight:600;color:#374151;">Assignment History</h4>
                        <div id="quickRiderTimeline" style="max-height:160px;overflow-y:auto;">
                            <div style="text-align:center;color:#6b7280;font-size:13px;padding:20px;">Loading history...</div>
                        </div>
                    </div>
                </div>
            </div>`;
            document.body.appendChild(modal);
        }
        (async function(){
            try {
                const rRes = await fetch('/riders/active', { headers: { 'Accept': 'application/json' } });
                const rJson = await rRes.json();
                const rSel = document.getElementById('quickRiderSelectStandalone');
                if (rJson && rJson.success && rSel) {
                    const opts = (rJson.data||[]).map(r=>`<option value="${r.id}" ${r.id == currentRiderId ? 'selected' : ''}>${r.fullname}</option>`).join('');
                    rSel.innerHTML = '<option value="">-- Unassign rider --</option>' + opts;
                }
                try {
                    const hRes = await fetch(`/orders/${orderId}/rider/timeline`, { headers: { 'Accept': 'application/json' } });
                    const hJson = await hRes.json();
                    const timeline = document.getElementById('quickRiderTimeline');
                    if (hJson && hJson.success && hJson.data && hJson.data.length > 0) {
                        timeline.innerHTML = hJson.data.slice(0,5).map(h=>{
                            const d = new Date(h.assigned_at);
                            const s = d.toLocaleDateString('en-US', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
                            const name = h.rider_name || 'Unknown';
                            const badge = h.is_current ? '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">CURRENT</span>' : '';
                            return `<div style="padding:8px;border-left:2px solid ${h.is_current ? '#10b981' : '#e5e7eb'};margin-bottom:8px;background:${h.is_current ? '#f0fdf4' : '#fafafa'};"><div style="font-size:12px;font-weight:600;color:#111827;">${name} ${badge}</div><div style="font-size:11px;color:#6b7280;margin-top:2px;">${s}</div></div>`;
                        }).join('');
                    } else {
                        timeline.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:12px;padding:20px;">No history</div>';
                    }
                } catch(e) { console.warn('Rider history load failed', e); }
                const saveBtn = document.getElementById('quickRiderSaveBtn');
                saveBtn.onclick = async function(){
                    const val = document.getElementById('quickRiderSelectStandalone').value;
                    const selectedRiderName = val ? document.getElementById('quickRiderSelectStandalone').selectedOptions[0].text : 'Unassigned';
                    saveBtn.textContent = 'Assigning...'; saveBtn.disabled = true;
                    
                    // Helper function to actually assign the rider
                    const assignRider = async function(confirmed = false) {
                        try {
                            const payload = { 
                                rider_user_id: val ? parseInt(val,10) : null 
                            };
                            
                            // Add confirmation flag if this is a retry after confirmation
                            if (confirmed) {
                                payload.confirmed = true;
                            }
                            
                            const aRes = await fetch(`/orders/${orderId}/rider/assign`, { 
                                method:'POST', 
                                headers:{ 
                                    'Accept':'application/json',
                                    'Content-Type':'application/json',
                                    'X-CSRF-TOKEN':document.querySelector('meta[name=\'csrf-token\']').getAttribute('content') 
                                }, 
                                body: JSON.stringify(payload) 
                            });
                            const aJson = await aRes.json();
                            
                            // Check if confirmation is required (ledger will be updated)
                            if (!aJson.success && aJson.requires_confirmation && aJson.confirmation_data) {
                                const data = aJson.confirmation_data;
                                const confirmMsg = 
                                    `⚠️ LEDGER WILL BE UPDATED\n\n` +
                                    `This order has been posted to the ledger.\n` +
                                    `Changing the rider will reverse the old ledger entry and create a new one.\n\n` +
                                    `Order: ${data.order_number}\n` +
                                    `Amount: Rs. ${parseFloat(data.amount).toFixed(2)}\n\n` +
                                    `Old Rider: ${data.old_rider_name}\n` +
                                    `New Rider: ${data.new_rider_name}\n\n` +
                                    `The ledger will be moved from ${data.old_rider_name}'s account to ${data.new_rider_name}'s account.\n\n` +
                                    `Do you want to proceed?`;
                                
                                saveBtn.textContent = 'Assign Rider'; 
                                saveBtn.disabled = false;
                                
                                if (confirm(confirmMsg)) {
                                    // User confirmed - retry with confirmation flag
                                    saveBtn.textContent = 'Assigning...'; 
                                    saveBtn.disabled = true;
                                    await assignRider(true);
                                }
                                return;
                            }
                            
                            // Check for other errors
                            if (!aJson.success) {
                                throw new Error(aJson.message||'Failed');
                            }
                            
                            // Success!
                            // ⭐ SMART SYNC: Update specific order row (NO HARD REFRESH)
                            updateOrderRowRider(orderId, val ? parseInt(val,10) : null, selectedRiderName);
                            
                            // Show success toast with ledger info if applicable
                            let successMsg = '✓ Rider assigned successfully';
                            if (aJson.ledger_updated) {
                                successMsg += ' (Ledger updated)';
                            }
                            showToast(successMsg, 'success');
                            
                            // Close modal
                            document.getElementById('quickRiderModal').remove();
                            
                            // Refresh card counts
                            if (window.refreshRiderCards) refreshRiderCards();
                            
                        } catch(e) {
                            alert('Assign rider failed: ' + (e.message || 'Unknown error'));
                            saveBtn.textContent = 'Assign Rider'; 
                            saveBtn.disabled = false;
                        }
                    };
                    
                    // Start the assignment process
                    await assignRider(false);
                };
            } catch(e) { console.warn('Quick rider load failed', e); }
        })();
    } catch(e) { console.warn('openQuickRiderAssign failed', e); }
}

// ⭐ SMART SYNC: Update specific order row (NO HARD REFRESH)
function updateOrderRowRider(orderId, riderId, riderName) {
    // Find the order row by data attribute
    const row = document.querySelector(`tr[data-order-id="${orderId}"]`);
    if (!row) {
        console.log('Order row not found for ID:', orderId);
        return;
    }
    
    // Find the rider cell (usually has 'rider-cell' class or similar)
    const riderCell = row.querySelector('.order-rider-cell');
    if (!riderCell) {
        console.log('Rider cell not found in order row');
        return;
    }
    
    // Update the rider - preserve button structure!
    if (riderId) {
        // Create button with blue pill styling + pending indicator
        const escapedName = String(riderName).replace(/'/g, "\\'");
        riderCell.innerHTML = `
            <button type="button" 
                    onclick="event.stopPropagation(); openQuickRiderAssign(${orderId}, ${riderId}, '${escapedName}')" 
                    class="inline-flex items-center px-2 py-1 rounded bg-blue-50 text-blue-800 border border-blue-300 text-xs font-medium hover:bg-blue-100 cursor-pointer transition" 
                    title="Click to change rider">
                ${riderName}<span class="sync-status-indicator" data-order-id="${orderId}" style="display:inline-flex;align-items:center;gap:2px;font-size:10px;padding:2px 6px;border-radius:4px;background:#fef3c7;color:#92400e;margin-left:4px;">
                    <svg style="width:10px;height:10px;animation:spin 1s linear infinite;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Pending
                </span>
            </button>
        `;
    } else {
        riderCell.innerHTML = '<button type="button" onclick="event.stopPropagation(); openQuickRiderAssign(' + orderId + ', null, \'Unassigned\')" class="inline-flex items-center px-2 py-1 rounded bg-gray-100 text-gray-700 text-xs hover:bg-gray-200 cursor-pointer transition" title="Click to assign rider">Unassigned</button>';
    }
    
    // Highlight row briefly
    row.style.background = '#dbeafe';
    setTimeout(() => {
        row.style.background = '';
    }, 2000);
}

// ============================================
// PAYMENT METHOD QUICK CHANGE
// ============================================
function openQuickPaymentMethodChange(orderId, currentMethod) {
    try {
        let modal = document.getElementById('quickPaymentMethodModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'quickPaymentMethodModal';
            modal.style.cssText = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);z-index:9999;';
            modal.innerHTML = `<div style="background:#fff;border-radius:10px;min-width:420px;max-width:520px;padding:16px;border:1px solid #e5e7eb;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <h3 style="margin:0;font-weight:600;color:#111827;font-size:16px;">Change Payment Method</h3>
                    <button onclick="document.getElementById('quickPaymentMethodModal').remove()" style="background:none;border:0;font-size:24px;color:#6b7280;cursor:pointer;line-height:1;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:background 0.15s;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='none'">&times;</button>
                </div>
                <div style="display:flex;gap:16px;">
                    <div style="flex:1;display:flex;flex-direction:column;gap:12px;">
                        <div style="padding:8px;background:#f3f4f6;border-radius:6px;border:1px solid #e5e7eb;">
                            <div style="font-size:12px;color:#6b7280;margin-bottom:2px;">Current Method</div>
                            <div id="quickPaymentMethodCurrent" style="font-size:14px;font-weight:500;color:#111827;"></div>
                        </div>
                        <select id="quickPaymentMethodSelect" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                            <option value="cash">Cash</option>
                            <option value="online">Online</option>
                        </select>
                        <textarea id="quickPaymentMethodNotes" placeholder="Reason for change (optional)" style="width:100%;min-height:70px;padding:8px;border:1px solid #d1d5db;border-radius:6px;resize:vertical;"></textarea>
                        <div style="display:flex;gap:8px;justify-content:flex-end;">
                            <button onclick="document.getElementById('quickPaymentMethodModal').remove()" style="padding:8px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;">Cancel</button>
                            <button id="quickPaymentMethodSaveBtn" style="padding:8px 12px;background:#2563eb;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:500;">Update Method</button>
                        </div>
                    </div>
                    <div style="flex:1;border-left:1px solid #e5e7eb;padding-left:16px;">
                        <h4 style="margin:0 0 8px 0;font-size:14px;font-weight:600;color:#374151;">Change History</h4>
                        <div id="quickPaymentMethodTimeline" style="max-height:160px;overflow-y:auto;">
                            <div style="text-align:center;color:#6b7280;font-size:13px;padding:20px;">Loading history...</div>
                        </div>
                    </div>
                </div>
            </div>`;
            document.body.appendChild(modal);
        }
        
        // Normalize current method for display
        const normalizedCurrent = (currentMethod || 'cash').toLowerCase().trim();
        let displayCurrent = 'Cash';
        if (normalizedCurrent.includes('online') || normalizedCurrent.includes('bank') || normalizedCurrent.includes('card')) {
            displayCurrent = 'Online';
        }
        
        // Set current method display
        document.getElementById('quickPaymentMethodCurrent').textContent = displayCurrent;
        
        // Set select value
        const selectEl = document.getElementById('quickPaymentMethodSelect');
        selectEl.value = displayCurrent.toLowerCase();
        
        // Load timeline
        loadQuickPaymentMethodTimeline(orderId);
        
        // Setup save button
        const btn = document.getElementById('quickPaymentMethodSaveBtn');
        btn.onclick = async function() {
            const newMethod = document.getElementById('quickPaymentMethodSelect').value;
            const notes = document.getElementById('quickPaymentMethodNotes').value;
            
            btn.textContent = 'Updating...';
            btn.disabled = true;
            
            try {
                const res = await fetch('/orders/api/change-payment-method', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        order_id: orderId,
                        payment_method: newMethod,
                        notes: notes
                    })
                });
                
                const data = await res.json();
                
                if (data.success) {
                    showToast('Payment method updated successfully', 'success');
                    document.getElementById('quickPaymentMethodModal').remove();
                    
                    // Update the order row
                    updateOrderRowPaymentMethod(orderId, newMethod);
                    
                    // Optionally reload data
                    if (typeof loadData === 'function') {
                        loadData();
                    }
                } else {
                    showToast(data.message || 'Failed to update payment method', 'error');
                    btn.textContent = 'Update Method';
                    btn.disabled = false;
                }
            } catch (error) {
                console.error('Error updating payment method:', error);
                showToast('Error updating payment method', 'error');
                btn.textContent = 'Update Method';
                btn.disabled = false;
            }
        };
    } catch (error) {
        console.error('Error opening payment method modal:', error);
    }
}

async function loadQuickPaymentMethodTimeline(orderId) {
    try {
        const response = await fetch(`/orders/${orderId}/payment-method/timeline`, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        const timeline = document.getElementById('quickPaymentMethodTimeline');
        
        if (data.success && data.data && data.data.length > 0) {
            timeline.innerHTML = data.data.slice(0, 5).map(h => {
                const d = new Date(h.changed_at);
                const s = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                return `<div style="padding:8px;border-bottom:1px solid #f3f4f6;">
                    <div style="font-size:12px;font-weight:500;color:#111827;">${h.old_method || 'N/A'} → ${h.new_method}</div>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px;">${s}</div>
                    ${h.notes ? `<div style="font-size:11px;color:#6b7280;margin-top:2px;font-style:italic;">${h.notes}</div>` : ''}
                </div>`;
            }).join('');
        } else {
            timeline.innerHTML = '<div style="text-align:center;color:#6b7280;font-size:13px;padding:20px;">No history available</div>';
        }
    } catch (error) {
        console.error('Error loading payment method timeline:', error);
        document.getElementById('quickPaymentMethodTimeline').innerHTML = '<div style="text-align:center;color:#ef4444;font-size:13px;padding:20px;">Failed to load history</div>';
    }
}

function updateOrderRowPaymentMethod(orderId, newMethod) {
    const row = document.querySelector(`tr[data-order-id="${orderId}"]`);
    if (!row) {
        console.log('Order row not found for ID:', orderId);
        return;
    }
    
    // Find the payment method cell
    const pmCell = row.querySelector('.order-payment-method-cell');
    if (!pmCell) {
        console.log('Payment method cell not found in order row');
        return;
    }
    
    // Determine styling based on method
    let displayText = 'Cash';
    let bgClass = 'bg-green-50';
    let borderClass = 'border-green-300';
    let textClass = 'text-green-800';
    
    if (newMethod.toLowerCase().includes('online')) {
        displayText = 'Online';
        bgClass = 'bg-purple-50';
        borderClass = 'border-purple-300';
        textClass = 'text-purple-800';
    }
    
    pmCell.innerHTML = `<button type="button" onclick="event.stopPropagation(); openQuickPaymentMethodChange(${orderId}, '${newMethod}')" class="inline-flex items-center px-2 py-1 rounded ${bgClass} ${borderClass} border ${textClass} text-xs font-medium hover:opacity-80 cursor-pointer transition" title="Click to change payment method">${displayText}</button>`;
    
    // Highlight row briefly
    row.style.background = '#dbeafe';
    setTimeout(() => {
        row.style.background = '';
    }, 2000);
}

// Toast notification (non-intrusive)
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    const bgColor = type === 'success' ? '#10b981' : '#ef4444';
    toast.style.cssText = `position:fixed;top:20px;right:20px;background:${bgColor};color:#fff;padding:12px 20px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:10000;font-size:14px;font-weight:500;animation:slideIn 0.3s ease-out;`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ⭐ SMART SYNC: Poll for sync status updates
let syncPollingInterval = null;
function startSyncStatusPolling() {
    // Stop existing polling if any
    if (syncPollingInterval) {
        clearInterval(syncPollingInterval);
    }
    
    // Initial check immediately
    checkSyncStatus();
    
    // Poll every 5 seconds for recent assignments
    syncPollingInterval = setInterval(checkSyncStatus, 5000);
}

async function checkSyncStatus() {
    try {
        const response = await fetch('/orders/sync-status?hours=1', {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        
        console.log('🔄 Sync status check:', data); // Debug log
        
        if (data.success && data.orders) {
            console.log(`📦 Found ${data.orders.length} orders to check`); // Debug
            
            data.orders.forEach(order => {
                console.log(`Checking order ${order.id}: ${order.sync_status}`); // Debug
                
                const row = document.querySelector(`tr[data-order-id="${order.id}"]`);
                if (!row) {
                    console.log(`❌ Row not found for order ${order.id}`);
                    return;
                }
                
                const riderCell = row.querySelector('.order-rider-cell');
                if (!riderCell) {
                    console.log(`❌ Rider cell not found for order ${order.id}`);
                    return;
                }
                
                let indicator = riderCell.querySelector(`.sync-status-indicator[data-order-id="${order.id}"]`);
                console.log(`Indicator for ${order.id}:`, indicator ? 'Found' : 'Not found');
                
                // If order needs sync but has no indicator, add one
                if (!indicator && order.sync_status === 'pending') {
                    console.log(`➕ Adding pending indicator for order ${order.id}`);
                    const riderButton = riderCell.querySelector('button');
                    if (riderButton) {
                        // Don't overwrite - append instead
                        const indicatorHtml = `<span class="sync-status-indicator" data-order-id="${order.id}" style="display:inline-flex;align-items:center;gap:2px;font-size:10px;padding:2px 6px;border-radius:4px;background:#fef3c7;color:#92400e;margin-left:4px;">
                            <svg style="width:10px;height:10px;animation:spin 1s linear infinite;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            Pending
                        </span>`;
                        riderButton.insertAdjacentHTML('beforeend', indicatorHtml);
                        indicator = riderCell.querySelector(`.sync-status-indicator[data-order-id="${order.id}"]`);
                    }
                }
                
                // Update existing indicator if order is now synced
                if (indicator && order.sync_status === 'synced') {
                    console.log(`✅ Updating to SYNCED for order ${order.id}`);
                    indicator.style.background = '#d1fae5';
                    indicator.style.color = '#065f46';
                    indicator.innerHTML = `
                        <svg style="width:10px;height:10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        Synced
                    `;
                    
                    // Remove indicator after 10 seconds - just remove the badge, don't touch the button
                    setTimeout(() => {
                        if (indicator && indicator.parentElement) {
                            console.log(`🔄 Removing synced badge for order ${order.id}`);
                            indicator.remove();
                        }
                    }, 10000);
                }
            });
        }
    } catch (error) {
        console.error('❌ Sync status polling error:', error);
    }
}

// Start polling when page loads
document.addEventListener('DOMContentLoaded', () => {
    startSyncStatusPolling();
});

// Load status timeline for edit order modal
async function loadEditOrderTimeline(orderId) {
    try {
        const timelineContainer = document.getElementById('editOrderTimeline');
        if (!timelineContainer) return;
        
        const response = await fetch(`/order-status/api/orders/${orderId}/timeline`, {
            headers: { 
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' 
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            const timelineHtml = data.data.map((item, index) => {
                const date = new Date(item.changed_at);
                const timeDisplay = formatExactTime(date);
                const isFirst = index === 0;
                
                return `
                    <div style="display:flex;align-items:start;gap:12px;margin-bottom:${index === data.data.length - 1 ? '0' : '16px'};">
                        <div style="width:10px;height:10px;border-radius:50%;background:${getStatusColor(item.color_class)};margin-top:6px;flex-shrink:0;${isFirst ? 'box-shadow:0 0 0 3px rgba(34,197,94,0.2);' : ''}"></div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                                <span style="font-size:14px;font-weight:${isFirst ? '600' : '500'};color:#374151;">${item.icon} ${item.status_name}</span>
                                ${isFirst ? '<span style="font-size:11px;background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;">Current</span>' : ''}
                            </div>
                            <div style="font-size:12px;color:#6b7280;margin-bottom:2px;">${timeDisplay} • ${item.changed_by_name}</div>
                            ${item.notes && item.notes !== 'Status changed to ' + item.status_code ? `<div style="font-size:12px;color:#9ca3af;font-style:italic;background:#f9fafb;padding:4px 8px;border-radius:4px;border-left:3px solid #e5e7eb;">${item.notes}</div>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            timelineContainer.innerHTML = timelineHtml;
        } else {
            timelineContainer.innerHTML = '<div style="text-align:center;color:#6b7280;font-size:13px;padding:20px;">No status history available</div>';
        }
    } catch (error) {
        console.error('Failed to load edit timeline:', error);
        const timelineContainer = document.getElementById('editOrderTimeline');
        if (timelineContainer) {
            timelineContainer.innerHTML = '<div style="text-align:center;color:#ef4444;font-size:13px;padding:20px;">Failed to load timeline</div>';
        }
    }
}

// Load status timeline for view order modal
async function loadViewOrderTimeline(orderId) {
    try {
        const timelineContainer = document.getElementById('viewOrderTimeline');
        if (!timelineContainer) return;
        
        const response = await fetch(`/order-status/api/orders/${orderId}/timeline`, {
            headers: { 
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' 
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            const timelineHtml = data.data.map((item, index) => {
                const date = new Date(item.changed_at);
                const timeDisplay = formatExactTime(date);
                const isFirst = index === 0;
                
                return `
                    <div style="display:flex;align-items:start;gap:14px;margin-bottom:${index === data.data.length - 1 ? '0' : '18px'};">
                        <div style="width:12px;height:12px;border-radius:50%;background:${getStatusColor(item.color_class)};margin-top:4px;flex-shrink:0;${isFirst ? 'box-shadow:0 0 0 4px rgba(34,197,94,0.15);' : ''}"></div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span style="font-size:15px;font-weight:${isFirst ? '600' : '500'};color:#111827;">${item.icon} ${item.status_name}</span>
                                ${isFirst ? '<span style="font-size:11px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-weight:500;">Current</span>' : ''}
                            </div>
                            <div style="font-size:13px;color:#6b7280;margin-bottom:4px;">${timeDisplay} • ${item.changed_by_name}</div>
                            ${item.notes && item.notes !== 'Status changed to ' + item.status_code ? `<div style="font-size:13px;color:#9ca3af;font-style:italic;background:#ffffff;padding:8px 12px;border-radius:6px;border-left:4px solid #e5e7eb;margin-top:6px;">${item.notes}</div>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            timelineContainer.innerHTML = timelineHtml;
        } else {
            timelineContainer.innerHTML = '<div style="text-align:center;color:#6b7280;font-size:14px;padding:30px;">No status history available</div>';
        }
    } catch (error) {
        console.error('Failed to load view timeline:', error);
        const timelineContainer = document.getElementById('viewOrderTimeline');
        if (timelineContainer) {
            timelineContainer.innerHTML = '<div style="text-align:center;color:#ef4444;font-size:14px;padding:30px;">Failed to load timeline</div>';
        }
    }
}

// Quick Status Change Modal
function openQuickStatusChange(orderId, currentStatus) {
    console.log('Opening quick status change for order ID:', orderId, 'Current status:', currentStatus); // Debug log
    // Build a simple modal lazily
    let modal = document.getElementById('quickStatusModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'quickStatusModal';
        modal.style.cssText = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);z-index:9999;';
        modal.innerHTML = `<div style="background:#fff;border-radius:10px;min-width:420px;max-width:520px;padding:16px;border:1px solid #e5e7eb;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 style="margin:0;font-weight:600;color:#111827;font-size:16px;">Change Status</h3>
                <button onclick="document.getElementById('quickStatusModal').remove()" style="background:none;border:0;font-size:20px;color:#6b7280;cursor:pointer">×</button>
            </div>
            <div style="display:flex;gap:16px;">
                <div style="flex:1;display:flex;flex-direction:column;gap:12px;">
                    <select id="quickStatusSelect" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;"></select>
                    <textarea id="quickStatusNotes" placeholder="Reason (optional)" style="width:100%;min-height:70px;padding:8px;border:1px solid #d1d5db;border-radius:6px;"></textarea>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button onclick="document.getElementById('quickStatusModal').remove()" style="padding:8px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;">Cancel</button>
                        <button id="quickStatusSave" style="padding:8px 12px;background:#2563eb;color:#fff;border:0;border-radius:6px;cursor:pointer;">Save</button>
                    </div>
                </div>
                <div style="flex:1;border-left:1px solid #e5e7eb;padding-left:16px;">
                    <h4 style="margin:0 0 8px 0;font-size:14px;font-weight:600;color:#374151;">Recent Changes</h4>
                    <div id="quickStatusTimeline" style="max-height:140px;overflow-y:auto;">
                        <div style="text-align:center;color:#6b7280;font-size:13px;padding:20px;">Loading timeline...</div>
                    </div>
                </div>
            </div>
        </div>`;
        document.body.appendChild(modal);
    }
    // Load statuses and preselect
    (async function(){
        try {
            const resp = await fetch('/order-status/api/statuses', { headers: { 'Accept': 'application/json','X-Requested-With':'XMLHttpRequest' }, credentials:'same-origin' });
            const data = await resp.json();
            const sel = document.getElementById('quickStatusSelect');
            if (sel) {
                sel.innerHTML = data.success ? data.data.map(s=>`<option value="${s.status_code}">${s.icon} ${s.status_name}</option>`).join('') : '';
                sel.value = currentStatus || 'new';
            }
            
            // Load timeline
            loadQuickStatusTimeline(orderId);
            const btn = document.getElementById('quickStatusSave');
            
            // Helper function to actually change the status
            const changeStatus = async function(confirmed = false) {
                try {
                    const status_code = document.getElementById('quickStatusSelect').value;
                    const notes = document.getElementById('quickStatusNotes').value;
                    
                    const payload = { 
                        order_id: orderId, 
                        status_code, 
                        notes 
                    };
                    
                    // Add confirmation flag if this is a retry after confirmation
                    if (confirmed) {
                        payload.confirmed = true;
                    }
                    
                    btn.textContent = 'Saving...';
                    btn.disabled = true;
                    
                    const res = await fetch('/order-status/api/change-status', {
                        method:'POST',
                        headers:{ 
                            'Accept':'application/json',
                            'Content-Type':'application/json',
                            'X-Requested-With':'XMLHttpRequest',
                            'X-CSRF-TOKEN':document.querySelector('meta[name=\'csrf-token\']').getAttribute('content') 
                        },
                        credentials:'same-origin',
                        body: JSON.stringify(payload)
                    });
                    const j = await res.json();
                    
                    // Check if confirmation is required (ledger will be reversed)
                    if (!j.success && j.requires_confirmation && j.confirmation_data) {
                        const data = j.confirmation_data;
                        const confirmMsg = 
                            `⚠️ LEDGER REVERSAL REQUIRED\n\n` +
                            `This order has been posted to the ledger.\n` +
                            `Cancelling will reverse the ledger entry.\n\n` +
                            `Order: ${data.order_number}\n` +
                            `Amount: Rs. ${parseFloat(data.amount).toFixed(2)}\n` +
                            `Posted to: ${data.account_name}\n` +
                            `Mode: ${data.ledger_mode === 'cash' ? 'Cash' : 'Online'}\n\n` +
                            `The ledger entry will be reversed and account balances will be updated.\n\n` +
                            `Do you want to proceed with cancellation?`;
                        
                        btn.textContent = 'Save';
                        btn.disabled = false;
                        
                        if (confirm(confirmMsg)) {
                            // User confirmed - retry with confirmation flag
                            await changeStatus(true);
                        }
                        return;
                    }
                    
                    // Check for other errors
                    if (j && j.success) {
                        document.getElementById('quickStatusModal').remove();
                        location.reload();
                    } else {
                        alert(j.message || 'Failed to change status');
                        btn.textContent = 'Save';
                        btn.disabled = false;
                    }
                } catch(e) {
                    console.error('Status change failed:', e);
                    alert('Failed to change status: ' + e.message);
                    btn.textContent = 'Save';
                    btn.disabled = false;
                }
            };
            
            btn.onclick = async function(){
                await changeStatus(false);
            };
        } catch(e) {
            console.warn('Quick status fetch failed', e);
        }
    })();
}
function saveOrderChanges(orderId) {
    const form = document.getElementById('editOrderForm');
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    // Validate customer information if creating new order
    if (!orderId) {
        const existingSection = document.getElementById('existingCustomerSection');
        const newSection = document.getElementById('newCustomerSection');
        
        // Check which mode is active by looking at display style
        const isExistingMode = existingSection && (existingSection.style.display === 'block' || existingSection.style.display === '');
        const isNewMode = newSection && (newSection.style.display === 'block' || newSection.style.display === '');
        
        console.log('Validation check:', { isExistingMode, isNewMode });
        
        if (isExistingMode) {
            // Existing customer mode - must have selected a customer
            const customerId = document.getElementById('selectedCustomerId')?.value;
            if (!customerId || customerId === '') {
                alert('Please select an existing customer or switch to "New Customer" mode to create a new one.');
                return;
            }
        } else if (isNewMode) {
            // New customer mode - validate required fields
            const firstName = form.querySelector('input[name="customer_first_name"]')?.value?.trim();
            const lastName = form.querySelector('input[name="customer_last_name"]')?.value?.trim();
            const phone = form.querySelector('input[name="customer_phone"]')?.value?.trim();
            const address1 = form.querySelector('input[name="customer_address1"]')?.value?.trim();
            
            if (!firstName) {
                alert('First Name is required for new customer');
                form.querySelector('input[name="customer_first_name"]')?.focus();
                return;
            }
            if (!lastName) {
                alert('Last Name is required for new customer');
                form.querySelector('input[name="customer_last_name"]')?.focus();
                return;
            }
            if (!phone) {
                alert('Phone Number is required for new customer');
                form.querySelector('input[name="customer_phone"]')?.focus();
                return;
            }
            if (!address1) {
                alert('Address Line 1 is required for new customer');
                form.querySelector('input[name="customer_address1"]')?.focus();
                return;
            }
        } else {
            // Neither mode is visible - shouldn't happen, but handle gracefully
            alert('Please select customer information');
            return;
        }
    }
    
    if (submitBtn) {
        submitBtn.textContent = 'Saving...';
        submitBtn.disabled = true;
    }
    
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
    
    // Collect discounts
    const discounts = [];
    document.querySelectorAll('.discount-row').forEach((row) => {
        const title = row.querySelector('[name$="[title]"]')?.value;
        const amount = parseFloat(row.querySelector('[name$="[amount]"]')?.value) || 0;
        
        if (title && amount > 0) {
            discounts.push({
                title: title,
                amount: amount,
                type: 'fixed'
            });
        }
    });
    
    // Prepare data for update (matching the existing update endpoint structure)
    const rawOrderDate = formData.get('order_date');
    const formattedOrderDate = rawOrderDate ? rawOrderDate.replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00';
    
    const orderData = {
        customer_id: formData.get('customer_id'), // ✅ CRITICAL: Include customer_id for linking
        order_status: formData.get('order_status'),
        order_date: formattedOrderDate,
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        expected_packets: formData.get('expected_packets') ? parseInt(formData.get('expected_packets')) : null, // Packet tracking
        items: items,
        discounts: discounts, // NEW: Include discounts array
        // Address fields
        address_first_name: formData.get('address_first_name'),
        address_last_name: formData.get('address_last_name'),
        address_email: formData.get('address_email'),
        address_phone: formData.get('address_phone'),
        address_line1: formData.get('address_line1'),
        address_line2: formData.get('address_line2'),
        address_city: formData.get('address_city'),
        address_country: formData.get('address_country')
    };
    
    // ================================================================
    // LEDGER ADJUSTMENT CONFIRMATION
    // ================================================================
    // Debug: Log order data
    console.log('📦 Order Data Being Sent:', {
        customer_id: orderData.customer_id,
        order_status: orderData.order_status,
        total_price: orderData.total_price
    });
    console.log('🔍 Ledger Adjustment Check:', {
        hasCurrentOrder: !!window.currentOrder,
        ledger_transaction_id: window.currentOrder?.ledger_transaction_id,
        order_status: window.currentOrder?.order_status,
        oldTotal: window.currentOrder?.total_price,
        newTotal: orderData.total_price
    });
    
    // Check if order is delivered and has ledger entry, and if price changed
    if (window.currentOrder && window.currentOrder.ledger_transaction_id && window.currentOrder.order_status === 'delivered') {
        const oldTotal = parseFloat(window.currentOrder.total_price) || 0;
        const newTotal = orderData.total_price;
        
        console.log('✅ Ledger adjustment conditions met - checking price difference');
        
        // Check if there's a significant change (more than 1 cent to account for floating point)
        if (Math.abs(oldTotal - newTotal) > 0.01) {
            const difference = newTotal - oldTotal;
            const confirmed = confirm(
                `⚠️ LEDGER ADJUSTMENT REQUIRED\n\n` +
                `This order has already been posted to the ledger.\n\n` +
                `Old Amount: Rs. ${oldTotal.toFixed(2)}\n` +
                `New Amount: Rs. ${newTotal.toFixed(2)}\n` +
                `Difference: ${difference >= 0 ? '+' : ''}Rs. ${difference.toFixed(2)}\n\n` +
                `The ledger adjustment will be sent for L1→L2 approval.\n` +
                `The order will be updated immediately, but the ledger will only be updated after approval.\n\n` +
                `Do you want to proceed?`
            );
            
            if (!confirmed) {
                submitBtn.textContent = 'Save';
                submitBtn.disabled = false;
                return; // Cancel the save
            }
        }
    }
    
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
            // Show appropriate message based on whether adjustment was created
            if (data.requires_approval) {
                showSuccessMessage(data.message + ' (Adjustment ID: #' + data.adjustment_id + ')');
            } else {
                showSuccessMessage('Order updated successfully!');
            }
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
    
    // Collect discounts (same as saveOrderChanges)
    const discounts = [];
    document.querySelectorAll('.discount-row').forEach((row) => {
        const title = row.querySelector('[name$="[title]"]')?.value;
        const amount = parseFloat(row.querySelector('[name$="[amount]"]')?.value) || 0;
        
        if (title && amount > 0) {
            discounts.push({
                title: title,
                amount: amount,
                type: 'fixed'
            });
        }
    });
    
    // Prepare data for update (same as saveOrderChanges)
    const rawOrderDate = formData.get('order_date');
    const formattedOrderDate = rawOrderDate ? rawOrderDate.replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00';
    
    const orderData = {
        customer_id: formData.get('customer_id'), // ✅ CRITICAL: Include customer_id for linking
        order_status: formData.get('order_status'),
        order_date: formattedOrderDate,
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        expected_packets: formData.get('expected_packets') ? parseInt(formData.get('expected_packets')) : null, // Packet tracking
        items: items,
        discounts: discounts, // NEW: Include discounts array
        // Address fields
        address_first_name: formData.get('address_first_name'),
        address_last_name: formData.get('address_last_name'),
        address_email: formData.get('address_email'),
        address_phone: formData.get('address_phone'),
        address_line1: formData.get('address_line1'),
        address_line2: formData.get('address_line2'),
        address_city: formData.get('address_city'),
        address_country: formData.get('address_country')
    };
    
    // ================================================================
    // LEDGER ADJUSTMENT CONFIRMATION (same as saveOrderChanges)
    // ================================================================
    if (window.currentOrder && window.currentOrder.ledger_transaction_id && window.currentOrder.order_status === 'delivered') {
        const oldTotal = parseFloat(window.currentOrder.total_price) || 0;
        const newTotal = orderData.total_price;
        
        if (Math.abs(oldTotal - newTotal) > 0.01) {
            const difference = newTotal - oldTotal;
            const confirmed = confirm(
                `⚠️ LEDGER ADJUSTMENT REQUIRED\n\n` +
                `This order has already been posted to the ledger.\n\n` +
                `Old Amount: Rs. ${oldTotal.toFixed(2)}\n` +
                `New Amount: Rs. ${newTotal.toFixed(2)}\n` +
                `Difference: ${difference >= 0 ? '+' : ''}Rs. ${difference.toFixed(2)}\n\n` +
                `The ledger adjustment will be sent for L1→L2 approval.\n` +
                `The order will be updated immediately, but the ledger will only be updated after approval.\n\n` +
                `Do you want to proceed?`
            );
            
            if (!confirmed) {
                saveAndCloseBtn.textContent = 'Save & Close';
                saveAndCloseBtn.disabled = false;
                return; // Cancel the save
            }
        }
    }
    
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
            // Show appropriate message based on whether adjustment was created
            if (data.requires_approval) {
                showSuccessMessage(data.message + ' (Adjustment ID: #' + data.adjustment_id + ')');
            } else {
                showSuccessMessage('Order updated successfully!');
            }
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
                            div.style.cssText = 'display: grid; grid-template-columns: 3fr 70px 90px 110px 32px; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;';
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
                                    <button type="button" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; line-height: 1;">×</button>
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
            newItem.style.cssText = 'display: grid; grid-template-columns: 3fr 70px 90px 110px 32px; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;';
            
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
                           style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${newWindow.lineItemIndex}); freezeProductName(${newWindow.lineItemIndex})">
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
                    <button type="button" onclick="removeLineItem(${newWindow.lineItemIndex})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; line-height: 1;">
                        ×
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

// Open create order in a full Orders tab (loads entire app and auto-opens create modal)
function openCreateInTab() {
    const url = '/orders?create_new_order=1';
    window.open(url, '_blank');
}

// ============================================================================
// Pop-out Notification System
// Shows visual indicators on tab when order needs attention
// Blue Dot (🔵) = Needs editing (disappears on save)
// Bell Icon (🔔) = Needs printing (disappears on print)
// ============================================================================
let popoutNotificationState = {
    orderId: null,
    orderStatus: null,
    needsEdit: true,      // Blue dot - starts true, disappears on save
    needsPrint: true,     // Bell icon - starts true, disappears on print
    originalTitle: '',
    faviconInterval: null
};

function initPopoutNotification(orderId) {
    if (!window.isPopoutMode) return;
    
    popoutNotificationState.orderId = orderId;
    popoutNotificationState.originalTitle = document.title;
    
    // Fetch order details to check status
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
        if (data.success && data.order) {
            popoutNotificationState.orderStatus = data.order.order_status;
            
            // Check if status requires notification
            // Exclude: new, delivered, completed, cancelled
            // Include: processing, out for delivery, pending, etc.
            if (shouldShowNotification(data.order.order_status)) {
                showPopoutNotification();
                attachPopoutEventListeners();
            }
        }
    })
    .catch(error => {
        console.error('Error checking order status for notification:', error);
    });
}

function shouldShowNotification(status) {
    if (!status) return false;
    
    const statusLower = status.toLowerCase().trim();
    const excludedStatuses = ['new', 'delivered', 'completed', 'cancelled'];
    
    return !excludedStatuses.includes(statusLower);
}

function showPopoutNotification() {
    // Update title with indicators
    updatePopoutTitle();
    
    // Start animated favicon
    startFaviconAnimation();
}

function hidePopoutNotification() {
    // Update title (will show remaining indicators if any)
    updatePopoutTitle();
    
    // Update favicon animation based on remaining indicators
    if (!popoutNotificationState.needsEdit && !popoutNotificationState.needsPrint) {
        stopFaviconAnimation();
    } else {
        // Restart animation to reflect current state
        stopFaviconAnimation();
        startFaviconAnimation();
    }
}

function updatePopoutTitle() {
    let prefix = '';
    
    // Add blue dot if needs editing
    if (popoutNotificationState.needsEdit) {
        prefix += '🔵 ';
    }
    
    // Add bell icon if needs printing
    if (popoutNotificationState.needsPrint) {
        prefix += '🔔 ';
    }
    
    document.title = prefix + popoutNotificationState.originalTitle;
}

function startFaviconAnimation() {
    // Create animated favicon with dual indicators
    const canvas = document.createElement('canvas');
    canvas.width = 32;
    canvas.height = 32;
    const ctx = canvas.getContext('2d');
    
    let isPulse = false;
    popoutNotificationState.faviconInterval = setInterval(() => {
        // Clear canvas
        ctx.clearRect(0, 0, 32, 32);
        
        // Draw base circle (blue)
        ctx.fillStyle = '#2563eb';
        ctx.beginPath();
        ctx.arc(16, 16, 14, 0, 2 * Math.PI);
        ctx.fill();
        
        // Draw blue dot (top-left) if needs editing
        if (popoutNotificationState.needsEdit) {
            ctx.fillStyle = isPulse ? '#3b82f6' : '#60a5fa'; // Alternate blue shades
            ctx.beginPath();
            ctx.arc(8, 8, 5, 0, 2 * Math.PI);
            ctx.fill();
            
            // White border
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1.5;
            ctx.stroke();
        }
        
        // Draw red/orange dot (top-right) if needs printing
        if (popoutNotificationState.needsPrint) {
            ctx.fillStyle = isPulse ? '#ef4444' : '#f97316'; // Alternate red/orange
            ctx.beginPath();
            ctx.arc(24, 8, 5, 0, 2 * Math.PI);
            ctx.fill();
            
            // White border
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1.5;
            ctx.stroke();
        }
        
        // Update favicon
        const link = document.querySelector("link[rel*='icon']") || document.createElement('link');
        link.type = 'image/x-icon';
        link.rel = 'shortcut icon';
        link.href = canvas.toDataURL();
        document.getElementsByTagName('head')[0].appendChild(link);
        
        isPulse = !isPulse;
    }, 800);
}

function stopFaviconAnimation() {
    if (popoutNotificationState.faviconInterval) {
        clearInterval(popoutNotificationState.faviconInterval);
        popoutNotificationState.faviconInterval = null;
    }
    
    // Restore default favicon
    const link = document.querySelector("link[rel*='icon']") || document.createElement('link');
    link.type = 'image/x-icon';
    link.rel = 'shortcut icon';
    link.href = '/favicon.ico';
    document.getElementsByTagName('head')[0].appendChild(link);
}

function attachPopoutEventListeners() {
    // Listen for save button clicks (editing complete)
    // We track save, not just typing, so blue dot disappears on save
    const originalSaveOrderChanges = window.saveOrderChanges;
    
    window.saveOrderChanges = function(orderId) {
        // Call original function first
        if (originalSaveOrderChanges) {
            originalSaveOrderChanges.apply(this, arguments);
        }
        
        // Mark as edited (blue dot disappears)
        if (popoutNotificationState.needsEdit) {
            popoutNotificationState.needsEdit = false;
            hidePopoutNotification();
        }
    };
    
    // Listen for print button clicks
    // Override the existing functions to track printing
    const originalPrintPdf = window.printInvoicePdf;
    const originalDownloadImage = window.downloadInvoiceImage;
    
    window.printInvoicePdf = function() {
        // Mark as printed (bell icon disappears)
        if (popoutNotificationState.needsPrint) {
            popoutNotificationState.needsPrint = false;
            hidePopoutNotification();
        }
        
        // Call original function
        if (originalPrintPdf) originalPrintPdf.apply(this, arguments);
    };
    
    window.downloadInvoiceImage = function() {
        // Mark as printed (bell icon disappears)
        if (popoutNotificationState.needsPrint) {
            popoutNotificationState.needsPrint = false;
            hidePopoutNotification();
        }
        
        // Call original function
        if (originalDownloadImage) originalDownloadImage.apply(this, arguments);
    };
}
// Update modal header for create order with pop-out functionality
function updateCreateOrderModalHeader() {
    const modal = document.getElementById('editOrderModal');
    if (!modal) return;
    
    // Find the modal header more reliably
    const headerDiv = modal.querySelector('[style*="padding: 20px"][style*="border-bottom"]');
    if (!headerDiv) return;
    
    headerDiv.innerHTML = `
        <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Create New Order</h3>
        <div style="display: flex; align-items: center; gap: 12px;">
            <!-- Pop Out Button -->
            <button onclick="openCreateInTab()" 
                    style="background: #7c3aed; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 6px;"
                    title="Open in new tab">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                    <polyline points="15,3 21,3 21,9"></polyline>
                    <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg>
                Pop Out
            </button>
            
            <button onclick="closeModal('editOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
    `;
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
        closeModal('customerDetailsModal');
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
        const createNew = params.get('create_new_order');
        
        if (editId) {
            // Mark this as a pop-out mode for full-screen styling
            window.isPopoutMode = true;
            editOrderDetails(editId);
            
            // Initialize notification tracking for pop-out mode
            initPopoutNotification(editId);
        } else if (createNew === '1') {
            // Mark this as a pop-out mode for full-screen styling
            window.isPopoutMode = true;
            createNewOrder();
            
            // Apply pop-out styling to the create order modal
            setTimeout(() => {
                const modal = document.getElementById('editOrderModal');
                if (modal) {
                    applyPopoutStyling(modal);
                    // Update title for create order pop-out
                    document.title = 'New Order';
                }
            }, 100);
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
// Global variables for keyboard navigation in product dropdown
let currentDropdownIndex = -1;
let currentLineItemIndex = -1;
let currentProducts = [];

// Expose functions globally for inline event handlers and pop-out windows
window.currentDropdownIndex = -1;
window.currentLineItemIndex = -1;
window.currentProducts = [];

function searchProducts(input, index) {
    clearTimeout(productSearchTimeout);
    const query = input.value.trim();
    
    if (query.length < 2) {
        hideProductDropdown(index);
        return;
    }
    
    productSearchTimeout = setTimeout(() => {
        fetch(`/api/products/search?q=${encodeURIComponent(query)}&limit=20`, {
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
                console.log('Product search debug:', data.debug);
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
    // Get the specific line item by data-index attribute to avoid selecting wrong row
    const lineItem = document.querySelector(`.line-item[data-index="${index}"]`);
    if (!lineItem) {
        console.error('Line item not found for index:', index);
        return;
    }
    
    // Fill in the product details using the specific line item context
    const nameInput = lineItem.querySelector(`input[name="items[${index}][name]"]`);
    const priceInput = lineItem.querySelector(`input[name="items[${index}][unit_price]"]`);
    const idInput = lineItem.querySelector(`input[name="items[${index}][id]"]`);
    
    if (nameInput) nameInput.value = productName;
    if (idInput) idInput.value = productId;
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
    
    // Freeze the product name immediately after selection
    setTimeout(() => {
        freezeProductName(index);
    }, 50);
    
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
    
    // Calculate total discount from all discount rows
    let totalDiscount = 0;
    document.querySelectorAll('.discount-row').forEach(row => {
        const amount = parseFloat(row.querySelector('[name$="[amount]"]')?.value) || 0;
        totalDiscount += amount;
    });
    
    const shipping = parseFloat(document.querySelector('input[name="shipping_total"]')?.value) || 0;
    
    // Update discount display
    const discountDisplay = document.getElementById('totalDiscountDisplay');
    if (discountDisplay) {
        discountDisplay.textContent = 'Rs. ' + totalDiscount.toFixed(2);
    }
    
    // Calculate final total
    const total = subtotal - totalDiscount + shipping;
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

// Discount management functions
let discountRowIndex = 0;

function addDiscountRow(title = '', amount = 0) {
    const container = document.getElementById('discountsContainer');
    if (!container) return;
    
    const index = discountRowIndex++;
    
    const row = document.createElement('div');
    row.className = 'discount-row';
    row.setAttribute('data-index', index);
    row.style.cssText = 'display:flex;gap:8px;align-items:center;background:#f9fafb;padding:8px;border-radius:4px;border:1px solid #e5e7eb;position:relative;';
    
    row.innerHTML = `
        <div style="flex:2;position:relative;">
            <input type="text" 
                   name="discounts[${index}][title]" 
                   id="orderDiscountTitle_${index}"
                   placeholder="e.g., Member Discount, Seasonal Promo" 
                   value="${title}"
                   onkeyup="searchOrderDiscountCoupons(${index}, this.value)"
                   onfocus="showOrderDiscountDropdown(${index})"
                   onblur="hideOrderDiscountDropdown(${index})"
                   style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">
            <div id="orderDiscountDropdown_${index}" 
                 style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #d1d5db;border-radius:4px;max-height:200px;overflow-y:auto;z-index:1000;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);margin-top:2px;">
            </div>
        </div>
        <input type="number" 
               step="0.01" 
               name="discounts[${index}][amount]" 
               id="orderDiscountAmount_${index}"
               placeholder="Amount" 
               value="${amount}"
               onchange="updateOrderTotal()"
               style="flex:1;padding:8px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">
        <button type="button" 
                onclick="removeDiscountRow(${index})" 
                style="padding:6px 10px;background:#ef4444;color:#fff;border:0;border-radius:4px;cursor:pointer;font-size:14px;font-weight:600;">
            ×
        </button>
    `;
    
    container.appendChild(row);
    updateOrderTotal();
}

// Order discount coupon autocomplete functionality
let orderDiscountSearchTimeouts = {};
function searchOrderDiscountCoupons(rowIndex, query) {
    clearTimeout(orderDiscountSearchTimeouts[rowIndex]);
    
    if (query.length < 2) {
        const dropdown = document.getElementById(`orderDiscountDropdown_${rowIndex}`);
        if (dropdown) dropdown.style.display = 'none';
        return;
    }
    
    orderDiscountSearchTimeouts[rowIndex] = setTimeout(() => {
        fetch(`/coupons/search?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                const dropdown = document.getElementById(`orderDiscountDropdown_${rowIndex}`);
                if (!dropdown) return;
                
                if (data.success && data.data && data.data.length > 0) {
                    dropdown.innerHTML = data.data.map(coupon => `
                        <div onmousedown="selectOrderDiscountCoupon(${rowIndex}, '${escapeHtmlForOrder(coupon.title)}', ${coupon.value}, '${coupon.value_type}')" 
                             style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6;"
                             onmouseover="this.style.backgroundColor='#f3f4f6'" 
                             onmouseout="this.style.backgroundColor='white'">
                            <div style="font-weight: 500; font-size: 13px;">${escapeHtmlForOrder(coupon.display || coupon.title)}</div>
                            <div style="font-size: 12px; color: #6b7280;">
                                ${coupon.value_type === 'percentage' ? coupon.value + '%' : 'PKR ' + coupon.value} off
                            </div>
                        </div>
                    `).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.innerHTML = '<div style="padding: 8px 12px; color: #6b7280; font-size: 12px;">No coupons found</div>';
                    dropdown.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error searching discount coupons:', error);
            });
    }, 300);
}

function showOrderDiscountDropdown(rowIndex) {
    const input = document.getElementById(`orderDiscountTitle_${rowIndex}`);
    if (input && input.value.length >= 2) {
        searchOrderDiscountCoupons(rowIndex, input.value);
    }
}

function hideOrderDiscountDropdown(rowIndex) {
    setTimeout(() => {
        const dropdown = document.getElementById(`orderDiscountDropdown_${rowIndex}`);
        if (dropdown) dropdown.style.display = 'none';
    }, 200);
}

function selectOrderDiscountCoupon(rowIndex, title, value, valueType) {
    const titleInput = document.getElementById(`orderDiscountTitle_${rowIndex}`);
    const amountInput = document.getElementById(`orderDiscountAmount_${rowIndex}`);
    
    if (titleInput) titleInput.value = title;
    
    // Auto-calculate discount based on subtotal if it's percentage
    if (amountInput) {
        if (valueType === 'percentage') {
            const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]')?.value) || 0;
            const discountAmount = (subtotal * value) / 100;
            amountInput.value = discountAmount.toFixed(2);
        } else {
            amountInput.value = value.toFixed(2);
        }
    }
    
    updateOrderTotal();
    
    // Hide dropdown
    const dropdown = document.getElementById(`orderDiscountDropdown_${rowIndex}`);
    if (dropdown) dropdown.style.display = 'none';
}

function escapeHtmlForOrder(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function removeDiscountRow(index) {
    const row = document.querySelector(`.discount-row[data-index="${index}"]`);
    if (row) {
        row.remove();
        updateOrderTotal();
    }
}

function initializeDiscountsFromOrder(order) {
    // Clear existing discount rows
    const container = document.getElementById('discountsContainer');
    if (!container) return;
    
    container.innerHTML = '';
    discountRowIndex = 0;
    
    // If order has discount details, populate them
    if (order.discounts && order.discounts.length > 0) {
        order.discounts.forEach(discount => {
            addDiscountRow(discount.discount_title, discount.discount_amount);
        });
    } else if (order.discount_total && order.discount_total > 0) {
        // Fallback: if no detail but has total, create single discount
        const title = order.coupon_code ? `Discount (${order.coupon_code})` : 'Discount';
        addDiscountRow(title, order.discount_total);
    } else {
        // No discounts - add one empty row
        addDiscountRow();
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
                
                // Show customer details using the enhanced display
                const customerData = {
                    name: fullName,
                    phone: customer.phone_original || customer.phone || '',
                    email: customer.email || '',
                    notes: customer.notes || '',
                    address: {
                        address1: customer.address1 || '',
                        address2: customer.address2 || '',
                        city: customer.city || '',
                        province: customer.province || '',
                        postal_code: customer.postal_code || '',
                        country: customer.country || ''
                    }
                };
                showSelectedCustomerDetails(customerData);
                
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
                    note.innerHTML = `✅ Customer pre-selected from customers page: ${fullName}`;
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
    id: { label: 'ID', width: 'w-16', key: 'id' },
    order_number: { label: 'Order #', width: 'w-24', key: 'order_number' },
    order_date: { label: 'Order Date', width: 'w-40', key: 'order_date' },
    order_status: { label: 'Status', width: 'w-32', key: 'order_status' },
    external_source: { label: 'Source', width: 'w-20', key: 'external_source' },
    external_id: { label: 'External ID', width: 'w-24', key: 'external_id' },
    
    // Customer Info
    customer_name: { label: 'Customer Name', width: 'w-48', key: 'customer_name' },
    contact_email: { label: 'Contact Email', width: 'w-56', key: 'contact_email' },
    customer_phone: { label: 'Customer Phone', width: 'w-36', key: 'customer_phone' },
    
    // Address Info
    address_first_name: { label: 'Address First Name', width: 'w-36', key: 'address_first_name' },
    address_last_name: { label: 'Address Last Name', width: 'w-36', key: 'address_last_name' },
    address_full_name: { label: 'Address Name', width: 'w-44', key: 'address_full_name' },
    address_email: { label: 'Address Email', width: 'w-48', key: 'address_email' },
    address_phone: { label: 'Address Phone', width: 'w-32', key: 'address_phone' },
    address1: { label: 'Address', width: 'w-40', key: 'address1' },
    address2: { label: 'Address Line 2', width: 'w-48', key: 'address2' },
    address_city: { label: 'City', width: 'w-28', key: 'address_city' },
    address_province: { label: 'Province', width: 'w-28', key: 'address_province' },
    address_country: { label: 'Country', width: 'w-24', key: 'address_country' },
    postal_code: { label: 'Postal Code', width: 'w-24', key: 'postal_code' },
    
    // Financial Info
    currency: { label: 'Currency', width: 'w-16', key: 'currency' },
    subtotal_price: { label: 'Subtotal', width: 'w-28', key: 'subtotal_price' },
    discount_total: { label: 'Discount', width: 'w-24', key: 'discount_total' },
    shipping_total: { label: 'Shipping', width: 'w-24', key: 'shipping_total' },
    total_price: { label: 'Total', width: 'w-32', key: 'total_price' },
    total_weight: { label: 'Weight', width: 'w-20', key: 'total_weight' },
    
    // Payment & Other Info
    payment_method: { label: 'Payment Method', width: 'w-36', key: 'payment_method' },
    coupon_code: { label: 'Coupon Code', width: 'w-28', key: 'coupon_code' },
    note: { label: 'Note', width: 'w-56', key: 'note' },
    created_at: { label: 'Created At', width: 'w-36', key: 'created_at' },
    updated_at: { label: 'Updated At', width: 'w-36', key: 'updated_at' },
    
    // Line Items Count
    line_items_count: { label: 'Items', width: 'w-16', key: 'line_items_count' },
    
    // Rider Info
    rider: { label: 'Rider', width: 'w-32', key: 'rider' },
    rider_id: { label: 'Rider ID', width: 'w-20', key: 'rider_id' },
    
    // Actions column
    actions: { label: '{{ $source === "shopify" && ($tab ?? "all") === "approvals" ? "Approve / Ignore" : "Actions" }}', width: 'w-44', key: 'actions', fixed: true }
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

// Ensure all address fields and rider columns are present in currentColumns
function ensureAddressFields() {
    const addressFields = [
        'address_first_name', 'address_last_name', 'address_full_name',
        'address1', 'address2', 'postal_code', 'rider', 'rider_id'
    ];
    
    addressFields.forEach(fieldId => {
        const hasField = currentColumns.find(col => col.id === fieldId);
        if (!hasField) {
            // Add missing field (default to not visible)
            currentColumns.push({ id: fieldId, visible: false });
        }
    });
}

// Initialize columns
ensureActionsColumn();
ensureAddressFields();

// Migration: Ensure rider columns exist for existing users
if (!currentColumns.find(col => col.id === 'rider')) {
    currentColumns.push({ id: 'rider', visible: false });
}
if (!currentColumns.find(col => col.id === 'rider_id')) {
    currentColumns.push({ id: 'rider_id', visible: false });
}
// Save the updated columns to localStorage
localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));

// Debug: Log current columns after initialization
console.log('Current columns after initialization:', currentColumns);

    // Populate status filter from loaded orders data - simple and efficient
    function initStatusFilter() {
        const sel = document.getElementById('statusFilter');
        if (!sel || !window.ordersData) return;
        
        // Extract unique statuses from loaded orders
        const uniqueStatuses = [...new Set(window.ordersData.map(order => order.order_status).filter(status => status))];
        
        // Build options HTML
        const preserved = sel.value; // Preserve current selection
        const optionsHtml = '<option value="">All status</option>' +
            uniqueStatuses.map(status => {
                const displayName = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
                return `<option value="${status}">${displayName}</option>`;
            }).join('');
        
        sel.innerHTML = optionsHtml;
        
        // Restore previous selection if it still exists
        if (uniqueStatuses.includes(preserved)) {
            sel.value = preserved;
        }
        
        console.log('Status filter populated with', uniqueStatuses.length, 'unique statuses:', uniqueStatuses);
    }

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
// Global: initialize the top status filter from currently loaded orders
// Safe to call from any tab. For Shopify tabs we intentionally show only "All status".
function initStatusFilter() {
	try {
		const selectEl = document.getElementById('statusFilter');
		if (!selectEl) return;

		const source = (window.currentSource || '').toLowerCase();
		const orders = Array.isArray(window.ordersData) ? window.ordersData : [];

		// For non-Shopify sources, derive unique statuses from the loaded orders
		let uniqueStatuses = [];
		if (source !== 'shopify' && source !== 'shopify_approvals' && source !== 'shopify-approvals') {
			uniqueStatuses = [...new Set(orders.map(o => (o && o.order_status) ? String(o.order_status) : '').filter(Boolean))];
		}

		const preserved = selectEl.value;
		if (!uniqueStatuses.length) {
			selectEl.innerHTML = '<option value="">All status</option>';
			selectEl.value = '';
			return;
		}

		const optionsHtml = '<option value="">All status</option>' +
			uniqueStatuses.map(s => {
				const label = s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
				return `<option value="${s}">${label}</option>`;
			}).join('');
		selectEl.innerHTML = optionsHtml;
		if (uniqueStatuses.includes(preserved)) selectEl.value = preserved;
	} catch (e) {
		console.warn('initStatusFilter failed', e);
	}
}

function openColumnSettings() {
    renderColumnSettings();
    document.getElementById('columnSettingsModal').style.display = 'block';
}

function renderColumnSettings() {
    const columnList = document.getElementById('columnList');
    columnList.innerHTML = '';
    
    // Separate visible and hidden columns
    const visibleColumns = currentColumns.filter(col => col.visible);
    const hiddenColumns = currentColumns.filter(col => !col.visible);
    
    // Create visible section
    if (visibleColumns.length > 0) {
        const visibleHeader = document.createElement('div');
        visibleHeader.style.cssText = 'padding: 8px 12px; margin-bottom: 12px; background: #dbeafe; border-radius: 6px; font-weight: 600; font-size: 13px; color: #1e40af;';
        visibleHeader.textContent = `✓ Visible Columns (${visibleColumns.length})`;
        columnList.appendChild(visibleHeader);
        
        visibleColumns.forEach((column, index) => {
            const item = createColumnItem(column, true);
            if (item) columnList.appendChild(item);
        });
    }
    
    // Create hidden section
    if (hiddenColumns.length > 0) {
        const hiddenHeader = document.createElement('div');
        hiddenHeader.style.cssText = 'padding: 8px 12px; margin: 20px 0 12px 0; background: #f3f4f6; border-radius: 6px; font-weight: 600; font-size: 13px; color: #6b7280;';
        hiddenHeader.textContent = `✕ Hidden Columns (${hiddenColumns.length})`;
        columnList.appendChild(hiddenHeader);
        
        hiddenColumns.forEach((column, index) => {
            const item = createColumnItem(column, false);
            if (item) columnList.appendChild(item);
        });
    }
}

function createColumnItem(column, isVisible) {
    const columnConfig = availableColumns[column.id];
    if (!columnConfig) return null;
    
    const item = document.createElement('div');
    item.className = 'column-item';
    item.draggable = !columnConfig.fixed;
    item.dataset.columnId = column.id;
    item.dataset.isVisible = isVisible;
    item.style.cssText = `
        display: flex; 
        align-items: center; 
        padding: 12px; 
        margin-bottom: 8px; 
        background: ${isVisible ? '#ffffff' : '#fafafa'}; 
        border: 1px solid ${isVisible ? '#93c5fd' : '#e5e7eb'}; 
        border-radius: 6px; 
        cursor: ${columnConfig.fixed ? 'default' : 'grab'};
        user-select: none;
        transition: all 0.2s;
    `;
    
    item.innerHTML = `
        <div style="display: flex; align-items: center; width: 100%;">
            <div style="margin-right: 12px; color: ${columnConfig.fixed ? '#9ca3af' : '#6b7280'}; cursor: ${columnConfig.fixed ? 'default' : 'grab'}; font-size: 16px;">
                ${columnConfig.fixed ? '🔒' : '☰'}
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 500; color: ${isVisible ? '#111827' : '#6b7280'};">${columnConfig.label}</div>
                <div style="font-size: 11px; color: #9ca3af;">${column.id}</div>
            </div>
            <label style="display: flex; align-items: center; cursor: pointer; background: ${isVisible ? '#dbeafe' : '#f3f4f6'}; padding: 6px 12px; border-radius: 6px;">
                <input type="checkbox" ${column.visible ? 'checked' : ''} 
                       onchange="toggleColumnVisibility('${column.id}', this.checked)"
                       style="margin-right: 8px; cursor: pointer; width: 16px; height: 16px;">
                <span style="font-size: 12px; color: ${isVisible ? '#1e40af' : '#6b7280'}; font-weight: 500;">${isVisible ? 'Visible' : 'Hidden'}</span>
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
    
    return item;
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
    if (this !== draggedItem && this.classList.contains('column-item')) {
        // Only allow dropping within the same visibility group
        const draggedVisible = draggedItem.dataset.isVisible === 'true';
        const targetVisible = this.dataset.isVisible === 'true';
        
        if (draggedVisible === targetVisible) {
            const allItems = Array.from(this.parentNode.children).filter(el => el.classList.contains('column-item'));
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
}

function handleDragEnd(e) {
    this.style.opacity = '';
    draggedItem = null;
}

function reorderColumns() {
    const columnList = document.getElementById('columnList');
    const items = Array.from(columnList.children).filter(el => el.classList.contains('column-item'));
    
    const newOrder = items.map(item => {
        const columnId = item.dataset.columnId;
        const checkbox = item.querySelector('input[type="checkbox"]');
        return {
            id: columnId,
            visible: checkbox ? checkbox.checked : false
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
        
        // Re-render to move item between sections
        renderColumnSettings();
    }
}

function saveColumnSettings() {
    localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
    document.getElementById('columnSettingsModal').style.display = 'none';
    renderOrdersTable();
}

// Alias for backward compatibility with button onclick
function applyColumnSettings() {
    saveColumnSettings();
}

function resetColumnSettings() {
    localStorage.removeItem('orderTableColumns');
    currentColumns = [...defaultColumns];
    ensureActionsColumn();
    ensureAddressFields();
    localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
    renderColumnSettings();
}

// Alias for backward compatibility
function resetColumns() {
    resetColumnSettings();
}
// ⚠️ DEPRECATED: This function has been replaced by the modular renderTableHeader() and renderTableBody() system
// Keeping this as a wrapper for backward compatibility, but it now calls the newer modular functions
function renderOrdersTable() {
    // Call the newer, more maintainable modular system
    renderTableHeader();
    renderTableBody();
}
/* âš ï¸ DEPRECATED: This function has been replaced by the newer getCellContent() function below (around line 2227)
   This older version has been commented out to avoid conflicts and duplicated code
   The newer version has better error handling, debug logging, and more features
function getCellContent_DEPRECATED(order, columnId) {
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
                'pending': { bg: 'bg-yellow-50', border: 'border-yellow-200', text: 'text-yellow-700', icon: '' },
                'processing': { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-700', icon: '' },
                'completed': { bg: 'bg-green-50', border: 'border-green-200', text: 'text-green-700', icon: '' },
                'cancelled': { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-700', icon: '' },
                'refunded': { bg: 'bg-purple-50', border: 'border-purple-200', text: 'text-purple-700', icon: '' },
                'on-hold': { bg: 'bg-orange-50', border: 'border-orange-200', text: 'text-orange-700', icon: '' }
            };
            const config = statusConfig[status] || { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700', icon: '' };
            
            // Check if quick status change should be disabled (delivered orders with ledger)
            const hasLedgerForStatus2 = order.ledger_transaction_id && order.ledger_transaction_id > 0;
            const isDeliveredForStatus2 = order.order_status === 'delivered';
            const restrictStatusChange2 = hasLedgerForStatus2 && isDeliveredForStatus2;
            
            if (restrictStatusChange2) {
                // Show non-clickable badge with lock indicator
                return `<span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${config.bg} ${config.border} ${config.text} opacity-75" title="Status change restricted for delivered orders with ledger entry">
                            <span class=\"mr-1 text-xs\">${config.icon}</span>
                            ${status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())}
                            <span class=\"ml-1 text-xs\">🔒</span>
                        </span>`;
            }
            
            return `<button type="button" onclick="event.stopPropagation(); openQuickStatusChange(${order.id}, '${status}')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${config.bg} ${config.border} ${config.text} hover:opacity-80 transition" title="Quick change status">
                        <span class=\"mr-1 text-xs\">${config.icon}</span>
                        ${status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())}
                    </button>`;
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
            if (!customerName) {
                return '<span class="table-text-small">N/A</span>';
            }
            
            // Make customer name clickable if customer_id exists
            if (order.customer_id && order.customer_id !== 'N/A' && order.customer_id !== null) {
                return `<div class="table-text-primary"><span class="customer-name-link text-blue-600 hover:text-blue-800 hover:underline font-medium cursor-pointer" onclick="openCustomerDetails(${order.customer_id})" title="View customer details">${customerName}</span></div>`;
            } else {
                return `<div class="table-text-primary">${customerName}</div>`;
            }
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
            // Create compact multi-line address display
            const addressParts = [];
            if (order.address_line1) addressParts.push(order.address_line1);
            if (order.address_line2) addressParts.push(order.address_line2);
            if (order.address_city) addressParts.push(order.address_city);
            if (order.address_province) addressParts.push(order.address_province);
            
            if (addressParts.length === 0) return '<span class="table-text-small">N/A</span>';
            
            const fullAddress = addressParts.join(', ');
            const shortAddress = addressParts.length > 2 ? 
                `${addressParts[0]}, ${addressParts[addressParts.length - 1]}` : 
                addressParts.join(', ');
            
            return `<div class="table-cell-address-compact" title="${fullAddress}">
                        <div class="text-xs text-gray-700 leading-tight" style="max-width: 140px; overflow: hidden; text-overflow: ellipsis;">${shortAddress}</div>
                        ${addressParts.length > 2 ? '<div class="text-xs text-gray-400 mt-0.5" style="font-size: 10px;">+' + (addressParts.length - 2) + ' more</div>' : ''}
                    </div>`;
        case 'address2':
            const addr2 = order.address_line2 || '';
            return addr2 ? `<div class="table-cell-address" title="${addr2}">${addr2}</div>` : '';
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
            return `<div class="table-cell-total">PKR ${totalPrice}</div>`;
        case 'total_weight':
            return order.total_weight || '0';
            
        // Payment & Other Info
        case 'payment_method':
            const paymentMethod = order.payment_method || 'cash';
            const normalizedMethod = paymentMethod.toLowerCase().trim();
            
            // Determine display text and styling
            let displayText = 'Cash';
            let pmConfig = { bg: 'bg-green-50', border: 'border-green-300', text: 'text-green-800' };
            
            if (normalizedMethod.includes('online') || normalizedMethod.includes('bank') || normalizedMethod.includes('card')) {
                displayText = 'Online';
                pmConfig = { bg: 'bg-purple-50', border: 'border-purple-300', text: 'text-purple-800' };
            } else if (normalizedMethod.includes('cash') || normalizedMethod.includes('cod')) {
                displayText = 'Cash';
                pmConfig = { bg: 'bg-green-50', border: 'border-green-300', text: 'text-green-800' };
            }
            
            // Check if quick payment method change should be disabled (delivered orders with ledger)
            const hasLedgerForPM = order.ledger_transaction_id && order.ledger_transaction_id > 0;
            const isDeliveredForPM = order.order_status === 'delivered';
            const restrictPMChange = hasLedgerForPM && isDeliveredForPM;
            
            if (restrictPMChange) {
                // Show non-clickable badge with lock indicator
                return `<div class="order-payment-method-cell"><span class="inline-flex items-center px-2 py-1 rounded ${pmConfig.bg} ${pmConfig.border} border ${pmConfig.text} text-xs font-medium opacity-75" title="Payment method change restricted for delivered orders with ledger entry">${displayText} <span class="ml-1">🔒</span></span></div>`;
            }
            
            return `<div class="order-payment-method-cell"><button type="button" onclick="event.stopPropagation(); openQuickPaymentMethodChange(${order.id}, '${paymentMethod}')" class="inline-flex items-center px-2 py-1 rounded ${pmConfig.bg} ${pmConfig.border} border ${pmConfig.text} text-xs font-medium hover:opacity-80 cursor-pointer transition" title="Click to change payment method">${displayText}</button></div>`;
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
// ⚠️ DUPLICATE INITIALIZATION REMOVED - Already exists earlier in file (around line 4687-4715)

// Migration: Ensure rider columns exist for existing users
if (!currentColumns.find(col => col.id === 'rider')) {
    currentColumns.push({ id: 'rider', visible: false });
}
if (!currentColumns.find(col => col.id === 'rider_id')) {
    currentColumns.push({ id: 'rider_id', visible: false });
}

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

// ⚠️ DUPLICATE COLUMN SETTINGS FUNCTIONS REMOVED
// The proper implementation with visible/hidden sections exists earlier in the file (around line 4803)

function renderOrdersTable() {
    renderTableHeader();
    renderTableBody();
}
function renderTableHeader() {
    const header = document.getElementById('table-header');
    const colgroup = document.getElementById('table-colgroup');
    header.innerHTML = '';
    if (colgroup) colgroup.innerHTML = '';
    
    // Add checkbox column first (only for non-Shopify orders)
    const isShopifyView = window.location.search.includes('source=shopify');
    if (!isShopifyView) {
        const checkboxTh = document.createElement('th');
        checkboxTh.className = 'px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-12';
        checkboxTh.innerHTML = '<input type="checkbox" id="selectAllOrders" onchange="toggleAllOrdersSelection()" class="rounded" title="Select all on page">';
        header.appendChild(checkboxTh);
        
        if (colgroup) {
            const checkboxCol = document.createElement('col');
            checkboxCol.style.width = '48px';
            colgroup.appendChild(checkboxCol);
        }
    }
    
    currentColumns.forEach(column => {
        if (column.visible) {
            const columnConfig = availableColumns[column.id];
            if (columnConfig) {
                const th = document.createElement('th');
                th.className = `px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider ${columnConfig.width}`;
                th.textContent = columnConfig.label;
                header.appendChild(th);

                // Also render a matching <col> for consistent widths even with custom columns
                if (colgroup) {
                    const col = document.createElement('col');
                    // Translate Tailwind width shorthands to pixel values for reliability
                    const tw = String(columnConfig.width || '').trim();
                    const map = {
                        'w-16': 64,
                        'w-20': 80,
                        'w-24': 96,
                        'w-28': 112,
                        'w-32': 128,
                        'w-36': 144,
                        'w-40': 160,
                        'w-44': 176,
                        'w-48': 192,
                        'w-56': 224,
                        'w-64': 256
                    };
                    let px = map[tw] || null;
                    // Handle arbitrary like w-[150px]
                    const match = tw.match(/w-\[(\d+)px\]/);
                    if (!px && match) px = parseInt(match[1], 10);
                    // Shrink address1 a bit to protect actions visibility
                    if ((column.id === 'address1' || column.id === 'address_line_1') && px && px > 200) {
                        px = 200;
                    }
                    if (px) col.style.width = px + 'px';
                    colgroup.appendChild(col);
                }
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
            row.className = 'hover:bg-gray-50 transition-colors duration-150 cursor-pointer';
            row.setAttribute('data-order-id', order.id); // ⭐ SMART SYNC: For row updates
            
            // Make entire row clickable to open view order details
            row.onclick = function(e) {
                // Don't trigger row click if user clicked on action buttons, customer name, or checkboxes
                if (e.target.closest('.sticky-actions') || e.target.closest('.customer-name-link') || e.target.closest('input[type="checkbox"]')) {
                    return;
                }
                viewOrderDetails(order.id);
            };
            
            // Add checkbox column first (only for non-Shopify orders)
            const isShopifyView = window.location.search.includes('source=shopify');
            if (!isShopifyView) {
                const checkboxTd = document.createElement('td');
                checkboxTd.className = 'px-3 py-4 whitespace-nowrap text-sm';
                if (order.external_source === 'shopify') {
                    checkboxTd.innerHTML = ''; // No checkbox for Shopify orders
                } else {
                    checkboxTd.innerHTML = `<input type=\"checkbox\" class=\"order-checkbox rounded\" id=\"order_${order.id}\" value=\"${order.id}\" onchange=\"toggleOrderSelection(${order.id})\" onclick=\"event.stopPropagation()\">`;
                }
                row.appendChild(checkboxTd);
            }
            
            currentColumns.forEach(column => {
                if (column.visible) {
                    try {
                        const td = document.createElement('td');
                        // Make actions sticky on the right
                        td.className = 'px-6 py-4 whitespace-nowrap text-sm';
                        if (column.id === 'actions') {
                            td.className += ' sticky-actions';
                        }
                        // ⭐ SMART SYNC: Mark rider cell for updates
                        if (column.id === 'rider') {
                            td.className += ' order-rider-cell';
                        }
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
            return `<div class="table-cell-id">${order.id}</div>`;
        case 'order_number':
            return `<div class="table-cell-order-number">${order.order_number || ''}</div>`;
        case 'order_date':
            return `<div class="table-cell-date">${formatDate(order.order_date)}</div>`;
        case 'order_status':
            const status = order.order_status || 'pending';
            const statusConfig = {
                'pending': { bg: 'bg-yellow-50', border: 'border-yellow-200', text: 'text-yellow-700', icon: '' },
                'processing': { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-700', icon: '' },
                'completed': { bg: 'bg-green-50', border: 'border-green-200', text: 'text-green-700', icon: '' },
                'cancelled': { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-700', icon: '' },
                'refunded': { bg: 'bg-purple-50', border: 'border-purple-200', text: 'text-purple-700', icon: '' },
                'on-hold': { bg: 'bg-orange-50', border: 'border-orange-200', text: 'text-orange-700', icon: '' },
                'new': { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700', icon: '' },
                'out_for_delivery': { bg: 'bg-indigo-50', border: 'border-indigo-200', text: 'text-indigo-700', icon: '' },
                'delivered': { bg: 'bg-green-50', border: 'border-green-200', text: 'text-green-700', icon: '' }
            };
            const config = statusConfig[status] || { bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700', icon: '' };
            
            // Check if quick status change should be disabled (delivered orders with ledger)
            const hasLedgerForStatus = order.ledger_transaction_id && order.ledger_transaction_id > 0;
            const isDeliveredForStatus = order.order_status === 'delivered';
            const restrictStatusChange = hasLedgerForStatus && isDeliveredForStatus;
            
            if (restrictStatusChange) {
                // Show non-clickable badge with lock indicator
                return `<span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${config.bg} ${config.border} ${config.text} opacity-75" title="Status change restricted for delivered orders with ledger entry">
                            <span class="mr-1 text-xs">${config.icon}</span>
                            ${status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())}
                            <span class="ml-1 text-xs">🔒</span>
                        </span>`;
            }
            
            return `<button type="button" onclick="event.stopPropagation(); openQuickStatusChange(${order.id}, '${status}')" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium border ${config.bg} ${config.border} ${config.text} hover:opacity-80 transition cursor-pointer" title="Click to change status">
                        <span class="mr-1 text-xs">${config.icon}</span>
                        ${status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())}
                    </button>`;
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
            if (!customerName) {
                return '<span class="table-text-small">N/A</span>';
            }
            
            // Make customer name clickable if customer_id exists
            if (order.customer_id && order.customer_id !== 'N/A' && order.customer_id !== null) {
                return `<div class="table-text-primary"><span class="customer-name-link text-blue-600 hover:text-blue-800 hover:underline font-medium cursor-pointer" onclick="openCustomerDetails(${order.customer_id})" title="View customer details">${customerName}</span></div>`;
            } else {
                return `<div class="table-text-primary">${customerName}</div>`;
            }
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
            // Create compact multi-line address display
            const addressParts = [];
            if (order.address_line1) addressParts.push(order.address_line1);
            if (order.address_line2) addressParts.push(order.address_line2);
            if (order.address_city) addressParts.push(order.address_city);
            if (order.address_province) addressParts.push(order.address_province);
            
            if (addressParts.length === 0) return '<span class="table-text-small">N/A</span>';
            
            const fullAddress = addressParts.join(', ');
            const shortAddress = addressParts.length > 2 ? 
                `${addressParts[0]}, ${addressParts[addressParts.length - 1]}` : 
                addressParts.join(', ');
            
            return `<div class="table-cell-address-compact" title="${fullAddress}">
                        <div class="text-xs text-gray-700 leading-tight" style="max-width: 140px; overflow: hidden; text-overflow: ellipsis;">${shortAddress}</div>
                        ${addressParts.length > 2 ? '<div class="text-xs text-gray-400 mt-0.5" style="font-size: 10px;">+' + (addressParts.length - 2) + ' more</div>' : ''}
                    </div>`;
        case 'address2':
            const addr2 = order.address_line2 || '';
            return addr2 ? `<div class="table-cell-address" title="${addr2}">${addr2}</div>` : '';
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
            return `<div class="table-cell-total">PKR ${totalPrice}</div>`;
        case 'total_weight':
            return order.total_weight || '0';
            
        // Payment & Other Info
        case 'payment_method':
            const paymentMethod2 = order.payment_method || 'cash';
            const normalizedMethod2 = paymentMethod2.toLowerCase().trim();
            
            // Determine display text and styling
            let displayText2 = 'Cash';
            let pmConfig2 = { bg: 'bg-green-50', border: 'border-green-300', text: 'text-green-800' };
            
            if (normalizedMethod2.includes('online') || normalizedMethod2.includes('bank') || normalizedMethod2.includes('card')) {
                displayText2 = 'Online';
                pmConfig2 = { bg: 'bg-purple-50', border: 'border-purple-300', text: 'text-purple-800' };
            } else if (normalizedMethod2.includes('cash') || normalizedMethod2.includes('cod')) {
                displayText2 = 'Cash';
                pmConfig2 = { bg: 'bg-green-50', border: 'border-green-300', text: 'text-green-800' };
            }
            
            // Check if quick payment method change should be disabled (delivered orders with ledger)
            const hasLedgerForPM2 = order.ledger_transaction_id && order.ledger_transaction_id > 0;
            const isDeliveredForPM2 = order.order_status === 'delivered';
            const restrictPMChange2 = hasLedgerForPM2 && isDeliveredForPM2;
            
            if (restrictPMChange2) {
                // Show non-clickable badge with lock indicator
                return `<div class="order-payment-method-cell"><span class="inline-flex items-center px-2 py-1 rounded ${pmConfig2.bg} ${pmConfig2.border} border ${pmConfig2.text} text-xs font-medium opacity-75" title="Payment method change restricted for delivered orders with ledger entry">${displayText2} <span class="ml-1">🔒</span></span></div>`;
            }
            
            return `<div class="order-payment-method-cell"><button type="button" onclick="event.stopPropagation(); openQuickPaymentMethodChange(${order.id}, '${paymentMethod2}')" class="inline-flex items-center px-2 py-1 rounded ${pmConfig2.bg} ${pmConfig2.border} border ${pmConfig2.text} text-xs font-medium hover:opacity-80 cursor-pointer transition" title="Click to change payment method">${displayText2}</button></div>`;
        case 'coupon_code':
            return order.coupon_code || '';
        case 'note':
            const note = order.note || '';
            return `<div class="table-cell-note" title="${note}">${note}</div>`;
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
            
        // Rider columns
        case 'rider_id':
            const riderId = order.assigned_rider_user_id;
            if (riderId == null || riderId === undefined) {
                return '<span class="text-gray-400 text-xs">-</span>';
            }
            return `<span class="text-gray-900 font-mono text-xs font-semibold">${riderId}</span>`;
        case 'rider':
            const rid = order.assigned_rider_user_id || order.rider_user_id || null;
            const rname = order.rider_name || (order.assigned_rider && (order.assigned_rider.fullname || order.assigned_rider.name)) || null;
            
            // Check if quick rider assignment should be disabled (delivered orders with ledger)
            const hasLedgerForRider = order.ledger_transaction_id && order.ledger_transaction_id > 0;
            const isDeliveredForRider = order.order_status === 'delivered';
            const restrictRiderChange = hasLedgerForRider && isDeliveredForRider;
            
            if (restrictRiderChange) {
                // Show non-clickable badge with lock indicator
                if (!rname && rid) {
                    return `<span class="inline-flex items-center px-2 py-1 rounded bg-amber-50 text-amber-800 border border-amber-300 text-xs font-medium opacity-75" title="Rider assignment restricted for delivered orders with ledger entry">User #${rid} <span class="ml-1">🔒</span></span>`;
                }
                if (!rname && !rid) {
                    return `<span class="inline-flex items-center px-2 py-1 rounded bg-gray-100 text-gray-700 text-xs opacity-75" title="Rider assignment restricted for delivered orders with ledger entry">Unassigned <span class="ml-1">🔒</span></span>`;
                }
                return `<span class="inline-flex items-center px-2 py-1 rounded bg-blue-50 text-blue-800 border border-blue-300 text-xs font-medium opacity-75" title="Rider assignment restricted for delivered orders with ledger entry">${String(rname)} <span class="ml-1">🔒</span></span>`;
            }
            
            // GUARANTEED FALLBACK: If we have an ID but no name, show "User #ID" in amber (clickable)
            if (!rname && rid) {
                return `<button type="button" onclick="event.stopPropagation(); openQuickRiderAssign(${order.id}, ${rid}, 'User #${rid}')" class="inline-flex items-center px-2 py-1 rounded bg-amber-50 text-amber-800 border border-amber-300 text-xs font-medium hover:bg-amber-100 cursor-pointer transition" title="Click to assign rider">User #${rid}</button>`;
            }
            
            // No rider assigned at all (clickable)
            if (!rname && !rid) {
                return `<button type="button" onclick="event.stopPropagation(); openQuickRiderAssign(${order.id}, null, 'Unassigned')" class="inline-flex items-center px-2 py-1 rounded bg-gray-100 text-gray-700 text-xs hover:bg-gray-200 cursor-pointer transition" title="Click to assign rider">Unassigned</button>`;
            }
            
            // We have a name - show it in blue (clickable)
            return `<button type="button" onclick="event.stopPropagation(); openQuickRiderAssign(${order.id}, ${rid}, '${String(rname).replace(/'/g, "\\'")}')" class="inline-flex items-center px-2 py-1 rounded bg-blue-50 text-blue-800 border border-blue-300 text-xs font-medium hover:bg-blue-100 cursor-pointer transition" title="Click to change rider">${String(rname)}</button>`;
        // Actions
        case 'actions':
            // Check if this is a Shopify order (any Shopify order, not just approvals)
            const isShopifyOrder = order.external_source === 'shopify';
            
            if (isShopifyOrder) {
                // For Shopify orders, check if already converted/approved
                const isConverted = order.converted && order.converted !== 0 && order.converted !== 3;
                const isIgnored = order.converted === 2;
                
                if (isConverted || isIgnored) {
                    // Already processed - show only view details
                    return `
                        <div class="flex items-center justify-center gap-1.5">
                            <button onclick="viewOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 hover:border-blue-300 hover:shadow-sm transition-all duration-200 group" title="View Order Details">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>`;
                } else {
                    // Pending approval - show approve/ignore/view
                return `
                    <div class="flex items-center justify-center gap-1.5">
                        <button onclick="convertOrder(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-emerald-600 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 hover:border-emerald-300 hover:shadow-sm transition-all duration-200 group" title="Approve (Convert)">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </button>
                        <button onclick="ignoreOrder(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-rose-600 bg-rose-50 border border-rose-200 rounded-lg hover:bg-rose-100 hover:border-rose-300 hover:shadow-sm transition-all duration-200 group" title="Ignore">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                            <button onclick="viewOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 hover:border-blue-300 hover:shadow-sm transition-all duration-200 group" title="View Order Details">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>`;
            }
            }
            
            // Default full actions for non-Shopify orders (webapp, manual, etc.)
            // Check if order is delivered with ledger entry (restrict quick edit)
            const hasLedger = order.ledger_transaction_id && order.ledger_transaction_id > 0;
            const isDelivered = order.order_status === 'delivered';
            const restrictEdit = hasLedger && isDelivered;
            
            // Build edit button - disabled if delivered with ledger
            let editButton = '';
            if (restrictEdit) {
                // Show disabled edit button with lock icon and tooltip
                editButton = `<button disabled class="inline-flex items-center justify-center w-8 h-8 text-gray-400 bg-gray-50 border border-gray-200 rounded-lg cursor-not-allowed opacity-60" title="Quick edit disabled for delivered orders. Use full edit modal from view details.">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </button>`;
            } else {
                // Show normal edit button
                editButton = `<button onclick="editOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-amber-600 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 hover:border-amber-300 hover:shadow-sm transition-all duration-200 group" title="Edit Order">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>`;
            }
            
            return `
                <div class="flex items-center justify-center gap-1.5">
                    <button onclick="viewOrderDetails(${order.id})" class="inline-flex items-center justify-center w-8 h-8 text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 hover:border-blue-300 hover:shadow-sm transition-all duration-200 group" title="View Order Details">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </button>
                    ${editButton}
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
    
    // Initialize status filter with current data
    initStatusFilter();
    
    // Initialize status cards if on open orders tab
    const currentTab = new URLSearchParams(window.location.search).get('tab');
    const currentSource = new URLSearchParams(window.location.search).get('source');
    
    // Load status cards for open orders tab
    const statusCardsSection = document.getElementById('openOrdersStatusCards');
    if (statusCardsSection) {
        const isVisible = statusCardsSection.style.display !== 'none' && 
                         statusCardsSection.offsetParent !== null;
        
        if ((currentSource === 'other' && currentTab === 'open') || isVisible) {
            console.log('Initializing status cards on page load...'); // Debug log
            setTimeout(() => {
                loadOpenOrdersStatusCards();
            }, 200);
        }
    }
    
    // Load rider cards for riders tab
    const ridersCardsSection = document.getElementById('ridersCards');
    if (ridersCardsSection) {
        const isVisible = ridersCardsSection.style.display !== 'none' && 
                         ridersCardsSection.offsetParent !== null;
        
        if ((currentSource === 'other' && currentTab === 'riders') || isVisible) {
            console.log('Initializing rider cards on page load...'); // Debug log
            setTimeout(() => {
                loadRiderCards();
            }, 200);
        }
    }
    
    // Set up search with debouncing
    const searchInput = document.getElementById('orderSearch');
    const statusFilter = document.getElementById('statusFilter');
    const dateFilter = document.getElementById('dateFilter');
    const deliveryDateFilter = document.getElementById('deliveryDateFilter');
    
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
    
    // Filter functionality - reload page with filter parameters to preserve pagination
    statusFilter.addEventListener('change', function() {
        applyFiltersToUrl();
    });
    
    dateFilter.addEventListener('change', function() { applyFiltersToUrl(); });
    if (deliveryDateFilter) deliveryDateFilter.addEventListener('change', function() { applyFiltersToUrl(); });
    
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

    // Auto-open Import modal when requested via query param
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get('open') === 'import') {
            if (typeof openImportModal === 'function') {
                setTimeout(() => openImportModal(), 200);
            }
        }
    } catch (e) {
        console.warn('Failed to process open=import param', e);
    }
// Apply filters to URL and reload page (for status and date filters)
// This preserves proper server-side pagination
function applyFiltersToUrl() {
    const statusFilter = document.getElementById('statusFilter').value;
    const dateFilter = document.getElementById('dateFilter').value;
    const deliveryDateFilter = document.getElementById('deliveryDateFilter') ? document.getElementById('deliveryDateFilter').value : '';
    
    const currentUrl = new URL(window.location);
    
    // Update filter parameters
    if (statusFilter) {
        currentUrl.searchParams.set('status', statusFilter);
    } else {
        currentUrl.searchParams.delete('status');
    }
    
    if (dateFilter) currentUrl.searchParams.set('date', dateFilter); else currentUrl.searchParams.delete('date');
    if (deliveryDateFilter) currentUrl.searchParams.set('delivery_date', deliveryDateFilter); else currentUrl.searchParams.delete('delivery_date');
    
    // Reset to page 1 when filters change
    currentUrl.searchParams.set('page', '1');
    
    // Reload page with new parameters
    window.location.href = currentUrl.toString();
}

// Fetch filtered orders from backend (for search only - returns up to 100 results)
function fetchFilteredOrders() {
    const searchTerm = document.getElementById('orderSearch').value.trim();
    const statusFilter = document.getElementById('statusFilter').value;
    const dateFilter = document.getElementById('dateFilter').value;
    const deliveryDateFilter = document.getElementById('deliveryDateFilter') ? document.getElementById('deliveryDateFilter').value : '';
    
    // Show loading state
    showLoadingState();
    
    // Build query parameters
    const params = new URLSearchParams();
    if (searchTerm) params.append('search', searchTerm);
    if (statusFilter) params.append('status', statusFilter);
    if (dateFilter) params.append('date', dateFilter);
    if (deliveryDateFilter) params.append('delivery_date', deliveryDateFilter);
    
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
    
    // Update pagination info element (for search results only)
    const paginationInfo = document.getElementById('pagination-info');
    if (paginationInfo) {
        paginationInfo.textContent = `Showing ${filteredCount} search results`;
    }
    
    // Hide pagination controls when showing search results (limited to 100)
    const pagerWrap = document.getElementById('pager-wrap');
    const numericPager = document.getElementById('numeric-pager');
    
    // Only hide pagination for search results (not for status/date filters)
    const searchTerm = document.getElementById('orderSearch').value.trim();
    
    if (searchTerm.length > 2 && pagerWrap) {
        // Hide pagination when showing search results (not paginated, max 100)
        pagerWrap.style.display = 'none';
        if (numericPager) numericPager.style.display = 'none';
    } else if (pagerWrap) {
        // Show pagination for normal view and filtered views
        pagerWrap.style.display = '';
        if (numericPager) numericPager.style.display = '';
    }
}

// Render table with filtered data
function renderOrdersWithFilters(data) {
    // Update the global ordersData
    window.ordersData = data;
    
    if (data.length === 0) {
        showEmptyState();
        // Also clear the table body to ensure nothing shows
        const tbody = document.getElementById('table-body');
        if (tbody) tbody.innerHTML = '';
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
    
    // Remove filter parameters from URL and reset to page 1
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.delete('search');
    currentUrl.searchParams.delete('status');
    currentUrl.searchParams.delete('date');
    currentUrl.searchParams.set('page', '1');
    
    // Navigate to the clean URL to reload with default data
    window.location.href = currentUrl.toString();
}
// Loading state functions
function showLoadingState() {
    const tbody = document.getElementById('table-body');
    const noResults = document.getElementById('no-results-state');
    const loading = document.getElementById('loading-state');
    if (tbody) tbody.style.display = 'none';
    if (noResults) noResults.classList.add('hidden');
    if (loading) loading.classList.remove('hidden');
}

function hideLoadingState() {
    const loading = document.getElementById('loading-state');
    const tbody = document.getElementById('table-body');
    if (loading) loading.classList.add('hidden');
    if (tbody) tbody.style.display = '';
}

function showEmptyState() {
    const tbody = document.getElementById('table-body');
    const loading = document.getElementById('loading-state');
    const noResults = document.getElementById('no-results-state');
    if (tbody) tbody.style.display = 'none';
    if (loading) loading.classList.add('hidden');
    if (noResults) noResults.classList.remove('hidden');
}
function hideEmptyState() {
    const noResults = document.getElementById('no-results-state');
    const tbody = document.getElementById('table-body');
    if (noResults) noResults.classList.add('hidden');
    if (tbody) tbody.style.display = '';
}
// ==================== END SEARCH AND FILTER ====================
// Create new order functionality
function createNewOrder() {
    // Reset and open the edit modal for creating new order
    const modal = document.getElementById('editOrderModal');
    const content = document.getElementById('editOrderContent');
    
    // Set up form for new order
    content.innerHTML = `
        <form id="editOrderForm" onsubmit="event.preventDefault(); saveOrderChanges(null);">
            <!-- Customer Section -->
            <div style="background-color: #fefefe; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0;">Customer Information</h4>
                </div>
                <div style="padding: 16px;">
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Customer Selection</label>
                        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                            <button type="button" id="existingCustomerBtn" onclick="selectCustomerMode('existing')" style="padding: 6px 12px; border: 1px solid #10b981; background-color: #10b981; color: white; border-radius: 4px; font-size: 12px; cursor: pointer;">Existing Customer</button>
                            <button type="button" id="newCustomerBtn" onclick="selectCustomerMode('new')" style="padding: 6px 12px; border: 1px solid #d1d5db; background-color: #f9fafb; color: #374151; border-radius: 4px; font-size: 12px; cursor: pointer;">New Customer</button>
                        </div>
                        
                        <!-- Existing Customer Search -->
                        <div id="existingCustomerSection" style="display: block;">
                            <div style="position: relative;">
                                <input type="text" id="customerSearch" placeholder="Search customers by name, phone, or email..." 
                                       style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;"
                                       onkeyup="searchCustomers(this)" onfocus="showCustomerDropdown()">
                                <div id="customerDropdown" class="customer-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></div>
                                <input type="hidden" name="customer_id" id="selectedCustomerId">
                            </div>
                        </div>
                        
                        <!-- New Customer Fields -->
                        <div id="newCustomerSection" style="display: none;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">First Name *</label>
                                    <input type="text" name="customer_first_name" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Last Name *</label>
                                    <input type="text" name="customer_last_name" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Phone Number *</label>
                                <input type="text" name="customer_phone" placeholder="+92345000681 or 03455000681" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Address Line 1 *</label>
                                    <input type="text" name="customer_address1" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Address Line 2</label>
                                    <input type="text" name="customer_address2" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">City</label>
                                    <input type="text" name="customer_city" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Country</label>
                                    <input type="text" name="customer_country" value="Pakistan" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
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
                            <select name="order_status" id="createOrderStatus" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <option value="">Loading statuses...</option>
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
                                <option value="cash" selected>Cash</option>
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
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 8px;">Discounts</label>
                            <div id="discountsContainer" style="display: flex; flex-direction: column; gap: 8px;">
                                <!-- Discount rows will be populated here -->
                            </div>
                            <button type="button" onclick="addDiscountRow()" 
                                    style="margin-top: 8px; padding: 6px 12px; background: #10b981; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 500;">
                                + Add Discount
                            </button>
                            <div style="margin-top: 8px; padding: 8px; background: #f3f4f6; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 600; color: #374151; font-size: 12px;">Total Discount:</span>
                                <span id="totalDiscountDisplay" style="font-weight: 700; color: #ef4444; font-size: 14px;">Rs. 0.00</span>
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
        saveOrderChanges(null); // Use unified function with validation
    };
    
    modal.style.display = 'block';
    
    // Initialize one empty discount row for new orders
    setTimeout(() => {
        const container = document.getElementById('discountsContainer');
        if (container) {
            container.innerHTML = '';
            discountRowIndex = 0;
            addDiscountRow(); // Add one empty discount row
        }
    }, 50);
    
    // Update modal header for create order with pop-out functionality after modal is shown
    setTimeout(() => {
        updateCreateOrderModalHeader();
    }, 10);
    // Load master statuses for create dropdown
    (async function(){
        try {
            const resp = await fetch('/order-status/api/statuses', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                credentials: 'same-origin'
            });
            const data = await resp.json();
            const sel = document.getElementById('createOrderStatus');
            if (data && data.success && sel) {
                sel.innerHTML = data.data.map(s => `<option value="${s.status_code}">${s.icon} ${s.status_name}</option>`).join('');
                if (data.data.find(s => s.status_code === 'new')) sel.value = 'new';
            }
        } catch (e) {
            const sel = document.getElementById('createOrderStatus');
            if (sel) sel.innerHTML = `
                <option value="new" selected>â³ New</option>
                <option value="processing">â¡ Processing</option>
                <option value="out_for_delivery">ð Out for Delivery</option>
                <option value="delivered">â
 Delivered</option>
                <option value="on_hold">â¸ On Hold</option>
                <option value="cancelled">â Cancelled</option>
                <option value="refunded">âª Refunded</option>`;
        }
    })();
}

// DUPLICATE FUNCTION REMOVED - Original exists at line 1402

// Save new order (with validation)
function saveNewOrder() {
    const form = document.getElementById('editOrderForm');
    const formData = new FormData(form);
    
    // VALIDATE CUSTOMER INFORMATION FIRST
    const existingSection = document.getElementById('existingCustomerSection');
    const newSection = document.getElementById('newCustomerSection');
    
    // Check which mode is active
    const isExistingMode = existingSection && (existingSection.style.display === 'block' || existingSection.style.display === '');
    const isNewMode = newSection && (newSection.style.display === 'block' || newSection.style.display === '');
    
    console.log('Validation check in saveNewOrder:', { isExistingMode, isNewMode });
    
    if (isExistingMode) {
        // Existing customer mode - must have selected a customer
        const customerId = document.getElementById('selectedCustomerId')?.value;
        if (!customerId || customerId === '') {
            alert('Please select an existing customer or switch to "New Customer" mode to create a new one.');
            return;
        }
    } else if (isNewMode) {
        // New customer mode - validate required fields
        const firstName = form.querySelector('input[name="customer_first_name"]')?.value?.trim();
        const lastName = form.querySelector('input[name="customer_last_name"]')?.value?.trim();
        const phone = form.querySelector('input[name="customer_phone"]')?.value?.trim();
        const address1 = form.querySelector('input[name="customer_address1"]')?.value?.trim();
        
        if (!firstName) {
            alert('First Name is required for new customer');
            form.querySelector('input[name="customer_first_name"]')?.focus();
            return;
        }
        if (!lastName) {
            alert('Last Name is required for new customer');
            form.querySelector('input[name="customer_last_name"]')?.focus();
            return;
        }
        if (!phone) {
            alert('Phone Number is required for new customer');
            form.querySelector('input[name="customer_phone"]')?.focus();
            return;
        }
        if (!address1) {
            alert('Address Line 1 is required for new customer');
            form.querySelector('input[name="customer_address1"]')?.focus();
            return;
        }
    } else {
        // Neither mode is visible
        alert('Please select customer information');
        return;
    }
    
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
    
    // Collect discounts
    const discounts = [];
    document.querySelectorAll('.discount-row').forEach((row) => {
        const title = row.querySelector('[name$="[title]"]')?.value;
        const amount = parseFloat(row.querySelector('[name$="[amount]"]')?.value) || 0;
        
        if (title && amount > 0) {
            discounts.push({
                title: title,
                amount: amount,
                type: 'fixed'
            });
        }
    });
    
    // Prepare data
    const orderData = {
        customer_id: formData.get('customer_id'),
        order_status: formData.get('order_status'),
        order_date: formData.get('order_date') ? formData.get('order_date').replace('T', ' ') + ':00' : getCurrentLocalDateTime().replace('T', ' ') + ':00',
        contact_email: formData.get('contact_email'),
        subtotal_price: parseFloat(formData.get('subtotal_price')) || 0,
        shipping_total: parseFloat(formData.get('shipping_total')) || 0,
        total_price: parseFloat(formData.get('total_price')) || 0,
        payment_method: formData.get('payment_method'),
        note: formData.get('note'),
        items: items,
        discounts: discounts, // NEW: Include discounts array
        // Customer creation fields
        customer_phone: formData.get('customer_phone'),
        customer_first_name: formData.get('customer_first_name'),
        customer_last_name: formData.get('customer_last_name'),
        customer_address1: formData.get('customer_address1'),
        customer_address2: formData.get('customer_address2'),
        customer_city: formData.get('customer_city'),
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
    const detailsDiv = document.getElementById('selectedCustomerDetails');

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
        
        // Focus on search input when switching to existing customer mode
        setTimeout(() => {
            const searchInput = document.getElementById('customerSearch');
            if (searchInput) searchInput.focus();
        }, 100);
    } else {
        existingSection.style.display = 'none';
        newSection.style.display = '';
        newBtn.style.backgroundColor = '#10b981';
        newBtn.style.color = '#ffffff';
        newBtn.style.borderColor = '#10b981';
        existingBtn.style.backgroundColor = '#f9fafb';
        existingBtn.style.color = '#374151';
        existingBtn.style.borderColor = '#d1d5db';
        
        // Hide customer details when switching to new customer mode
        if (detailsDiv) detailsDiv.style.display = 'none';
        
        // Clear search input and selected customer
        const searchInput = document.getElementById('customerSearch');
        const hiddenId = document.getElementById('selectedCustomerId');
        if (searchInput) searchInput.value = '';
        if (hiddenId) hiddenId.value = '';
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

// Customer selector for edit mode
function showCustomerSelector() {
    const selector = document.getElementById('editCustomerSelector');
    if (selector) {
        selector.style.display = 'block';
        const searchInput = document.getElementById('editCustomerSearch');
        if (searchInput) searchInput.focus();
    }
}

function hideCustomerSelector() {
    const selector = document.getElementById('editCustomerSelector');
    if (selector) selector.style.display = 'none';
    const dd = document.getElementById('editCustomerDropdown');
    if (dd) dd.style.display = 'none';
}

function searchCustomersForEdit(inputEl) {
    const query = (inputEl && inputEl.value) ? inputEl.value.trim() : '';
    clearTimeout(customerSearchTimeout);
    if (!query) { hideEditCustomerDropdown(); return; }

    customerSearchTimeout = setTimeout(function() {
        fetch('/api/customers/search?q=' + encodeURIComponent(query) + '&limit=10', {
            headers: { 'Accept': 'application/json' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            const customers = (data && data.success && data.customers) ? data.customers : [];
            showEditCustomerResults(customers);
        })
        .catch(function() {});
    }, 250);
}

function showEditCustomerResults(customers) {
    const dd = document.getElementById('editCustomerDropdown');
    if (!dd) return;
    if (!customers || customers.length === 0) { 
        dd.innerHTML = '<div style="padding:8px;color:#6b7280;font-size:12px;">No customers found</div>'; 
        showEditCustomerDropdown(); 
        return; 
    }

    // Store customers globally for access
    window.editCustomerResults = customers;

    let html = '';
    customers.forEach((c, idx) => {
        const addressParts = [];
        if (c.address && c.address.address1) addressParts.push(c.address.address1);
        if (c.address && c.address.city) addressParts.push(c.address.city);
        const addressStr = addressParts.length > 0 ? addressParts.join(', ') : 'No address';
        
        const safeName = escapeHtml(c.name || '');
        const safePhone = escapeHtml(c.phone || 'No phone');
        const safeAddress = escapeHtml(addressStr);
        
        html += `
            <div onclick="selectEditCustomer(${idx})" 
                 style="padding:10px; cursor:pointer; border-bottom:1px solid #f3f4f6; font-size:13px;">
                <div style="font-weight:500; color:#111827;">${safeName}</div>
                <div style="font-size:11px; color:#6b7280; margin-top:2px;">${safePhone} • ${safeAddress}</div>
            </div>
        `;
    });
    
    dd.innerHTML = html;
    showEditCustomerDropdown();
}

function showEditCustomerDropdown() {
    const dd = document.getElementById('editCustomerDropdown');
    if (dd) dd.style.display = 'block';
}

function hideEditCustomerDropdown() {
    const dd = document.getElementById('editCustomerDropdown');
    if (dd) dd.style.display = 'none';
}

function selectEditCustomer(customerIndex) {
    try {
        // Get customer from global storage
        if (!window.editCustomerResults || !window.editCustomerResults[customerIndex]) {
            console.error('Customer not found at index:', customerIndex);
            alert('Error: Customer data not found');
            return;
        }
        
        const customer = window.editCustomerResults[customerIndex];
        console.log('Selected customer:', customer);
        
        // ✅ CRITICAL: Update customer_id hidden field
        const customerIdField = document.getElementById('editCustomerId');
        if (customerIdField) {
            customerIdField.value = customer.id || '';
            console.log('Set customer_id to:', customer.id);
        }
        
        // Update all customer fields
        if (customer.address) {
            const firstNameField = document.getElementById('editAddressFirstName');
            const lastNameField = document.getElementById('editAddressLastName');
            const emailField = document.getElementById('editAddressEmail');
            const phoneField = document.getElementById('editAddressPhone');
            const line1Field = document.getElementById('editAddressLine1');
            const line2Field = document.getElementById('editAddressLine2');
            const cityField = document.getElementById('editAddressCity');
            const countryField = document.getElementById('editAddressCountry');
            
            if (firstNameField) firstNameField.value = customer.address.first_name || '';
            if (lastNameField) lastNameField.value = customer.address.last_name || '';
            if (emailField) emailField.value = customer.address.email || '';
            if (phoneField) phoneField.value = customer.address.phone || customer.phone || '';
            if (line1Field) line1Field.value = customer.address.address1 || '';
            if (line2Field) line2Field.value = customer.address.address2 || '';
            if (cityField) cityField.value = customer.address.city || '';
            if (countryField) countryField.value = customer.address.country || 'Pakistan';
        }
        
        // Update customer name field if it exists
        const customerNameField = document.querySelector('input[name="customer_name"]');
        if (customerNameField) customerNameField.value = customer.name || '';
        
        // Hide the selector
        hideCustomerSelector();
        
        alert('Customer information updated successfully!');
    } catch (e) {
        console.error('Error selecting customer:', e);
        alert('Error updating customer information');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
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
    if (!customers || customers.length === 0) { 
        dd.innerHTML = '<div style="padding:8px;color:#6b7280;font-size:12px;">No customers found</div>'; 
        showCustomerDropdown(); 
        return; 
    }

    let html = '';
    customers.forEach(c => {
        // Build address display
        const addressParts = [];
        if (c.address && c.address.address1) addressParts.push(c.address.address1);
        if (c.address && c.address.city) addressParts.push(c.address.city);
        if (c.address && c.address.province) addressParts.push(c.address.province);
        const addressDisplay = addressParts.length > 0 ? addressParts.join(', ') : 'No address';
        
        // Prepare customer data for selection
        const customerData = {
            id: c.id,
            name: c.name || '',
            phone: c.phone || '',
            email: c.email || '',
            notes: c.notes || '',
            address: c.address || {}
        };
        const payload = encodeURIComponent(JSON.stringify(customerData));
        
        html += `
            <div style="padding:10px 12px; cursor:pointer; border-bottom:1px solid #f3f4f6; transition: background-color 0.15s ease;" 
                 onclick="selectCustomer('${c.id}','${(c.name||'').replace(/'/g, "\\'")}', '${payload}')"
                 onmouseover="this.style.backgroundColor='#f8fafc'" 
                 onmouseout="this.style.backgroundColor='white'">
                <div style="font-weight: 500; color: #374151; font-size: 14px; margin-bottom: 2px;">
                    ${c.name || 'No name'}
                </div>
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">
                    📞 ${c.phone || 'No phone'} ${c.email ? '• ✉️ ' + c.email : ''}
                </div>
                <div style="font-size: 12px; color: #9ca3af;">
                    📍 ${addressDisplay}
                </div>
            </div>
        `;
    });
    dd.innerHTML = html;
    showCustomerDropdown();
}
function selectCustomer(customerId, customerName, encodedData) {
    const customerData = encodedData ? JSON.parse(decodeURIComponent(encodedData)) : {};
    const searchInput = document.getElementById('customerSearch');
    const hiddenId = document.getElementById('selectedCustomerId');
    
    // Update the search input with customer name
    if (searchInput) searchInput.value = customerData.name || customerName || '';
    if (hiddenId) hiddenId.value = customerId || '';
    hideCustomerDropdown();

    // Show detailed customer information after selection
    showSelectedCustomerDetails(customerData);

    // Pre-fill new customer fields if visible (reusing existing functionality)
    if (customerData.address) {
    const fields = [
        ['input[name="customer_address1"]', 'address1'],
        ['input[name="customer_address2"]', 'address2'],
        ['input[name="customer_city"]', 'city']
    ];
    fields.forEach(([sel, key]) => {
        const el = document.querySelector(sel);
            if (el && customerData.address[key]) el.value = customerData.address[key];
        });
    }
}
function showSelectedCustomerDetails(customerData) {
    // Find or create customer details display area
    let detailsDiv = document.getElementById('selectedCustomerDetails');
    
    if (!detailsDiv) {
        // Create the details display area below the search input
        const searchContainer = document.getElementById('existingCustomerSection');
        if (searchContainer) {
            detailsDiv = document.createElement('div');
            detailsDiv.id = 'selectedCustomerDetails';
            detailsDiv.style.cssText = `
                margin-top: 12px;
                padding: 12px;
                background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
                border: 1px solid #bae6fd;
                border-radius: 8px;
                font-size: 13px;
                display: none;
            `;
            searchContainer.appendChild(detailsDiv);
        }
    }

    if (detailsDiv && customerData.name) {
        // Build address display
        const addressParts = [];
        if (customerData.address) {
            if (customerData.address.address1) addressParts.push(customerData.address.address1);
            if (customerData.address.address2) addressParts.push(customerData.address.address2);
            if (customerData.address.city) addressParts.push(customerData.address.city);
            if (customerData.address.province) addressParts.push(customerData.address.province);
            if (customerData.address.postal_code) addressParts.push(customerData.address.postal_code);
        }
        const fullAddress = addressParts.length > 0 ? addressParts.join(', ') : 'No address provided';

        // Build customer notes display if they exist
        let notesHtml = '';
        if (customerData.notes && customerData.notes.trim() !== '') {
            notesHtml = `
                <div style="margin-top: 12px; padding: 10px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #fbbf24; border-radius: 6px;">
                    <div style="display: flex; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 18px; margin-right: 6px;">⚠️</span>
                        <strong style="color: #92400e; font-size: 13px;">Customer Instructions / Notes:</strong>
                    </div>
                    <div style="color: #78350f; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word;">${customerData.notes}</div>
                </div>
            `;
        }

        detailsDiv.innerHTML = `
            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 16px; margin-right: 8px;">👤</span>
                <h4 style="margin: 0; color: #0369a1; font-weight: 600;">Selected Customer</h4>
            </div>
            <div style="margin-bottom: 6px;">
                <strong style="color: #374151;">${customerData.name}</strong>
            </div>
            <div style="margin-bottom: 4px; color: #6b7280;">
                📞 ${customerData.phone || 'No phone'} ${customerData.email ? '• ✉️ ' + customerData.email : ''}
            </div>
            <div style="color: #6b7280; line-height: 1.4;">
                📍 ${fullAddress}
            </div>
            ${notesHtml}
        `;
        detailsDiv.style.display = 'block';
    } else if (detailsDiv) {
        detailsDiv.style.display = 'none';
    }
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

// Function to switch to Open Orders tab
function switchToOpenOrders() {
    const tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.classList.add('opacity-60');

    fetch('/orders/filter?source=other&tab=open')
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success) return false;

            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('source', 'other');
            url.searchParams.set('tab', 'open');
            window.history.pushState({}, '', url);

            // Update page title
            const pageTitle = document.querySelector('h1');
            if (pageTitle) pageTitle.textContent = 'Orders';

            // Update tabs
            updateTabsForOpenOrders(data);

            // Load status cards after a short delay to ensure DOM is updated
            console.log('Switching to Open Orders - loading status cards...'); // Debug log
            setTimeout(() => {
                loadOpenOrdersStatusCards();
            }, 200);

            // Render open orders dataset
            rebuildTableWithOrders(data.orders, 'other', 'open');
            
            // Update pagination for filtered results
            updatePaginationForTab(data.orders, 'other', 'open', data.open_count);
            
            refreshPaginationInfo({
                shopify_all_count: data.shopify_all_count,
                shopify_approvals_count: data.shopify_approvals_count,
                other_count: data.other_count,
                open_count: data.open_count
            });
        })
        .catch(err => console.error('Failed to load open orders:', err))
        .finally(() => {
            if (tableContainer) tableContainer.classList.remove('opacity-60');
        });

    return false;
}
// Update tabs for open orders view
function updateTabsForOpenOrders(data) {
    const tabsContainer = document.querySelector('.flex.space-x-1.bg-gray-100');
    if (tabsContainer) {
        tabsContainer.innerHTML = `
            <a href="#" onclick="return switchToInvoices()" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50">
                Invoices
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-invoices">${data.other_count || '-'}</span>
            </a>
            <button class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 bg-white text-blue-600 shadow-sm">
                Open Orders
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-open">${data.open_count || '-'}</span>
            </button>
            <button onclick="switchToRiders()" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50">
                Riders
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-riders">${data.open_count || '-'}</span>
            </button>
            <button onclick="switchToShopifyApprovals()" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50">
                Shopify Approvals
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-approvals">${data.shopify_approvals_count || '-'}</span>
            </button>
        `;
    }

    // Show status cards section, hide riders cards
    const statusCardsSection = document.getElementById('openOrdersStatusCards');
    const ridersCards = document.getElementById('ridersCards');
    if (statusCardsSection) {
        statusCardsSection.style.display = 'block';
    }
    if (ridersCards) {
        ridersCards.style.display = 'none';
    }
}

// Load and display open orders status cards
async function loadOpenOrdersStatusCards() {
    console.log('Loading open orders status cards...'); // Debug log
    try {
        const response = await fetch('/orders/open-status-counts', {
            headers: { 
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' 
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        console.log('Status cards API response:', data); // Debug log
        
        if (data.success) {
            // Store verified/unverified counts globally for filtering
            window.verifiedLocationCounts = {
                all_open_verified: data.all_open_verified || 0,
                all_open_unverified: data.all_open_unverified || 0,
                out_for_delivery_verified: data.out_for_delivery_verified || 0,
                out_for_delivery_unverified: data.out_for_delivery_unverified || 0
            };
            
            renderStatusCards(data.status_counts, data.total_open_count, data.delivered_today || 0, window.verifiedLocationCounts);
        } else {
            console.error('Failed to load status counts:', data.message);
        }
    } catch (error) {
        console.error('Error loading status cards:', error);
    }
}
// Render status cards with modern design
function renderStatusCards(statusCounts, totalOpenCount, deliveredTodayCount = 0, verifiedCounts = null) {
    console.log('Rendering status cards:', statusCounts, 'Total:', totalOpenCount, 'Verified counts:', verifiedCounts); // Debug log
    const container = document.getElementById('statusCardsContainer');
    if (!container) {
        console.log('Status cards container not found!'); // Debug log
        return;
    }

    // Create "All Open" card first with verified/unverified breakdown
    let cardsHtml = `
        <div class="status-card active" data-status="all" onclick="filterByStatus('all')">
            <div class="flex items-center justify-between p-4 bg-white rounded-lg border-2 border-blue-200 shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer min-w-[140px]">
                <div class="flex-1">
                    <div class="text-2xl font-bold text-blue-600">${totalOpenCount}</div>
                    <div class="text-sm font-medium text-gray-700">All Open</div>
                    ${verifiedCounts ? `
                        <div class="mt-2">
                            <div class="text-xs text-gray-600 font-medium mb-1">Verified Location:</div>
                            <div class="flex gap-2 text-xs">
                                <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded cursor-pointer hover:bg-green-200" onclick="event.stopPropagation(); filterByVerifiedLocation('all', 'verified');" title="Click to filter verified addresses">
                                    ✓ ${verifiedCounts.all_open_verified}
                                </span>
                                <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded cursor-pointer hover:bg-orange-200" onclick="event.stopPropagation(); filterByVerifiedLocation('all', 'unverified');" title="Click to filter unverified addresses">
                                    ✗ ${verifiedCounts.all_open_unverified}
                                </span>
                            </div>
                        </div>
                    ` : ''}
                </div>
                <div class="text-2xl">📋</div>
            </div>
        </div>
    `;

    // Delivered Today card (informational)
    cardsHtml += `
        <div class="status-card" data-status="delivered_today">
            <div class="flex items-center justify-between p-4 bg-white rounded-lg border-2 border-green-200 shadow-sm hover:shadow-md transition-all duration-200 cursor-default min-w-[140px]">
                <div>
                    <div class="text-2xl font-bold text-green-600">${deliveredTodayCount}</div>
                    <div class="text-sm font-medium text-gray-700">Delivered Today</div>
                </div>
                <div class="text-2xl">✅</div>
            </div>
        </div>
    `;

    // Add individual status cards
    statusCounts.forEach(status => {
        const colorClass = getStatusColorClass(status.color_class);
        const isOutForDelivery = status.status_code === 'out_for_delivery';
        
        cardsHtml += `
            <div class="status-card" data-status="${status.status_code}" onclick="filterByStatus('${status.status_code}')">
                <div class="flex items-center justify-between p-4 bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer min-w-[140px] hover:border-${colorClass}-300">
                    <div class="flex-1">
                        <div class="text-2xl font-bold text-${colorClass}-600">${status.count}</div>
                        <div class="text-sm font-medium text-gray-700">${status.status_name}</div>
                        ${isOutForDelivery && verifiedCounts ? `
                            <div class="mt-2">
                                <div class="text-xs text-gray-600 font-medium mb-1">Verified Location:</div>
                                <div class="flex gap-2 text-xs">
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded cursor-pointer hover:bg-green-200" onclick="event.stopPropagation(); filterByVerifiedLocation('out_for_delivery', 'verified');" title="Click to filter verified addresses">
                                        ✓ ${verifiedCounts.out_for_delivery_verified}
                                    </span>
                                    <span class="px-2 py-0.5 bg-orange-100 text-orange-700 rounded cursor-pointer hover:bg-orange-200" onclick="event.stopPropagation(); filterByVerifiedLocation('out_for_delivery', 'unverified');" title="Click to filter unverified addresses">
                                        ✗ ${verifiedCounts.out_for_delivery_unverified}
                                    </span>
                                </div>
                            </div>
                        ` : ''}
                    </div>
                    <div class="text-2xl">${status.icon}</div>
                </div>
            </div>
        `;
    });

    container.innerHTML = cardsHtml;
}

// Get appropriate color class for status
function getStatusColorClass(colorClass) {
    const colorMap = {
        'yellow': 'yellow',
        'orange': 'orange', 
        'blue': 'blue',
        'purple': 'purple',
        'green': 'green',
        'red': 'red',
        'gray': 'gray'
    };
    return colorMap[colorClass] || 'gray';
}

// Filter orders by status
function filterByStatus(statusCode) {
    // Update active card
    document.querySelectorAll('.status-card').forEach(card => {
        card.classList.remove('active');
        const cardDiv = card.querySelector('div');
        if (statusCode === 'all') {
            cardDiv.className = cardDiv.className.replace(/border-\w+-200/, 'border-gray-200');
        }
    });
    
    const activeCard = document.querySelector(`[data-status="${statusCode}"]`);
    if (activeCard) {
        activeCard.classList.add('active');
        const cardDiv = activeCard.querySelector('div');
        if (statusCode === 'all') {
            cardDiv.className = cardDiv.className.replace(/border-gray-200/, 'border-blue-200');
        } else {
            // Add active border color for specific status
            const status = statusCode;
            cardDiv.className = cardDiv.className.replace(/border-gray-200/, `border-blue-200`);
        }
    }

    // Filter table data
    const tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.classList.add('opacity-60');

    const params = new URLSearchParams({
        source: 'other',
        tab: 'open'
    });
    
    if (statusCode !== 'all') {
        params.append('status', statusCode);
    }

    fetch(`/orders/filter?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                rebuildTableWithOrders(data.orders, 'other', 'open');
                // Update pagination for filtered results
                updatePaginationForTab(data.orders, 'other', 'open');
            }
        })
        .catch(err => console.error('Failed to filter by status:', err))
        .finally(() => {
            if (tableContainer) tableContainer.classList.remove('opacity-60');
    });
}

// Filter orders by verified location status
function filterByVerifiedLocation(statusCode, verificationType) {
    console.log('Filtering by verified location:', statusCode, verificationType);
    
    // Show loading state
    const tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.classList.add('opacity-60');

    // Build filter params
    const params = new URLSearchParams({
        source: 'other',
        tab: 'open'
    });
    
    // Add status filter if not 'all'
    if (statusCode !== 'all') {
        params.append('status', statusCode);
    }

    // Fetch orders
    fetch(`/orders/filter?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                // Filter orders based on verified location
                let filteredOrders = data.orders;
                
                if (verificationType === 'verified') {
                    // Show only orders with verified location
                    filteredOrders = filteredOrders.filter(order => {
                        // Check if customer has verified location
                        if (order.customer_id && window.ordersData) {
                            const fullOrder = window.ordersData.find(o => o.id === order.id);
                            if (fullOrder && fullOrder.customer) {
                                return fullOrder.customer.verified_location_url || 
                                       (fullOrder.customer.latitude && fullOrder.customer.longitude);
                            }
                        }
                        return false;
                    });
                } else if (verificationType === 'unverified') {
                    // Show only orders without verified location
                    filteredOrders = filteredOrders.filter(order => {
                        // Check if customer does NOT have verified location
                        if (order.customer_id && window.ordersData) {
                            const fullOrder = window.ordersData.find(o => o.id === order.id);
                            if (fullOrder && fullOrder.customer) {
                                return !fullOrder.customer.verified_location_url && 
                                       !(fullOrder.customer.latitude && fullOrder.customer.longitude);
                            }
                        }
                        return true; // If no customer data, assume unverified
                    });
                }
                
                rebuildTableWithOrders(filteredOrders, 'other', 'open');
                // Update pagination for filtered results
                updatePaginationForTab(filteredOrders, 'other', 'open');
                
                // Show toast message
                const message = verificationType === 'verified' 
                    ? `Showing ${filteredOrders.length} orders with verified addresses`
                    : `Showing ${filteredOrders.length} orders without verified addresses`;
                showToast('Filtered', message, 'info');
            }
        })
        .catch(err => console.error('Failed to filter by verified location:', err))
        .finally(() => {
            if (tableContainer) tableContainer.classList.remove('opacity-60');
        });
}

// Function to switch to Shopify Approvals from main orders page
function switchToShopifyApprovals() {
    // Hide all card sections
    hideAllCardSections();
    
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
    // Use full navigation to restore server-rendered pagination and correct pagination links
    window.location.href = '/orders?source=other';
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
            <table class="min-w-full divide-y divide-gray-200" style="width: max-content; min-width: 100%;">
                <colgroup id="table-colgroup"></colgroup>
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

    // Update status filter with new data (safe for Shopify: will show only All status)
    initStatusFilter();

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
            const bOpen = document.getElementById('badge-open');
            if (bAll && counts.shopify_all_count != null) bAll.textContent = counts.shopify_all_count;
            if (bApp && counts.shopify_approvals_count != null) bApp.textContent = counts.shopify_approvals_count;
            if (bInv && counts.other_count != null) bInv.textContent = counts.other_count;
            if (bOpen && counts.open_count != null) bOpen.textContent = counts.open_count;
        }
    } catch (e) { console.warn('refreshPaginationInfo failed', e); }
}
// Update pagination for filtered/tab-switched data
function updatePaginationForTab(orders, source, tab, totalCount = null) {
    try {
        const paginationInfo = document.getElementById('pagination-info');
        const numericPager = document.getElementById('numeric-pager');
        const pagerWrap = document.getElementById('pager-wrap');
        
        if (!orders || !Array.isArray(orders)) return;
        
        const perPageSelect = document.getElementById('per-page-selector');
        const perPage = perPageSelect ? parseInt(perPageSelect.value, 10) : 25;
        const displayedCount = Math.min(orders.length, perPage);
        const total = totalCount || orders.length;
        
        // Update pagination info
        if (paginationInfo) {
            paginationInfo.textContent = `1-${displayedCount} of ${total}`;
        }
        
        // Hide numeric pagination for single page results
        if (numericPager) {
            if (total <= perPage) {
                numericPager.classList.add('hidden');
            } else {
                numericPager.classList.remove('hidden');
                // For now, just show page 1 since we're loading limited results
                numericPager.innerHTML = '<span class="px-3 py-2 text-sm bg-blue-600 text-white rounded-md font-medium">1</span>';
            }
        }
        
        // Update Previous/Next buttons to be disabled since we're showing filtered results
        if (pagerWrap) {
            const prevBtn = pagerWrap.querySelector('a[href*="page"], button:first-child');
            const nextBtn = pagerWrap.querySelector('a[href*="page"]:last-child, button:last-child');
            
            if (prevBtn) {
                prevBtn.outerHTML = '<button class="px-3 py-2 text-sm text-gray-400 bg-white border border-gray-300 rounded-md cursor-not-allowed" disabled>Previous</button>';
            }
            if (nextBtn && nextBtn !== prevBtn) {
                nextBtn.outerHTML = '<button class="px-3 py-2 text-sm text-gray-400 bg-white border border-gray-300 rounded-md cursor-not-allowed" disabled>Next</button>';
            }
        }
        
    } catch (e) { 
        console.warn('updatePaginationForTab failed', e); 
    }
}

// Bulk Status Change Functions
let availableStatuses = [];
let selectedOrderIds = [];

// Load available statuses for bulk change
async function loadAvailableStatuses() {
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
            availableStatuses = data.data;
            populateStatusSelect();
        }
    } catch (error) {
        console.error('Error loading statuses:', error);
    }
}
// Populate status select dropdown
function populateStatusSelect() {
    const select = document.getElementById('bulkNewStatus');
    if (!select) return;
    
    select.innerHTML = '<option value="">Select new status...</option>';
    
    availableStatuses.forEach(status => {
        const option = document.createElement('option');
        option.value = status.status_code;
        option.textContent = `${status.icon} ${status.status_name}`;
        select.appendChild(option);
    });
}

// Open bulk status modal
// Note: openBulkStatusModal function is defined later with enhanced checkbox functionality

// Populate orders list for bulk selection
function populateBulkOrdersList() {
    const container = document.getElementById('bulkOrdersList');
    if (!container || !window.currentOrders) return;
    
    // Filter out Shopify orders
    const eligibleOrders = window.currentOrders.filter(order => 
        order.external_source !== 'shopify'
    );
    
    if (eligibleOrders.length === 0) {
        container.innerHTML = '<div style="color: #6b7280; font-style: italic;">No eligible orders found on this page</div>';
        return;
    }
    
    container.innerHTML = eligibleOrders.map(order => `
        <div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: white; border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 4px;">
            <input type="checkbox" id="order_${order.id}" value="${order.id}" onchange="toggleOrderSelection(${order.id})" style="border-radius: 4px;">
            <label for="order_${order.id}" style="flex: 1; font-size: 14px; cursor: pointer;">
                <span style="font-weight: 500;">#${order.order_number || order.id}</span>
                <span style="color: #6b7280; margin-left: 8px;">$${parseFloat(order.total_price || 0).toFixed(2)}</span>
                <span style="color: #6b7280; margin-left: 8px;">${order.order_status || 'unknown'}</span>
            </label>
        </div>
    `).join('');
}
// Toggle individual order selection
function toggleOrderSelection(orderId) {
    const checkbox = document.getElementById(`order_${orderId}`);
    if (!checkbox) return;
    
    if (checkbox.checked) {
        if (!selectedOrderIds.includes(orderId)) {
            selectedOrderIds.push(orderId);
        }
    } else {
        selectedOrderIds = selectedOrderIds.filter(id => id !== orderId);
    }
    
    updateSelectedCount();
    updateSelectAllCheckbox();
    updateBulkActionButtons(); // Add this line to update bulk status button
}

// Toggle all orders selection
function toggleAllOrders() {
    const selectAllCheckbox = document.getElementById('selectAllOrders');
    const orderCheckboxes = document.querySelectorAll('#bulkOrdersList input[type="checkbox"]');
    
    orderCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
        const orderId = parseInt(checkbox.value);
        
        if (selectAllCheckbox.checked) {
            if (!selectedOrderIds.includes(orderId)) {
                selectedOrderIds.push(orderId);
            }
        } else {
            selectedOrderIds = selectedOrderIds.filter(id => id !== orderId);
        }
    });
    
    updateSelectedCount();
}

// Update selected orders count
function updateSelectedCount() {
    const countElement = document.getElementById('selectedOrdersCount');
    const executeBtn = document.getElementById('executeBulkStatusBtn');
    
    if (countElement) {
        countElement.textContent = `${selectedOrderIds.length} orders selected`;
    }
    
    if (executeBtn) {
        executeBtn.disabled = selectedOrderIds.length === 0;
    }
}

// Update select all checkbox state
function updateSelectAllCheckbox() {
    const selectAllCheckbox = document.getElementById('selectAllOrders');
    const orderCheckboxes = document.querySelectorAll('#bulkOrdersList input[type="checkbox"]');
    const checkedBoxes = document.querySelectorAll('#bulkOrdersList input[type="checkbox"]:checked');
    
    if (checkedBoxes.length === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedBoxes.length === orderCheckboxes.length) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }
}

// Execute bulk status change
async function executeBulkStatusChange() {
    const newStatus = document.getElementById('bulkNewStatus').value;
    const notes = document.getElementById('bulkStatusNotes').value;
    
    console.log('Bulk update debug:');
    console.log('- newStatus:', newStatus);
    console.log('- selectedOrderIds:', selectedOrderIds);
    console.log('- selectedOrderIds.length:', selectedOrderIds.length);
    
    if (!newStatus) {
        showBulkStatusAlert('Please select a new status', 'error');
        return;
    }
    
    if (selectedOrderIds.length === 0) {
        showBulkStatusAlert('Please select at least one order', 'error');
        return;
    }
    
    const executeBtn = document.getElementById('executeBulkStatusBtn');
    const originalText = executeBtn.textContent;
    
    try {
        executeBtn.disabled = true;
        executeBtn.innerHTML = '<div style="width: 16px; height: 16px; border: 2px solid #ffffff; border-top: 2px solid transparent; border-radius: 50%; animation: spin 1s linear infinite; margin-right: 8px;"></div>Updating...';
        
        const requestBody = {
            order_ids: selectedOrderIds,
            status_code: newStatus,
            notes: notes
        };
        
        console.log('Bulk update request body:', requestBody);
        
        const response = await fetch('/order-status/api/bulk-change', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(requestBody),
            credentials: 'same-origin'
        });
        
        console.log('Bulk update response status:', response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Bulk update API failed:', response.status, errorText);
            showBulkStatusAlert(`API Error: ${response.status} - ${errorText}`, 'error');
            return;
        }
        
        const data = await response.json();
        console.log('Bulk update response data:', data);
        
        if (data.success) {
            const successCount = data.summary ? data.summary.success : data.updated_count || 0;
            showBulkStatusAlert(`Successfully updated ${successCount} orders`, 'success');
            
            // Refresh the orders table
            setTimeout(() => {
                closeModal('bulkStatusModal');
                location.reload(); // Simple refresh for now
            }, 2000);
        } else {
            showBulkStatusAlert(data.message || 'Failed to update orders', 'error');
        }
    } catch (error) {
        console.error('Error updating orders:', error);
        showBulkStatusAlert('An error occurred while updating orders', 'error');
    } finally {
        executeBtn.disabled = false;
        executeBtn.textContent = originalText;
    }
}

// Show alert in bulk status modal
function showBulkStatusAlert(message, type) {
    const alertContainer = document.getElementById('bulkStatusAlert');
    if (!alertContainer) return;
    
    const alertClass = type === 'success' ? 'background: #d1fae5; color: #065f46; border: 1px solid #34d399;' : 'background: #fee2e2; color: #dc2626; border: 1px solid #f87171;';
    
    alertContainer.innerHTML = `
        <div style="padding: 12px 16px; border-radius: 8px; font-size: 14px; ${alertClass}">
            ${message}
        </div>
    `;
    alertContainer.style.display = 'block';
    
    // Auto-hide success messages
    if (type === 'success') {
        setTimeout(() => {
            alertContainer.style.display = 'none';
        }, 5000);
    }
}

// View order status history
async function viewOrderStatusHistory(orderId) {
    document.getElementById('statusHistoryModal').style.display = 'flex';
    
    try {
        const response = await fetch(`/order-status/api/orders/${orderId}/history`);
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            renderStatusHistory(data.data);
        } else {
            document.getElementById('statusHistoryContent').innerHTML = `
                <div style="text-align: center; padding: 40px; color: #6b7280;">
                    No status history found for this order
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading status history:', error);
        document.getElementById('statusHistoryContent').innerHTML = `
            <div style="text-align: center; padding: 40px; color: #ef4444;">
                Error loading status history
            </div>
        `;
    }
}
// Render status history
function renderStatusHistory(history) {
    const content = document.getElementById('statusHistoryContent');
    
    content.innerHTML = `
        <div style="space-y: 16px;">
            ${history.map((entry, index) => `
                <div style="display: flex; gap: 16px; ${index < history.length - 1 ? 'border-bottom: 1px solid #e5e7eb; padding-bottom: 16px;' : ''}">
                    <div style="flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; background: ${getStatusColor(entry.status_code)}; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 12px;">
                        ${entry.status ? entry.status.icon : '?'}
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 600; color: #111827; margin-bottom: 4px;">
                            ${entry.status ? entry.status.status_name : entry.status_code}
                        </div>
                        <div style="font-size: 14px; color: #6b7280; margin-bottom: 4px;">
                            ${formatDate(entry.changed_at)} by ${entry.changed_by ? entry.changed_by.name : 'System'}
                        </div>
                        ${entry.notes ? `<div style="font-size: 14px; color: #374151; background: #f9fafb; padding: 8px; border-radius: 6px; border-left: 3px solid ${getStatusColor(entry.status_code)};">${entry.notes}</div>` : ''}
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}
// Get status color for history display
function getStatusColor(statusCode) {
    const colorMap = {
        'new': '#eab308',
        'on_hold': '#f97316', 
        'processing': '#3b82f6',
        'out_for_delivery': '#8b5cf6',
        'delivered': '#10b981',
        'cancelled': '#ef4444',
        'refunded': '#8b5cf6'
    };
    return colorMap[statusCode] || '#6b7280';
}

// Bulk selection functions for checkbox-based selection
function toggleAllOrdersSelection() {
    const selectAllCheckbox = document.getElementById('selectAllOrders');
    const orderCheckboxes = document.querySelectorAll('.order-checkbox');
    
    orderCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateBulkActionButtons();
}

function updateBulkActionButtons() {
    // Use selectedOrderIds array as source of truth instead of DOM queries
    const selectedCount = selectedOrderIds ? selectedOrderIds.length : 0;
    const bulkStatusBtn = document.querySelector('button[onclick="openBulkStatusModal()"]');
    const selectAllCheckbox = document.getElementById('selectAllOrders');
    const totalCheckboxes = document.querySelectorAll('.order-checkbox');
    
    // Update bulk status button
    if (bulkStatusBtn) {
        if (selectedCount > 0) {
            // Update button text while preserving the SVG icon
            const svgIcon = `<svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>`;
            bulkStatusBtn.innerHTML = svgIcon + `Bulk Status (${selectedCount})`;
            bulkStatusBtn.disabled = false;
            bulkStatusBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        } else {
            // Reset button text while preserving the SVG icon
            const svgIcon = `<svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>`;
            bulkStatusBtn.innerHTML = svgIcon + 'Bulk Status';
            bulkStatusBtn.disabled = true;
            bulkStatusBtn.classList.add('opacity-50', 'cursor-not-allowed');
        }
    }
    
    // Update select all checkbox state based on actual checked checkboxes (for visual consistency)
    const selectedCheckboxes = document.querySelectorAll('.order-checkbox:checked');
    if (selectAllCheckbox && totalCheckboxes.length > 0) {
        if (selectedCheckboxes.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (selectedCheckboxes.length === totalCheckboxes.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }
}

function getSelectedOrderIds() {
    // Source of truth is selectedOrderIds so table and modal stay in sync
    if (selectedOrderIds && selectedOrderIds.length) return [...selectedOrderIds];
    const selectedCheckboxes = document.querySelectorAll('.order-checkbox:checked');
    return Array.from(selectedCheckboxes).map(cb => parseInt(cb.value));
}
// Enhanced bulk status modal for checkbox-based selection
function openBulkStatusModal() {
    const selectedIds = selectedOrderIds && selectedOrderIds.length ? selectedOrderIds : getSelectedOrderIds();
    
    if (selectedIds.length === 0) {
        alert('Please select orders first by checking the checkboxes.');
        return;
    }
    
    // Load available statuses
    loadAvailableStatuses();
    
    // Reset form
    document.getElementById('bulkNewStatus').value = '';
    document.getElementById('bulkStatusNotes').value = '';
    
    // Update modal content to show selected orders
    const container = document.getElementById('bulkOrdersList');
    const selectedOrders = (window.ordersData || []).filter(order => selectedIds.includes(order.id));
    
    container.innerHTML = selectedOrders.map(order => `
        <div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: #e0f2fe; border: 1px solid #0284c7; border-radius: 6px; margin-bottom: 4px;">
            <span style="color: #0284c7;">[âœ“]</span>
            <label style="flex: 1; font-size: 14px;">
                <span style="font-weight: 500;">#${order.order_number || order.id}</span>
                <span style="color: #6b7280; margin-left: 8px;">$${parseFloat(order.total_price || 0).toFixed(2)}</span>
                <span style="color: #6b7280; margin-left: 8px;">${order.order_status || 'unknown'}</span>
            </label>
        </div>
    `).join('');
    
    // Update selected count
    document.getElementById('selectedOrdersCount').textContent = `${selectedIds.length} orders selected`;
    document.getElementById('executeBulkStatusBtn').disabled = false;
    
    // Store selected IDs globally
    selectedOrderIds = selectedIds;
    
    document.getElementById('bulkStatusModal').style.display = 'flex';
}

// Initialize bulk status functionality when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Store current orders globally for bulk operations
    window.currentOrders = [];
    
    // Initialize bulk action buttons state
    setTimeout(() => {
        updateBulkActionButtons();
    }, 1000);
});

// Bulk Rider Assignment Functions
function openBulkRiderModal() {
    const selectedIds = selectedOrderIds && selectedOrderIds.length ? selectedOrderIds : getSelectedOrderIds();
    
    if (selectedIds.length === 0) {
        alert('Please select orders first by checking the checkboxes.');
        return;
    }
    
    // Load riders
    fetch('/riders/active', { headers: { 'Accept': 'application/json' }})
        .then(r => r.json())
        .then(j => {
            const sel = document.getElementById('bulkRiderSelect');
            if (j && j.success && j.data) {
                sel.innerHTML = '<option value="">-- Select a rider to assign --</option>' + 
                    j.data.map(r => `<option value="${r.id}">${r.fullname}</option>`).join('');
            } else {
                sel.innerHTML = '<option value="">No riders found</option>';
            }
        })
        .catch(() => {
            document.getElementById('bulkRiderSelect').innerHTML = '<option value="">Failed to load riders</option>';
        });
    
    // Show selected orders
    const container = document.getElementById('bulkRiderOrdersList');
    const selectedOrders = (window.ordersData || []).filter(order => selectedIds.includes(order.id));
    
    container.innerHTML = selectedOrders.map(order => `
        <div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: #e0f2fe; border: 1px solid #0891b2; border-radius: 6px; margin-bottom: 4px;">
            <span style="color: #0891b2;">[✓]</span>
            <label style="flex: 1; font-size: 14px;">
                <span style="font-weight: 500;">#${order.order_number || order.id}</span>
                <span style="color: #6b7280; margin-left: 8px;">${order.rider_name || 'No rider'}</span>
            </label>
        </div>
    `).join('');
    
    // Update count
    document.getElementById('selectedRiderOrdersCount').textContent = `${selectedIds.length} orders selected`;
    document.getElementById('executeBulkRiderBtn').disabled = false;
    
    // Store selected IDs
    selectedOrderIds = selectedIds;
    
    document.getElementById('bulkRiderModal').style.display = 'flex';
}

async function executeBulkRiderAssign() {
    const riderId = document.getElementById('bulkRiderSelect').value;
    const selectedIds = selectedOrderIds && selectedOrderIds.length ? selectedOrderIds : getSelectedOrderIds();
    
    if (selectedIds.length === 0) {
        alert('No orders selected');
        return;
    }
    
    if (!riderId) {
        alert('Please select a rider');
        return;
    }
    
    const btn = document.getElementById('executeBulkRiderBtn');
    const originalText = btn.textContent;
    btn.textContent = 'Assigning...';
    btn.disabled = true;
    
    try {
        // Assign rider to each order
        let successCount = 0;
        let failCount = 0;
        
        for (const orderId of selectedIds) {
            try {
                const response = await fetch(`/orders/${orderId}/rider/assign`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({ rider_user_id: parseInt(riderId, 10) })
                });
                
                const data = await response.json();
                if (data.success) {
                    successCount++;
                } else {
                    failCount++;
                }
            } catch (error) {
                failCount++;
            }
        }
        
        // Show results
        alert(`Bulk assignment complete!\nSuccess: ${successCount}\nFailed: ${failCount}`);
        
        // Close modal and refresh
        closeModal('bulkRiderModal');
        location.reload();
        
    } catch (error) {
        alert('Bulk assignment failed: ' + error.message);
        btn.textContent = originalText;
        btn.disabled = false;
    }
}

// ============================================
// RIDERS TAB FUNCTIONS
// ============================================

/**
 * Switch to Riders tab - shows rider-wise breakdown
 */
function switchToRiders() {
    const tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.classList.add('opacity-60');

    // Hide other cards
    hideAllCardSections();

    fetch('/orders/filter?source=other&tab=riders')
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success) return false;

            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('source', 'other');
            url.searchParams.set('tab', 'riders');
            window.history.pushState({}, '', url);

            // Update page title
            const pageTitle = document.querySelector('h1');
            if (pageTitle) pageTitle.textContent = 'Orders';

            // Update tabs
            updateTabsForRiders(data);

            // Load rider cards after a short delay
            console.log('Switching to Riders - loading rider cards...');
            setTimeout(() => {
                loadRiderCards();
            }, 200);

            // Render open orders dataset (same as open orders tab)
            rebuildTableWithOrders(data.orders, 'other', 'riders');
            
            // Update pagination for filtered results
            updatePaginationForTab(data.orders, 'other', 'riders', data.open_count);
            
            // Remove opacity
            if (tableContainer) tableContainer.classList.remove('opacity-60');
        })
        .catch(err => {
            console.error('Error switching to riders:', err);
            if (tableContainer) tableContainer.classList.remove('opacity-60');
        });
}

/**
 * Update tabs for riders view
 */
function updateTabsForRiders(data) {
    const tabsContainer = document.querySelector('.flex.space-x-1.bg-gray-100');
    if (tabsContainer) {
        tabsContainer.innerHTML = `
            <a href="#" onclick="return switchToInvoices()" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50">
                Invoices
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-invoices">${data.other_count || '-'}</span>
            </a>
            <button onclick="switchToOpenOrders()" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50">
                Open Orders
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-open">${data.open_count || '-'}</span>
            </button>
            <button class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 bg-white text-blue-600 shadow-sm">
                Riders
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-riders">${data.open_count || '-'}</span>
            </button>
            <button onclick="switchToShopifyApprovals()" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-200 text-gray-600 hover:text-gray-800 hover:bg-gray-50">
                Shopify Approvals
                <span class="ml-1.5 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full font-semibold" id="badge-approvals">${data.shopify_approvals_count || '-'}</span>
            </button>
        `;
    }

    // Show riders cards section, hide status cards
    const ridersCards = document.getElementById('ridersCards');
    const statusCards = document.getElementById('openOrdersStatusCards');
    if (ridersCards) ridersCards.style.display = 'block';
    if (statusCards) statusCards.style.display = 'none';
}

/**
 * Load rider cards from API
 */
async function loadRiderCards() {
    console.log('Loading rider cards...');
    const container = document.getElementById('ridersCardsContainer');
    
    try {
        const response = await fetch('/orders/rider-counts', {
            headers: { 
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest' 
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        console.log('Rider cards API response:', data);
        
        if (data.success) {
            renderRiderCards(data);
        } else {
            console.error('Failed to load rider counts:', data.message);
            if (container) {
                container.innerHTML = '<div class="flex items-center justify-center py-8 text-red-500">Failed to load rider data: ' + (data.message || 'Unknown error') + '</div>';
            }
        }
    } catch (error) {
        console.error('Error loading rider cards:', error);
        if (container) {
            container.innerHTML = '<div class="flex items-center justify-center py-8 text-red-500">Error loading rider cards. Please refresh the page.</div>';
        }
    }
}

/**
 * Render rider cards with modern design
 */
function renderRiderCards(data) {
    console.log('Rendering rider cards:', data);
    const container = document.getElementById('ridersCardsContainer');
    if (!container) {
        console.log('Rider cards container not found!');
        return;
    }

    const { riders, unassigned_count, unassigned_breakdown, total_open_count, assigned_count, riders_count } = data;

    // Start with summary cards
    let cardsHtml = '';

    // Card 1: Total Open Orders
    cardsHtml += `
        <div class="rider-card">
            <div class="flex items-center justify-between p-4 bg-white rounded-lg border-2 border-blue-200 shadow-sm hover:shadow-md transition-all duration-200 cursor-default min-w-[160px]">
                <div>
                    <div class="text-2xl font-bold text-blue-600">${total_open_count}</div>
                    <div class="text-sm font-medium text-gray-700">Total Open</div>
                </div>
                <div class="text-2xl">📦</div>
            </div>
        </div>
    `;

    // Card 2: Assigned Orders
    cardsHtml += `
        <div class="rider-card">
            <div class="flex items-center justify-between p-4 bg-white rounded-lg border-2 border-green-200 shadow-sm hover:shadow-md transition-all duration-200 cursor-default min-w-[160px]">
                <div>
                    <div class="text-2xl font-bold text-green-600">${assigned_count}</div>
                    <div class="text-sm font-medium text-gray-700">Assigned</div>
                    <div class="text-xs text-gray-500 mt-1">${riders_count} Riders</div>
                </div>
                <div class="text-2xl">✅</div>
            </div>
        </div>
    `;

    // Card 3: Unassigned Orders
    const unassignedBreakdownText = formatStatusBreakdown(unassigned_breakdown);
    cardsHtml += `
        <div class="rider-card" onclick="filterByRider(null)">
            <div class="flex items-center justify-between p-4 bg-white rounded-lg border-2 border-amber-200 shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer min-w-[160px]">
                <div>
                    <div class="text-2xl font-bold text-amber-600">${unassigned_count}</div>
                    <div class="text-sm font-medium text-gray-700">Unassigned</div>
                    ${unassignedBreakdownText ? `<div class="text-xs text-gray-500 mt-1">${unassignedBreakdownText}</div>` : ''}
                </div>
                <div class="text-2xl">❓</div>
            </div>
        </div>
    `;

    // Add individual rider cards
    riders.forEach(rider => {
        const breakdownText = formatStatusBreakdown(rider.status_breakdown);
        cardsHtml += `
            <div class="rider-card" onclick="filterByRider(${rider.rider_id})">
                <div class="flex items-center justify-between p-4 bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-md hover:border-blue-300 transition-all duration-200 cursor-pointer min-w-[180px]">
                    <div class="flex-1">
                        <div class="text-2xl font-bold text-gray-800">${rider.total_count}</div>
                        <div class="text-sm font-semibold text-gray-900">${rider.rider_name}</div>
                        ${breakdownText ? `<div class="text-xs text-gray-500 mt-1.5 leading-relaxed">${breakdownText}</div>` : ''}
                    </div>
                    <div class="text-2xl ml-2">🏍️</div>
                </div>
            </div>
        `;
    });

    container.innerHTML = cardsHtml;
}

/**
 * Format status breakdown for display in rider cards
 */
function formatStatusBreakdown(breakdown) {
    if (!breakdown || Object.keys(breakdown).length === 0) {
        return '';
    }

    const statusNames = {
        'new': 'New',
        'pending': 'Pending',
        'processing': 'Processing',
        'on_hold': 'On Hold',
        'out_for_delivery': 'Out',
        'delivered': 'Delivered'
    };

    const parts = [];
    for (const [status, count] of Object.entries(breakdown)) {
        const name = statusNames[status] || status;
        parts.push(`${name}: ${count}`);
    }

    return parts.join(' • ');
}

/**
 * Filter orders by rider
 */
function filterByRider(riderId) {
    console.log('Filtering by rider:', riderId);
    
    // Use the orders data directly instead of parsing HTML
    if (!window.allOrders || window.allOrders.length === 0) {
        console.warn('No orders data available for filtering');
        return;
    }
    
    let filtered;

        if (riderId === null) {
        // Show unassigned orders only
        filtered = window.allOrders.filter(order => {
            const assignedRider = order.assigned_rider_user_id || order.rider_user_id;
            return !assignedRider || assignedRider === null || assignedRider === undefined;
        });
        console.log(`Filtered ${filtered.length} unassigned orders`);
            } else {
        // Show orders for specific rider
        filtered = window.allOrders.filter(order => {
            const assignedRider = order.assigned_rider_user_id || order.rider_user_id;
            return assignedRider && parseInt(assignedRider) === parseInt(riderId);
        });
        console.log(`Filtered ${filtered.length} orders for rider ID ${riderId}`);
    }
    
    // Update filtered orders and re-render table
    window.filteredOrders = filtered;
    renderOrdersWithFilters(filtered);
    updateResultsCount();
}

/**
 * Helper function to hide all card sections
 */
function hideAllCardSections() {
    const ridersCards = document.getElementById('ridersCards');
    const statusCards = document.getElementById('openOrdersStatusCards');
    if (ridersCards) ridersCards.style.display = 'none';
    if (statusCards) statusCards.style.display = 'none';
}

</script>
<!-- Bulk Status Change Modal -->
<div id="bulkStatusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        
        <!-- Modal Header -->
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Bulk Status Change</h3>
            <button onclick="closeModal('bulkStatusModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 24px;">
            <div id="bulkStatusAlert" style="display: none; margin-bottom: 16px;"></div>
            
            <!-- Step 1: Select Orders -->
            <div style="margin-bottom: 24px;">
                <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #374151;">Step 1: Select Orders</h4>
                <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; max-height: 300px; overflow-y: auto;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <input type="checkbox" id="selectAllOrders" onchange="toggleAllOrders()" style="border-radius: 4px;">
                        <label for="selectAllOrders" style="font-size: 14px; font-weight: 500; color: #374151;">Select All Visible Orders</label>
                    </div>
                    <div id="bulkOrdersList" style="space-y: 8px;">
                        <!-- Orders will be populated here -->
                    </div>
                </div>
                <div id="selectedOrdersCount" style="margin-top: 8px; font-size: 14px; color: #6b7280;">
                    0 orders selected
                </div>
            </div>
            
            <!-- Step 2: Choose New Status -->
            <div style="margin-bottom: 24px;">
                <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #374151;">Step 2: Choose New Status</h4>
                <select id="bulkNewStatus" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    <option value="">Select new status...</option>
                </select>
            </div>
            
            <!-- Step 3: Add Notes (Optional) -->
            <div style="margin-bottom: 24px;">
                <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #374151;">Step 3: Add Notes (Optional)</h4>
                <textarea id="bulkStatusNotes" placeholder="Reason for status change..." style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical; min-height: 80px;"></textarea>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div style="padding: 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <button onclick="closeModal('bulkStatusModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                Cancel
            </button>
            <button id="executeBulkStatusBtn" onclick="executeBulkStatusChange()" style="padding: 8px 24px; background: #7c3aed; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;" disabled>
                Update Selected Orders
            </button>
        </div>
    </div>
</div>

<!-- Bulk Rider Assignment Modal -->
<div id="bulkRiderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        
        <!-- Modal Header -->
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Bulk Rider Assignment</h3>
            <button onclick="closeModal('bulkRiderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 24px;">
            <div id="bulkRiderAlert" style="display: none; margin-bottom: 16px;"></div>
            
            <!-- Step 1: Selected Orders -->
            <div style="margin-bottom: 24px;">
                <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #374151;">Selected Orders</h4>
                <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; max-height: 300px; overflow-y: auto;">
                    <div id="bulkRiderOrdersList">
                        <!-- Orders will be populated here -->
                    </div>
                </div>
                <div id="selectedRiderOrdersCount" style="margin-top: 8px; font-size: 14px; color: #6b7280;">
                    0 orders selected
                </div>
            </div>
            
            <!-- Step 2: Choose Rider -->
            <div style="margin-bottom: 24px;">
                <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #374151;">Choose Rider</h4>
                <select id="bulkRiderSelect" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    <option value="">Loading riders...</option>
                </select>
            </div>
        </div>
        
        <!-- Modal Footer -->
        <div style="padding: 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <button onclick="closeModal('bulkRiderModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                Cancel
            </button>
            <button id="executeBulkRiderBtn" onclick="executeBulkRiderAssign()" style="padding: 8px 24px; background: #0891b2; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;" disabled>
                Assign Rider to Selected Orders
            </button>
        </div>
    </div>
</div>

<!-- Order Status History Modal -->
<div id="statusHistoryModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        
        <!-- Modal Header -->
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Order Status History</h3>
            <button onclick="closeModal('statusHistoryModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
            image.png</div>
        
        <!-- Modal Body -->
        <div style="padding: 24px;">
            <div id="statusHistoryContent">
                <div style="display: flex; align-items: center; justify-content: center; padding: 40px;">
                    <div style="display: flex; align-items: center; gap: 8px; color: #6b7280;">
                        <div style="width: 16px; height: 16px; border: 2px solid #e5e7eb; border-top: 2px solid #7c3aed; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                        Loading status history...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ============= Phase 3: Toast Notifications =============
function showToast(title, message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icon = type === 'success' 
        ? '<svg class="toast-icon text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>'
        : '<svg class="toast-icon text-red-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>';
    
    toast.innerHTML = `
        ${icon}
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ============= Phase 3: Bulk Action Bar =============
function updateBulkActionBar() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    const bar = document.getElementById('bulk-action-bar');
    const count = document.getElementById('bulk-count');
    
    if (checkboxes.length > 0) {
        count.textContent = `${checkboxes.length} selected`;
        bar.classList.add('show');
    } else {
        bar.classList.remove('show');
    }
}

// Override the existing toggleOrderSelection to also update bulk bar
const originalToggleOrderSelection = window.toggleOrderSelection;
window.toggleOrderSelection = function(orderId) {
    if (originalToggleOrderSelection) {
        originalToggleOrderSelection(orderId);
    }
    updateBulkActionBar();
};

// Override toggleAllOrdersSelection to also update bulk bar
const originalToggleAllOrdersSelection = window.toggleAllOrdersSelection;
window.toggleAllOrdersSelection = function() {
    if (originalToggleAllOrdersSelection) {
        originalToggleAllOrdersSelection();
    }
    updateBulkActionBar();
};

// Bulk action functions
function bulkAssignRider() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    if (checkboxes.length === 0) {
        showToast('No Orders Selected', 'Please select orders to assign rider', 'error');
        return;
    }
    openBulkRiderModal();
}

function bulkChangeStatus() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    if (checkboxes.length === 0) {
        showToast('No Orders Selected', 'Please select orders to change status', 'error');
        return;
    }
    openBulkStatusModal();
}

function exportSelected() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    if (checkboxes.length === 0) {
        showToast('No Orders Selected', 'Please select orders to export', 'error');
        return;
    }
    showToast('Export Started', `Exporting ${checkboxes.length} orders...`, 'success');
    // Actual export logic would go here
}

// Clear all selections
function clearAllSelections() {
    const checkboxes = document.querySelectorAll('.order-checkbox:checked');
    checkboxes.forEach(checkbox => checkbox.checked = false);
    
    const selectAll = document.getElementById('selectAllOrders');
    if (selectAll) selectAll.checked = false;
    
    // Clear the selected IDs array
    if (window.selectedOrderIds) {
        window.selectedOrderIds = [];
    }
    
    updateBulkActionBar();
    showToast('Selection Cleared', 'All orders have been deselected', 'success');
}

// Initialize bulk bar on page load
document.addEventListener('DOMContentLoaded', function() {
    updateBulkActionBar();
});

// ============================================
// Verified Location Functions (Reused from customers page)
// ============================================
let currentCustomerId = null;

function setVerifiedLocation(customerId) {
    currentCustomerId = customerId;
    document.getElementById('verifiedLocationUrl').value = '';
    document.getElementById('verifiedLocationModal').style.display = 'block';
}

function updateVerifiedLocation(customerId) {
    currentCustomerId = customerId;
    document.getElementById('verifiedLocationUrl').value = '';
    document.getElementById('verifiedLocationModal').style.display = 'block';
}

function closeVerifiedLocationModal() {
    document.getElementById('verifiedLocationModal').style.display = 'none';
    currentCustomerId = null;
}

function saveVerifiedLocation() {
    const url = document.getElementById('verifiedLocationUrl').value.trim();
    
    if (!url) {
        alert('Please enter a Google Maps URL');
        return;
    }
    
    // Show loading state
    const saveBtn = event.target;
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    saveBtn.disabled = true;
    
    fetch(`/customers/${currentCustomerId}/set-verified-location`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ url: url })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Verified location saved successfully!');
            closeVerifiedLocationModal();
            // Refresh order view if currently viewing
            if (currentOrderId && document.getElementById('viewOrderModal').style.display !== 'none') {
                viewOrderDetails(currentOrderId);
            }
        } else {
            alert('Error: ' + (data.message || 'Failed to save location'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to save location. Please try again.');
    })
    .finally(() => {
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    });
}
</script>

<!-- Verified Location Modal (Reused from customers page) -->
<div id="verifiedLocationModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
    <div style="background-color: #fefefe; margin: 10% auto; padding: 0; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 20px; border-radius: 12px 12px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 20px; font-weight: 600;">
                    <i class="fas fa-map-marker-alt"></i> Set Verified Location
                </h3>
                <button onclick="closeVerifiedLocationModal()" style="background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; line-height: 1;">&times;</button>
            </div>
        </div>
        <div style="padding: 24px;">
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #374151;">
                    <i class="fas fa-link"></i> Google Maps URL
                </label>
                <input type="text" id="verifiedLocationUrl" placeholder="https://maps.app.goo.gl/..." style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                <small style="display: block; margin-top: 8px; color: #6b7280;">
                    Paste a Google Maps link (works with any format: short links, place URLs, etc.)
                </small>
            </div>
            <div style="background-color: #eff6ff; padding: 16px; border-radius: 8px; border-left: 4px solid #3b82f6; margin-bottom: 20px;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #1e40af;">
                    <i class="fas fa-info-circle"></i> How to get the link:
                </p>
                <ol style="margin: 0; padding-left: 20px; color: #1e40af;">
                    <li>Open Google Maps</li>
                    <li>Find the location</li>
                    <li>Tap "Share" → Copy link</li>
                    <li>Paste here</li>
                </ol>
            </div>
            <div style="display: flex; gap: 12px;">
                <button onclick="closeVerifiedLocationModal()" style="flex: 1; padding: 12px; background-color: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button onclick="saveVerifiedLocation()" style="flex: 2; padding: 12px; background-color: #3b82f6; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer;">
                    <i class="fas fa-save"></i> Save Location
                </button>
            </div>
        </div>
    </div>
</div>

@endpush