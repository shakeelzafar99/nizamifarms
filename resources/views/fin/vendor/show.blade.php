@extends('layouts.app')

@section('content')
<style>
@media print {
    /* Hide everything except the report content */
    body * {
        visibility: hidden;
    }
    
    /* Show only the printable report */
    #printableVendorReport,
    #printableVendorReport * {
        visibility: visible;
    }
    
    /* Position the report at the top of the page */
    #printableVendorReport {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 20px;
    }
    
    /* Hide the modal overlay and wrapper */
    #vendorReportModal {
        background: white !important;
        backdrop-filter: none !important;
        position: static !important;
        padding: 0 !important;
    }
    
    /* Remove shadows for print but keep borders */
    #printableVendorReport {
        box-shadow: none !important;
    }
    
    /* Hide print and excel buttons when printing */
    button[onclick="printVendorReport()"],
    button[onclick="exportVendorToExcel()"] {
        display: none !important;
    }
    
    /* Ensure proper page breaks */
    .print\\:break-inside-avoid {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    
    /* Preserve table borders */
    table, th, td {
        border: 1px solid #000 !important;
    }
    
    /* Preserve background colors for headers */
    .print\\:bg-white {
        background-color: white !important;
    }
    
    .print\\:bg-purple-200 {
        background-color: #e9d5ff !important;
    }
    
    /* Preserve colors for amounts */
    .text-red-600, .text-red-700 {
        color: #dc2626 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-green-600, .text-green-700 {
        color: #16a34a !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-blue-600 {
        color: #2563eb !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-orange-600 {
        color: #ea580c !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Preserve gradient backgrounds */
    .bg-gradient-to-r {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        color-adjust: exact;
    }
    
    /* Ensure text is black for better print quality */
    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Custom page margins and settings */
    @page {
        margin: 0.5cm;
        size: A4;
    }
    
    /* Add company name to footer */
    #printableVendorReport::after {
        content: "Nizami Farms";
        display: block;
        text-align: left;
        margin-top: 20px;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
        font-size: 10pt;
        color: #666;
        visibility: visible;
    }
    
    /* Ensure content starts from top */
    html, body {
        margin: 0 !important;
        padding: 0 !important;
    }
}
</style>
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $vendor->vendor_name }}</h1>
        <a href="{{ route('fin.vendors.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Vendors
        </a>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Error Message -->
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Date Range Filter -->
    <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-3 mb-4 border border-blue-300 shadow-sm">
        <form method="GET" action="{{ route('fin.vendors.show', $vendor->id) }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-semibold text-gray-700 mb-1">From Date</label>
                <input type="date" name="date_from" value="{{ request('date_from', date('Y-m-01')) }}"
                       class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-900">
            </div>
            <div class="flex-1 min-w-[150px]">
                <label class="block text-xs font-semibold text-gray-700 mb-1">To Date</label>
                <input type="date" name="date_to" value="{{ request('date_to', date('Y-m-d')) }}"
                       class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-900">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                    🔍 Filter
                </button>
                @if(request('date_from') || request('date_to'))
                    <a href="{{ route('fin.vendors.show', $vendor->id) }}" class="px-4 py-1.5 bg-gray-300 hover:bg-gray-400 text-gray-900 text-sm font-semibold rounded shadow-sm transition-colors">
                        ✕ Clear
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Vendor Summary Cards - Horizontal Layout -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
        <!-- Card 1: Balance -->
        <div class="bg-white border {{ $summary['current_balance'] > 0 ? 'border-red-300' : 'border-gray-300' }} rounded-lg p-3 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="text-3xl flex-shrink-0">💰</div>
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-semibold text-gray-600 uppercase mb-1">Balance</div>
                    <div class="text-xl font-bold {{ $summary['current_balance'] > 0 ? 'text-red-600' : 'text-green-600' }} truncate">
                        Rs. {{ number_format($summary['current_balance'], 2) }}
                    </div>
                    @if($summary['last_payment_date'] && $summary['last_payment_amount'])
                        <div class="text-xs text-gray-500 mt-1">
                            Last: Rs. {{ number_format($summary['last_payment_amount'], 0) }} • {{ \Carbon\Carbon::parse($summary['last_payment_date'])->format('M d') }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Card 2: Purchases -->
        <div class="bg-white border border-orange-300 rounded-lg p-3 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="text-3xl flex-shrink-0">📦</div>
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-semibold text-gray-600 uppercase mb-1">
                        Purchases @if(request('date_from') || request('date_to'))(Filtered)@endif
                    </div>
                    <div class="text-xl font-bold text-orange-600 truncate">
                        Rs. {{ number_format($summary['filtered_purchases'], 0) }}
                    </div>
                    <div class="flex gap-3 text-xs text-gray-600 mt-1">
                        <span>This Week: <strong class="text-gray-800">{{ number_format($summary['purchases_this_week'], 0) }}</strong></span>
                        <span>Last Week: <strong class="text-gray-700">{{ number_format($summary['purchases_last_week'], 0) }}</strong></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 3: Payments -->
        <div class="bg-white border border-green-300 rounded-lg p-3 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="text-3xl flex-shrink-0">💵</div>
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-semibold text-gray-600 uppercase mb-1">
                        Payments @if(request('date_from') || request('date_to'))(Filtered)@endif
                    </div>
                    <div class="text-xl font-bold text-green-600 truncate">
                        Rs. {{ number_format($summary['filtered_payments'], 2) }}
                    </div>
                    @if($summary['last_five_payments']->isNotEmpty())
                        <div class="text-xs text-gray-600 mt-1">
                            Last 5: 
                            @foreach($summary['last_five_payments']->take(3) as $payment)
                                <span class="text-green-700 font-medium">{{ number_format($payment->amount, 0) }}</span>{{ !$loop->last ? ',' : '' }}
                            @endforeach
                            @if($summary['last_five_payments']->count() > 3)
                                <span class="text-gray-500">...</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-3 mb-6">
        <!-- Debug: Current method is {{ $vendor->default_purchase_method ?? 'NULL' }} -->
        @if(isset($vendor->default_purchase_method) && $vendor->default_purchase_method == 'by_weight')
            <button onclick="openWeightedPurchaseModal()" 
                    class="inline-flex items-center px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-md"
                    style="background-color: #ea580c !important; color: white !important;">
                <span style="color: white !important;">⚖️ Purchase by Weight</span>
            </button>
            <a href="{{ route('fin.vendors.products', $vendor->id) }}" 
               class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md"
               style="background-color: #2563eb !important; color: white !important;">
                <span style="color: white !important;">🛒 Manage Products</span>
            </a>
        @else
            <button onclick="openPurchaseModal()" 
                    class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md"
                    style="background-color: #dc2626 !important; color: white !important;">
                <span style="color: white !important;">📦 Record Purchase</span>
            </button>
        @endif
        <button onclick="openPaymentModal()" 
                class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md"
                style="background-color: #059669 !important; color: white !important;">
            <span style="color: white !important;">💰 Record Payment</span>
        </button>
        <button onclick="openVendorReportModal()" 
                class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md"
                style="background-color: #9333ea !important; color: white !important;">
            <span style="color: white !important;">📊 Vendor Report</span>
        </button>
    </div>

    <!-- Ledger Transactions -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-medium text-gray-900">Transaction History</h2>
            <button id="toggleExpandBtn" onclick="toggleExpandAll()" 
                    class="px-4 py-2 text-sm font-medium rounded-md transition-colors"
                    style="background-color: #6366f1; color: white;">
                <span id="toggleExpandText">{{ $expandAll ? '📕 Collapse All' : '📖 Expand All' }}</span>
            </button>
        </div>
        
        <div class="overflow-x-auto">
            @forelse($groupedTransactions as $date => $transactions)
                @php
                    $summary = $dailySummaries[$date];
                    $dateObj = $date !== 'unknown' ? \Carbon\Carbon::parse($date) : null;
                @endphp
                
                <!-- Date Group Header -->
                <div class="border-b border-gray-200 bg-gray-50 hover:bg-gray-100 cursor-pointer transition-colors" 
                     onclick="toggleDateGroup('{{ $date }}')">
                    <div class="px-6 py-3 flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl" id="icon-{{ $date }}">{{ $expandAll ? '📂' : '📁' }}</span>
                            <div>
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ $dateObj ? $dateObj->format('l, F j, Y') : 'Unknown Date' }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $summary['transaction_count'] }} transaction(s)
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-sm">
                            @if($summary['purchases'] > 0)
                                <div class="text-red-600 font-medium">
                                    📦 Rs. {{ number_format($summary['purchases'], 0) }}
                                </div>
                            @endif
                            @if($summary['payments'] > 0)
                                <div class="text-green-600 font-medium">
                                    💵 Rs. {{ number_format($summary['payments'], 0) }}
                                </div>
                            @endif
                            <div class="px-3 py-1 rounded-full text-xs font-semibold
                                {{ $summary['net'] > 0 ? 'bg-red-100 text-red-800' : ($summary['net'] < 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') }}">
                                Net: Rs. {{ number_format(abs($summary['net']), 0) }}
                                {{ $summary['net'] > 0 ? '↑' : ($summary['net'] < 0 ? '↓' : '→') }}
                            </div>
                            <div class="text-gray-700 font-bold">
                                Balance: Rs. {{ number_format($summary['end_balance'], 0) }}
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Transactions Table for this Date -->
                <div id="group-{{ $date }}" class="{{ $expandAll ? '' : 'hidden' }}">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Purchase</th>
                                <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payment</th>
                                <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                <th class="px-6 py-2 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($transactions as $transaction)
                                <tr class="hover:bg-gray-50 cursor-pointer transition-colors" onclick="viewTransactionDetails({{ $transaction->id }})">
                                    <td class="px-6 py-3 whitespace-nowrap text-xs text-gray-600">
                                        {{ $transaction->created_at ? $transaction->created_at->format('h:i A') : '-' }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            {{ $transaction->transaction_type == 'vendor_purchase' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                            {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-900">
                                        {{ $transaction->description }}
                                        @if($transaction->comments)
                                            <div class="text-xs text-gray-500">{{ $transaction->comments }}</div>
                                        @endif
                                        @if($transaction->bill_image)
                                            <div class="text-xs text-blue-600 mt-1">📎 Has Bill Image</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                        @if($transaction->transaction_type === 'vendor_purchase')
                                            Rs. {{ number_format($transaction->amount, 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                        @if($transaction->transaction_type === 'vendor_payment')
                                            Rs. {{ number_format($transaction->amount, 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-bold {{ $transaction->running_balance > 0 ? 'text-red-600' : 'text-gray-900' }}">
                                        Rs. {{ number_format($transaction->running_balance, 2) }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-center text-sm" onclick="event.stopPropagation()">
                                        <button onclick="viewTransactionDetails({{ $transaction->id }})" 
                                                class="text-blue-600 hover:text-blue-900 mr-2"
                                                title="View Details">
                                            👁️
                                        </button>
                                        <button onclick="openEditTransactionModal({{ $transaction->id }})" 
                                                class="text-indigo-600 hover:text-indigo-900 mr-2"
                                                title="Edit Transaction">
                                            ✏️
                                        </button>
                                        <button onclick="confirmDeleteTransaction({{ $transaction->id }}, '{{ $transaction->transaction_type }}', {{ $transaction->amount }})" 
                                                class="text-red-600 hover:text-red-900"
                                                title="Delete Transaction">
                                            🗑️
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <div class="px-6 py-8 text-center text-sm text-gray-500">
                    No transactions found for this vendor.
                </div>
            @endforelse
        </div>
    </div>
</div>

<!-- Record Purchase Modal - Elegant Design (Matching Weighted Purchase Style) -->
<div id="purchaseModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fecaca; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    📦
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Record Purchase</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Enter total purchase amount from vendor</p>
                </div>
            </div>
            <button type="button" onclick="closePurchaseModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form action="{{ route('fin.vendors.purchase', $vendor->id) }}" method="POST" id="purchaseForm" enctype="multipart/form-data">
                @csrf
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Date <span class="text-red-500">*</span></label>
                        <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    </div>
                    
                    <!-- Amount Field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Amount (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-lg font-semibold">
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (Optional)</label>
                        <textarea name="description" rows="2" placeholder="Add any notes about this purchase..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm"></textarea>
                    </div>
                    
                    <!-- Bill Image Upload -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Bill Image 📷</label>
                        <input type="file" name="bill_image" accept="image/*"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-red-50 file:text-red-700 hover:file:bg-red-100">
                        <p class="text-xs text-gray-500 mt-1">📸 Upload vendor's bill/receipt (optional)</p>
                    </div>
                    
                    <!-- Warning -->
                    <div style="padding: 12px; background: #fef2f2; border: 2px solid #fecaca; border-radius: 8px;">
                        <p style="font-size: 12px; color: #991b1b; font-weight: 600; margin: 0;">
                            ⚠️ This will increase the amount payable to this vendor.
                        </p>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Actions -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 20px 24px; flex-shrink: 0;">
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closePurchaseModal()" style="flex: 1; padding: 12px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" form="purchaseForm"
                        style="flex: 1; padding: 12px 16px; background: #dc2626; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Record Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Purchase by Weight Modal - Elegant Design with Line Items -->
<div id="weightedPurchaseModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1000px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fed7aa; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ⚖️
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Purchase by Weight</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Add products and quantities - totals calculate automatically</p>
                </div>
            </div>
            <button type="button" onclick="closeWeightedPurchaseModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form action="{{ route('fin.vendors.weighted-purchase', $vendor->id) }}" method="POST" id="weightedPurchaseForm" enctype="multipart/form-data">
                @csrf
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Field -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Date <span class="text-red-500">*</span></label>
                            <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        <div class="flex items-end">
                            <button type="button" onclick="addLineItem(true)" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-150 text-sm font-medium">
                                + Add Line Item
                            </button>
                        </div>
                    </div>
                    
                    <!-- Line Items Section -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Purchase Items</label>
                        <div id="lineItemsContainer" class="space-y-3">
                            <!-- Line items will be added here dynamically -->
                        </div>
                        <p class="text-xs text-gray-500 mt-2 text-center" id="emptyLineItemsMsg">Click "Add Product Line" to start adding items</p>
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (Optional)</label>
                        <textarea name="description" rows="2" placeholder="Add any notes about this purchase..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-sm"></textarea>
                    </div>
                    
                    <!-- Bill Image Upload -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Bill Image 📷</label>
                        <input type="file" name="bill_image" accept="image/*"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-sm file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-700 hover:file:bg-orange-100">
                        <p class="text-xs text-gray-500 mt-1">📸 Upload vendor's bill/receipt (optional)</p>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Total and Actions -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 20px 24px; flex-shrink: 0;">
            <!-- Total Display -->
            <div style="margin-bottom: 16px; padding: 16px; background: linear-gradient(135deg, #fed7aa 0%, #ffedd5 100%); border: 2px solid #fb923c; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 18px; font-weight: 600; color: #7c2d12;">Grand Total:</span>
                    <span style="font-size: 28px; font-weight: bold; color: #7c2d12;" id="grandTotal">Rs. 0.00</span>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closeWeightedPurchaseModal()" style="flex: 1; padding: 12px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" form="weightedPurchaseForm" id="submitWeightedPurchase"
                        style="flex: 1; padding: 12px 16px; background: #ea580c; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Record Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Modal - Elegant Design (Matching Purchase Style) -->
<div id="paymentModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #d1fae5 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #6ee7b7; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    💰
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Record Payment</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Pay vendor for outstanding purchases</p>
                </div>
            </div>
            <button type="button" onclick="closePaymentModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form action="{{ route('fin.vendors.payment', $vendor->id) }}" method="POST" id="paymentForm">
                @csrf
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Date <span class="text-red-500">*</span></label>
                        <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    </div>
                    
                    <!-- Amount Field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Amount (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" max="{{ $vendor->getBalance() }}" required placeholder="0.00"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-lg font-semibold">
                        <p class="text-xs text-gray-500 mt-1">Current payable: Rs. {{ number_format($vendor->getBalance(), 2) }}</p>
                    </div>
                    
                    <!-- Payment Source Selection -->
                    <div style="padding: 12px; background: #d1fae5; border: 2px solid #6ee7b7; border-radius: 8px;">
                        <label class="block text-sm font-medium text-gray-800 mb-2">💳 Pay From:</label>
                        <select name="payment_source_account_id" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <option value="">NF Cash (Auto-Approved)</option>
                            @php
                                // Only show Online Bank, NF Cash (Main Till), and Expense Fund
                                $paymentSources = \App\Models\FIN\AccountModel::where('is_active', 1)
                                    ->whereIn('account_code', ['ONLINE', 'NF_CASH', 'EXP_FUND'])
                                    ->orderBy('account_name')
                                    ->get();
                            @endphp
                            @foreach($paymentSources as $source)
                                <option value="{{ $source->id }}">
                                    {{ $source->account_name }} (Rs. {{ number_format($source->current_balance, 2) }})
                                </option>
                            @endforeach
                        </select>
                        <p style="font-size: 11px; color: #047857; font-weight: 600; margin: 6px 0 0 0;">
                            ⚠️ Online and Manager cash require approval
                        </p>
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (Optional)</label>
                        <textarea name="description" rows="2" placeholder="Add any notes about this payment..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm"></textarea>
                    </div>
                    
                    <!-- Warning -->
                    <div style="padding: 12px; background: #d1fae5; border: 2px solid #6ee7b7; border-radius: 8px;">
                        <p style="font-size: 12px; color: #065f46; font-weight: 600; margin: 0;">
                            ✓ This will reduce the amount payable to this vendor.
                        </p>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Actions -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 20px 24px; flex-shrink: 0;">
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closePaymentModal()" style="flex: 1; padding: 12px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" form="paymentForm"
                        style="flex: 1; padding: 12px 16px; background: #059669; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Vendor Data
const vendorName = '{{ $vendor->vendor_name }}';

// Vendor Products Data (fetched from server)
let vendorProducts = [];
let lineItemCounter = 0;

// Fetch vendor products on page load
document.addEventListener('DOMContentLoaded', function() {
    fetchVendorProducts();
});

function fetchVendorProducts() {
    fetch('{{ route('fin.vendors.products.list', $vendor->id) }}')
        .then(response => response.json())
        .then(data => {
            vendorProducts = data.products || [];
        })
        .catch(error => {
            console.error('Error fetching vendor products:', error);
            vendorProducts = [];
        });
}

function openPurchaseModal() {
    const modal = document.getElementById('purchaseModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
}

function closePurchaseModal() {
    const modal = document.getElementById('purchaseModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function openWeightedPurchaseModal() {
    const modal = document.getElementById('weightedPurchaseModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
    
    // Automatically add the first product line item with default product pre-selected
    const container = document.getElementById('lineItemsContainer');
    if (!container.children.length || container.children.length === 0) {
        addLineItem(true); // Pass true to auto-select default product
    }
}

function closeWeightedPurchaseModal() {
    const modal = document.getElementById('weightedPurchaseModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Clear line items
        document.getElementById('lineItemsContainer').innerHTML = '';
        lineItemCounter = 0;
        updateGrandTotal();
        
        // Show empty message again
        const emptyMsg = document.getElementById('emptyLineItemsMsg');
        if (emptyMsg) emptyMsg.style.display = 'block';
    }
}

function addLineItem(isInitialLoad = false) {
    lineItemCounter++;
    const container = document.getElementById('lineItemsContainer');
    const emptyMsg = document.getElementById('emptyLineItemsMsg');
    if (emptyMsg) emptyMsg.style.display = 'none';
    
    // Find default product if this is initial load or user wants default
    const defaultProduct = vendorProducts.find(p => p.is_default === 1 || p.is_default === true);
    
    // Create product options HTML
    let productOptions = '<option value="">-- Select Product --</option>';
    vendorProducts.forEach(product => {
        const isSelected = isInitialLoad && defaultProduct && product.id === defaultProduct.id ? 'selected' : '';
        productOptions += `<option value="${product.id}" data-rate="${product.rate_per_unit}" data-unit="${product.unit}" ${isSelected}>${product.product_name} (${product.unit}) - Rs. ${parseFloat(product.rate_per_unit).toFixed(2)}/${product.unit}</option>`;
    });
    
    const lineItem = document.createElement('div');
    lineItem.className = 'line-item-row bg-white p-4 rounded-lg border-2 border-gray-200 hover:border-orange-300 transition-colors duration-150';
    lineItem.id = `lineItem${lineItemCounter}`;
    lineItem.innerHTML = `
        <div class="flex items-start gap-3">
            <div class="flex-1 grid grid-cols-12 gap-3">
                <div class="col-span-6">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Product</label>
                    <select name="items[${lineItemCounter}][product_id]" onchange="updateLineItem(${lineItemCounter})" required
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        ${productOptions}
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Qty</label>
                    <input type="number" name="items[${lineItemCounter}][quantity]" step="0.001" min="0.001" required
                           onchange="updateLineItem(${lineItemCounter})" oninput="updateLineItem(${lineItemCounter})"
                           class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-right"
                           placeholder="0">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Rate</label>
                    <input type="number" name="items[${lineItemCounter}][rate]" step="0.01" min="0.01" readonly
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-gray-50 text-right"
                           placeholder="0.00">
                </div>
                <div class="col-span-2">
                    <label class="text-xs font-medium text-gray-600 mb-1 block">Total</label>
                    <input type="number" id="lineTotal${lineItemCounter}" readonly
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg bg-gradient-to-r from-orange-50 to-white font-semibold text-right text-orange-700"
                           placeholder="0.00">
                </div>
            </div>
            <div class="flex-shrink-0" style="padding-top: 22px;">
                <button type="button" onclick="removeLineItem(${lineItemCounter})"
                        class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors duration-150" title="Remove item">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        <input type="hidden" name="items[${lineItemCounter}][unit]" value="">
        <input type="hidden" name="items[${lineItemCounter}][product_name]" value="">
    `;
    
    // Insert at the beginning instead of end for easier access
    container.insertBefore(lineItem, container.firstChild);
}

function updateLineItem(id) {
    const row = document.getElementById(`lineItem${id}`);
    if (!row) return;
    
    const productSelect = row.querySelector('select[name*="[product_id]"]');
    const quantityInput = row.querySelector('input[name*="[quantity]"]');
    const rateInput = row.querySelector('input[name*="[rate]"]');
    const totalInput = row.querySelector(`#lineTotal${id}`);
    const unitInput = row.querySelector('input[name*="[unit]"]');
    const nameInput = row.querySelector('input[name*="[product_name]"]');
    
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value) {
        const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
        const unit = selectedOption.getAttribute('data-unit') || '';
        const productName = selectedOption.text.split(' (')[0]; // Extract product name
        
        rateInput.value = rate.toFixed(2);
        unitInput.value = unit;
        nameInput.value = productName;
        
        const quantity = parseFloat(quantityInput.value) || 0;
        const lineTotal = quantity * rate;
        
        totalInput.value = lineTotal.toFixed(2);
    } else {
        rateInput.value = '';
        totalInput.value = '';
        unitInput.value = '';
        nameInput.value = '';
    }
    
    updateGrandTotal();
}

function removeLineItem(id) {
    const row = document.getElementById(`lineItem${id}`);
    if (row) {
        row.remove();
        updateGrandTotal();
        
        // Show empty message if no items left
        const container = document.getElementById('lineItemsContainer');
        const emptyMsg = document.getElementById('emptyLineItemsMsg');
        if (container.querySelectorAll('.line-item-row').length === 0 && emptyMsg) {
            emptyMsg.style.display = 'block';
        }
    }
}

function updateGrandTotal() {
    let total = 0;
    document.querySelectorAll('[id^="lineTotal"]').forEach(input => {
        const value = parseFloat(input.value) || 0;
        total += value;
    });
    
    document.getElementById('grandTotal').textContent = `Rs. ${total.toFixed(2)}`;
    
    // Update submit button state
    const submitBtn = document.getElementById('submitWeightedPurchase');
    if (total > 0 && document.querySelectorAll('.line-item-row').length > 0) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
    } else {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
    }
}

function openPaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
}

function closePaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    const purchaseModal = document.getElementById('purchaseModal');
    const paymentModal = document.getElementById('paymentModal');
    const weightedPurchaseModal = document.getElementById('weightedPurchaseModal');
    
    if (event.target === purchaseModal) {
        closePurchaseModal();
    }
    if (event.target === paymentModal) {
        closePaymentModal();
    }
    if (event.target === weightedPurchaseModal) {
        closeWeightedPurchaseModal();
    }
});

// View Transaction Details
function viewTransactionDetails(transactionId) {
    fetch(`/finance/ledger/transaction/${transactionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showTransactionModal(data.transaction);
            } else {
                alert('Error loading transaction details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading transaction details');
        });
}

function showTransactionModal(transaction) {
    console.log('Transaction data received:', transaction); // DEBUG
    console.log('Bill image path:', transaction.bill_image); // DEBUG
    
    const modal = document.getElementById('transactionDetailsModal');
    const content = document.getElementById('transactionDetailsContent');
    const footer = document.getElementById('transactionDetailsFooter');
    
    let html = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Date</label>
                <p style="font-size: 14px; color: #111827;">${transaction.transaction_date || '-'}</p>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Type</label>
                <p style="font-size: 14px; color: #111827;">${transaction.transaction_type || '-'}</p>
            </div>
            <div style="grid-column: 1 / -1;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Description</label>
                <p style="font-size: 14px; color: #111827;">${transaction.description || '-'}</p>
            </div>
            <div>
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">Amount</label>
                <p style="font-size: 18px; font-weight: 700; color: #111827;">Rs. ${parseFloat(transaction.amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
            </div>
        </div>
    `;
    
    // Show line items if available
    if (transaction.line_items && transaction.line_items.length > 0) {
        html += `
            <div style="margin-top: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">📦 Purchase Items</label>
                <div style="border: 2px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                    <table style="width: 100%; font-size: 13px;">
                        <thead style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                            <tr>
                                <th style="padding: 8px; text-align: left; font-weight: 600; color: #374151;">Product</th>
                                <th style="padding: 8px; text-align: right; font-weight: 600; color: #374151;">Qty</th>
                                <th style="padding: 8px; text-align: right; font-weight: 600; color: #374151;">Rate</th>
                                <th style="padding: 8px; text-align: right; font-weight: 600; color: #374151;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        transaction.line_items.forEach(item => {
            html += `
                <tr style="border-bottom: 1px solid #f3f4f6;">
                    <td style="padding: 8px; color: #111827;">${item.product_name}</td>
                    <td style="padding: 8px; text-align: right; color: #6b7280;">${item.quantity} ${item.unit}</td>
                    <td style="padding: 8px; text-align: right; color: #6b7280;">Rs. ${parseFloat(item.rate_per_unit).toFixed(2)}</td>
                    <td style="padding: 8px; text-align: right; font-weight: 600; color: #111827;">Rs. ${parseFloat(item.line_total).toFixed(2)}</td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }
    
    if (transaction.bill_image && transaction.bill_image !== '' && transaction.bill_image !== null) {
        console.log('Displaying bill image:', transaction.bill_image); // DEBUG
        // Try /storage/ first, fallback to /public-storage/ if it fails
        const primaryUrl = `/storage/${transaction.bill_image}`;
        const fallbackUrl = `/public-storage/${transaction.bill_image}`;
        html += `
            <div style="margin-top: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">📎 Bill Image</label>
                <div style="border: 2px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #f9fafb;">
                    <img src="${primaryUrl}" 
                         alt="Bill Image" 
                         style="width: 100%; max-height: 400px; object-fit: contain; border-radius: 4px; cursor: pointer;"
                         onclick="window.open(this.src, '_blank')"
                         onerror="if (this.src !== '${fallbackUrl}') { this.src = '${fallbackUrl}'; } else { console.error('Failed to load image'); this.parentElement.innerHTML = '<p style=\\'color: #ef4444; text-align: center;\\'>Failed to load image</p>'; }">
                    <p style="text-align: center; font-size: 11px; color: #6b7280; margin-top: 4px;">Click image to view full size</p>
                </div>
            </div>
        `;
    } else {
        console.log('No bill image to display'); // DEBUG
    }
    
    content.innerHTML = html;
    
    // Update footer with Edit button
    footer.innerHTML = `
        <button type="button" onclick="openEditTransactionModal(${transaction.id})" 
                style="padding: 10px 24px; border: 2px solid #3b82f6; background: white; color: #3b82f6; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s;">
            ✏️ Edit
        </button>
        <button type="button" onclick="closeTransactionModal()" 
                style="padding: 10px 24px; border: none; background: #3b82f6; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            Close
        </button>
    `;
    
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeTransactionModal() {
    const modal = document.getElementById('transactionDetailsModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

// Edit Transaction Functions
function openEditTransactionModal(transactionId) {
    // Close the view modal first
    closeTransactionModal();
    
    // Fetch transaction details
    fetch(`/finance/ledger/transaction/${transactionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const transaction = data.transaction;
                
                // Check if this is a weighted purchase (has line items)
                if (transaction.line_items && transaction.line_items.length > 0) {
                    // Open weighted purchase edit modal
                    openWeightedPurchaseEditModal(transaction);
                    return;
                }
                
                // Populate form for simple transactions
                document.getElementById('edit_transaction_id').value = transaction.id;
                document.getElementById('edit_transaction_date').value = transaction.transaction_date.split(' ')[0];
                document.getElementById('edit_amount').value = transaction.amount;
                document.getElementById('edit_description').value = transaction.description || '';
                
                // Handle existing bill image
                if (transaction.bill_image) {
                    document.getElementById('currentImageSection').style.display = 'block';
                    document.getElementById('currentBillImage').src = '/storage/' + transaction.bill_image;
                    document.getElementById('billImageLabel').textContent = 'Replace Bill Image 📷';
                    document.getElementById('billImageHint').textContent = 'Upload a new image to replace the current one (optional)';
                } else {
                    document.getElementById('currentImageSection').style.display = 'none';
                    document.getElementById('billImageLabel').textContent = 'Bill Image 📷';
                    document.getElementById('billImageHint').textContent = 'Upload vendor\'s bill/receipt (optional)';
                }
                
                // Show modal
                const modal = document.getElementById('editTransactionModal');
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
            } else {
                alert('Error loading transaction details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading transaction details');
        });
}

// Edit Weighted Purchase Modal
function openWeightedPurchaseEditModal(transaction) {
    console.log('Opening weighted purchase edit modal with transaction:', transaction);
    
    // Populate edit form with existing data
    document.getElementById('edit_weighted_transaction_id').value = transaction.id;
    
    // Set date directly (backend now returns Y-m-d format)
    document.getElementById('edit_weighted_date').value = transaction.transaction_date;
    document.getElementById('edit_weighted_description').value = transaction.description || '';
    
    // Clear and populate line items
    const container = document.getElementById('editLineItemsContainer');
    container.innerHTML = '';
    
    let editLineItemCounter = 0;
    transaction.line_items.forEach((item, index) => {
        console.log('Adding line item:', item);
        addEditLineItem(item, editLineItemCounter++);
    });
    
    // Update grand total after a short delay to ensure DOM is ready
    setTimeout(() => {
        updateEditGrandTotal();
    }, 100);
    
    // Show modal
    const modal = document.getElementById('editWeightedPurchaseModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeEditWeightedPurchaseModal() {
    const modal = document.getElementById('editWeightedPurchaseModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('editLineItemsContainer').innerHTML = '';
}

function addEditLineItem(existingItem = null, index = null, isInitialLoad = false) {
    const container = document.getElementById('editLineItemsContainer');
    const itemIndex = index !== null ? index : Date.now();
    
    console.log('addEditLineItem called with:', {existingItem, index, itemIndex, isInitialLoad});
    
    // Find default product if this is initial load and no existing item
    const defaultProduct = !existingItem && isInitialLoad ? vendorProducts.find(p => p.is_default === 1 || p.is_default === true) : null;
    
    // Build product options HTML
    let productOptionsHtml = '<option value="">Select Product</option>';
    vendorProducts.forEach(p => {
        let selected = '';
        if (existingItem && existingItem.vendor_product_id == p.id) {
            selected = 'selected';
        } else if (defaultProduct && p.id === defaultProduct.id) {
            selected = 'selected';
        }
        console.log(`Product ${p.product_name}: existingItem.vendor_product_id=${existingItem?.vendor_product_id}, p.id=${p.id}, selected=${selected}`);
        productOptionsHtml += `<option value="${p.id}" data-unit="${p.unit}" data-rate="${p.rate_per_unit}" data-name="${p.product_name}" ${selected}>${p.product_name}</option>`;
    });
    
    // Use default product values if available and no existing item
    const itemData = existingItem || (defaultProduct ? {
        product_name: defaultProduct.product_name,
        quantity: '',
        unit: defaultProduct.unit,
        rate_per_unit: defaultProduct.rate_per_unit,
        line_total: 0
    } : {});
    
    const itemHtml = `
        <div class="edit-line-item border border-gray-300 rounded-lg p-3 bg-gray-50" data-index="${itemIndex}">
            <div class="grid grid-cols-12 gap-2 items-end">
                <div class="col-span-4">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Product</label>
                    <select name="items[${itemIndex}][product_id]" required onchange="updateEditProductDetails(${itemIndex})" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                        ${productOptionsHtml}
                    </select>
                    <input type="hidden" name="items[${itemIndex}][product_name]" value="${itemData.product_name || ''}">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Qty</label>
                    <input type="number" name="items[${itemIndex}][quantity]" step="0.001" min="0.001" value="${itemData.quantity || ''}" required onchange="calculateEditLineTotal(${itemIndex})" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Unit</label>
                    <input type="text" name="items[${itemIndex}][unit]" value="${itemData.unit || ''}" required readonly class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded bg-gray-100">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Rate</label>
                    <input type="number" name="items[${itemIndex}][rate]" step="0.01" min="0.01" value="${itemData.rate_per_unit || ''}" required onchange="calculateEditLineTotal(${itemIndex})" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-orange-500">
                </div>
                <div class="col-span-1">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Total</label>
                    <input type="text" readonly class="line-total w-full px-2 py-1.5 text-sm border border-gray-300 rounded bg-gray-100 font-semibold" value="${itemData.line_total ? parseFloat(itemData.line_total).toFixed(2) : '0.00'}">
                </div>
                <div class="col-span-1 flex items-end justify-center">
                    <button type="button" onclick="removeEditLineItem(${itemIndex})" class="px-2 py-1.5 text-red-600 hover:text-red-800 text-lg font-bold">×</button>
                </div>
            </div>
        </div>
    `;
    
    // Insert at the beginning instead of end for easier access
    container.insertAdjacentHTML('afterbegin', itemHtml);
}

function updateEditProductDetails(index) {
    const item = document.querySelector(`.edit-line-item[data-index="${index}"]`);
    const select = item.querySelector('select');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        item.querySelector('input[name*="[unit]"]').value = selectedOption.dataset.unit || '';
        item.querySelector('input[name*="[rate]"]').value = selectedOption.dataset.rate || '';
        item.querySelector('input[name*="[product_name]"]').value = selectedOption.dataset.name || selectedOption.text;
        calculateEditLineTotal(index);
    }
}

function calculateEditLineTotal(index) {
    const item = document.querySelector(`.edit-line-item[data-index="${index}"]`);
    const qty = parseFloat(item.querySelector('input[name*="[quantity]"]').value) || 0;
    const rate = parseFloat(item.querySelector('input[name*="[rate]"]').value) || 0;
    const total = qty * rate;
    
    item.querySelector('.line-total').value = total.toFixed(2);
    updateEditGrandTotal();
}

function removeEditLineItem(index) {
    document.querySelector(`.edit-line-item[data-index="${index}"]`).remove();
    updateEditGrandTotal();
}

function updateEditGrandTotal() {
    let total = 0;
    document.querySelectorAll('#editLineItemsContainer .line-total').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    document.getElementById('editGrandTotal').textContent = 'Rs. ' + total.toFixed(2);
}

function submitEditWeightedPurchase() {
    const form = document.getElementById('editWeightedPurchaseForm');
    const formData = new FormData(form);
    const transactionId = document.getElementById('edit_weighted_transaction_id').value;
    
    // Validate that we have at least one line item
    const lineItems = document.querySelectorAll('.edit-line-item');
    if (lineItems.length === 0) {
        alert('Please add at least one product line item');
        return;
    }
    
    fetch(`/finance/vendors/transaction/${transactionId}/update`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeEditWeightedPurchaseModal();
            // Force a full page reload to refresh all data including balance cards
            window.location.reload(true);
        } else {
            alert('Error: ' + (data.message || 'Failed to update transaction'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating transaction');
    });
}

function closeEditTransactionModal() {
    const modal = document.getElementById('editTransactionModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    
    // Reset form
    document.getElementById('editTransactionForm').reset();
}

function submitEditTransaction() {
    const transactionId = document.getElementById('edit_transaction_id').value;
    const form = document.getElementById('editTransactionForm');
    const formData = new FormData(form);
    
    fetch(`/finance/vendors/transaction/${transactionId}/update`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeEditTransactionModal();
            // Force a full page reload to refresh all data including balance cards
            window.location.reload(true);
        } else {
            alert('Error: ' + (data.message || 'Failed to update transaction'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating transaction');
    });
}

// Delete Transaction Function
function confirmDeleteTransaction(transactionId, transactionType, amount) {
    const typeName = transactionType === 'vendor_purchase' ? 'Purchase' : 'Payment';
    const message = `Are you sure you want to delete this ${typeName} of Rs. ${parseFloat(amount).toFixed(2)}?\n\nThis will:\n- Remove the transaction from ledger\n- Reverse the account balances\n- Delete any associated line items\n\nThis action cannot be undone!`;
    
    if (confirm(message)) {
        // Show loading state
        const deleteBtn = event.target;
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '⏳';
        deleteBtn.disabled = true;
        
        fetch(`/finance/vendors/transaction/${transactionId}/delete`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Transaction deleted successfully!');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to delete transaction'));
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting transaction');
            deleteBtn.innerHTML = originalText;
            deleteBtn.disabled = false;
        });
    }
}

// ===== DATE GROUPING EXPAND/COLLAPSE FUNCTIONS =====
let expandAllState = {{ $expandAll ? 'true' : 'false' }};

function toggleDateGroup(date) {
    const group = document.getElementById('group-' + date);
    const icon = document.getElementById('icon-' + date);
    
    if (group.classList.contains('hidden')) {
        group.classList.remove('hidden');
        icon.textContent = '📂';
    } else {
        group.classList.add('hidden');
        icon.textContent = '📁';
    }
}

function toggleExpandAll() {
    expandAllState = !expandAllState;
    
    // Update UI
    const allGroups = document.querySelectorAll('[id^="group-"]');
    const allIcons = document.querySelectorAll('[id^="icon-"]');
    const toggleText = document.getElementById('toggleExpandText');
    
    allGroups.forEach(group => {
        if (expandAllState) {
            group.classList.remove('hidden');
        } else {
            group.classList.add('hidden');
        }
    });
    
    allIcons.forEach(icon => {
        icon.textContent = expandAllState ? '📂' : '📁';
    });
    
    toggleText.textContent = expandAllState ? '📕 Collapse All' : '📖 Expand All';
    
    // Save preference to session
    fetch('{{ route('fin.vendors.toggle-expand') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            expand_all: expandAllState
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('Expand preference saved:', data);
    })
    .catch(error => {
        console.error('Error saving preference:', error);
    });
}
</script>

<!-- Transaction Details Modal -->
<div id="transactionDetailsModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px;" onclick="closeTransactionModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 700px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #dbeafe 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #93c5fd; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    📄
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Transaction Details</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">View complete transaction information</p>
                </div>
            </div>
            <button type="button" onclick="closeTransactionModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div id="transactionDetailsContent" style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <!-- Fixed Footer -->
        <div id="transactionDetailsFooter" style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; justify-content: center; gap: 12px;">
            <button type="button" onclick="closeTransactionModal()" 
                    style="padding: 10px 24px; border: none; background: #3b82f6; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Edit Transaction Modal -->
<div id="editTransactionModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px;" onclick="closeEditTransactionModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fef3c7 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fde68a; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ✏️
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Edit Transaction</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Update transaction details</p>
                </div>
            </div>
            <button type="button" onclick="closeEditTransactionModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form id="editTransactionForm" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" id="edit_transaction_id" name="transaction_id">
                
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Date <span class="text-red-600">*</span></label>
                        <input type="date" id="edit_transaction_date" name="transaction_date" required
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Amount (Rs.) <span class="text-red-600">*</span></label>
                        <input type="number" id="edit_amount" name="amount" step="0.01" min="0.01" required
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Description</label>
                        <textarea id="edit_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900"></textarea>
                    </div>
                    
                    <div id="currentImageSection" style="display: none;">
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Current Bill Image</label>
                        <div style="border: 2px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #f9fafb;">
                            <img id="currentBillImage" src="" alt="Current Bill" 
                                 style="width: 100%; max-height: 200px; object-fit: contain; border-radius: 4px; cursor: pointer;"
                                 onclick="window.open(this.src, '_blank')">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">
                            <span id="billImageLabel">Bill Image 📷</span>
                        </label>
                        <input type="file" id="edit_bill_image" name="bill_image" accept="image/*"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-yellow-50 file:text-yellow-700 hover:file:bg-yellow-100">
                        <p class="text-xs text-gray-600 mt-1">📸 <span id="billImageHint">Upload vendor's bill/receipt (optional)</span></p>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; gap: 12px;">
            <button type="button" onclick="closeEditTransactionModal()" 
                    style="flex: 1; padding: 10px 16px; border: 2px solid #d1d5db; background: white; color: #374151; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s;">
                Cancel
            </button>
            <button type="button" onclick="submitEditTransaction()"
                    style="flex: 1; padding: 10px 16px; border: none; background: #f59e0b; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                ✓ Update Transaction
            </button>
        </div>
    </div>
</div>

<!-- Edit Weighted Purchase Modal -->
<div id="editWeightedPurchaseModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1000px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fff7ed 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fed7aa; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ✏️
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Edit Weighted Purchase</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Modify products, quantities, and rates</p>
                </div>
            </div>
            <button type="button" onclick="closeEditWeightedPurchaseModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form id="editWeightedPurchaseForm" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" id="edit_weighted_transaction_id" name="transaction_id">
                
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Date Field -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Date <span class="text-red-500">*</span></label>
                            <input type="date" id="edit_weighted_date" name="transaction_date" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        <div class="flex items-end">
                            <button type="button" onclick="addEditLineItem(null, null, true)" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-150 text-sm font-medium">
                                + Add Line Item
                            </button>
                        </div>
                    </div>
                    
                    <!-- Line Items Section -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Purchase Items</label>
                        <div id="editLineItemsContainer" class="space-y-3">
                            <!-- Line items will be added here dynamically -->
                        </div>
                    </div>
                    
                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Description (Optional)</label>
                        <textarea id="edit_weighted_description" name="description" rows="2" placeholder="Add any notes about this purchase..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-transparent text-sm"></textarea>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer with Total and Actions -->
        <div style="border-top: 1px solid #e5e7eb; background: #f9fafb; padding: 20px 24px; flex-shrink: 0;">
            <!-- Total Display -->
            <div style="margin-bottom: 16px; padding: 16px; background: linear-gradient(135deg, #fed7aa 0%, #ffedd5 100%); border: 2px solid #fb923c; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 18px; font-weight: 600; color: #7c2d12;">Grand Total:</span>
                    <span style="font-size: 28px; font-weight: bold; color: #7c2d12;" id="editGrandTotal">Rs. 0.00</span>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closeEditWeightedPurchaseModal()" style="flex: 1; padding: 12px 16px; border: 1px solid #d1d5db; background: white; color: #374151; font-weight: 500; border-radius: 8px; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="button" onclick="submitEditWeightedPurchase()"
                        style="flex: 1; padding: 12px 16px; background: #ea580c; color: white; font-weight: 500; border-radius: 8px; cursor: pointer; border: none; font-size: 14px;">
                    ✓ Update Purchase
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Vendor Report Modal (Pre-filtered for this vendor) -->
<div id="vendorReportModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;" onclick="if(event.target === this) closeVendorReportModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1400px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #f3e8ff 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #c084fc; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    📊
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">{{ $vendor->vendor_name }} - Report</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">View detailed purchase and payment summary</p>
                </div>
            </div>
            <button type="button" onclick="closeVendorReportModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <!-- Report Filters - Compact Design -->
            <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-3 border border-purple-300 mb-4 shadow-sm">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">From <span class="text-red-600">*</span></label>
                        <input type="date" id="vendor_report_date_from" value="{{ date('Y-m-01') }}"
                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500 text-gray-900">
                    </div>
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">To <span class="text-red-600">*</span></label>
                        <input type="date" id="vendor_report_date_to" value="{{ date('Y-m-d') }}"
                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500 text-gray-900">
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded cursor-pointer hover:bg-gray-50 transition-colors">
                            <input type="checkbox" id="vendor_show_payments" checked class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500 focus:ring-2">
                            <span class="ml-2 text-sm font-medium text-gray-700">Show Payments</span>
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="generateVendorReport()" 
                                class="px-4 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            🔍 Generate
                        </button>
                        <button onclick="printVendorReport()" id="vendorPrintBtn" style="display: none;"
                                class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            🖨️ Print
                        </button>
                        <button onclick="exportVendorToExcel()" id="vendorExcelBtn" style="display: none;"
                                class="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            📊 Excel
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Report Content -->
            <div id="vendorReportContent" style="display: none;">
                <!-- Report will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
// Vendor Report Functions
function openVendorReportModal() {
    const modal = document.getElementById('vendorReportModal');
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    document.body.style.overflow = 'hidden';
}

function closeVendorReportModal() {
    const modal = document.getElementById('vendorReportModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

function generateVendorReport() {
    const dateFrom = document.getElementById('vendor_report_date_from').value;
    const dateTo = document.getElementById('vendor_report_date_to').value;
    const showPayments = document.getElementById('vendor_show_payments').checked;
    
    if (!dateFrom || !dateTo) {
        alert('Please select both From and To dates');
        return;
    }
    
    // Show loading
    const reportContent = document.getElementById('vendorReportContent');
    reportContent.style.display = 'block';
    reportContent.innerHTML = '<div class="text-center py-8"><div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600"></div><p class="mt-4 text-gray-600">Generating report...</p></div>';
    
    // Fetch report data (pre-filtered for this vendor)
    fetch(`/finance/vendors/report?vendor_id={{ $vendor->id }}&date_from=${dateFrom}&date_to=${dateTo}&show_payments=${showPayments ? 1 : 0}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayVendorReport(data.report);
                document.getElementById('vendorPrintBtn').style.display = 'inline-block';
                document.getElementById('vendorExcelBtn').style.display = 'inline-block';
            } else {
                reportContent.innerHTML = '<div class="text-center py-8 text-red-600">Error generating report</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            reportContent.innerHTML = '<div class="text-center py-8 text-red-600">Error generating report</div>';
        });
}

function displayVendorReport(report) {
    const reportContent = document.getElementById('vendorReportContent');
    const showPayments = document.getElementById('vendor_show_payments').checked;
    
    // Extract vendor data (should be only one vendor since we're filtering by vendor_id)
    const vendor = report.vendors && report.vendors.length > 0 ? report.vendors[0] : null;
    
    if (!vendor) {
        reportContent.innerHTML = '<div class="text-center py-8 text-red-600">No data found for this period</div>';
        return;
    }
    
    let html = `
        <div id="printableVendorReport" class="bg-white">
            <!-- Vendor Name Header for Print -->
            <div style="text-align: center; margin-bottom: 20px; padding: 20px 0; border-bottom: 3px solid #7c3aed;">
                <h1 style="font-size: 28px; font-weight: bold; color: #111827; margin: 0 0 8px 0;">${vendorName}</h1>
                <p style="font-size: 14px; color: #6b7280; margin: 0;">Report Period: ${report.date_from} to ${report.date_to}</p>
            </div>
            
            <div style="padding: 0 20px;">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-purple-100 print:bg-purple-200">
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold text-gray-700">Date</th>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold text-gray-700">Type</th>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold text-gray-700">Details</th>
                                <th class="border border-gray-300 px-3 py-2 text-right font-semibold text-gray-700">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
    `;
    
    // Loop through each day and transaction
    vendor.daily_summary.forEach(day => {
        let dayRowSpan = 0;
        
        // Count total rows for this day (including line items)
        day.transactions.forEach(txn => {
            if (txn.line_items && txn.line_items.length > 0) {
                dayRowSpan += txn.line_items.length;
            } else {
                dayRowSpan += 1;
            }
        });
        
        let isFirstRowOfDay = true;
        
        day.transactions.forEach(txn => {
            const isPayment = txn.type === 'payment';
            const amountColor = isPayment ? 'text-green-700' : 'text-red-700';
            const bgColor = isPayment ? 'bg-green-50' : 'bg-red-50';
            
            if (txn.line_items && txn.line_items.length > 0) {
                // Weighted purchase with line items
                txn.line_items.forEach((item, itemIndex) => {
                    html += `<tr class="${bgColor} print:bg-white">`;
                    
                    // Date column (only on first row of the day)
                    if (isFirstRowOfDay) {
                        html += `<td class="border border-gray-300 px-3 py-2 align-top font-medium text-gray-900" rowspan="${dayRowSpan}">${day.date}</td>`;
                        isFirstRowOfDay = false;
                    }
                    
                    // Type column (only on first line item)
                    if (itemIndex === 0) {
                        html += `
                            <td class="border border-gray-300 px-3 py-2 align-top" rowspan="${txn.line_items.length}">
                                <div class="font-medium ${amountColor}">${isPayment ? '💰 Payment' : '📦 Purchase'}</div>
                                <div class="text-xs text-gray-500 mt-0.5">${txn.transaction_id}</div>
                                ${txn.description ? `<div class="text-xs text-gray-600 mt-1">${txn.description}</div>` : ''}
                                ${isPayment && txn.payment_mode ? `<div class="text-xs text-blue-600 mt-1">${txn.payment_mode}</div>` : ''}
                            </td>
                        `;
                    }
                    
                    // Details column (product details)
                    html += `
                        <td class="border border-gray-300 px-3 py-2">
                            <div class="flex justify-between items-center">
                                <span class="font-medium text-gray-800">${item.product_name}</span>
                                <span class="text-gray-600 ml-4">${item.quantity} ${item.unit} × Rs. ${Number(item.rate_per_unit).toLocaleString('en-PK', {minimumFractionDigits: 2})}</span>
                            </div>
                        </td>
                    `;
                    
                    // Amount column (only on first line item, show total)
                    if (itemIndex === 0) {
                        html += `
                            <td class="border border-gray-300 px-3 py-2 text-right align-top font-bold ${amountColor}" rowspan="${txn.line_items.length}">
                                Rs. ${Number(txn.amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                            </td>
                        `;
                    }
                    
                    html += `</tr>`;
                });
            } else {
                // Simple transaction (payment or flat purchase)
                html += `<tr class="${bgColor} print:bg-white">`;
                
                // Date column (only on first row of the day)
                if (isFirstRowOfDay) {
                    html += `<td class="border border-gray-300 px-3 py-2 align-top font-medium text-gray-900" rowspan="${dayRowSpan}">${day.date}</td>`;
                    isFirstRowOfDay = false;
                }
                
                // Type column
                html += `
                    <td class="border border-gray-300 px-3 py-2">
                        <div class="font-medium ${amountColor}">${isPayment ? '💰 Payment' : '📦 Purchase'}</div>
                        <div class="text-xs text-gray-500 mt-0.5">${txn.transaction_id}</div>
                        ${isPayment && txn.payment_mode ? `<div class="text-xs text-blue-600 mt-1">${txn.payment_mode}</div>` : ''}
                    </td>
                `;
                
                // Details column
                html += `
                    <td class="border border-gray-300 px-3 py-2">
                        <div class="text-gray-700">${txn.description || '-'}</div>
                    </td>
                `;
                
                // Amount column
                html += `
                    <td class="border border-gray-300 px-3 py-2 text-right font-bold ${amountColor}">
                        Rs. ${Number(txn.amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                    </td>
                `;
                
                html += `</tr>`;
            }
        });
    });
    
    // Calculate payment mode totals
    let vendorTotalPaymentsOnline = 0;
    let vendorTotalPaymentsCash = 0;
    vendor.daily_summary.forEach(day => {
        vendorTotalPaymentsOnline += day.total_payments_online || 0;
        vendorTotalPaymentsCash += day.total_payments_cash || 0;
    });
    
    // Vendor Summary Row
    html += `
                            <tr class="bg-purple-100 font-bold print:bg-purple-200">
                                <td colspan="3" class="border border-gray-300 px-3 py-2 text-right">
                                    <span class="text-gray-700">Vendor Total:</span>
                                    <span class="text-red-600 ml-4">Purchases: Rs. ${Number(vendor.total_purchases).toLocaleString('en-PK', {minimumFractionDigits: 2})}</span>
                                    ${showPayments ? `
                                        <span class="text-green-600 ml-4">Payments: Rs. ${Number(vendor.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2})}</span>
                                        ${vendorTotalPaymentsOnline > 0 ? `<span class="text-blue-600 ml-2 text-xs">(Online: Rs. ${Number(vendorTotalPaymentsOnline).toLocaleString('en-PK', {minimumFractionDigits: 2})})</span>` : ''}
                                        ${vendorTotalPaymentsCash > 0 ? `<span class="text-orange-600 ml-2 text-xs">(Cash: Rs. ${Number(vendorTotalPaymentsCash).toLocaleString('en-PK', {minimumFractionDigits: 2})})</span>` : ''}
                                    ` : ''}
                                </td>
                                <td class="border border-gray-300 px-3 py-2 text-right ${(vendor.total_purchases - vendor.total_payments) > 0 ? 'text-red-600' : 'text-green-600'}">
                                    Rs. ${Number(vendor.total_purchases - vendor.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
    
    reportContent.innerHTML = html;
}

function printVendorReport() {
    // Show instructions for better print quality
    const userConfirmed = confirm(
        'Print Tips:\n\n' +
        '1. In the print dialog, click "More settings"\n' +
        '2. Uncheck "Headers and footers" to hide the URL\n' +
        '3. Set margins to "None" or "Minimum" for best results\n\n' +
        'Click OK to continue to print dialog.'
    );
    
    if (userConfirmed) {
        window.print();
    }
}

function exportVendorToExcel() {
    const dateFrom = document.getElementById('vendor_report_date_from').value;
    const dateTo = document.getElementById('vendor_report_date_to').value;
    const showPayments = document.getElementById('vendor_show_payments').checked;
    
    window.location.href = `/finance/vendors/report/export?vendor_id={{ $vendor->id }}&date_from=${dateFrom}&date_to=${dateTo}&show_payments=${showPayments ? 1 : 0}`;
}
</script>

@endsection

