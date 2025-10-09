@extends('layouts.app')

@section('title', 'Overall Ledger')

@section('content')
<div class="container mx-auto px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Overall Ledger</h1>
        <a href="{{ route('fin.ledger.transfer') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
            🔄 New Transfer
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <!-- Pending Approvals Summary -->
    @if($pendingSummary['total_count'] > 0)
    <div class="bg-gradient-to-r from-yellow-50 to-orange-50 border-l-4 border-orange-500 rounded-lg p-6 mb-6 shadow-sm">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                    <span class="text-2xl">⚠️</span>
                    Pending Approvals
                </h3>
                <p class="text-sm text-gray-600 mt-1">Transactions waiting for your approval</p>
            </div>
            <a href="{{ route('fin.ledger.index', ['status' => 'pending']) }}" 
               class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-md transition">
                View All Pending
            </a>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Total Card -->
            <div class="bg-white rounded-lg p-4 border-2 border-orange-200">
                <div class="text-sm font-medium text-gray-600 mb-1">Total Pending</div>
                <div class="text-3xl font-bold text-orange-600">{{ $pendingSummary['total_count'] }}</div>
                <div class="text-lg font-semibold text-gray-700 mt-1">
                    Rs. {{ number_format($pendingSummary['total_amount'], 2) }}
                </div>
            </div>
            
            <!-- Breakdown Cards -->
            @if(isset($pendingSummary['by_type']['invoice']))
            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="text-sm font-medium text-gray-600 mb-1">Online Invoices</div>
                <div class="text-2xl font-bold text-blue-600">{{ $pendingSummary['by_type']['invoice']->count }}</div>
                <div class="text-sm font-semibold text-gray-600 mt-1">
                    Rs. {{ number_format($pendingSummary['by_type']['invoice']->amount, 2) }}
                </div>
            </div>
            @endif
            
            @if(isset($pendingSummary['by_type']['employee_deposit']))
            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="text-sm font-medium text-gray-600 mb-1">Employee Deposits</div>
                <div class="text-2xl font-bold text-green-600">{{ $pendingSummary['by_type']['employee_deposit']->count }}</div>
                <div class="text-sm font-semibold text-gray-600 mt-1">
                    Rs. {{ number_format($pendingSummary['by_type']['employee_deposit']->amount, 2) }}
                </div>
            </div>
            @endif
            
            @if(isset($pendingSummary['by_type']['vendor_payment']))
            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="text-sm font-medium text-gray-600 mb-1">Vendor Payments</div>
                <div class="text-2xl font-bold text-purple-600">{{ $pendingSummary['by_type']['vendor_payment']->count }}</div>
                <div class="text-sm font-semibold text-gray-600 mt-1">
                    Rs. {{ number_format($pendingSummary['by_type']['vendor_payment']->amount, 2) }}
                </div>
            </div>
            @endif
            
            @if(isset($pendingSummary['by_type']['transfer']))
            <div class="bg-white rounded-lg p-4 border border-gray-200">
                <div class="text-sm font-medium text-gray-600 mb-1">Transfers</div>
                <div class="text-2xl font-bold text-indigo-600">{{ $pendingSummary['by_type']['transfer']->count }}</div>
                <div class="text-sm font-semibold text-gray-600 mt-1">
                    Rs. {{ number_format($pendingSummary['by_type']['transfer']->amount, 2) }}
                </div>
            </div>
            @endif
        </div>
        
        <!-- All Types Breakdown -->
        @if($pendingSummary['by_type']->count() > 4)
        <details class="mt-4">
            <summary class="text-sm font-medium text-gray-700 cursor-pointer hover:text-gray-900">
                Show all transaction types ({{ $pendingSummary['by_type']->count() }} types)
            </summary>
            <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-2">
                @foreach($pendingSummary['by_type'] as $type => $data)
                    <div class="text-xs bg-white px-3 py-2 rounded border border-gray-200">
                        <span class="font-semibold text-gray-700">{{ ucfirst(str_replace('_', ' ', $type)) }}:</span>
                        <span class="text-gray-600">{{ $data->count }} (Rs. {{ number_format($data->amount, 0) }})</span>
                    </div>
                @endforeach
            </div>
        </details>
        @endif
    </div>
    @endif

    <!-- Filters -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
        <form method="GET" action="{{ route('fin.ledger.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="{{ request('start_date') }}" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="{{ request('end_date') }}" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
                <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">All Types</option>
                    @foreach($transactionTypes as $key => $label)
                        <option value="{{ $key }}" {{ request('type') == $key ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mode</label>
                <select name="mode" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">All Modes</option>
                    <option value="cash" {{ request('mode') == 'cash' ? 'selected' : '' }}>Cash</option>
                    <option value="online" {{ request('mode') == 'online' ? 'selected' : '' }}>Online</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">All Statuses</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="approved" {{ request('status') == 'approved' ? 'selected' : '' }}>Approved</option>
                    <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Account</label>
                <select name="account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">All Accounts</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" {{ request('account_id') == $account->id ? 'selected' : '' }}>
                            {{ $account->account_name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Description..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-medium rounded-md">
                    🔍 Filter
                </button>
                <a href="{{ route('fin.ledger.index') }}" class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Ledger Table -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-medium text-gray-900">All Transactions</h2>
            <div class="text-sm text-gray-500">
                Showing {{ $ledger->firstItem() ?? 0 }}-{{ $ledger->lastItem() ?? 0 }} of {{ $ledger->total() }}
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Mode</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($ledger as $transaction)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->transaction_date->format('Y-m-d') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs">
                                <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-800">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->fromAccount->account_name ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->toAccount->account_name ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate">
                                {{ $transaction->description }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                                Rs. {{ number_format($transaction->amount, 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                                @if($transaction->mode)
                                    <span class="px-2 py-1 rounded-full {{ $transaction->mode === 'cash' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' }}">
                                        {{ ucfirst($transaction->mode) }}
                                    </span>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                                @if($transaction->approval_status === 'pending')
                                    <span class="px-2 py-1 rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                                @elseif($transaction->approval_status === 'approved')
                                    <span class="px-2 py-1 rounded-full bg-green-100 text-green-800">Approved</span>
                                @else
                                    <span class="px-2 py-1 rounded-full bg-red-100 text-red-800">Rejected</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                @if($transaction->approval_status === 'pending')
                                    <button onclick="approveTransaction({{ $transaction->id }})" class="text-green-600 hover:text-green-900 mr-2" title="Approve">
                                        ✅
                                    </button>
                                    <button onclick="rejectTransaction({{ $transaction->id }})" class="text-red-600 hover:text-red-900" title="Reject">
                                        ❌
                                    </button>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-8 text-center text-sm text-gray-500">
                                No transactions found. Try adjusting your filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    @if($ledger->hasPages())
        <div class="mt-4">
            {{ $ledger->links() }}
        </div>
    @endif
</div>

<!-- Approve Modal -->
<div id="approveModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Approve Transaction</h3>
            <button onclick="closeApproveModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <form id="approveForm" method="POST">
            @csrf
            <div class="space-y-4">
                <!-- Transaction Details (readonly) -->
                <div id="txnDetails" class="p-3 bg-gray-50 border border-gray-200 rounded-md text-xs">
                    <div class="font-semibold text-gray-900 mb-2">Transaction Details:</div>
                    <div class="space-y-1 text-gray-700">
                        <div>From: <span id="txnFrom" class="font-medium"></span></div>
                        <div>To: <span id="txnTo" class="font-medium"></span></div>
                        <div>Amount: <span id="txnAmount" class="font-medium text-green-600"></span></div>
                    </div>
                </div>
                
                <!-- Account Override Options -->
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <div class="text-sm font-semibold text-blue-900 mb-2">💡 Override Accounts (Optional)</div>
                    
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-blue-900 mb-1">Change Source Account:</label>
                            <select name="override_source_account_id" 
                                    class="w-full px-2 py-1.5 border border-blue-300 rounded text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Keep Original</option>
                                @php
                                    $allAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                                        ->orderBy('account_name')
                                        ->get();
                                @endphp
                                @foreach($allAccounts as $acc)
                                    <option value="{{ $acc->id }}">
                                        {{ $acc->account_name }} (Rs. {{ number_format($acc->current_balance, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-medium text-blue-900 mb-1">Change Destination Account:</label>
                            <select name="override_destination_account_id" 
                                    class="w-full px-2 py-1.5 border border-blue-300 rounded text-xs focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Keep Original</option>
                                @foreach($allAccounts as $acc)
                                    <option value="{{ $acc->id }}">
                                        {{ $acc->account_name }} (Rs. {{ number_format($acc->current_balance, 2) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    
                    <p class="text-xs text-blue-700 mt-2">Leave as "Keep Original" if no changes needed</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Approval Notes (optional)</label>
                    <textarea name="approval_notes" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                </div>
                
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md">
                        ✅ Approve
                    </button>
                    <button type="button" onclick="closeApproveModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900">Reject Transaction</h3>
            <button onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <form id="rejectForm" method="POST">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rejection Reason (required)</label>
                    <textarea name="rejection_reason" rows="3" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                </div>
                <div class="flex gap-2 pt-2">
                    <button type="submit" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md">
                        ❌ Reject
                    </button>
                    <button type="button" onclick="closeRejectModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let ledgerData = @json($ledger->items());

function approveTransaction(id) {
    // Find transaction in data
    const txn = ledgerData.find(t => t.id === id);
    
    if (txn) {
        // Populate transaction details
        document.getElementById('txnFrom').textContent = txn.from_account ? txn.from_account.account_name : 'N/A';
        document.getElementById('txnTo').textContent = txn.to_account ? txn.to_account.account_name : 'N/A';
        document.getElementById('txnAmount').textContent = 'Rs. ' + parseFloat(txn.amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
    
    document.getElementById('approveForm').action = `/finance/ledger/${id}/approve`;
    document.getElementById('approveModal').classList.remove('hidden');
}

function closeApproveModal() {
    document.getElementById('approveModal').classList.add('hidden');
    // Reset form
    document.getElementById('approveForm').reset();
}

function rejectTransaction(id) {
    document.getElementById('rejectForm').action = `/finance/ledger/${id}/reject`;
    document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    // Reset form
    document.getElementById('rejectForm').reset();
}
</script>

@endsection

