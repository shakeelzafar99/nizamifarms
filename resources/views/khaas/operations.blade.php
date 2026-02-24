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
            <div class="bg-white border border-gray-200 rounded-xl p-4">
                <p class="text-xs font-medium text-gray-500 uppercase">{{ $buAcc->account_name }}</p>
                <p class="text-xl font-bold mt-1" style="color: {{ $buAcc->current_balance >= 0 ? '#059669' : '#DC2626' }}">Rs. {{ number_format($buAcc->current_balance, 0) }}</p>
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
                                <div class="text-xs text-green-600 mt-1">Approved by {{ $transfer->approver->fullname }} · {{ $transfer->approved_at ? $transfer->approved_at->format('M d, h:i A') : '' }}</div>
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
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 999999;" onclick="if(event.target===this)closeRejectModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden" onclick="event.stopPropagation()">
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
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeRejectModal(); });
</script>
@endpush
