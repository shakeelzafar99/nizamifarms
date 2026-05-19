@extends('layouts.app')

@section('title', 'Qurbani Orders')

@push('custom_css')
<style>
.qo-filter { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white; min-width: 110px; }
.qo-filter:focus { outline: none; border-color: #d97706; box-shadow: 0 0 0 2px rgba(217,119,6,.15); }
/* Dependent filters get an amber side-stripe so the user knows their
   options are narrowed by another filter (Slot depends on Day×DeliveryType,
   Sub-Region depends on the active Region chip). Same convention as the
   invoices page. */
.qo-filter.filter-dependent { border-left: 3px solid #FBBF24; background: #FFFBEB; }
.qo-filter.filter-dependent:focus { background: #FFF; }

/* Region quick-filter chips */
.qo-region-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.qo-region-chip { padding: 5px 12px; border-radius: 16px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #6b7280; user-select: none; transition: all 0.15s; }
.qo-region-chip:hover { background: #f9fafb; border-color: #d1d5db; }
.qo-region-chip.active { background: #d97706; border-color: #d97706; color: #fff; }
.qo-region-chip .count { display: inline-block; margin-left: 4px; font-size: 11px; opacity: 0.8; }

/* Status badges */
.qo-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; text-transform: uppercase; white-space: nowrap; }
.qo-status-open { background: #dbeafe; color: #1e40af; }
.qo-status-slaughtered { background: #fef3c7; color: #92400e; }
.qo-status-out_for_delivery { background: #e0e7ff; color: #3730a3; }
.qo-status-delivered { background: #dcfce7; color: #166534; }

/* Item card */
.qo-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; margin-bottom: 6px; transition: box-shadow 0.15s; }
.qo-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.qo-card.cat-bakra { background: #fefce8; border-color: #fef3c7; }
.qo-card.cat-hissa { background: #eff6ff; border-color: #dbeafe; }
.qo-card.cat-lamb, .qo-card.cat-dumba { background: #fdf2f8; border-color: #fce7f3; }
.qo-card.is-delivered { opacity: 0.65; }

.qo-card-row1 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
.qo-card-row2 { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 12px; color: #6b7280; }
.qo-card-row3 { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 6px; }

.qo-bundle-chip { display: inline-flex; align-items: center; gap: 3px; background: #1f2937; color: #fff; border-radius: 4px; padding: 2px 8px; font-size: 12px; font-weight: 700; }
.qo-qty-chip { display: inline-flex; align-items: center; gap: 3px; background: #f3f4f6; color: #374151; border-radius: 4px; padding: 2px 6px; font-size: 11px; font-weight: 600; }
.qo-customer { font-size: 14px; font-weight: 600; color: #111827; }
.qo-product { font-size: 12px; color: #6b7280; }
.qo-order-num { font-size: 11px; color: #9ca3af; font-family: monospace; }

.qo-meta-tag { display: inline-flex; align-items: center; gap: 3px; padding: 1px 6px; border-radius: 4px; background: #f9fafb; border: 1px solid #f3f4f6; font-size: 11px; color: #4b5563; }
.qo-meta-tag.day { background: #fef9c3; border-color: #fde68a; color: #854d0e; }
.qo-meta-tag.slot { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.qo-meta-tag.delivery-type { background: #ede9fe; border-color: #ddd6fe; color: #5b21b6; }
.qo-meta-tag.q-type { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
.qo-meta-tag.paya { background: #f0f9ff; border-color: #bae6fd; color: #0c4a6e; }

.qo-rider-chip { display: inline-flex; align-items: center; gap: 4px; background: #dbeafe; color: #1e40af; border-radius: 4px; padding: 2px 8px; font-size: 11px; font-weight: 600; }
/* Phase C — GPS health pill on order cards (small, sits next to the rider chip). */
.qo-gps-chip { display: inline-flex; align-items: center; gap: 3px; border-radius: 10px; padding: 1px 7px; font-size: 10px; font-weight: 700; }
.qo-eta-chip { display: inline-flex; align-items: center; gap: 4px; background: #fef3c7; color: #92400e; border-radius: 4px; padding: 2px 8px; font-size: 11px; font-weight: 600; }

.qo-print-state { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 1px 6px; border-radius: 4px; }
.qo-print-state.printed { background: #d1fae5; color: #065f46; }
.qo-print-state.partial { background: #fed7aa; color: #9a3412; }
.qo-print-state.pending { background: #f3f4f6; color: #6b7280; }

.qo-action-btn { background: #fff; border: 1px solid #d1d5db; border-radius: 4px; padding: 3px 8px; font-size: 11px; font-weight: 600; color: #374151; cursor: pointer; transition: all 0.15s; }
.qo-action-btn:hover { background: #f9fafb; border-color: #9ca3af; }
.qo-action-btn.primary { background: #d97706; border-color: #d97706; color: #fff; }
.qo-action-btn.primary:hover { background: #b45309; }
.qo-action-btn:disabled { opacity: 0.5; cursor: not-allowed; }

.qo-inline-select { padding: 2px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 11px; background: #fff; max-width: 130px; }
.qo-inline-select.changed { border-color: #d97706; background: #fffbeb; }

.qo-verified { color: #059669; font-size: 13px; }

/* Group headers */
.qo-group-hdr { display: flex; align-items: center; gap: 8px; padding: 8px 12px; cursor: pointer; user-select: none; background: #fafafa; border-radius: 6px; margin-bottom: 6px; }
.qo-group-hdr:hover { background: #f3f4f6; }
.qo-group-hdr .arrow { font-size: 10px; color: #9ca3af; transition: transform 0.15s; }
.qo-group-hdr.collapsed .arrow { transform: rotate(-90deg); }
.qo-group-hdr .count { font-size: 11px; color: #6b7280; }
.qo-group-body { padding-left: 16px; margin-bottom: 12px; }
.qo-group-body.hidden { display: none; }

.qo-status-group { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; margin-bottom: 12px; }

/* Summary bar */
.qo-summary { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; font-size: 13px; color: #374151; }
.qo-summary b { color: #111827; }

/* Print toolbar */
.qo-toolbar-btn { padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid; transition: all 0.15s; display: inline-flex; align-items: center; gap: 6px; }
.qo-toolbar-btn.primary { background: #d97706; border-color: #d97706; color: #fff; }
.qo-toolbar-btn.primary:hover { background: #b45309; }
.qo-toolbar-btn.secondary { background: #fff; border-color: #d1d5db; color: #374151; }
.qo-toolbar-btn.secondary:hover { background: #f9fafb; }
.qo-toolbar-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Modal */
.qo-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 9998; display: none; }
.qo-modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; border-radius: 12px; padding: 24px; max-width: 700px; width: 95%; max-height: 85vh; overflow-y: auto; z-index: 9999; display: none; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.qo-modal h2 { font-size: 18px; font-weight: 700; margin: 0 0 16px; color: #111827; }
.qo-print-tab { padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid #e5e7eb; background: #fff; color: #6b7280; }
.qo-print-tab.active { background: #d97706; border-color: #d97706; color: #fff; }

/* Toast */
.qo-toast { position: fixed; top: 20px; right: 20px; z-index: 10000; padding: 12px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,.15); display: none; }
.qo-toast.success { background: #d1fae5; color: #065f46; }
.qo-toast.error { background: #fee2e2; color: #991b1b; }
.qo-toast.info { background: #dbeafe; color: #1e40af; }
</style>
@endpush

@section('content')
<div class="kt-container-fixed" style="max-width: 1400px;">
    <!-- Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px;">
        <div>
            <h1 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Qurbani Orders</h1>
            <p style="font-size: 13px; color: #6b7280; margin: 4px 0 0;">Region-wise dispatch view &middot; box label printing &middot; per-item actions</p>
        </div>
        <div style="display: flex; gap: 8px;">
            <button class="qo-toolbar-btn secondary" onclick="openRidersMap()" title="Open the live dispatch map for any rider">
                🗺️ Riders Map
            </button>
            <button class="qo-toolbar-btn secondary" onclick="openPrintModal()" title="Open print picker (filtered)">
                Print Box Labels
            </button>
        </div>
    </div>

    <!-- Filters Row 1 -->
    <div class="bg-white border border-gray-200 rounded-lg p-3 mb-3">
        <div class="flex flex-wrap items-center gap-3">
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Status</label>
                <select id="filterStatus" class="qo-filter" onchange="loadItems()">
                    <option value="">All Statuses</option>
                    @foreach($statusOptions as $s)
                    <option value="{{ $s }}">{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Day</label>
                <select id="filterDay" class="qo-filter" onchange="onDayChanged()">
                    <option value="">All Days</option>
                    @foreach($days as $d)
                    <option value="{{ $d }}">{{ $d }}</option>
                    @endforeach
                    <option value="__unassigned__" style="color:#DC2626;">-- Unassigned --</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Delivery Type</label>
                <select id="filterDeliveryType" class="qo-filter" onchange="onDeliveryTypeChanged()">
                    <option value="">All Types</option>
                    @foreach($deliveryTypes as $dt)
                    <option value="{{ $dt }}">{{ $dt }}</option>
                    @endforeach
                    <option value="__unassigned__" style="color:#DC2626;">-- Unassigned --</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1" title="Slot options narrow based on selected Day &amp; Delivery Type">
                    Slot <span class="text-[10px] font-normal text-gray-400">(Day/Type)</span>
                </label>
                <select id="filterSlot" class="qo-filter filter-dependent" onchange="loadItems()">
                    <option value="">All Slots</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1" title="Sub Region options narrow based on the active Region chip">
                    Sub Region <span class="text-[10px] font-normal text-gray-400">(Region)</span>
                </label>
                <select id="filterSubRegion" class="qo-filter filter-dependent" onchange="loadItems()">
                    <option value="">All Sub Regions</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Category</label>
                <select id="filterCategory" class="qo-filter" onchange="loadItems()">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                    <option value="__unassigned__" style="color:#DC2626;">-- Uncategorized --</option>
                </select>
            </div>
            @if(isset($qurbaniTypes) && count($qurbaniTypes) > 0)
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Qurbani Type</label>
                <select id="filterQurbaniType" class="qo-filter" onchange="loadItems()">
                    <option value="">All</option>
                    @foreach($qurbaniTypes as $qt)
                    <option value="{{ $qt }}">{{ $qt }}</option>
                    @endforeach
                    <option value="__unassigned__" style="color:#DC2626;">-- Unassigned --</option>
                </select>
            </div>
            @endif
            @if(isset($payaOptions) && count($payaOptions) > 0)
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Paya</label>
                <select id="filterQurbaniPaya" class="qo-filter" onchange="loadItems()">
                    <option value="">All</option>
                    @foreach($payaOptions as $po)
                    <option value="{{ $po }}">{{ $po }}</option>
                    @endforeach
                    <option value="__unassigned__" style="color:#DC2626;">-- Unassigned --</option>
                </select>
            </div>
            @endif
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Rider</label>
                <select id="filterRider" class="qo-filter" onchange="loadItems()">
                    <option value="">All Riders</option>
                    @foreach($riders as $rider)
                    <option value="{{ $rider->id }}">{{ $rider->fullname }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-medium text-gray-500 block mb-1">Customer</label>
                <input type="text" id="filterCustomer" class="qo-filter" placeholder="Search..." oninput="debouncedLoad()" style="min-width:140px;">
            </div>
            <div style="margin-left:auto;">
                <label class="text-xs font-medium text-gray-500 block mb-1">&nbsp;</label>
                <button class="qo-toolbar-btn secondary" onclick="resetFilters()" style="padding:6px 10px;font-size:12px;">Reset Filters</button>
            </div>
        </div>
    </div>

    <!-- Region quick-filter chips -->
    <div id="regionChips" class="qo-region-chips"></div>

    <!-- Summary bar -->
    <div class="bg-white border border-gray-200 rounded-lg p-3 mb-4">
        <div class="qo-summary" id="summaryBar">
            <span class="text-sm text-gray-400">Loading...</span>
        </div>
    </div>

    <!-- Items grouped list -->
    <div id="itemsContainer">
        <div class="text-center py-12 text-gray-400">Loading items...</div>
    </div>
</div>

<!-- Print Modal -->
<div class="qo-modal-overlay" id="printOverlay" onclick="closePrintModal()"></div>
<div class="qo-modal" id="printModal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="margin:0;">Print Box Labels</h2>
        <button onclick="closePrintModal()" style="background:none;border:none;font-size:20px;color:#6b7280;cursor:pointer;">&times;</button>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:14px;">
        <button class="qo-print-tab active" data-print-filter="pending" onclick="setPrintFilter('pending')">Pending</button>
        <button class="qo-print-tab" data-print-filter="printed" onclick="setPrintFilter('printed')">Printed</button>
        <button class="qo-print-tab" data-print-filter="all" onclick="setPrintFilter('all')">All</button>
    </div>
    <div id="printSummary" style="font-size:13px;color:#6b7280;margin-bottom:12px;"></div>
    <div id="printLabelList" style="max-height:400px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;"></div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;flex-wrap:wrap;gap:8px;">
        <label style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:6px;">
            <input type="checkbox" id="autoMarkPrinted" checked>
            Auto-mark as printed after print
        </label>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button class="qo-toolbar-btn secondary" onclick="selectAllLabels()" style="padding:5px 10px;font-size:12px;">Select All</button>
            <button class="qo-toolbar-btn secondary" onclick="deselectAllLabels()" style="padding:5px 10px;font-size:12px;">Deselect All</button>
            <button class="qo-toolbar-btn primary" id="printSelectedBtn" onclick="printFromModal()" style="padding:6px 14px;font-size:12px;">Print Selected (0)</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="qo-toast" id="qoToast"></div>

<!-- Persistent print-progress banner. Shown while a multi-batch print
     job is running so the user always knows which batch is in flight,
     can cancel mid-flow, and isn't surprised by repeated print dialogs. -->
<div id="qoPrintProgress" style="display:none;position:fixed;top:0;left:0;right:0;z-index:9997;background:#1f2937;color:#fff;padding:10px 20px;box-shadow:0 2px 8px rgba(0,0,0,.2);">
    <div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;">
            <svg style="width:18px;height:18px;animation:qoSpin 1s linear infinite;" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="#fbbf24" stroke-width="3" fill="none" stroke-dasharray="50" stroke-linecap="round"/></svg>
            <span style="font-weight:600;" id="qoPrintProgressLabel">Printing...</span>
        </div>
        <div style="flex:1;min-width:200px;background:#374151;border-radius:9999px;height:8px;overflow:hidden;">
            <div id="qoPrintProgressBar" style="height:100%;background:linear-gradient(90deg,#d97706,#f59e0b);width:0%;transition:width 0.4s;"></div>
        </div>
        <span id="qoPrintProgressCount" style="font-size:13px;color:#fcd34d;font-weight:600;">0/0</span>
        <button id="qoPrintCancelBtn" onclick="cancelPrintRun()" style="background:#dc2626;color:#fff;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;">Cancel</button>
    </div>
</div>
<style>@keyframes qoSpin { to { transform: rotate(360deg); } }</style>

{{-- Phase C2 (May-2026) — Dispatch map modal. Lazy-loads Google Maps
     JS only on first open so the orders page stays fast. The list on
     the left is built from the rider dropdown options; clicking a
     rider fetches /qurbani/api/riders/{id}/dispatch-map and pins the
     OFD bundles + delivered + rider GPS + base. Auto-refreshes the
     rider's GPS every 30s while the modal is open. --}}
{{-- Phase 2 (May-2026) — Timeline modal for a single Qurbani line item.
     Slides in from the right with status events, rider + dispatch info,
     current ETA, delay alert (when a prior stop slipped), and today's
     WhatsApp activity. Loads on demand via openTimeline(lineItemId). --}}
<div id="qoTimelineOverlay" onclick="closeTimeline()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;"></div>
<div id="qoTimelineModal" style="display:none;position:fixed;top:0;right:0;bottom:0;width:min(480px, 95vw);background:#fff;box-shadow:-8px 0 24px rgba(0,0,0,0.18);z-index:10001;overflow:hidden;flex-direction:column;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #e5e7eb;background:#fef3c7;">
        <div style="min-width:0;">
            <h2 style="margin:0;font-size:17px;font-weight:700;color:#1f2937;">🕒 Timeline</h2>
            <p id="qoTimelineSubtitle" style="margin:3px 0 0;font-size:12px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Loading…</p>
        </div>
        <button onclick="closeTimeline()" style="background:none;border:none;font-size:24px;color:#6b7280;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div id="qoTimelineBody" style="flex:1;overflow-y:auto;padding:14px 18px;background:#fff;">
        <div style="text-align:center;padding:40px 0;color:#9ca3af;font-size:13px;">Loading…</div>
    </div>
</div>

<div id="qoMapOverlay" onclick="closeRidersMap()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9998;"></div>
<div id="qoMapModal" style="display:none;position:fixed;top:3vh;left:3vw;right:3vw;bottom:3vh;background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.3);z-index:9999;overflow:hidden;flex-direction:column;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e5e7eb;">
        <div>
            <h2 style="margin:0;font-size:18px;font-weight:700;">🗺️ Dispatch map</h2>
            <p id="qoMapSubtitle" style="margin:3px 0 0;font-size:12px;color:#6b7280;">Pick a rider on the left to plot their current dispatch.</p>
        </div>
        <button onclick="closeRidersMap()" style="background:none;border:none;font-size:22px;color:#6b7280;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="display:flex;flex:1;min-height:0;">
        <div id="qoMapRidersList" style="width:240px;flex-shrink:0;border-right:1px solid #e5e7eb;overflow-y:auto;padding:8px 6px;background:#f9fafb;">
            <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;padding:6px 8px;letter-spacing:.4px;">Riders with dispatch</div>
            <div id="qoMapRidersInner"><span style="font-size:13px;color:#9ca3af;padding:6px 8px;display:block;">Loading...</span></div>
        </div>
        <div style="flex:1;min-width:0;display:flex;flex-direction:column;">
            <div id="qoMapLegend" style="display:flex;gap:14px;padding:8px 14px;border-bottom:1px solid #e5e7eb;background:#fff;font-size:12px;color:#374151;flex-wrap:wrap;align-items:center;">
                <span>📍 Tap a rider to load their dispatch</span>
            </div>
            <div id="qoMapContainer" style="flex:1;min-height:300px;background:#f3f4f6;"></div>
        </div>
    </div>
</div>

@endsection
@push('custom_js')
@php
    // Pre-compute the boot data as a PHP variable. We can't put a
    // multi-line array (with arrow functions + nested arrays) directly
    // inside @json(...) — Blade's directive parser counts parens/brackets
    // naively and chokes on the nested ']'.
    //
    // fieldOptions ships the FULL row (id, parent_id, delivery_type_parent_id,
    // option_value, is_active) for every dimension — this is what powers
    // the cascading Slot ⊂ Day×DeliveryType and Sub-Region ⊂ Region
    // dropdowns on the client. Same data the invoices page reads.
    $fieldOptionsForJs = [];
    foreach (($fieldOptions ?? []) as $fieldName => $rows) {
        $fieldOptionsForJs[$fieldName] = $rows->map(function ($r) {
            return [
                'id' => $r->id ?? null,
                'parent_id' => $r->parent_id ?? null,
                'delivery_type_parent_id' => $r->delivery_type_parent_id ?? null,
                'option_value' => $r->option_value,
                'is_active' => isset($r->is_active) ? (int)$r->is_active : 1,
                'display_order' => $r->display_order ?? 0,
            ];
        })->values()->all();
    }
    $qurbaniOrdersBoot = [
        'configuredRegions' => $regions->values()->all(),
        'riders' => $riders->map(function ($r) {
            return ['id' => $r->id, 'fullname' => $r->fullname];
        })->values()->all(),
        'statusOptions' => $statusOptions->values()->all(),
        'fieldOptions' => $fieldOptionsForJs,
        'csrfToken' => csrf_token(),
    ];
@endphp
<script>
window.QURBANI_ORDERS_BOOT = {!! json_encode($qurbaniOrdersBoot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
</script>
@endpush
@push('custom_js')
<script>
(function() {
    'use strict';

    const BOOT = window.QURBANI_ORDERS_BOOT || {};
    const CSRF = BOOT.csrfToken || '';
    const RIDERS = BOOT.riders || [];
    const STATUS_OPTIONS = BOOT.statusOptions || ['open', 'slaughtered', 'out_for_delivery', 'delivered'];
    const CONFIGURED_REGIONS = BOOT.configuredRegions || [];
    const FIELD_OPTIONS = BOOT.fieldOptions || {};

    let allItems = [];
    let allLabels = [];
    let printFilter = 'pending';
    let debounceTimer = null;

    // ── Toast ──────────────────────────────────────────────────────
    function toast(msg, type = 'info', duration = 2500) {
        const el = document.getElementById('qoToast');
        el.className = 'qo-toast ' + type;
        el.textContent = msg;
        el.style.display = 'block';
        setTimeout(() => { el.style.display = 'none'; }, duration);
    }

    // ── Filter param collection ────────────────────────────────────
    function collectFilterParams() {
        const params = new URLSearchParams();
        const fields = {
            status: 'filterStatus', day: 'filterDay', delivery_type: 'filterDeliveryType',
            slot: 'filterSlot', region: 'filterRegion', sub_region: 'filterSubRegion',
            category: 'filterCategory', rider_id: 'filterRider', customer: 'filterCustomer'
        };
        for (const [key, id] of Object.entries(fields)) {
            const el = document.getElementById(id);
            if (el && el.value) params.set(key, el.value);
        }
        const qtEl = document.getElementById('filterQurbaniType');
        if (qtEl && qtEl.value) params.set('qurbani_type', qtEl.value);
        const ppEl = document.getElementById('filterQurbaniPaya');
        if (ppEl && ppEl.value) params.set('qurbani_paya', ppEl.value);
        if (window._activeRegion) params.set('region', window._activeRegion);
        return params;
    }

    // ── Load Items ─────────────────────────────────────────────────
    function loadItems() {
        const params = collectFilterParams();
        document.getElementById('itemsContainer').innerHTML = '<div class="text-center py-12 text-gray-400">Loading...</div>';

        fetch('/qurbani/api/orders-items?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed');
                allItems = data.items || [];
                renderRegionChips();
                renderSummary(data.summary);
                renderItems();
            })
            .catch(err => {
                document.getElementById('itemsContainer').innerHTML = '<div class="text-center py-8 text-red-500">' + esc(err.message) + '</div>';
            });
    }

    function debouncedLoad() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadItems, 400);
    }

    // ── Region chips (quick-filter) ────────────────────────────────
    function renderRegionChips() {
        // Compute counts from the current filtered set BEFORE region filter.
        // Since region filter is applied server-side, allItems already
        // reflects the active region. So we always show every configured
        // region as a chip with the count of items currently rendered.
        // To make chips informative across all regions, we maintain a
        // "snapshot" count from a no-region fetch on first load.
        const counts = {};
        allItems.forEach(it => {
            const r = it.qurbani_region || 'Unassigned';
            counts[r] = (counts[r] || 0) + 1;
        });

        const regions = [...CONFIGURED_REGIONS];
        // Append any regions present in items that aren't in the config list.
        Object.keys(counts).forEach(r => {
            if (r !== 'Unassigned' && !regions.includes(r)) regions.push(r);
        });

        // Use data-region attribute + delegated click handler instead of
        // inline onclick. Inline onclick with JSON.stringify(name) breaks
        // when the region name contains characters that conflict with
        // HTML attribute quoting (the resulting attribute looked like
        // onclick="setActiveRegion("Bahria Phase 8")" which is invalid).
        const active = window._activeRegion || '';
        let html = '';
        html += '<button class="qo-region-chip ' + (!active ? 'active' : '') + '" data-region="">All Regions</button>';
        regions.forEach(r => {
            const c = counts[r] || 0;
            const cls = active === r ? 'active' : '';
            html += '<button class="qo-region-chip ' + cls + '" data-region="' + esc(r) + '">' + esc(r);
            if (c > 0) html += '<span class="count">(' + c + ')</span>';
            html += '</button>';
        });
        if (counts['Unassigned']) {
            const cls = active === '__unassigned__' ? 'active' : '';
            html += '<button class="qo-region-chip ' + cls + '" style="color:#dc2626;" data-region="__unassigned__">Unassigned <span class="count">(' + counts['Unassigned'] + ')</span></button>';
        }
        const wrap = document.getElementById('regionChips');
        wrap.innerHTML = html;
    }

    // One delegated handler for ALL chips — set up once during init.
    function bindRegionChipDelegation() {
        const wrap = document.getElementById('regionChips');
        if (!wrap || wrap._delegationBound) return;
        wrap.addEventListener('click', function(e) {
            const btn = e.target.closest('.qo-region-chip');
            if (!btn) return;
            // dataset.region is "" for All, region name otherwise.
            setActiveRegion(btn.dataset.region || '');
        });
        wrap._delegationBound = true;
    }

    // Delegated handler for group / sub-group "Print" buttons. Re-bound
    // every render is fine because we check the _delegationBound flag.
    //
    // May-2026 bugfix: bound on the CAPTURE phase (third arg = true).
    // The Print buttons live inside `<div onclick="event.stopPropagation()">`
    // wrappers (intentional — they keep the parent group header's
    // accordion-toggle handler from firing on a button click). On the
    // bubble phase the wrapper's stopPropagation halts the event before
    // it reaches #itemsContainer, so a bubble-phase delegation listener
    // here would never see the click. Capture phase fires outermost →
    // innermost BEFORE the bubble phase, so this listener runs first
    // (handles the print intent) and the wrapper's stopPropagation still
    // does its job afterwards (suppresses the accordion toggle). Both
    // behaviours preserved.
    function bindGroupPrintDelegation() {
        const wrap = document.getElementById('itemsContainer');
        if (!wrap || wrap._delegationBound) return;
        wrap.addEventListener('click', function(e) {
            const btn = e.target.closest('[data-print-status]');
            if (!btn) return;
            // No stopPropagation here — the wrapper div's inline
            // onclick handles that during the bubble phase. Doing it
            // again here on capture would be redundant.
            const status = btn.dataset.printStatus;
            const sub = btn.dataset.printSub || null;
            printGroup(status, sub);
        }, true);
        wrap._delegationBound = true;
    }

    function setActiveRegion(region) {
        window._activeRegion = region || null;
        // Active region drives sub-region narrowing — refresh the Sub-Region
        // dropdown to only show options that belong to this region.
        updateSubRegionDropdown();
        loadItems();
    }

    // ── Cascading filters ──────────────────────────────────────────
    // Slot      ⊂ Day × DeliveryType  (via parent_id + delivery_type_parent_id)
    // Sub-Region ⊂ Region              (via parent_id)
    // Same logic as the Qurbani Invoices page (kept consistent so the team
    // doesn't have to learn two filter behaviours).
    function onDayChanged() {
        updateSlotDropdown();
        loadItems();
    }
    function onDeliveryTypeChanged() {
        updateSlotDropdown();
        loadItems();
    }

    function updateSlotDropdown() {
        const slotSel = document.getElementById('filterSlot');
        if (!slotSel) return;
        const dayVal = (document.getElementById('filterDay') || {}).value || '';
        const dtVal  = (document.getElementById('filterDeliveryType') || {}).value || '';
        const currentSlot = slotSel.value;

        // Clear all options except the first ("All Slots").
        while (slotSel.options.length > 1) slotSel.remove(1);

        let slots = (FIELD_OPTIONS.qurbani_slot || []).filter(o => o.is_active);

        // Resolve the parent objects so we can match by id (parent_id is what
        // the qurbani_settings table stores, NOT the textual value).
        let dayObj = null, dtObj = null;
        if (dayVal && dayVal !== '__unassigned__') {
            dayObj = (FIELD_OPTIONS.qurbani_day || []).find(d => d.is_active && d.option_value === dayVal);
        }
        if (dtVal && dtVal !== '__unassigned__') {
            dtObj = (FIELD_OPTIONS.qurbani_delivery_type || []).find(d => d.is_active && d.option_value === dtVal);
        }

        // Tier 1: Day + DeliveryType — most specific.
        // Tier 2: Day only (fallback when no slots are configured for the
        //         specific DT — better to show day-level slots than nothing).
        // Tier 3: All slots.
        if (dayObj && dtObj) {
            const filtered = slots.filter(s => s.parent_id === dayObj.id && s.delivery_type_parent_id === dtObj.id);
            if (filtered.length > 0) slots = filtered;
            else {
                const dayOnly = slots.filter(s => s.parent_id === dayObj.id);
                if (dayOnly.length > 0) slots = dayOnly;
            }
        } else if (dayObj) {
            const filtered = slots.filter(s => s.parent_id === dayObj.id);
            if (filtered.length > 0) slots = filtered;
        }

        const seen = new Set();
        slots.forEach(o => {
            if (seen.has(o.option_value)) return;
            seen.add(o.option_value);
            const opt = document.createElement('option');
            opt.value = o.option_value;
            opt.textContent = o.option_value;
            if (o.option_value === currentSlot) opt.selected = true;
            slotSel.appendChild(opt);
        });
        const unOpt = document.createElement('option');
        unOpt.value = '__unassigned__';
        unOpt.textContent = '-- Unassigned --';
        unOpt.style.color = '#DC2626';
        if (currentSlot === '__unassigned__') unOpt.selected = true;
        slotSel.appendChild(unOpt);

        // If the previously selected slot is no longer in the list (e.g.,
        // user changed Day and the old slot doesn't apply to the new day),
        // reset to "All Slots" so the filter doesn't silently exclude
        // everything.
        if (currentSlot && currentSlot !== '__unassigned__' && !seen.has(currentSlot)) {
            slotSel.value = '';
        }
    }

    function updateSubRegionDropdown() {
        const subSel = document.getElementById('filterSubRegion');
        if (!subSel) return;
        const regionVal = window._activeRegion || '';
        const currentSub = subSel.value;

        while (subSel.options.length > 1) subSel.remove(1);

        let subs = (FIELD_OPTIONS.qurbani_sub_region || []).filter(o => o.is_active);
        if (regionVal && regionVal !== '__unassigned__') {
            const regionObj = (FIELD_OPTIONS.qurbani_region || []).find(r => r.is_active && r.option_value === regionVal);
            if (regionObj) {
                const filtered = subs.filter(s => s.parent_id === regionObj.id);
                if (filtered.length > 0) subs = filtered;
            }
        }

        const seen = new Set();
        subs.forEach(o => {
            if (seen.has(o.option_value)) return;
            seen.add(o.option_value);
            const opt = document.createElement('option');
            opt.value = o.option_value;
            opt.textContent = o.option_value;
            if (o.option_value === currentSub) opt.selected = true;
            subSel.appendChild(opt);
        });
        const unOpt = document.createElement('option');
        unOpt.value = '__unassigned__';
        unOpt.textContent = '-- Unassigned --';
        unOpt.style.color = '#DC2626';
        if (currentSub === '__unassigned__') unOpt.selected = true;
        subSel.appendChild(unOpt);

        if (currentSub && currentSub !== '__unassigned__' && !seen.has(currentSub)) {
            subSel.value = '';
        }
    }

    // ── Summary ────────────────────────────────────────────────────
    function renderSummary(summary) {
        if (!summary) return;
        let html = '<span><b>' + summary.total_line_items + '</b> items</span>';
        html += '<span><b>' + summary.total_boxes + '</b> boxes</span>';
        html += '<span style="color:#059669;"><b>' + summary.total_printed + '</b> printed</span>';
        html += '<span style="color:#d97706;"><b>' + (summary.total_boxes - summary.total_printed) + '</b> pending print</span>';
        if (summary.by_status) {
            for (const [st, cnt] of Object.entries(summary.by_status)) {
                html += '<span class="qo-badge qo-status-' + esc(st) + '">' + esc(st.replace(/_/g, ' ')) + ': ' + cnt + '</span>';
            }
        }
        document.getElementById('summaryBar').innerHTML = html;
    }

    // ── Render grouped items ───────────────────────────────────────
    function renderItems() {
        if (!allItems.length) {
            document.getElementById('itemsContainer').innerHTML = '<div class="bg-white border border-gray-200 rounded-lg p-8 text-center text-gray-400">No items match your filters.</div>';
            return;
        }

        // Group: Status > Sub-region (region is now a quick-filter chip).
        const grouped = {};
        allItems.forEach(it => {
            const status = it.qurbani_item_status || 'open';
            const sub = it.qurbani_sub_region || 'Unassigned Sub-Region';
            if (!grouped[status]) grouped[status] = {};
            if (!grouped[status][sub]) grouped[status][sub] = [];
            grouped[status][sub].push(it);
        });

        const statusOrder = ['open', 'slaughtered', 'out_for_delivery', 'delivered'];
        const sortedStatuses = Object.keys(grouped).sort((a, b) => {
            const ai = statusOrder.indexOf(a), bi = statusOrder.indexOf(b);
            return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi);
        });

        let html = '';
        sortedStatuses.forEach(status => {
            const subs = grouped[status];
            const statusCount = Object.values(subs).reduce((s, arr) => s + arr.length, 0);
            const printableInGroup = collectGroupItems(status).filter(i => i.qurbani_item_status !== 'delivered').length;
            const statusLabel = status === 'slaughtered' ? '🔪 Slaughtered' : ucwords(status.replace(/_/g, ' '));

            html += '<div class="qo-status-group" data-status="' + esc(status) + '">';
            html += '<div class="qo-group-hdr" onclick="toggleGroup(this)">';
            html += '<span class="arrow">▼</span>';
            html += '<span class="qo-badge qo-status-' + esc(status) + '">' + statusLabel + '</span>';
            html += '<span class="count">' + statusCount + ' items</span>';
            html += '<div style="margin-left:auto;display:flex;gap:6px;" onclick="event.stopPropagation();">';
            if (printableInGroup > 0) {
                // data-attr based — handled by delegated click below.
                html += '<button class="qo-action-btn primary" data-print-status="' + esc(status) + '" title="Print all undelivered items in this status group">';
                html += 'Print Group (' + printableInGroup + ')</button>';
            }
            html += '</div>';
            html += '</div>';
            html += '<div class="qo-group-body">';

            const sortedSubs = Object.keys(subs).sort();
            sortedSubs.forEach(sub => {
                const subItems = subs[sub];
                const printableInSub = subItems.filter(i => i.qurbani_item_status !== 'delivered').length;
                html += '<div data-sub="' + esc(sub) + '">';
                html += '<div class="qo-group-hdr" style="background:#f9fafb;padding:6px 10px;" onclick="toggleGroup(this)">';
                html += '<span class="arrow">▼</span>';
                html += '<span style="font-size:13px;font-weight:600;color:#374151;">' + esc(sub) + '</span>';
                html += '<span class="count">(' + subItems.length + ')</span>';
                html += '<div style="margin-left:auto;display:flex;gap:6px;" onclick="event.stopPropagation();">';
                if (printableInSub > 0) {
                    // Use data-attrs + delegation (avoids the same
                    // double-quote-collision bug that broke region chips).
                    html += '<button class="qo-action-btn" data-print-status="' + esc(status) + '" data-print-sub="' + esc(sub) + '" title="Print all undelivered items in this sub-region">';
                    html += 'Print (' + printableInSub + ')</button>';
                }
                html += '</div>';
                html += '</div>';
                html += '<div class="qo-group-body" style="padding-left:8px;">';
                subItems.forEach(it => { html += renderCard(it); });
                html += '</div></div>';
            });

            html += '</div></div>';
        });

        document.getElementById('itemsContainer').innerHTML = html;
    }

    function collectGroupItems(status, sub) {
        return allItems.filter(it => {
            const itStatus = it.qurbani_item_status || 'open';
            if (itStatus !== status) return false;
            if (sub !== null && sub !== undefined) {
                const itSub = it.qurbani_sub_region || 'Unassigned Sub-Region';
                if (itSub !== sub) return false;
            }
            return true;
        });
    }

    function renderCard(it) {
        const catClass = getCatClass(it.category_level_2);
        const isDelivered = (it.qurbani_item_status || 'open') === 'delivered';
        const cardClasses = ['qo-card', catClass, isDelivered ? 'is-delivered' : ''].filter(Boolean).join(' ');
        const status = it.qurbani_item_status || 'open';
        const qty = parseFloat(it.quantity || 1) || 1;

        let html = '<div class="' + cardClasses + '" data-li-id="' + it.line_item_id + '">';

        // Row 1: bundle chip + qty + customer + status badge + print state
        html += '<div class="qo-card-row1">';
        // Display = THIS line item's qty out of the FULL bundle size
        // (not bundle_position_end/bundle_size which would show "5/5" for
        // a Hissa contributing 4 boxes to a 5-bundle when its end position
        // happens to be 5). Position numbers are still used for the
        // physical print labels (3 of 5, 4 of 5, 5 of 5).
        html += '<span class="qo-bundle-chip" title="' + qty + ' box(es) in a bundle of ' + it.bundle_size + ' (positions ' + it.bundle_position_start + '–' + it.bundle_position_end + ')">';
        html += qty + '/' + it.bundle_size;
        html += '</span>';
        if (it.has_verified_location) {
            const vBy = it.verified_location_saved_by_name ? ('Verified by ' + it.verified_location_saved_by_name) : 'Verified location';
            html += '<span class="qo-verified" title="' + esc(vBy) + '">📍</span>';
        }
        html += '<div style="flex:1;min-width:160px;">';
        html += '<div class="qo-customer">' + esc(it.customer_name) + '</div>';
        if (it.customer_phone) html += '<div class="qo-product" style="font-size:11px;">' + esc(it.customer_phone) + '</div>';
        html += '</div>';
        html += '<span class="qo-badge qo-status-' + esc(status) + '">' + esc(status === 'slaughtered' ? '🔪 Slaughtered' : ucwords(status.replace(/_/g, ' '))) + '</span>';

        // Print state
        if (it.print_count > 0 && it.print_count >= it.box_count) {
            html += '<span class="qo-print-state printed">✓ Printed</span>';
        } else if (it.print_count > 0) {
            html += '<span class="qo-print-state partial">' + it.print_count + '/' + it.box_count + ' printed</span>';
        } else {
            html += '<span class="qo-print-state pending">Not printed</span>';
        }
        html += '<span class="qo-order-num">#' + esc(it.order_number || '') + '</span>';
        html += '</div>';

        // Row 2: product + meta tags (day, slot, delivery, type, paya)
        html += '<div class="qo-card-row2">';
        html += '<span style="font-weight:500;">' + esc(it.product_name || '') + '</span>';
        if (it.category_level_2) html += '<span style="color:#9ca3af;">(' + esc(it.category_level_2) + ')</span>';
        if (it.qurbani_day) html += '<span class="qo-meta-tag day">📅 ' + esc(it.qurbani_day) + '</span>';
        if (it.qurbani_slot) html += '<span class="qo-meta-tag slot">⏰ ' + esc(it.qurbani_slot) + '</span>';
        if (it.qurbani_delivery_type) html += '<span class="qo-meta-tag delivery-type">🚚 ' + esc(it.qurbani_delivery_type) + '</span>';
        if (it.qurbani_type) html += '<span class="qo-meta-tag q-type">' + esc(it.qurbani_type) + '</span>';
        if (it.qurbani_paya) html += '<span class="qo-meta-tag paya">Paya: ' + esc(it.qurbani_paya) + '</span>';
        if (it.assigned_rider_name) {
            html += '<span class="qo-rider-chip">🛵 ' + esc(it.assigned_rider_name) + '</span>';
            // Phase C (May-2026) — small GPS health pill next to the
            // rider chip. Only rendered when we have an assignment.
            if (it.assigned_rider_gps) {
                const g = it.assigned_rider_gps;
                let bg = '#f3f4f6', fg = '#6b7280', icon = '⚫', label = 'No GPS';
                if (g.status === 'live')      { bg = '#d1fae5'; fg = '#065f46'; icon = '🟢'; label = 'Live'; }
                else if (g.status === 'recent') { bg = '#fef3c7'; fg = '#92400e'; icon = '🟡'; label = g.age_minutes != null ? (g.age_minutes + 'm') : 'Recent'; }
                else if (g.status === 'stale') { bg = '#fee2e2'; fg = '#991b1b'; icon = '🔴'; label = 'Stale'; }
                html += '<span class="qo-gps-chip" style="background:' + bg + ';color:' + fg + ';" title="GPS ' + esc(label) + (g.captured_at ? ' (last seen ' + esc(g.captured_at) + ')' : '') + '">' + icon + ' ' + esc(label) + '</span>';
            }
        }
        if (it.qurbani_estimated_delivery_at) {
            const eta = new Date(it.qurbani_estimated_delivery_at);
            html += '<span class="qo-eta-chip">ETA ' + eta.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) + '</span>';
        }
        html += '</div>';

        // Row 3: actions — status dropdown, rider dropdown, print
        html += '<div class="qo-card-row3" onclick="event.stopPropagation();">';
        // Status select
        html += '<select class="qo-inline-select" onchange="changeStatus(' + it.line_item_id + ', this)" data-original="' + esc(status) + '">';
        STATUS_OPTIONS.forEach(s => {
            const sel = (s === status) ? 'selected' : '';
            const lbl = s === 'slaughtered' ? '🔪 ' + ucwords(s.replace(/_/g, ' ')) : ucwords(s.replace(/_/g, ' '));
            html += '<option value="' + esc(s) + '" ' + sel + '>' + esc(lbl) + '</option>';
        });
        html += '</select>';
        // Rider select
        html += '<select class="qo-inline-select" onchange="changeRider(' + it.line_item_id + ', this)" data-original="' + (it.assigned_rider_user_id || '') + '">';
        html += '<option value="">— No rider —</option>';
        RIDERS.forEach(r => {
            const sel = (r.id == it.assigned_rider_user_id) ? 'selected' : '';
            html += '<option value="' + r.id + '" ' + sel + '>' + esc(r.fullname) + '</option>';
        });
        html += '</select>';
        // Per-row print
        if (!isDelivered) {
            html += '<button class="qo-action-btn primary" onclick="printItem(' + it.line_item_id + ')" title="Print labels for this line item">🖨️ Print (' + it.box_count + ')</button>';
        } else {
            html += '<span style="font-size:11px;color:#9ca3af;">(delivered — no print)</span>';
        }
        if (it.has_verified_location && it.cust_lat && it.cust_lng) {
            html += '<a href="https://www.google.com/maps/search/?api=1&query=' + it.cust_lat + ',' + it.cust_lng + '" target="_blank" class="qo-action-btn">📍 Map</a>';
        }
        // Phase 2 (May-2026) — Timeline button. Always visible since
        // the timeline data exists from order placement onwards (even
        // before slaughter). One click loads everything for THIS line
        // item: status events, rider/dispatch, current ETA, delay
        // alert (when a prior stop slipped), and today's WhatsApp
        // activity for this customer.
        html += '<button class="qo-action-btn" onclick="openTimeline(' + it.line_item_id + ')" title="See status, rider, ETA, delay alerts and today\'s WhatsApp activity">🕒 Timeline</button>';
        html += '</div>';

        html += '</div>';
        return html;
    }

    // ── Inline actions ─────────────────────────────────────────────
    async function changeStatus(lineItemId, sel) {
        const newStatus = sel.value;
        const original = sel.dataset.original;
        if (newStatus === original) return;

        sel.disabled = true;
        sel.classList.add('changed');
        try {
            const res = await fetch('/qurbani/api/items/' + lineItemId + '/status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ status: newStatus })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed');
            toast('Status → ' + ucwords(newStatus.replace(/_/g, ' ')), 'success');
            // Reload to reflect grouping changes
            setTimeout(loadItems, 400);
        } catch (e) {
            toast('Failed: ' + e.message, 'error');
            sel.value = original;
            sel.classList.remove('changed');
        } finally {
            sel.disabled = false;
        }
    }

    async function changeRider(lineItemId, sel) {
        const newRider = sel.value || null;
        const original = sel.dataset.original;
        if ((newRider || '') === original) return;

        sel.disabled = true;
        sel.classList.add('changed');
        try {
            const res = await fetch('/qurbani/api/items/' + lineItemId + '/rider', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ rider_id: newRider ? parseInt(newRider) : null })
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed');
            toast(data.message || 'Rider updated', 'success');
            sel.dataset.original = newRider || '';
            // Update item locally so next paint is correct without full reload
            const it = allItems.find(i => i.line_item_id === lineItemId);
            if (it) {
                it.assigned_rider_user_id = newRider ? parseInt(newRider) : null;
                it.assigned_rider_name = data.rider_name;
            }
            renderItems();
        } catch (e) {
            toast('Failed: ' + e.message, 'error');
            sel.value = original;
            sel.classList.remove('changed');
        } finally {
            sel.disabled = false;
        }
    }

    // ── Per-row + per-group print ──────────────────────────────────
    function expandToLabels(items) {
        const labels = [];
        items.forEach(it => {
            if ((it.qurbani_item_status || 'open') === 'delivered') return;
            const start = it.bundle_position_start || 1;
            const end = it.bundle_position_end || it.bundle_size || 1;
            const size = it.bundle_size || 1;
            for (let pos = start; pos <= end; pos++) {
                labels.push({
                    line_item_id: it.line_item_id,
                    order_id: it.order_id,
                    order_number: it.order_number,
                    customer_name: it.customer_name,
                    customer_phone: it.customer_phone,
                    product_name: it.product_name,
                    category_level_2: it.category_level_2,
                    qurbani_day: it.qurbani_day,
                    qurbani_slot: it.qurbani_slot,
                    qurbani_region: it.qurbani_region,
                    qurbani_sub_region: it.qurbani_sub_region,
                    qurbani_delivery_type: it.qurbani_delivery_type,
                    qurbani_type: it.qurbani_type,
                    qurbani_paya: it.qurbani_paya,
                    bundle_size: size,
                    bundle_key: it.bundle_key,
                    position: pos,
                    instructions: it.instructions
                });
            }
        });
        return labels;
    }

    async function printItem(lineItemId) {
        const it = allItems.find(i => i.line_item_id === lineItemId);
        if (!it) { toast('Item not found', 'error'); return; }
        const labels = expandToLabels([it]);
        if (!labels.length) { toast('Nothing to print', 'info'); return; }
        await runPrint(labels, true);
    }

    async function printGroup(status, sub) {
        const items = collectGroupItems(status, sub);
        const labels = expandToLabels(items);
        if (!labels.length) { toast('No undelivered items to print', 'info'); return; }
        await runPrint(labels, true);
    }

    async function printFromModal() {
        const checked = Array.from(document.querySelectorAll('.label-cb:checked'));
        if (!checked.length) return;
        const selectedLabels = checked.map(cb => allLabels[parseInt(cb.dataset.idx)]);
        const autoMark = document.getElementById('autoMarkPrinted').checked;
        await runPrint(selectedLabels, autoMark);
    }

    // ── The actual print runner ────────────────────────────────────
    // Opens a NEW WINDOW with just the labels HTML per batch so we
    // don't fight the main page's CSS. Each batch is ONE print job sent
    // to the OS print spooler.
    //
    // Why batch instead of one big job:
    //   • Home printers and inkjet drivers can buffer a limited number
    //     of pages — sending 200 pages at once sometimes stalls or
    //     freezes mid-print.
    //   • Each batch becomes its own job so if anything goes wrong
    //     (paper jam, ink out) you only lose 1 batch's worth of state
    //     rather than the entire run.
    //   • The 1.5s pause between batches gives the spooler time to
    //     start sending pages before the next job arrives.
    //
    // What the user sees:
    //   • A black progress banner at the top of the page that stays
    //     visible the entire run and updates with batch X / Y.
    //   • For each batch: a new browser window opens and IMMEDIATELY
    //     fires the print dialog. They click Print → the window auto-
    //     closes → 1.5s pause → next batch.
    //   • A Cancel button on the banner stops further batches from
    //     starting (already-spooled batches will still print at the
    //     printer's pace).
    let _printRunCancelled = false;
    async function runPrint(labels, autoMark) {
        if (!labels.length) return;
        const BATCH_SIZE = 25;
        const batches = [];
        for (let i = 0; i < labels.length; i += BATCH_SIZE) {
            batches.push(labels.slice(i, i + BATCH_SIZE));
        }
        _printRunCancelled = false;
        showPrintProgress(0, labels.length, 1, batches.length);

        let printed = 0;
        for (let bIdx = 0; bIdx < batches.length; bIdx++) {
            if (_printRunCancelled) {
                toast('Print run cancelled. ' + printed + ' label(s) sent before cancel.', 'info', 3500);
                break;
            }
            const batch = batches[bIdx];
            updatePrintProgress(printed, labels.length, bIdx + 1, batches.length, 'Opening print window for batch ' + (bIdx + 1) + '/' + batches.length + '...');
            await openPrintWindow(batch);
            printed += batch.length;
            updatePrintProgress(printed, labels.length, bIdx + 1, batches.length, 'Marking ' + batch.length + ' label(s) as printed...');
            if (autoMark && !_printRunCancelled) {
                await markPrinted(batch);
            }
            if (bIdx < batches.length - 1 && !_printRunCancelled) {
                updatePrintProgress(printed, labels.length, bIdx + 2, batches.length, 'Pausing 1.5s before next batch...');
                await sleep(1500);
            }
        }
        hidePrintProgress();
        if (!_printRunCancelled) {
            toast('Print run complete: ' + printed + ' label(s) sent. Refreshing...', 'success', 3500);
        }
        loadItems();
    }

    function showPrintProgress(done, total, currentBatch, totalBatches) {
        const wrap = document.getElementById('qoPrintProgress');
        if (!wrap) return;
        wrap.style.display = 'block';
        updatePrintProgress(done, total, currentBatch, totalBatches);
    }

    function updatePrintProgress(done, total, currentBatch, totalBatches, label) {
        const lbl = document.getElementById('qoPrintProgressLabel');
        const bar = document.getElementById('qoPrintProgressBar');
        const cnt = document.getElementById('qoPrintProgressCount');
        const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        if (lbl) lbl.textContent = label || ('Printing batch ' + currentBatch + ' of ' + totalBatches);
        if (bar) bar.style.width = pct + '%';
        if (cnt) cnt.textContent = done + '/' + total + ' labels';
    }

    function hidePrintProgress() {
        const wrap = document.getElementById('qoPrintProgress');
        if (wrap) wrap.style.display = 'none';
    }

    function cancelPrintRun() {
        _printRunCancelled = true;
        const lbl = document.getElementById('qoPrintProgressLabel');
        if (lbl) lbl.textContent = 'Cancelling after current batch...';
    }

    function openPrintWindow(batch) {
        return new Promise((resolve) => {
            const html = buildPrintDocument(batch);
            const w = window.open('', '_blank', 'width=900,height=700');
            if (!w) {
                alert('Popup blocked! Please allow popups for this site to print labels.');
                resolve();
                return;
            }
            w.document.open();
            w.document.write(html);
            w.document.close();
            // Window auto-prints on load (see embedded script). Resolve when window is closed.
            const startTime = Date.now();
            const checkClosed = setInterval(() => {
                if (w.closed) {
                    clearInterval(checkClosed);
                    resolve();
                } else if (Date.now() - startTime > 120000) {
                    // Safety timeout: 2 mins
                    clearInterval(checkClosed);
                    resolve();
                }
            }, 400);
        });
    }

    // Builds the HTML document that gets opened in the print window.
    // The CSS is inlined so the label is fully self-contained — no
    // dependency on the main page's stylesheet, which means we can't
    // accidentally style-leak and end up with the "28 sheets" bug.
    function buildPrintDocument(batch) {
        let pages = '';
        batch.forEach(l => { pages += buildLabelHTML(l); });

        // ────────────────────────────────────────────────────────────
        // Sizing budget for an A4 portrait sheet (297mm tall).
        // We learned the hard way that 200pt+48pt with generous padding
        // pushed the footer off the bottom — re-tuned values are below
        // and the budget is verified to fit:
        //   • Padding 8mm + 8mm                    = 16mm
        //   • Brand strip (5mm pad + 24pt)         ≈ 18mm
        //   • Margin → box block                   =  2mm
        //   • Box block (170pt × 0.85 line-h)      ≈ 60mm
        //   • Margin → grid                        =  2mm
        //   • Info grid (4 std rows + xl banner)   ≈ 130mm
        //   • Spacer (margin: auto on footer)      ≈ ~50mm flex slack
        //   • Footer (5mm + 18pt)                  ≈ 13mm
        //   • Total content                        ≈ 241mm  ✓
        // The flex spacer absorbs any height variation from long customer
        // names / instructions, so the footer always sits at the bottom.
        // ────────────────────────────────────────────────────────────
        const css =
            '@page { size: A4 portrait; margin: 0; }' +
            'html, body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background: #fff; color: #111; }' +
            '* { box-sizing: border-box; }' +
            // Tighter padding (8mm vs 12mm) — pulls the brand strip up
            // toward the top edge as user requested.
            '.label-page { width: 210mm; height: 297mm; padding: 8mm 10mm; page-break-after: always; page-break-inside: avoid; overflow: hidden; display: flex; flex-direction: column; position: relative; }' +
            '.label-page:last-child { page-break-after: auto; }' +
            // Brand strip — slimmer vertical padding so it doesn\'t eat
            // into the data area.
            '.brand-strip { background: #d97706; color: #fff; padding: 5mm 9mm; display: flex; align-items: center; justify-content: space-between; border-radius: 3mm; }' +
            '.brand-name { font-size: 24pt; font-weight: 900; letter-spacing: 1.5pt; }' +
            '.brand-tag  { font-size: 12pt; font-weight: 600; letter-spacing: 1pt; text-transform: uppercase; opacity: 0.92; }' +
            // Box block — closer to the brand strip, slightly smaller
            // numbers so the info grid + footer have room.
            '.box-block { display: flex; align-items: baseline; justify-content: center; margin-top: 2mm; margin-bottom: 2mm; gap: 6mm; }' +
            '.box-num   { font-size: 170pt; font-weight: 900; line-height: 0.85; color: #111; }' +
            '.box-of    { font-size: 42pt; font-weight: 700; color: #555; }' +
            // Information grid.
            // flex: 1 makes the table grow to fill the empty space between
            // the box-block and the footer (which is pinned to the bottom
            // via margin-top: auto). Browsers distribute the extra height
            // proportionally across <tr> rows, so every cell gets bigger
            // and the table reaches all the way down to the footer.
            // vertical-align: middle keeps the labels/values centred
            // inside the now-taller cells (instead of stuck to the top).
            '.info-grid { width: 100%; border-collapse: collapse; margin-top: 2mm; border: 2.5pt solid #111; flex: 1 1 auto; }' +
            '.info-grid td { border: 1pt solid #444; padding: 5mm 6mm; vertical-align: middle; }' +
            '.cell-label { font-size: 10pt; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 0.5pt; margin-bottom: 1.5mm; }' +
            '.cell-value { font-size: 20pt; font-weight: 700; color: #111; line-height: 1.2; word-wrap: break-word; }' +
            // Auto-fit tiers — applied per-cell by the JS helper fitClass()
            // based on text length. Short text (Day 3, Washed) gets a much
            // bigger font so the cell space is actually used; long text
            // stays smaller so it doesn't wrap into 4 lines and overflow
            // the cell. Tiers were bumped one notch up (vs original) so
            // "Day 3" feels edge-to-edge instead of floating in the cell.
            // The "lg" + "xl" classes are explicit overrides for the two
            // visual hero values (No. of Boxes, Customer Name).
            '.cell-value.cv-tiny  { font-size: 48pt; line-height: 1.05; }' +
            '.cell-value.cv-short { font-size: 34pt; line-height: 1.1; }' +
            '.cell-value.cv-mid   { font-size: 26pt; line-height: 1.15; }' +
            '.cell-value.cv-long  { font-size: 15pt; line-height: 1.25; }' +
            '.cell-value.lg { font-size: 30pt; font-weight: 800; letter-spacing: -0.5pt; }' +
            '.cell-value.xl { font-size: 34pt; font-weight: 800; }' +
            // Footer — pinned to bottom by margin-top:auto on the flex column.
            '.label-footer { margin-top: auto; border-top: 2pt solid #111; padding-top: 3mm; display: flex; justify-content: space-between; align-items: center; font-size: 12pt; color: #555; }' +
            '.label-footer .phone { font-size: 16pt; font-weight: 800; color: #111; }' +
            '.label-footer .stamp { font-size: 10pt; color: #888; }' +
            // Screen preview backdrop.
            '@media screen { body { background: #f3f4f6; padding: 20px; } .label-page { background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.1); margin-bottom: 20px; } }';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Qurbani Box Labels</title>' +
            '<style>' + css + '</style></head><body>' +
            pages +
            '<script>window.onload=function(){setTimeout(function(){window.focus();window.print();},250);};window.onafterprint=function(){setTimeout(function(){window.close();},500);};</' + 'script>' +
            '</body></html>';
    }

    // Builds ONE label page. The structure follows the reference image:
    //   • Branded header strip (NF logo text + Qurbani tag)
    //   • Big box number block (X / Y) for at-a-glance recognition
    //   • Customer Name banner (full width, large)
    //   • 3-column info grid: Order/Day/Region, Qurbani/Slot/Sub-Region,
    //     Delivery-Type/Trotters/No.-of-Boxes, Qurbani-Type + Instructions
    //   • Footer with phone + order number + print stamp
    // Auto-fit helper — picks a font-size class based on text length so
    // each cell value visually fills its cell. Tuned against the actual
    // cell width (~60mm at 3-col layout) — sizes were bumped one tier
    // higher across the board on user request so "Day 3" and similar
    // short values feel edge-to-edge instead of floating in the cell:
    //   ≤5   ("Day 3", "Yes")              → 48pt
    //   ≤12  ("QUR26-169", "Delivery")     → 34pt
    //   ≤22  ("Bahria Phase 8")            → 26pt
    //   23-45                               → 20pt (default)
    //   >45   (long instructions)          → 15pt
    function fitClass(text) {
        const t = String(text == null ? '' : text);
        if (!t || t === '—') return 'cell-value';
        const len = t.length;
        if (len <= 5)  return 'cell-value cv-tiny';
        if (len <= 12) return 'cell-value cv-short';
        if (len <= 22) return 'cell-value cv-mid';
        if (len > 45)  return 'cell-value cv-long';
        return 'cell-value';
    }

    // Strips noise from the product name so the cell shows just the
    // meat description, e.g.:
    //   "Qurbani '26 - Goat (Bakra) Day 3 (Goat (Bakra))" + cat="Goat (Bakra)"
    //     → "Goat (Bakra)"
    // Three cleanups:
    //   1) Drop the leading "Qurbani 'YY - " (any 1-2 digit year) — the
    //      brand strip already says "Qurbani '26", so repeating it in
    //      every cell wastes space.
    //   2) Drop "Day N" embedded in the product name — there's already a
    //      dedicated DAY cell on the label, so duplicating it in the
    //      Qurbani cell is noise. Match patterns like "Day 3", " - Day 3",
    //      " (Day 3)" anywhere in the name.
    //   3) Don't append the category in parens if it's already part of
    //      the product name (avoids the duplication seen earlier).
    function cleanProductName(name, category) {
        let t = String(name || '').trim();
        t = t.replace(/^qurbani\s*['\u2018\u2019]?\s*\d{1,2}\s*-\s*/i, '');
        // Strip embedded Day N — handles "- Day 3", "(Day 3)", " Day 3 ",
        // and trailing/leading occurrences. Run twice in case of multiple.
        t = t.replace(/[\s\-,]*\(?\s*day\s*\d+\s*\)?/gi, '').trim();
        // Clean up double spaces and stray punctuation left behind.
        t = t.replace(/\s+/g, ' ').replace(/\s*-\s*$/, '').trim();
        const cat = String(category || '').trim();
        if (cat && t.toLowerCase().indexOf(cat.toLowerCase()) === -1) {
            t = t + ' (' + cat + ')';
        }
        return t || '—';
    }

    function buildLabelHTML(l) {
        // Field mapping (left-side label → underlying data):
        //   "Qurbani"      → cleaned product_name (no "Qurbani '26 -" prefix,
        //                    no duplicate category)
        //   "Qurbani Type" → qurbani_type (e.g., "Bakra Q-2")
        //   "Trotters"     → qurbani_paya (Yes/No / "Bhunnay Paaye")
        //   "No. of Boxes" → "X/Y" — compact form for the cell
        const customerName = esc(l.customer_name || '—');
        const orderNo      = esc(l.order_number || '—');
        const day          = esc(l.qurbani_day || '—');
        const region       = esc(l.qurbani_region || '—');
        const product      = esc(cleanProductName(l.product_name, l.category_level_2));
        const slot         = esc(l.qurbani_slot || '—');
        const subRegion    = esc(l.qurbani_sub_region || '—');
        const delivery     = esc(l.qurbani_delivery_type || '—');
        const trotters     = esc(l.qurbani_paya || '—');
        // Compact form ("1/2") for the small cell so it doesn't wrap on
        // narrow A4 columns. The big box-number block at the top still
        // uses the verbose "1 of 2" form because it's the visual hero.
        const noOfBoxes    = l.position + '/' + l.bundle_size;
        const qurbaniType  = esc(l.qurbani_type || '—');
        const instructions = esc(l.instructions || '');
        const phone        = esc(l.customer_phone || '');
        const printStamp   = new Date().toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

        let html = '<div class="label-page">';

        // Branded header
        html += '<div class="brand-strip">' +
                '<div class="brand-name">NIZAMI FARMS</div>' +
                '<div class="brand-tag">Qurbani \'26 · Box Label</div>' +
                '</div>';

        // Big box number
        html += '<div class="box-block">' +
                '<span class="box-num">' + l.position + '</span>' +
                '<span class="box-of">of ' + l.bundle_size + '</span>' +
                '</div>';

        // Customer Name banner (full-width row)
        html += '<table class="info-grid">';
        html += '<tr><td colspan="3" style="background:#fffbeb;">' +
                '<div class="cell-label">Customer Name</div>' +
                '<div class="cell-value xl">' + customerName + '</div>' +
                '</td></tr>';

        // Row: Order No. | Day | Region — fitClass() picks the font size
        // tier so short values like "Day 3" really fill the cell, while
        // long values fall back to the default size.
        html += '<tr>' +
                '<td><div class="cell-label">Order No.</div><div class="' + fitClass(l.order_number) + '">' + orderNo + '</div></td>' +
                '<td><div class="cell-label">Day</div><div class="' + fitClass(l.qurbani_day) + '">' + day + '</div></td>' +
                '<td><div class="cell-label">Region</div><div class="' + fitClass(l.qurbani_region) + '">' + region + '</div></td>' +
                '</tr>';

        // Row: Qurbani | Slot | Sub-Region. Use the cleaned product name
        // for fit calculation so the size tier reflects what's actually
        // shown (not the long raw value with the "Qurbani '26 -" prefix).
        const productLen = cleanProductName(l.product_name, l.category_level_2);
        html += '<tr>' +
                '<td><div class="cell-label">Qurbani</div><div class="' + fitClass(productLen) + '">' + product + '</div></td>' +
                '<td><div class="cell-label">Slot</div><div class="' + fitClass(l.qurbani_slot) + '">' + slot + '</div></td>' +
                '<td><div class="cell-label">Sub-Region</div><div class="' + fitClass(l.qurbani_sub_region) + '">' + subRegion + '</div></td>' +
                '</tr>';

        // Row: Delivery / Self Collection | Trotters | No. of Boxes.
        // No. of Boxes keeps the explicit "lg" override — it's the
        // visual hero echoing the big block at the top.
        html += '<tr>' +
                '<td><div class="cell-label">Delivery / Self Collection</div><div class="' + fitClass(l.qurbani_delivery_type) + '">' + delivery + '</div></td>' +
                '<td><div class="cell-label">Trotters (Paya)</div><div class="' + fitClass(l.qurbani_paya) + '">' + trotters + '</div></td>' +
                '<td style="background:#fef3c7;"><div class="cell-label">No. of Boxes</div><div class="cell-value lg">' + noOfBoxes + '</div></td>' +
                '</tr>';

        // Row: Qurbani Type | Instructions (spans 2 cols).
        // Instructions stay capped at 14pt because they can be a long
        // sentence; bumping higher risks overflow on multi-line text.
        html += '<tr>' +
                '<td><div class="cell-label">Qurbani Type</div><div class="' + fitClass(l.qurbani_type) + '">' + qurbaniType + '</div></td>' +
                '<td colspan="2"><div class="cell-label">Instructions</div><div class="cell-value" style="font-size:14pt;font-weight:600;line-height:1.3;">' + (instructions || '—') + '</div></td>' +
                '</tr>';

        html += '</table>';

        // Footer: phone + order # + print stamp
        html += '<div class="label-footer">';
        html += '<span class="phone">' + (phone ? '☎ ' + phone : '') + '</span>';
        html += '<span>Order #' + orderNo + '</span>';
        html += '<span class="stamp">Printed ' + printStamp + '</span>';
        html += '</div>';

        html += '</div>';
        return html;
    }

    async function markPrinted(labels) {
        const boxes = labels.map(l => ({
            line_item_id: l.line_item_id,
            position: l.position,
            bundle_size: l.bundle_size,
            bundle_key: l.bundle_key
        }));
        try {
            await fetch('/qurbani/api/box-print/mark', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ boxes })
            });
        } catch (e) {
            console.error('Failed to mark printed:', e);
        }
    }

    // ── Print modal (full picker) ──────────────────────────────────
    function openPrintModal() {
        document.getElementById('printOverlay').style.display = 'block';
        document.getElementById('printModal').style.display = 'block';
        loadBoxLabels();
    }

    function closePrintModal() {
        document.getElementById('printOverlay').style.display = 'none';
        document.getElementById('printModal').style.display = 'none';
    }

    function loadBoxLabels() {
        const params = collectFilterParams();
        document.getElementById('printLabelList').innerHTML = '<div class="p-4 text-center text-gray-400">Loading labels...</div>';

        fetch('/qurbani/api/box-labels?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed');
                allLabels = data.labels || [];
                renderPrintSummary(data.summary);
                renderPrintLabels();
            })
            .catch(err => {
                document.getElementById('printLabelList').innerHTML = '<div class="p-4 text-center text-red-500">' + esc(err.message) + '</div>';
            });
    }

    function renderPrintSummary(summary) {
        if (!summary) return;
        document.getElementById('printSummary').innerHTML =
            '<b>' + summary.total + '</b> labels · ' +
            '<span style="color:#059669;font-weight:600;">' + summary.printed + ' printed</span> · ' +
            '<span style="color:#d97706;font-weight:600;">' + summary.pending + ' pending</span>';
    }

    function setPrintFilter(f) {
        printFilter = f;
        document.querySelectorAll('.qo-print-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.printFilter === f);
        });
        renderPrintLabels();
    }

    function renderPrintLabels() {
        let filtered = allLabels;
        if (printFilter === 'pending') filtered = allLabels.filter(l => !l.is_printed);
        else if (printFilter === 'printed') filtered = allLabels.filter(l => l.is_printed);

        if (!filtered.length) {
            document.getElementById('printLabelList').innerHTML = '<div class="p-4 text-center text-gray-400">No labels in this category.</div>';
            updatePrintCount();
            return;
        }

        let html = '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
        html += '<thead><tr style="background:#f9fafb;position:sticky;top:0;">';
        html += '<th style="padding:6px 8px;text-align:left;width:30px;"><input type="checkbox" id="selectAllCb" onchange="toggleAllCb(this)"></th>';
        html += '<th style="padding:6px 8px;text-align:left;">Box</th>';
        html += '<th style="padding:6px 8px;text-align:left;">Customer</th>';
        html += '<th style="padding:6px 8px;text-align:left;">Region</th>';
        html += '<th style="padding:6px 8px;text-align:left;">Order</th>';
        html += '<th style="padding:6px 8px;text-align:center;">State</th></tr></thead><tbody>';

        filtered.forEach(l => {
            const idx = allLabels.indexOf(l);
            const stale = l.is_stale_print ? 'background:#fef3c7;' : '';
            html += '<tr style="' + stale + '">';
            html += '<td style="padding:5px 8px;border-top:1px solid #f3f4f6;"><input type="checkbox" class="label-cb" data-idx="' + idx + '"' + (!l.is_printed ? ' checked' : '') + ' onchange="updatePrintCount()"></td>';
            html += '<td style="padding:5px 8px;border-top:1px solid #f3f4f6;font-weight:700;">' + l.position + '/' + l.bundle_size + '</td>';
            html += '<td style="padding:5px 8px;border-top:1px solid #f3f4f6;">' + esc(l.customer_name) + '</td>';
            html += '<td style="padding:5px 8px;border-top:1px solid #f3f4f6;">' + esc((l.qurbani_region || '') + (l.qurbani_sub_region ? ' / ' + l.qurbani_sub_region : '')) + '</td>';
            html += '<td style="padding:5px 8px;border-top:1px solid #f3f4f6;">#' + esc(l.order_number || '') + '</td>';
            html += '<td style="padding:5px 8px;border-top:1px solid #f3f4f6;text-align:center;">';
            if (l.is_printed) html += '<span style="color:#059669;font-size:11px;">✓ ' + esc(l.printed_by_name || 'Printed') + '</span>';
            else html += '<span style="color:#9ca3af;font-size:11px;">Pending</span>';
            html += '</td></tr>';
        });

        html += '</tbody></table>';
        document.getElementById('printLabelList').innerHTML = html;
        updatePrintCount();
    }

    function toggleAllCb(master) {
        document.querySelectorAll('.label-cb').forEach(cb => { cb.checked = master.checked; });
        updatePrintCount();
    }

    function selectAllLabels() {
        document.querySelectorAll('.label-cb').forEach(cb => { cb.checked = true; });
        const m = document.getElementById('selectAllCb'); if (m) m.checked = true;
        updatePrintCount();
    }

    function deselectAllLabels() {
        document.querySelectorAll('.label-cb').forEach(cb => { cb.checked = false; });
        const m = document.getElementById('selectAllCb'); if (m) m.checked = false;
        updatePrintCount();
    }

    function updatePrintCount() {
        const count = document.querySelectorAll('.label-cb:checked').length;
        const btn = document.getElementById('printSelectedBtn');
        btn.textContent = 'Print Selected (' + count + ')';
        btn.disabled = count === 0;
    }

    // ── Helpers ────────────────────────────────────────────────────
    function toggleGroup(hdr) {
        const body = hdr.nextElementSibling;
        if (!body) return;
        if (body.classList.contains('hidden')) {
            body.classList.remove('hidden');
            hdr.classList.remove('collapsed');
        } else {
            body.classList.add('hidden');
            hdr.classList.add('collapsed');
        }
    }

    function getCatClass(cat) {
        if (!cat) return '';
        const lower = cat.toLowerCase();
        if (lower.includes('bakra')) return 'cat-bakra';
        if (lower.includes('hissa')) return 'cat-hissa';
        if (lower.includes('lamb') || lower.includes('dumba')) return 'cat-lamb';
        return '';
    }

    function ucwords(str) { return String(str || '').replace(/\b\w/g, c => c.toUpperCase()); }

    function esc(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

    function resetFilters() {
        document.querySelectorAll('.qo-filter').forEach(el => {
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
            else el.value = '';
        });
        window._activeRegion = null;
        // Re-populate dependent dropdowns since their options depend on
        // the (now-cleared) parent filters.
        updateSlotDropdown();
        updateSubRegionDropdown();
        loadItems();
    }

    // Expose globals
    window.loadItems = loadItems;
    window.debouncedLoad = debouncedLoad;
    window.resetFilters = resetFilters;
    window.setActiveRegion = setActiveRegion;
    window.onDayChanged = onDayChanged;
    window.onDeliveryTypeChanged = onDeliveryTypeChanged;
    window.toggleGroup = toggleGroup;
    window.changeStatus = changeStatus;
    window.changeRider = changeRider;
    window.printItem = printItem;
    window.printGroup = printGroup;
    window.printFromModal = printFromModal;
    window.cancelPrintRun = cancelPrintRun;
    window.openPrintModal = openPrintModal;
    window.closePrintModal = closePrintModal;
    window.setPrintFilter = setPrintFilter;
    window.toggleAllCb = toggleAllCb;
    window.selectAllLabels = selectAllLabels;
    window.deselectAllLabels = deselectAllLabels;
    window.updatePrintCount = updatePrintCount;
    window.openRidersMap = openRidersMap;
    window.closeRidersMap = closeRidersMap;
    // Phase 2 (May-2026) — Timeline modal. Inline onclick handlers
    // on the per-card "🕒 Timeline" button + modal backdrop / close
    // need these reachable on window.*; otherwise the browser
    // throws "openTimeline is not defined" the first time it's
    // pressed. Same pattern as the other delegations above.
    window.openTimeline = openTimeline;
    window.closeTimeline = closeTimeline;

    // ===== Phase C2 (May-2026) — Riders Map modal =====================
    // Lazy-loads the Google Maps JS API on first open. Same key as
    // attendance/locations.blade.php and the new Qurbani settings card.
    let _qoMap = null;
    let _qoMapMarkers = []; // marker references so we can clear between rider switches
    let _qoMapPoly = null;  // polyline (rider GPS → next OFD bundle)
    let _qoMapBoundsLast = null;
    let _qoMapActiveRider = null;
    let _qoMapRefreshTimer = null;

    function ensureQoGoogleMaps(cb) {
        if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') return cb();
        if (window._qoMapsLoading) {
            const prev = window._qoMapsLoading;
            window._qoMapsLoading = function() { prev(); cb(); };
            return;
        }
        window._qoMapsLoading = cb;
        const script = document.createElement('script');
        const apiKey = 'AIzaSyBFCBj7ebflrliC1pHq0XhsjuW18Q3iElk';
        script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places`;
        script.async = true; script.defer = true;
        script.onload = function() {
            const fn = window._qoMapsLoading; window._qoMapsLoading = null;
            if (typeof fn === 'function') fn();
        };
        script.onerror = function() {
            toast('Failed to load Google Maps — check internet / API key.', 'error');
            window._qoMapsLoading = null;
        };
        window.gm_authFailure = function() { toast('Google Maps API key rejected. Contact admin.', 'error'); };
        document.head.appendChild(script);
    }

    function openRidersMap() {
        document.getElementById('qoMapOverlay').style.display = 'block';
        const modal = document.getElementById('qoMapModal');
        modal.style.display = 'flex';
        // Build the riders list from the Rider filter <select> options
        // (already populated server-side with all active Qurbani-permitted
        // riders). Filtered down to riders with at least one assigned
        // line item visible in `allItems`.
        const ridersWithItems = {};
        (window.QURBANI_ORDERS_ALL_ITEMS_REF && window.QURBANI_ORDERS_ALL_ITEMS_REF()).forEach(it => {
            if (it.assigned_rider_user_id && it.assigned_rider_name) {
                ridersWithItems[it.assigned_rider_user_id] = it.assigned_rider_name;
            }
        });
        const ids = Object.keys(ridersWithItems);
        let html = '';
        if (ids.length === 0) {
            html = '<div style="padding:12px;font-size:12px;color:#9ca3af;">No riders with assigned items right now.</div>';
        } else {
            ids.sort((a, b) => ridersWithItems[a].localeCompare(ridersWithItems[b]));
            ids.forEach(id => {
                html += '<button type="button" data-rider-id="' + id + '" class="qo-map-rider-row" style="display:block;width:100%;text-align:left;padding:8px 10px;border:none;background:transparent;border-radius:6px;cursor:pointer;font-size:13px;color:#1f2937;font-weight:600;">';
                html += '🛵 ' + (ridersWithItems[id] || ('Rider #' + id));
                html += '</button>';
            });
        }
        document.getElementById('qoMapRidersInner').innerHTML = html;
        // Lazy-init map
        ensureQoGoogleMaps(initQoMap);
        // Wire delegation for the rider rows.
        document.getElementById('qoMapRidersInner').onclick = function(e) {
            const btn = e.target.closest('button[data-rider-id]');
            if (!btn) return;
            // Highlight the active row.
            document.querySelectorAll('.qo-map-rider-row').forEach(b => {
                b.style.background = 'transparent'; b.style.color = '#1f2937';
            });
            btn.style.background = '#1e40af'; btn.style.color = '#fff';
            loadDispatchMapForRider(parseInt(btn.dataset.riderId, 10));
        };
    }

    function closeRidersMap() {
        document.getElementById('qoMapOverlay').style.display = 'none';
        document.getElementById('qoMapModal').style.display = 'none';
        if (_qoMapRefreshTimer) { clearInterval(_qoMapRefreshTimer); _qoMapRefreshTimer = null; }
        _qoMapActiveRider = null;
    }

    // ── Phase 2 (May-2026) — Timeline modal ────────────────────────
    // Single line-item scope; lazy-loaded on click. The endpoint
    // returns everything in one shot so we just render. We keep the
    // modal open even after fetch failure so the user can close it
    // cleanly — no auto-dismiss on errors.
    function openTimeline(lineItemId) {
        const overlay = document.getElementById('qoTimelineOverlay');
        const modal   = document.getElementById('qoTimelineModal');
        const sub     = document.getElementById('qoTimelineSubtitle');
        const body    = document.getElementById('qoTimelineBody');
        overlay.style.display = 'block';
        modal.style.display = 'flex';
        sub.textContent = 'Loading…';
        body.innerHTML = '<div style="text-align:center;padding:40px 0;color:#9ca3af;font-size:13px;">Loading…</div>';
        fetch('/qurbani/api/line-items/' + lineItemId + '/timeline', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }
        })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.success) {
                body.innerHTML = '<div style="padding:30px 0;color:#dc2626;font-size:13px;text-align:center;">' + esc(d && d.message ? d.message : 'Failed to load timeline.') + '</div>';
                return;
            }
            renderTimeline(d);
        })
        .catch(e => {
            body.innerHTML = '<div style="padding:30px 0;color:#dc2626;font-size:13px;text-align:center;">' + esc(e.message || 'Network error.') + '</div>';
        });
    }

    function closeTimeline() {
        document.getElementById('qoTimelineOverlay').style.display = 'none';
        document.getElementById('qoTimelineModal').style.display = 'none';
    }

    function renderTimeline(d) {
        const sub  = document.getElementById('qoTimelineSubtitle');
        const body = document.getElementById('qoTimelineBody');

        // Compact relative time helper. Used inside dispatch / ETA
        // notes so the user gets "today 16:32" instead of an ISO blob.
        function fmtTime(ts) {
            if (!ts) return '';
            try {
                const dt = new Date(ts.replace(' ', 'T'));
                return dt.toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ts; }
        }
        function fmtTimeOnly(ts) {
            if (!ts) return '';
            try {
                const dt = new Date(ts.replace(' ', 'T'));
                return dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ts; }
        }

        // Subtitle in the modal header so the user knows which order
        // they're looking at without reading the body. Combines
        // customer + order_number for quick scan.
        const order = d.order || {};
        const li = d.line_item || {};
        const subParts = [];
        if (order.customer_name) subParts.push(order.customer_name);
        if (order.order_number)  subParts.push('#' + order.order_number);
        sub.textContent = subParts.join(' · ');

        let html = '';

        // ── Order details strip ────────────────────────────────────
        const itemBits = [];
        if (li.qurbani_day)  itemBits.push(esc(li.qurbani_day));
        if (li.qurbani_slot) itemBits.push(esc(li.qurbani_slot));
        if (li.qurbani_delivery_type) itemBits.push(esc(li.qurbani_delivery_type));
        if (li.qurbani_sub_region)    itemBits.push(esc(li.qurbani_sub_region));
        if (itemBits.length) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">';
            itemBits.forEach(t => {
                html += '<span style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:600;color:#374151;">' + t + '</span>';
            });
            html += '</div>';
        }

        // ── Delay alert (top of body when active) ──────────────────
        if (d.delay_alert && d.delay_alert.active) {
            html += '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:10px 12px;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;">';
            html += '<span style="font-size:18px;line-height:1;">⚠️</span>';
            html += '<div style="flex:1;font-size:13px;color:#92400e;line-height:1.45;"><strong>Running late.</strong> ' + esc(d.delay_alert.reason) + '</div>';
            html += '</div>';
        }

        // ── Rider + Dispatch summary ───────────────────────────────
        if (d.rider || d.dispatch) {
            html += '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Rider &amp; Dispatch</div>';
            if (d.rider) {
                html += '<div style="font-size:13px;color:#1f2937;margin-bottom:4px;"><strong>🛵 Rider:</strong> ' + esc(d.rider.name) + '</div>';
            } else {
                html += '<div style="font-size:13px;color:#9ca3af;margin-bottom:4px;font-style:italic;">No rider assigned yet.</div>';
            }
            if (d.dispatch) {
                let dispLine = '<strong>🚀 Dispatched:</strong> ' + esc(fmtTime(d.dispatch.at));
                if (d.dispatch.by_name) dispLine += ' · by ' + esc(d.dispatch.by_name);
                html += '<div style="font-size:13px;color:#1f2937;margin-bottom:4px;">' + dispLine + '</div>';
                if (d.dispatch.started_at) {
                    html += '<div style="font-size:13px;color:#0e7490;"><strong>🏁 Rider started:</strong> ' + esc(fmtTime(d.dispatch.started_at)) + '</div>';
                }
            } else {
                html += '<div style="font-size:13px;color:#9ca3af;font-style:italic;">Not yet dispatched.</div>';
            }
            html += '</div>';
        }

        // ── Current ETA ────────────────────────────────────────────
        if (d.current_eta) {
            html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Current ETA</div>';
            html += '<div style="font-size:18px;font-weight:700;color:#1e3a8a;">⏱ ' + esc(fmtTime(d.current_eta.at)) + '</div>';
            if (d.current_eta.note) {
                html += '<div style="font-size:11px;color:#1d4ed8;margin-top:4px;">' + esc(d.current_eta.note) + '</div>';
            } else if (d.current_eta.is_initial && d.current_eta.calculated_at) {
                html += '<div style="font-size:11px;color:#1d4ed8;margin-top:4px;">Initial estimate from dispatch.</div>';
            }
            // Phase 4 (May-2026): show the live route position next to
            // the ETA. The number self-corrects on every refresh as
            // earlier stops get delivered (their qurbani_delivered_at
            // gets stamped and they drop out of the ahead count).
            if (d.route_position && d.route_position.is_in_dispatch) {
                const rp = d.route_position;
                const totalLine = (rp.total_remaining > 0)
                    ? rp.total_remaining + ' total stop' + (rp.total_remaining === 1 ? '' : 's') + ' still pending in this dispatch'
                    : '';
                html += '<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #bfdbfe;display:flex;align-items:center;gap:8px;">'
                    + '<span style="background:#1e40af;color:#fff;border-radius:6px;padding:3px 8px;font-size:12px;font-weight:700;">🚚 ' + esc(rp.label) + '</span>'
                    + (totalLine ? '<span style="font-size:11px;color:#1d4ed8;">' + esc(totalLine) + '</span>' : '')
                    + '</div>';
            }
            html += '</div>';
        } else if (d.dispatch) {
            html += '<div style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:13px;color:#6b7280;">⏱ ETA not yet calculated for this stop.</div>';
        }

        // ── Status events timeline (most recent at the bottom; we
        //    walk top-to-bottom in order of occurrence so the eye
        //    follows the order's lifecycle naturally). ──────────────
        html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
        html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Status Events</div>';
        if (!d.events || d.events.length === 0) {
            html += '<div style="font-size:13px;color:#9ca3af;font-style:italic;">No status events yet.</div>';
        } else {
            d.events.forEach((ev, idx) => {
                const isLast = idx === d.events.length - 1;
                html += '<div style="display:flex;gap:10px;align-items:flex-start;position:relative;padding-bottom:' + (isLast ? '0' : '14px') + ';">';
                if (!isLast) {
                    html += '<div style="position:absolute;left:11px;top:22px;bottom:0;width:2px;background:' + esc(ev.color || '#e5e7eb') + ';opacity:0.35;"></div>';
                }
                html += '<div style="width:24px;height:24px;border-radius:50%;background:' + esc(ev.color || '#6b7280') + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;line-height:1;z-index:1;">' + esc(ev.icon || '•') + '</div>';
                html += '<div style="flex:1;min-width:0;">';
                html += '<div style="font-size:13px;font-weight:600;color:#1f2937;">' + esc(ev.label) + '</div>';
                let metaParts = [];
                if (ev.at) metaParts.push(fmtTime(ev.at));
                if (ev.by) metaParts.push('by ' + ev.by);
                if (metaParts.length) {
                    html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' + esc(metaParts.join(' · ')) + '</div>';
                }
                html += '</div>';
                html += '</div>';
            });
        }
        html += '</div>';

        // ── WhatsApp today ─────────────────────────────────────────
        const wa = d.whatsapp_today || {};
        html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
        html += '<div style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">📱 WhatsApp · Today</div>';
        if (!wa.last_inbound && !wa.last_outbound) {
            html += '<div style="font-size:13px;color:#6b7280;font-style:italic;">No messages exchanged today.</div>';
        } else {
            if (wa.last_inbound) {
                html += '<div style="margin-bottom:8px;">';
                html += '<div style="font-size:11px;color:#15803d;font-weight:700;margin-bottom:2px;">⬅️ Last from customer · ' + esc(fmtTimeOnly(wa.last_inbound.at)) + '</div>';
                html += '<div style="font-size:13px;color:#1f2937;line-height:1.45;">' + esc(wa.last_inbound.preview || '(empty message)') + '</div>';
                html += '</div>';
            } else {
                html += '<div style="margin-bottom:8px;font-size:12px;color:#6b7280;font-style:italic;">No customer messages today.</div>';
            }
            if (wa.last_outbound) {
                html += '<div>';
                let label = 'Last sent';
                if (wa.last_outbound.is_template) label += ' (template)';
                html += '<div style="font-size:11px;color:#15803d;font-weight:700;margin-bottom:2px;">➡️ ' + esc(label) + ' · ' + esc(fmtTimeOnly(wa.last_outbound.at));
                if (wa.last_outbound.by) html += ' · by ' + esc(wa.last_outbound.by);
                html += '</div>';
                html += '<div style="font-size:13px;color:#1f2937;line-height:1.45;">' + esc(wa.last_outbound.preview || '(empty message)') + '</div>';
                html += '</div>';
            } else {
                html += '<div style="font-size:12px;color:#6b7280;font-style:italic;">Nothing sent today.</div>';
            }
        }
        html += '</div>';

        body.innerHTML = html;
    }

    function initQoMap() {
        const c = document.getElementById('qoMapContainer');
        if (!c) return;
        if (!_qoMap) {
            _qoMap = new google.maps.Map(c, {
                center: { lat: 33.6844, lng: 73.0479 },
                zoom: 12,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: true,
            });
        }
    }

    function clearQoMapMarkers() {
        _qoMapMarkers.forEach(m => m.setMap(null));
        _qoMapMarkers = [];
        if (_qoMapPoly) { _qoMapPoly.setMap(null); _qoMapPoly = null; }
    }

    async function loadDispatchMapForRider(riderId) {
        if (!riderId) return;
        _qoMapActiveRider = riderId;
        ensureQoGoogleMaps(() => {
            initQoMap();
            fetchAndRenderDispatchMap(riderId);
        });
        // 30-second auto-refresh on rider GPS while the modal is open.
        if (_qoMapRefreshTimer) clearInterval(_qoMapRefreshTimer);
        _qoMapRefreshTimer = setInterval(() => {
            if (_qoMapActiveRider) fetchAndRenderDispatchMap(_qoMapActiveRider);
        }, 30000);
    }

    async function fetchAndRenderDispatchMap(riderId) {
        try {
            const r = await fetch('{{ url("/qurbani/api/riders") }}/' + riderId + '/dispatch-map');
            const j = await r.json();
            if (!j.success) { toast(j.message || 'Failed to load dispatch map', 'error'); return; }
            renderDispatchMap(j);
        } catch (e) {
            toast('Failed to load map data: ' + (e.message || e), 'error');
        }
    }

    function renderDispatchMap(d) {
        if (!_qoMap) return;
        clearQoMapMarkers();

        const subtitleEl = document.getElementById('qoMapSubtitle');
        const legend = document.getElementById('qoMapLegend');

        if (!d.dispatched_at) {
            subtitleEl.textContent = (d.rider?.name || 'Rider') + ' — no active dispatch yet.';
            legend.innerHTML = '<span style="color:#9ca3af;">Nothing dispatched. Press "Set Route" then "Start Delivery" on the rider planner.</span>';
            return;
        }

        const ofd = d.ofd_bundles || [];
        const delivered = d.delivered_bundles || [];
        subtitleEl.textContent = (d.rider?.name || 'Rider') + ' · ' + ofd.length + ' OFD · ' + delivered.length + ' delivered · dispatched ' + (d.dispatched_at || '');
        legend.innerHTML = '<span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#3B82F6;margin-right:4px;"></span> Rider GPS</span>'
            + ' <span><span style="display:inline-block;width:10px;height:10px;background:#F59E0B;margin-right:4px;"></span> OFD bundle</span>'
            + ' <span><span style="display:inline-block;width:10px;height:10px;background:#10B981;margin-right:4px;"></span> Delivered</span>'
            + ' <span><span style="display:inline-block;width:10px;height:10px;background:#7C3AED;margin-right:4px;"></span> Base</span>'
            + (d.rider_gps?.status ? ' <span style="margin-left:auto;font-weight:600;color:' + (d.rider_gps.status === 'live' ? '#059669' : d.rider_gps.status === 'recent' ? '#B45309' : d.rider_gps.status === 'stale' ? '#DC2626' : '#6B7280') + ';">GPS ' + d.rider_gps.status + (d.rider_gps.age_minutes != null ? ' (' + d.rider_gps.age_minutes + ' min)' : '') + '</span>' : '');

        const bounds = new google.maps.LatLngBounds();
        let anyPin = false;

        // Base pin
        if (d.base && d.base.lat && d.base.lng) {
            const m = new google.maps.Marker({
                position: { lat: d.base.lat, lng: d.base.lng },
                map: _qoMap,
                label: { text: '🏪', fontSize: '18px' },
                title: 'Qurbani Base — ' + d.base.name,
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 12, fillColor: '#7C3AED', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
            });
            _qoMapMarkers.push(m);
            bounds.extend(m.getPosition()); anyPin = true;
        }

        // Rider GPS pin (blue, larger)
        if (d.rider_gps && d.rider_gps.lat && d.rider_gps.lng) {
            const m = new google.maps.Marker({
                position: { lat: d.rider_gps.lat, lng: d.rider_gps.lng },
                map: _qoMap,
                title: '🛵 Rider — GPS ' + (d.rider_gps.status || 'unknown') + (d.rider_gps.age_minutes != null ? ' · ' + d.rider_gps.age_minutes + ' min ago' : ''),
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: '#3B82F6', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 },
                zIndex: 999,
            });
            _qoMapMarkers.push(m);
            bounds.extend(m.getPosition()); anyPin = true;
        }

        // Delivered bundles (green, with check)
        delivered.forEach((b, idx) => {
            if (!b.lat || !b.lng) return;
            const m = new google.maps.Marker({
                position: { lat: b.lat, lng: b.lng },
                map: _qoMap,
                label: { text: '✓', color: '#fff', fontSize: '12px', fontWeight: '700' },
                title: '✓ Delivered #' + idx + ' · ' + (b.customer_name || '') + ' · ' + (b.order_number || ''),
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 11, fillColor: '#10B981', fillOpacity: 0.85, strokeColor: '#fff', strokeWeight: 2 },
            });
            const info = new google.maps.InfoWindow({
                content: '<div style="font-size:12px;line-height:1.4;"><b>Delivered</b><br>' + esc(b.customer_name || '') + '<br>Order ' + esc(b.order_number || '') + '<br><span style="color:#6b7280;">at ' + esc(b.delivered_at || '') + '</span></div>',
            });
            m.addListener('click', () => info.open(_qoMap, m));
            _qoMapMarkers.push(m);
            bounds.extend(m.getPosition()); anyPin = true;
        });

        // OFD bundles (orange, numbered)
        ofd.forEach((b, idx) => {
            if (!b.lat || !b.lng) return;
            const seq = b.priority || (idx + 1);
            const m = new google.maps.Marker({
                position: { lat: b.lat, lng: b.lng },
                map: _qoMap,
                label: { text: String(seq), color: '#fff', fontSize: '13px', fontWeight: '800' },
                title: 'Stop ' + seq + ' · ' + (b.customer_name || ''),
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 13, fillColor: '#F59E0B', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
            });
            const eta = b.estimated_delivery_at ? new Date(String(b.estimated_delivery_at).replace(' ', 'T')).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'}) : '—';
            const info = new google.maps.InfoWindow({
                content: '<div style="font-size:12px;line-height:1.4;"><b>Stop ' + seq + '</b><br>' + esc(b.customer_name || '') + '<br>Order ' + esc(b.order_number || '') + '<br>ETA ' + esc(eta) + '<br><span style="color:#6b7280;">' + esc((b.qurbani_sub_region || b.qurbani_region) || '') + '</span></div>',
            });
            m.addListener('click', () => info.open(_qoMap, m));
            _qoMapMarkers.push(m);
            bounds.extend(m.getPosition()); anyPin = true;
        });

        // Soft polyline from rider GPS → next OFD stop, just to make it
        // visually obvious where the rider is heading next.
        if (d.rider_gps && d.rider_gps.lat && d.rider_gps.lng && ofd.length > 0 && ofd[0].lat && ofd[0].lng) {
            _qoMapPoly = new google.maps.Polyline({
                path: [
                    { lat: d.rider_gps.lat, lng: d.rider_gps.lng },
                    { lat: ofd[0].lat, lng: ofd[0].lng },
                ],
                geodesic: true,
                strokeColor: '#3B82F6',
                strokeOpacity: 0.7,
                strokeWeight: 3,
                map: _qoMap,
            });
        }

        if (anyPin) {
            // Only auto-fit bounds the first time we render this rider's
            // dispatch — subsequent 30s GPS refreshes shouldn't re-zoom
            // and yank the user's current pan/zoom around.
            const boundsKey = (d.rider?.id || 0) + ':' + (d.dispatched_at || '');
            if (_qoMapBoundsLast !== boundsKey) {
                _qoMap.fitBounds(bounds, 60);
                _qoMapBoundsLast = boundsKey;
            }
        }
    }

    // Expose a tiny accessor so openRidersMap() can read the latest
    // items array without polluting the rest of the IIFE scope.
    window.QURBANI_ORDERS_ALL_ITEMS_REF = function() { return allItems || []; };

    document.addEventListener('DOMContentLoaded', function() {
        bindRegionChipDelegation();
        bindGroupPrintDelegation();
        // Populate dependent dropdowns BEFORE the first load so users
        // see the right slot/sub-region options on initial paint.
        updateSlotDropdown();
        updateSubRegionDropdown();
        loadItems();
    });
})();
</script>
@endpush
