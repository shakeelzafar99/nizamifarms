@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Employee Cash</h1>
        <a href="{{ route('admin.operations') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Operations
        </a>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-lg shadow-sm p-3 mb-4 border border-gray-200">
        <form method="GET" action="{{ route('fin.employee.index') }}" class="flex flex-wrap gap-2 items-end">
            <!-- Filter Type -->
            <div class="flex-shrink-0">
                <label class="block text-xs font-medium text-gray-700 mb-1">Period</label>
                <select name="filter_type" id="filterType" onchange="toggleFilterInputs()" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="month" {{ $summaryKPIs['filter_type'] === 'month' ? 'selected' : '' }}>Month</option>
                    <option value="day" {{ $summaryKPIs['filter_type'] === 'day' ? 'selected' : '' }}>Day</option>
                    <option value="custom" {{ $summaryKPIs['filter_type'] === 'custom' ? 'selected' : '' }}>Custom</option>
                </select>
            </div>

            <!-- Month Input -->
            <div class="flex-shrink-0" id="monthInput" style="display: {{ $summaryKPIs['filter_type'] === 'month' ? 'block' : 'none' }}">
                <label class="block text-xs font-medium text-gray-700 mb-1">Month</label>
                <input type="month" name="filter_month" value="{{ $summaryKPIs['filter_month'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <!-- Day Input -->
            <div class="flex-shrink-0" id="dayInput" style="display: {{ $summaryKPIs['filter_type'] === 'day' ? 'block' : 'none' }}">
                <label class="block text-xs font-medium text-gray-700 mb-1">Date</label>
                <input type="date" name="filter_date" value="{{ $summaryKPIs['filter_date'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <!-- Custom Range -->
            <div class="flex gap-2" id="customInput" style="display: {{ $summaryKPIs['filter_type'] === 'custom' ? 'flex' : 'none' }}">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">From</label>
                    <input type="date" name="filter_start_date" value="{{ $summaryKPIs['filter_start_date'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">To</label>
                    <input type="date" name="filter_end_date" value="{{ $summaryKPIs['filter_end_date'] }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 self-end">
                Apply
            </button>
        </form>
    </div>

    <!-- Summary KPI Cards (Compact & Practical) -->
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
        <!-- Card 1: Invoices Delivered -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">📄</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">Invoices</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['total_invoices'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <div class="flex justify-between">
                    <span>💵 Cash:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['invoices_cash'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>💳 Online:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['invoices_online'], 0) }}</span>
                </div>
            </div>
        </div>

        <!-- Card 2: Deposits to NF Cash -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">💰</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">Deposits</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['total_deposits'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                To NF Cash (Main Till)
            </div>
        </div>

        <!-- Card 3: All Approved Expenses -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">🧾</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">All Expenses</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['total_approved_expenses'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <div class="flex justify-between">
                    <span>⏳ Settlement:</span>
                    <span class="font-medium text-yellow-600">Rs. {{ number_format($summaryKPIs['expenses_waiting_settlement'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>✓ In Fund:</span>
                    <span class="font-medium text-green-600">Rs. {{ number_format($summaryKPIs['expenses_in_fund'], 0) }}</span>
                </div>
            </div>
        </div>

        <!-- Card 4: Online Payments -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">🌐</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">Online</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['total_online'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <div class="flex justify-between">
                    <span>✓ Approved:</span>
                    <span class="font-medium">Rs. {{ number_format($summaryKPIs['online_approved'], 0) }}</span>
                </div>
                <div class="flex justify-between mt-1">
                    <span>⏳ Pending:</span>
                    <span class="font-medium text-yellow-600">Rs. {{ number_format($summaryKPIs['online_pending'], 0) }}</span>
                </div>
            </div>
        </div>

        <!-- Card 5: Riders Balance (Real-time) -->
        <div class="bg-white rounded-lg shadow-sm p-3 border border-gray-200">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xl">👥</span>
                <span class="text-xs font-semibold text-gray-600 uppercase">With Riders</span>
            </div>
            <div class="text-xl font-bold text-gray-900">Rs. {{ number_format($summaryKPIs['riders_balance'], 0) }}</div>
            <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500">
                <span class="text-blue-600 font-medium">⚡ Real-time</span>
            </div>
        </div>
    </div>

    <script>
        function toggleFilterInputs() {
            const filterType = document.getElementById('filterType').value;
            document.getElementById('monthInput').style.display = filterType === 'month' ? 'block' : 'none';
            document.getElementById('dayInput').style.display = filterType === 'day' ? 'block' : 'none';
            document.getElementById('customInput').style.display = filterType === 'custom' ? 'flex' : 'none';
        }
    </script>

    <!-- Search and Filter -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
        <form method="GET" action="{{ route('fin.employee.index') }}" class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="search" value="{{ request('search') }}" 
                       placeholder="Search accounts..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <select name="account_type" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="all" {{ $accountTypeFilter == 'all' ? 'selected' : '' }}>All Accounts</option>
                <option value="employees" {{ $accountTypeFilter == 'employees' ? 'selected' : '' }}>👤 Employees Only</option>
                <option value="company" {{ $accountTypeFilter == 'company' ? 'selected' : '' }}>🏢 Company Only</option>
                <option value="NF_CASH" {{ $accountTypeFilter == 'NF_CASH' ? 'selected' : '' }}>💵 NF Cash</option>
                <option value="ONLINE" {{ $accountTypeFilter == 'ONLINE' ? 'selected' : '' }}>🏦 Online</option>
                <option value="EXP_FUND" {{ $accountTypeFilter == 'EXP_FUND' ? 'selected' : '' }}>💼 Expense Fund</option>
            </select>
            <select name="balance_filter" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">All Balances</option>
                <option value="positive" {{ request('balance_filter') == 'positive' ? 'selected' : '' }}>Positive Balance</option>
                <option value="zero" {{ request('balance_filter') == 'zero' ? 'selected' : '' }}>Zero Balance</option>
                <option value="negative" {{ request('balance_filter') == 'negative' ? 'selected' : '' }}>Negative Balance</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                Search
            </button>
        </form>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Accounts Table -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Code</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Current Balance</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Pending Actions</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @php
                    $lastCategory = null;
                @endphp
                @forelse($accounts as $account)
                    @php
                        $isCompanyAccount = in_array($account->account_category, ['cash', 'bank']);
                        $currentCategory = $isCompanyAccount ? 'company' : 'employee';
                        $showSeparator = $lastCategory !== null && $lastCategory !== $currentCategory;
                        $lastCategory = $currentCategory;
                    @endphp
                    
                    @if($showSeparator)
                        <tr class="bg-gradient-to-r from-gray-100 via-gray-50 to-gray-100">
                            <td colspan="7" class="px-6 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                                    <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                        👤 Employee Accounts
                                    </span>
                                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                                </div>
                            </td>
                        </tr>
                    @endif
                    
                    <tr class="hover:bg-gray-50 {{ $isCompanyAccount ? 'bg-green-50/30' : 'bg-blue-50/20' }}">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $account->account_name }}</div>
                            @if($account->user)
                                <div class="text-xs text-gray-500">{{ $account->user->username }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($account->account_category === 'employee_cash')
                                <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded">👤 Employee</span>
                            @elseif($account->account_category === 'cash')
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">🏢 Company Cash</span>
                            @elseif($account->account_category === 'bank')
                                <span class="px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded">🏦 Bank</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded">{{ ucfirst($account->account_category) }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-xs font-mono text-gray-600 bg-gray-100 px-2 py-1 rounded">{{ $account->account_code }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-semibold {{ $account->current_balance > 0 ? 'text-green-600' : ($account->current_balance < 0 ? 'text-red-600' : 'text-gray-900') }}">
                                Rs. {{ number_format($account->current_balance, 2) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-semibold {{ $account->pending_approvals > 0 ? 'text-yellow-600' : 'text-gray-400' }}">
                                Rs. {{ number_format($account->pending_approvals, 2) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {{ $account->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                {{ $account->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <a href="{{ route('fin.employee.show', $account->id) }}" class="text-blue-600 hover:text-blue-900">View Ledger</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                            No accounts found matching your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($accounts->hasPages())
        <div class="mt-4">
            {{ $accounts->appends(request()->query())->links() }}
        </div>
    @endif
</div>
@endsection

