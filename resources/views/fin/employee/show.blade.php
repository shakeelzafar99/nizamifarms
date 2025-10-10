@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $account->account_name }}</h1>
        <a href="{{ route('fin.employee.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Employees
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

    <!-- Date Filter Section -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="text-sm font-medium text-gray-700">📅 Filter Period:</span>
            </div>
            
            <!-- Quick Filters -->
            <div class="flex gap-2">
                <button onclick="applyQuickFilter('today')" 
                        class="quick-filter-btn px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition">
                    Today
                </button>
                <button onclick="applyQuickFilter('week')" 
                        class="quick-filter-btn px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition">
                    This Week
                </button>
                <button onclick="applyQuickFilter('month')" 
                        class="quick-filter-btn px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition">
                    This Month
                </button>
                <button onclick="applyQuickFilter('all')" 
                        class="quick-filter-btn active px-3 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                    All Time
                </button>
            </div>
            
            <!-- Custom Date Range -->
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-600">or</span>
                <input type="date" id="dateFrom" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:outline-none">
                <span class="text-sm text-gray-600">to</span>
                <input type="date" id="dateTo" class="px-3 py-1.5 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:outline-none">
                <button onclick="applyCustomRange()" 
                        class="px-4 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 transition font-medium">
                    Apply
                </button>
                <button onclick="clearFilters()" 
                        class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-800 transition">
                    Clear
                </button>
            </div>
        </div>
        <div id="activeFilterText" class="mt-2 text-xs text-blue-600 hidden"></div>
    </div>

    <!-- Employee Summary Cards - Redesigned (6 cards) -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs text-gray-500 uppercase font-medium">💵 Invoices</div>
            <div class="text-lg font-bold text-green-600 mt-1">Rs. {{ number_format($summary['total_invoices'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-3" title="Expenses paid FROM employee's cash (not reimbursements)">
            <div class="text-xs text-gray-500 uppercase font-medium flex items-center gap-1">
                💸 Expenses
                <span class="text-gray-400 cursor-help" title="Expenses paid FROM employee's cash">ⓘ</span>
            </div>
            <div class="text-lg font-bold text-red-600 mt-1">Rs. {{ number_format($summary['total_expenses'], 2) }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-3">
            <div class="text-xs text-gray-500 uppercase font-medium">🏦 Deposits</div>
            <div class="text-lg font-bold text-blue-600 mt-1">Rs. {{ number_format($summary['total_deposits'], 2) }}</div>
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
            <div class="text-xs text-yellow-700 uppercase font-medium">⏳ Pending Reimbursements</div>
            <div class="text-lg font-bold text-yellow-900 mt-1">Rs. {{ number_format($expenseSummary['pending'], 2) }}</div>
        </div>
        <div class="border border-gray-200 rounded-lg p-3 {{ $summary['current_balance'] > 0 ? 'bg-green-50' : ($summary['current_balance'] < 0 ? 'bg-red-50' : 'bg-white') }}">
            <div class="text-xs text-gray-500 uppercase font-medium">💰 Current Balance</div>
            <div class="text-lg font-bold {{ $summary['current_balance'] > 0 ? 'text-green-600' : ($summary['current_balance'] < 0 ? 'text-red-600' : 'text-gray-900') }} mt-1">
                Rs. {{ number_format($summary['current_balance'], 2) }}
            </div>
        </div>
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
            <div class="text-xs text-gray-600 uppercase font-medium">🏷️ Account</div>
            <div class="text-sm font-mono font-bold text-gray-900 mt-1">{{ $account->account_code }}</div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-4 mb-6">
        <button onclick="openDepositModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
            💵 Record Deposit to NF Cash
        </button>
        <button onclick="openExpenseRequestModal()" 
                class="inline-flex items-center justify-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md" 
                style="background-color: #059669 !important; color: white !important;">
            <span style="color: white !important;">💰 Request Expense</span>
        </button>
        <button onclick="openAdjustmentModal()" class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-md">
            ⚖️ Make Adjustment
        </button>
    </div>

    <!-- Tab Navigation -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="-mb-px flex gap-8">
            <button onclick="switchTab('cash')" id="tab-cash" 
                    class="tab-button border-b-2 border-blue-500 py-3 px-1 text-sm font-medium text-blue-600">
                💵 Cash Transactions
            </button>
            <button onclick="switchTab('expenses')" id="tab-expenses" 
                    class="tab-button border-b-2 border-transparent py-3 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 hover:border-gray-300">
                💰 Expense Requests
                @if($expenseRequests->where('status', 'pending')->count() > 0)
                <span class="ml-2 px-2 py-1 bg-yellow-100 text-yellow-800 text-xs font-bold rounded-full">
                    {{ $expenseRequests->where('status', 'pending')->count() }}
                </span>
                @endif
            </button>
        </nav>
    </div>

    <!-- Tab Content: Cash Transactions -->
    <div id="content-cash" class="tab-content">
    
    <!-- Cash Accountability Alert (Days with undeposited cash) -->
    @php
        // Group transactions by date for accountability check
        $groupedByDate = [];
        foreach($ledger as $txn) {
            $date = $txn->transaction_date ? $txn->transaction_date->format('Y-m-d') : 'unknown';
            if (!isset($groupedByDate[$date])) {
                $groupedByDate[$date] = ['in' => 0, 'out' => 0, 'transactions' => []];
            }
            if ($txn->to_account_id === $account->id) {
                $groupedByDate[$date]['in'] += $txn->amount;
            } else {
                $groupedByDate[$date]['out'] += $txn->amount;
            }
            $groupedByDate[$date]['transactions'][] = $txn;
        }
        
        // Calculate net for each date
        $daysWithCash = [];
        foreach ($groupedByDate as $date => $data) {
            $net = $data['in'] - $data['out'];
            if ($net != 0) {
                $daysWithCash[$date] = $net;
            }
        }
        krsort($daysWithCash); // Newest first
    @endphp
    
    @if(count($daysWithCash) > 0)
    <div class="bg-gradient-to-r from-yellow-50 to-orange-50 border-2 border-yellow-300 rounded-lg p-4 mb-6">
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-yellow-900 flex items-center gap-2 mb-2">
                    ⚠️ Cash Accountability Alert
                    <span class="px-2 py-0.5 bg-yellow-200 text-yellow-900 text-xs font-bold rounded-full">{{ count($daysWithCash) }} day(s)</span>
                </h3>
                <p class="text-xs text-yellow-700 mb-3">The following days have undeposited or overspent cash:</p>
                <div class="space-y-1.5 max-h-24 overflow-y-auto">
                    @foreach(array_slice($daysWithCash, 0, 5, true) as $date => $net)
                        <div class="flex items-center justify-between py-1.5 px-3 bg-white rounded border {{ $net > 0 ? 'border-red-200' : 'border-yellow-200' }}">
                            <span class="text-xs font-medium text-gray-700">{{ \Carbon\Carbon::parse($date)->format('M j, Y') }}</span>
                            <span class="text-xs font-bold {{ $net > 0 ? 'text-red-700' : 'text-yellow-700' }}">
                                {{ $net > 0 ? '🔴 +' : '⚠️ ' }}Rs. {{ number_format(abs($net), 2) }}
                                {{ $net > 0 ? ' held' : ' short' }}
                            </span>
                        </div>
                    @endforeach
                    @if(count($daysWithCash) > 5)
                        <p class="text-xs text-yellow-600 italic mt-2">+ {{ count($daysWithCash) - 5 }} more day(s) below...</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Ledger Transactions with Grouping -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center mb-3">
                <h2 class="text-lg font-medium text-gray-900">Transaction History</h2>
                <div class="text-sm text-gray-500">
                    Total: {{ $ledger->total() }} transaction(s)
                </div>
            </div>
            
            <!-- Grouping Controls -->
            <div class="flex items-center gap-4 flex-wrap">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-gray-600">Group by:</span>
                    <button onclick="setGrouping('date')" id="btn-group-date" 
                            class="group-btn px-3 py-1 text-xs font-medium rounded-md bg-blue-600 text-white">
                        📅 Date
                    </button>
                    <button onclick="setGrouping('month')" id="btn-group-month" 
                            class="group-btn px-3 py-1 text-xs font-medium rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">
                        📆 Month
                    </button>
                    <button onclick="setGrouping('none')" id="btn-group-none" 
                            class="group-btn px-3 py-1 text-xs font-medium rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">
                        📋 List
                    </button>
                </div>
                
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="chk-nonzero-only" onchange="toggleNonZeroFilter()" 
                           class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-2 focus:ring-blue-500">
                    <span class="text-xs text-gray-600">Show only non-zero days</span>
                </label>
                
                <button onclick="toggleAllGroups()" id="btn-expand-all" 
                        class="ml-auto px-3 py-1 text-xs font-medium text-blue-600 border border-blue-300 rounded-md hover:bg-blue-50">
                    Expand All
                </button>
            </div>
        </div>
        
        <!-- Grouped Transaction View -->
        <div id="transaction-grouped-view" class="divide-y divide-gray-200">
            @php
                krsort($groupedByDate); // Newest first
            @endphp
            
            @forelse($groupedByDate as $date => $dateData)
                @php
                    $netAmount = $dateData['in'] - $dateData['out'];
                    $dateObj = \Carbon\Carbon::parse($date);
                    $displayDate = $dateObj->format('l, F j, Y'); // "Monday, February 9, 2025"
                    $monthYear = $dateObj->format('F Y'); // "February 2025"
                    $isZero = abs($netAmount) < 0.01;
                @endphp
                
                <div class="date-group" data-date="{{ $date }}" data-month="{{ $dateObj->format('Y-m') }}" data-net="{{ $netAmount }}">
                    <!-- Date Header (Collapsible) -->
                    <div class="px-6 py-3 bg-gray-50 hover:bg-gray-100 cursor-pointer transition" 
                         onclick="toggleDateGroup('{{ $date }}')">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <svg class="date-chevron w-5 h-5 text-gray-400 transition-transform" 
                                     id="chevron-{{ $date }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-800">{{ $displayDate }}</h3>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        In: Rs. {{ number_format($dateData['in'], 2) }} • 
                                        Out: Rs. {{ number_format($dateData['out'], 2) }} • 
                                        {{ count($dateData['transactions']) }} transaction(s)
                                    </p>
                                </div>
                            </div>
                            
                            <!-- Net Amount Badge -->
                            <div class="flex items-center gap-2">
                                @if($isZero)
                                    <span class="px-3 py-1 bg-green-100 text-green-800 text-xs font-bold rounded-full flex items-center gap-1">
                                        ✅ Balanced
                                    </span>
                                @elseif($netAmount > 0)
                                    <span class="px-3 py-1 bg-red-100 text-red-800 text-xs font-bold rounded-full flex items-center gap-1">
                                        🔴 +Rs. {{ number_format($netAmount, 2) }} held
                                    </span>
                                @else
                                    <span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-xs font-bold rounded-full flex items-center gap-1">
                                        ⚠️ Rs. {{ number_format(abs($netAmount), 2) }} short
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    
                    <!-- Transactions for this date (Initially collapsed) -->
                    <div id="transactions-{{ $date }}" class="hidden bg-white">
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead class="bg-gray-50 border-y border-gray-200">
                                    <tr>
                                        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cash In</th>
                                        <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cash Out</th>
                                        <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($dateData['transactions'] as $transaction)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-600">
                                                {{ $transaction->transaction_date ? $transaction->transaction_date->format('h:i A') : '-' }}
                                            </td>
                                            <td class="px-6 py-3 whitespace-nowrap">
                                                @php
                                                    $typeColors = [
                                                        'invoice' => 'bg-green-100 text-green-800',
                                                        'expense' => 'bg-red-100 text-red-800',
                                                        'employee_deposit' => 'bg-blue-100 text-blue-800',
                                                        'transfer' => 'bg-purple-100 text-purple-800',
                                                        'adjustment' => 'bg-orange-100 text-orange-800',
                                                    ];
                                                    $color = $typeColors[$transaction->transaction_type] ?? 'bg-gray-100 text-gray-800';
                                                @endphp
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $color }}">
                                                    {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-3 text-sm text-gray-900">
                                                {{ $transaction->description }}
                                                @if($transaction->comments)
                                                    <div class="text-xs text-gray-500 mt-1">💬 {{ $transaction->comments }}</div>
                                                @endif
                                            </td>
                                            <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                                @if($transaction->to_account_id === $account->id)
                                                    Rs. {{ number_format($transaction->amount, 2) }}
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                                @if($transaction->from_account_id === $account->id)
                                                    Rs. {{ number_format($transaction->amount, 2) }}
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-bold {{ $transaction->running_balance > 0 ? 'text-green-600' : ($transaction->running_balance < 0 ? 'text-red-600' : 'text-gray-900') }}">
                                                Rs. {{ number_format($transaction->running_balance, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-6 py-8 text-center text-sm text-gray-500">
                    No transactions found for this employee.
                </div>
            @endforelse
        </div>
        
        <!-- Traditional List View (Initially hidden) -->
        <div id="transaction-list-view" class="hidden overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cash In</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cash Out</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($ledger as $transaction)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $transaction->transaction_date ? $transaction->transaction_date->format('M j, Y') : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $typeColors = [
                                        'invoice' => 'bg-green-100 text-green-800',
                                        'expense' => 'bg-red-100 text-red-800',
                                        'employee_deposit' => 'bg-blue-100 text-blue-800',
                                        'transfer' => 'bg-purple-100 text-purple-800',
                                        'adjustment' => 'bg-orange-100 text-orange-800',
                                    ];
                                    $color = $typeColors[$transaction->transaction_type] ?? 'bg-gray-100 text-gray-800';
                                @endphp
                                <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $color }}">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->transaction_type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                {{ $transaction->description }}
                                @if($transaction->comments)
                                    <div class="text-xs text-gray-500 mt-1">{{ $transaction->comments }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-green-600">
                                @if($transaction->to_account_id === $account->id)
                                    Rs. {{ number_format($transaction->amount, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-red-600">
                                @if($transaction->from_account_id === $account->id)
                                    Rs. {{ number_format($transaction->amount, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold {{ $transaction->running_balance > 0 ? 'text-green-600' : ($transaction->running_balance < 0 ? 'text-red-600' : 'text-gray-900') }}">
                                Rs. {{ number_format($transaction->running_balance, 2) }}
                            </td>
                        </tr>
                    @endforeach
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

    </div> <!-- End Tab Content: Cash Transactions -->

    <!-- Tab Content: Expense Requests -->
    <div id="content-expenses" class="tab-content hidden">
        
        <!-- Expense Summary Cards -->
        <div class="bg-gradient-to-r from-yellow-50 to-orange-50 border border-yellow-200 rounded-lg p-6 mb-6">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">💰 Expense Requests Summary</h3>
            <div class="grid grid-cols-3 gap-6">
                <div>
                    <p class="text-xs text-yellow-700 uppercase font-medium mb-1">Pending Approval</p>
                    <p class="text-2xl font-bold text-yellow-900">Rs. {{ number_format($expenseSummary['pending'], 2) }}</p>
                    <p class="text-xs text-yellow-600 mt-1">{{ $expenseRequests->where('status', 'pending')->count() }} request(s)</p>
                </div>
                <div>
                    <p class="text-xs text-green-700 uppercase font-medium mb-1">Approved (Unpaid)</p>
                    <p class="text-2xl font-bold text-green-900">Rs. {{ number_format($expenseSummary['approved_unpaid'], 2) }}</p>
                    <p class="text-xs text-green-600 mt-1">{{ $expenseRequests->where('status', 'approved')->filter(fn($r) => is_null($r->ledger_transaction_id))->count() }} request(s)</p>
                </div>
                <div>
                    <p class="text-xs text-blue-700 uppercase font-medium mb-1">Paid</p>
                    <p class="text-2xl font-bold text-blue-900">Rs. {{ number_format($expenseSummary['paid'], 2) }}</p>
                    <p class="text-xs text-blue-600 mt-1">{{ $expenseRequests->whereNotNull('ledger_transaction_id')->count() }} request(s)</p>
                </div>
            </div>
        </div>

        <!-- Expense Requests Table -->
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-medium text-gray-900">Expense Request History</h2>
            </div>
            
            @if($expenseRequests->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($expenseRequests as $req)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                {{ $req->request_number }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                {{ $req->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $req->expense_category ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                Rs. {{ number_format($req->amount, 2) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($req->status === 'pending')
                                    @if($req->level_1_status === 'pending')
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            Pending L1
                                        </span>
                                    @elseif($req->level_2_status === 'pending')
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 text-orange-800">
                                            Pending L2
                                        </span>
                                    @else
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            Pending
                                        </span>
                                    @endif
                                @elseif($req->status === 'approved')
                                    @if($req->ledger_transaction_id)
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                            ✓ Paid
                                        </span>
                                    @else
                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            Approved
                                        </span>
                                    @endif
                                @elseif($req->status === 'rejected')
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                        Rejected
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                {{ $req->createdBy->fullname ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                <a href="{{ route('requests.show', $req->id) }}" class="text-blue-600 hover:text-blue-900 font-medium">
                                    View Details
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="px-6 py-8 text-center">
                <p class="text-gray-500 text-sm">No expense requests found for this employee.</p>
            </div>
            @endif
        </div>

    </div> <!-- End Tab Content: Expense Requests -->

</div> <!-- End max-w-7xl container -->

<!-- Inline Expense Category Modal for Employee Form -->
<div id="empInlineExpenseCategoryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 99999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">➕ Add New Expense Category</h2>
                <button onclick="closeEmpInlineExpenseCategoryModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="emp_inline_category_name"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500"
                           placeholder="e.g., Fuel, Marketing, Travel">
                </div>
                
                <div class="p-3 bg-purple-50 border border-purple-200 rounded-md">
                    <p class="text-xs text-purple-800">
                        ℹ️ <strong>System will automatically:</strong>
                    </p>
                    <ul class="text-xs text-purple-700 mt-1 ml-4 list-disc">
                        <li>Create an expense account (e.g., EXP_FUEL)</li>
                        <li>Add to expense type dropdown</li>
                        <li>Make it available for all expense requests</li>
                    </ul>
                </div>
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeEmpInlineExpenseCategoryModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="button" onclick="submitEmpInlineCategory()" class="flex-1 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-md">
                        ✓ Create & Select
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

<!-- Modals (Portalized outside content to avoid clipping) -->

<!-- Record Deposit Modal -->
<div id="depositModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">💵 Record Deposit to NF Cash</h2>
                <button onclick="closeDepositModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form action="{{ route('fin.employee.deposit', $account->id) }}" method="POST">
                @csrf
                <div class="space-y-4">
                
                @if($userRole !== 'rider')
                <!-- MANAGERS/ADMINS: Full Form -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" max="{{ $account->current_balance }}" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Current balance: Rs. {{ number_format($account->current_balance, 2) }}</p>
                </div>
                
                <!-- Destination Account (Managers/Admins) -->
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <label class="block text-sm font-medium text-blue-900 mb-2">💰 Deposit To (Default: NF Cash):</label>
                    <select name="destination_account_id" 
                            class="w-full px-3 py-2 border border-blue-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">NF Cash (Main Till)</option>
                        @php
                            $destinationAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                                ->whereIn('account_code', ['ONLINE', 'NF_CASH'])
                                ->orWhere('account_category', 'employee_cash')
                                ->orderBy('account_name')
                                ->get();
                        @endphp
                        @foreach($destinationAccounts as $dest)
                            <option value="{{ $dest->id }}">
                                {{ $dest->account_name }} (Rs. {{ number_format($dest->current_balance, 2) }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-blue-700 mt-1">⚠️ All deposits require approval. Approver can change destination.</p>
                </div>
                @else
                <!-- RIDERS: Simplified Form -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required readonly
                           class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 cursor-not-allowed">
                    <p class="text-xs text-gray-500 mt-1">Date set by system</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" max="{{ $account->current_balance }}" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Current balance: Rs. {{ number_format($account->current_balance, 2) }}</p>
                </div>
                
                <!-- Fixed Destination for Riders -->
                <input type="hidden" name="destination_account_id" value="">
                <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                    <p class="text-sm text-yellow-900 font-medium">💰 Depositing to: NF Cash (Main Till)</p>
                    <p class="text-xs text-yellow-700 mt-1">⏳ Deposit will be approved by manager before reflecting in accounts</p>
                </div>
                @endif
                
                {{-- Short/Over and Description fields hidden as per user request --}}
                <input type="hidden" name="short_over" value="">
                <input type="hidden" name="description" value="">
                
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeDepositModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md">
                        💾 Record Deposit
                    </button>
                </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manual Adjustment Modal -->
<div id="adjustmentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">⚖️ Manual Adjustment</h2>
                <button onclick="closeAdjustmentModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form action="{{ route('fin.employee.adjustment', $account->id) }}" method="POST">
                @csrf
                <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                    <select name="type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="increase">Increase Balance</option>
                        <option value="decrease">Decrease Balance</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reason (required)</label>
                    <textarea name="reason" rows="3" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeAdjustmentModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-md">
                        ✅ Make Adjustment
                    </button>
                </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Request Expense Modal -->
<div id="expenseRequestModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">💰 Request Expense for {{ $account->user->fullname ?? $account->account_name }}</h2>
                <button onclick="closeExpenseRequestModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form action="{{ route('fin.employee.expense-request', $account->id) }}" method="POST">
                @csrf
                <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Expense Type <span class="text-red-500">*</span></label>
                    <select name="expense_category" id="emp_expense_category" required onchange="handleEmpExpenseCategoryChange()" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="">Select Expense Type</option>
                        @if(count($expenseCategories) > 0)
                            @foreach($expenseCategories as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        @else
                            {{-- Fallback options if database is empty --}}
                            <option value="Petrol">Petrol</option>
                            <option value="Rent">Rent</option>
                            <option value="Office Supplies">Office Supplies</option>
                        @endif
                        <option value="__ADD_NEW__" style="background-color: #f3f4f6; font-weight: bold; color: #059669;">➕ Add New Category...</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Select the type of expense, or add a new category</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.) <span class="text-red-500">*</span></label>
                    <input type="number" name="amount" step="0.01" min="0.01" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="4" placeholder="Provide detailed information about this expense"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                </div>
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <p class="text-xs text-blue-800">
                        <strong>Note:</strong> This request will go through L1→L2 approval workflow. 
                        Upon approval, the expense will be paid from the Expense Fund and posted to the ledger.
                    </p>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="button" onclick="closeExpenseRequestModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md">
                        💸 Submit Request
                    </button>
                </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDepositModal() {
    console.log('openDepositModal called');
    const modal = document.getElementById('depositModal');
    console.log('Modal element:', modal);
    if (!modal) {
        console.error('depositModal element not found!');
        alert('Error: Modal not found. Please refresh the page.');
        return;
    }
    
    // Portalize to body if not already there
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    // Force proper display
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999',
        backgroundColor: 'rgba(0,0,0,0.5)'
    });
    
    // Scroll modal content to top
    const modalContent = modal.querySelector('.bg-white');
    if (modalContent) modalContent.scrollTop = 0;
    
    document.body.style.overflow = 'hidden';
    console.log('Modal opened successfully');
}
function closeDepositModal() {
    const modal = document.getElementById('depositModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}
function openAdjustmentModal() {
    const modal = document.getElementById('adjustmentModal');
    if (!modal) return;
    
    // Portalize to body
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    // Force proper display
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999',
        backgroundColor: 'rgba(0,0,0,0.5)'
    });
    
    const modalContent = modal.querySelector('.bg-white');
    if (modalContent) modalContent.scrollTop = 0;
    document.body.style.overflow = 'hidden';
}
function closeAdjustmentModal() {
    const modal = document.getElementById('adjustmentModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function openExpenseRequestModal() {
    const modal = document.getElementById('expenseRequestModal');
    if (!modal) return;
    
    // Portalize to body
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    // Force proper display
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999',
        backgroundColor: 'rgba(0,0,0,0.5)'
    });
    
    const modalContent = modal.querySelector('.bg-white');
    if (modalContent) modalContent.scrollTop = 0;
    document.body.style.overflow = 'hidden';
}
function closeExpenseRequestModal() {
    const modal = document.getElementById('expenseRequestModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Tab switching function
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active styles from all tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Add active styles to selected tab
    const activeTab = document.getElementById('tab-' + tabName);
    activeTab.classList.remove('border-transparent', 'text-gray-500');
    activeTab.classList.add('border-blue-500', 'text-blue-600');
}

// Handle expense category change (with "+ Add New" option)
function handleEmpExpenseCategoryChange() {
    const select = document.getElementById('emp_expense_category');
    const selectedValue = select.value;
    
    if (selectedValue === '__ADD_NEW__') {
        // Open inline modal
        openEmpInlineExpenseCategoryModal();
        // Reset selection
        select.value = '';
    }
}

function openEmpInlineExpenseCategoryModal() {
    document.getElementById('empInlineExpenseCategoryModal').classList.remove('hidden');
    document.getElementById('empInlineExpenseCategoryModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEmpInlineExpenseCategoryModal() {
    document.getElementById('empInlineExpenseCategoryModal').classList.add('hidden');
    document.getElementById('empInlineExpenseCategoryModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function submitEmpInlineCategory() {
    const categoryName = document.getElementById('emp_inline_category_name').value.trim();
    
    if (!categoryName) {
        alert('Please enter a category name');
        return;
    }
    
    // Show loading
    const submitBtn = document.querySelector('#empInlineExpenseCategoryModal button[type="button"]:last-child');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Creating...';
    submitBtn.disabled = true;
    
    // Submit via AJAX
    fetch('{{ route("fin.expense-category.store") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ category_name: categoryName })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success || data.message.includes('successfully')) {
            // Add to dropdown
            const select = document.getElementById('emp_expense_category');
            const newOption = document.createElement('option');
            newOption.value = categoryName;
            newOption.textContent = categoryName;
            
            // Insert before the "Add New" option
            const addNewOption = select.querySelector('option[value="__ADD_NEW__"]');
            select.insertBefore(newOption, addNewOption);
            
            // Select the new option
            select.value = categoryName;
            
            // Close modal
            closeEmpInlineExpenseCategoryModal();
            document.getElementById('emp_inline_category_name').value = '';
            
            alert('✓ Category "' + categoryName + '" created successfully!');
        } else {
            alert('Error: ' + (data.message || 'Failed to create category'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const depositModal = document.getElementById('depositModal');
    const adjustmentModal = document.getElementById('adjustmentModal');
    const expenseRequestModal = document.getElementById('expenseRequestModal');
    
    if (event.target === depositModal) {
        closeDepositModal();
    }
    if (event.target === adjustmentModal) {
        closeAdjustmentModal();
    }
    if (event.target === expenseRequestModal) {
        closeExpenseRequestModal();
    }
});

// Date Filter Functions
function applyQuickFilter(period) {
    const today = new Date();
    let dateFrom, dateTo;
    
    switch(period) {
        case 'today':
            dateFrom = dateTo = formatDate(today);
            updateFilterUI('Today');
            break;
        case 'week':
            const weekStart = new Date(today);
            weekStart.setDate(today.getDate() - today.getDay()); // Start of week (Sunday)
            dateFrom = formatDate(weekStart);
            dateTo = formatDate(today);
            updateFilterUI('This Week');
            break;
        case 'month':
            dateFrom = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
            dateTo = formatDate(today);
            updateFilterUI('This Month');
            break;
        case 'all':
            // Clear filters and reload
            window.location.href = window.location.pathname;
            return;
    }
    
    // Apply filter
    const url = new URL(window.location.href);
    url.searchParams.set('date_from', dateFrom);
    url.searchParams.set('date_to', dateTo);
    window.location.href = url.toString();
}

function applyCustomRange() {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    if (!dateFrom || !dateTo) {
        alert('Please select both start and end dates');
        return;
    }
    
    if (dateFrom > dateTo) {
        alert('Start date cannot be after end date');
        return;
    }
    
    const url = new URL(window.location.href);
    url.searchParams.set('date_from', dateFrom);
    url.searchParams.set('date_to', dateTo);
    window.location.href = url.toString();
}

function clearFilters() {
    window.location.href = window.location.pathname;
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function updateFilterUI(filterName) {
    // Update active button styling
    document.querySelectorAll('.quick-filter-btn').forEach(btn => {
        btn.classList.remove('active', 'bg-blue-600', 'text-white');
        btn.classList.add('border-gray-300');
    });
    
    // Show filter text
    const filterText = document.getElementById('activeFilterText');
    filterText.textContent = `Showing: ${filterName}`;
    filterText.classList.remove('hidden');
}

// Check for active filters on page load
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const dateFrom = urlParams.get('date_from');
    const dateTo = urlParams.get('date_to');
    
    if (dateFrom && dateTo) {
        // Populate date inputs
        document.getElementById('dateFrom').value = dateFrom;
        document.getElementById('dateTo').value = dateTo;
        
        // Show active filter
        const filterText = document.getElementById('activeFilterText');
        filterText.textContent = `Showing: ${dateFrom} to ${dateTo}`;
        filterText.classList.remove('hidden');
        
        // Update button styling
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            btn.classList.remove('active', 'bg-blue-600', 'text-white');
        });
    }
});

// ============================================
// Transaction Grouping Functions
// ============================================

// Toggle individual date group
function toggleDateGroup(date) {
    const transactionsDiv = document.getElementById('transactions-' + date);
    const chevron = document.getElementById('chevron-' + date);
    
    if (transactionsDiv.classList.contains('hidden')) {
        transactionsDiv.classList.remove('hidden');
        chevron.style.transform = 'rotate(90deg)';
    } else {
        transactionsDiv.classList.add('hidden');
        chevron.style.transform = 'rotate(0deg)';
    }
}

// Expand/Collapse all groups
let allExpanded = false;
function toggleAllGroups() {
    const btn = document.getElementById('btn-expand-all');
    allExpanded = !allExpanded;
    
    document.querySelectorAll('.date-group').forEach(group => {
        const date = group.getAttribute('data-date');
        const transactionsDiv = document.getElementById('transactions-' + date);
        const chevron = document.getElementById('chevron-' + date);
        
        if (allExpanded) {
            transactionsDiv.classList.remove('hidden');
            chevron.style.transform = 'rotate(90deg)';
        } else {
            transactionsDiv.classList.add('hidden');
            chevron.style.transform = 'rotate(0deg)';
        }
    });
    
    btn.textContent = allExpanded ? 'Collapse All' : 'Expand All';
}

// Set grouping mode (date, month, none)
let currentGrouping = 'date';
function setGrouping(mode) {
    currentGrouping = mode;
    
    // Update button styles
    document.querySelectorAll('.group-btn').forEach(btn => {
        btn.classList.remove('bg-blue-600', 'text-white');
        btn.classList.add('border', 'border-gray-300', 'text-gray-700');
    });
    
    const activeBtn = document.getElementById('btn-group-' + mode);
    activeBtn.classList.remove('border', 'border-gray-300', 'text-gray-700');
    activeBtn.classList.add('bg-blue-600', 'text-white');
    
    // Show/hide appropriate views
    const groupedView = document.getElementById('transaction-grouped-view');
    const listView = document.getElementById('transaction-list-view');
    const expandBtn = document.getElementById('btn-expand-all');
    const nonZeroCheckbox = document.getElementById('chk-nonzero-only').parentElement;
    
    if (mode === 'none') {
        // Show list view
        groupedView.classList.add('hidden');
        listView.classList.remove('hidden');
        expandBtn.classList.add('hidden');
        nonZeroCheckbox.classList.add('hidden');
    } else {
        // Show grouped view
        groupedView.classList.remove('hidden');
        listView.classList.add('hidden');
        expandBtn.classList.remove('hidden');
        nonZeroCheckbox.classList.remove('hidden');
        
        if (mode === 'month') {
            applyMonthGrouping();
        } else {
            applyDateGrouping();
        }
    }
}

// Apply date grouping (already rendered by PHP)
function applyDateGrouping() {
    document.querySelectorAll('.date-group').forEach(group => {
        group.style.display = '';
    });
    applyNonZeroFilter(); // Reapply filter if active
}

// Apply month grouping
function applyMonthGrouping() {
    const groups = document.querySelectorAll('.date-group');
    const groupedByMonth = {};
    
    // Group by month
    groups.forEach(group => {
        const month = group.getAttribute('data-month');
        if (!groupedByMonth[month]) {
            groupedByMonth[month] = [];
        }
        groupedByMonth[month].push(group);
    });
    
    // Hide all groups first
    groups.forEach(group => group.style.display = 'none');
    
    // Show only first date of each month, hide rest
    Object.keys(groupedByMonth).sort().reverse().forEach(month => {
        const monthGroups = groupedByMonth[month];
        // For now, just show all groups (full month grouping would require backend changes)
        monthGroups.forEach(group => {
            group.style.display = '';
        });
    });
    
    applyNonZeroFilter(); // Reapply filter if active
}

// Toggle non-zero filter
function toggleNonZeroFilter() {
    applyNonZeroFilter();
}

function applyNonZeroFilter() {
    const checkbox = document.getElementById('chk-nonzero-only');
    const isChecked = checkbox.checked;
    
    document.querySelectorAll('.date-group').forEach(group => {
        const net = parseFloat(group.getAttribute('data-net'));
        
        if (isChecked) {
            // Show only non-zero
            if (Math.abs(net) < 0.01) {
                group.style.display = 'none';
            } else {
                group.style.display = '';
            }
        } else {
            // Show all
            if (currentGrouping === 'date') {
                group.style.display = '';
            }
        }
    });
}
</script>
