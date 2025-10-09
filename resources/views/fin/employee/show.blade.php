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
    
    <!-- Ledger Transactions -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-medium text-gray-900">Transaction History</h2>
            <div class="text-sm text-gray-500">
                Showing {{ $ledger->firstItem() ?? 0 }}-{{ $ledger->lastItem() ?? 0 }} of {{ $ledger->total() }}
            </div>
        </div>
        
        <div class="overflow-x-auto">
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
                    @forelse($ledger as $transaction)
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
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">
                                No transactions found for this employee.
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
                    <select name="expense_category" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="">Select Expense Type</option>
                        @if(count($expenseCategories) > 0)
                            @foreach($expenseCategories as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        @else
                            {{-- Fallback options if database is empty --}}
                            <option value="Petrol">Petrol</option>
                            <option value="Rent">Rent</option>
                            <option value="Utility Bills">Utility Bills</option>
                            <option value="Packaging - Shrink wrap">Packaging - Shrink wrap</option>
                            <option value="Packaging - Bags">Packaging - Bags</option>
                            <option value="Food">Food</option>
                            <option value="Office Supplies">Office Supplies</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Transportation">Transportation</option>
                            <option value="Communication">Communication</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Insurance">Insurance</option>
                            <option value="Professional Fees">Professional Fees</option>
                            <option value="Bank Charges">Bank Charges</option>
                            <option value="Staff Salaries">Staff Salaries</option>
                            <option value="Miscellaneous">Miscellaneous</option>
                        @endif
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Select the type of expense for proper accounting</p>
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
</script>
