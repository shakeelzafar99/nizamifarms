@extends('layouts.app')

@section('title', 'Qurbani Orders')

@push('custom_css')
<style>
.qurbani-tab { cursor: pointer; transition: all 0.2s; }
.qurbani-tab.active { border-color: #d97706; color: #d97706; font-weight: 600; }
.qurbani-tab:not(.active) { border-color: transparent; color: #6b7280; }
.qurbani-tab:hover:not(.active) { color: #374151; border-color: #d1d5db; }
.order-row:hover { background-color: #fffbeb; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.status-open { background: #dbeafe; color: #1e40af; }
.status-confirmed { background: #d1fae5; color: #065f46; }
.status-delivered { background: #dcfce7; color: #166534; }
.status-cancelled { background: #fee2e2; color: #991b1b; }
.pay-unpaid { background: #fee2e2; color: #991b1b; }
.pay-partial { background: #fef3c7; color: #92400e; }
.pay-paid { background: #dcfce7; color: #166534; }
.filter-select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white; min-width: 120px; }
.chart-container { position: relative; height: 300px; }
</style>
@endpush

@section('content')
<div class="kt-container-fixed" style="max-width: 1400px;">
    <!-- Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <div>
            <h1 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Qurbani Orders</h1>
            <p style="font-size: 14px; color: #6b7280; margin: 4px 0 0;">Manage and track all Qurbani orders</p>
        </div>
        <a href="/orders?create_new_order=1&qurbani_mode=1" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #d97706; color: #fff; font-size: 14px; font-weight: 600; border-radius: 8px; text-decoration: none; transition: background 0.2s;" onmouseover="this.style.background='#b45309'" onmouseout="this.style.background='#d97706'">
            <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
            New Qurbani Order
        </a>
    </div>

    <!-- Main Tabs: Orders / Dashboard -->
    <div class="mb-5">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-6">
                <button id="ordersMainTab" class="qurbani-tab active border-b-2 py-2 px-1 text-sm" onclick="switchMainTab('orders')">
                    <i class="ki-filled ki-parcel mr-1"></i> Orders
                </button>
                <button id="dashboardMainTab" class="qurbani-tab border-b-2 py-2 px-1 text-sm" onclick="switchMainTab('dashboard')">
                    <i class="ki-filled ki-chart-line mr-1"></i> Dashboard
                </button>
            </nav>
        </div>
    </div>

    <!-- ======= ORDERS TAB ======= -->
    <div id="ordersContent">
        <!-- Filters Row -->
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Day</label>
                    <select id="filterDay" class="filter-select" onchange="onDayFilterChange()">
                        <option value="">All Days</option>
                        @foreach($days as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Slot</label>
                    <select id="filterSlot" class="filter-select" onchange="loadOrders()">
                        <option value="">All Slots</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Region</label>
                    <select id="filterRegion" class="filter-select" onchange="loadOrders()">
                        <option value="">All Regions</option>
                        @foreach($regions as $r)
                        <option value="{{ $r }}">{{ $r }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Delivery Type</label>
                    <select id="filterDeliveryType" class="filter-select" onchange="loadOrders()">
                        <option value="">All Types</option>
                        @foreach($deliveryTypes as $dt)
                        <option value="{{ $dt }}">{{ $dt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Status</label>
                    <select id="filterStatus" class="filter-select" onchange="loadOrders()">
                        <option value="">All Statuses</option>
                        <option value="open">Open</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="in_progress">In Progress</option>
                        <option value="delivered">Delivered</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Customer</label>
                    <input type="text" id="filterCustomer" class="filter-select" placeholder="Search customer..." oninput="debouncedLoadOrders()" style="min-width: 160px;">
                </div>
                <div class="ml-auto flex items-end gap-2">
                    <button onclick="clearFilters()" class="px-3 py-2 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md">
                        Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Region Sub-Tabs -->
        <div class="mb-4">
            <div class="flex flex-wrap gap-2">
                <button class="region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 border border-amber-300" data-region="" onclick="selectRegionTab(this, '')">
                    All Orders
                </button>
                @foreach($regions as $r)
                <button class="region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200" data-region="{{ $r }}" onclick="selectRegionTab(this, '{{ $r }}')">
                    {{ $r }}
                </button>
                @endforeach
            </div>
        </div>

        <!-- Category Summary Cards (shown when region selected) -->
        <div id="categorySummary" style="display:none;" class="mb-3"></div>

        <!-- Orders Count -->
        <div class="flex items-center justify-between mb-3">
            <span id="ordersCount" class="text-sm text-gray-500">Loading...</span>
        </div>

        <!-- Orders Table -->
        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Day</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Slot</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Region</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Payment</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rider</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="ordersBody">
                        <tr><td colspan="11" class="px-4 py-8 text-center text-gray-400">Loading orders...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ======= DASHBOARD TAB ======= -->
    <div id="dashboardContent" style="display:none;">
        <!-- Yearly Summary Chart -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Qurbani Orders by Year</h2>
            <div class="chart-container">
                <canvas id="yearlyChart"></canvas>
            </div>
        </div>

        <!-- Year Selector + Table -->
        <div class="bg-white border border-gray-200 rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Order Details</h2>
                <select id="yearSelector" class="filter-select" onchange="loadDashboard()">
                    <option value="{{ date('Y') }}">{{ date('Y') }}</option>
                </select>
            </div>
            <div id="yearTableLoading" class="py-8 text-center text-gray-400">Loading...</div>
            <div id="yearTableContainer" style="display:none;">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Day</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Region</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                            </tr>
                        </thead>
                        <tbody id="yearTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Inline Edit Modal for Taimur role -->
@php
    $isTaimurOrMgmt = false;
    if (auth()->check()) {
        $isTaimurOrMgmt = \DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', auth()->id())
            ->where(function($q) {
                $q->whereRaw('LOWER(r.urole_name) = ?', ['taimur'])
                  ->orWhereRaw('LOWER(r.type) = ?', ['management']);
            })
            ->exists();
    }
@endphp

@if($isTaimurOrMgmt)
<div id="editFieldOptionsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10100;">
    <div style="max-width:500px; margin:60px auto; background:white; border-radius:12px; max-height:80vh; overflow:auto;">
        <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-weight:600; font-size:16px; margin:0;" id="editFieldTitle">Edit Options</h3>
            <button onclick="closeEditFieldModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280;">&times;</button>
        </div>
        <div style="padding:20px;" id="editFieldBody"></div>
    </div>
</div>
@endif

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
let yearlyChart = null;
let allOrders = [];
let hasItemFilters = false;
let activeCategoryFilter = null;
let currentRegion = '';
const isTaimurOrMgmt = {{ $isTaimurOrMgmt ? 'true' : 'false' }};
const fieldOptions = @json($fieldOptions);
window._qurbaniRiders = @json($riders);

function switchMainTab(tab) {
    document.getElementById('ordersMainTab').classList.toggle('active', tab === 'orders');
    document.getElementById('dashboardMainTab').classList.toggle('active', tab === 'dashboard');
    document.getElementById('ordersContent').style.display = tab === 'orders' ? '' : 'none';
    document.getElementById('dashboardContent').style.display = tab === 'dashboard' ? '' : 'none';
    if (tab === 'dashboard' && !yearlyChart) loadDashboard();
}

function selectRegionTab(btn, region) {
    document.querySelectorAll('.region-tab').forEach(b => {
        b.className = 'region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200';
    });
    btn.className = 'region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 border border-amber-300';
    document.getElementById('filterRegion').value = region;
    currentRegion = region;
    activeCategoryFilter = null;
    loadOrders();
}

function clearFilters() {
    document.getElementById('filterDay').value = '';
    document.getElementById('filterSlot').value = '';
    document.getElementById('filterRegion').value = '';
    document.getElementById('filterDeliveryType').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterCustomer').value = '';
    currentRegion = '';
    activeCategoryFilter = null;
    document.querySelectorAll('.region-tab').forEach((b, i) => {
        b.className = i === 0
            ? 'region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 border border-amber-300'
            : 'region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200';
    });
    loadOrders();
}

let qurbaniSlotOptionsCache = null;
let qurbaniDayOptionsCache = null;

function loadSlotOptions() {
    if (qurbaniSlotOptionsCache) { updateSlotDropdown(); return; }
    fetch('/qurbani-settings/api/options', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                qurbaniSlotOptionsCache = (data.options || {}).qurbani_slot || [];
                qurbaniDayOptionsCache = (data.options || {}).qurbani_day || [];
                updateSlotDropdown();
            }
        });
}

function updateSlotDropdown() {
    const slotSel = document.getElementById('filterSlot');
    const dayVal = document.getElementById('filterDay').value;
    const currentSlot = slotSel.value;
    while (slotSel.options.length > 1) slotSel.remove(1);

    let slots = (qurbaniSlotOptionsCache || []).filter(o => o.is_active);
    if (dayVal && qurbaniDayOptionsCache) {
        const dayObj = qurbaniDayOptionsCache.find(d => d.is_active && d.option_value === dayVal);
        if (dayObj) {
            const filtered = slots.filter(s => s.parent_id === dayObj.id);
            if (filtered.length > 0) slots = filtered;
        }
    }

    slots.forEach(o => {
        const opt = document.createElement('option');
        opt.value = o.option_value;
        opt.textContent = o.option_value;
        if (o.option_value === currentSlot) opt.selected = true;
        slotSel.appendChild(opt);
    });
}

function onDayFilterChange() {
    updateSlotDropdown();
    loadOrders();
}

let loadOrdersTimer = null;
function debouncedLoadOrders() {
    if (loadOrdersTimer) clearTimeout(loadOrdersTimer);
    loadOrdersTimer = setTimeout(() => loadOrders(), 400);
}

function loadOrders() {
    const params = new URLSearchParams();
    const day = document.getElementById('filterDay').value;
    const slot = document.getElementById('filterSlot').value;
    const region = document.getElementById('filterRegion').value;
    const dt = document.getElementById('filterDeliveryType').value;
    const status = document.getElementById('filterStatus').value;
    const customer = document.getElementById('filterCustomer').value.trim();
    if (day) params.set('day', day);
    if (slot) params.set('slot', slot);
    if (region) params.set('region', region);
    if (dt) params.set('delivery_type', dt);
    if (status) params.set('status', status);
    if (customer) params.set('customer', customer);

    document.getElementById('ordersBody').innerHTML = '<tr><td colspan="14" class="px-4 py-8 text-center text-gray-400">Loading...</td></tr>';

    fetch('/qurbani/api/orders?' + params.toString(), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        allOrders = data.orders || [];
        hasItemFilters = data.has_item_filters || false;
        activeCategoryFilter = null;
        renderOrders(allOrders);
    })
    .catch(err => {
        document.getElementById('ordersBody').innerHTML = '<tr><td colspan="14" class="px-4 py-8 text-center text-red-500">Error loading orders</td></tr>';
    });
}

function renderOrders(orders) {
    // Build category summary from ALL visible line items
    const catMap = {};
    let totalItemQty = 0;
    orders.forEach(o => {
        (o.line_items || []).forEach(li => {
            const cat = li.category_level_2 || '';
            const qty = parseInt(li.quantity) || 0;
            catMap[cat] = (catMap[cat] || 0) + qty;
            totalItemQty += qty;
        });
    });

    // Render category summary cards when a region is selected
    const sumEl = document.getElementById('categorySummary');
    const cats = Object.entries(catMap).sort((a, b) => b[1] - a[1]);
    if (cats.length > 0) {
        let cardsHtml = '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
        const allActive = !activeCategoryFilter;
        cardsHtml += `<button onclick="filterByCategory(null)" style="padding:6px 14px; border-radius:8px; border:1px solid ${allActive ? '#D97706' : '#e5e7eb'}; background:${allActive ? '#FEF3C7' : '#fff'}; cursor:pointer; min-width:60px; text-align:center;">
            <div style="font-size:18px; font-weight:700; color:${allActive ? '#B45309' : '#374151'};">${totalItemQty}</div>
            <div style="font-size:11px; font-weight:600; color:${allActive ? '#92400E' : '#6b7280'};">All</div>
        </button>`;
        cats.forEach(([cat, qty]) => {
            const active = activeCategoryFilter === cat;
            const label = cat || 'Uncategorized';
            cardsHtml += `<button onclick="filterByCategory('${cat.replace(/'/g, "\\'")}')" style="padding:6px 14px; border-radius:8px; border:1px solid ${active ? '#D97706' : '#e5e7eb'}; background:${active ? '#FEF3C7' : '#fff'}; cursor:pointer; min-width:60px; text-align:center;">
                <div style="font-size:18px; font-weight:700; color:${active ? '#B45309' : '#374151'};">${qty}</div>
                <div style="font-size:11px; font-weight:600; color:${active ? '#92400E' : '#6b7280'}; max-width:100px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${label}</div>
            </button>`;
        });
        cardsHtml += '</div>';
        sumEl.innerHTML = cardsHtml;
        sumEl.style.display = '';
    } else {
        sumEl.style.display = 'none';
    }

    // If category filter is active, filter orders to only those with matching items, and filter items
    let displayOrders = orders;
    if (activeCategoryFilter !== null) {
        displayOrders = orders.map(o => {
            const matchingItems = (o.line_items || []).filter(li => (li.category_level_2 || '') === activeCategoryFilter);
            if (matchingItems.length === 0) return null;
            return {...o, line_items: matchingItems, product_names: matchingItems.map(li => li.name).join(', '), total_qty: matchingItems.reduce((s, li) => s + (parseInt(li.quantity) || 0), 0), filtered: true, all_items_count: o.all_items_count || (o.line_items || []).length};
        }).filter(Boolean);
    }

    const filteredQty = displayOrders.reduce((sum, o) => sum + (parseInt(o.total_qty) || 0), 0);
    const totalAllQty = orders.reduce((sum, o) => sum + (parseInt(o.all_items_qty || o.total_qty) || 0), 0);
    let countLabel = displayOrders.length + ' order' + (displayOrders.length !== 1 ? 's' : '');
    if (hasItemFilters || activeCategoryFilter !== null) {
        countLabel += ' · ' + filteredQty + ' matching item' + (filteredQty !== 1 ? 's' : '') + ' (of ' + totalAllQty + ' total)';
    } else {
        countLabel += ' · ' + filteredQty + ' items';
    }
    document.getElementById('ordersCount').textContent = countLabel;
    if (displayOrders.length === 0) {
        document.getElementById('ordersBody').innerHTML = '<tr><td colspan="14" class="px-4 py-8 text-center text-gray-400">No orders found</td></tr>';
        return;
    }
    const html = displayOrders.map(o => {
        const statusClass = 'status-' + (o.order_status || 'open').replace(/_/g, '');
        const payClass = o.payment_status === 'paid' ? 'pay-paid' : (o.payment_status === 'partial' ? 'pay-partial' : 'pay-unpaid');
        const dateStr = o.order_date ? new Date(o.order_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) : '-';

        const items = o.line_items || [];
        const allCount = o.all_items_count || items.length;
        const isFiltered = o.filtered && items.length < allCount;

        // Product column: show each item on its own line for clarity
        let productHtml = items.map(li => {
            const catBadge = li.category_level_2 ? '<span style="background:#EDE9FE; color:#5B21B6; padding:1px 5px; border-radius:4px; font-size:10px; font-weight:600; margin-left:4px;">' + li.category_level_2 + '</span>' : '';
            return '<div style="line-height:1.5;">' + (parseInt(li.quantity) || 0) + 'x ' + (li.name || '-') + catBadge + '</div>';
        }).join('');
        if (items.length === 0) productHtml = o.product_names || '-';
        if (isFiltered) {
            productHtml += '<div class="text-xs" style="color:#d97706; margin-top:2px;">' + items.length + ' of ' + allCount + ' items shown</div>';
        }

        // Build per-item qurbani details for Day/Slot/Region/Type columns from visible items only
        function formatItemField(field) {
            if (items.length === 0) return o[field] || '-';
            const vals = items.map(i => i[field] || '-');
            const unique = [...new Set(vals)];
            if (unique.length === 1) return unique[0];
            return vals.map(v => '<div class="text-xs" style="line-height:1.4;">' + v + '</div>').join('');
        }
        const dayHtml = formatItemField('qurbani_day');
        const slotHtml = formatItemField('qurbani_slot');
        const regionHtml = formatItemField('qurbani_region');
        const dtypeHtml = formatItemField('qurbani_delivery_type');

        return `<tr class="order-row border-b border-gray-100 cursor-pointer" onclick="window.open('/orders?edit_order_id=${o.id}', '_blank')">
            <td class="px-3 py-2 text-gray-900 font-medium">${o.order_number || o.id}</td>
            <td class="px-3 py-2">
                <div class="text-gray-900">${(o.customer_name || '').trim() || '-'}</div>
                <div class="text-xs text-gray-400">${o.customer_phone || ''}</div>
            </td>
            <td class="px-3 py-2 text-xs text-gray-700">${productHtml}</td>
            <td class="px-3 py-2 text-center font-medium">${parseInt(o.total_qty) || '-'}</td>
            <td class="px-3 py-2">${dayHtml}</td>
            <td class="px-3 py-2">${slotHtml}</td>
            <td class="px-3 py-2">${regionHtml}</td>
            <td class="px-3 py-2">${dtypeHtml}</td>
            <td class="px-3 py-2"><span class="status-badge ${statusClass}">${(o.order_status || 'open').replace(/_/g,' ')}</span></td>
            <td class="px-3 py-2 text-right font-medium">PKR ${Number(o.total_price || 0).toLocaleString()}</td>
            <td class="px-3 py-2">
                <span class="status-badge ${payClass}">${o.payment_status || 'unpaid'}</span>
                ${o.total_paid > 0 ? '<div class="text-xs text-gray-500 mt-0.5">Paid: ' + Number(o.total_paid).toLocaleString() + '</div>' : ''}
            </td>
            <td class="px-3 py-2 text-gray-600" onclick="event.stopPropagation()">
                <select class="rider-select" data-order-id="${o.id}" style="padding:2px 4px; border:1px solid #d1d5db; border-radius:4px; font-size:11px; max-width:100px;" onchange="assignRiderFromTable(this)">
                    <option value="">-</option>
                    ${(window._qurbaniRiders || []).map(r => '<option value="' + r.id + '"' + (o.assigned_rider_user_id == r.id ? ' selected' : '') + '>' + r.fullname + '</option>').join('')}
                </select>
            </td>
            <td class="px-3 py-2 text-gray-500 text-xs">${dateStr}</td>
            <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                <div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">
                    <button onclick="openQurbaniPaymentModal(${o.id}, ${Number(o.balance_remaining || 0)})" style="padding:3px 8px; background:#D97706; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="Add Payment">💰 Pay</button>
                    <button onclick="window.open('/orders/${o.id}/invoice', '_blank')" style="padding:3px 8px; background:#4F46E5; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="View Invoice">📄 Invoice</button>
                </div>
            </td>
        </tr>`;
    }).join('');
    document.getElementById('ordersBody').innerHTML = html;
}

function filterByCategory(cat) {
    activeCategoryFilter = (activeCategoryFilter === cat) ? null : cat;
    renderOrders(allOrders);
}

function assignRiderFromTable(sel) {
    const orderId = sel.getAttribute('data-order-id');
    const riderId = sel.value;
    const riderUserId = riderId ? parseInt(riderId) : 0;
    if (riderUserId === 0 && !confirm('Are you sure you want to unassign the rider?')) {
        loadOrders();
        return;
    }
    sel.disabled = true;
    fetch(`/orders/${orderId}/rider/assign`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({ rider_user_id: riderUserId, confirmed: true }),
    })
    .then(r => r.json())
    .then(data => {
        sel.disabled = false;
        if (data.success) {
            sel.style.borderColor = '#10B981';
            setTimeout(() => { sel.style.borderColor = '#d1d5db'; loadOrders(); }, 1000);
        } else {
            alert(data.message || 'Failed');
        }
    })
    .catch(() => { sel.disabled = false; alert('Error assigning rider'); });
}

var _qurbaniPaymentOrderId = null;
function openQurbaniPaymentModal(orderId, balanceRemaining) {
    _qurbaniPaymentOrderId = orderId;
    var existing = document.getElementById('qurbaniPayOverlay');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'qurbaniPayOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center;';
    const defaultAmt = balanceRemaining > 0 ? balanceRemaining : '';
    overlay.innerHTML = `
        <div style="background:#fff; border-radius:12px; padding:24px; width:400px; max-width:90vw; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="margin:0; font-size:18px; font-weight:700;">Add Payment</h3>
                <button onclick="document.getElementById('qurbaniPayOverlay').remove()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280;">✕</button>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Amount *</label>
                <input type="number" id="qPayAmount" value="${defaultAmt}" placeholder="Enter amount" step="0.01" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                ${balanceRemaining > 0 ? '<div style="font-size:11px; color:#6b7280; margin-top:2px;">Remaining: PKR ' + Number(balanceRemaining).toLocaleString() + '</div>' : ''}
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Method</label>
                <select id="qPayMethod" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                    <option value="cash">Cash</option>
                    <option value="online">Online</option>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Reference (optional)</label>
                <input type="text" id="qPayRef" placeholder="Transaction reference" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Notes (optional)</label>
                <input type="text" id="qPayNotes" placeholder="Notes" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
            </div>
            <button id="qPaySubmitBtn" onclick="submitQurbaniPayment()" style="width:100%; padding:10px; background:#D97706; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;">Record Payment</button>
        </div>
    `;
    document.body.appendChild(overlay);
    document.getElementById('qPayAmount').focus();
}

function submitQurbaniPayment() {
    const amount = parseFloat(document.getElementById('qPayAmount').value);
    if (!amount || amount <= 0) { alert('Enter a valid amount'); return; }
    const btn = document.getElementById('qPaySubmitBtn');
    btn.disabled = true; btn.textContent = 'Saving...';

    fetch('/orders/' + _qurbaniPaymentOrderId + '/qurbani-payments', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({
            amount: amount,
            payment_method: document.getElementById('qPayMethod').value,
            payment_date: new Date().toISOString().split('T')[0],
            reference: document.getElementById('qPayRef').value || null,
            notes: document.getElementById('qPayNotes').value || null,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('qurbaniPayOverlay').remove();
            loadOrders();
        } else {
            alert(data.message || 'Failed');
            btn.disabled = false; btn.textContent = 'Record Payment';
        }
    })
    .catch(() => { alert('Error'); btn.disabled = false; btn.textContent = 'Record Payment'; });
}

// ======= DASHBOARD =======
function loadDashboard() {
    const year = document.getElementById('yearSelector').value;
    document.getElementById('yearTableLoading').style.display = '';
    document.getElementById('yearTableContainer').style.display = 'none';

    fetch('/qurbani/api/dashboard?year=' + year, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            renderYearlyChart(data.yearly_summary);
            populateYearSelector(data.yearly_summary, data.selected_year);
            renderYearTable(data.detailed_orders);
        }
    });
}

function renderYearlyChart(summary) {
    const ctx = document.getElementById('yearlyChart').getContext('2d');
    if (yearlyChart) yearlyChart.destroy();

    const labels = summary.map(s => s.year);
    const counts = summary.map(s => s.order_count);
    const qtys = summary.map(s => s.total_qty || 0);
    const revenues = summary.map(s => s.revenue);

    yearlyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Orders',
                    data: counts,
                    backgroundColor: 'rgba(217,119,6,0.7)',
                    borderColor: '#d97706',
                    borderWidth: 1,
                    yAxisID: 'y',
                },
                {
                    label: 'Total Qty',
                    data: qtys,
                    backgroundColor: 'rgba(59,130,246,0.5)',
                    borderColor: '#3b82f6',
                    borderWidth: 1,
                    yAxisID: 'y',
                },
                {
                    label: 'Revenue (PKR)',
                    data: revenues,
                    type: 'line',
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5,150,105,0.1)',
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { position: 'left', title: { display: true, text: 'Orders / Qty' }, beginAtZero: true },
                y1: { position: 'right', title: { display: true, text: 'Revenue (PKR)' }, beginAtZero: true, grid: { drawOnChartArea: false } }
            }
        }
    });
}

function populateYearSelector(summary, selected) {
    const sel = document.getElementById('yearSelector');
    const currentVal = sel.value;
    sel.innerHTML = '';
    const years = summary.map(s => s.year).sort((a,b) => b - a);
    if (years.length === 0) years.push(new Date().getFullYear());
    years.forEach(y => {
        const opt = document.createElement('option');
        opt.value = y;
        opt.textContent = y;
        if (y == selected) opt.selected = true;
        sel.appendChild(opt);
    });
}

function renderYearTable(orders) {
    document.getElementById('yearTableLoading').style.display = 'none';
    document.getElementById('yearTableContainer').style.display = '';

    if (!orders || orders.length === 0) {
        document.getElementById('yearTableBody').innerHTML = '<tr><td colspan="10" class="px-4 py-6 text-center text-gray-400">No orders found for this year</td></tr>';
        return;
    }

    const totalOrders = orders.length;
    const totalQty = orders.reduce((sum, o) => sum + (parseInt(o.total_qty) || 0), 0);

    const html = orders.map(o => {
        const dateStr = o.order_date ? new Date(o.order_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) : '-';
        const statusClass = 'status-' + (o.order_status || 'open').replace(/_/g, '');
        const itemsHtml = (o.line_items || []).map(li => `<div class="text-xs text-gray-600">${li.qty}x ${li.name}${li.instructions ? ' <span style="color:#9CA3AF;font-style:italic;">📝 ' + li.instructions + '</span>' : ''}</div>`).join('') || '-';
        return `<tr class="border-b border-gray-100 hover:bg-gray-50">
            <td class="px-3 py-2 font-medium text-gray-900">${o.order_number || o.id}</td>
            <td class="px-3 py-2">${(o.customer_name || '').trim() || '-'}</td>
            <td class="px-3 py-2" style="max-width:250px;">${itemsHtml}</td>
            <td class="px-3 py-2 text-center font-medium">${parseInt(o.total_qty) || '-'}</td>
            <td class="px-3 py-2 text-gray-500 text-xs">${dateStr}</td>
            <td class="px-3 py-2"><span class="status-badge ${statusClass}">${(o.order_status || '-').replace(/_/g,' ')}</span></td>
            <td class="px-3 py-2 text-right font-medium">PKR ${Number(o.total_price || 0).toLocaleString()}</td>
            <td class="px-3 py-2">${o.qurbani_day || '-'}</td>
            <td class="px-3 py-2">${o.qurbani_region || '-'}</td>
            <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs ${o.source === 'historical' ? 'bg-gray-100 text-gray-600' : 'bg-blue-50 text-blue-700'}">${o.source}</span></td>
        </tr>`;
    }).join('');
    document.getElementById('yearTableBody').innerHTML = html;

    // Update header with summary counts
    const header = document.querySelector('#dashboardContent h2');
    if (header) header.textContent = `Order Details (${totalOrders} orders, ${totalQty} items)`;
}

// ======= INLINE EDIT FOR TAIMUR =======
@if($isTaimurOrMgmt)
function openEditFieldOptions(fieldName) {
    const modal = document.getElementById('editFieldOptionsModal');
    const title = document.getElementById('editFieldTitle');
    const body = document.getElementById('editFieldBody');
    const labels = { qurbani_day: 'Qurbani Day', qurbani_slot: 'Qurbani Slot', qurbani_region: 'Qurbani Region', qurbani_delivery_type: 'Delivery Type' };
    title.textContent = 'Edit: ' + (labels[fieldName] || fieldName);

    fetch('/qurbani-settings/api/options?field=' + fieldName, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        const optionsObj = data.options || {};
        const opts = optionsObj[fieldName] || [];
        let html = '<div id="fieldOptionsList">';
        opts.forEach(o => {
            html += `<div class="flex items-center gap-2 mb-2" data-id="${o.id}">
                <input type="text" value="${o.option_value}" class="flex-1 px-3 py-2 border border-gray-300 rounded-md text-sm" data-original="${o.option_value}">
                <button onclick="saveFieldOption(${o.id}, this)" class="px-3 py-2 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">Save</button>
                <button onclick="deleteFieldOption(${o.id}, this)" class="px-3 py-2 bg-red-100 text-red-700 text-xs rounded hover:bg-red-200">Del</button>
            </div>`;
        });
        html += '</div>';
        html += `<div class="flex items-center gap-2 mt-4 pt-4 border-t border-gray-200">
            <input type="text" id="newOptionValue" placeholder="New option value..." class="flex-1 px-3 py-2 border border-gray-300 rounded-md text-sm">
            <button onclick="addFieldOption('${fieldName}')" class="px-4 py-2 bg-green-600 text-white text-xs rounded hover:bg-green-700 font-medium">Add</button>
        </div>`;
        body.innerHTML = html;
        modal.style.display = '';
    });
}

function closeEditFieldModal() {
    document.getElementById('editFieldOptionsModal').style.display = 'none';
}

function saveFieldOption(id, btn) {
    const input = btn.parentElement.querySelector('input');
    const val = input.value.trim();
    if (!val) return;
    fetch('/qurbani-settings/api/options/' + id, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({ option_value: val })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            input.setAttribute('data-original', val);
            input.style.borderColor = '#10b981';
            setTimeout(() => input.style.borderColor = '#d1d5db', 1500);
        } else {
            alert(d.message || 'Error');
        }
    });
}

function deleteFieldOption(id, btn) {
    if (!confirm('Deactivate this option?')) return;
    fetch('/qurbani-settings/api/options/' + id, {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) btn.closest('[data-id]').remove();
    });
}

function addFieldOption(fieldName) {
    const input = document.getElementById('newOptionValue');
    const val = input.value.trim();
    if (!val) return;
    fetch('/qurbani-settings/api/options', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({ field_name: fieldName, option_value: val })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeEditFieldModal();
            openEditFieldOptions(fieldName);
        } else {
            alert(d.message || 'Error');
        }
    });
}
@endif

// ======= INIT =======
document.addEventListener('DOMContentLoaded', () => {
    loadSlotOptions();
    loadOrders();

    // Add edit icons to filter labels for Taimur role
    if (isTaimurOrMgmt) {
        ['filterDay', 'filterSlot', 'filterRegion', 'filterDeliveryType'].forEach(id => {
            const fieldMap = { filterDay: 'qurbani_day', filterSlot: 'qurbani_slot', filterRegion: 'qurbani_region', filterDeliveryType: 'qurbani_delivery_type' };
            const label = document.getElementById(id)?.previousElementSibling;
            if (label) {
                const editBtn = document.createElement('button');
                editBtn.innerHTML = ' <i class="ki-filled ki-pencil" style="font-size:10px;"></i>';
                editBtn.style.cssText = 'background:none;border:none;cursor:pointer;color:#d97706;padding:0;margin-left:4px;';
                editBtn.title = 'Edit options';
                editBtn.onclick = (e) => { e.stopPropagation(); openEditFieldOptions(fieldMap[id]); };
                label.appendChild(editBtn);
            }
        });
    }
});
</script>
@endsection
