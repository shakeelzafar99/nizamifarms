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

/* Apr-2026 redesign: day rows now sit on the Y-axis (rows) and categories
   live on the X-axis (cols). Each day has expandable nested slot sub-rows
   beneath. Day rows are clickable (toggle slot expansion); slot rows are
   indented and rendered in a paler tone so they read as secondary
   detail beneath the parent day. The chevron on the day row's row-label
   indicates the expand/collapse state. */
.qstats-table .day-row .row-label { cursor:pointer; user-select:none; }
.qstats-table .day-row .row-label:hover { background:#f3f4f6; }
.qstats-table .day-row .row-label .chev { display:inline-block; width:10px; color:#9ca3af; font-size:10px; margin-right:4px; transition:transform .15s ease; }
.qstats-table .day-row.expanded .row-label .chev { transform:rotate(90deg); color:#374151; }
.qstats-table .slot-row td { background:#fafbfc; font-size:11px; color:#4b5563; }
.qstats-table .slot-row .row-label { background:#fafbfc; font-weight:500; color:#6b7280; padding-left:24px; font-size:11px; }
.qstats-table .slot-row.zero-row td { color:#d1d5db; }
.qstats-table .slot-row.zero-row .row-label { color:#9ca3af; }

/* Apr-2026 Phase 2: filter-on-click. Clickable data cells get a faint
   hover ring so the affordance is discoverable without being noisy.
   Disabled (zero) cells are not clickable — pointer stays as default
   so users don't accidentally apply a filter that returns nothing. */
.qstats-table td.filterable { cursor:pointer; transition:background .12s ease, box-shadow .12s ease; }
.qstats-table td.filterable:hover { background:#fef3c7; box-shadow: inset 0 0 0 2px #f59e0b; }
.qstats-table td.filterable.zero { cursor:default; }
.qstats-table td.filterable.zero:hover { background:inherit; box-shadow:none; }

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
/* Apr-2026: soft-cap target rendering. "booked/target" with color hint.
   Color hints are intentionally subtle so the table still reads as numeric
   data first, target awareness second. */
.qstats-cell-target { color:#9ca3af; font-size:10px; font-weight:500; margin-left:2px; }
.qstats-cell-ok     { color:#15803d; font-weight:700; }   /* < 90% */
.qstats-cell-near   { color:#b45309; font-weight:700; }   /* 90-100% */
.qstats-cell-over   { color:#b91c1c; font-weight:700; }   /* > 100% */
/* Edit-mode inputs. Kept tiny so the table doesn't visually jump when
   switching between view and edit modes. */
.qstats-target-input {
    width: 58px; text-align:center; font-size:11px; padding:2px 4px;
    border:1px solid #fcd34d; border-radius:4px; background:#fffbeb; color:#111827;
}
.qstats-target-input:focus { outline:none; border-color:#d97706; box-shadow:0 0 0 2px rgba(217,119,6,.15); }
.qstats-savebtn { font-size:11px; padding:4px 12px; border-radius:6px; border:1px solid #059669; background:#10b981; color:#fff; cursor:pointer; font-weight:600; }
.qstats-savebtn:hover { background:#059669; }
.qstats-savebtn:disabled { background:#9ca3af; border-color:#9ca3af; cursor:not-allowed; }
.qstats-cancelbtn { font-size:11px; padding:4px 10px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer; font-weight:600; }
.qstats-cancelbtn:hover { background:#f9fafb; color:#111827; }

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
                {{-- New Apr-2026 qurbani attributes. Rendered only when the
                     admin has configured option values so a fresh install
                     (no migration / seeds yet) doesn't show empty pickers. --}}
                @if(isset($qurbaniTypes) && count($qurbaniTypes) > 0)
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Qurbani Type</label>
                    <select id="filterQurbaniType" class="filter-select" onchange="loadOrders()">
                        <option value="">All Types</option>
                        @foreach($qurbaniTypes as $qt)
                        <option value="{{ $qt }}">{{ $qt }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if(isset($payaOptions) && count($payaOptions) > 0)
                <div>
                    <label class="text-xs font-medium text-gray-500 block mb-1">Paya</label>
                    <select id="filterQurbaniPaya" class="filter-select" onchange="loadOrders()">
                        <option value="">All Paya</option>
                        @foreach($payaOptions as $po)
                        <option value="{{ $po }}">{{ $po }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
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
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Payment Type</th>
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
// Apr-2026: target quantities are Taimur-only (NOT management). Used to
// gate the "Set Targets" buttons on the booked summary. Backend enforces
// the same rule in QurbaniWebController::saveQurbaniTargets so hiding
// the buttons is purely a UX nicety.
const isTaimurStrict = {{ ($isTaimur ?? false) ? 'true' : 'false' }};
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
    // New Apr-2026 qurbani attributes. Guarded because the filters only
    // render when the admin has configured option values.
    const qtEl = document.getElementById('filterQurbaniType');
    if (qtEl) qtEl.value = '';
    const payaEl = document.getElementById('filterQurbaniPaya');
    if (payaEl) payaEl.value = '';
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
    // New Apr-2026 qurbani attributes (only present in the DOM when the admin
    // has configured option values — guarded lookup keeps older layouts working).
    const qType = (document.getElementById('filterQurbaniType') || {}).value || '';
    const qPaya = (document.getElementById('filterQurbaniPaya') || {}).value || '';
    if (day) params.set('day', day);
    if (slot) params.set('slot', slot);
    if (region) params.set('region', region);
    if (subRegion) params.set('sub_region', subRegion);
    if (dt) params.set('delivery_type', dt);
    if (paymentStatus) params.set('payment_status', paymentStatus);
    if (category) params.set('category', category);
    if (customer) params.set('customer', customer);
    if (qType) params.set('qurbani_type', qType);
    if (qPaya) params.set('qurbani_paya', qPaya);

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
        // NF: tint fully-paid rows green for easier scanning. If the row also
        // has notes (yellow) we keep the yellow tint so the note signal isn't
        // lost — notes are actionable; "paid" is just informational. A thick
        // dark-green left bar still marks the row as paid even when yellow
        // wins. Bumped the green tint from emerald-50 (#ECFDF5) to emerald-100
        // (#D1FAE5) and the bar from 3px/#10B981 to 5px/#059669 so the signal
        // actually pops across a dense grid.
        const isPaid = o.payment_status === 'paid';
        const rowBgClass = hasAnyNote ? ' bg-yellow-50' : '';
        const paidInline = isPaid
            ? (hasAnyNote ? 'border-left:5px solid #059669;' : 'background-color:#D1FAE5; border-left:5px solid #059669;')
            : '';
        const rowInlineStyle = paidInline ? ` style="${paidInline}"` : '';

        // Payment-type badge (replaces the old order_status column). We keep
        // `statusClass` computed above so any downstream consumer isn't broken,
        // but the visible cell now shows cash / online.
        const rawPayMethod = (o.payment_method || '').toLowerCase();
        const payMethodLabel = rawPayMethod === 'online'
            ? 'Online'
            : (rawPayMethod === 'cash' ? 'Cash' : '-');
        const payMethodStyle = rawPayMethod === 'online'
            ? 'background:#CFFAFE; color:#155E75;'
            : (rawPayMethod === 'cash' ? 'background:#E5E7EB; color:#374151;' : 'background:#F3F4F6; color:#6B7280;');

        return `<tr class="order-row border-b border-gray-100 cursor-pointer${rowBgClass}"${rowInlineStyle} onclick="window.open('/orders?edit_order_id=${o.id}', '_blank')">
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
            <td class="px-3 py-2"><span class="status-badge" style="${payMethodStyle}">${payMethodLabel}</span></td>
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
                    <button onclick="openQurbaniPaymentModal(${o.id}, ${Number(o.balance_remaining || 0)}, '${rawPayMethod}')" style="padding:3px 8px; background:#D97706; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="Add Payment">💰 Pay</button>
                    ${(Number(o.total_paid) > 0 || o.payment_status === 'paid' || o.payment_status === 'partial') ? `<button onclick="openQurbaniPaymentsHistoryModal(${o.id})" style="padding:3px 8px; background:#0EA5E9; color:#fff; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap;" title="View / edit payments">💳 Payments</button>` : ''}
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
// Online Approvals uses. `let` (not `const`) because the quick-add-bank
// feature below appends new accounts on the fly and we re-render from
// this list. The backing DB stays authoritative — next page load we'll
// get a fresh copy seeded by QurbaniWebController::index.
let QURBANI_RECEIVING_ACCOUNTS = @json($receivingAccounts ?? []);
// Qurbani settings → default payment method for NEW orders (cash/online).
// Used when the current order has no payment_method saved yet, so the
// modal opens on the admin-configured default instead of a hard-coded
// 'cash'. Overridden if the order already has a method set.
var QURBANI_DEFAULT_PAYMENT_METHOD = @json($defaultPaymentMethod ?? 'cash');

var _qurbaniPaymentOrderId = null;
var _qurbaniPaymentReceivingId = null; // currently-selected receiving bank id
function openQurbaniPaymentModal(orderId, balanceRemaining, orderPaymentMethod) {
    _qurbaniPaymentOrderId = orderId;
    _qurbaniPaymentReceivingId = null;
    // Normalise the order's payment method so the dropdown opens on the
    // right option. Orders store either 'cash' or 'online'; anything
    // else (or missing) falls back to the admin-configured default from
    // Qurbani settings so brand-new orders respect the team's preference.
    var defaultMethod;
    if (orderPaymentMethod === 'online') {
        defaultMethod = 'online';
    } else if (orderPaymentMethod === 'cash') {
        defaultMethod = 'cash';
    } else {
        defaultMethod = (QURBANI_DEFAULT_PAYMENT_METHOD === 'online') ? 'online' : 'cash';
    }
    var existing = document.getElementById('qurbaniPayOverlay');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'qurbaniPayOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px; overflow-y:auto;';
    const defaultAmt = balanceRemaining > 0 ? balanceRemaining : '';
    const todayIso = new Date().toISOString().split('T')[0];

    // Bank row starts open when the order itself is an online order so the
    // team sees the bank choices immediately; for cash orders the row stays
    // hidden (same as before) and only reveals if the user changes the
    // method dropdown.
    const bankRowInitialDisplay = (defaultMethod === 'online') ? 'block' : 'none';
    const cashSelected   = defaultMethod === 'cash'   ? ' selected' : '';
    const onlineSelected = defaultMethod === 'online' ? ' selected' : '';

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
                        <option value="cash"${cashSelected}>Cash</option>
                        <option value="online"${onlineSelected}>Online</option>
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

            <div id="qPayBankRow" style="display:${bankRowInitialDisplay}; margin-bottom:12px;">
                <label style="display:block; font-size:13px; font-weight:600; color:#0369A1; margin-bottom:6px;">🏦 Received in Bank <span style="color:#DC2626;">*</span></label>
                <div id="qPayBankChips" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                <!-- Quick inline form for adding a new receiving bank without
                     leaving this modal. Hits POST /online-receiving-accounts
                     (same endpoint Online Approvals uses) and appends the
                     result to the local chip list + auto-selects it. -->
                <div id="qPayAddBankForm" style="display:none; margin-top:8px; padding:10px; border:1px dashed #93C5FD; border-radius:8px; background:#F8FAFC;">
                    <div style="display:grid; grid-template-columns:1.4fr 1fr 42px; gap:6px; margin-bottom:6px;">
                        <input type="text" id="qPayNewBankName" placeholder="Bank name (e.g. Habib Bank)" style="padding:6px 8px; border:1px solid #CBD5E1; border-radius:5px; font-size:12px;">
                        <input type="text" id="qPayNewBankCode" placeholder="Short (e.g. HBL)" maxlength="20" style="padding:6px 8px; border:1px solid #CBD5E1; border-radius:5px; font-size:12px; text-transform:uppercase;">
                        <input type="color" id="qPayNewBankColor" value="#3B82F6" title="Chip colour" style="width:100%; height:32px; padding:1px; border:1px solid #CBD5E1; border-radius:5px; cursor:pointer;">
                    </div>
                    <div style="display:flex; gap:6px; justify-content:flex-end;">
                        <button type="button" id="qPayAddBankCancelBtn" onclick="toggleQurbaniAddBankForm(false)" style="padding:5px 10px; background:#E5E7EB; color:#374151; border:none; border-radius:5px; font-size:11px; font-weight:600; cursor:pointer;">Cancel</button>
                        <button type="button" id="qPayAddBankSaveBtn" onclick="submitQurbaniAddBank()" style="padding:5px 10px; background:#16A34A; color:#fff; border:none; border-radius:5px; font-size:11px; font-weight:600; cursor:pointer;">Save Bank</button>
                    </div>
                </div>
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
    // Render the bank-chip row once the DOM is in place. We render via a
    // helper (instead of baking chips into innerHTML) so the quick-add
    // flow below can simply re-render after appending a new account.
    renderQurbaniBankChips();
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
        renderQurbaniBankChips();
        // Also fold the quick-add form if it was open, just to keep the
        // modal tidy on method toggles.
        toggleQurbaniAddBankForm(false);
    }
}

// Renders the bank chip row into #qPayBankChips. Builds from the current
// QURBANI_RECEIVING_ACCOUNTS array (which the quick-add flow appends to)
// and highlights whichever id is in _qurbaniPaymentReceivingId. A "+ Add
// new" chip at the end opens the inline form. The former "None" chip was
// removed — receiving bank is now mandatory for online payments.
function renderQurbaniBankChips() {
    const host = document.getElementById('qPayBankChips');
    if (!host) return;
    let html = '';
    (QURBANI_RECEIVING_ACCOUNTS || []).forEach(function (acc) {
        const active = _qurbaniPaymentReceivingId && String(_qurbaniPaymentReceivingId) === String(acc.id);
        const bg = active ? '#3B82F6' : '#fff';
        const fg = active ? '#fff'    : '#334155';
        html += '<button type="button" class="qpay-bank-chip' + (active ? ' qpay-bank-chip-active' : '') + '" data-bank-id="' + acc.id + '" onclick="selectQurbaniBankChip(this, ' + acc.id + ')" '
            + 'style="padding:5px 12px; border-radius:16px; border:1px solid #CBD5E1; background:' + bg + '; color:' + fg + '; font-size:12px; font-weight:600; cursor:pointer;">'
            + escapeQurbaniHtml(acc.short_code || acc.name) + '</button>';
    });
    // "+ Add new" chip always last. Kept visually distinct (dashed border,
    // green text) so nobody confuses it with a real bank.
    html += '<button type="button" onclick="toggleQurbaniAddBankForm(true)" '
        + 'style="padding:5px 12px; border-radius:16px; border:1px dashed #16A34A; background:#F0FDF4; color:#166534; font-size:12px; font-weight:600; cursor:pointer;" title="Add a new receiving bank">+ Add new</button>';
    host.innerHTML = html;
}

function selectQurbaniBankChip(el, bankId) {
    _qurbaniPaymentReceivingId = bankId || null;
    renderQurbaniBankChips();
}

// Tiny local escape for rendering bank short codes / names into chip text.
// We deliberately avoid touching any of the existing `esc` helpers used
// elsewhere in the file — those are for different contexts.
function escapeQurbaniHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function toggleQurbaniAddBankForm(show) {
    const form = document.getElementById('qPayAddBankForm');
    if (!form) return;
    form.style.display = show ? 'block' : 'none';
    if (show) {
        const nameEl = document.getElementById('qPayNewBankName');
        if (nameEl) nameEl.focus();
    } else {
        // Clear fields on close so re-opening is always a fresh form.
        ['qPayNewBankName', 'qPayNewBankCode'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        const colorEl = document.getElementById('qPayNewBankColor');
        if (colorEl) colorEl.value = '#3B82F6';
    }
}

// Create a new receiving bank from inside the Add Payment modal. On
// success we push the new account onto QURBANI_RECEIVING_ACCOUNTS, re-
// render the chips, auto-select the new bank, and close the form. Uses
// the same endpoint as the Online Approvals bank manager so the two
// sources of truth never drift.
function submitQurbaniAddBank() {
    const nameEl  = document.getElementById('qPayNewBankName');
    const codeEl  = document.getElementById('qPayNewBankCode');
    const colorEl = document.getElementById('qPayNewBankColor');
    const saveBtn = document.getElementById('qPayAddBankSaveBtn');
    const name  = (nameEl && nameEl.value || '').trim();
    const code  = (codeEl && codeEl.value || '').trim();
    const color = (colorEl && colorEl.value) || '#3B82F6';

    if (!name)  { alert('Bank name is required');  if (nameEl) nameEl.focus(); return; }
    if (!code)  { alert('Short code is required'); if (codeEl) codeEl.focus(); return; }

    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }
    fetch('/online-receiving-accounts', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ name: name, short_code: code, color_hex: color }),
    })
    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
    .then(function (res) {
        if (res.ok && res.data && res.data.success && res.data.data) {
            const acc = res.data.data;
            // Make sure we don't end up with duplicate ids if the user
            // clicks Save twice somehow — dedupe by id.
            QURBANI_RECEIVING_ACCOUNTS = (QURBANI_RECEIVING_ACCOUNTS || [])
                .filter(function (a) { return String(a.id) !== String(acc.id); });
            QURBANI_RECEIVING_ACCOUNTS.push({
                id: acc.id,
                name: acc.name,
                short_code: acc.short_code,
                color_hex: acc.color_hex,
            });
            _qurbaniPaymentReceivingId = acc.id;
            renderQurbaniBankChips();
            toggleQurbaniAddBankForm(false);
        } else {
            const msg = (res.data && (res.data.message || (res.data.errors && JSON.stringify(res.data.errors))))
                || 'Failed to add bank';
            alert(msg);
        }
    })
    .catch(function () { alert('Network error while adding bank'); })
    .finally(function () {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save Bank'; }
    });
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

// ---------------------------------------------------------------------------
// Payment history + metadata editor (web). Lets the team amend non-financial
// fields (receiving bank, reference, notes) on an already-recorded payment
// without touching the ledger. Amount / method / date stay locked — those
// still go through the existing void-and-readd flow.
// ---------------------------------------------------------------------------
var _qurbaniHistoryOrderId = null;
var _qurbaniHistoryEditingId = null;

function openQurbaniPaymentsHistoryModal(orderId) {
    _qurbaniHistoryOrderId = orderId;
    _qurbaniHistoryEditingId = null;

    var existing = document.getElementById('qurbaniHistoryOverlay');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'qurbaniHistoryOverlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:10002; display:flex; align-items:center; justify-content:center; padding:20px; overflow-y:auto;';
    overlay.innerHTML = `
        <div style="background:#fff; border-radius:12px; padding:22px; width:640px; max-width:96vw; max-height:92vh; overflow-y:auto; box-shadow:0 8px 30px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <h3 style="margin:0; font-size:17px; font-weight:700; color:#075985;">💳 Payment History</h3>
                <button onclick="document.getElementById('qurbaniHistoryOverlay').remove()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#6b7280;">✕</button>
            </div>
            <div style="font-size:11px; color:#6b7280; margin-bottom:14px;">
                Amount, method and date are locked (use void + re-add if those need changing). You can add the receiving bank, reference or notes later.
            </div>
            <div id="qurbaniHistoryList" style="font-size:13px; color:#374151;">
                <div style="text-align:center; padding:24px; color:#94a3b8;">Loading…</div>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    fetch('/orders/' + orderId + '/qurbani-payments', {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data || !data.success) {
            document.getElementById('qurbaniHistoryList').innerHTML = '<div style="text-align:center; padding:24px; color:#dc2626;">Failed to load</div>';
            return;
        }
        renderQurbaniHistoryList(data.payments || []);
    })
    .catch(function () {
        document.getElementById('qurbaniHistoryList').innerHTML = '<div style="text-align:center; padding:24px; color:#dc2626;">Network error</div>';
    });
}

function renderQurbaniHistoryList(payments) {
    var el = document.getElementById('qurbaniHistoryList');
    if (!el) return;
    if (!payments.length) {
        el.innerHTML = '<div style="text-align:center; padding:24px; color:#94a3b8;">No payments yet.</div>';
        return;
    }

    // Render each payment as a row. If this row is being edited we swap it
    // for an inline edit form (receiving bank chips + reference + notes).
    // Amount / method / date are read-only inside the form too.
    var html = '';
    payments.forEach(function (p) {
        var isEditing = String(_qurbaniHistoryEditingId) === String(p.id);
        var dateStr = p.payment_date ? new Date(p.payment_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '-';
        var methodLabel = (p.payment_method || '').toUpperCase();
        var bankHtml = p.receiving_account_code
            ? '<span style="background:' + (p.receiving_account_color || '#DBEAFE') + '; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600;">' + p.receiving_account_code + '</span>'
            : '<span style="color:#94a3b8; font-size:11px;">— no bank —</span>';

        if (!isEditing) {
            html += '<div style="border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin-bottom:10px;">'
                + '<div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">'
                +   '<div><div style="font-weight:700; color:#111827;">PKR ' + Number(p.amount || 0).toLocaleString() + '</div>'
                +     '<div style="font-size:11px; color:#6b7280;">' + methodLabel + ' • ' + dateStr + '</div></div>'
                +   '<div style="text-align:right;">' + bankHtml + '</div>'
                + '</div>'
                + '<div style="margin-top:6px; font-size:12px; color:#374151;">'
                +   '<div><b>Reference:</b> ' + (p.reference ? escapeHtmlQ(p.reference) : '<span style="color:#94a3b8;">—</span>') + '</div>'
                +   (p.notes ? '<div><b>Notes:</b> ' + escapeHtmlQ(p.notes) + '</div>' : '')
                + '</div>'
                + '<div style="margin-top:8px; display:flex; gap:6px;">'
                +   '<button onclick="editQurbaniHistoryRow(' + p.id + ')" style="padding:4px 10px; background:#0369A1; color:#fff; border:none; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer;">✏️ Edit details</button>'
                + '</div>'
                + '</div>';
        } else {
            var chips = '<button type="button" class="qpay-hist-bank-chip" data-bank-id="" onclick="selectQurbaniHistoryBank(this, null)" '
                + 'style="padding:4px 10px; border-radius:14px; border:1px solid #CBD5E1; background:' + (p.receiving_account_id ? '#fff' : '#3B82F6') + '; color:' + (p.receiving_account_id ? '#334155' : '#fff') + '; font-size:11px; font-weight:600; cursor:pointer;">None</button>';
            (QURBANI_RECEIVING_ACCOUNTS || []).forEach(function (acc) {
                var active = String(acc.id) === String(p.receiving_account_id);
                chips += '<button type="button" class="qpay-hist-bank-chip" data-bank-id="' + acc.id + '" onclick="selectQurbaniHistoryBank(this, ' + acc.id + ')" '
                    + 'style="padding:4px 10px; border-radius:14px; border:1px solid #CBD5E1; background:' + (active ? '#3B82F6' : '#fff') + '; color:' + (active ? '#fff' : '#334155') + '; font-size:11px; font-weight:600; cursor:pointer;">'
                    + (acc.short_code || acc.name) + '</button>';
            });

            var isOnlineMethod = (p.payment_method === 'online');
            html += '<div style="border:1px solid #0EA5E9; border-radius:10px; padding:12px; margin-bottom:10px; background:#F0F9FF;">'
                + '<div style="font-size:11px; color:#6b7280; margin-bottom:8px;">Editing payment of <b>PKR ' + Number(p.amount || 0).toLocaleString() + '</b> (' + methodLabel + ' on ' + dateStr + ')</div>'
                + (isOnlineMethod ? ('<div style="margin-bottom:10px;"><label style="display:block; font-size:12px; font-weight:600; color:#0369A1; margin-bottom:6px;">🏦 Received in Bank</label><div id="qHistBankChips" style="display:flex; flex-wrap:wrap; gap:6px;">' + chips + '</div></div>') : '<div style="font-size:11px; color:#6b7280; margin-bottom:10px;">Cash payment — no receiving bank.</div>')
                + '<div style="margin-bottom:10px;"><label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Reference</label>'
                +   '<input type="text" id="qHistRef" value="' + escapeHtmlQ(p.reference || '') + '" placeholder="e.g. TX-9871231" style="width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>'
                + '<div style="margin-bottom:10px;"><label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Notes</label>'
                +   '<input type="text" id="qHistNotes" value="' + escapeHtmlQ(p.notes || '') + '" placeholder="Notes" style="width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px;"></div>'
                + '<div style="display:flex; gap:8px; justify-content:flex-end;">'
                +   '<button id="qHistCancelBtn" onclick="cancelQurbaniHistoryEdit()" style="padding:7px 14px; background:#f1f5f9; color:#475569; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">Cancel</button>'
                +   '<button id="qHistSaveBtn" onclick="submitQurbaniHistoryEdit(' + p.id + ', \'' + p.payment_method + '\')" style="padding:7px 14px; background:#0369A1; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;">Save</button>'
                + '</div>'
                + '</div>';

            // Seed the in-flight editing bank id from what the server returned
            // so a no-op save doesn't wipe the existing bank.
            _qurbaniHistoryEditingBankId = p.receiving_account_id || null;
        }
    });
    el.innerHTML = html;
}

var _qurbaniHistoryEditingBankId = null;

function escapeHtmlQ(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function editQurbaniHistoryRow(paymentId) {
    _qurbaniHistoryEditingId = paymentId;
    fetch('/orders/' + _qurbaniHistoryOrderId + '/qurbani-payments', {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data && data.success) renderQurbaniHistoryList(data.payments || []);
    });
}

function cancelQurbaniHistoryEdit() {
    _qurbaniHistoryEditingId = null;
    fetch('/orders/' + _qurbaniHistoryOrderId + '/qurbani-payments', {
        method: 'GET',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data && data.success) renderQurbaniHistoryList(data.payments || []);
    });
}

function selectQurbaniHistoryBank(el, bankId) {
    _qurbaniHistoryEditingBankId = bankId || null;
    document.querySelectorAll('.qpay-hist-bank-chip').forEach(function (c) {
        c.style.background = '#fff';
        c.style.color = '#334155';
    });
    el.style.background = '#3B82F6';
    el.style.color = '#fff';
}

function submitQurbaniHistoryEdit(paymentId, paymentMethod) {
    var btn = document.getElementById('qHistSaveBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

    var payload = {
        reference: (document.getElementById('qHistRef') && document.getElementById('qHistRef').value) || null,
        notes:     (document.getElementById('qHistNotes') && document.getElementById('qHistNotes').value) || null,
    };
    // Only send receiving_account_id for online payments; the server would
    // ignore it for cash anyway but keeping the payload tight makes logs
    // easier to read.
    if (paymentMethod === 'online') {
        payload.receiving_account_id = _qurbaniHistoryEditingBankId || null;
    }

    fetch('/orders/' + _qurbaniHistoryOrderId + '/qurbani-payments/' + paymentId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify(payload),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data && data.success) {
            _qurbaniHistoryEditingId = null;
            // Reload the list so the saved row renders in read mode.
            fetch('/orders/' + _qurbaniHistoryOrderId + '/qurbani-payments', {
                method: 'GET',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) renderQurbaniHistoryList(d.payments || []);
            });
        } else {
            alert((data && data.message) ? (typeof data.message === 'string' ? data.message : 'Validation failed') : 'Failed to save');
            if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
        }
    })
    .catch(function () {
        alert('Network error');
        if (btn) { btn.disabled = false; btn.textContent = 'Save'; }
    });
}

function submitQurbaniPayment() {
    const amount = parseFloat(document.getElementById('qPayAmount').value);
    if (!amount || amount <= 0) { alert('Enter a valid amount'); return; }

    const method = document.getElementById('qPayMethod').value;
    // Receiving bank is mandatory for online payments — this keeps the
    // ledger clean so finance always knows which account received the
    // money. Server-side the column is still nullable (for historical
    // rows + cash payments), so we enforce here at the UI boundary.
    if (method === 'online' && !_qurbaniPaymentReceivingId) {
        alert('Please select the receiving bank for this online payment.');
        return;
    }

    const btn = document.getElementById('qPaySubmitBtn');
    btn.disabled = true; btn.textContent = 'Saving...';
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

// Non-blocking Qurbani-invoice send (Apr-2026). Mirrors the regular
// orders page rewrite — closes the dialog right away, fires the
// actual send in the background, and surfaces a sticky red banner if
// it fails so the operator can keep working without the WhatsApp API
// holding the page hostage. See orders/index.blade.php for the
// regular-orders equivalent.
function sendWhatsAppInvoice() {
    var phone = document.getElementById('waInvPhone').value.trim();
    var templateName = document.getElementById('waTemplateName').value.trim();
    var bodyParamsStr = document.getElementById('waBodyParams').value.trim();
    if (!phone) { alert('Please enter a phone number'); return; }
    if (!templateName) { alert('Please enter the template name'); return; }
    var params = bodyParamsStr ? bodyParamsStr.split(',').map(function(s) { return s.trim(); }) : [];

    // Snapshot dialog state before we tear it down — currentOrderId is
    // shared across other modals on the page and would have flipped
    // by the time the fetch settles.
    var orderIdSnap = qurWaCurrentOrderId;
    var labelCustomer = (params[0] || '').trim() || phone;
    var labelOrder    = (params[1] || '').trim() || ('#' + orderIdSnap);

    var overlay = document.getElementById('waInvoiceOverlay');
    if (overlay) overlay.remove();
    qurShowSendToast('Sending invoice ' + labelOrder + ' to ' + labelCustomer + '...', 'info');

    var previewReady = document.getElementById('waInvPreviewArea')?.style.display === 'block';
    var ensureReady = previewReady
        ? Promise.resolve()
        : new Promise(function(resolve) {
            fetch('/messages/invoice-image/' + orderIdSnap, { headers: {'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json'} })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.success && d.needs_capture) {
                        return captureQurbaniInvoiceImage(d.invoice_url, orderIdSnap).then(function() { resolve(); }).catch(function() { resolve(); });
                    }
                    resolve();
                })
                .catch(function() { resolve(); });
        });

    ensureReady.then(function() {
        return fetch('/messages/send-invoice', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ order_id: orderIdSnap, phone: phone, template_name: templateName, body_params: params }),
        });
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            qurShowSendToast('Invoice ' + labelOrder + ' sent to ' + labelCustomer, 'success');
        } else {
            qurShowSendFailure(labelOrder, labelCustomer, data.message || 'Failed to send', orderIdSnap);
        }
    })
    .catch(function(e) {
        qurShowSendFailure(labelOrder, labelCustomer, e.message || 'Network error', orderIdSnap);
    });
}

// Lightweight toast for the Qurbani orders page (no global helper here).
// Auto-dismisses after 3s. Stacks bottom-right so it doesn't overlap
// the failure banners pinned to the top-right.
function qurShowSendToast(msg, kind) {
    var toast = document.createElement('div');
    var bg = kind === 'success' ? '#10b981' : (kind === 'info' ? '#0ea5e9' : '#ef4444');
    toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:' + bg + ';color:#fff;padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:10000;font-size:13px;font-weight:500;max-width:360px;';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3000);
}

// Sticky failure banner — same contract as the orders/index.blade.php
// equivalent. One banner per (order, customer) pair; subsequent
// failures for the same order replace the existing entry rather than
// stacking duplicates.
function qurShowSendFailure(orderLabel, customerLabel, reason, orderId) {
    var host = document.getElementById('qurInvFailureStack');
    if (!host) {
        host = document.createElement('div');
        host.id = 'qurInvFailureStack';
        host.style.cssText = 'position:fixed;top:80px;right:20px;z-index:10001;display:flex;flex-direction:column;gap:8px;max-width:380px;';
        document.body.appendChild(host);
    }
    var key = 'fail-' + (orderId || orderLabel);
    var banner = host.querySelector('[data-key="' + key + '"]');
    if (!banner) {
        banner = document.createElement('div');
        banner.dataset.key = key;
        banner.style.cssText = 'background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;padding:12px 14px;border-radius:8px;box-shadow:0 4px 12px rgba(220,38,38,0.15);font-size:13px;color:#991b1b;line-height:1.4;';
        host.appendChild(banner);
    }
    var safe = function(s) { return String(s).replace(/[&<>"]/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); };
    banner.innerHTML =
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">' +
            '<div style="font-weight:700;color:#991b1b;">⚠ Qurbani invoice send failed</div>' +
            '<button onclick="this.closest(\'[data-key]\').remove()" style="background:none;border:none;color:#991b1b;cursor:pointer;font-size:18px;line-height:1;padding:0;margin:-4px -4px 0 0;">&times;</button>' +
        '</div>' +
        '<div style="margin-top:6px;color:#7f1d1d;">Order <strong>' + safe(orderLabel) + '</strong> · Customer <strong>' + safe(customerLabel) + '</strong></div>' +
        '<div style="margin-top:6px;color:#7f1d1d;font-size:12px;">' + safe(reason) + '</div>';
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
    const labels = { qurbani_day: 'Qurbani Day', qurbani_slot: 'Qurbani Slot', qurbani_region: 'Qurbani Region', qurbani_sub_region: 'Sub Region', qurbani_delivery_type: 'Delivery Type', qurbani_type: 'Qurbani Type', qurbani_paya: 'Paya' };
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
// Apr-2026: target-editing state. When a user clicks "Set Targets" on a
// specific delivery-type card we switch that card (and only that card) into
// edit mode. The key is the delivery_type string; value is true while
// editing. Breakdown edit state is tracked separately per mini-table key
// "<dt>||<day>||<cat>" so we can have at most one breakdown table in edit
// mode at a time without forcing all of them into inputs at once.
const qStatsSummaryEditing = new Set();
let qStatsBreakdownEditingKey = null;

const QSTATS_COLLAPSED_KEY = 'qurbani_stats_collapsed_v1';

// Apr-2026: per-card per-day expand state for the new axis-swapped layout.
// Keyed as "<deliveryType>||<day>". A day is collapsed by default — only
// the totals row shows — and clicking the day row reveals nested slot
// sub-rows. Persisted in-memory (resets on full reload) which keeps the
// initial render compact (the user explicitly asked for the compact view
// to remain available).
const qStatsExpandedDays = new Set();
function qstatsDayKey(dt, day) { return String(dt) + '||' + String(day); }
window.qstatsToggleDayExpand = function(dt, day) {
    const k = qstatsDayKey(dt, day);
    if (qStatsExpandedDays.has(k)) qStatsExpandedDays.delete(k);
    else qStatsExpandedDays.add(k);
    renderQurbaniSummary();
};

// Apr-2026: bulk toggle. "Expand All" reveals every day's slot
// sub-rows for one delivery-type card; "Collapse All" hides them.
// We compute the current state by counting how many of the card's
// days are already expanded — if at least one is collapsed, the
// button reads "Expand All"; if all are expanded, it reads
// "Collapse All". This means a single click always lands the user
// in the obviously-useful next state.
function qstatsCardDays(deliveryType) {
    if (!qStatsData) return [];
    const detailForDt = (qStatsData.detail || {})[deliveryType] || {};
    const used = Object.keys(detailForDt);
    if (used.length > 0) return used;
    // Fallback to the global day list when the card has no data yet
    // (e.g. Delivery card before any orders exist for that type).
    return qStatsData.days || [];
}
function qstatsCardAllExpanded(deliveryType) {
    const days = qstatsCardDays(deliveryType);
    if (days.length === 0) return false;
    return days.every(d => qStatsExpandedDays.has(qstatsDayKey(deliveryType, d)));
}
window.qstatsToggleCardExpandAll = function(deliveryType) {
    const days = qstatsCardDays(deliveryType);
    const allExpanded = qstatsCardAllExpanded(deliveryType);
    days.forEach(d => {
        const k = qstatsDayKey(deliveryType, d);
        if (allExpanded) qStatsExpandedDays.delete(k);
        else qStatsExpandedDays.add(k);
    });
    renderQurbaniSummary();
};

// Apr-2026 Phase 2: clicking a summary cell drives the orders-table
// filters below. Each cell carries data attributes naming the
// (deliveryType, day, category, slot) tuple it represents; this
// helper applies them to the existing filter <select>s in the
// "Filters Row" panel and triggers loadOrders().
//
// Empty / undefined values are treated as "clear that filter".
// 'Unassigned' values are also treated as clear because:
//   1. Filter <select>s are populated from t_crm_qurbani_field_options
//      and don't carry an "Unassigned" option (the COALESCE label
//      only appears in the *aggregate* SQL, never in the option
//      table). Setting select.value = 'Unassigned' silently fails
//      and the dropdown reverts to its previous value, leaving the
//      user with a stale filter — confusing.
//   2. Filtering by literal 'Unassigned' on the backend would also
//      require a special whereNull/whereEmpty branch that doesn't
//      exist today. Keeping that out of scope for this Phase 2.
// We surface a small toast in this case so the user understands why
// nothing happened and can navigate manually if needed.
function qstatsApplyFilterValue(selId, val) {
    const sel = document.getElementById(selId);
    if (!sel) return false;
    const isUnassigned = (val === 'Unassigned' || val === 'Uncategorized');
    const target = (val === undefined || val === null || isUnassigned) ? '' : String(val);
    // Only set if the option exists; otherwise we'd silently mis-filter.
    if (target !== '') {
        const opt = Array.prototype.find.call(sel.options, o => o.value === target);
        if (!opt) return false;
    }
    sel.value = target;
    return true;
}
function qstatsToast(msg) {
    let host = document.getElementById('qstatsToastHost');
    if (!host) {
        host = document.createElement('div');
        host.id = 'qstatsToastHost';
        host.style.cssText = 'position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:8px;';
        document.body.appendChild(host);
    }
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'background:#1f2937; color:#fff; padding:10px 14px; border-radius:8px; font-size:12px; max-width:340px; box-shadow:0 8px 20px rgba(0,0,0,0.18); opacity:0; transform:translateY(-6px); transition:all .2s ease;';
    host.appendChild(t);
    requestAnimationFrame(() => { t.style.opacity = '1'; t.style.transform = 'translateY(0)'; });
    setTimeout(() => {
        t.style.opacity = '0'; t.style.transform = 'translateY(-6px)';
        setTimeout(() => { try { host.removeChild(t); } catch(_){} }, 250);
    }, 3500);
}
window.qstatsApplyFilter = function(opts) {
    const o = opts || {};
    const had = { dt: false, day: false, cat: false, slot: false };
    const skipped = [];

    if (o.deliveryType !== undefined) {
        had.dt = qstatsApplyFilterValue('filterDeliveryType', o.deliveryType);
        if (!had.dt && o.deliveryType && o.deliveryType !== 'Unassigned') skipped.push('delivery type');
    }
    if (o.day !== undefined) {
        had.day = qstatsApplyFilterValue('filterDay', o.day);
        if (!had.day && o.day && o.day !== 'Unassigned') skipped.push('day');
    }
    if (o.category !== undefined) {
        had.cat = qstatsApplyFilterValue('filterCategory', o.category);
        if (!had.cat && o.category && o.category !== 'Uncategorized') skipped.push('category');
        // Also keep the "category cards" tab strip in sync.
        if (typeof activeCategoryFilter !== 'undefined') {
            try { activeCategoryFilter = (o.category && o.category !== 'Uncategorized') ? o.category : null; } catch(_){}
        }
    }
    if (o.slot !== undefined) {
        // Slot dropdown is a dependent filter: its options are pruned
        // based on the currently-selected day + delivery_type. We have
        // to rebuild the option list FIRST so the slot value we want
        // to set is actually in the dropdown.
        if (typeof updateSlotDropdown === 'function') updateSlotDropdown();
        had.slot = qstatsApplyFilterValue('filterSlot', o.slot);
        if (!had.slot && o.slot && o.slot !== 'Unassigned') skipped.push('slot');
    } else {
        // Clear slot when day/dt change so we don't carry over an
        // incompatible slot from a previous click.
        const slotSel = document.getElementById('filterSlot');
        if (slotSel) slotSel.value = '';
        if (typeof updateSlotDropdown === 'function') updateSlotDropdown();
    }

    // Region / sub-region / payment / qurbani_type / paya are NOT touched
    // by summary clicks — keeping those independent so the user can stack
    // a region filter on top of a "Day 1 / Cow Share" cell click.

    // Inform the user when an Unassigned bucket was clicked.
    const unassignedHits = [];
    if (o.day === 'Unassigned') unassignedHits.push('day');
    if (o.deliveryType === 'Unassigned') unassignedHits.push('delivery type');
    if (o.category === 'Uncategorized') unassignedHits.push('category');
    if (o.slot === 'Unassigned') unassignedHits.push('slot');
    if (unassignedHits.length > 0) {
        qstatsToast('Unassigned ' + unassignedHits.join(', ') + ' cannot be filtered directly. Showing closest match — please assign these line items at the order level.');
    }

    if (typeof loadOrders === 'function') loadOrders();

    // Smooth-scroll the orders list into view so the user immediately
    // sees the result of their filter click.
    setTimeout(() => {
        const target = document.getElementById('ordersTableContainer')
            || document.getElementById('ordersTable')
            || document.getElementById('ordersList')
            || document.querySelector('#ordersContent .bg-white.border.border-gray-200.rounded-lg');
        if (target && target.scrollIntoView) {
            try { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch(_){}
        }
    }, 60);
};

// Helper: shape the "booked / target" cell content with color hinting.
// When no target is set we render only the booked count, so cards remain
// clean on fresh installs that haven't configured caps yet.
function qstatsFormatCell(booked, target) {
    const t = parseInt(target) || 0;
    const b = parseInt(booked) || 0;
    if (t <= 0) {
        return b === 0
            ? `<span class="zero">0</span>`
            : `<span>${b}</span>`;
    }
    let cls = 'qstats-cell-ok';
    const pct = t > 0 ? (b / t) * 100 : 0;
    if (pct > 100) cls = 'qstats-cell-over';
    else if (pct >= 90) cls = 'qstats-cell-near';
    return `<span class="${cls}">${b}</span><span class="qstats-cell-target">/${t}</span>`;
}

// Helper: shape an "edit-mode" input cell. Pre-filled with the current
// target; empty / 0 means "no target". Uses data attributes so we can
// serialize all inputs in one pass when the user hits Save.
function qstatsEditCell(dt, day, cat, booked, target, slot, region) {
    const val = (parseInt(target) || 0) === 0 ? '' : String(parseInt(target));
    const safeAttrs =
        `data-dt="${qstatsEscape(dt)}" ` +
        `data-day="${qstatsEscape(day)}" ` +
        `data-cat="${qstatsEscape(cat)}" ` +
        `data-slot="${qstatsEscape(slot || '')}" ` +
        `data-region="${qstatsEscape(region || '')}"`;
    return `
        <div style="display:flex; flex-direction:column; align-items:center; gap:2px;">
            <input type="number" min="0" step="1" class="qstats-target-input" ${safeAttrs}
                   value="${qstatsEscape(val)}" placeholder="-">
            <span style="font-size:9px; color:#9ca3af;">booked ${parseInt(booked) || 0}</span>
        </div>
    `;
}

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
    // Apr-2026 layout swap.
    //   X-axis (columns)        : categories (Cow Share, Goat, Lamb, ...).
    //   Y-axis (rows)           : days (Day 1, Day 2, ..., Unassigned).
    //   Nested rows under day   : slots (Morning, Afternoon, ...) with
    //                             per-(day, slot, category) booked counts.
    //                             Sourced by aggregating the existing
    //                             detail[dt][day][cat]['cell'][slot][region]
    //                             map across regions (since this card is
    //                             slot-not-region focused — the breakdown
    //                             view still does region drill-down).
    //   Always visible          : day rows (with totals) + final Total row,
    //                             so a collapsed card still reports the
    //                             headline numbers per day.
    //   Click on day row label  : toggles its slot sub-rows.
    //   Targets edit mode       : applies ONLY to the day row cells (i.e.
    //                             summary-level (dt, day, cat) targets).
    //                             Slot-level targets are still managed via
    //                             the breakdown view (unchanged) since
    //                             those carry an extra region dimension.
    //
    // Targets live on the server response. Falls back to empty maps if the
    // migration hasn't been run yet (backend returns {} in that case).
    const allTargets = ((qStatsData && qStatsData.targets) || {}).summary || {};
    const byDayCatTarget = allTargets[deliveryType] || {};
    const isEditing = qStatsSummaryEditing.has(deliveryType);
    // Pre-declare the JS-arg-safe deliveryType string. We need this BEFORE
    // building the table HTML below because the bottom Total row's click
    // handlers reference it. (Apr-2026: previously this was declared
    // further down with the footer actions, which made `dtJs` hit the
    // const TDZ when the totals row was being constructed and silently
    // killed the whole summary render.)
    const dtJs = qstatsEscape(deliveryType).replace(/'/g, "\\'");

    // Pull the global slot order from the API. We ALSO re-derive the
    // slots actually used by THIS delivery type so the card only lists
    // slots that are relevant to it (e.g. if Self-Collection has no
    // 'Afternoon' bookings, we don't render an empty Afternoon row
    // under every day on the Self-Collection card).
    const globalSlots = (qStatsData && Array.isArray(qStatsData.slots)) ? qStatsData.slots : [];
    const detailForDt = ((qStatsData && qStatsData.detail) || {})[deliveryType] || {};
    const slotsUsedSet = {};
    Object.keys(detailForDt).forEach(day => {
        const catBlobs = detailForDt[day] || {};
        Object.keys(catBlobs).forEach(cat => {
            const cellMap = (catBlobs[cat] && catBlobs[cat].cell) || {};
            Object.keys(cellMap).forEach(slot => { slotsUsedSet[slot] = true; });
        });
    });
    // Preserve global ordering, fall back to whatever order we discovered.
    const slotsForCard = globalSlots.filter(s => slotsUsedSet[s])
        .concat(Object.keys(slotsUsedSet).filter(s => globalSlots.indexOf(s) < 0));

    // byDaySlotCat[day][slot][cat] = qty — built from detail by summing
    // across regions for the (day, slot, cat) triple.
    const byDaySlotCat = {};
    Object.keys(detailForDt).forEach(day => {
        byDaySlotCat[day] = byDaySlotCat[day] || {};
        const catBlobs = detailForDt[day] || {};
        Object.keys(catBlobs).forEach(cat => {
            const cellMap = (catBlobs[cat] && catBlobs[cat].cell) || {};
            Object.keys(cellMap).forEach(slot => {
                const regionMap = cellMap[slot] || {};
                let q = 0;
                Object.keys(regionMap).forEach(rg => { q += parseInt(regionMap[rg]) || 0; });
                if (!byDaySlotCat[day][slot]) byDaySlotCat[day][slot] = {};
                byDaySlotCat[day][slot][cat] = (byDaySlotCat[day][slot][cat] || 0) + q;
            });
        });
    });

    // Column totals = total per category (sum across days).
    const catColTotals = {};
    categories.forEach(c => { catColTotals[c] = 0; });
    let grandTotal = 0;
    const dayRows = days.map(day => {
        let dayTotal = 0;
        const catCells = categories.map(cat => {
            const qty = ((byDayCat[day] || {})[cat]) || 0;
            const tgt = ((byDayCatTarget[day] || {})[cat]) || 0;
            dayTotal += qty;
            catColTotals[cat] = (catColTotals[cat] || 0) + qty;
            return { qty, tgt, cat };
        });
        grandTotal += dayTotal;
        return { day, catCells, dayTotal };
    });

    // When editing we always show the full grid so admins can set targets
    // for (day, category) pairs that currently have 0 bookings. Slot
    // sub-rows are suppressed during editing — admins are setting
    // day×category caps, not slot caps.
    const showEmpty = isEditing;
    let tableHtml;
    if (grandTotal === 0 && !showEmpty) {
        tableHtml = '<div class="qstats-empty">No bookings yet for this type.</div>';
    } else {
        // Build day-row block (day row + optional slot sub-rows).
        const buildDayBlock = (r) => {
            const dayKey = qstatsDayKey(deliveryType, r.day);
            const isExpanded = !isEditing && qStatsExpandedDays.has(dayKey);
            const dayJsArg = qstatsEscape(deliveryType).replace(/'/g, "\\'");
            const dayValueJsArg = qstatsEscape(r.day).replace(/'/g, "\\'");

            // Slot sub-rows are only rendered when expanded AND not editing.
            // They show qty per category for that (day, slot) combo. Cells
            // are read-only here — slot-level targets stay in the breakdown
            // view since they carry an extra region dimension.
            let slotRowsHtml = '';
            if (isExpanded && slotsForCard.length > 0) {
                slotRowsHtml = slotsForCard.map(slot => {
                    const slotCatMap = (byDaySlotCat[r.day] || {})[slot] || {};
                    let slotTotal = 0;
                    const slotCells = categories.map(cat => {
                        const q = parseInt(slotCatMap[cat] || 0) || 0;
                        slotTotal += q;
                        return q;
                    });
                    if (slotTotal === 0) return '';
                    const slotJsArg = qstatsEscape(slot).replace(/'/g, "\\'");
                    const slotCellsHtml = categories.map((cat, idx) => {
                        const q = slotCells[idx];
                        const catJsArg = qstatsEscape(cat).replace(/'/g, "\\'");
                        const cls = q === 0 ? 'zero filterable' : 'filterable';
                        const click = q === 0
                            ? ''
                            : `onclick="qstatsApplyFilter({deliveryType:'${dayJsArg}', day:'${dayValueJsArg}', category:'${catJsArg}', slot:'${slotJsArg}'})"`;
                        const cellTitle = q === 0
                            ? ''
                            : `title="Filter orders to ${qstatsEscape(deliveryType)} · ${qstatsEscape(r.day)} · ${qstatsEscape(slot)} · ${qstatsEscape(cat)}"`;
                        return `<td class="${cls}" ${click} ${cellTitle}>${q === 0 ? '<span class="zero">0</span>' : q}</td>`;
                    }).join('');
                    // Slot-row Total cell → filter by (dt, day, slot), category cleared.
                    const slotTotalClick = `onclick="qstatsApplyFilter({deliveryType:'${dayJsArg}', day:'${dayValueJsArg}', slot:'${slotJsArg}', category:''})"`;
                    return `
                        <tr class="slot-row">
                            <td class="row-label" title="Slot under ${qstatsEscape(r.day)}">↳ ${qstatsEscape(slot)}</td>
                            ${slotCellsHtml}
                            <td class="total-col filterable" ${slotTotalClick} title="Filter orders to ${qstatsEscape(deliveryType)} · ${qstatsEscape(r.day)} · ${qstatsEscape(slot)} (any category)">${slotTotal}</td>
                        </tr>
                    `;
                }).join('');
            }

            const chev = isEditing ? '' : `<span class="chev">${isExpanded ? '▾' : '▸'}</span>`;
            // Day-row label still toggles expansion (unchanged). Cell click
            // is for filtering — they live in different DOM nodes so there's
            // no event-handler conflict.
            const onClickAttr = isEditing
                ? ''
                : `onclick="qstatsToggleDayExpand('${dayJsArg}','${dayValueJsArg}')"`;

            // Day-row category cells. In view mode each cell is filterable
            // (sets dt + day + cat, clears slot). Edit mode keeps the
            // input rendering — no filter clicks while editing targets.
            const dayCatCellsHtml = r.catCells.map(c => {
                if (isEditing) {
                    return `<td>${qstatsEditCell(deliveryType, r.day, c.cat, c.qty, c.tgt, '', '')}</td>`;
                }
                const catJsArg = qstatsEscape(c.cat).replace(/'/g, "\\'");
                const isZero = c.qty === 0 && c.tgt === 0;
                const cls = isZero ? 'zero filterable' : 'filterable';
                const click = isZero
                    ? ''
                    : `onclick="qstatsApplyFilter({deliveryType:'${dayJsArg}', day:'${dayValueJsArg}', category:'${catJsArg}', slot:''})"`;
                const titleAttr = isZero
                    ? ''
                    : `title="Filter orders to ${qstatsEscape(deliveryType)} · ${qstatsEscape(r.day)} · ${qstatsEscape(c.cat)}"`;
                return `<td class="${cls}" ${click} ${titleAttr}>${qstatsFormatCell(c.qty, c.tgt)}</td>`;
            }).join('');

            // Day-row Total cell → filter by (dt, day) only, category & slot cleared.
            const dayTotalClick = isEditing
                ? ''
                : `onclick="qstatsApplyFilter({deliveryType:'${dayJsArg}', day:'${dayValueJsArg}', category:'', slot:''})"`;
            const dayTotalCls = isEditing ? 'total-col' : 'total-col filterable';

            return `
                <tr class="day-row${isExpanded ? ' expanded' : ''}">
                    <td class="row-label" ${onClickAttr}>${chev}${qstatsEscape(r.day)}</td>
                    ${dayCatCellsHtml}
                    <td class="${dayTotalCls}" ${dayTotalClick} ${isEditing ? '' : `title="Filter orders to ${qstatsEscape(deliveryType)} · ${qstatsEscape(r.day)} (any category)"`}>${r.dayTotal}</td>
                </tr>
                ${slotRowsHtml}
            `;
        };

        // Bottom Total row: clicking a category column total filters
        // by (dt, category) across all days. Clicking the grand total
        // clears day/category/slot and just narrows by delivery type.
        // Edit mode disables filter clicks across the whole card.
        const totalRowCellsHtml = categories.map(c => {
            const v = catColTotals[c] || 0;
            if (isEditing) return `<td>${v}</td>`;
            const catJsArg = qstatsEscape(c).replace(/'/g, "\\'");
            const cls = v === 0 ? '' : 'filterable';
            const click = v === 0
                ? ''
                : `onclick="qstatsApplyFilter({deliveryType:'${dtJs}', day:'', category:'${catJsArg}', slot:''})"`;
            const titleAttr = v === 0
                ? ''
                : `title="Filter orders to ${qstatsEscape(deliveryType)} · ${qstatsEscape(c)} (all days)"`;
            return `<td class="${cls}" ${click} ${titleAttr}>${v}</td>`;
        }).join('');
        const grandTotalCls = isEditing ? '' : 'filterable';
        const grandTotalClick = isEditing
            ? ''
            : `onclick="qstatsApplyFilter({deliveryType:'${dtJs}', day:'', category:'', slot:''})"`;
        const grandTotalTitle = isEditing
            ? ''
            : `title="Filter orders to ${qstatsEscape(deliveryType)} (all days, all categories)"`;

        tableHtml = `
            <table class="qstats-table" data-card-dt="${qstatsEscape(deliveryType)}">
                <thead>
                    <tr>
                        <th></th>
                        ${categories.map(c => `<th>${qstatsEscape(c)}</th>`).join('')}
                        <th class="total-col">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${dayRows.map(buildDayBlock).join('')}
                    <tr class="total-row">
                        <td class="row-label">Total</td>
                        ${totalRowCellsHtml}
                        <td class="${grandTotalCls}" ${grandTotalClick} ${grandTotalTitle}>${grandTotal}</td>
                    </tr>
                </tbody>
            </table>
        `;
    }

    const dotColor = qstatsDotColor(deliveryType);
    // Footer actions change based on mode: view mode offers breakdown/set,
    // edit mode offers save/cancel. Kept identically structured so the
    // card height barely shifts when toggling. (`dtJs` is declared at the
    // top of this function — needed earlier for the totals row click
    // handlers; see comment there.)
    // Expand-all toggle label flips based on current state. Only render
    // the button when there's at least one slot worth showing — otherwise
    // it would be a no-op control that just confuses the user.
    const allExpanded = qstatsCardAllExpanded(deliveryType);
    const expandBtnHtml = (slotsForCard.length > 0 && grandTotal > 0)
        ? `<button class="qstats-ghostbtn" onclick="qstatsToggleCardExpandAll('${dtJs}')" title="Show or hide slot rows for every day">${allExpanded ? '▾ Collapse All' : '▸ Expand All'}</button>`
        : '';
    const actionsHtml = isEditing
        ? `
            <button class="qstats-cancelbtn" onclick="qstatsCancelSummaryEdit('${dtJs}')">Cancel</button>
            <button class="qstats-savebtn"   onclick="qstatsSaveSummaryEdit('${dtJs}')">Save Targets</button>
        `
        : `
            ${expandBtnHtml}
            ${isTaimurStrict ? `<button class="qstats-ghostbtn secondary" onclick="qstatsStartSummaryEdit('${dtJs}')">🎯 Set Targets</button>` : ''}
            <button class="qstats-ghostbtn" onclick="renderQurbaniBreakdown('${dtJs}')" ${grandTotal === 0 ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''}>View breakdown &rarr;</button>
        `;

    return `
        <div class="qstats-card" data-dt="${qstatsEscape(deliveryType)}">
            <div class="qstats-card-head">
                <div class="qstats-card-title">
                    <span class="dot" style="background:${dotColor};"></span>
                    <span>${qstatsEscape(deliveryType)}</span>
                    ${isEditing ? '<span class="qstats-badge" style="background:#fef3c7; color:#92400e;">Editing targets</span>' : ''}
                </div>
                <div class="qstats-card-total">Total <b>${grandTotal}</b></div>
            </div>
            ${tableHtml}
            <div class="qstats-card-actions" style="justify-content:flex-end; margin-top:8px;">
                ${actionsHtml}
            </div>
        </div>
    `;
}

function qstatsStartSummaryEdit(deliveryType) {
    qStatsSummaryEditing.add(deliveryType);
    renderQurbaniSummary();
}

function qstatsCancelSummaryEdit(deliveryType) {
    qStatsSummaryEditing.delete(deliveryType);
    renderQurbaniSummary();
}

function qstatsSaveSummaryEdit(deliveryType) {
    // Grab every input belonging to this delivery-type card. We scoped the
    // inputs by data-dt so Delivery/Self-Collection cards can be edited and
    // saved independently.
    const inputs = document.querySelectorAll(`table[data-card-dt="${CSS.escape(deliveryType)}"] input.qstats-target-input`);
    const entries = [];
    inputs.forEach(inp => {
        const raw = (inp.value || '').trim();
        const qty = raw === '' ? 0 : Math.max(0, parseInt(raw) || 0);
        entries.push({
            delivery_type: inp.getAttribute('data-dt'),
            day:           inp.getAttribute('data-day'),
            category:      inp.getAttribute('data-cat'),
            slot:          inp.getAttribute('data-slot') || '',
            region:        inp.getAttribute('data-region') || '',
            target_qty:    qty,
        });
    });
    qstatsPostTargets('summary', entries, () => {
        qStatsSummaryEditing.delete(deliveryType);
        loadQurbaniStats();
    });
}

// Posts target rows to the backend and refreshes on success. Shared between
// summary-card and breakdown-mini-table saves so the behaviour stays
// consistent (toast-free; errors surface via alert).
function qstatsPostTargets(level, entries, onSuccess) {
    // Reuse the top-level csrfToken already hydrated from the meta tag.
    return fetch('/qurbani/api/targets', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ level, entries }),
    })
    .then(r => r.json())
    .then(d => {
        if (!d || !d.success) throw new Error(d && d.message ? d.message : 'Failed to save targets');
        if (typeof onSuccess === 'function') onSuccess(d);
    })
    .catch(err => {
        alert((err && err.message) || 'Could not save targets. Please try again.');
    });
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
    // Breakdown-level targets: targets.breakdown[dt][day][cat][slot][region].
    // Falls back to empty {} when absent.
    const bdTargetsAll = ((qStatsData && qStatsData.targets) || {}).breakdown || {};
    const bdTargetsType = bdTargetsAll[deliveryType] || {};

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

            const targetsForCell = ((bdTargetsType[day] || {})[cat]) || {};
            const editKey = `${deliveryType}||${day}||${cat}`;
            const isEditing = qStatsBreakdownEditingKey === editKey;

            const rowsHtml = slots.map(slot => {
                const cellsHtml = regions.map(region => {
                    const v = ((cellMap[slot] || {})[region]) || 0;
                    const t = ((targetsForCell[slot] || {})[region]) || 0;
                    if (isEditing) {
                        return `<td>${qstatsEditCell(deliveryType, day, cat, v, t, slot, region)}</td>`;
                    }
                    return `<td class="${v === 0 && t === 0 ? 'zero' : ''}">${qstatsFormatCell(v, t)}</td>`;
                }).join('');
                return `<tr><td class="row-label">${qstatsEscape(slot)}</td>${cellsHtml}</tr>`;
            }).join('');

            // Each mini-table gets its own Set / Save / Cancel toolbar so
            // admins can focus on one (day × category) at a time.
            const dtJs  = qstatsEscape(deliveryType).replace(/'/g, "\\'");
            const dayJs = qstatsEscape(day).replace(/'/g, "\\'");
            const catJs = qstatsEscape(cat).replace(/'/g, "\\'");
            const toolbarHtml = isEditing
                ? `
                    <button class="qstats-cancelbtn" onclick="qstatsCancelBreakdownEdit()">Cancel</button>
                    <button class="qstats-savebtn"   onclick="qstatsSaveBreakdownEdit('${dtJs}','${dayJs}','${catJs}')">Save</button>
                `
                : (isTaimurStrict
                    ? `<button class="qstats-ghostbtn secondary" style="font-size:10px; padding:2px 8px;" onclick="qstatsStartBreakdownEdit('${dtJs}','${dayJs}','${catJs}')">🎯 Set Targets</button>`
                    : '');

            miniHtml.push(`
                <div class="qstats-mini" data-edit-key="${qstatsEscape(editKey)}">
                    <div class="qstats-mini-head">
                        <div class="qstats-mini-title">${qstatsEscape(day)} &middot; ${qstatsEscape(cat)}${isEditing ? ' <span style="color:#d97706; font-weight:600; font-size:10px;">(editing)</span>' : ''}</div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <div class="qstats-mini-total">${qtyTotal}</div>
                            ${toolbarHtml}
                        </div>
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

function qstatsStartBreakdownEdit(deliveryType, day, cat) {
    qStatsBreakdownEditingKey = `${deliveryType}||${day}||${cat}`;
    renderQurbaniBreakdown(deliveryType);
}

function qstatsCancelBreakdownEdit() {
    const prev = qStatsBreakdownEditingKey;
    qStatsBreakdownEditingKey = null;
    if (prev) {
        const dt = prev.split('||')[0];
        renderQurbaniBreakdown(dt);
    }
}

function qstatsSaveBreakdownEdit(deliveryType, day, cat) {
    const editKey = `${deliveryType}||${day}||${cat}`;
    const container = document.querySelector(`[data-edit-key="${CSS.escape(editKey)}"]`);
    if (!container) { qStatsBreakdownEditingKey = null; renderQurbaniBreakdown(deliveryType); return; }
    const inputs = container.querySelectorAll('input.qstats-target-input');
    const entries = [];
    inputs.forEach(inp => {
        const raw = (inp.value || '').trim();
        const qty = raw === '' ? 0 : Math.max(0, parseInt(raw) || 0);
        entries.push({
            delivery_type: inp.getAttribute('data-dt'),
            day:           inp.getAttribute('data-day'),
            category:      inp.getAttribute('data-cat'),
            slot:          inp.getAttribute('data-slot') || '',
            region:        inp.getAttribute('data-region') || '',
            target_qty:    qty,
        });
    });
    qstatsPostTargets('breakdown', entries, () => {
        qStatsBreakdownEditingKey = null;
        loadQurbaniStats();
    });
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
