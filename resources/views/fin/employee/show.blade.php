@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $account->account_name }}</h1>
        <a href="{{ route('fin.employee.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Employee Cash
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

    <!-- Summary Cards -->
    @if($isEmployeeAccount)
        <!-- Employee Summary Cards - Custom 3-3 Layout -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
            <!-- LEFT SIDE: Cash Flow -->
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500 uppercase font-medium">💵 Invoices</div>
                <div class="text-lg font-bold text-green-600 mt-1">Rs. {{ number_format($summary['total_invoices'], 2) }}</div>
            </div>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                <div class="text-xs text-yellow-700 uppercase font-medium">⏳ Pending</div>
                <div class="text-lg font-bold text-yellow-900 mt-1">Rs. {{ number_format($expenseSummary['pending'], 2) }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500 uppercase font-medium">🏦 Deposits</div>
                <div class="text-lg font-bold text-blue-600 mt-1">Rs. {{ number_format($summary['total_deposits'], 2) }}</div>
            </div>
            
            <!-- RIGHT SIDE: Expense Tracking & Balance -->
            <div class="bg-orange-50 border border-orange-200 rounded-lg p-3" title="Expenses paid from rider's own balance (affects his balance)">
                <div class="text-xs text-orange-700 uppercase font-medium">💸 Expense from Rider Balance</div>
                <div class="text-lg font-bold text-orange-900 mt-1">Rs. {{ number_format($expenseSummary['expense_from_rider_balance'], 2) }}</div>
            </div>
            <div class="border border-gray-200 rounded-lg p-3 {{ $summary['current_balance'] > 0 ? 'bg-green-50' : ($summary['current_balance'] < 0 ? 'bg-red-50' : 'bg-white') }}">
                <div class="text-xs text-gray-500 uppercase font-medium">💰 Balance</div>
                <div class="text-lg font-bold {{ $summary['current_balance'] > 0 ? 'text-green-600' : ($summary['current_balance'] < 0 ? 'text-red-600' : 'text-gray-900') }} mt-1">
                    Rs. {{ number_format($summary['current_balance'], 2) }}
                </div>
            </div>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-3" title="All approved expenses paid from other sources (NF Cash, Expense Fund, etc.) - does NOT affect rider balance">
                <div class="text-xs text-purple-700 uppercase font-medium">💰 Expense Amount</div>
                <div class="text-lg font-bold text-purple-900 mt-1">Rs. {{ number_format($expenseSummary['expense_amount'], 2) }}</div>
            </div>
        </div>
    @else
        <!-- Company Account Summary Cards - Reorganized Layout -->
        
        @if($account->account_code === 'ONLINE')
            <!-- ONLINE BANK: Simplified Cards (Only 2 cards) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <!-- Card 1: Current Balance -->
                <div class="bg-white border border-gray-200 rounded-lg p-4 cursor-pointer hover:shadow-md transition-shadow" 
                     onclick="filterTransactions('all')" 
                     data-filter-type="all">
                    <div class="text-xs text-gray-500 uppercase font-medium">💰 Current Balance</div>
                    <div class="text-2xl font-bold {{ $summary['current_balance'] > 0 ? 'text-green-600' : ($summary['current_balance'] < 0 ? 'text-red-600' : 'text-gray-900') }} mt-2">
                        Rs. {{ number_format($summary['current_balance'], 2) }}
                    </div>
                    <div class="text-xs text-gray-500 mt-1">Online Bank Balance</div>
                </div>

                <!-- Card 2: Pending Online Approvals (Clickable to open modal) -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 cursor-pointer hover:shadow-md transition-shadow" 
                     onclick="openOnlineApprovalsModal()" 
                     data-filter-type="pending">
                    <div class="text-xs text-yellow-700 uppercase font-medium">⏳ Online Approvals Pending</div>
                    <div class="text-2xl font-bold text-yellow-900 mt-2">Rs. {{ number_format($summary['total_pending'] ?? 0, 2) }}</div>
                    <div class="text-xs text-yellow-600 mt-1">
                        {{ count($summary['pending_approvals'] ?? []) }} invoice(s) awaiting approval
                    </div>
                </div>
            </div>
        @else
            <!-- OTHER COMPANY ACCOUNTS: Original 5 Cards -->
            <!-- Row 1: Quick Summary Cards (5 smaller cards) -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
                <!-- Card 1: Current Balance -->
                <div class="bg-white border border-gray-200 rounded-lg p-3 cursor-pointer hover:shadow-md transition-shadow" 
                     onclick="filterTransactions('all')" 
                     data-filter-type="all">
                    <div class="text-xs text-gray-500 uppercase font-medium">💰 Current Balance</div>
                    <div class="text-xl font-bold {{ $summary['current_balance'] > 0 ? 'text-green-600' : ($summary['current_balance'] < 0 ? 'text-red-600' : 'text-gray-900') }} mt-1">
                        Rs. {{ number_format($summary['current_balance'], 2) }}
                    </div>
                </div>

                <!-- Card 2: Pending Approvals -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 cursor-pointer hover:shadow-md transition-shadow" 
                     onclick="filterTransactions('pending')" 
                     data-filter-type="pending">
                    <div class="text-xs text-yellow-700 uppercase font-medium">⏳ Pending</div>
                    <div class="text-xl font-bold text-yellow-900 mt-1">Rs. {{ number_format($summary['total_pending'] ?? 0, 2) }}</div>
                    <div class="text-xs text-yellow-600 mt-0.5">Awaiting approval</div>
                </div>

                <!-- Card 3: Short Cash -->
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-3" 
                     title="Expenses paid from rider balance but not yet settled">
                    <div class="text-xs text-orange-700 uppercase font-medium">💸 Short Cash</div>
                    <div class="text-xl font-bold text-orange-900 mt-1">Rs. {{ number_format($summary['short_cash'] ?? 0, 2) }}</div>
                    <div class="text-xs text-orange-600 mt-0.5">Unsettled</div>
                </div>

                <!-- Card 4: Cash Invoices -->
                <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 cursor-pointer hover:shadow-md transition-shadow" 
                     onclick="filterTransactions('cash_invoices')" 
                     data-filter-type="cash_invoices"
                     title="Total value of cash/COD invoices delivered">
                    <div class="text-xs text-purple-700 uppercase font-medium">💵 Cash Invoices</div>
                    <div class="text-xl font-bold text-purple-900 mt-1">Rs. {{ number_format($summary['cash_invoices'] ?? 0, 2) }}</div>
                    <div class="text-xs text-purple-600 mt-0.5">Delivered</div>
                </div>

                <!-- Card 5: Riders Balance (NEW) -->
                <div class="bg-teal-50 border border-teal-200 rounded-lg p-3"
                     title="Total cash currently held by all riders">
                    <div class="text-xs text-teal-700 uppercase font-medium">👥 Riders Balance</div>
                    <div class="text-xl font-bold text-teal-900 mt-1">Rs. {{ number_format($summary['riders_balance'] ?? 0, 2) }}</div>
                    <div class="text-xs text-teal-600 mt-0.5">With riders</div>
                </div>
            </div>
        @endif

        <!-- Row 2: Detailed Breakdown Cards (2 larger cards with dropdowns) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <!-- Card: Total Cash IN (Expandable with sub-values) -->
            <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                <div class="flex justify-between items-center cursor-pointer" onclick="toggleCardBreakdown('cashInCard')">
                    <div class="text-xs text-green-700 uppercase font-medium">📥 Total Cash In</div>
                    <svg id="cashInIcon" class="w-4 h-4 text-green-700 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
            </div>
                <div class="text-2xl font-bold text-green-900 mt-2 cursor-pointer hover:text-green-700" 
                     onclick="filterTransactions('cash_in')">
                    Rs. {{ number_format(($summary['cash_in']['total'] ?? 0), 2) }}
                </div>
                
                <!-- Breakdown (Initially Hidden) -->
                <div id="cashInCard" class="mt-3 pt-3 border-t border-green-300 space-y-2 hidden">
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-green-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('deposits')">
                        <span class="text-green-700">💵 Deposits</span>
                        <span class="font-medium text-green-900">Rs. {{ number_format(($summary['cash_in']['deposits'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-green-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('settlements')">
                        <span class="text-green-700">🔄 Settlements</span>
                        <span class="font-medium text-green-900">Rs. {{ number_format(($summary['cash_in']['settlements'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-green-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('transfers_in')">
                        <span class="text-green-700">🔀 Transfers In</span>
                        <span class="font-medium text-green-900">Rs. {{ number_format(($summary['cash_in']['transfers_in'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-green-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('invoices')">
                        <span class="text-green-700">📄 Invoices</span>
                        <span class="font-medium text-green-900">Rs. {{ number_format(($summary['cash_in']['invoices'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-green-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('others_in')">
                        <span class="text-green-700">📦 Others</span>
                        <span class="font-medium text-green-900">Rs. {{ number_format(($summary['cash_in']['others_in'] ?? 0), 2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Card: Total Cash OUT (Expandable with sub-values) -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex justify-between items-center cursor-pointer" onclick="toggleCardBreakdown('cashOutCard')">
                    <div class="text-xs text-blue-700 uppercase font-medium">📤 Total Cash Out</div>
                    <svg id="cashOutIcon" class="w-4 h-4 text-blue-700 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
            </div>
                <div class="text-2xl font-bold text-blue-900 mt-2 cursor-pointer hover:text-blue-700" 
                     onclick="filterTransactions('cash_out')">
                    Rs. {{ number_format(($summary['cash_out']['total'] ?? 0), 2) }}
                </div>
                
                <!-- Breakdown (Initially Hidden) -->
                <div id="cashOutCard" class="mt-3 pt-3 border-t border-blue-300 space-y-2 hidden">
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-blue-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('unsettled_expenses')">
                        <span class="text-blue-700">💸 Unsettled Expenses</span>
                        <span class="font-medium text-blue-900">Rs. {{ number_format(($summary['cash_out']['unsettled_expenses'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-blue-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('vendor_payments')">
                        <span class="text-blue-700">🏪 Vendor Payments</span>
                        <span class="font-medium text-blue-900">Rs. {{ number_format(($summary['cash_out']['vendor_payments'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-blue-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('transfers_out')">
                        <span class="text-blue-700">🔀 Transfers Out</span>
                        <span class="font-medium text-blue-900">Rs. {{ number_format(($summary['cash_out']['transfers_out'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-blue-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('expenses_ledger')">
                        <span class="text-blue-700">📋 Expenses (Ledger)</span>
                        <span class="font-medium text-blue-900">Rs. {{ number_format(($summary['cash_out']['expenses_ledger'] ?? 0), 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm cursor-pointer hover:bg-blue-100 p-1 rounded" 
                         onclick="event.stopPropagation(); filterTransactions('others_out')">
                        <span class="text-blue-700">📦 Others</span>
                        <span class="font-medium text-blue-900">Rs. {{ number_format(($summary['cash_out']['others_out'] ?? 0), 2) }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Action Buttons -->
    @if($isEmployeeAccount)
    <div class="flex gap-4 mb-6">
        <button onclick="openSettlementModal()" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md shadow-md" style="background-color: #7c3aed !important; color: white !important;">
            <span style="color: white !important;">📋 Settle & Deposit</span>
        </button>
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
    @else
    <!-- Company Account Action Buttons -->
    <div class="flex gap-4 mb-6">
        @if(in_array($account->account_code, ['NF_CASH', 'CASH_NF_MAIN_TILL']))
        <a href="{{ route('fin.employee.all-outstanding-invoices') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md shadow-md" style="background-color: #7c3aed !important; color: white !important;">
            <span style="color: white !important;">📋 View All Outstanding Invoices</span>
        </a>
        @endif
        <button onclick="openCompanyReceiveModal()" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md" style="background-color: #059669 !important; color: white !important;">
            <span style="color: white !important;">💵 Record Receipt</span>
        </button>
        <button onclick="openCompanyPaymentModal()" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md" style="background-color: #2563eb !important; color: white !important;">
            <span style="color: white !important;">💳 Record Payment</span>
        </button>
        <button onclick="openCompanyTransferModal()" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md" style="background-color: #7c3aed !important; color: white !important;">
            <span style="color: white !important;">🔄 Transfer Between Accounts</span>
        </button>
    </div>
    @endif

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
    
    {{-- Cash Accountability Alert - HIDDEN (user can see in date groups anyway) --}}
    @if(false && count($daysWithCash) > 0)
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
                                        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cash In</th>
                                        <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cash Out</th>
                                        <th class="px-6 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($dateData['transactions'] as $transaction)
                                        @php
                                            // Determine direction for filtering
                                            $direction = $transaction->to_account_id === $account->id ? 'in' : 'out';
                                        @endphp
                                        <tr class="hover:bg-gray-50" 
                                            data-transaction-type="{{ $transaction->transaction_type }}"
                                            data-approval-status="{{ $transaction->approval_status ?? 'approved' }}"
                                            data-direction="{{ $direction }}"
                                            data-description="{{ $transaction->description }}">
                                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-600">
                                                {{ $transaction->created_at ? $transaction->created_at->format('h:i A') : '-' }}
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
                                            <td class="px-6 py-3 whitespace-nowrap">
                                                @php
                                                    $status = $transaction->approval_status ?? 'approved';
                                                    $statusColors = [
                                                        'pending' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
                                                        'approved' => 'bg-green-100 text-green-800 border-green-300',
                                                        'rejected' => 'bg-red-100 text-red-800 border-red-300',
                                                    ];
                                                    $statusColor = $statusColors[$status] ?? 'bg-gray-100 text-gray-800 border-gray-300';
                                                @endphp
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full border {{ $statusColor }}">
                                                    @if($status === 'pending')
                                                        ⏳ Pending
                                                    @elseif($status === 'approved')
                                                        ✅ Approved
                                                    @elseif($status === 'rejected')
                                                        ❌ Rejected
                                                    @else
                                                        {{ ucfirst($status) }}
                                                    @endif
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
        
        <!-- Expense Requests Filter Bar - Compact -->
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h3 class="text-sm font-semibold text-gray-700">📋 Filter by Status:</h3>
                <div class="flex gap-2 flex-wrap">
                    <button onclick="resetExpenseFilter()" class="filter-btn px-3 py-1.5 text-xs font-medium border border-gray-300 rounded-md hover:bg-gray-50 transition">
                        🔄 All ({{ $expenseRequests->count() }})
                    </button>
                    <button onclick="filterExpenseRequests('pending')" class="filter-btn px-3 py-1.5 text-xs font-medium border border-yellow-300 bg-yellow-50 text-yellow-700 rounded-md hover:bg-yellow-100 transition">
                        ⏳ Pending ({{ $expenseRequests->where('status', 'pending')->count() }})
                    </button>
                    <button onclick="filterExpenseRequests('all_approved')" class="filter-btn px-3 py-1.5 text-xs font-medium border border-green-300 bg-green-50 text-green-700 rounded-md hover:bg-green-100 transition">
                        ✅ Approved ({{ $expenseRequests->where('status', 'approved')->count() }})
                    </button>
                    <button onclick="filterExpenseRequests('company')" class="filter-btn px-3 py-1.5 text-xs font-medium border border-purple-300 bg-purple-50 text-purple-700 rounded-md hover:bg-purple-100 transition">
                        🏢 From Company ({{ $expenseRequests->whereNotNull('ledger_transaction_id')->filter(function($r) { return $r->paymentSourceAccount && in_array($r->paymentSourceAccount->account_code, ['EXP_FUND', 'NF_CASH', 'ONLINE', 'CASH_NF_MAIN_TILL']); })->count() }})
                    </button>
                    <button onclick="filterExpenseRequests('employee')" class="filter-btn px-3 py-1.5 text-xs font-medium border border-indigo-300 bg-indigo-50 text-indigo-700 rounded-md hover:bg-indigo-100 transition">
                        👤 From Employee ({{ $expenseRequests->whereNotNull('ledger_transaction_id')->filter(function($r) { return $r->paymentSourceAccount && $r->paymentSourceAccount->account_category === 'employee_cash'; })->count() }})
                    </button>
                    <button onclick="filterExpenseRequests('rejected')" class="filter-btn px-3 py-1.5 text-xs font-medium border border-red-300 bg-red-50 text-red-700 rounded-md hover:bg-red-100 transition">
                        ❌ Rejected ({{ $expenseRequests->where('status', 'rejected')->count() }})
                    </button>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paid From</th>
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
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" data-payment-source="{{ $req->paymentSourceAccount ? $req->paymentSourceAccount->account_category : '' }}">
                                @if($req->ledger_transaction_id && $req->paymentSourceAccount)
                                    @if(in_array($req->paymentSourceAccount->account_code, ['EXP_FUND', 'NF_CASH', 'ONLINE', 'CASH_NF_MAIN_TILL']))
                                        <span class="px-2 py-1 text-xs font-medium bg-purple-50 text-purple-700 rounded">
                                            🏢 {{ $req->paymentSourceAccount->account_name }}
                                        </span>
                                    @elseif($req->paymentSourceAccount->account_category === 'employee_cash')
                                        <span class="px-2 py-1 text-xs font-medium bg-indigo-50 text-indigo-700 rounded">
                                            👤 {{ $req->paymentSourceAccount->account_name }}
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-600">{{ $req->paymentSourceAccount->account_name }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">-</span>
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

<!-- Online Approvals Modal (for ONLINE Bank account) -->
@if($account->account_code === 'ONLINE')
<div id="onlineApprovalsModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #fef3c7 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #fde68a; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ⏳
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 700; color: #78350f; margin: 0;">Online Approvals Pending</h3>
                    <p style="font-size: 14px; color: #92400e; margin: 4px 0 0 0;">
                        <span id="approvalCount">{{ count($summary['pending_approvals'] ?? []) }}</span> invoice(s) awaiting approval
                    </p>
                </div>
            </div>
            <button onclick="closeOnlineApprovalsModal()" style="width: 32px; height: 32px; border-radius: 50%; background: #fef3c7; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #78350f; transition: all 0.2s;" onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">
                ✕
            </button>
        </div>

        <!-- Scrollable Content -->
        <div style="flex: 1; overflow-y: auto; padding: 24px;">
            @if(count($summary['pending_approvals'] ?? []) > 0)
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    @foreach($summary['pending_approvals'] as $approval)
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 16px;">
                        <div style="display: flex; justify-between; align-items: start; margin-bottom: 12px;">
                            <div style="flex: 1;">
                                <div style="font-size: 14px; font-weight: 600; color: #78350f; margin-bottom: 4px;">
                                    Invoice #{{ $approval->id }}
                                </div>
                                <div style="font-size: 12px; color: #92400e;">
                                    Date: {{ $approval->transaction_date->format('Y-m-d') }}
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 20px; font-weight: 700; color: #78350f;">
                                    Rs. {{ number_format($approval->amount, 2) }}
                                </div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 12px; padding: 12px; background: white; border-radius: 6px;">
                            <div>
                                <div style="font-size: 11px; color: #92400e; text-transform: uppercase; margin-bottom: 4px;">From</div>
                                <div style="font-size: 13px; color: #78350f; font-weight: 500;">{{ $approval->fromAccount->account_name ?? 'N/A' }}</div>
                            </div>
                            <div>
                                <div style="font-size: 11px; color: #92400e; text-transform: uppercase; margin-bottom: 4px;">To</div>
                                <div style="font-size: 13px; color: #78350f; font-weight: 500;">{{ $approval->toAccount->account_name ?? 'N/A' }}</div>
                            </div>
                        </div>

                        @if($approval->description)
                        <div style="padding: 12px; background: white; border-radius: 6px; margin-bottom: 12px;">
                            <div style="font-size: 11px; color: #92400e; text-transform: uppercase; margin-bottom: 4px;">Description</div>
                            <div style="font-size: 13px; color: #78350f;">{{ $approval->description }}</div>
                        </div>
                        @endif

                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <button onclick="approveOnlineInvoice({{ $approval->id }})" 
                                    style="padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s;"
                                    onmouseover="this.style.background='#059669'" 
                                    onmouseout="this.style.background='#10b981'">
                                ✅ Approve
                            </button>
                            <button onclick="rejectOnlineInvoice({{ $approval->id }})" 
                                    style="padding: 8px 16px; background: #ef4444; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s;"
                                    onmouseover="this.style.background='#dc2626'" 
                                    onmouseout="this.style.background='#ef4444'">
                                ❌ Reject
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 40px; color: #92400e;">
                    <div style="font-size: 48px; margin-bottom: 16px;">✅</div>
                    <div style="font-size: 16px; font-weight: 500;">No pending approvals</div>
                    <div style="font-size: 14px; margin-top: 8px;">All online invoices have been processed</div>
                </div>
            @endif
        </div>

        <!-- Fixed Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #fafafa; flex-shrink: 0; display: flex; justify-content: flex-end;">
            <button onclick="closeOnlineApprovalsModal()" 
                    style="padding: 10px 20px; background: #6b7280; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s;"
                    onmouseover="this.style.background='#4b5563'" 
                    onmouseout="this.style.background='#6b7280'">
                Close
            </button>
        </div>
    </div>
</div>
@endif

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

<!-- Settlement & Deposit Modal -->
<div id="settlementModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">📋 Settle Invoices & Deposit</h2>
                <button onclick="closeSettlementModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <!-- Loading State -->
            <div id="settlement-loading" class="text-center py-8">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                <p class="text-sm text-gray-600 mt-2">Loading outstanding invoices...</p>
            </div>

            <!-- No Invoices State -->
            <div id="settlement-no-invoices" class="hidden text-center py-8">
                <div class="text-4xl mb-2">✅</div>
                <p class="text-lg font-medium text-gray-800">All invoices settled!</p>
                <p class="text-sm text-gray-600 mt-2">There are no outstanding invoices to settle.</p>
                <button onclick="closeSettlementModal()" class="mt-4 px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-md">
                    Close
                </button>
            </div>

            <!-- Settlement Form -->
            <form id="settlement-form" class="hidden" action="{{ route('fin.employee.settlement-deposit', $account->id) }}" method="POST" onsubmit="return handleSettlementSubmit(event)">
                @csrf
                <div class="space-y-4">
                    <!-- Outstanding Invoices Table -->
                    <div class="border border-gray-200 rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                            <h3 class="text-sm font-semibold text-gray-700">📦 Outstanding Invoices</h3>
                            <p class="text-xs text-gray-600 mt-1">Select invoices to settle with this deposit</p>
                        </div>
                        <div class="overflow-x-auto max-h-64">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left">
                                            <input type="checkbox" id="select-all-invoices" onchange="toggleAllInvoices(this)" 
                                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        </th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    </tr>
                                </thead>
                                <tbody id="invoices-tbody" class="bg-white divide-y divide-gray-200">
                                    <!-- Will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Summary Card -->
                    <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-medium text-indigo-900">Selected Invoices:</span>
                            <span id="selected-count" class="text-lg font-bold text-indigo-900">0</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-indigo-900">Total Outstanding:</span>
                            <span id="total-outstanding" class="text-lg font-bold text-indigo-900">Rs. 0.00</span>
                        </div>
                    </div>

                    <!-- Deposit Details -->
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date <span class="text-red-500">*</span></label>
                            <input type="date" name="transaction_date" value="{{ date('Y-m-d') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Amount Depositing (Rs.) <span class="text-red-500">*</span></label>
                            <input type="text" id="settlement-amount" name="amount" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                   placeholder="0.00"
                                   oninput="validateAndFormatAmount(this)"
                                   onblur="formatAmountOnBlur(this)">
                            <p id="amount-error" class="text-xs text-red-600 mt-1 hidden"></p>
                            <p class="text-xs text-gray-600 mt-1">💡 Tip: Enter any amount up to the total outstanding (partial settlement allowed)</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                            <textarea name="description" rows="2" placeholder="Any additional notes..."
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                        </div>
                    </div>

                    <!-- Destination (hidden, always NF Cash) -->
                    <input type="hidden" name="destination_account_id" value="">
                    
                    <!-- Info Alert -->
                    <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                        <p class="text-sm text-yellow-900 font-medium">💰 Depositing to: NF Cash (Main Till)</p>
                        <p class="text-xs text-yellow-700 mt-1">⏳ Settlement will be approved by manager before invoices are marked as paid</p>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeSettlementModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" id="submit-settlement-btn" disabled 
                                style="background-color: #4f46e5 !important; color: white !important;" 
                                class="flex-1 px-4 py-2 hover:opacity-90 font-medium rounded-md disabled:bg-gray-300 disabled:cursor-not-allowed disabled:opacity-50">
                            💾 Submit for Approval
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
                
                @if($userRole !== 'rider')
                <!-- MANAGERS/ADMINS: Full Payment Source Selection -->
                <div class="p-3 bg-purple-50 border border-purple-200 rounded-md">
                    <label class="block text-sm font-medium text-purple-900 mb-2">💳 Payment Source (Default: Expense Fund):</label>
                    <select name="payment_source_account_id" 
                            class="w-full px-3 py-2 border border-purple-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="">Expense Fund</option>
                        @php
                            // Company accounts + Current rider's account only (not other riders)
                            $paymentSources = \App\Models\FIN\AccountModel::where('is_active', 1)
                                ->where(function($q) use ($account) {
                                    $q->whereIn('account_code', ['EXP_FUND', 'NF_CASH', 'ONLINE'])
                                      ->orWhere('id', $account->id); // Only current rider's account
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
                    <p class="text-xs text-purple-700 mt-1">⚠️ Select where this expense should be paid from. Can be changed during approval.</p>
                </div>
                @else
                <!-- RIDERS: Choice between Expense Fund (Default) and Company Cash -->
                <div class="p-3 bg-purple-50 border border-purple-200 rounded-md">
                    <label class="block text-sm font-medium text-purple-900 mb-2">💳 Payment Source:</label>
                    <select name="payment_source_account_id" 
                            class="w-full px-3 py-2 border border-purple-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500">
                        <option value="">Expense Fund (Default - No Balance Impact)</option>
                        <option value="{{ $account->id }}">Company Cash I Hold (Deducts from My Balance: Rs. {{ number_format($account->current_balance, 2) }})</option>
                    </select>
                    <p class="text-xs text-purple-700 mt-1">💡 Choose "Company Cash" only if you're paying from cash in hand. Otherwise, leave as "Expense Fund".</p>
                </div>
                @endif
                
                <div class="p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <p class="text-xs text-blue-800">
                        <strong>Note:</strong> This request will go through L1→L2 approval workflow. 
                        Upon approval, the expense will be paid and posted to the ledger.
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

@if(!$isEmployeeAccount)
<!-- Company Receipt Modal -->
<div id="companyReceiveModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">💵 Record Receipt into {{ $account->account_name }}</h2>
                <button onclick="closeCompanyReceiveModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form id="companyReceiveForm" method="POST" action="{{ route('fin.employee.company-receive', $account->id) }}">
                @csrf
                
                <!-- Source Type Selection -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Receipt Source</label>
                    <div class="flex gap-4">
                        <label class="flex items-center">
                            <input type="radio" name="receipt_source_type" value="external" checked onclick="toggleReceiptSource()" class="mr-2">
                            <span class="text-sm">External (Customer/Vendor)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="receipt_source_type" value="internal" onclick="toggleReceiptSource()" class="mr-2">
                            <span class="text-sm">Internal Account</span>
                        </label>
                    </div>
                </div>

                <!-- External Source Section -->
                <div id="receipt_external_section" class="mb-4">
                    <label for="receipt_from_external" class="block text-sm font-medium text-gray-700">From (Name/Description)</label>
                    <input type="text" id="receipt_from_external" name="from_external" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="e.g., Customer Payment, Sale, etc.">
                </div>

                <!-- Internal Account Section -->
                <div id="receipt_account_section" class="mb-4" style="display: none;">
                    <label for="receipt_from_account" class="block text-sm font-medium text-gray-700">From Account</label>
                    <select id="receipt_from_account" name="from_account_id" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        <option value="">Select account...</option>
                        @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank', 'employee_cash'])->get() as $acc)
                            @if($acc->id != $account->id)
                                <option value="{{ $acc->id }}">{{ $acc->account_name }} (Rs. {{ number_format($acc->current_balance, 2) }})</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <!-- Amount -->
                <div class="mb-4">
                    <label for="receipt_amount" class="block text-sm font-medium text-gray-700">Amount *</label>
                    <input type="number" id="receipt_amount" name="amount" step="0.01" min="0.01" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>

                <!-- Description -->
                <div class="mb-4">
                    <label for="receipt_description" class="block text-sm font-medium text-gray-700">Description *</label>
                    <textarea id="receipt_description" name="description" rows="2" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="Purpose of receipt..."></textarea>
                </div>

                <!-- Date -->
                <div class="mb-4">
                    <label for="receipt_date" class="block text-sm font-medium text-gray-700">Date *</label>
                    <input type="date" id="receipt_date" name="transaction_date" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>

                <!-- Requires Approval -->
                <div class="mb-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="requires_approval" value="1" class="mr-2">
                        <span class="text-sm text-gray-700">Requires manager approval before posting</span>
                    </label>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeCompanyReceiveModal()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md">
                        💵 Record Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Company Payment Modal -->
<div id="companyPaymentModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">💳 Record Payment from {{ $account->account_name }}</h2>
                <button onclick="closeCompanyPaymentModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form id="companyPaymentForm" method="POST" action="{{ route('fin.employee.company-payment', $account->id) }}">
                @csrf
                
                <!-- Destination Type Selection -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Payment Destination</label>
                    <div class="flex gap-4">
                        <label class="flex items-center">
                            <input type="radio" name="payment_dest_type" value="external" checked onclick="togglePaymentDestination()" class="mr-2">
                            <span class="text-sm">External (Vendor/Expense)</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="payment_dest_type" value="internal" onclick="togglePaymentDestination()" class="mr-2">
                            <span class="text-sm">Internal Account</span>
                        </label>
                    </div>
                </div>

                <!-- External Destination Section -->
                <div id="payment_external_section" class="mb-4">
                    <label for="payment_to_external" class="block text-sm font-medium text-gray-700">To (Name/Description)</label>
                    <input type="text" id="payment_to_external" name="to_external" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="e.g., Vendor Name, Bill Payment, etc.">
                </div>

                <!-- Internal Account Section -->
                <div id="payment_account_section" class="mb-4" style="display: none;">
                    <label for="payment_to_account" class="block text-sm font-medium text-gray-700">To Account</label>
                    <select id="payment_to_account" name="to_account_id" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        <option value="">Select account...</option>
                        @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank', 'employee_cash'])->get() as $acc)
                            @if($acc->id != $account->id)
                                <option value="{{ $acc->id }}">{{ $acc->account_name }} (Rs. {{ number_format($acc->current_balance, 2) }})</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <!-- Expense Category (Optional) -->
                <div class="mb-4">
                    <label for="payment_expense_category" class="block text-sm font-medium text-gray-700">Expense Category (Optional)</label>
                    <select id="payment_expense_category" name="expense_category" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        <option value="">None</option>
                        <option value="Utilities">Utilities</option>
                        <option value="Rent">Rent</option>
                        <option value="Salary">Salary</option>
                        <option value="Supplies">Supplies</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <!-- Amount -->
                <div class="mb-4">
                    <label for="payment_amount" class="block text-sm font-medium text-gray-700">Amount *</label>
                    <input type="number" id="payment_amount" name="amount" step="0.01" min="0.01" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>

                <!-- Description -->
                <div class="mb-4">
                    <label for="payment_description" class="block text-sm font-medium text-gray-700">Description *</label>
                    <textarea id="payment_description" name="description" rows="2" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="Purpose of payment..."></textarea>
                </div>

                <!-- Date -->
                <div class="mb-4">
                    <label for="payment_date" class="block text-sm font-medium text-gray-700">Date *</label>
                    <input type="date" id="payment_date" name="transaction_date" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>

                <!-- Requires Approval -->
                <div class="mb-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="requires_approval" value="1" class="mr-2">
                        <span class="text-sm text-gray-700">Requires manager approval before posting</span>
                    </label>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeCompanyPaymentModal()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md">
                        💳 Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Company Transfer Modal -->
<div id="companyTransferModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">🔄 Transfer from {{ $account->account_name }}</h2>
                <button onclick="closeCompanyTransferModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form id="companyTransferForm" method="POST" action="{{ route('fin.employee.company-transfer', $account->id) }}">
                @csrf
                
                <!-- To Account -->
                <div class="mb-4">
                    <label for="transfer_to_account" class="block text-sm font-medium text-gray-700">Transfer To *</label>
                    <select id="transfer_to_account" name="to_account_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        <option value="">Select destination account...</option>
                        @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->get() as $acc)
                            @if($acc->id != $account->id)
                                <option value="{{ $acc->id }}">{{ $acc->account_name }} (Rs. {{ number_format($acc->current_balance, 2) }})</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <!-- Amount -->
                <div class="mb-4">
                    <label for="transfer_amount" class="block text-sm font-medium text-gray-700">Amount *</label>
                    <input type="number" id="transfer_amount" name="amount" step="0.01" min="0.01" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                    <p class="mt-1 text-xs text-gray-500">Available balance: Rs. {{ number_format($account->current_balance, 2) }}</p>
                </div>

                <!-- Description -->
                <div class="mb-4">
                    <label for="transfer_description" class="block text-sm font-medium text-gray-700">Description (Optional)</label>
                    <textarea id="transfer_description" name="description" rows="2" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md" placeholder="Notes about this transfer..."></textarea>
                </div>

                <!-- Date -->
                <div class="mb-6">
                    <label for="transfer_date" class="block text-sm font-medium text-gray-700">Date *</label>
                    <input type="date" id="transfer_date" name="transaction_date" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>

                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <p class="text-xs text-blue-800">
                        <strong>Note:</strong> Internal transfers between company accounts are processed immediately without requiring approval.
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeCompanyTransferModal()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md">
                        🔄 Complete Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<script>
// ===================================================================
// PHASE 1: Card Filtering & Breakdown Toggle
// ===================================================================

// Global variable to track current filter
let currentTransactionFilter = 'all';

// Transaction type mappings for filtering
const transactionTypeMap = {
    'all': null, // Show all
    'cash_in': ['employee_deposit', 'expense_settlement', 'transfer', 'adjustment', 'reimbursement_payment', 'salary_advance'],
    'cash_out': ['expense', 'vendor_payment', 'transfer', 'adjustment', 'vendor_purchase'],
    'deposits': ['employee_deposit'],
    'settlements': ['expense_settlement'],
    'transfers_in': ['transfer'], // Will check to_account_id
    'transfers_out': ['transfer'], // Will check from_account_id
    'unsettled_expenses': null, // Special: will need backend support or hide for now
    'vendor_payments': ['vendor_payment'],
    'expenses_ledger': ['expense'],
    'others_in': ['adjustment', 'reimbursement_payment', 'salary_advance'],
    'others_out': ['adjustment', 'vendor_purchase'],
    'pending': null, // Will filter by approval_status
    'cash_invoices': ['invoice'] // Will filter invoices with cash/COD description
};

/**
 * Toggle card breakdown expansion
 */
function toggleCardBreakdown(cardId) {
    const card = document.getElementById(cardId);
    const icon = document.getElementById(cardId.replace('Card', 'Icon'));
    
    if (!card) return;
    
    if (card.classList.contains('hidden')) {
        card.classList.remove('hidden');
        if (icon) icon.style.transform = 'rotate(180deg)';
    } else {
        card.classList.add('hidden');
        if (icon) icon.style.transform = 'rotate(0deg)';
    }
}

/**
 * Filter transactions in the table
 */
function filterTransactions(filterType) {
    currentTransactionFilter = filterType;
    console.log('Filtering transactions by:', filterType);
    
    // Get all transaction rows
    const rows = document.querySelectorAll('tbody tr[data-transaction-type]');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const transactionType = row.getAttribute('data-transaction-type');
        const approvalStatus = row.getAttribute('data-approval-status');
        const direction = row.getAttribute('data-direction'); // 'in' or 'out'
        const description = row.getAttribute('data-description') || ''; // For cash invoice filtering
        
        let shouldShow = false;
        
        if (filterType === 'all') {
            shouldShow = true;
        } else if (filterType === 'pending') {
            shouldShow = approvalStatus === 'pending';
        } else if (filterType === 'transfers_in') {
            shouldShow = transactionType === 'transfer' && direction === 'in';
        } else if (filterType === 'transfers_out') {
            shouldShow = transactionType === 'transfer' && direction === 'out';
        } else if (filterType === 'cash_invoices') {
            // Special filter for cash invoices: must be invoice type AND contain cash/COD in description
            const descLower = description.toLowerCase();
            shouldShow = transactionType === 'invoice' && 
                        (descLower.includes('cash') || descLower.includes('cod'));
        } else {
            const allowedTypes = transactionTypeMap[filterType];
            if (allowedTypes) {
                shouldShow = allowedTypes.includes(transactionType);
                
                // Additional filtering for cash_in vs cash_out
                if (filterType === 'cash_in' && direction !== 'in') shouldShow = false;
                if (filterType === 'cash_out' && direction !== 'out') shouldShow = false;
            }
        }
        
        if (shouldShow) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update active filter indicator
    updateActiveFilterUI(filterType);
    
    // Show count or no results message
    console.log(`Showing ${visibleCount} transactions`);
    updateFilterResultsMessage(visibleCount, filterType);
}

/**
 * Update UI to show which filter is active
 */
function updateActiveFilterUI(filterType) {
    // Remove all active states
    document.querySelectorAll('[data-filter-type]').forEach(el => {
        el.classList.remove('ring-2', 'ring-blue-500', 'ring-offset-2');
    });
    
    // Add active state to current filter (if applicable)
    const activeCard = document.querySelector(`[data-filter-type="${filterType}"]`);
    if (activeCard) {
        activeCard.classList.add('ring-2', 'ring-blue-500', 'ring-offset-2');
    }
}

/**
 * Show filter results message
 */
function updateFilterResultsMessage(count, filterType) {
    // Check if message div exists, create if not
    let messageDiv = document.getElementById('filterResultsMessage');
    if (!messageDiv) {
        messageDiv = document.createElement('div');
        messageDiv.id = 'filterResultsMessage';
        messageDiv.className = 'mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md flex justify-between items-center';
        
        // Insert before the transaction table
        const tableContainer = document.querySelector('.bg-white.border.rounded-lg.overflow-hidden');
        if (tableContainer && tableContainer.parentElement) {
            tableContainer.parentElement.insertBefore(messageDiv, tableContainer);
        }
    }
    
    if (filterType === 'all') {
        messageDiv.style.display = 'none';
        return;
    }
    
    const filterName = filterType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    
    messageDiv.style.display = 'flex';
    messageDiv.innerHTML = `
        <div class="text-sm text-blue-800">
            <strong>Filter Active:</strong> ${filterName} (${count} transaction${count !== 1 ? 's' : ''})
        </div>
        <button onclick="filterTransactions('all')" class="text-sm text-blue-600 hover:text-blue-800 underline">
            Clear Filter
        </button>
    `;
}

// ===================================================================
// EXISTING FUNCTIONS
// ===================================================================

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

// ===================================================================
// SETTLEMENT MODAL FUNCTIONS
// ===================================================================

let settlementInvoices = [];
let selectedInvoiceIds = [];

async function openSettlementModal() {
    const modal = document.getElementById('settlementModal');
    if (!modal) return;
    
    // Portalize to body
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    // Show modal with loading state
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
    document.body.style.overflow = 'hidden';
    
    // Show loading, hide others
    document.getElementById('settlement-loading').classList.remove('hidden');
    document.getElementById('settlement-no-invoices').classList.add('hidden');
    document.getElementById('settlement-form').classList.add('hidden');
    
    // Fetch outstanding invoices
    try {
        const response = await fetch('{{ route("fin.employee.outstanding-invoices", $account->id) }}');
        const data = await response.json();
        
        if (data.success && data.invoices && data.invoices.length > 0) {
            settlementInvoices = data.invoices;
            selectedInvoiceIds = data.invoices.map(inv => inv.id); // Select all by default
            renderInvoicesTable();
            updateSettlementSummary();
            
            // Show form
            document.getElementById('settlement-loading').classList.add('hidden');
            document.getElementById('settlement-form').classList.remove('hidden');
            
            // Pre-fill amount with total
            document.getElementById('settlement-amount').value = data.total_outstanding.toFixed(2);
        } else {
            // No invoices
            document.getElementById('settlement-loading').classList.add('hidden');
            document.getElementById('settlement-no-invoices').classList.remove('hidden');
        }
    } catch (error) {
        console.error('Error loading invoices:', error);
        alert('Error loading outstanding invoices. Please try again.');
        closeSettlementModal();
    }
}

function closeSettlementModal() {
    const modal = document.getElementById('settlementModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    // Reset state
    settlementInvoices = [];
    selectedInvoiceIds = [];
}

function renderInvoicesTable() {
    const tbody = document.getElementById('invoices-tbody');
    tbody.innerHTML = '';
    
    settlementInvoices.forEach(invoice => {
        const isChecked = selectedInvoiceIds.includes(invoice.id);
        const row = document.createElement('tr');
        row.className = isChecked ? 'bg-indigo-50' : '';
        row.innerHTML = `
            <td class="px-4 py-3">
                <input type="checkbox" name="invoice_ids[]" value="${invoice.id}" 
                       ${isChecked ? 'checked' : ''}
                       onchange="toggleInvoice(${invoice.id}, this.checked)"
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            </td>
            <td class="px-4 py-3 text-sm font-medium text-gray-900">${invoice.order_number}</td>
            <td class="px-4 py-3 text-sm text-gray-600">${invoice.transaction_date}</td>
            <td class="px-4 py-3 text-sm text-right font-semibold text-gray-900">Rs. ${parseFloat(invoice.outstanding_amount).toFixed(2)}</td>
        `;
        tbody.appendChild(row);
    });
}

function toggleInvoice(invoiceId, isChecked) {
    if (isChecked) {
        if (!selectedInvoiceIds.includes(invoiceId)) {
            selectedInvoiceIds.push(invoiceId);
        }
    } else {
        selectedInvoiceIds = selectedInvoiceIds.filter(id => id !== invoiceId);
    }
    updateSettlementSummary();
    renderInvoicesTable();
}

function toggleAllInvoices(checkbox) {
    if (checkbox.checked) {
        selectedInvoiceIds = settlementInvoices.map(inv => inv.id);
    } else {
        selectedInvoiceIds = [];
    }
    updateSettlementSummary();
    renderInvoicesTable();
}

function validateAndFormatAmount(input) {
    // Remove any non-numeric characters except decimal point
    let value = input.value.replace(/[^\d.]/g, '');
    
    // Ensure only one decimal point
    const parts = value.split('.');
    if (parts.length > 2) {
        value = parts[0] + '.' + parts.slice(1).join('');
    }
    
    // Limit to 2 decimal places
    if (parts.length === 2 && parts[1].length > 2) {
        value = parts[0] + '.' + parts[1].substring(0, 2);
    }
    
    input.value = value;
    
    // Validate against total outstanding
    const amount = parseFloat(value) || 0;
    const selectedInvoices = settlementInvoices.filter(inv => selectedInvoiceIds.includes(inv.id));
    const totalOutstanding = selectedInvoices.reduce((sum, inv) => sum + parseFloat(inv.outstanding_amount), 0);
    
    const errorEl = document.getElementById('amount-error');
    const submitBtn = document.getElementById('submit-settlement-btn');
    
    if (amount <= 0) {
        errorEl.textContent = 'Amount must be greater than 0';
        errorEl.classList.remove('hidden');
        submitBtn.disabled = true;
    } else if (amount > totalOutstanding + 0.01) { // Allow 1 cent tolerance for floating point precision
        errorEl.textContent = `Amount cannot exceed total outstanding (Rs. ${totalOutstanding.toFixed(2)})`;
        errorEl.classList.remove('hidden');
        submitBtn.disabled = true;
    } else {
        errorEl.classList.add('hidden');
        submitBtn.disabled = selectedInvoices.length === 0;
    }
}

function formatAmountOnBlur(input) {
    const value = parseFloat(input.value) || 0;
    if (value > 0) {
        input.value = value.toFixed(2);
    }
}

function updateSettlementSummary() {
    const selectedInvoices = settlementInvoices.filter(inv => selectedInvoiceIds.includes(inv.id));
    const totalOutstanding = selectedInvoices.reduce((sum, inv) => sum + parseFloat(inv.outstanding_amount), 0);
    
    document.getElementById('selected-count').textContent = selectedInvoices.length;
    document.getElementById('total-outstanding').textContent = `Rs. ${totalOutstanding.toFixed(2)}`;
    
    // Auto-update amount input to match total
    const amountInput = document.getElementById('settlement-amount');
    const currentAmount = parseFloat(amountInput.value) || 0;
    
    // If no invoices selected, clear the amount
    if (selectedInvoices.length === 0) {
        amountInput.value = '';
    }
    // If current amount is 0 or invalid, set to total
    else if (currentAmount === 0 || isNaN(currentAmount)) {
        amountInput.value = totalOutstanding.toFixed(2);
    }
    // If current amount is greater than the new total, adjust it down
    else if (currentAmount > totalOutstanding) {
        amountInput.value = totalOutstanding.toFixed(2);
    }
    // Otherwise, keep the user's manually entered amount (for partial settlements)
    
    // Re-validate the amount
    validateAndFormatAmount(amountInput);
    
    // Update select-all checkbox state
    const selectAllCheckbox = document.getElementById('select-all-invoices');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = selectedInvoices.length === settlementInvoices.length && selectedInvoices.length > 0;
    }
}

function handleSettlementSubmit(event) {
    const submitBtn = document.getElementById('submit-settlement-btn');
    
    // Prevent double submission
    if (submitBtn.disabled || submitBtn.dataset.submitting === 'true') {
        event.preventDefault();
        return false;
    }
    
    // Mark as submitting
    submitBtn.dataset.submitting = 'true';
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="inline-block animate-spin mr-2">⏳</span> Submitting...';
    
    // Allow form to submit
    return true;
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

// Apply date grouping (restore to original state)
function applyDateGrouping() {
    document.querySelectorAll('.date-group').forEach(group => {
        // Show all groups
        group.style.display = '';
        
        // Restore original HTML if it was modified in month view
        const originalHtml = group.getAttribute('data-original-html');
        if (originalHtml && group.getAttribute('data-in-month-view') === 'true') {
            group.innerHTML = originalHtml;
            group.removeAttribute('data-in-month-view');
        }
    });
    applyNonZeroFilter(); // Reapply filter if active
}

// Apply month grouping
function applyMonthGrouping() {
    const groups = document.querySelectorAll('.date-group');
    const groupedByMonth = {};
    
    // First, save original HTML for each group to restore later
    groups.forEach(group => {
        const date = group.getAttribute('data-date');
        if (!group.hasAttribute('data-original-html')) {
            group.setAttribute('data-original-html', group.innerHTML);
        }
    });
    
    // Group by month and calculate totals
    groups.forEach(group => {
        const month = group.getAttribute('data-month');
        if (!groupedByMonth[month]) {
            groupedByMonth[month] = {
                groups: [],
                totalIn: 0,
                totalOut: 0,
                transactionCount: 0
            };
        }
        groupedByMonth[month].groups.push(group);
        
        // Extract data from the summary paragraph
        const summaryP = group.querySelector('p.text-xs.text-gray-500');
        if (summaryP) {
            const text = summaryP.textContent;
            // Extract In, Out, and transaction count
            const inMatch = text.match(/In: Rs\.\s*([\d,]+\.?\d*)/);
            const outMatch = text.match(/Out: Rs\.\s*([\d,]+\.?\d*)/);
            const countMatch = text.match(/(\d+)\s+transaction/);
            
            if (inMatch) groupedByMonth[month].totalIn += parseFloat(inMatch[1].replace(/,/g, ''));
            if (outMatch) groupedByMonth[month].totalOut += parseFloat(outMatch[1].replace(/,/g, ''));
            if (countMatch) groupedByMonth[month].transactionCount += parseInt(countMatch[1]);
        }
    });
    
    // Hide all date groups initially
    groups.forEach(group => {
        group.style.display = 'none';
        // Store that it's in month view
        group.setAttribute('data-in-month-view', 'true');
    });
    
    // For each month, show only the first date group and modify it to show month summary
    Object.keys(groupedByMonth).sort().reverse().forEach((month) => {
        const monthData = groupedByMonth[month];
        const firstGroup = monthData.groups[0];
        
        // Show the first group
        firstGroup.style.display = '';
        
        // Get the header div
        const header = firstGroup.querySelector(':scope > div:first-child');
        if (header) {
            const monthName = new Date(month + '-01').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            const net = monthData.totalIn - monthData.totalOut;
            const isBalanced = Math.abs(net) < 0.01;
            
            // Replace header content with month summary
            header.innerHTML = `
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <div>
                            <h3 class="text-base font-bold text-gray-900">📅 ${monthName}</h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                In: Rs. ${formatNumber(monthData.totalIn)} • 
                                Out: Rs. ${formatNumber(monthData.totalOut)} • 
                                ${monthData.transactionCount} transaction(s) across ${monthData.groups.length} day(s)
                            </p>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        ${isBalanced 
                            ? '<span class="px-3 py-1 bg-green-100 text-green-800 text-xs font-bold rounded-full">✅ Balanced</span>'
                            : net > 0
                                ? '<span class="px-3 py-1 bg-red-100 text-red-800 text-xs font-bold rounded-full">🔴 +Rs. ' + formatNumber(net) + ' held</span>'
                                : '<span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-xs font-bold rounded-full">⚠️ Rs. ' + formatNumber(Math.abs(net)) + ' short</span>'
                        }
                        <button onclick="toggleMonthDays('${month}')" class="px-3 py-1.5 text-xs bg-blue-600 text-white rounded hover:bg-blue-700 transition font-medium">
                            ▼ View ${monthData.groups.length} Days
                        </button>
                    </div>
                </div>
            `;
            
            // Remove the onclick from header (don't want it to toggle on click)
            header.removeAttribute('onclick');
            header.style.cursor = 'default';
        }
        
        // Hide transactions for this first group (we're showing it as month header only)
        const firstDate = firstGroup.getAttribute('data-date');
        const transDiv = document.getElementById('transactions-' + firstDate);
        if (transDiv) {
            transDiv.classList.add('hidden');
        }
    });
}

// Helper to format numbers with commas
function formatNumber(num) {
    return num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Toggle showing individual days within a month
function toggleMonthDays(month) {
    const groups = document.querySelectorAll('.date-group[data-month="' + month + '"]');
    const allGroups = Array.from(groups);
    
    if (allGroups.length === 0) return;
    
    // First group is being used as month header
    const firstGroup = allGroups[0];
    const button = firstGroup.querySelector('button[onclick*="toggleMonthDays"]');
    
    // Check current state - if day 2 onwards are visible, we're expanded
    let daysShown = allGroups.length > 1 && allGroups[1].style.display !== 'none';
    
    if (daysShown) {
        // COLLAPSE: Hide days 2 onwards, keep first group as month header only
        for (let i = 1; i < allGroups.length; i++) {
            allGroups[i].style.display = 'none';
        }
        // Also ensure first group's transactions stay hidden (it's the header)
        const firstDate = firstGroup.getAttribute('data-date');
        const transDiv = document.getElementById('transactions-' + firstDate);
        if (transDiv) {
            transDiv.classList.add('hidden');
        }
        if (button) {
            button.innerHTML = '▼ View ' + allGroups.length + ' Days';
        }
    } else {
        // EXPAND: Show ALL groups including first (restore it as a regular day)
        allGroups.forEach(group => {
            group.style.display = '';
            // Restore original HTML if it was stored
            const originalHtml = group.getAttribute('data-original-html');
            if (originalHtml) {
                group.innerHTML = originalHtml;
            }
        });
        // Make sure first group's transactions are visible
        const firstDate = firstGroup.getAttribute('data-date');
        const transDiv = document.getElementById('transactions-' + firstDate);
        if (transDiv) {
            transDiv.classList.remove('hidden');
        }
        if (button) {
            button.innerHTML = '▲ Hide Days';
        }
    }
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

// ============================================
// Expense Request Filtering Functions
// ============================================

function filterExpenseRequests(filterType) {
    const rows = document.querySelectorAll('#content-expenses tbody tr');
    
    rows.forEach(row => {
        let show = false;
        
        // Get the status and payment source
        const statusCell = row.querySelector('td:nth-child(5)'); // Status column
        const paymentSourceCell = row.querySelector('td:nth-child(6)'); // Paid From column
        const statusText = statusCell ? statusCell.textContent.trim() : '';
        
        switch(filterType) {
            case 'pending':
                // Show only pending
                show = statusText.includes('Pending');
                break;
            case 'all_approved':
                // Show all approved (paid or unpaid)
                show = statusText.includes('Paid') || statusText.includes('Approved');
                break;
            case 'company':
                // Show only paid from company accounts
                show = statusText.includes('Paid') && paymentSourceCell.innerHTML.includes('🏢');
                break;
            case 'employee':
                // Show only paid from employee accounts
                show = statusText.includes('Paid') && paymentSourceCell.innerHTML.includes('👤');
                break;
            case 'rejected':
                // Show rejected requests
                show = statusText.includes('Rejected');
                break;
            case 'all_paid':
                // Show all paid transactions
                show = statusText.includes('Paid');
                break;
            default:
                show = true;
        }
        
        // Show/hide the row
        if (show) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update visual feedback on filter buttons
    document.querySelectorAll('#content-expenses .filter-btn').forEach(btn => {
        btn.classList.remove('ring-2', 'ring-blue-500');
    });
    
    // Highlight the active filter
    if (event && event.currentTarget) {
        event.currentTarget.classList.add('ring-2', 'ring-blue-500');
    }
}

// Reset filter (show all)
function resetExpenseFilter() {
    const rows = document.querySelectorAll('#content-expenses tbody tr');
    rows.forEach(row => row.style.display = '');
    
    // Remove visual feedback from all buttons
    document.querySelectorAll('#content-expenses .filter-btn').forEach(btn => {
        btn.classList.remove('ring-2', 'ring-blue-500');
    });
}

// Company Account Modal Functions
function openCompanyReceiveModal() {
    document.getElementById('companyReceiveModal').classList.remove('hidden');
    document.getElementById('receipt_date').valueAsDate = new Date();
}

function closeCompanyReceiveModal() {
    document.getElementById('companyReceiveModal').classList.add('hidden');
    document.getElementById('companyReceiveForm').reset();
}

function openCompanyPaymentModal() {
    document.getElementById('companyPaymentModal').classList.remove('hidden');
    document.getElementById('payment_date').valueAsDate = new Date();
}

function closeCompanyPaymentModal() {
    document.getElementById('companyPaymentModal').classList.add('hidden');
    document.getElementById('companyPaymentForm').reset();
}

function openCompanyTransferModal() {
    document.getElementById('companyTransferModal').classList.remove('hidden');
    document.getElementById('transfer_date').valueAsDate = new Date();
}

function closeCompanyTransferModal() {
    document.getElementById('companyTransferModal').classList.add('hidden');
    document.getElementById('companyTransferForm').reset();
}

function toggleReceiptSource() {
    const sourceType = document.querySelector('input[name="receipt_source_type"]:checked').value;
    document.getElementById('receipt_account_section').style.display = sourceType === 'internal' ? 'block' : 'none';
    document.getElementById('receipt_external_section').style.display = sourceType === 'external' ? 'block' : 'none';
}

function togglePaymentDestination() {
    const destType = document.querySelector('input[name="payment_dest_type"]:checked').value;
    document.getElementById('payment_account_section').style.display = destType === 'internal' ? 'block' : 'none';
    document.getElementById('payment_external_section').style.display = destType === 'external' ? 'block' : 'none';
}

// Online Approvals Modal Functions
function openOnlineApprovalsModal() {
    const modal = document.getElementById('onlineApprovalsModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closeOnlineApprovalsModal() {
    const modal = document.getElementById('onlineApprovalsModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

function approveOnlineInvoice(ledgerId) {
    if (!confirm('Are you sure you want to approve this online invoice?')) {
        return;
    }
    
    // Submit approval
    fetch(`/finance/ledger/${ledgerId}/approve`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Invoice approved successfully!');
            location.reload(); // Reload to update the list
        } else {
            alert('Error: ' + (data.message || 'Failed to approve invoice'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while approving the invoice');
    });
}

function rejectOnlineInvoice(ledgerId) {
    const reason = prompt('Please enter rejection reason:');
    if (!reason || reason.trim() === '') {
        alert('Rejection reason is required');
        return;
    }
    
    // Submit rejection
    fetch(`/finance/ledger/${ledgerId}/reject`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            rejection_reason: reason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Invoice rejected successfully!');
            location.reload(); // Reload to update the list
        } else {
            alert('Error: ' + (data.message || 'Failed to reject invoice'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while rejecting the invoice');
    });
}
</script>
