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
    <div class="flex gap-4 mb-6">
        <button onclick="openPurchaseModal()" 
                class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md"
                style="background-color: #dc2626 !important; color: white !important;">
            <span style="color: white !important;">📦 Record Purchase</span>
        </button>
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
    
    if (event.target === purchaseModal) {
        closePurchaseModal();
    }
    if (event.target === paymentModal) {
        closePaymentModal();
    }
});
</script>

@endsection

