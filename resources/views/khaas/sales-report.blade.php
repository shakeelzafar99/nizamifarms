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
            <form method="GET" action="{{ route('khaas.sales-report') }}" id="sales-filter-form" class="flex items-center gap-2">
                <select name="month" onchange="this.form.submit()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-amber-500 focus:border-amber-500" style="min-width: 160px;">
                    @foreach($availableMonths as $val => $label)
                        <option value="{{ $val }}" {{ $selectedMonth == $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="exclude_free" id="exclude_free_input" value="{{ request('exclude_free', 0) }}">
            </form>
            <label class="flex items-center gap-1.5 cursor-pointer select-none">
                <input type="checkbox" id="exclude_free_cb" {{ request('exclude_free') ? 'checked' : '' }}
                    onchange="document.getElementById('exclude_free_input').value = this.checked ? 1 : 0; document.getElementById('sales-filter-form').submit();"
                    class="w-4 h-4 rounded border-gray-300 text-green-600 focus:ring-green-500">
                <span class="text-xs font-medium text-gray-600">Exclude Free</span>
            </label>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                🌿 {{ $khaasBU->name }}
            </span>
        </div>
    </div>

    <!-- Summary KPI Row -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
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
                <div class="text-xl font-bold text-green-800">{{ round($grandTotalDeliveredQty) }}</div>
                <div class="text-xs text-green-600">Delivered Units</div>
            </div>
        </div>
        <div class="bg-white border border-amber-200 rounded-xl px-5 py-4 flex items-center gap-3" style="background-color: #fffbeb;">
            <span class="text-2xl">⏳</span>
            <div class="flex-1">
                <div class="text-xl font-bold text-amber-700">{{ round($grandTotalOpenQty) }}</div>
                <div class="text-xs text-amber-600">Open (Pending)</div>
            </div>
        </div>
        <div class="bg-white border border-purple-200 rounded-xl px-5 py-4 flex items-center gap-3" style="background-color: #faf5ff;">
            <span class="text-2xl">🎁</span>
            <div class="flex-1">
                <div class="text-xl font-bold text-purple-700">{{ round($grandTotalFreeQty) }}</div>
                <div class="text-xs text-purple-600">Free Items</div>
            </div>
        </div>
        <div class="bg-white border border-green-200 rounded-xl px-5 py-4 flex items-center gap-3" style="background-color: #f0fdf4;">
            <span class="text-2xl">🛒</span>
            <div class="flex-1">
                <div class="text-xl font-bold text-green-800">{{ $grandTotalOrders }}</div>
                <div class="text-xs text-green-600">Orders with {{ $khaasBU->name }} Products</div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="flex border-b border-gray-200 mb-4">
        <button id="tab-product-sales" onclick="switchSalesTab('product-sales')" class="px-5 py-2.5 text-sm font-semibold border-b-2 border-green-600 text-green-700 transition-colors">
            📊 Product Sales
        </button>
        <button id="tab-daily-sales" onclick="switchSalesTab('daily-sales')" class="px-5 py-2.5 text-sm font-semibold border-b-2 border-transparent text-gray-500 hover:text-gray-700 transition-colors">
            📅 Daily Sales
        </button>
    </div>

    <!-- PRODUCT SALES TAB -->
    <div id="panel-product-sales">

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
                        <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Product</th>
                        <th class="px-3 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Total Qty</th>
                        <th class="px-3 py-3 text-xs font-semibold text-green-600 uppercase tracking-wider text-right">Delivered</th>
                        <th class="px-3 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider text-right">Open</th>
                        <th class="px-3 py-3 text-xs font-semibold text-purple-600 uppercase tracking-wider text-right">Free</th>
                        <th class="px-3 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Revenue</th>
                        <th class="px-3 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Orders</th>
                        <th class="px-3 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">% of Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse($productSales as $ps)
                    <tr class="hover:bg-gray-50 transition-colors cursor-pointer" onclick="toggleDailyBreakdown({{ $ps->product_id }}, this)">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold" style="background-color: {{ $khaasBU->color_hex ?? '#f59e0b' }}20; color: {{ $khaasBU->color_hex ?? '#f59e0b' }};">
                                    {{ strtoupper(substr($ps->name ?? '?', 0, 1)) }}
                                </div>
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ $ps->name ?? 'Unknown Product' }}</span>
                                    <span class="text-[10px] text-gray-400 ml-1">▶ daily</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <span class="text-sm font-semibold text-gray-800">{{ round($ps->total_qty) }}</span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <span class="text-sm font-semibold text-green-700">{{ round($ps->delivered_qty) }}</span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if($ps->open_qty > 0)
                            <span class="text-sm font-semibold text-amber-600">{{ round($ps->open_qty) }}</span>
                            @else
                            <span class="text-sm text-gray-300">0</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if($ps->free_qty > 0)
                            <span class="text-sm font-semibold text-purple-600">{{ round($ps->free_qty) }}</span>
                            @else
                            <span class="text-sm text-gray-300">0</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right">
                            <span class="text-sm font-bold text-green-700">Rs. {{ number_format($ps->total_revenue) }}</span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <span class="text-sm text-gray-700">{{ $ps->order_count }}</span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            @php $pct = $grandTotalRevenue > 0 ? round(($ps->total_revenue / $grandTotalRevenue) * 100, 1) : 0; @endphp
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-14 h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full" style="width: {{ $pct }}%; background-color: #16a34a;"></div>
                                </div>
                                <span class="text-xs font-medium text-gray-600 w-10 text-right">{{ $pct }}%</span>
                            </div>
                        </td>
                    </tr>
                    <tr class="hidden daily-row" id="daily-{{ $ps->product_id }}">
                        <td colspan="8" class="px-0 py-0">
                            <div class="bg-gray-50 px-8 py-3 text-xs" id="daily-content-{{ $ps->product_id }}">
                                <div class="text-gray-400 text-center py-2">Loading daily breakdown...</div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-5 py-12 text-center text-gray-400">
                            <div class="text-3xl mb-2">🧾</div>
                            No sales data found for {{ $monthLabel }}.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
                @if($productSales->count() > 0)
                <tfoot>
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-3 text-sm text-gray-900">TOTAL</td>
                        <td class="px-3 py-3 text-right text-sm text-gray-900">{{ round($grandTotalQty) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-green-700">{{ round($grandTotalDeliveredQty) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-amber-600">{{ round($grandTotalOpenQty) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-purple-600">{{ round($grandTotalFreeQty) }}</td>
                        <td class="px-3 py-3 text-right text-sm font-bold text-green-700">Rs. {{ number_format($grandTotalRevenue) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-gray-900">{{ $grandTotalOrders }}</td>
                        <td class="px-3 py-3 text-right text-sm text-gray-600">100%</td>
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
                        <td class="px-5 py-3 text-right text-sm font-medium text-gray-800">
                            {{ round($txn->quantity) }}
                            @if($txn->is_free)
                                <span class="ml-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-purple-100 text-purple-700">FREE</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-sm text-gray-600">
                            @if($txn->is_free)
                                <span class="line-through text-gray-400">Rs. {{ number_format($txn->unit_price) }}</span>
                                <span class="text-purple-600 font-bold ml-1">Rs. 0</span>
                            @else
                                Rs. {{ number_format($txn->unit_price) }}
                            @endif
                        </td>
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

    </div>{{-- end panel-product-sales --}}

    <!-- DAILY SALES TAB -->
    <div id="panel-daily-sales" class="hidden">
        <div class="bg-white border border-gray-200 rounded-xl mb-6">
            <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900">📅 Daily Sales — {{ $monthLabel }}</h3>
                <div id="daily-sales-summary" class="text-xs text-gray-500"></div>
            </div>

            <div id="daily-sales-loading" class="px-5 py-12 text-center text-gray-400">
                <div class="inline-block animate-spin rounded-full h-6 w-6 border-2 border-gray-300 border-t-green-600 mb-2"></div>
                <div class="text-sm">Loading daily sales data...</div>
            </div>

            <div id="daily-sales-content" class="hidden overflow-x-auto">
                <table class="w-full" id="daily-sales-table">
                    <thead>
                        <tr class="border-b border-gray-100 text-left">
                            <th class="px-4 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                            <th class="px-3 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Orders</th>
                            <th class="px-3 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Qty</th>
                            <th class="px-3 py-3 text-xs font-semibold text-green-600 uppercase tracking-wider text-right">Delivered</th>
                            <th class="px-3 py-3 text-xs font-semibold text-purple-600 uppercase tracking-wider text-right">Free</th>
                            <th class="px-3 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody id="daily-sales-body" class="divide-y divide-gray-50"></tbody>
                </table>
            </div>

            <div id="daily-sales-empty" class="hidden px-5 py-12 text-center text-gray-400">
                <div class="text-3xl mb-2">📅</div>
                No daily sales data found for {{ $monthLabel }}.
            </div>
        </div>
    </div>{{-- end panel-daily-sales --}}

</div>

<script>
/* ── Tab switching ── */
var dailySalesLoaded = false;
function switchSalesTab(tab) {
    var tabs = ['product-sales', 'daily-sales'];
    tabs.forEach(function(t) {
        var panel = document.getElementById('panel-' + t);
        var btn = document.getElementById('tab-' + t);
        if (t === tab) {
            panel.classList.remove('hidden');
            btn.classList.add('border-green-600', 'text-green-700');
            btn.classList.remove('border-transparent', 'text-gray-500');
        } else {
            panel.classList.add('hidden');
            btn.classList.remove('border-green-600', 'text-green-700');
            btn.classList.add('border-transparent', 'text-gray-500');
        }
    });
    if (tab === 'daily-sales' && !dailySalesLoaded) {
        loadDailySales();
    }
}

/* ── Daily Sales AJAX ── */
var dailySalesData = null;
function loadDailySales() {
    dailySalesLoaded = true;
    var excludeFreeVal = document.getElementById('exclude_free_cb') && document.getElementById('exclude_free_cb').checked ? '1' : '0';
    fetch('/khaas/sales-report/daily-ajax?month={{ $selectedMonth }}&exclude_free=' + excludeFreeVal, {
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('daily-sales-loading').classList.add('hidden');
        if (data.success && data.days && data.days.length > 0) {
            dailySalesData = data.days;
            document.getElementById('daily-sales-summary').textContent = data.days.length + ' days';
            renderDailySalesTable(data.days);
            document.getElementById('daily-sales-content').classList.remove('hidden');
        } else {
            document.getElementById('daily-sales-empty').classList.remove('hidden');
        }
    })
    .catch(function() {
        document.getElementById('daily-sales-loading').innerHTML = '<div class="text-red-400 text-center py-2">Failed to load daily data.</div>';
    });
}

function renderDailySalesTable(days) {
    var body = document.getElementById('daily-sales-body');
    var html = '';
    var totQty = 0, totDlvd = 0, totFree = 0, totRev = 0, totOrders = 0;
    for (var i = 0; i < days.length; i++) {
        var d = days[i];
        totQty += d.total_qty; totDlvd += d.delivered_qty; totFree += d.free_qty;
        totRev += d.revenue; totOrders += d.order_count;
        var dateStr = new Date(d.date + 'T00:00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', weekday: 'short'});

        html += '<tr class="hover:bg-gray-50 transition-colors cursor-pointer" onclick="toggleDailySalesProducts(' + i + ', this)">';
        html += '<td class="px-4 py-3"><span class="text-sm font-medium text-gray-900">' + dateStr + '</span><span class="text-[10px] text-gray-400 ml-1">▶</span></td>';
        html += '<td class="px-3 py-3 text-right text-sm text-gray-700">' + d.order_count + '</td>';
        html += '<td class="px-3 py-3 text-right text-sm font-semibold text-gray-800">' + d.total_qty + '</td>';
        html += '<td class="px-3 py-3 text-right text-sm font-semibold text-green-700">' + d.delivered_qty + '</td>';
        html += '<td class="px-3 py-3 text-right text-sm ' + (d.free_qty > 0 ? 'font-semibold text-purple-600' : 'text-gray-300') + '">' + (d.free_qty > 0 ? d.free_qty : '-') + '</td>';
        html += '<td class="px-3 py-3 text-right text-sm font-bold text-green-700">Rs. ' + Number(d.revenue).toLocaleString() + '</td>';
        html += '</tr>';

        html += '<tr class="hidden daily-products-row" id="daily-products-' + i + '">';
        html += '<td colspan="6" class="px-0 py-0"><div class="bg-gray-50 px-8 py-3">';
        if (d.products && d.products.length > 0) {
            html += '<table class="w-full text-xs"><thead><tr class="text-left border-b border-gray-200">';
            html += '<th class="py-1.5 pr-3 font-semibold text-gray-600">Product</th>';
            html += '<th class="py-1.5 pr-3 text-right font-semibold text-gray-600">Qty</th>';
            html += '<th class="py-1.5 pr-3 text-right font-semibold text-green-600">Delivered</th>';
            html += '<th class="py-1.5 pr-3 text-right font-semibold text-purple-600">Free</th>';
            html += '<th class="py-1.5 text-right font-semibold text-gray-600">Revenue</th>';
            html += '</tr></thead><tbody>';
            for (var j = 0; j < d.products.length; j++) {
                var p = d.products[j];
                html += '<tr class="border-b border-gray-100">';
                html += '<td class="py-1.5 pr-3 font-medium text-gray-700">' + (p.product_name || 'Unknown') + '</td>';
                html += '<td class="py-1.5 pr-3 text-right font-semibold">' + p.qty + '</td>';
                html += '<td class="py-1.5 pr-3 text-right text-green-700">' + p.delivered_qty + '</td>';
                html += '<td class="py-1.5 pr-3 text-right text-purple-600">' + (p.free_qty > 0 ? p.free_qty : '-') + '</td>';
                html += '<td class="py-1.5 text-right font-semibold text-green-700">Rs. ' + Number(p.revenue).toLocaleString() + '</td>';
                html += '</tr>';
            }
            html += '</tbody></table>';
        } else {
            html += '<div class="text-gray-400 text-center text-xs py-2">No product breakdown available.</div>';
        }
        html += '</div></td></tr>';
    }

    // Footer totals
    html += '<tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">';
    html += '<td class="px-4 py-3 text-sm text-gray-900">TOTAL</td>';
    html += '<td class="px-3 py-3 text-right text-sm text-gray-900">' + totOrders + '</td>';
    html += '<td class="px-3 py-3 text-right text-sm text-gray-900">' + totQty + '</td>';
    html += '<td class="px-3 py-3 text-right text-sm text-green-700">' + totDlvd + '</td>';
    html += '<td class="px-3 py-3 text-right text-sm text-purple-600">' + totFree + '</td>';
    html += '<td class="px-3 py-3 text-right text-sm font-bold text-green-700">Rs. ' + Number(totRev).toLocaleString() + '</td>';
    html += '</tr>';

    body.innerHTML = html;
}

function toggleDailySalesProducts(idx, clickedRow) {
    var row = document.getElementById('daily-products-' + idx);
    if (!row) return;
    var isHidden = row.classList.contains('hidden');
    document.querySelectorAll('.daily-products-row').forEach(function(r) { r.classList.add('hidden'); });
    if (isHidden) row.classList.remove('hidden');
}

/* ── Product daily breakdown (existing) ── */
var dailyCache = {};
function toggleDailyBreakdown(productId, clickedRow) {
    var row = document.getElementById('daily-' + productId);
    if (!row) return;
    var isHidden = row.classList.contains('hidden');
    // Close all other open rows
    document.querySelectorAll('.daily-row').forEach(function(r) { r.classList.add('hidden'); });
    if (isHidden) {
        row.classList.remove('hidden');
        if (!dailyCache[productId]) {
            fetch('/khaas/sales-report/product/' + productId + '/daily?month={{ $selectedMonth }}')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.daily && data.daily.length > 0) {
                        dailyCache[productId] = data.daily;
                        renderDailyTable(productId, data.daily);
                    } else {
                        document.getElementById('daily-content-' + productId).innerHTML = '<div class="text-gray-400 text-center py-2">No daily data found.</div>';
                    }
                })
                .catch(function() {
                    document.getElementById('daily-content-' + productId).innerHTML = '<div class="text-red-400 text-center py-2">Failed to load.</div>';
                });
        } else {
            renderDailyTable(productId, dailyCache[productId]);
        }
    }
}
function renderDailyTable(productId, daily) {
    var h = '<table class="w-full text-xs"><thead><tr class="text-left border-b border-gray-200">';
    h += '<th class="py-1.5 pr-3 font-semibold text-gray-600">Date</th>';
    h += '<th class="py-1.5 pr-3 text-right font-semibold text-gray-600">Total</th>';
    h += '<th class="py-1.5 pr-3 text-right font-semibold text-green-600">Delivered</th>';
    h += '<th class="py-1.5 pr-3 text-right font-semibold text-amber-600">Open</th>';
    h += '<th class="py-1.5 pr-3 text-right font-semibold text-purple-600">Free</th>';
    h += '<th class="py-1.5 pr-3 text-right font-semibold text-gray-600">Revenue</th>';
    h += '<th class="py-1.5 text-right font-semibold text-gray-600">Orders</th>';
    h += '</tr></thead><tbody>';
    for (var i = 0; i < daily.length; i++) {
        var d = daily[i];
        var dateStr = new Date(d.sale_date + 'T00:00:00').toLocaleDateString('en-GB', {day: '2-digit', month: 'short', weekday: 'short'});
        h += '<tr class="border-b border-gray-100">';
        h += '<td class="py-1.5 pr-3 font-medium text-gray-700">' + dateStr + '</td>';
        h += '<td class="py-1.5 pr-3 text-right font-semibold">' + Math.round(d.total_qty) + '</td>';
        h += '<td class="py-1.5 pr-3 text-right text-green-700">' + Math.round(d.delivered_qty) + '</td>';
        h += '<td class="py-1.5 pr-3 text-right text-amber-600">' + (d.open_qty > 0 ? Math.round(d.open_qty) : '-') + '</td>';
        h += '<td class="py-1.5 pr-3 text-right text-purple-600">' + (d.free_qty > 0 ? Math.round(d.free_qty) : '-') + '</td>';
        h += '<td class="py-1.5 pr-3 text-right font-semibold text-green-700">Rs. ' + Number(d.revenue).toLocaleString() + '</td>';
        h += '<td class="py-1.5 text-right text-gray-600">' + d.order_count + '</td>';
        h += '</tr>';
    }
    h += '</tbody></table>';
    document.getElementById('daily-content-' + productId).innerHTML = h;
}
</script>
@endsection
