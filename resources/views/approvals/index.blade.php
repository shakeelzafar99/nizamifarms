@extends('layouts.app')

@section('title', 'Approvals Dashboard')

@section('content')
<div class="container mx-auto px-4 py-6">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">📋 Approvals Dashboard</h1>
        <p class="text-gray-600 mt-2">All pending approvals in one place</p>
    </div>

    <!-- Compact Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <!-- Expense Requests Card -->
        <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 border border-yellow-300 rounded-lg p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-2xl">💸</span>
                <span class="px-2 py-0.5 bg-yellow-200 text-yellow-800 text-xs font-bold rounded-full">
                    {{ $expenseSummary['count'] }}
                </span>
            </div>
            <h3 class="text-sm font-semibold text-gray-700">Expense Requests</h3>
            <p class="text-xl font-bold text-yellow-700 mt-1">Rs. {{ number_format($expenseSummary['total_amount'], 0) }}</p>
        </div>

        <!-- Leave Approvals Card -->
        <div class="bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-300 rounded-lg p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-2xl">🏖️</span>
                <span class="px-2 py-0.5 bg-purple-200 text-purple-800 text-xs font-bold rounded-full">
                    {{ $leaveSummary['count'] }}
                </span>
            </div>
            <h3 class="text-sm font-semibold text-gray-700">Leave Approvals</h3>
            <p class="text-xl font-bold text-purple-700 mt-1">{{ $leaveSummary['total_days'] }} Days</p>
        </div>

        <!-- Cash Transactions Card -->
        <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-300 rounded-lg p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-2xl">💵</span>
                <span class="px-2 py-0.5 bg-green-200 text-green-800 text-xs font-bold rounded-full">
                    {{ $cashSummary['count'] }}
                </span>
            </div>
            <h3 class="text-sm font-semibold text-gray-700">Cash Approvals</h3>
            <p class="text-xl font-bold text-green-700 mt-1">Rs. {{ number_format($cashSummary['total_amount'], 0) }}</p>
        </div>

        <!-- Online/Bank Transactions Card -->
        <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-300 rounded-lg p-4 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between mb-2">
                <span class="text-2xl">🏦</span>
                <span class="px-2 py-0.5 bg-blue-200 text-blue-800 text-xs font-bold rounded-full">
                    {{ $onlineSummary['count'] }}
                </span>
            </div>
            <h3 class="text-sm font-semibold text-gray-700">Online/Bank Approvals</h3>
            <p class="text-xl font-bold text-blue-700 mt-1">Rs. {{ number_format($onlineSummary['total_amount'], 0) }}</p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="bg-white rounded-lg shadow-md border border-gray-200">
        <div class="border-b border-gray-200">
            <ul class="flex flex-wrap -mb-px text-sm font-medium text-center" role="tablist">
                <li class="mr-2" role="presentation">
                    <button class="inline-flex items-center gap-2 px-4 py-3 border-b-2 border-yellow-500 text-yellow-600 font-bold hover:text-yellow-700" 
                            id="expenses-tab" 
                            data-tabs-target="#expenses" 
                            type="button" 
                            role="tab" 
                            aria-controls="expenses" 
                            aria-selected="true"
                            onclick="switchTab('expenses')">
                        💸 Expenses
                        <span class="px-2 py-0.5 bg-yellow-100 text-yellow-800 text-xs font-bold rounded-full">
                            {{ $expenseSummary['count'] }}
                        </span>
                    </button>
                </li>
                <li class="mr-2" role="presentation">
                    <button class="inline-flex items-center gap-2 px-4 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-600 hover:border-gray-300"
                            id="leaves-tab" 
                            data-tabs-target="#leaves" 
                            type="button" 
                            role="tab" 
                            aria-controls="leaves" 
                            aria-selected="false"
                            onclick="switchTab('leaves')">
                        🏖️ Leaves
                        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs font-bold rounded-full">
                            {{ $leaveSummary['count'] }}
                        </span>
                    </button>
                </li>
                <li class="mr-2" role="presentation">
                    <button class="inline-flex items-center gap-2 px-4 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-600 hover:border-gray-300"
                            id="cash-tab" 
                            data-tabs-target="#cash" 
                            type="button" 
                            role="tab" 
                            aria-controls="cash" 
                            aria-selected="false"
                            onclick="switchTab('cash')">
                        💵 Cash
                        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs font-bold rounded-full">
                            {{ $cashSummary['count'] }}
                        </span>
                    </button>
                </li>
                <li class="mr-2" role="presentation">
                    <button class="inline-flex items-center gap-2 px-4 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-600 hover:border-gray-300"
                            id="online-tab" 
                            data-tabs-target="#online" 
                            type="button" 
                            role="tab" 
                            aria-controls="online" 
                            aria-selected="false"
                            onclick="switchTab('online')">
                        🏦 Online/Bank
                        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs font-bold rounded-full">
                            {{ $onlineSummary['count'] }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>

        <!-- Tab Content -->
        <div id="tabContent">
            <!-- Expense Requests Tab -->
            <div id="expenses" role="tabpanel" aria-labelledby="expenses-tab">
                <div class="p-6">
                    @if($expenseRequests->count() > 0)
                        <div class="mb-4 text-sm text-gray-600">
                            You have <strong class="text-gray-900">{{ $expenseRequests->count() }}</strong> pending expense requests
                            @if($hasLevel1Rights) <span class="text-green-600">(Level 1 Approver)</span> @endif
                            @if($hasLevel2Rights) <span class="text-blue-600">(Level 2 Approver)</span> @endif
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Request #</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requester</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment Source</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Level</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($expenseRequests as $request)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $request->request_number }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $request->requester->fullname ?? 'Unknown' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $request->category->category_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-900">Rs. {{ number_format($request->amount, 2) }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-600">
                                            @if($request->paymentSourceAccount)
                                                <span class="px-2 py-1 text-xs font-medium bg-purple-50 text-purple-700 rounded">
                                                    💳 {{ $request->paymentSourceAccount->account_name }}
                                                </span>
                                            @else
                                                <span class="text-xs text-gray-400">Expense Fund</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            @if($request->level_1_status === 'pending')
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs font-medium rounded">L1 Pending</span>
                                            @elseif($request->level_2_status === 'pending')
                                                <span class="px-2 py-1 bg-orange-100 text-orange-800 text-xs font-medium rounded">L2 Pending</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $request->submitted_at ? $request->submitted_at->format('M d, Y') : 'N/A' }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="{{ route('requests.show', $request->id) }}" 
                                               class="inline-flex items-center px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition">
                                                View & Approve
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No pending expense requests</h3>
                            <p class="mt-1 text-sm text-gray-500">All expense requests have been approved!</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Leave Requests Tab -->
            <div id="leaves" class="hidden" role="tabpanel" aria-labelledby="leaves-tab">
                <div class="p-6">
                    @if($leaveRequests->count() > 0)
                        <div class="mb-4 text-sm text-gray-600">
                            You have <strong class="text-gray-900">{{ $leaveRequests->count() }}</strong> pending leave requests
                            @if($hasLevel1Rights) <span class="text-green-600">(Level 1 Approver)</span> @endif
                            @if($hasLevel2Rights) <span class="text-blue-600">(Level 2 Approver)</span> @endif
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Request #</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Leave Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Start Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">End Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Level</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($leaveRequests as $request)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm font-medium text-blue-600">{{ $request->request_number }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $request->requester->fullname ?? 'Unknown' }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="px-2 py-1 bg-purple-100 text-purple-800 text-xs font-medium rounded">{{ ucfirst($request->leave_type ?? 'N/A') }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $request->leave_start_date ? $request->leave_start_date->format('M d, Y') : 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $request->leave_end_date ? $request->leave_end_date->format('M d, Y') : 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-900">
                                            @if($request->leave_start_date && $request->leave_end_date)
                                                {{ $request->leave_start_date->diffInDays($request->leave_end_date) + 1 }} days
                                            @else
                                                N/A
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            @if($request->level_1_status === 'pending')
                                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs font-medium rounded">L1 Pending</span>
                                            @elseif($request->level_2_status === 'pending')
                                                <span class="px-2 py-1 bg-orange-100 text-orange-800 text-xs font-medium rounded">L2 Pending</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="{{ route('requests.show', $request->id) }}" 
                                               class="inline-flex items-center px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition">
                                                View & Approve
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No pending leave requests</h3>
                            <p class="mt-1 text-sm text-gray-500">All leave requests have been approved!</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Cash Transactions Tab -->
            <div id="cash" class="hidden" role="tabpanel" aria-labelledby="cash-tab">
                <div class="p-6">
                    @if($cashLedger->count() > 0)
                        <div class="mb-4 text-sm text-gray-600">
                            You have <strong class="text-gray-900">{{ $cashLedger->count() }}</strong> pending cash transactions totaling <strong>Rs. {{ number_format($cashSummary['total_amount'], 0) }}</strong>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($cashLedger as $ledger)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ \Carbon\Carbon::parse($ledger->transaction_date)->format('M d, Y') }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded">
                                                {{ ucfirst(str_replace('_', ' ', $ledger->transaction_type)) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $ledger->description }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $ledger->fromAccount->account_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $ledger->toAccount->account_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-900">Rs. {{ number_format($ledger->amount, 2) }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="{{ route('fin.ledger.show', $ledger->id) }}" 
                                               class="inline-flex items-center px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition">
                                                View & Approve
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No pending cash transactions</h3>
                            <p class="mt-1 text-sm text-gray-500">All cash transactions have been approved!</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Online/Bank Transactions Tab -->
            <div id="online" class="hidden" role="tabpanel" aria-labelledby="online-tab">
                <div class="p-6">
                    @if($onlineLedger->count() > 0)
                        <div class="mb-4 text-sm text-gray-600">
                            You have <strong class="text-gray-900">{{ $onlineLedger->count() }}</strong> pending online/bank transactions totaling <strong>Rs. {{ number_format($onlineSummary['total_amount'], 0) }}</strong>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($onlineLedger as $ledger)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ \Carbon\Carbon::parse($ledger->transaction_date)->format('M d, Y') }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded">
                                                {{ ucfirst(str_replace('_', ' ', $ledger->transaction_type)) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $ledger->description }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $ledger->fromAccount->account_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $ledger->toAccount->account_name ?? 'N/A' }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-900">Rs. {{ number_format($ledger->amount, 2) }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <a href="{{ route('fin.ledger.show', $ledger->id) }}" 
                                               class="inline-flex items-center px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition">
                                                View & Approve
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-12">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No pending online/bank transactions</h3>
                            <p class="mt-1 text-sm text-gray-500">All online/bank transactions have been approved!</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tabName) {
    // Define all tabs and their colors
    const tabs = {
        'expenses': { color: 'yellow', element: 'expenses', button: 'expenses-tab' },
        'leaves': { color: 'purple', element: 'leaves', button: 'leaves-tab' },
        'cash': { color: 'green', element: 'cash', button: 'cash-tab' },
        'online': { color: 'blue', element: 'online', button: 'online-tab' }
    };
    
    // Hide all tab contents and remove active styles from all buttons
    Object.keys(tabs).forEach(key => {
        const tab = tabs[key];
        const tabElement = document.getElementById(tab.element);
        const buttonElement = document.getElementById(tab.button);
        
        if (tabElement) tabElement.classList.add('hidden');
        if (buttonElement) {
            buttonElement.classList.remove(`border-${tab.color}-500`, `text-${tab.color}-600`, 'font-bold');
            buttonElement.classList.add('border-transparent', 'text-gray-500');
        }
    });
    
    // Show selected tab and apply active styles
    if (tabs[tabName]) {
        const selectedTab = tabs[tabName];
        const tabElement = document.getElementById(selectedTab.element);
        const buttonElement = document.getElementById(selectedTab.button);
        
        if (tabElement) tabElement.classList.remove('hidden');
        if (buttonElement) {
            buttonElement.classList.add(`border-${selectedTab.color}-500`, `text-${selectedTab.color}-600`, 'font-bold');
            buttonElement.classList.remove('border-transparent', 'text-gray-500');
        }
    }
}
</script>
@endsection

