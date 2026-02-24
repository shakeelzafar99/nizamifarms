@extends('layouts.app')

@section('title', '🧾 Khaas Sales Report')

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('khaas.dashboard') }}" class="text-gray-400 hover:text-amber-600 transition-colors">
                <i class="ki-filled ki-arrow-left text-lg"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">🧾 {{ $khaasBU->name }} Sales Report</h1>
                <p class="text-sm text-gray-600 mt-0.5">Product-level sales breakdown for {{ $monthLabel }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <!-- Month Selector -->
            <form method="GET" action="{{ route('khaas.sales-report') }}" class="flex items-center gap-2">
                <select name="month" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500" style="min-width: 160px;">
                    @foreach($availableMonths as $val => $label)
                        <option value="{{ $val }}" {{ $selectedMonth == $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                🌿 {{ $khaasBU->name }}
            </span>
        </div>
    </div>

    <!-- Summary KPI Row -->
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 1.25rem;">
        <div class="bg-white border border-green-200 rounded-xl px-5 py-4 flex items-center gap-3" style="background-color: #f0fdf4;">
            <span class="text-2xl">💰</span>
            <div class="flex-1">
                <div class="text-xl font-bold text-green-800">Rs. {{ number_format($grandTotalRevenue) }}</div>
                <div class="text-xs text-green-600">Total Revenue · {{ $monthLabel }}</div>
            </div>
        </div>
        <div class="bg-white border border-green-200 rounded-xl px-5 py-4 flex items-center gap-3" style="background-color: #f0fdf4;">
            <span class="text-2xl">📦</span>
            <div class="flex-1">
                <div class="text-xl font-bold text-green-800">{{ round($grandTotalQty) }}</div>
                <div class="text-xs text-green-600">Total Units Sold</div>
            </div>
        </div>
        <div class="bg-white border border-green-200 rounded-xl px-5 py-4 flex items-center gap-3" style="background-color: #f0fdf4;">
            <span class="text-2xl">🛒</span>
            <div class="flex-1">
                <div class="text-xl font-bold text-green-800">{{ $grandTotalOrders }}</div>
                <div class="text-xs text-green-600">Orders with Khaas Products</div>
            </div>
        </div>
    </div>

    <!-- Product Sales Breakdown -->
    <div class="bg-white border border-gray-200 rounded-xl mb-6">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">📊 Product Sales Breakdown — {{ $monthLabel }}</h3>
            <span class="text-xs text-gray-500">{{ $productSales->count() }} products</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-100 text-left">
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Product</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Qty Sold</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Revenue</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Orders</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Avg Price/Unit</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">% of Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($productSales as $ps)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                                    {{ strtoupper(substr($ps->name ?? '?', 0, 1)) }}
                                </div>
                                <span class="text-sm font-medium text-gray-900">{{ $ps->name ?? 'Unknown Product' }}</span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span class="text-sm font-semibold text-gray-800">{{ round($ps->total_qty) }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span class="text-sm font-bold text-green-700">Rs. {{ number_format($ps->total_revenue) }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span class="text-sm text-gray-700">{{ $ps->order_count }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span class="text-sm text-gray-600">Rs. {{ $ps->total_qty > 0 ? number_format($ps->total_revenue / $ps->total_qty) : '0' }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            @php $pct = $grandTotalRevenue > 0 ? round(($ps->total_revenue / $grandTotalRevenue) * 100, 1) : 0; @endphp
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-16 h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full" style="width: {{ $pct }}%; background-color: #16a34a;"></div>
                                </div>
                                <span class="text-xs font-medium text-gray-600 w-10 text-right">{{ $pct }}%</span>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-gray-400">
                            <div class="text-3xl mb-2">🧾</div>
                            No sales data found for {{ $monthLabel }}.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if($productSales->count() > 0)
                <tfoot>
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-5 py-3 text-sm text-gray-900">TOTAL</td>
                        <td class="px-5 py-3 text-right text-sm text-gray-900">{{ round($grandTotalQty) }}</td>
                        <td class="px-5 py-3 text-right text-sm font-bold text-green-700">Rs. {{ number_format($grandTotalRevenue) }}</td>
                        <td class="px-5 py-3 text-right text-sm text-gray-900">{{ $grandTotalOrders }}</td>
                        <td class="px-5 py-3 text-right text-sm text-gray-600">Rs. {{ $grandTotalQty > 0 ? number_format($grandTotalRevenue / $grandTotalQty) : '0' }}</td>
                        <td class="px-5 py-3 text-right text-sm text-gray-600">100%</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>

    <!-- Detailed Transactions Table -->
    <div class="bg-white border border-gray-200 rounded-xl">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">📋 Transaction Details — {{ $monthLabel }}</h3>
            <span class="text-xs text-gray-500">{{ $transactions->total() }} line items</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-100 text-left">
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Order</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Customer</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Product</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Qty</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Unit Price</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Total</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($transactions as $txn)
                    @php
                        $order = $txn->order;
                        $customer = $order?->customer;
                    @endphp
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-3">
                            <a href="{{ route('orders.show', $order->id ?? 0) }}" class="text-sm font-bold text-blue-700 hover:text-blue-900 hover:underline">
                                {{ $order->order_number ?? '#' }}
                            </a>
                        </td>
                        <td class="px-5 py-3 text-sm text-gray-600 whitespace-nowrap">
                            {{ $order && $order->order_date ? date('d M Y', strtotime($order->order_date)) : '—' }}
                        </td>
                        <td class="px-5 py-3">
                            <div class="text-sm text-gray-900">{{ $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Walk-in' }}</div>
                            @if($customer && $customer->phone_original)
                                <div class="text-[10px] text-gray-400">{{ $customer->phone_original }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-sm font-medium text-gray-900">{{ $txn->name ?? 'Unknown' }}</span>
                            @if($txn->sku)
                                <span class="text-[10px] text-gray-400 ml-1">({{ $txn->sku }})</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-sm font-medium text-gray-800">{{ round($txn->quantity) }}</td>
                        <td class="px-5 py-3 text-right text-sm text-gray-600">Rs. {{ number_format($txn->unit_price) }}</td>
                        <td class="px-5 py-3 text-right text-sm font-bold text-green-700">Rs. {{ number_format($txn->line_total) }}</td>
                        <td class="px-5 py-3">
                            @php
                                $statusColors = [
                                    'delivered' => 'bg-green-100 text-green-700',
                                    'new' => 'bg-blue-100 text-blue-700',
                                    'in_transit' => 'bg-yellow-100 text-yellow-700',
                                    'out_for_delivery' => 'bg-yellow-100 text-yellow-700',
                                    'pending' => 'bg-gray-100 text-gray-600',
                                ];
                                $statusClass = $statusColors[$order->order_status ?? ''] ?? 'bg-gray-100 text-gray-600';
                            @endphp
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $statusClass }}">
                                {{ str_replace('_', ' ', $order->order_status ?? 'unknown') }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap">
                            {{ ucfirst(str_replace('_', ' ', $order->payment_method ?? '—')) }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-5 py-12 text-center text-gray-400">
                            <div class="text-3xl mb-2">📋</div>
                            No transactions found for {{ $monthLabel }}.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($transactions->hasPages())
        <div class="px-5 py-4 border-t border-gray-100">
            {{ $transactions->appends(request()->query())->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
