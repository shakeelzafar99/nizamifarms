@extends('layouts.app')

@section('title', '🌿 Khaas Expenses')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-amber-600 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">💰 {{ $khaasBU->name }} Expenses</h1>
                <p class="text-sm text-gray-600 mt-0.5">Track and manage expenses for {{ $khaasBU->name }} business unit</p>
            </div>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
            🌿 {{ $khaasBU->name }}
        </span>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <!-- Total Expenses -->
        <div class="bg-white border border-gray-200 rounded-xl p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Expenses</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">Rs. {{ number_format($totalExpenses, 0) }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-red-50 text-red-500">
                    <i class="ki-filled ki-chart-line-up-2 text-2xl"></i>
                </div>
            </div>
        </div>
        <!-- Settled -->
        <div class="bg-white border border-gray-200 rounded-xl p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Settled</p>
                    <p class="text-2xl font-bold text-green-600 mt-1">Rs. {{ number_format($settledExpenses, 0) }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-green-50 text-green-500">
                    <i class="ki-filled ki-check-circle text-2xl"></i>
                </div>
            </div>
        </div>
        <!-- Pending Settlement -->
        <div class="bg-white border border-gray-200 rounded-xl p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Pending Settlement</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">Rs. {{ number_format($pendingSettlement, 0) }}</p>
                </div>
                <div class="w-12 h-12 rounded-lg flex items-center justify-center bg-amber-50 text-amber-500">
                    <i class="ki-filled ki-time text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white border border-gray-200 rounded-xl p-4 mb-6">
        <form method="GET" action="{{ route('khaas.expenses') }}" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[150px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Date From</label>
                <input type="date" name="date_from" value="{{ $dateFrom }}" 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
            </div>
            <div class="min-w-[150px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Date To</label>
                <input type="date" name="date_to" value="{{ $dateTo }}" 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
            </div>
            <div class="min-w-[160px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                    <option value="">All Categories</option>
                    @foreach($expenseCategories as $cat)
                        <option value="{{ $cat }}" {{ $category == $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="block text-xs font-medium text-gray-600 mb-1">Settlement</label>
                <select name="settlement_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500">
                    <option value="">All</option>
                    <option value="pending" {{ $settlementStatus == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="settled" {{ $settlementStatus == 'settled' ? 'selected' : '' }}>Settled</option>
                    <option value="not_required" {{ $settlementStatus == 'not_required' ? 'selected' : '' }}>Not Required</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors">
                <i class="ki-filled ki-magnifier mr-1"></i> Filter
            </button>
            @if(request()->hasAny(['date_from', 'date_to', 'category', 'settlement_status']))
                <a href="{{ route('khaas.expenses') }}" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    Clear
                </a>
            @endif
        </form>
    </div>

    <!-- Expenses Table -->
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requested By</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Source</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Settlement</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($expenses as $expense)
                    @php
                        $expDate = $expense->expense_date ?? $expense->created_at;
                    @endphp
                    <tr class="hover:bg-amber-50/50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">
                                {{ \Carbon\Carbon::parse($expDate)->format('M d, Y') }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ \Carbon\Carbon::parse($expDate)->format('l') }}
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">
                                {{ $expense->expense_category ?? ($expense->category ? $expense->category->category_name : 'N/A') }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-900 max-w-xs truncate" title="{{ $expense->description }}">
                                {{ $expense->description ?? '—' }}
                            </div>
                            @if($expense->request_number)
                                <div class="text-xs text-gray-400">{{ $expense->request_number }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-700">
                                {{ $expense->requester ? $expense->requester->fullname : '—' }}
                            </div>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <span class="text-sm font-bold text-gray-900">Rs. {{ number_format($expense->amount, 0) }}</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if($expense->paymentSourceAccount)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
                                    {{ $expense->paymentSourceAccount->account_name }}
                                </span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if($expense->settlement_status === 'settled')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                    ✅ Settled
                                </span>
                                @if($expense->settledBy)
                                    <div class="text-[10px] text-gray-400 mt-0.5">by {{ $expense->settledBy->fullname }}</div>
                                @endif
                            @elseif($expense->settlement_status === 'pending')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                    ⏳ Pending
                                </span>
                            @elseif($expense->settlement_status === 'not_required' || $expense->settlement_status === 'not_applicable')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                    N/A
                                </span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <div class="text-4xl mb-3">💰</div>
                            <h3 class="text-lg font-semibold text-gray-700">No Khaas Expenses Found</h3>
                            <p class="text-sm text-gray-500 mt-1">Expenses for the {{ $khaasBU->name }} business unit in the selected period will appear here.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    @if($expenses->hasPages())
    <div class="mt-6">
        {{ $expenses->appends(request()->query())->links() }}
    </div>
    @endif
</div>
@endsection
