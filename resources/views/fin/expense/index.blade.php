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

    <!-- KPI Cards - Redesigned Layout: 4 cards (2x2) on left, 1 large card on right -->
    @php
        $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
            ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
        <!-- Left Side: 4 Small Cards in 2x2 Grid -->
        <div class="lg:col-span-2 grid grid-cols-2 gap-3">
            <!-- Total Expenses (Filtered) -->
            <div class="bg-white border border-gray-200 rounded-lg p-3">
                <div class="text-xs text-gray-500 uppercase font-medium">📊 Total Expenses</div>
                <div class="text-lg font-bold text-gray-900 mt-1">Rs. {{ number_format($kpis['total_expenses'], 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">
                    @if($dateFrom && $dateTo)
                        {{ date('M d', strtotime($dateFrom)) }} - {{ date('M d', strtotime($dateTo)) }}
                    @elseif($category)
                        {{ $category }}
                    @else
                        All time
                    @endif
                </div>
            </div>

            <!-- Pending Approvals (Real-time) - Clickable -->
            <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-3 cursor-pointer hover:bg-yellow-100 transition-colors" 
                 onclick="openPendingApprovalsModal()"
                 title="Click to view and approve pending requests">
                <div class="text-xs text-yellow-700 uppercase font-medium">⏳ Pending Approvals</div>
                <div class="text-lg font-bold text-yellow-900 mt-1">Rs. {{ number_format($kpis['pending_approvals'], 2) }}</div>
                <div class="text-xs text-yellow-600 mt-1">
                    {{ $kpis['pending_approvals_count'] }} request(s)
                    <span class="ml-1">👆 Click</span>
                </div>
            </div>

            <!-- Needs Settlement (Real-time) -->
            <div class="bg-orange-50 border border-orange-300 rounded-lg p-3">
                <div class="text-xs text-orange-700 uppercase font-medium">⚠️ Needs Settlement</div>
                <div class="text-lg font-bold text-orange-900 mt-1">Rs. {{ number_format($kpis['needs_settlement'], 2) }}</div>
                <div class="text-xs text-orange-600 mt-1">{{ $kpis['pending_count'] }} expense(s)</div>
            </div>

            <!-- Expense Fund Balance (Real-time) -->
            <div class="bg-blue-50 border border-blue-300 rounded-lg p-3">
                <div class="text-xs text-blue-700 uppercase font-medium">💰 Fund Balance</div>
                <div class="text-lg font-bold text-blue-900 mt-1">
                    Rs. {{ $expenseFund ? number_format($expenseFund->current_balance, 2) : '0.00' }}
                </div>
                <div class="text-xs text-blue-600 mt-1">Available</div>
            </div>
        </div>

        <!-- Right Side: Large Card with Top 10 Categories -->
        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 border border-purple-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-3">
                <div class="text-sm text-purple-700 uppercase font-semibold">📊 Top Expense Categories</div>
                <span class="text-xs text-purple-600">Click to filter</span>
            </div>
            <div class="space-y-1.5 max-h-[180px] overflow-y-auto">
                @foreach($kpis['top_categories'] ?? [] as $cat => $amount)
                    <div class="flex items-center justify-between p-2 bg-white rounded hover:bg-purple-100 cursor-pointer transition-colors border border-transparent hover:border-purple-300"
                         onclick="filterByCategory('{{ $cat }}')">
                        <span class="text-xs font-medium text-gray-700 truncate mr-2">{{ $cat }}</span>
                        <span class="text-xs font-bold text-purple-900 whitespace-nowrap">Rs. {{ number_format($amount, 0) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Filter Bar - Redesigned Simple & Effective -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
        <form method="GET" action="{{ route('fin.expenses.index') }}" id="filterForm">
            <div class="flex flex-wrap items-end gap-3">
                <!-- Quick Month Selector -->
                <div class="flex-shrink-0">
                    <label class="text-xs font-medium text-gray-700 block mb-1">📅 Quick Select</label>
                    <select onchange="setMonthRange(this.value)" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="">Custom Range</option>
                        <option value="current_month">This Month</option>
                        <option value="last_month">Last Month</option>
                        <option value="last_3_months">Last 3 Months</option>
                        <option value="last_6_months">Last 6 Months</option>
                        <option value="this_year">This Year</option>
                    </select>
                </div>

                <!-- Date Range -->
                <div class="flex items-center gap-2">
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">From</label>
                        <input type="date" name="date_from" id="dateFrom" value="{{ $dateFrom }}" 
                               class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="self-end pb-2 text-gray-400">→</div>
                    <div>
                        <label class="text-xs font-medium text-gray-700 block mb-1">To</label>
                        <input type="date" name="date_to" id="dateTo" value="{{ $dateTo }}" 
                               class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <!-- Category -->
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Category</label>
                    <select name="category" id="category" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="" {{ empty($category) ? 'selected' : '' }}>All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" {{ !empty($category) && $category == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Payment Source -->
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Payment Source</label>
                    <select name="payment_source" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="">All Sources</option>
                        @foreach($paymentSources as $source)
                            <option value="{{ $source->id }}" {{ $paymentSource == $source->id ? 'selected' : '' }}>
                                {{ $source->account_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Settlement Status -->
                <div>
                    <label class="text-xs font-medium text-gray-700 block mb-1">Settlement</label>
                    <select name="settlement_status" class="rounded border-gray-300 text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                        <option value="">All Status</option>
                        <option value="not_required" {{ $settlementStatus == 'not_required' ? 'selected' : '' }}>No Action</option>
                        <option value="pending" {{ $settlementStatus == 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="settled" {{ $settlementStatus == 'settled' ? 'selected' : '' }}>Settled</option>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-2 ml-auto">
                    <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg shadow-sm transition-colors">
                        🔍 Apply Filters
                    </button>
                    <button type="button" onclick="clearFilters()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-lg transition-colors">
                        ✕ Clear
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <script>
    // Quick month selector helper
    function setMonthRange(period) {
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const today = new Date();
        
        switch(period) {
            case 'current_month':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                dateTo.value = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
                break;
            case 'last_month':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth() - 1, 1).toISOString().split('T')[0];
                dateTo.value = new Date(today.getFullYear(), today.getMonth(), 0).toISOString().split('T')[0];
                break;
            case 'last_3_months':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth() - 3, 1).toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
                break;
            case 'last_6_months':
                dateFrom.value = new Date(today.getFullYear(), today.getMonth() - 6, 1).toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
                break;
            case 'this_year':
                dateFrom.value = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                dateTo.value = today.toISOString().split('T')[0];
                break;
        }
        
        // Auto-submit if a quick period is selected
        if (period) {
            document.getElementById('filterForm').submit();
        }
    }
    
    // Filter by category (from top 10 categories card)
    function filterByCategory(category) {
        const form = document.getElementById('filterForm');
        const categoryInput = document.getElementById('category');
        
        if (categoryInput) {
            categoryInput.value = category;
            form.submit();
        } else {
            // If category input doesn't exist in form, add it
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'category';
            input.value = category;
            form.appendChild(input);
            form.submit();
        }
    }
    
    // Clear all filters
    function clearFilters() {
        window.location.href = '{{ route('fin.expenses.index') }}';
    }
    
    // On page load, ensure dropdown reflects the actual filter value
    document.addEventListener('DOMContentLoaded', function() {
        const categoryDropdown = document.getElementById('category');
        const urlParams = new URLSearchParams(window.location.search);
        const categoryParam = urlParams.get('category');
        
        if (categoryDropdown) {
            // If no category in URL or it's empty, set to "All Categories"
            if (!categoryParam || categoryParam === '') {
                categoryDropdown.value = '';
            } else {
                categoryDropdown.value = categoryParam;
            }
        }
    });
    </script>

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
                            @forelse($allExpensesForDisplay as $expense)
                            <tr class="hover:bg-gray-50 {{ isset($expense->type) && $expense->type === 'salary' ? 'bg-purple-50' : '' }}">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ isset($expense->type) && $expense->type === 'salary' ? 'text-purple-600' : 'text-blue-600' }}">
                                    @if(isset($expense->type) && $expense->type === 'salary')
                                        {{ $expense->request_number }}
                                    @else
                                        <a href="javascript:void(0)" onclick="openRequestDetailModal({{ $expense->id }})" class="hover:underline cursor-pointer">
                                            {{ $expense->request_number }}
                                        </a>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->created_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $expense->requester->fullname ?? 'Unknown' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->expense_category ?? ($expense->category ? $expense->category->category_name : 'N/A') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    Rs. {{ number_format($expense->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->paymentSourceAccount ? $expense->paymentSourceAccount->account_name : 'N/A' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if(isset($expense->type) && $expense->type === 'salary')
                                        <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">✅ No Action</span>
                                    @elseif($expense->settlement_status === 'not_required' || $expense->settlement_status === 'not_applicable')
                                        <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded">✅ No Action</span>
                                    @elseif($expense->settlement_status === 'pending')
                                        <span class="px-2 py-1 text-xs font-medium bg-orange-100 text-orange-800 rounded">⚠️ Pending</span>
                                    @elseif($expense->settlement_status === 'settled')
                                        <span class="px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded">✓ Settled</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    @if(isset($expense->type) && $expense->type === 'salary')
                                        <span class="text-gray-400 text-xs">{{ ucfirst($expense->status) }}</span>
                                    @elseif($expense->settlement_status === 'pending')
                                        <button onclick="openSettlementModal({{ is_string($expense->id) ? 0 : $expense->id }})" 
                                                class="text-blue-600 hover:text-blue-800 font-medium">
                                            ⚙️ Settle
                                        </button>
                                    @elseif($expense->settlement_status === 'settled')
                                        <span class="text-gray-400 text-xs">
                                            {{ isset($expense->settled_at) ? $expense->settled_at->format('M d') : 'N/A' }} by {{ isset($expense->settledBy) ? ($expense->settledBy->name ?? 'System') : 'System' }}
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
                                    <a href="javascript:void(0)" onclick="openRequestDetailModal({{ $expense->id }})" class="hover:underline cursor-pointer">
                                        {{ $expense->request_number }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    {{ $expense->created_at->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $expense->requester->fullname ?? 'Unknown' }}
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
                                    {{ $expense->requester->fullname ?? 'Unknown' }}
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
<div id="settlementModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[85vh] overflow-y-auto" onclick="event.stopPropagation()">
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

// Open pending approvals modal
function openPendingApprovalsModal() {
    const modal = document.getElementById('pendingApprovalsModal');
    if (modal) {
        modal.classList.remove('hidden');
        // Force proper modal display and centering
        Object.assign(modal.style, {
            display: 'flex',
            position: 'fixed',
            top: '0',
            left: '0',
            right: '0',
            bottom: '0',
            zIndex: '9999',
            alignItems: 'center',
            justifyContent: 'center',
            backgroundColor: 'rgba(0,0,0,0.5)'
        });
        document.body.style.overflow = 'hidden';
    }
}

// Close pending approvals modal
function closePendingApprovalsModal() {
    const modal = document.getElementById('pendingApprovalsModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Open request detail modal (loads via AJAX to stay on same page)
async function openRequestDetailModal(requestId) {
    const modal = document.getElementById('requestDetailModal');
    const content = document.getElementById('requestDetailContent');
    
    if (!modal || !content) return;
    
    // Force proper modal display
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '10000',
        alignItems: 'center',
        justifyContent: 'center',
        backgroundColor: 'rgba(0,0,0,0.5)'
    });
    document.body.style.overflow = 'hidden';
    
    try {
        // Fetch request details
        const response = await fetch(`/requests/${requestId}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) throw new Error('Failed to load request');
        
        const html = await response.text();
        
        // Extract just the content section (not the full page)
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const mainContent = doc.querySelector('.container, .container-fluid, main, [role="main"]') || doc.body;
        
        content.innerHTML = mainContent.innerHTML;
        
        // Wrap content in padding container if not already wrapped
        const wrapper = content.querySelector('.p-6') || content.querySelector('.p-4');
        if (!wrapper) {
            const paddingDiv = document.createElement('div');
            paddingDiv.className = 'p-6';
            paddingDiv.innerHTML = content.innerHTML;
            content.innerHTML = '';
            content.appendChild(paddingDiv);
        }
        
        // Add close button at the top
        const closeBtn = document.createElement('button');
        closeBtn.className = 'absolute top-4 right-4 text-gray-400 hover:text-gray-600 text-3xl leading-none z-50';
        closeBtn.innerHTML = '&times;';
        closeBtn.onclick = closeRequestDetailModal;
        closeBtn.style.cssText = 'background: none; border: none; cursor: pointer;';
        content.querySelector('.p-6, .p-4, div')?.prepend(closeBtn);
        
        // Re-inject approval/rejection JavaScript functions into the modal context
        // These functions are defined in the original request page but need to be available here
        const scriptContent = doc.querySelectorAll('script');
        scriptContent.forEach(script => {
            if (script.textContent.includes('approveRequest') || script.textContent.includes('rejectRequest')) {
                // Extract the request ID from the loaded content
                const requestIdMatch = html.match(/\/requests\/(\d+)/);
                if (requestIdMatch) {
                    const loadedRequestId = requestIdMatch[1];
                    
                    // Create wrapper functions that work in modal context
                    window.approveRequest = function() {
                        const form = document.getElementById('approval-form');
                        if (!form) return;
                        
                        const formData = new FormData(form);
                        const data = Object.fromEntries(formData.entries());
                        
                        if (!confirm('Are you sure you want to approve this request?')) {
                            return;
                        }
                        
                        fetch(`/requests/${loadedRequestId}/approve`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value
                            },
                            body: JSON.stringify(data)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Request approved successfully!');
                                closeRequestDetailModal(false);
                                closePendingApprovalsModal();
                                location.reload();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred. Please try again.');
                        });
                    };
                    
                    window.rejectRequest = function() {
                        const comments = document.querySelector('[name="comments"]').value.trim();
                        
                        if (!comments) {
                            alert('Please enter comments explaining the rejection.');
                            document.querySelector('[name="comments"]').focus();
                            return;
                        }
                        
                        if (!confirm('Are you sure you want to reject this request?')) {
                            return;
                        }
                        
                        const form = document.getElementById('approval-form');
                        if (!form) return;
                        
                        const formData = new FormData(form);
                        const data = Object.fromEntries(formData.entries());
                        
                        fetch(`/requests/${loadedRequestId}/reject`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value
                            },
                            body: JSON.stringify(data)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Request rejected.');
                                closeRequestDetailModal(false);
                                closePendingApprovalsModal();
                                location.reload();
                            } else {
                                alert('Error: ' + data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred. Please try again.');
                        });
                    };
                }
            }
        });
        
    } catch (error) {
        console.error('Error loading request:', error);
        content.innerHTML = `
            <div class="p-6 text-center">
                <div class="text-red-600 text-xl mb-2">&#10060;</div>
                <p class="text-gray-700 font-medium">Failed to load request details</p>
                <p class="text-gray-500 text-sm mt-2">${error.message}</p>
                <button onclick="closeRequestDetailModal()" class="mt-4 px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded">
                    Close
                </button>
            </div>
        `;
    }
}

// Close request detail modal and refresh if approval/rejection happened
function closeRequestDetailModal(shouldReload = false) {
    const modal = document.getElementById('requestDetailModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    // If request was approved/rejected, reload page to update counts
    if (shouldReload) {
        location.reload();
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
window.openPendingApprovalsModal = openPendingApprovalsModal;
window.closePendingApprovalsModal = closePendingApprovalsModal;
window.openRequestDetailModal = openRequestDetailModal;
window.closeRequestDetailModal = closeRequestDetailModal;

console.log('Expense Management JS loaded. Functions:', {
    openSettlementModal: typeof window.openSettlementModal,
    switchTab: typeof window.switchTab,
    openPendingApprovalsModal: typeof window.openPendingApprovalsModal
});
</script>




<!-- Pending Approvals Modal (Portalized - matches working modals) -->
<div id="pendingApprovalsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()">
        <div class="p-6">
            <!-- Header -->
            <div class="flex justify-between items-center mb-4 border-b pb-3">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Pending Expense Approvals</h2>
                    <p class="text-sm text-gray-600 mt-1">Review and approve pending expense requests</p>
                </div>
                <button onclick="closePendingApprovalsModal()" class="text-gray-400 hover:text-gray-600 text-3xl leading-none">&times;</button>
            </div>
            
            @if($pendingApprovals && $pendingApprovals->count() > 0)
            <!-- Pending Requests List -->
            <div class="space-y-3">
                @foreach($pendingApprovals as $request)
                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                    <div class="flex items-start justify-between">
                        <!-- Request Details -->
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <span class="text-sm font-semibold text-blue-600">{{ $request->request_number }}</span>
                                <span class="px-2 py-0.5 bg-yellow-100 text-yellow-800 text-xs font-medium rounded">
                                    {{ $request->status }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    {{ $request->created_at ? $request->created_at->format('M d, Y h:i A') : '-' }}
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                <div>
                                    <span class="text-gray-500">Employee:</span>
                                    <span class="font-medium text-gray-900 ml-1">
                                        {{ $request->requester->fullname ?? 'Unknown' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Category:</span>
                                    <span class="font-medium text-gray-900 ml-1">
                                        {{ $request->expense_category ?? 'N/A' }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Amount:</span>
                                    <span class="font-bold text-green-700 ml-1">
                                        Rs. {{ number_format($request->amount, 2) }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-500">Payment From:</span>
                                    <span class="font-medium text-gray-900 ml-1">
                                        {{ $request->paymentSourceAccount->account_name ?? 'Expense Fund' }}
                                    </span>
                                </div>
                            </div>
                            
                            @if($request->description)
                            <div class="mt-2 text-sm text-gray-600">
                                <span class="text-gray-500">Description:</span> {{ Str::limit($request->description, 100) }}
                            </div>
                            @endif
                        </div>
                        
                        <!-- Action Button -->
                        <div class="ml-4">
                            <button onclick="openRequestDetailModal({{ $request->id }})" 
                                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors shadow-sm"
                                    style="background-color: #059669 !important;">
                                <span style="color: white !important;">View & Approve</span>
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <!-- No Pending Requests -->
            <div class="text-center py-12">
                <div class="text-6xl mb-3">&#10004;</div>
                <h3 class="text-lg font-semibold text-gray-700">All Caught Up!</h3>
                <p class="text-gray-500 mt-1">No pending expense requests at the moment.</p>
            </div>
            @endif
            
            <!-- Footer -->
            <div class="mt-6 pt-4 border-t flex justify-end">
                <button onclick="closePendingApprovalsModal()" 
                        class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 font-medium rounded-lg transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Request Detail Modal (for approving without leaving page) -->
<div id="requestDetailModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 10000;">
    <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto relative" onclick="event.stopPropagation()" style="margin: auto;">
        <div id="requestDetailContent" class="relative">
            <!-- Content will be loaded here via AJAX -->
            <div class="text-center py-12">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                <p class="mt-4 text-gray-600">Loading request details...</p>
            </div>
        </div>
    </div>
</div>

