@extends('layouts.app')

@section('title', '🌿 Khaas Operations')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-amber-600 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">🌿 {{ $khaasBU->name }} Operations</h1>
                <p class="text-sm text-gray-600 mt-0.5">Vendors, Expenses & Warehouse Transfers</p>
            </div>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
            🌿 {{ $khaasBU->name }}
        </span>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center gap-2">
            <span class="text-green-600">✅</span>
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg flex items-center gap-2">
            <span class="text-red-600">❌</span>
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="flex gap-1 -mb-px" aria-label="Tabs">
            <a href="{{ route('khaas.operations', ['tab' => 'vendors']) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'vendors' ? 'border-amber-500 text-amber-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                <i class="ki-filled ki-shop text-base"></i>
                Vendors
                <span class="ml-1 px-2 py-0.5 rounded-full text-xs {{ $activeTab === 'vendors' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500' }}">{{ $vendors->total() }}</span>
            </a>
            <a href="{{ route('khaas.operations', ['tab' => 'expenses']) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'expenses' ? 'border-amber-500 text-amber-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                <i class="ki-filled ki-chart-line-up-2 text-base"></i>
                Expenses
                <span class="ml-1 px-2 py-0.5 rounded-full text-xs {{ $activeTab === 'expenses' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500' }}">{{ $expenses->total() }}</span>
            </a>
            <a href="{{ route('khaas.operations', ['tab' => 'transfers']) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'transfers' ? 'border-amber-500 text-amber-700' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                <i class="ki-filled ki-delivery text-base"></i>
                Transfers
                @if($pendingTransferCount > 0)
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-amber-500 text-white font-bold">{{ $pendingTransferCount }}</span>
                @else
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs {{ $activeTab === 'transfers' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500' }}">{{ $transfers->total() }}</span>
                @endif
            </a>
        </nav>
    </div>

    {{-- ====================== VENDORS TAB ====================== --}}
    @if($activeTab === 'vendors')
    <div id="tab-vendors">
        <!-- Vendor Summary -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <div class="text-sm text-gray-600">
                    Total Balance: 
                    <span class="font-bold {{ $totalVendorBalance >= 0 ? 'text-red-600' : 'text-green-600' }}">
                        Rs. {{ number_format(abs($totalVendorBalance)) }}
                    </span>
                    <span class="text-xs text-gray-400">{{ $totalVendorBalance >= 0 ? '(Payable)' : '(Receivable)' }}</span>
                </div>
            </div>
        </div>

        <!-- Vendor Filters -->
        <div class="bg-white border border-gray-200 rounded-xl p-4 mb-5">
            <form method="GET" action="{{ route('khaas.operations') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="tab" value="vendors">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Search</label>
                    <input type="text" name="vendor_search" value="{{ request('vendor_search') }}" placeholder="Vendor name, contact..." 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                </div>
                <div class="min-w-[130px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="vendor_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                        <option value="active" {{ $vendorStatus == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ $vendorStatus == 'inactive' ? 'selected' : '' }}>Inactive</option>
                        <option value="all" {{ $vendorStatus == 'all' ? 'selected' : '' }}>All</option>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700">
                    <i class="ki-filled ki-magnifier mr-1"></i> Filter
                </button>
                @if(request()->has('vendor_search'))
                    <a href="{{ route('khaas.operations', ['tab' => 'vendors']) }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200">Clear</a>
                @endif
            </form>
        </div>

        <!-- Vendors Table -->
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                            <th class="px-5 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($vendors as $vendor)
                        @php $balance = $vendor->account ? $vendor->account->current_balance : 0; @endphp
                        <tr class="hover:bg-amber-50/40 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-xs font-bold text-white" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                                        {{ strtoupper(substr($vendor->vendor_name, 0, 2)) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-900 text-sm">{{ $vendor->vendor_name }}</div>
                                        @if($vendor->contact_person)
                                            <div class="text-xs text-gray-500">{{ $vendor->contact_person }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                @if($vendor->contact_phone)
                                    <div class="text-sm text-gray-700">{{ $vendor->contact_phone }}</div>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded text-xs font-medium {{ $vendor->default_purchase_method === 'by_weight' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                                    {{ $vendor->default_purchase_method === 'by_weight' ? '⚖️ Weight' : '💵 Total' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="font-semibold text-sm {{ $balance > 0 ? 'text-red-600' : ($balance < 0 ? 'text-green-600' : 'text-gray-400') }}">
                                    Rs. {{ number_format(abs($balance)) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $vendor->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <a href="{{ route('fin.vendors.show', $vendor->id) }}" class="text-amber-600 hover:text-amber-800 text-xs font-medium">View →</a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center">
                                <div class="text-3xl mb-2">🏭</div>
                                <p class="text-sm text-gray-500">No Khaas vendors found.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($vendors->hasPages())
        <div class="mt-4">{{ $vendors->appends(request()->query())->links() }}</div>
        @endif
    </div>
    @endif

    {{-- ====================== EXPENSES TAB ====================== --}}
    @if($activeTab === 'expenses')
    <div id="tab-expenses">
        <!-- Expense KPIs -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-xs font-medium text-gray-500 uppercase">Total Expenses</p>
                <p class="text-xl font-bold text-gray-900 mt-1">Rs. {{ number_format($totalExpenses, 0) }}</p>
            </div>
            <div class="bg-white border border-amber-300 rounded-xl p-4" style="background-color: #fffbeb;">
                <p class="text-xs font-medium text-amber-600 uppercase">Pending Approvals</p>
                <p class="text-xl font-bold text-amber-700 mt-1">{{ $pendingExpenseCount }} <span class="text-sm font-normal text-amber-500">(Rs. {{ number_format($pendingExpenseAmount, 0) }})</span></p>
            </div>
            @foreach($buPaymentAccounts as $buAcc)
            {{-- Clickable: the balance alone never explained itself. Almost every movement on
                 the fund account is a vendor-payment outflow, so it changed daily with nothing
                 on screen to say why, by whom, or when. --}}
            <div class="bg-white border border-gray-200 rounded-xl p-4 cursor-pointer hover:border-amber-300 hover:shadow-sm transition-all group"
                 onclick="openAccountActivity({{ $buAcc->id }}, '{{ addslashes($buAcc->account_name) }}')"
                 title="Click to see the last movements in and out of this account">
                <p class="text-xs font-medium text-gray-500 uppercase">
                    {{ $buAcc->account_name }}
                    <span class="opacity-0 group-hover:opacity-100 transition-opacity text-[9px]">📋</span>
                </p>
                <p class="text-xl font-bold mt-1" style="color: {{ $buAcc->current_balance >= 0 ? '#059669' : '#DC2626' }}">Rs. {{ number_format($buAcc->current_balance, 0) }}</p>
                <p class="text-[10px] text-gray-400 mt-0.5">View activity 👆</p>
            </div>
            @endforeach
        </div>

        {{-- Pending Approval Expenses --}}
        @if($pendingExpenseCount > 0)
        <div class="bg-white border border-amber-300 rounded-xl mb-5 overflow-hidden" style="background-color: #fffbeb;">
            <div class="px-5 py-3 border-b border-amber-200 flex items-center gap-2">
                <span class="text-lg">⏳</span>
                <h3 class="font-semibold text-gray-900 text-sm">Pending Expense Approvals</h3>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold" style="background-color: #f59e0b; color: #ffffff;">{{ $pendingExpenseCount }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-amber-200">
                    <thead class="bg-amber-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Source</th>
                            <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100">
                        @foreach($pendingExpenses as $pending)
                        @php
                            $l1Done = $pending->approvals->where('approval_level', 1)->where('status', 'approved')->first();
                            $l2Done = $pending->approvals->where('approval_level', 2)->where('status', 'approved')->first();
                        @endphp
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-700">{{ $pending->created_at->format('M d') }}</td>
                            <td class="px-4 py-2"><span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">{{ $pending->expense_category ?? '—' }}</span></td>
                            <td class="px-4 py-2 text-sm text-gray-700">{{ $pending->requester?->fullname ?? '—' }}</td>
                            <td class="px-4 py-2 text-right text-sm font-bold text-gray-900">Rs. {{ number_format($pending->amount, 0) }}</td>
                            <td class="px-4 py-2 text-center">
                                @if($pending->paymentSourceAccount)
                                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded">{{ $pending->paymentSourceAccount->account_name }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-center">
                                @if($l1Done && $l2Done)
                                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">L1 ✅ L2 ✅</span>
                                @elseif($l1Done)
                                    <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">L1 ✅ L2 ⏳</span>
                                @else
                                    <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">L1 ⏳</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <!-- Expense Filters -->
        <div class="bg-white border border-gray-200 rounded-xl p-4 mb-5">
            <form method="GET" action="{{ route('khaas.operations') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="tab" value="expenses">
                <div class="min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                </div>
                <div class="min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                </div>
                <div class="min-w-[140px]">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                    <select name="exp_category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                        <option value="">All</option>
                        @foreach($expenseCategories as $cat)
                            <option value="{{ $cat }}" {{ $expCategory == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700">
                    <i class="ki-filled ki-magnifier mr-1"></i> Filter
                </button>
                @if(request()->hasAny(['date_from', 'date_to', 'exp_category']))
                    <a href="{{ route('khaas.operations', ['tab' => 'expenses']) }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200">Clear</a>
                @endif
            </form>
        </div>

        <!-- Expenses Table -->
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">By</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-5 py-3 text-center text-xs font-medium text-gray-500 uppercase">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($expenses as $expense)
                        @php $expDate = $expense->expense_date ?? $expense->created_at; @endphp
                        <tr class="hover:bg-amber-50/40 transition-colors">
                            <td class="px-5 py-3.5 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ \Carbon\Carbon::parse($expDate)->format('M d, Y') }}</div>
                                <div class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($expDate)->format('l') }}</div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">
                                    {{ $expense->expense_category ?? ($expense->category ? $expense->category->category_name : '—') }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="text-sm text-gray-900 max-w-[200px] truncate" title="{{ $expense->description }}">{{ $expense->description ?? '—' }}</div>
                                @if($expense->request_number)
                                    <div class="text-[10px] text-gray-400">{{ $expense->request_number }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-700">{{ $expense->requester ? $expense->requester->fullname : '—' }}</td>
                            <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                <span class="text-sm font-bold text-gray-900">Rs. {{ number_format($expense->amount, 0) }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                @if($expense->paymentSourceAccount)
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">{{ $expense->paymentSourceAccount->account_name }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            {{-- Status column removed — all rows here are approved --}}
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center">
                                <div class="text-3xl mb-2">💰</div>
                                <p class="text-sm text-gray-500">No Khaas expenses found for this period.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($expenses->hasPages())
        <div class="mt-4">{{ $expenses->appends(request()->query())->links() }}</div>
        @endif
    </div>
    @endif

    {{-- ====================== TRANSFERS TAB ====================== --}}
    @if($activeTab === 'transfers')
    <div id="tab-transfers">
        <!-- Transfer Status Filter -->
        <div class="flex items-center gap-2 mb-5">
            <a href="{{ route('khaas.operations', ['tab' => 'transfers', 'transfer_status' => 'all']) }}"
               class="px-3 py-1.5 text-sm rounded-lg font-medium" style="{{ $transferStatus === 'all' ? 'background-color:#1f2937;color:#ffffff;' : 'background-color:#f3f4f6;color:#4b5563;' }}">All</a>
            <a href="{{ route('khaas.operations', ['tab' => 'transfers', 'transfer_status' => 'pending']) }}"
               class="px-3 py-1.5 text-sm rounded-lg font-medium" style="{{ $transferStatus === 'pending' ? 'background-color:#d97706;color:#ffffff;' : 'background-color:#fffbeb;color:#b45309;' }}">⏳ Pending ({{ $pendingTransferCount }})</a>
            <a href="{{ route('khaas.operations', ['tab' => 'transfers', 'transfer_status' => 'approved']) }}"
               class="px-3 py-1.5 text-sm rounded-lg font-medium" style="{{ $transferStatus === 'approved' ? 'background-color:#16a34a;color:#ffffff;' : 'background-color:#f0fdf4;color:#15803d;' }}">✅ Approved ({{ $approvedTransferCount }})</a>
            <a href="{{ route('khaas.operations', ['tab' => 'transfers', 'transfer_status' => 'rejected']) }}"
               class="px-3 py-1.5 text-sm rounded-lg font-medium" style="{{ $transferStatus === 'rejected' ? 'background-color:#dc2626;color:#ffffff;' : 'background-color:#fef2f2;color:#b91c1c;' }}">❌ Rejected ({{ $rejectedTransferCount }})</a>
        </div>

        <!-- Transfers List -->
        <div class="space-y-3">
            @forelse($transfers as $transfer)
            <div class="bg-white border border-gray-200 rounded-xl p-4 hover:shadow-sm transition-shadow">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 flex-1">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm shrink-0 {{ $transfer->status === 'pending' ? 'bg-amber-100 text-amber-600' : ($transfer->status === 'approved' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600') }}">
                            @if($transfer->status === 'pending') ⏳ @elseif($transfer->status === 'approved') ✅ @else ❌ @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h4 class="font-semibold text-gray-900 text-sm">{{ $transfer->product ? $transfer->product->title : 'Product #' . $transfer->product_id }}</h4>
                                @if($transfer->variant)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 text-gray-600">{{ $transfer->variant->title }}</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                <span><strong>{{ $transfer->quantity }}</strong> units</span>
                                <span>🏭→🏪</span>
                                <span>by {{ $transfer->requester ? $transfer->requester->fullname : '—' }}</span>
                                <span>{{ $transfer->created_at->format('M d, h:i A') }}</span>
                            </div>
                            @if($transfer->notes)
                                <p class="text-xs text-gray-400 mt-1">📝 {{ $transfer->notes }}</p>
                            @endif
                            @if($transfer->status === 'approved' && $transfer->approver)
                                {{-- approver may be null if the user record was removed; the
                                     rejecter line below is already guarded the same way. --}}
                                <div class="text-xs text-green-600 mt-1">Approved by {{ $transfer->approver->fullname ?? 'Unknown' }} · {{ $transfer->approved_at ? $transfer->approved_at->format('M d, h:i A') : '' }}</div>
                            @elseif($transfer->status === 'rejected')
                                <div class="text-xs text-red-600 mt-1">
                                    Rejected{{ $transfer->rejected_at ? ' on ' . $transfer->rejected_at->format('M d, h:i A') : '' }}
                                    @if($transfer->rejection_reason) — {{ $transfer->rejection_reason }}@endif
                                </div>
                            @endif
                        </div>
                    </div>
                    @if($transfer->status === 'pending')
                    <div class="flex items-center gap-2 shrink-0">
                        <form method="POST" action="{{ route('khaas.transfers.approve', $transfer->id) }}" class="inline">
                            @csrf
                            <button type="submit" onclick="return confirm('Approve transfer of {{ $transfer->quantity }} units?')"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg shadow-sm" style="background-color: #16a34a; color: #ffffff;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">✓ Approve</button>
                        </form>
                        <button type="button" onclick="openRejectModal({{ $transfer->id }}, '{{ $transfer->product ? addslashes($transfer->product->title) : '' }}', {{ $transfer->quantity }})"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg" style="background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca;" onmouseover="this.style.backgroundColor='#fee2e2'" onmouseout="this.style.backgroundColor='#fef2f2'">✕ Reject</button>
                    </div>
                    @else
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase {{ $transfer->status === 'approved' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $transfer->status }}</span>
                    @endif
                </div>
            </div>
            @empty
            <div class="bg-white border border-gray-200 rounded-xl p-10 text-center">
                <div class="text-3xl mb-2">🚚</div>
                <p class="text-sm text-gray-500">
                    @if($transferStatus === 'pending') No transfers pending approval. All caught up! 🎉
                    @else No {{ $transferStatus !== 'all' ? $transferStatus : '' }} transfers found.
                    @endif
                </p>
            </div>
            @endforelse
        </div>
        @if($transfers->hasPages())
        <div class="mt-4">{{ $transfers->appends(request()->query())->links() }}</div>
        @endif
    </div>
    @endif
</div>

@endsection

@push('modals')
<!-- Reject Transfer Modal -->
{{-- ⚠️ Shell inline-styled deliberately: inset-0, flex, max-w-md, overflow-y-auto and
     flex-shrink-0 are ALL purged from the built styles.css, so a class-only shell renders
     un-positioned with no backdrop. Same pattern as khaas/products.blade.php. --}}
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeRejectModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden" style="width:100%; max-width:28rem; max-height:90vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-5 border-b border-gray-100" style="background: linear-gradient(to right, #fef2f2, #fff7ed);">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center text-xl">❌</div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">Reject Transfer</h3>
                    <p class="text-xs text-gray-500" id="rejectModalInfo"></p>
                </div>
            </div>
        </div>
        <form id="rejectForm" method="POST">
            @csrf
            <div class="px-6 py-5">
                <label class="block text-sm font-semibold text-gray-700 mb-2">Reason for Rejection</label>
                <textarea name="reason" rows="3" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500 resize-none" placeholder="Provide a reason..." required></textarea>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3">
                <button type="button" onclick="closeRejectModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm" style="background-color: #dc2626;" onmouseover="this.style.backgroundColor='#b91c1c'" onmouseout="this.style.backgroundColor='#dc2626'">Reject Transfer</button>
            </div>
        </form>
    </div>
</div>

{{-- Account Activity Modal — last movements in/out of a BU payment account.
     ⚠️ Shell rules (learned the hard way): inset-0 / max-w-* / overflow-y-auto / flex-shrink-0
     ARE purged from the built styles.css, so those must be inline. But .hidden, .flex,
     .items-center and .justify-center are NOT purged — so do NOT put `display` on the outer
     overlay inline, or it outranks .hidden{display:none} and the modal can never close. --}}
<div id="acctActivityModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem;" onclick="if(event.target===this)closeAccountActivity()">
    <div class="w-full bg-white rounded-2xl shadow-2xl text-left overflow-hidden" style="width:100%; max-width:40rem; max-height:85vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100" style="background: linear-gradient(to right, #f0fdf4, #ecfdf5); flex-shrink:0;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background-color:#d1fae5;"><span class="text-xl">💰</span></div>
                    <div>
                        <h3 id="acct-activity-title" class="text-lg font-bold text-gray-900">Account Activity</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Balance: <span id="acct-activity-balance" class="font-bold" style="color:#047857;">—</span></p>
                    </div>
                </div>
                <button onclick="closeAccountActivity()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div id="acct-activity-body" style="flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;">
            <div class="flex items-center justify-center py-12 text-gray-400">
                <svg class="animate-spin w-6 h-6 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Loading activity...
            </div>
        </div>
        <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-3" style="flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
            <p id="acct-activity-foot" class="text-[10px] text-gray-400">Most recent movements in and out of this account</p>
            <div class="flex items-center gap-2">
                <button id="acct-activity-more" onclick="loadAccountActivity(50)" class="px-3 py-2 text-sm font-medium rounded-lg" style="background-color:#f3f4f6; color:#374151; border:1px solid #d1d5db;">Show 50</button>
                <button onclick="closeAccountActivity()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Close</button>
            </div>
        </div>
    </div>
</div>
@endpush

@push('demo1_js')
<script>
function openRejectModal(transferId, productName, quantity) {
    document.getElementById('rejectModalInfo').textContent = quantity + ' units of "' + productName + '" — stock returns to warehouse';
    document.getElementById('rejectForm').action = '{{ url("khaas/transfers") }}/' + transferId + '/reject';
    document.getElementById('rejectModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    document.body.style.overflow = '';
}
// ═══════════════════════════════════════════════════════════════
// Account Activity — what actually moved this account's balance
// ═══════════════════════════════════════════════════════════════
var acctActivityId = null;

function openAccountActivity(accountId, accountName) {
    acctActivityId = accountId;
    document.getElementById('acct-activity-title').textContent = accountName;
    document.getElementById('acct-activity-balance').textContent = '—';
    document.getElementById('acct-activity-body').innerHTML =
        '<div class="flex items-center justify-center py-12 text-gray-400">' +
        '<svg class="animate-spin w-6 h-6 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>' +
        'Loading activity...</div>';
    document.getElementById('acctActivityModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    loadAccountActivity(10);
}

function closeAccountActivity() {
    document.getElementById('acctActivityModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function loadAccountActivity(limit) {
    if (!acctActivityId) return;
    var more = document.getElementById('acct-activity-more');
    more.disabled = true;
    fetch('{{ url("khaas/account-activity") }}/' + acctActivityId + '?limit=' + limit)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            more.disabled = false;
            if (!d.success) { renderAcctError(d.message); return; }
            renderAccountActivity(d, limit);
        })
        .catch(function(e) { more.disabled = false; renderAcctError(null); console.error(e); });
}

function renderAcctError(msg) {
    document.getElementById('acct-activity-body').innerHTML =
        '<div class="text-center py-12 text-red-400"><div class="text-3xl mb-2">⚠️</div>' +
        '<p class="text-sm">' + escAcct(msg || 'Failed to load activity.') + '</p></div>';
}

function renderAccountActivity(d, limit) {
    document.getElementById('acct-activity-balance').textContent = 'Rs. ' + Math.round(d.account.balance).toLocaleString();

    var rows = d.activity || [];
    if (rows.length === 0) {
        document.getElementById('acct-activity-body').innerHTML =
            '<div class="text-center py-12 text-gray-400"><div class="text-3xl mb-2">📭</div>' +
            '<p class="text-sm">No movements recorded for this account yet.</p></div>';
        document.getElementById('acct-activity-more').style.display = 'none';
        return;
    }

    var html = '';
    // In / Out / Balance strip
    html += '<div class="px-5 py-3 grid grid-cols-3 gap-2 border-b" style="position:sticky; top:0; z-index:2; background-color:#f9fafb; border-color:#e5e7eb;">';
    html += '<div><div class="text-[10px] text-gray-500 uppercase">In</div><div class="text-sm font-bold" style="color:#059669;">Rs. ' + Math.round(d.summary.total_in).toLocaleString() + '</div></div>';
    html += '<div><div class="text-[10px] text-gray-500 uppercase">Out</div><div class="text-sm font-bold" style="color:#dc2626;">Rs. ' + Math.round(d.summary.total_out).toLocaleString() + '</div></div>';
    html += '<div><div class="text-[10px] text-gray-500 uppercase">Balance</div><div class="text-sm font-bold" style="color:#2563eb;">Rs. ' + Math.round(d.account.balance).toLocaleString() + '</div></div>';
    html += '</div>';

    var lastDay = null;
    html += '<div class="divide-y divide-gray-50">';
    for (var i = 0; i < rows.length; i++) {
        var ev = rows[i];
        var isIn = ev.direction === 'in';

        if (ev.date_display !== lastDay) {
            lastDay = ev.date_display;
            html += '<div class="px-5 py-2 bg-gray-100 border-y border-gray-200">';
            html += '<span class="text-xs font-bold text-gray-700">📅 ' + escAcct(ev.date_display) + '</span></div>';
        }

        html += '<div class="px-5 py-2.5 hover:bg-gray-50 transition-colors" style="border-left: 3px solid ' + (isIn ? '#22c55e' : '#ef4444') + ';">';
        html += '<div class="flex items-start justify-between gap-3">';
        html += '<div class="flex-1 min-w-0">';
        html += '<div class="text-xs font-semibold text-gray-900">' + (isIn ? '📥' : '📤') + ' ' + escAcct(ev.type_label) + '</div>';
        html += '<div class="text-[11px] text-gray-600 mt-0.5">' + (isIn ? 'From' : 'To') + ': ' + escAcct(ev.counterparty) + '</div>';
        if (ev.description) {
            html += '<div class="text-[10px] text-gray-400 mt-0.5">' + escAcct(ev.description) + '</div>';
        }
        // the point of the whole view: exactly when, and who
        html += '<div class="text-[10px] text-gray-400 mt-1">🕒 ' + escAcct(ev.when_display) + ' · by <span class="font-medium text-gray-600">' + escAcct(ev.moved_by) + '</span></div>';
        html += '</div>';
        html += '<div class="text-right shrink-0">';
        html += '<div class="text-sm font-bold" style="color:' + (isIn ? '#059669' : '#dc2626') + ';">' + (isIn ? '+' : '−') + 'Rs. ' + Math.round(ev.amount).toLocaleString() + '</div>';
        if (ev.balance_after !== null && ev.balance_after !== undefined) {
            html += '<div class="text-[10px] text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded mt-1">bal ' + Math.round(ev.balance_after).toLocaleString() + '</div>';
        }
        if (ev.is_pending) {
            html += '<div class="text-[10px] font-bold mt-1" style="color:#b45309;">⏳ pending</div>';
        }
        html += '</div></div></div>';
    }
    html += '</div>';

    document.getElementById('acct-activity-body').innerHTML = html;

    var more = document.getElementById('acct-activity-more');
    // Only offer "Show 50" while there could be more to show.
    more.style.display = (limit < 50 && rows.length >= limit) ? 'inline-block' : 'none';
    document.getElementById('acct-activity-foot').textContent =
        rows.length + ' movement' + (rows.length === 1 ? '' : 's') + ' shown, newest first';
}

function escAcct(s) {
    if (s === null || s === undefined) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeRejectModal(); closeAccountActivity(); }
});
</script>
@endpush
