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

    <!-- Vendor Summary Cards - Compact -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs text-gray-500 uppercase">Opening</div>
            <div class="text-lg font-bold text-gray-900 mt-1">Rs. {{ number_format($summary['opening_balance'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs text-gray-500 uppercase">Purchases</div>
            <div class="text-lg font-bold text-red-600 mt-1">Rs. {{ number_format($summary['total_purchases'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs text-gray-500 uppercase">Payments</div>
            <div class="text-lg font-bold text-green-600 mt-1">Rs. {{ number_format($summary['total_payments'], 2) }}</div>
        </div>
        <div class="border border-gray-200 rounded-lg p-3 {{ $summary['current_balance'] > 0 ? 'bg-red-50' : 'bg-white' }}">
            <div class="text-xs text-gray-500 uppercase">Payable</div>
            <div class="text-lg font-bold {{ $summary['current_balance'] > 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">
                Rs. {{ number_format($summary['current_balance'], 2) }}
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-3 mb-6">
        <button onclick="openPurchaseModal()" 
                class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md"
                style="background-color: #dc2626 !important; color: white !important;">
            <span style="color: white !important;">📦 Record Purchase</span>
        </button>
        <button onclick="openWeightedPurchaseModal()" 
                class="inline-flex items-center px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-md"
                style="background-color: #ea580c !important; color: white !important;">
            <span style="color: white !important;">⚖️ Purchase by Weight</span>
        </button>
        <button onclick="openPaymentModal()" 
                class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md"
                style="background-color: #059669 !important; color: white !important;">
            <span style="color: white !important;">💰 Record Payment</span>
        </button>
        <a href="{{ route('fin.vendors.products', $vendor->id) }}" 
           class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md"
           style="background-color: #2563eb !important; color: white !important;">
            <span style="color: white !important;">🛒 Manage Products</span>
        </a>
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
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($ledgerWithBalance as $transaction)
                        <tr class="hover:bg-gray-50">
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">
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
<div id="purchaseModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800">📦 Record Purchase</h3>
                <button onclick="closePurchaseModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form action="{{ route('fin.vendors.purchase', $vendor->id) }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
                        <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="3" placeholder="Optional purchase details..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                    </div>
                    <div class="p-3 bg-red-50 border border-red-200 rounded-md">
                        <p class="text-xs text-red-800">
                            <strong>Note:</strong> This will increase the amount payable to this vendor.
                        </p>
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closePurchaseModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-md"
                                style="background-color: #dc2626 !important; color: white !important;">
                            <span style="color: white !important;">✓ Record Purchase</span>
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
            <form action="{{ route('fin.vendors.weighted-purchase', $vendor->id) }}" method="POST" id="weightedPurchaseForm">
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
                            <button type="button" onclick="addLineItem()" class="w-full px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-colors duration-150 text-sm font-medium">
                                ➕ Add Product Line
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
</script>

@endsection

