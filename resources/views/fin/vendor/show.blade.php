@extends('layouts.app')

@section('content')
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
    </div>

    <!-- Ledger Transactions -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Transaction History</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Purchase</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($ledgerWithBalance as $transaction)
                        <tr class="hover:bg-gray-100 cursor-pointer transition-colors" onclick="viewTransactionDetails({{ $transaction->id }})">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->transaction_date ? $transaction->transaction_date->format('M j, Y') : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                    {{ $transaction->transaction_type == 'vendor_purchase' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->description }}
                                @if($transaction->comments)
                                    <div class="text-xs text-gray-500">{{ $transaction->comments }}</div>
                                @endif
                                @if($transaction->bill_image)
                                    <div class="text-xs text-blue-600 mt-1">📎 Has Bill Image</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                @if($transaction->to_account_id === $vendor->account->id)
                                    Rs. {{ number_format($transaction->amount, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                @if($transaction->from_account_id === $vendor->account->id)
                                    Rs. {{ number_format($transaction->amount, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold {{ $transaction->running_balance > 0 ? 'text-red-600' : 'text-gray-900' }}">
                                Rs. {{ number_format($transaction->running_balance, 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm" onclick="event.stopPropagation()">
                                <button onclick="viewTransactionDetails({{ $transaction->id }})" 
                                        class="text-blue-600 hover:text-blue-900 mr-2"
                                        title="View Details">
                                    👁️
                                </button>
                                <button onclick="openEditTransactionModal({{ $transaction->id }})" 
                                        class="text-indigo-600 hover:text-indigo-900"
                                        title="Edit Transaction">
                                    ✏️
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                No transactions found for this vendor.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Record Purchase Modal - Modern Design -->
<div id="purchaseModal" class="hidden fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center p-4 overflow-y-auto" style="z-index: 9999;">
    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full my-8 border-4 border-red-500" onclick="event.stopPropagation()">
        <div class="p-6 max-h-[85vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">📦 Record Purchase</h3>
                <button onclick="closePurchaseModal()" class="text-gray-500 hover:text-gray-700 text-2xl leading-none font-bold">&times;</button>
            </div>
            <form action="{{ route('fin.vendors.purchase', $vendor->id) }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Date <span class="text-red-600">*</span></label>
                        <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 text-gray-900">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Amount (Rs.) <span class="text-red-600">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 text-gray-900">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Description</label>
                        <textarea name="description" rows="3" placeholder="Optional purchase details..."
                                  class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 text-gray-900"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Bill Image 📷</label>
                        <input type="file" name="bill_image" accept="image/*"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-red-50 file:text-red-700 hover:file:bg-red-100">
                        <p class="text-xs text-gray-600 mt-1">📸 Upload vendor's bill/receipt (optional)</p>
                    </div>
                    <div class="p-3 bg-red-50 border-2 border-red-200 rounded-md">
                        <p class="text-xs text-red-900 font-semibold">
                            ⚠️ This will increase the amount payable to this vendor.
                        </p>
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closePurchaseModal()" 
                                class="flex-1 px-4 py-2 border-2 border-gray-300 text-gray-700 font-semibold rounded-md hover:bg-gray-100 transition">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-md transition shadow-md">
                            ✓ Record Purchase
                        </button>
                    </div>
                </div>
            </form>
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
                            <button type="button" onclick="addLineItem()" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-150 text-sm font-medium">
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

<!-- Record Payment Modal - Modern Design -->
<div id="paymentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800">💰 Record Payment</h3>
                <button onclick="closePaymentModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form action="{{ route('fin.vendors.payment', $vendor->id) }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
                        <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" max="{{ $vendor->getBalance() }}" required placeholder="0.00"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        <p class="text-xs text-gray-500 mt-1">Current payable: Rs. {{ number_format($vendor->getBalance(), 2) }}</p>
                    </div>
                    
                    <!-- Payment Source Selection -->
                    <div class="p-3 bg-green-50 border border-green-200 rounded-md">
                        <label class="block text-sm font-medium text-green-900 mb-2">💳 Pay From:</label>
                        <select name="payment_source_account_id" 
                                class="w-full px-3 py-2 border border-green-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">NF Cash (Auto-Approved)</option>
                            @php
                                $paymentSources = \App\Models\FIN\AccountModel::where('is_active', 1)
                                    ->where(function($q) {
                                        $q->whereIn('account_code', ['ONLINE', 'NF_CASH', 'EXP_FUND'])
                                          ->orWhere('account_category', 'employee_cash');
                                    })
                                    ->orderBy('account_name')
                                    ->get();
                            @endphp
                            @foreach($paymentSources as $source)
                                <option value="{{ $source->id }}">
                                    {{ $source->account_name }} (Rs. {{ number_format($source->current_balance, 2) }})
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-green-700 mt-1">⚠️ Online and Manager cash require approval</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="3" placeholder="Optional payment details..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                    </div>
                    
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closePaymentModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md"
                                style="background-color: #059669 !important; color: white !important;">
                            <span style="color: white !important;">✓ Record Payment</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
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
    
    // Automatically add the first product line item so user doesn't have to click "Add Product Line"
    const container = document.getElementById('lineItemsContainer');
    if (!container.children.length || container.children.length === 0) {
        addLineItem();
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

function addLineItem() {
    lineItemCounter++;
    const container = document.getElementById('lineItemsContainer');
    const emptyMsg = document.getElementById('emptyLineItemsMsg');
    if (emptyMsg) emptyMsg.style.display = 'none';
    
    // Create product options HTML
    let productOptions = '<option value="">-- Select Product --</option>';
    vendorProducts.forEach(product => {
        productOptions += `<option value="${product.id}" data-rate="${product.rate_per_unit}" data-unit="${product.unit}">${product.product_name} (${product.unit}) - Rs. ${parseFloat(product.rate_per_unit).toFixed(2)}/${product.unit}</option>`;
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
    
    container.appendChild(lineItem);
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
        html += `
            <div style="margin-top: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 8px;">📎 Bill Image</label>
                <div style="border: 2px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #f9fafb;">
                    <img src="/storage/${transaction.bill_image}" 
                         alt="Bill Image" 
                         style="width: 100%; max-height: 400px; object-fit: contain; border-radius: 4px; cursor: pointer;"
                         onclick="window.open('/storage/${transaction.bill_image}', '_blank')"
                         onerror="console.error('Failed to load image:', '/storage/${transaction.bill_image}'); this.parentElement.innerHTML = '<p style=\\'color: #ef4444; text-align: center;\\'>Failed to load image</p>';">
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
                
                // Populate form
                document.getElementById('edit_transaction_id').value = transaction.id;
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
    
    fetch(`/finance/ledger/transaction/${transactionId}/update`, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Transaction updated successfully!');
            closeEditTransactionModal();
            location.reload(); // Reload to show updated data
        } else {
            alert('Error: ' + (data.message || 'Failed to update transaction'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating transaction');
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

@endsection

