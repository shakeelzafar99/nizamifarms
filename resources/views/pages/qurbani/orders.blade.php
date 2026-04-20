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
.filter-select.filter-dependent { border-left: 3px solid #FBBF24; background: #FFFBEB; }
.filter-select.filter-dependent:focus { background: #FFF; }
.chart-container { position: relative; height: 300px; }

/* ===== Qurbani Stats Panel ===== */
.qstats-wrap { margin-bottom: 16px; }
.qstats-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.qstats-title { font-size:13px; font-weight:700; color:#374151; display:flex; align-items:center; gap:8px; }
.qstats-title .qstats-badge { background:#fef3c7; color:#92400e; font-size:10px; font-weight:600; padding:2px 6px; border-radius:4px; letter-spacing:.3px; text-transform:uppercase; }
.qstats-head-btns { display:flex; gap:6px; }
.qstats-iconbtn { background:#fff; border:1px solid #e5e7eb; color:#6b7280; padding:4px 8px; border-radius:6px; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:4px; }
.qstats-iconbtn:hover { background:#f9fafb; color:#111827; }
.qstats-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.qstats-cards.single { grid-template-columns:1fr; }
.qstats-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
.qstats-card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.qstats-card-title { font-size:13px; font-weight:700; color:#111827; display:flex; align-items:center; gap:6px; }
.qstats-card-title .dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.qstats-card-total { font-size:12px; color:#6b7280; }
.qstats-card-total b { color:#d97706; font-weight:700; font-size:13px; }
.qstats-card-actions { display:flex; gap:6px; }
.qstats-ghostbtn { font-size:11px; padding:4px 10px; border-radius:6px; border:1px solid #d97706; background:#fff; color:#d97706; cursor:pointer; font-weight:600; }
.qstats-ghostbtn:hover { background:#fef3c7; }
.qstats-ghostbtn.secondary { border-color:#e5e7eb; color:#6b7280; }
.qstats-ghostbtn.secondary:hover { background:#f9fafb; color:#111827; }
.qstats-table { width:100%; border-collapse:collapse; font-size:12px; }
.qstats-table th, .qstats-table td { padding:6px 8px; border:1px solid #f0f1f3; text-align:center; color:#111827; }
.qstats-table thead th { background:#f9fafb; color:#6b7280; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.3px; }
.qstats-table .row-label { background:#fafafa; font-weight:600; color:#374151; text-align:left; white-space:nowrap; }
.qstats-table .zero { color:#d1d5db; }
.qstats-table .total-row td { background:#fffbeb; font-weight:700; color:#92400e; }
.qstats-table .total-col { background:#fffbeb; font-weight:700; color:#92400e; }
.qstats-empty { text-align:center; padding:20px; color:#9ca3af; font-size:12px; font-style:italic; }

.qstats-mini-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:10px; margin-top:10px; }
.qstats-mini { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:8px 10px; }
.qstats-mini-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; padding-bottom:6px; border-bottom:1px dashed #e5e7eb; }
.qstats-mini-title { font-size:12px; font-weight:700; color:#111827; }
.qstats-mini-total { font-size:11px; color:#d97706; font-weight:700; background:#fef3c7; padding:2px 6px; border-radius:4px; }
.qstats-mini table { width:100%; border-collapse:collapse; font-size:11px; }
.qstats-mini th, .qstats-mini td { padding:4px 6px; border:1px solid #f0f1f3; text-align:center; }
.qstats-mini th { background:#f9fafb; color:#6b7280; font-weight:600; font-size:10px; text-transform:uppercase; }
.qstats-mini .row-label { background:#fafafa; text-align:left; font-weight:600; color:#374151; }
.qstats-mini .zero { color:#d1d5db; }

@media (max-width: 768px) {
    .qstats-cards { grid-template-columns:1fr; }
}
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
        <!-- Stats Panel (summary + expandable breakdown) -->
        <div id="qStatsWrap" class="qstats-wrap">
            <div class="qstats-head">
                <div class="qstats-title">
                    <span>Qurbani Booked Summary</span>
                    <span class="qstats-badge">All orders</span>
                </div>
                <div class="qstats-head-btns">
                    <button type="button" class="qstats-iconbtn" onclick="loadQurbaniStats()" title="Refresh stats"><i class="ki-filled ki-arrows-circle"></i> Refresh</button>
                    <button type="button" id="qStatsToggle" class="qstats-iconbtn" onclick="toggleQurbaniStats()" title="Hide stats"><i class="ki-filled ki-up"></i> Hide</button>
                </div>
            </div>
            <div id="qStatsBody">
                <div id="qStatsLoading" class="qstats-empty" style="background:#fff; border:1px solid #e5e7eb; border-radius:10px;">Loading stats&hellip;</div>
                <div id="qStatsSummary" class="qstats-cards" style="display:none;"></div>
                <div id="qStatsBreakdown" style="display:none;"></div>
            </div>
        </div>

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
                    <label class="text-xs font-medium text-gray-500 block mb-1">
                        Delivery Type
                    </label>
                    <select id="filterDeliveryType" class="filter-select" onchange="onDeliveryTypeFilterChange()">
                        <option value="">All Types</option>
                        @foreach($deliveryTypes as $dt)
                        <option value="{{ $dt }}">{{ $dt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1" title="Slot options change based on the selected Day &amp; Delivery Type">
                        Slot <span class="text-[10px] font-normal text-gray-400">(depends on Day/Type)</span>
                    </label>
                    <select id="filterSlot" class="filter-select filter-dependent" onchange="loadOrders()">
                        <option value="">All Slots</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Region</label>
                    <select id="filterRegion" class="filter-select" onchange="onRegionFilterChange()">
                        <option value="">All Regions</option>
                        @foreach($regions as $r)
                        <option value="{{ $r }}">{{ $r }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1" title="Sub Regions change based on the selected Region">
                        Sub Region <span class="text-[10px] font-normal text-gray-400">(depends on Region)</span>
                    </label>
                    <select id="filterSubRegion" class="filter-select filter-dependent" onchange="loadOrders()">
                        <option value="">All Sub Regions</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Category</label>
                    <select id="filterCategory" class="filter-select" onchange="onCategoryFilterChange()">
                        <option value="">All Categories</option>
                        @foreach(($categories ?? []) as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Payment</label>
                    <select id="filterPaymentStatus" class="filter-select" onchange="loadOrders()">
                        <option value="">All Payments</option>
                        <option value="paid">Paid</option>
                        <option value="partial">Partial</option>
                        <option value="unpaid">Unpaid</option>
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
const canDeleteOrders = {{ ($isTaimur ?? false) && ($deleteEnabled ?? false) ? 'true' : 'false' }};
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
    document.getElementById('filterSubRegion').value = '';
    document.getElementById('filterDeliveryType').value = '';
    const payEl = document.getElementById('filterPaymentStatus');
    if (payEl) payEl.value = '';
    const catEl = document.getElementById('filterCategory');
    if (catEl) catEl.value = '';
    document.getElementById('filterCustomer').value = '';
    currentRegion = '';
    activeCategoryFilter = null;
    document.querySelectorAll('.region-tab').forEach((b, i) => {
        b.className = i === 0
            ? 'region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 border border-amber-300'
            : 'region-tab px-3 py-1.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600 border border-gray-200 hover:bg-gray-200';
    });
    // Re-render dependent dropdowns (they show full option sets once parents are cleared).
    if (typeof updateSlotDropdown === 'function') updateSlotDropdown();
    if (typeof updateSubRegionDropdown === 'function') updateSubRegionDropdown();
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
    const dtVal = document.getElementById('filterDeliveryType').value;
    const currentSlot = slotSel.value;
    while (slotSel.options.length > 1) slotSel.remove(1);

    let slots = (qurbaniSlotOptionsCache || []).filter(o => o.is_active);

    let dayObj = null, dtObj = null;
    if (dayVal && qurbaniDayOptionsCache) {
        dayObj = qurbaniDayOptionsCache.find(d => d.is_active && d.option_value === dayVal);
    }
    if (dtVal) {
        const dtOpts = (fieldOptions['qurbani_delivery_type'] || []);
        dtObj = dtOpts.find(d => d.is_active && d.option_value === dtVal);
    }

    if (dayObj && dtObj) {
        const filtered = slots.filter(s => s.parent_id === dayObj.id && s.delivery_type_parent_id === dtObj.id);
        if (filtered.length > 0) slots = filtered;
        else if (dayObj) {
            const dayOnly = slots.filter(s => s.parent_id === dayObj.id);
            if (dayOnly.length > 0) slots = dayOnly;
        }
    } else if (dayObj) {
        const filtered = slots.filter(s => s.parent_id === dayObj.id);
        if (filtered.length > 0) slots = filtered;
    }

    // Dedupe by option_value so slots that exist for multiple Day/DT combos don't appear twice.
    const seenSlot = new Set();
    slots.forEach(o => {
        if (seenSlot.has(o.option_value)) return;
        seenSlot.add(o.option_value);
        const opt = document.createElement('option');
        opt.value = o.option_value;
        opt.textContent = o.option_value;
        if (o.option_value === currentSlot) opt.selected = true;
        slotSel.appendChild(opt);
    });
    // If the previously-selected slot is no longer a valid option, drop it so backend
    // filtering isn't done against a stale invisible value.
    if (currentSlot && !seenSlot.has(currentSlot)) slotSel.value = '';
}

function updateSubRegionDropdown() {
    const subRegionSel = document.getElementById('filterSubRegion');
    const regionVal = document.getElementById('filterRegion').value;
    const currentSub = subRegionSel.value;
    while (subRegionSel.options.length > 1) subRegionSel.remove(1);

    let subRegions = (fieldOptions['qurbani_sub_region'] || []).filter(o => o.is_active);
    if (regionVal) {
        const regionOpts = fieldOptions['qurbani_region'] || [];
        const regionObj = regionOpts.find(r => r.is_active && r.option_value === regionVal);
        if (regionObj) {
            const filtered = subRegions.filter(s => s.parent_id === regionObj.id);
            if (filtered.length > 0) subRegions = filtered;
        }
    }

    const seenSR = new Set();
    subRegions.forEach(o => {
        if (seenSR.has(o.option_value)) return;
        seenSR.add(o.option_value);
        const opt = document.createElement('option');
        opt.value = o.option_value;
        opt.textContent = o.option_value;
        if (o.option_value === currentSub) opt.selected = true;
        subRegionSel.appendChild(opt);
    });
    if (currentSub && !seenSR.has(currentSub)) subRegionSel.value = '';
}

function onDayFilterChange() {
    updateSlotDropdown();
    loadOrders();
}

function onDeliveryTypeFilterChange() {
    updateSlotDropdown();
    loadOrders();
}

function onRegionFilterChange() {
    updateSubRegionDropdown();
    loadOrders();
}

function onCategoryFilterChange() {
    const val = document.getElementById('filterCategory').value;
    // Keep client-side category cards in sync with the dropdown so both UIs reflect the same state.
    activeCategoryFilter = val || null;
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
    const subRegion = document.getElementById('filterSubRegion').value;
    const dt = document.getElementById('filterDeliveryType').value;
    const paymentStatus = (document.getElementById('filterPaymentStatus') || {}).value || '';
    const category = (document.getElementById('filterCategory') || {}).value || '';
    const customer = document.getElementById('filterCustomer').value.trim();
    if (day) params.set('day', day);
    if (slot) params.set('slot', slot);
    if (region) params.set('region', region);
    if (subRegion) params.set('sub_region', subRegion);
    if (dt) params.set('delivery_type', dt);
    if (paymentStatus) params.set('payment_status', paymentStatus);
    if (category) params.set('category', category);
    if (customer) params.set('customer', customer);

    document.getElementById('ordersBody').innerHTML = '<tr><td colspan="14" class="px-4 py-8 text-center text-gray-400">Loading...</td></tr>';

    fetch('/qurbani/api/orders?' + params.toString(), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        allOrders = data.orders || [];
        hasItemFilters = data.has_item_filters || false;
        // Respect the Category dropdown selection across reloads (user expectation: if they
        // picked a category, it stays selected). Card clicks also update the dropdown, so
        // reading from the dropdown is now the single source of truth for client-side filtering.
        const catSel = document.getElementById('filterCategory');
        activeCategoryFilter = (catSel && catSel.value) ? catSel.value : null;
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
        const regionHtml = (() => {
            if (items.length === 0) {
                let r = o.qurbani_region || '-';
                if (o.qurbani_sub_region) r += '<div class="text-xs text-gray-400">' + o.qurbani_sub_region + '</div>';
                return r;
            }
            return items.map(i => {
                let r = i.qurbani_region || '-';
                if (i.qurbani_sub_region) r += ' <span style="color:#6b7280; font-size:10px;">(' + i.qurbani_sub_region + ')</span>';
                return '<div class="text-xs" style="line-height:1.4;">' + r + '</div>';
            }).join('');
        })();
        const dtypeHtml = formatItemField('qurbani_delivery_type');
        const hasOrderNote = o.note && o.note.trim();
        const hasAnyNote = hasOrderNote || items.some(i => i.instructions && i.instructions.trim());

        return `<tr class="order-row border-b border-gray-100 cursor-pointer${hasAnyNote ? ' bg-yellow-50' : ''}" onclick="window.open('/orders?edit_order_id=${o.id}', '_blank')">
            <td class="px-3 py-2 text-gray-900 font-medium">
                ${o.order_number || o.id}
                ${hasOrderNote ? '<div style="font-size:10px; color:#92400e; margin-top:2px;" title="' + o.note.replace(/"/g, '&quot;') + '">📝 Note</div>' : ''}
            </td>
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
                    ${(o.payment_status === 'paid') ? `<button onclick="openStampEditorModal(${o.id})" style="padding:3px 8px; background:#7C2D12; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="Edit invoice PAID stamp (display only)">📜 Stamp</button>` : ''}
                    <button onclick="window.open('/orders/${o.id}/invoice', '_blank')" style="padding:3px 8px; background:#4F46E5; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="View Invoice">📄 Invoice</button>
                    <button onclick="openWhatsAppInvoiceModal(${o.id}, '${(o.customer_name || '').replace(/'/g, "\\'")}', '${o.order_number || ''}', '${(o.customer_phone || '').replace(/'/g, "\\'")}')" style="padding:3px 8px; background:#25D366; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="Send WhatsApp Invoice">📱 WA</button>
                    ${canDeleteOrders ? `<button onclick="deleteQurbaniOrder(${o.id}, '${(o.order_number || '').replace(/'/g, "\\'")}')" style="padding:3px 8px; background:#DC2626; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="Delete Order">🗑️ Del</button>` : ''}
                </div>
            </td>
        </tr>`;
    }).join('');
    document.getElementById('ordersBody').innerHTML = html;
}

function filterByCategory(cat) {
    activeCategoryFilter = (activeCategoryFilter === cat) ? null : cat;
    // Keep the Category dropdown and the server-side fetch in sync with the quick-filter
    // summary cards: if the category exists as a dropdown option we reload via the
    // server so untoggling reveals the full set again; otherwise we fall back to a purely
    // client-side re-render for ad-hoc categories that aren't in the dropdown.
    const catSel = document.getElementById('filterCategory');
    const target = activeCategoryFilter == null ? '' : activeCategoryFilter;
    const optionExists = !!catSel && (target === '' || Array.from(catSel.options).some(o => o.value === target));
    if (catSel && optionExists && catSel.value !== target) {
        catSel.value = target;
        loadOrders();
        return;
    }
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

// Receiving-bank list (HBL, MBL, EP, JC, …) rendered as chips inside the
// Add Payment modal when payment_method = online. Server-side seeded from
// t_fin_online_receiving_accounts so the list stays in sync with what
// Online Approvals uses.
const QURBANI_RECEIVING_ACCOUNTS = @json($receivingAccounts ?? []);

var _qurbaniPaymentOrderId = null;
var _qurbaniPaymentReceivingId = null; // currently-selected receiving bank id
function openQurbaniPaymentModal(orderId, balanceRemaining) {
    _qurbaniPaymentOrderId = orderId;
    _qurbaniPaymentReceivingId = null;
    var existing = document.getElementById('qurbaniPayOverlay');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'qurbaniPayOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px; overflow-y:auto;';
    const defaultAmt = balanceRemaining > 0 ? balanceRemaining : '';
    const todayIso = new Date().toISOString().split('T')[0];

    // Build the bank-chip strip from the server-rendered list.
    let bankChipsHtml = '<button type="button" class="qpay-bank-chip qpay-bank-chip-active" data-bank-id="" onclick="selectQurbaniBankChip(this, null)" '
        + 'style="padding:5px 12px; border-radius:16px; border:1px solid #CBD5E1; background:#3B82F6; color:#fff; font-size:12px; font-weight:600; cursor:pointer;">None</button>';
    (QURBANI_RECEIVING_ACCOUNTS || []).forEach(function (acc) {
        bankChipsHtml += '<button type="button" class="qpay-bank-chip" data-bank-id="' + acc.id + '" onclick="selectQurbaniBankChip(this, ' + acc.id + ')" '
            + 'style="padding:5px 12px; border-radius:16px; border:1px solid #CBD5E1; background:#fff; color:#334155; font-size:12px; font-weight:600; cursor:pointer;">'
            + (acc.short_code || acc.name) + '</button>';
    });

    overlay.innerHTML = `
        <div style="background:#fff; border-radius:12px; padding:24px; width:440px; max-width:92vw; max-height:92vh; overflow-y:auto; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h3 style="margin:0; font-size:18px; font-weight:700;">Add Payment</h3>
                <button onclick="document.getElementById('qurbaniPayOverlay').remove()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280;">✕</button>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Amount *</label>
                <input type="number" id="qPayAmount" value="${defaultAmt}" placeholder="Enter amount" step="0.01" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                ${balanceRemaining > 0 ? '<div style="font-size:11px; color:#6b7280; margin-top:2px;">Remaining: PKR ' + Number(balanceRemaining).toLocaleString() + '</div>' : ''}
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Method</label>
                    <select id="qPayMethod" onchange="onQurbaniMethodChange()" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                        <option value="cash">Cash</option>
                        <option value="online">Online</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Payment Date *</label>
                    {{-- Defaults to today but can be backdated. The server uses this for
                         the payment + ledger row; it also seeds the PAID-stamp date
                         when the user doesn't explicitly override the stamp date below. --}}
                    <input type="date" id="qPayPaymentDate" value="${todayIso}" max="${todayIso}" style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                </div>
            </div>

            <div id="qPayBankRow" style="display:none; margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; color:#0369A1; margin-bottom:6px;">🏦 Received in Bank (optional)</label>
                <div id="qPayBankChips" style="display:flex; flex-wrap:wrap; gap:6px;">${bankChipsHtml}</div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Transaction Reference (optional)</label>
                <input type="text" id="qPayRef" placeholder="e.g. TX-9871231" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Notes (optional)</label>
                <input type="text" id="qPayNotes" placeholder="Notes" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
            </div>

            <!-- PAID-stamp metadata — purely display. Finance (amount, method,
                 bank, reference) stays untouched if the user ignores this. -->
            <div style="border-top:1px dashed #e5e7eb; padding-top:12px; margin-bottom:12px;">
                <div style="font-size:12px; font-weight:700; color:#7C2D12; margin-bottom:8px; letter-spacing:.3px;">📜 INVOICE PAID STAMP (OPTIONAL)</div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#374151;">Customer's Sending Bank</label>
                        <input type="text" id="qPayStampBank" placeholder="e.g. HBL" style="width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#374151;">Stamp Date</label>
                        <input type="date" id="qPayStampDate" value="${todayIso}" style="width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                    </div>
                </div>

                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#374151;">Show on Stamp</label>
                <select id="qPayStampRefMode" style="width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;">
                    <option value="reference">Reference (default)</option>
                    <option value="customer_name">Customer name</option>
                    <option value="blank">Blank (hide this line)</option>
                </select>
            </div>

            <button id="qPaySubmitBtn" onclick="submitQurbaniPayment()" style="width:100%; padding:10px; background:#D97706; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;">Record Payment</button>
        </div>
    `;
    document.body.appendChild(overlay);
    document.getElementById('qPayAmount').focus();

    // When the user changes the payment date, propagate it to the stamp
    // date UNLESS they already customised the stamp date. We track
    // "customised" with a data flag set on user input.
    const payDateEl = document.getElementById('qPayPaymentDate');
    const stampDateEl = document.getElementById('qPayStampDate');
    if (payDateEl && stampDateEl) {
        stampDateEl.addEventListener('input', function () {
            stampDateEl.dataset.userEdited = '1';
        });
        payDateEl.addEventListener('change', function () {
            if (stampDateEl.dataset.userEdited !== '1') {
                stampDateEl.value = payDateEl.value;
            }
        });
    }

    // Pre-fill the stamp fields from whatever was saved on the order last
    // time (so a second payment doesn't require re-typing the bank).
    prefillQurbaniStampFields(orderId);
}

function onQurbaniMethodChange() {
    const method = document.getElementById('qPayMethod').value;
    const row = document.getElementById('qPayBankRow');
    if (row) row.style.display = (method === 'online') ? 'block' : 'none';
    if (method !== 'online') {
        // Clear the selection so we don't send a stale id on a cash payment.
        _qurbaniPaymentReceivingId = null;
        document.querySelectorAll('.qpay-bank-chip').forEach(function (c) {
            const isNone = c.getAttribute('data-bank-id') === '';
            c.classList.toggle('qpay-bank-chip-active', isNone);
            c.style.background = isNone ? '#3B82F6' : '#fff';
            c.style.color = isNone ? '#fff' : '#334155';
        });
    }
}

function selectQurbaniBankChip(el, bankId) {
    _qurbaniPaymentReceivingId = bankId || null;
    document.querySelectorAll('.qpay-bank-chip').forEach(function (c) {
        c.classList.remove('qpay-bank-chip-active');
        c.style.background = '#fff';
        c.style.color = '#334155';
    });
    el.classList.add('qpay-bank-chip-active');
    el.style.background = '#3B82F6';
    el.style.color = '#fff';
}

// Standalone stamp editor — lets the team adjust what the invoice PAID
// stamp shows (sending bank / date / third-line ref) without touching any
// payment row. Hits the same POST /orders/{id}/paid-stamp endpoint the
// invoice view uses. Payment history (amounts, methods, ledger) is
// completely untouched.
function openStampEditorModal(orderId) {
    var existing = document.getElementById('stampEditorOverlay');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'stampEditorOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10001; display:flex; align-items:center; justify-content:center; padding:20px; overflow-y:auto;';
    overlay.innerHTML = `
        <div style="background:#fff; border-radius:12px; padding:22px; width:420px; max-width:92vw; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 style="margin:0; font-size:17px; font-weight:700; color:#7C2D12;">📜 Edit Invoice Stamp</h3>
                <button onclick="document.getElementById('stampEditorOverlay').remove()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280;">✕</button>
            </div>
            <div style="font-size:11px; color:#6b7280; margin-bottom:12px;">Display only — payment records stay untouched.</div>

            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Customer's Sending Bank</label>
            <input type="text" id="stampEditBank" placeholder="e.g. HBL (leave blank for CASH)" style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; margin-bottom:12px;">

            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Stamp Date</label>
            <input type="date" id="stampEditDate" style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; margin-bottom:12px;">

            <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Show on Stamp</label>
            <select id="stampEditRefMode" style="width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; margin-bottom:16px;">
                <option value="reference">Reference (default)</option>
                <option value="customer_name">Customer name</option>
                <option value="blank">Blank (hide this line)</option>
            </select>

            <button id="stampEditSaveBtn" onclick="submitStampEdit(${orderId})" style="width:100%; padding:10px; background:#7C2D12; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;">Save</button>
        </div>
    `;
    document.body.appendChild(overlay);

    // Pre-fill from saved values (if any) using the existing qurbani
    // payments endpoint which already returns the paid_stamp block.
    fetch('/orders/' + orderId + '/qurbani-payments', {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data || !data.success) return;
        const s = data.paid_stamp || {};
        if (s.sending_bank) document.getElementById('stampEditBank').value = s.sending_bank;
        if (s.date)         document.getElementById('stampEditDate').value = s.date;
        document.getElementById('stampEditRefMode').value = s.ref_mode || 'reference';
    })
    .catch(function () { /* non-fatal; blank form is still valid */ });
}

function submitStampEdit(orderId) {
    const btn = document.getElementById('stampEditSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
    const payload = {
        sending_bank:   (document.getElementById('stampEditBank').value  || '').trim(),
        stamp_date:     document.getElementById('stampEditDate').value    || '',
        stamp_ref_mode: document.getElementById('stampEditRefMode').value || 'reference',
    };
    fetch('/orders/' + orderId + '/paid-stamp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify(payload),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            document.getElementById('stampEditorOverlay').remove();
        } else {
            alert((typeof data.message === 'string') ? data.message : 'Failed to save');
            btn.disabled = false; btn.textContent = 'Save';
        }
    })
    .catch(function () { alert('Error'); btn.disabled = false; btn.textContent = 'Save'; });
}

function prefillQurbaniStampFields(orderId) {
    fetch('/orders/' + orderId + '/qurbani-payments', {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data || !data.success || !data.paid_stamp) return;
        const s = data.paid_stamp;
        const bankInput = document.getElementById('qPayStampBank');
        const dateInput = document.getElementById('qPayStampDate');
        const refModeSel = document.getElementById('qPayStampRefMode');
        if (bankInput && s.sending_bank) bankInput.value = s.sending_bank;
        if (dateInput && s.date)         dateInput.value = s.date;
        if (refModeSel && s.ref_mode)    refModeSel.value = s.ref_mode;
    })
    .catch(function () { /* non-fatal — defaults apply */ });
}

function submitQurbaniPayment() {
    const amount = parseFloat(document.getElementById('qPayAmount').value);
    if (!amount || amount <= 0) { alert('Enter a valid amount'); return; }
    const btn = document.getElementById('qPaySubmitBtn');
    btn.disabled = true; btn.textContent = 'Saving...';

    const method = document.getElementById('qPayMethod').value;
    const paymentDateEl = document.getElementById('qPayPaymentDate');
    const paymentDate = (paymentDateEl && paymentDateEl.value)
        ? paymentDateEl.value
        : new Date().toISOString().split('T')[0];
    const payload = {
        amount: amount,
        payment_method: method,
        payment_date: paymentDate,
        reference: document.getElementById('qPayRef').value || null,
        notes: document.getElementById('qPayNotes').value || null,
        // New: receiving bank (only for online) + stamp overrides. Keys are
        // omitted when empty so the server "don't wipe what's already set"
        // logic works (see addQurbaniPayment).
    };
    if (method === 'online' && _qurbaniPaymentReceivingId) {
        payload.receiving_account_id = _qurbaniPaymentReceivingId;
    }
    const stampBank = (document.getElementById('qPayStampBank').value || '').trim();
    if (stampBank) payload.sending_bank = stampBank;
    const stampDate = document.getElementById('qPayStampDate').value;
    if (stampDate) payload.stamp_date = stampDate;
    const refMode = document.getElementById('qPayStampRefMode').value;
    if (refMode) payload.stamp_ref_mode = refMode;

    fetch('/orders/' + _qurbaniPaymentOrderId + '/qurbani-payments', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify(payload),
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

// ======= WHATSAPP INVOICE =======
var qurWaCurrentOrderId = null;
var qurWaCurrentPhone = '';

function openFullscreenImg(src) {
    if (!src) return;
    var overlay = document.getElementById('waImgOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'waImgOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
    overlay.innerHTML = '<img src="' + src + '" style="max-width:92vw;max-height:92vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.5);" /><button style="position:absolute;top:16px;right:24px;background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:28px;cursor:pointer;border-radius:50%;width:44px;height:44px;">&times;</button>';
    overlay.addEventListener('click', function() { overlay.remove(); });
    document.body.appendChild(overlay);
}

function captureQurbaniInvoiceImage(invoiceUrl, orderId) {
    return new Promise(function(resolve, reject) {
        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:900px;height:1400px;border:none;opacity:0;';
        document.body.appendChild(iframe);
        iframe.src = invoiceUrl;
        iframe.onload = function() {
            try {
                var iDoc = iframe.contentDocument || iframe.contentWindow.document;
                var script = iDoc.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
                script.onload = function() {
                    var node = iDoc.querySelector('.invoice-container');
                    if (!node) { iframe.remove(); reject(new Error('Invoice container not found')); return; }
                    iframe.contentWindow.html2canvas(node, {scale: 2, useCORS: true, allowTaint: true}).then(function(canvas) {
                        var dataUrl = canvas.toDataURL('image/png');
                        iframe.remove();
                        fetch('/messages/upload-invoice-image', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'},
                            body: JSON.stringify({ order_id: orderId, image_data: dataUrl })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(uploadRes) {
                            if (uploadRes.success) resolve(uploadRes);
                            else reject(new Error(uploadRes.message || 'Upload failed'));
                        })
                        .catch(reject);
                    }).catch(function(err) { iframe.remove(); reject(err); });
                };
                iDoc.head.appendChild(script);
            } catch (err) { iframe.remove(); reject(err); }
        };
        iframe.onerror = function() { iframe.remove(); reject(new Error('Failed to load invoice')); };
    });
}

// ---- WhatsApp modal (Qurbani) ----
// Tabs: "Invoice" (default) and "Other Message".
// - Invoice tab retains existing send-invoice flow + auto-preview on open.
// - Other Message tab lets the user pick any non-invoice template allowed on
//   this page (uses the shared template filter: qurbani-only + context orders).
let qurWaCustomerName = '';
let qurWaOrderNumber = '';

function openWhatsAppInvoiceModal(orderId, customerName, orderNumber, customerPhone) {
    var existing = document.getElementById('waInvoiceOverlay');
    if (existing) existing.remove();

    qurWaCurrentOrderId = orderId;
    qurWaCurrentPhone = customerPhone;
    qurWaCustomerName = customerName || '';
    qurWaOrderNumber = orderNumber || '';

    var overlay = document.createElement('div');
    overlay.id = 'waInvoiceOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center;';
    overlay.innerHTML = `
        <div style="background:#fff; border-radius:12px; width:520px; max-width:95vw; max-height:90vh; overflow:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-size:20px;">📱</span>
                    <span style="font-weight:700; font-size:15px; color:#111827;">Send WhatsApp Message</span>
                </div>
                <button onclick="document.getElementById('waInvoiceOverlay').remove()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280;">&times;</button>
            </div>
            <div style="padding:16px 20px 6px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div>
                        <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Customer</div>
                        <div style="font-size:14px; font-weight:600; color:#111827;">${qstatsEscape(customerName || 'Unknown')}</div>
                    </div>
                    <div>
                        <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">Order</div>
                        <div style="font-size:14px; font-weight:600; color:#111827;">#${qstatsEscape(orderNumber)}</div>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:4px;">Phone Number</label>
                    <input id="waInvPhone" type="text" value="${qstatsEscape(customerPhone || '')}" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;" placeholder="923001234567" />
                </div>

                <!-- Tab bar -->
                <div style="display:inline-flex; background:#f3f4f6; border-radius:8px; padding:3px; margin-bottom:14px;">
                    <button id="qurWaTabInvoice" type="button" onclick="qurWaSwitchTab('invoice')" style="padding:6px 14px; border:none; background:#fff; color:#111827; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.08);">🧾 Invoice</button>
                    <button id="qurWaTabOther" type="button" onclick="qurWaSwitchTab('other')" style="padding:6px 14px; border:none; background:transparent; color:#6b7280; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">💬 Other Message</button>
                </div>

                <!-- INVOICE TAB -->
                <div id="qurWaInvoicePanel">
                    <div style="margin-bottom:12px;">
                        <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:4px;">Invoice Template</label>
                        <select id="waTemplateName" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; background:#fff;" onchange="onQurbaniTemplateChange()">
                            <option value="">Loading templates...</option>
                        </select>
                        <div style="font-size:11px; color:#9ca3af; margin-top:4px;">Templates tagged "Qurbani Invoice" / "Invoice" in Manage Templates</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:4px;">Body Variables (comma-separated)</label>
                        <input id="waBodyParams" type="text" value="${qstatsEscape(customerName || '')}, ${qstatsEscape(orderNumber || '')}" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                        <div id="waVarHint" style="font-size:11px; color:#9ca3af; margin-top:4px;">Variables are passed to the template in order (e.g. 1=Name, 2=Order#)</div>
                    </div>
                    <div id="waInvPreviewArea" style="margin-bottom:12px; display:none;">
                        <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:4px;">Invoice Preview</label>
                        <div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; text-align:center; background:#f9fafb; padding:8px;">
                            <img id="waInvPreviewImg" style="max-width:100%; max-height:300px; border-radius:4px; cursor:pointer;" onclick="openFullscreenImg(this.src)" title="Click to view full size" />
                        </div>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button id="waInvPreviewBtn" onclick="previewQurbaniInvoice()" style="flex:1; padding:10px; border:1px solid #25D366; color:#25D366; background:#fff; border-radius:8px; font-weight:600; cursor:pointer; font-size:13px;">Refresh Preview</button>
                        <button id="waSendBtn" onclick="sendWhatsAppInvoice()" style="flex:1; padding:10px; background:#25D366; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:13px;">Send Invoice</button>
                    </div>
                    <div id="waInvStatus" style="margin-top:10px; font-size:13px; text-align:center; display:none;"></div>
                </div>

                <!-- OTHER TAB -->
                <div id="qurWaOtherPanel" style="display:none;">
                    <div style="margin-bottom:12px;">
                        <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:4px;">Message Template</label>
                        <select id="qurWaOtherTpl" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; background:#fff;" onchange="onQurbaniOtherTemplateChange()">
                            <option value="">Loading templates...</option>
                        </select>
                        <div style="font-size:11px; color:#9ca3af; margin-top:4px;">Shows only templates allowed on Qurbani orders (no invoice templates).</div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-size:12px; color:#6b7280; display:block; margin-bottom:4px;">Body Variables (comma-separated)</label>
                        <input id="qurWaOtherParams" type="text" value="${qstatsEscape(customerName || '')}" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;">
                        <div id="qurWaOtherHint" style="font-size:11px; color:#9ca3af; margin-top:4px;">Variables are passed to the template in order.</div>
                    </div>
                    <div id="qurWaOtherPreview" style="display:none; background:#f9fafb; border:1px dashed #e5e7eb; border-radius:6px; padding:10px; margin-bottom:12px; font-size:12px; color:#374151; white-space:pre-wrap;"></div>
                    <div style="display:flex; gap:8px;">
                        <button onclick="sendQurbaniOtherMessage()" style="flex:1; padding:10px; background:#25D366; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:13px;">Send Message</button>
                    </div>
                    <div id="qurWaOtherStatus" style="margin-top:10px; font-size:13px; text-align:center; display:none;"></div>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });

    qurWaLoadInvoiceTemplates();
    qurWaLoadOtherTemplates();

    // Kick off the invoice preview automatically so the user sees what they are
    // sending without having to press the preview button first.
    previewQurbaniInvoice();
}

function qurWaSwitchTab(tab) {
    var invTab = document.getElementById('qurWaTabInvoice');
    var othTab = document.getElementById('qurWaTabOther');
    var invPanel = document.getElementById('qurWaInvoicePanel');
    var othPanel = document.getElementById('qurWaOtherPanel');
    if (!invTab || !othTab || !invPanel || !othPanel) return;
    var activeStyle = 'padding:6px 14px; border:none; background:#fff; color:#111827; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.08);';
    var inactiveStyle = 'padding:6px 14px; border:none; background:transparent; color:#6b7280; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;';
    if (tab === 'other') {
        invTab.setAttribute('style', inactiveStyle);
        othTab.setAttribute('style', activeStyle);
        invPanel.style.display = 'none';
        othPanel.style.display = '';
    } else {
        invTab.setAttribute('style', activeStyle);
        othTab.setAttribute('style', inactiveStyle);
        invPanel.style.display = '';
        othPanel.style.display = 'none';
    }
}

function qurWaLoadInvoiceTemplates() {
    window._qurInvoiceTemplates = [];
    fetch('/messages/templates?context=qurbani_invoice,invoice', {
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var sel = document.getElementById('waTemplateName');
        if (!sel) return;
        sel.innerHTML = '';
        var tpls = (data.templates || []);
        window._qurInvoiceTemplates = tpls;
        if (tpls.length === 0) {
            sel.innerHTML = '<option value="">No invoice templates found</option>';
            return;
        }
        var defaultIdx = tpls.findIndex(function(t) { return t.is_default; });
        if (defaultIdx < 0) defaultIdx = 0;
        tpls.forEach(function(t, i) {
            var opt = document.createElement('option');
            opt.value = t.name;
            opt.textContent = t.display_name + ' (' + t.variable_count + ' vars)' + (t.is_default ? ' ⭐' : '');
            opt.dataset.varCount = t.variable_count;
            opt.dataset.idx = i;
            if (i === defaultIdx) opt.selected = true;
            sel.appendChild(opt);
        });
        onQurbaniTemplateChange();
    })
    .catch(function() {
        var sel = document.getElementById('waTemplateName');
        if (sel) sel.innerHTML = '<option value="">Failed to load templates</option>';
    });
}

function qurWaLoadOtherTemplates() {
    window._qurOtherTemplates = [];
    // Non-invoice contexts that make sense for a qurbani order customer message.
    fetch('/messages/templates?context=qurbani_orders,orders,messages,customers', {
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var sel = document.getElementById('qurWaOtherTpl');
        if (!sel) return;
        sel.innerHTML = '';
        var tpls = (data.templates || []).filter(function(t) {
            // Exclude anything explicitly marked as an invoice template to avoid
            // duplicating the invoice flow here (invoice templates go through
            // the image-header sender, not /send-template).
            var raw = t.show_in;
            var showIn = Array.isArray(raw)
                ? raw.map(function(x) { return String(x).toLowerCase().trim(); })
                : String(raw || '').toLowerCase().split(',').map(function(x) { return x.trim(); });
            if (showIn.indexOf('invoice') >= 0 || showIn.indexOf('qurbani_invoice') >= 0) return false;
            return true;
        });
        window._qurOtherTemplates = tpls;
        if (tpls.length === 0) {
            sel.innerHTML = '<option value="">No templates available</option>';
            return;
        }
        var defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = '-- Select a template --';
        sel.appendChild(defaultOpt);
        tpls.forEach(function(t, i) {
            var opt = document.createElement('option');
            opt.value = t.name;
            opt.textContent = t.display_name + ' (' + t.variable_count + ' vars)';
            opt.dataset.varCount = t.variable_count;
            opt.dataset.idx = i;
            sel.appendChild(opt);
        });
    })
    .catch(function() {
        var sel = document.getElementById('qurWaOtherTpl');
        if (sel) sel.innerHTML = '<option value="">Failed to load templates</option>';
    });
}

function onQurbaniTemplateChange() {
    var sel = document.getElementById('waTemplateName');
    var paramsInput = document.getElementById('waBodyParams');
    var hintEl = document.getElementById('waVarHint');
    if (!sel || !paramsInput) return;
    var selectedOpt = sel.options[sel.selectedIndex];
    var varCount = parseInt(selectedOpt?.dataset?.varCount || '0');
    var custName = qurWaCustomerName;
    var orderNum = qurWaOrderNumber;
    if (varCount === 0) {
        paramsInput.value = '';
        if (hintEl) hintEl.textContent = 'This template has no variables';
    } else if (varCount === 1) {
        paramsInput.value = custName;
        if (hintEl) hintEl.textContent = '1 variable: Name';
    } else {
        paramsInput.value = custName + ', ' + orderNum;
        if (hintEl) hintEl.textContent = varCount + ' variables: e.g. Name, Order#' + (varCount > 2 ? ', ...' : '');
    }
}

function onQurbaniOtherTemplateChange() {
    var sel = document.getElementById('qurWaOtherTpl');
    var paramsInput = document.getElementById('qurWaOtherParams');
    var hintEl = document.getElementById('qurWaOtherHint');
    var previewEl = document.getElementById('qurWaOtherPreview');
    if (!sel || !paramsInput) return;
    var selectedOpt = sel.options[sel.selectedIndex];
    var idx = selectedOpt?.dataset?.idx;
    var tpls = window._qurOtherTemplates || [];
    var tpl = (idx != null) ? tpls[parseInt(idx)] : null;
    var varCount = parseInt(selectedOpt?.dataset?.varCount || '0');

    // Default variables: pre-fill customer name / order number as useful seeds.
    if (varCount === 0) {
        paramsInput.value = '';
        if (hintEl) hintEl.textContent = 'This template has no variables';
    } else if (varCount === 1) {
        paramsInput.value = qurWaCustomerName;
        if (hintEl) hintEl.textContent = '1 variable: Name';
    } else {
        paramsInput.value = qurWaCustomerName + ', ' + qurWaOrderNumber;
        if (hintEl) hintEl.textContent = varCount + ' variables (e.g. Name, Order#)';
    }

    if (previewEl) {
        if (tpl && tpl.body_text) {
            previewEl.textContent = tpl.body_text;
            previewEl.style.display = '';
        } else {
            previewEl.style.display = 'none';
        }
    }
}

function previewQurbaniInvoice() {
    var btn = document.getElementById('waInvPreviewBtn');
    if (!btn) return;
    btn.textContent = 'Loading...';
    btn.disabled = true;

    fetch('/messages/invoice-image/' + qurWaCurrentOrderId, {
        headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'}
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (!d.success) { alert(d.message || 'Failed'); btn.textContent = 'Preview Invoice'; btn.disabled = false; return; }
        if (d.needs_capture) {
            captureQurbaniInvoiceImage(d.invoice_url, qurWaCurrentOrderId).then(function(uploadRes) {
                var img = document.getElementById('waInvPreviewImg');
                var area = document.getElementById('waInvPreviewArea');
                if (img) img.src = uploadRes.image_url;
                if (area) area.style.display = 'block';
                btn.textContent = 'Refresh Preview'; btn.disabled = false;
            }).catch(function(err) { alert('Failed to capture invoice: ' + err.message); btn.textContent = 'Refresh Preview'; btn.disabled = false; });
        } else {
            var img = document.getElementById('waInvPreviewImg');
            var area = document.getElementById('waInvPreviewArea');
            if (img) img.src = d.image_url;
            if (area) area.style.display = 'block';
            btn.textContent = 'Refresh Preview'; btn.disabled = false;
        }
    })
    .catch(function(e) { alert('Error: ' + e.message); btn.textContent = 'Refresh Preview'; btn.disabled = false; });
}

function sendWhatsAppInvoice() {
    var phone = document.getElementById('waInvPhone').value.trim();
    var templateName = document.getElementById('waTemplateName').value.trim();
    var bodyParamsStr = document.getElementById('waBodyParams').value.trim();
    if (!phone) { alert('Please enter a phone number'); return; }
    if (!templateName) { alert('Please enter the template name'); return; }
    var params = bodyParamsStr ? bodyParamsStr.split(',').map(function(s) { return s.trim(); }) : [];
    var btn = document.getElementById('waSendBtn');
    var statusEl = document.getElementById('waInvStatus');
    btn.textContent = 'Sending...'; btn.disabled = true;
    statusEl.style.display = 'none';

    // If preview image isn't ready yet (e.g. user hit Send before the auto-
    // preview finished), force a capture first so the header image resolves.
    var previewReady = document.getElementById('waInvPreviewArea')?.style.display === 'block';
    var ensureReady = previewReady
        ? Promise.resolve()
        : new Promise(function(resolve) {
            fetch('/messages/invoice-image/' + qurWaCurrentOrderId, { headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'} })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.success && d.needs_capture) {
                        return captureQurbaniInvoiceImage(d.invoice_url, qurWaCurrentOrderId).then(function(uploadRes) {
                            var img = document.getElementById('waInvPreviewImg');
                            var area = document.getElementById('waInvPreviewArea');
                            if (img) img.src = uploadRes.image_url;
                            if (area) area.style.display = 'block';
                            resolve();
                        }).catch(function() { resolve(); });
                    }
                    if (d && d.success && d.image_url) {
                        var img = document.getElementById('waInvPreviewImg');
                        var area = document.getElementById('waInvPreviewArea');
                        if (img) img.src = d.image_url;
                        if (area) area.style.display = 'block';
                    }
                    resolve();
                })
                .catch(function() { resolve(); });
        });

    ensureReady.then(function() {
        return fetch('/messages/send-invoice', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ order_id: qurWaCurrentOrderId, phone: phone, template_name: templateName, body_params: params }),
        });
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            statusEl.style.display = 'block'; statusEl.style.color = '#16a34a';
            statusEl.textContent = 'Invoice sent successfully!';
            btn.textContent = 'Sent!';
            setTimeout(function() { var el = document.getElementById('waInvoiceOverlay'); if (el) el.remove(); }, 2000);
        } else {
            statusEl.style.display = 'block'; statusEl.style.color = '#dc2626';
            statusEl.textContent = data.message || 'Failed to send';
            btn.textContent = 'Send Invoice'; btn.disabled = false;
        }
    })
    .catch(function(e) { statusEl.style.display = 'block'; statusEl.style.color = '#dc2626'; statusEl.textContent = e.message; btn.textContent = 'Send Invoice'; btn.disabled = false; });
}

function sendQurbaniOtherMessage() {
    var phone = document.getElementById('waInvPhone').value.trim();
    var tplSel = document.getElementById('qurWaOtherTpl');
    var templateName = tplSel ? tplSel.value.trim() : '';
    var paramsStr = (document.getElementById('qurWaOtherParams').value || '').trim();
    if (!phone) { alert('Please enter a phone number'); return; }
    if (!templateName) { alert('Please pick a template'); return; }
    var params = paramsStr ? paramsStr.split(',').map(function(s) { return s.trim(); }) : [];
    var statusEl = document.getElementById('qurWaOtherStatus');
    var btn = document.querySelector('#qurWaOtherPanel button[onclick="sendQurbaniOtherMessage()"]');
    if (btn) { btn.textContent = 'Sending...'; btn.disabled = true; }
    if (statusEl) statusEl.style.display = 'none';

    fetch('/messages/send-template', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({ phone: phone, template_name: templateName, body_params: params }),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!statusEl || !btn) return;
        if (data.success) {
            statusEl.style.display = 'block'; statusEl.style.color = '#16a34a';
            statusEl.textContent = 'Message sent successfully!';
            btn.textContent = 'Sent!';
            setTimeout(function() { var el = document.getElementById('waInvoiceOverlay'); if (el) el.remove(); }, 1500);
        } else {
            statusEl.style.display = 'block'; statusEl.style.color = '#dc2626';
            statusEl.textContent = data.message || 'Failed to send';
            btn.textContent = 'Send Message'; btn.disabled = false;
        }
    })
    .catch(function(e) {
        if (statusEl) { statusEl.style.display = 'block'; statusEl.style.color = '#dc2626'; statusEl.textContent = e.message; }
        if (btn) { btn.textContent = 'Send Message'; btn.disabled = false; }
    });
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
    const labels = { qurbani_day: 'Qurbani Day', qurbani_slot: 'Qurbani Slot', qurbani_region: 'Qurbani Region', qurbani_sub_region: 'Sub Region', qurbani_delivery_type: 'Delivery Type' };
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

// ======= QURBANI STATS PANEL =======
let qStatsData = null;                  // latest response from /qurbani/api/order-stats
let qStatsBreakdownKey = null;          // currently expanded delivery_type, or null for summary view

const QSTATS_COLLAPSED_KEY = 'qurbani_stats_collapsed_v1';

function qstatsDotColor(dt) {
    const k = String(dt || '').toLowerCase();
    if (k.indexOf('self') >= 0) return '#6366f1';
    if (k.indexOf('deliver') >= 0) return '#10b981';
    if (k === 'unassigned') return '#9ca3af';
    return '#d97706';
}

function qstatsSortDeliveryTypes(types) {
    const pref = ['delivery', 'self collection', 'self-collection', 'self collect'];
    const arr = types.slice();
    arr.sort((a, b) => {
        const ia = pref.findIndex(p => String(a).toLowerCase().indexOf(p) >= 0);
        const ib = pref.findIndex(p => String(b).toLowerCase().indexOf(p) >= 0);
        if (a === 'Unassigned') return 1;
        if (b === 'Unassigned') return -1;
        if (ia !== ib) {
            if (ia < 0) return 1;
            if (ib < 0) return -1;
            return ia - ib;
        }
        return String(a).localeCompare(String(b));
    });
    return arr;
}

function qstatsEscape(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function loadQurbaniStats() {
    const loading = document.getElementById('qStatsLoading');
    const summary = document.getElementById('qStatsSummary');
    const breakdown = document.getElementById('qStatsBreakdown');
    if (loading) { loading.style.display = ''; loading.textContent = 'Loading stats…'; }
    if (summary) summary.style.display = 'none';
    if (breakdown) breakdown.style.display = 'none';

    fetch('/qurbani/api/order-stats', {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data || !data.success) throw new Error('Bad response');
        qStatsData = data;
        if (qStatsBreakdownKey) renderQurbaniBreakdown(qStatsBreakdownKey);
        else renderQurbaniSummary();
    })
    .catch(() => {
        if (loading) loading.textContent = 'Failed to load stats. Try refreshing.';
    });
}

function renderQurbaniSummary() {
    qStatsBreakdownKey = null;
    const loading = document.getElementById('qStatsLoading');
    const summary = document.getElementById('qStatsSummary');
    const breakdown = document.getElementById('qStatsBreakdown');
    if (!qStatsData) return;

    const days = qStatsData.days || [];
    const categories = qStatsData.categories || [];
    const summaryMap = qStatsData.summary || {};

    const deliveryTypes = qstatsSortDeliveryTypes(Object.keys(summaryMap));

    if (loading) loading.style.display = 'none';
    if (breakdown) { breakdown.style.display = 'none'; breakdown.innerHTML = ''; }
    if (!summary) return;
    summary.style.display = '';
    summary.className = 'qstats-cards';

    if (deliveryTypes.length === 0 || days.length === 0) {
        summary.innerHTML = '<div class="qstats-empty" style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; grid-column:1 / -1;">No qurbani orders booked yet.</div>';
        return;
    }

    // Ensure Delivery and Self Collection are always rendered as separate cards
    // even if one has no data yet (user requested the two fixed cards).
    const knownTypes = ['Delivery', 'Self Collection'];
    knownTypes.forEach(kt => {
        const exists = deliveryTypes.some(dt => String(dt).toLowerCase() === kt.toLowerCase());
        if (!exists) deliveryTypes.push(kt);
    });
    const sortedTypes = qstatsSortDeliveryTypes(deliveryTypes);

    summary.innerHTML = sortedTypes.map(dt => qstatsBuildSummaryCard(dt, days, categories, summaryMap[dt] || {})).join('');
}

function qstatsBuildSummaryCard(deliveryType, days, categories, byDayCat) {
    // Compute row / col totals and grand total.
    const colTotals = {};
    let grandTotal = 0;
    const rows = categories.map(cat => {
        let rowTotal = 0;
        const cells = days.map(day => {
            const qty = ((byDayCat[day] || {})[cat]) || 0;
            rowTotal += qty;
            colTotals[day] = (colTotals[day] || 0) + qty;
            return qty;
        });
        grandTotal += rowTotal;
        return { cat, cells, rowTotal };
    });

    let tableHtml;
    if (grandTotal === 0) {
        tableHtml = '<div class="qstats-empty">No bookings yet for this type.</div>';
    } else {
        tableHtml = `
            <table class="qstats-table">
                <thead>
                    <tr>
                        <th></th>
                        ${days.map(d => `<th>${qstatsEscape(d)}</th>`).join('')}
                        <th class="total-col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.map(r => `
                        <tr>
                            <td class="row-label">${qstatsEscape(r.cat)}</td>
                            ${r.cells.map(v => `<td class="${v === 0 ? 'zero' : ''}">${v}</td>`).join('')}
                            <td class="total-col">${r.rowTotal}</td>
                        </tr>
                    `).join('')}
                    <tr class="total-row">
                        <td class="row-label">Total</td>
                        ${days.map(d => `<td>${colTotals[d] || 0}</td>`).join('')}
                        <td>${grandTotal}</td>
                    </tr>
                </tbody>
            </table>
        `;
    }

    const dotColor = qstatsDotColor(deliveryType);
    return `
        <div class="qstats-card" data-dt="${qstatsEscape(deliveryType)}">
            <div class="qstats-card-head">
                <div class="qstats-card-title">
                    <span class="dot" style="background:${dotColor};"></span>
                    <span>${qstatsEscape(deliveryType)}</span>
                </div>
                <div class="qstats-card-total">Total <b>${grandTotal}</b></div>
            </div>
            ${tableHtml}
            <div class="qstats-card-actions" style="justify-content:flex-end; margin-top:8px;">
                <button class="qstats-ghostbtn" onclick="renderQurbaniBreakdown('${qstatsEscape(deliveryType).replace(/'/g, "\\'")}')" ${grandTotal === 0 ? 'disabled style=\"opacity:0.5; cursor:not-allowed;\"' : ''}>View breakdown &rarr;</button>
            </div>
        </div>
    `;
}

function renderQurbaniBreakdown(deliveryType) {
    qStatsBreakdownKey = deliveryType;
    const loading = document.getElementById('qStatsLoading');
    const summary = document.getElementById('qStatsSummary');
    const breakdown = document.getElementById('qStatsBreakdown');
    if (!qStatsData || !breakdown) return;

    const days = qStatsData.days || [];
    const categories = qStatsData.categories || [];
    const detail = (qStatsData.detail || {})[deliveryType] || {};
    const summaryForType = (qStatsData.summary || {})[deliveryType] || {};

    if (loading) loading.style.display = 'none';
    if (summary) summary.style.display = 'none';
    breakdown.style.display = '';

    const dotColor = qstatsDotColor(deliveryType);

    // Build mini-tables for every (day × category) combination that has data.
    const miniHtml = [];
    days.forEach(day => {
        categories.forEach(cat => {
            const qtyTotal = ((summaryForType[day] || {})[cat]) || 0;
            if (qtyTotal === 0) return;   // skip empty buckets
            const cellMap = (((detail[day] || {})[cat] || {}).cell) || {};
            const slots = ((detail[day] || {})[cat] || {}).slots || [];
            const regions = ((detail[day] || {})[cat] || {}).regions || [];
            if (slots.length === 0 || regions.length === 0) return;

            const rowsHtml = slots.map(slot => {
                const cellsHtml = regions.map(region => {
                    const v = ((cellMap[slot] || {})[region]) || 0;
                    return `<td class="${v === 0 ? 'zero' : ''}">${v}</td>`;
                }).join('');
                return `<tr><td class="row-label">${qstatsEscape(slot)}</td>${cellsHtml}</tr>`;
            }).join('');

            miniHtml.push(`
                <div class="qstats-mini">
                    <div class="qstats-mini-head">
                        <div class="qstats-mini-title">${qstatsEscape(day)} &middot; ${qstatsEscape(cat)}</div>
                        <div class="qstats-mini-total">${qtyTotal}</div>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th style="text-align:left;">Slot \\ Region</th>
                                ${regions.map(r => `<th>${qstatsEscape(r)}</th>`).join('')}
                            </tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                </div>
            `);
        });
    });

    const body = miniHtml.length
        ? `<div class="qstats-mini-grid">${miniHtml.join('')}</div>`
        : '<div class="qstats-empty" style="background:#fff; border:1px solid #e5e7eb; border-radius:10px;">No detailed breakdown yet.</div>';

    breakdown.innerHTML = `
        <div class="qstats-card">
            <div class="qstats-card-head">
                <div class="qstats-card-title">
                    <span class="dot" style="background:${dotColor};"></span>
                    <span>${qstatsEscape(deliveryType)} &mdash; detailed breakdown</span>
                </div>
                <div class="qstats-card-actions">
                    <button class="qstats-ghostbtn secondary" onclick="renderQurbaniSummary()">&larr; Back to summary</button>
                </div>
            </div>
            ${body}
        </div>
    `;
}

function toggleQurbaniStats() {
    const body = document.getElementById('qStatsBody');
    const btn = document.getElementById('qStatsToggle');
    if (!body || !btn) return;
    const hidden = body.style.display === 'none';
    body.style.display = hidden ? '' : 'none';
    btn.innerHTML = hidden ? '<i class="ki-filled ki-up"></i> Hide' : '<i class="ki-filled ki-down"></i> Show';
    try { localStorage.setItem(QSTATS_COLLAPSED_KEY, hidden ? '0' : '1'); } catch (e) {}
}

function initQurbaniStatsPanel() {
    let collapsed = '0';
    try { collapsed = localStorage.getItem(QSTATS_COLLAPSED_KEY) || '0'; } catch (e) {}
    const body = document.getElementById('qStatsBody');
    const btn = document.getElementById('qStatsToggle');
    if (collapsed === '1' && body && btn) {
        body.style.display = 'none';
        btn.innerHTML = '<i class="ki-filled ki-down"></i> Show';
    }
    loadQurbaniStats();
}

// ======= INIT =======
document.addEventListener('DOMContentLoaded', () => {
    loadSlotOptions();
    updateSubRegionDropdown();
    loadOrders();
    initQurbaniStatsPanel();

    // Add edit icons to filter labels for Taimur role
    if (isTaimurOrMgmt) {
        ['filterDay', 'filterSlot', 'filterRegion', 'filterSubRegion', 'filterDeliveryType'].forEach(id => {
            const fieldMap = { filterDay: 'qurbani_day', filterSlot: 'qurbani_slot', filterRegion: 'qurbani_region', filterSubRegion: 'qurbani_sub_region', filterDeliveryType: 'qurbani_delivery_type' };
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

async function deleteQurbaniOrder(orderId, orderNumber) {
    if (!confirm(`Are you sure you want to permanently delete order #${orderNumber}?\n\nThis will remove the order, all line items, payments, and ledger entries. This action cannot be undone.`)) return;
    if (!confirm(`FINAL CONFIRMATION: Delete order #${orderNumber} permanently?`)) return;

    try {
        const response = await fetch(`/qurbani/api/orders/${orderId}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        });
        const data = await response.json();
        if (data.success) {
            alert(data.message);
            loadOrders();
        } else {
            alert(data.message || 'Failed to delete order');
        }
    } catch (e) {
        alert('Network error while deleting order');
    }
}
</script>
@endsection
