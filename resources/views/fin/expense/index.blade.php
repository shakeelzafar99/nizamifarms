@extends('layouts.app')

@section('title', 'Expense Management')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">💰 Expense Management</h1>
            <p class="text-sm text-gray-600 mt-1">Track all expenses and manage settlements</p>
        </div>
    </div>

    <!-- KPI Cards - Compact -->
    @php
        $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
            ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
        <!-- Total Expenses -->
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs text-gray-500 uppercase font-medium">📊 Total</div>
            <div class="text-lg font-bold text-gray-900 mt-1">Rs. {{ number_format($kpis['total_expenses'], 2) }}</div>
        </div>

        <!-- From Expense Fund -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-3">
            <div class="text-xs text-green-700 uppercase font-medium">✅ From Fund</div>
            <div class="text-lg font-bold text-green-900 mt-1">Rs. {{ number_format($kpis['from_expense_fund'], 2) }}</div>
        </div>

        <!-- Needs Settlement -->
        <div class="bg-orange-50 border border-orange-200 rounded-lg p-3">
            <div class="text-xs text-orange-700 uppercase font-medium">⚠️ Needs Settlement</div>
            <div class="text-lg font-bold text-orange-900 mt-1">Rs. {{ number_format($kpis['needs_settlement'], 2) }}</div>
            <div class="text-xs text-orange-600 mt-1">{{ $kpis['pending_count'] }} expense(s)</div>
        </div>

        <!-- Settled -->
        <div class="bg-purple-50 border border-purple-200 rounded-lg p-3">
            <div class="text-xs text-purple-700 uppercase font-medium">✓ Settled</div>
            <div class="text-lg font-bold text-purple-900 mt-1">Rs. {{ number_format($kpis['settled'], 2) }}</div>
            <div class="text-xs text-purple-600 mt-1">{{ $kpis['settled_count'] }} done</div>
        </div>

        <!-- Expense Fund Balance -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <div class="text-xs text-blue-700 uppercase font-medium">💰 Fund Balance</div>
            <div class="text-lg font-bold text-blue-900 mt-1">
                Rs. {{ $expenseFund ? number_format($expenseFund->current_balance, 2) : '0.00' }}
            </div>
            <div class="text-xs text-blue-600 mt-1">Available</div>
        </div>
    </div>

    <!-- Filter Bar - Compact -->
    <div class="bg-white border border-gray-200 rounded-lg p-3 mb-4">
        <form method="GET" action="{{ route('fin.expenses.index') }}" id="filterForm">
            <div class="grid grid-cols-2 md:grid-cols-6 gap-2">
                <!-- Date Range -->
                <div>
                    <label class="text-xs font-medium text-gray-600">From</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" 
                           class="mt-1 block w-full rounded border-gray-300 text-xs p-1.5">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">To</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" 
                           class="mt-1 block w-full rounded border-gray-300 text-xs p-1.5">
                </div>

                <!-- Category -->
                <div>
                    <label class="text-xs font-medium text-gray-600">Category</label>
                    <select name="category" class="mt-1 block w-full rounded border-gray-300 text-xs p-1.5">
                        <option value="">All</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" {{ $category == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Payment Source -->
                <div>
                    <label class="text-xs font-medium text-gray-600">Source</label>
                    <select name="payment_source" class="mt-1 block w-full rounded border-gray-300 text-xs p-1.5">
                        <option value="">All</option>
                        @foreach($paymentSources as $source)
                            <option value="{{ $source->id }}" {{ $paymentSource == $source->id ? 'selected' : '' }}>
                                {{ $source->account_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Settlement Status -->
                <div>
                    <label class="text-xs font-medium text-gray-600">Status</label>
                    <select name="settlement_status" class="mt-1 block w-full rounded border-gray-300 text-xs p-1.5">
                        <option value="">All</option>
                        <option value="not_required" {{ $settlementStatus == 'not_required' ? 'selected' : '' }}>✅ From Fund</option>
                        <option value="pending" {{ $settlementStatus == 'pending' ? 'selected' : '' }}>⚠️ Pending</option>
                        <option value="settled" {{ $settlementStatus == 'settled' ? 'selected' : '' }}>✓ Settled</option>
                    </select>
                </div>

                <!-- Buttons -->
                <div class="flex gap-1 items-end">
                    <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded">
                        Apply
                    </button>
                    <a href="{{ route('fin.expenses.index') }}" class="px-3 py-1.5 bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs font-medium rounded">
                        Clear
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabs -->
    <div class="bg-white border border-gray-200 rounded-lg">
        <!-- Tab Navigation -->
        <div class="border-b border-gray-200">
            <nav class="flex space-x-8 px-6" aria-label="Tabs">
                <button onclick="switchTab('all')" id="tab-all" class="tab-button active border-b-2 border-blue-500 py-4 px-1 text-sm font-medium text-blue-600">
                    📋 All Expenses ({{ $allExpenses->count() }})
                </button>
                <button onclick="switchTab('pending')" id="tab-pending" class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    ⚠️ Needs Settlement ({{ $pendingSettlement->count() }})
                </button>
                <button onclick="switchTab('history')" id="tab-history" class="tab-button border-b-2 border-transparent py-4 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    ✓ Settlement History ({{ $settlementHistory->count() }})
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div class="p-6">
            <!-- Tab 1: All Expenses -->
            <div id="content-all" class="tab-content">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paid From</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($allExpenses as $expense)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                    {{ $expense->request_number }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->created_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $expense->requester->name ?? 'Unknown' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->expense_category ?? $expense->category->category_name }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    Rs. {{ number_format($expense->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->paymentSourceAccount->account_name ?? 'N/A' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if($expense->settlement_status === 'not_required')
                                        <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">✅ No Action</span>
                                    @elseif($expense->settlement_status === 'pending')
                                        <span class="px-2 py-1 text-xs font-medium bg-orange-100 text-orange-800 rounded">⚠️ Pending</span>
                                    @elseif($expense->settlement_status === 'settled')
                                        <span class="px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded">✓ Settled</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if($expense->settlement_status === 'pending')
                                        <button onclick="openSettlementModal({{ $expense->id }})" 
                                                class="text-blue-600 hover:text-blue-800 font-medium">
                                            ⚙️ Settle
                                        </button>
                                    @elseif($expense->settlement_status === 'settled')
                                        <span class="text-gray-400 text-xs">
                                            {{ $expense->settled_at->format('M d') }} by {{ $expense->settledBy->name ?? 'System' }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                    <div class="text-4xl mb-2">📭</div>
                                    <p>No expenses found matching your filters</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 2: Needs Settlement -->
            <div id="content-pending" class="tab-content hidden">
                @if($pendingSettlement->count() > 0)
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <input type="checkbox" id="selectAll" onclick="toggleSelectAll()" class="rounded">
                        <label for="selectAll" class="text-sm font-medium text-gray-700">Select All</label>
                        <span id="selectedCount" class="text-sm text-gray-600"></span>
                    </div>
                    <button onclick="bulkSettle()" id="bulkSettleBtn" disabled
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md disabled:opacity-50 disabled:cursor-not-allowed">
                        ⚙️ Settle Selected
                    </button>
                </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-orange-50">
                            <tr>
                                <th class="px-6 py-3 text-left">
                                    <input type="checkbox" id="selectAllHeader" onclick="toggleSelectAll()" class="rounded">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Request #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Employee</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Paid From</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Days</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($pendingSettlement as $expense)
                            <tr class="hover:bg-gray-50 {{ \Carbon\Carbon::parse($expense->created_at)->diffInDays(now()) > 7 ? 'bg-yellow-50' : '' }}">
                                <td class="px-6 py-4">
                                    <input type="checkbox" class="expense-checkbox rounded" value="{{ $expense->id }}" onchange="updateBulkButton()">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                    {{ $expense->request_number }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->created_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $expense->requester->name ?? 'Unknown' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->expense_category ?? $expense->category->category_name }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    Rs. {{ number_format($expense->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded">
                                        {{ $expense->paymentSourceAccount->account_name ?? 'Unknown' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ \Carbon\Carbon::parse($expense->created_at)->diffInDays(now()) }} days
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <button onclick="openSettlementModal({{ $expense->id }})" 
                                            class="text-blue-600 hover:text-blue-800 font-medium">
                                        ⚙️ Settle
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                                    <div class="text-4xl mb-2">✅</div>
                                    <p class="font-medium">All clear! No expenses need settlement.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 3: Settlement History -->
            <div id="content-history" class="tab-content hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-purple-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Request #</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Paid On</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Employee</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Originally From</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Destination</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Settled On</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase">Settled By</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse($settlementHistory as $expense)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                    {{ $expense->request_number }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->created_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $expense->requester->name ?? 'Unknown' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    Rs. {{ number_format($expense->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->paymentSourceAccount->account_name ?? 'N/A' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->settlementDestinationAccount->account_name ?? 'N/A' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->settled_at ? $expense->settled_at->format('M d, Y') : 'N/A' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->settledBy->name ?? 'System' }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                    <div class="text-4xl mb-2">📜</div>
                                    <p>No settlement history yet</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

<!-- Settlement Modal (Portalized outside content to avoid clipping) -->
<div id="settlementModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;" onclick="closeSettlementModal()">
    <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[85vh] overflow-y-auto" style="margin: auto;" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">⚙️ Settle Expense</h2>
                <button onclick="closeSettlementModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <div id="settlementModalContent">
                <div class="text-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Loading settlement details...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
function switchTab(tabName) {
    // Hide all content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active from all buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-blue-500', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Mark button as active
    const activeButton = document.getElementById('tab-' + tabName);
    activeButton.classList.add('active', 'border-blue-500', 'text-blue-600');
    activeButton.classList.remove('border-transparent', 'text-gray-500');
}

// Bulk selection
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll').checked || document.getElementById('selectAllHeader').checked;
    document.querySelectorAll('.expense-checkbox').forEach(checkbox => {
        checkbox.checked = selectAll;
    });
    document.getElementById('selectAll').checked = selectAll;
    document.getElementById('selectAllHeader').checked = selectAll;
    updateBulkButton();
}

function updateBulkButton() {
    const selected = document.querySelectorAll('.expense-checkbox:checked').length;
    const btn = document.getElementById('bulkSettleBtn');
    const countSpan = document.getElementById('selectedCount');
    
    if (selected > 0) {
        btn.disabled = false;
        countSpan.textContent = `${selected} expense(s) selected`;
    } else {
        btn.disabled = true;
        countSpan.textContent = '';
    }
}

// Open settlement modal
async function openSettlementModal(expenseId) {
    console.log('Opening settlement modal for expense ID:', expenseId);
    
    const modal = document.getElementById('settlementModal');
    if (!modal) {
        console.error('Settlement modal element not found!');
        alert('Error: Modal element not found. Please refresh the page.');
        return;
    }
    
    // Force remove hidden class and make visible
    modal.classList.remove('hidden');
    modal.style.display = 'flex'; // Force display
    console.log('Modal classes after opening:', modal.className);
    console.log('Modal display style:', modal.style.display);
    console.log('Modal computed display:', window.getComputedStyle(modal).display);
    
    try {
        console.log('Fetching settlement details...');
        const response = await fetch(`/finance/expenses/${expenseId}/settlement-details`);
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Settlement details:', result);
        
        if (result.success) {
            const data = result.data;
            console.log('Data received:', data);
            
            const contentDiv = document.getElementById('settlementModalContent');
            if (!contentDiv) {
                console.error('settlementModalContent div not found!');
                alert('Error: Modal content container not found');
                return;
            }
            
            console.log('Setting modal content...');
            contentDiv.innerHTML = `
                <div class="space-y-4">
                    <!-- Expense Details -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-900 mb-3">Expense Details</h4>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <span class="text-gray-600">Request #:</span>
                                <span class="font-medium ml-2">${data.request_number}</span>
                            </div>
                            <div>
                                <span class="text-gray-600">Date:</span>
                                <span class="font-medium ml-2">${data.date}</span>
                            </div>
                            <div>
                                <span class="text-gray-600">Employee:</span>
                                <span class="font-medium ml-2">${data.employee}</span>
                            </div>
                            <div>
                                <span class="text-gray-600">Category:</span>
                                <span class="font-medium ml-2">${data.category}</span>
                            </div>
                            <div class="col-span-2">
                                <span class="text-gray-600">Amount:</span>
                                <span class="font-semibold text-lg ml-2">Rs. ${parseFloat(data.amount).toLocaleString('en-PK', {minimumFractionDigits: 2})}</span>
                            </div>
                            <div class="col-span-2">
                                <span class="text-gray-600">Currently Paid From:</span>
                                <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded ml-2">${data.paid_from}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Settlement Transaction -->
                    <div class="bg-blue-50 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-900 mb-3">Settlement Transaction</h4>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Settlement Source</label>
                                <select id="settlementSource" class="w-full rounded-md border-gray-300 shadow-sm">
                                    @foreach($settlementSources as $source)
                                    <option value="{{ $source->id }}" {{ $source->account_code === 'EXP_FUND' ? 'selected' : '' }}>
                                        {{ $source->account_name }} (Rs. {{ number_format($source->current_balance, 2) }})
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            
                            <div class="flex items-center justify-center py-2">
                                <div class="text-2xl">➡️</div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Settlement Destination</label>
                                <input type="text" value="${data.destination}" readonly 
                                       class="w-full rounded-md border-gray-300 bg-gray-100 shadow-sm">
                                <p class="text-xs text-gray-500 mt-1">💡 Based on employee's recent deposit history</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notes (Optional)</label>
                                <textarea id="settlementNotes" rows="2" 
                                          class="w-full rounded-md border-gray-300 shadow-sm"
                                          placeholder="Add any notes about this settlement..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Impact Summary -->
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                        <p class="text-sm text-green-800">
                            <strong>ℹ️ This will:</strong>
                        </p>
                        <ul class="text-sm text-green-700 mt-2 space-y-1 ml-4">
                            <li>• Transfer Rs. ${parseFloat(data.amount).toLocaleString('en-PK', {minimumFractionDigits: 2})} to ${data.destination}</li>
                            <li>• Mark expense as settled in the system</li>
                            <li>• Update employee's expense tracking automatically</li>
                            <li>• Create audit trail with your username & timestamp</li>
                        </ul>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-3 mt-6">
                        <button onclick="closeSettlementModal()" 
                                class="flex-1 px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button onclick="confirmSettlement(${expenseId})" 
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium">
                            ✅ Confirm Settlement
                        </button>
                    </div>
                </div>
            `;
            console.log('Modal content set successfully. Modal should be visible now.');
        } else {
            console.error('Result success was false:', result);
            alert('Error: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error loading settlement details:', error);
        document.getElementById('settlementModalContent').innerHTML = `
            <div class="text-center py-8 text-red-600">
                <p>❌ Error loading expense details.</p>
                <p class="text-sm mt-2">${error.message}</p>
                <button onclick="closeSettlementModal()" class="mt-4 px-4 py-2 bg-gray-200 rounded">Close</button>
            </div>
        `;
    }
}

function closeSettlementModal() {
    document.getElementById('settlementModal').classList.add('hidden');
}

// Confirm settlement
async function confirmSettlement(expenseId) {
    const sourceAccountId = document.getElementById('settlementSource').value;
    const notes = document.getElementById('settlementNotes').value;
    
    console.log('Confirming settlement for expense:', expenseId);
    console.log('Source account:', sourceAccountId);
    console.log('Notes:', notes);
    
    try {
        const response = await fetch(`/finance/expenses/${expenseId}/settle`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                settlement_source_account_id: sourceAccountId,
                notes: notes
            })
        });
        
        console.log('Settlement response status:', response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Settlement error response:', errorText);
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Settlement result:', result);
        
        if (result.success) {
            alert('✅ ' + result.message);
            location.reload(); // Refresh page to show updated data
        } else {
            alert('❌ ' + (result.message || 'Settlement failed'));
        }
    } catch (error) {
        console.error('Error settling expense:', error);
        alert('❌ Error processing settlement: ' + error.message);
    }
}

// Bulk settle
async function bulkSettle() {
    const selected = Array.from(document.querySelectorAll('.expense-checkbox:checked')).map(cb => cb.value);
    
    if (selected.length === 0) {
        alert('⚠️ Please select at least one expense to settle');
        return;
    }
    
    if (!confirm(`Settle ${selected.length} expense(s) from Expense Fund?`)) {
        return;
    }
    
    try {
        const response = await fetch('/finance/expenses/bulk-settle', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                expense_ids: selected,
                notes: 'Bulk settlement'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`✅ ${result.message}\n\nSuccess: ${result.details.success_count}\nFailed: ${result.details.fail_count}`);
            location.reload();
        } else {
            alert('❌ ' + result.message);
        }
    } catch (error) {
        console.error('Error in bulk settlement:', error);
        alert('❌ Error processing bulk settlement. Please try again.');
    }
}

// Ensure all functions are globally accessible
window.switchTab = switchTab;
window.toggleSelectAll = toggleSelectAll;
window.updateBulkButton = updateBulkButton;
window.openSettlementModal = openSettlementModal;
window.closeSettlementModal = closeSettlementModal;
window.confirmSettlement = confirmSettlement;
window.bulkSettle = bulkSettle;

console.log('Expense Management JS loaded. Functions:', {
    openSettlementModal: typeof window.openSettlementModal,
    switchTab: typeof window.switchTab
});
</script>

