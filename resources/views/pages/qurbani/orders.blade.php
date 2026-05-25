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

/* ===== Phase 6 (May-2026) — Qurbani Location Request feature ====== */

/* Toolbar badge — small "12 ready" pill that sits on top of the
   Request Locations toolbar button when there are replies waiting
   for staff to review. */
.qo-loc-badge { position: absolute; top: -6px; right: -6px; background: #dc2626; color: #fff;
                font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 10px;
                box-shadow: 0 2px 4px rgba(0,0,0,.15); min-width: 18px; text-align: center; }
.qo-toolbar-btn { position: relative; } /* lets the badge anchor */

/* Per-card "📍 Request location" button — same shape as the other
   qo-action-btn rows but tinted amber so the eye is drawn to it. */
.qo-action-btn.loc-req { background: #fffbeb; border-color: #fcd34d; color: #92400e; }
.qo-action-btn.loc-req:hover { background: #fef3c7; border-color: #f59e0b; }
.qo-action-btn.loc-req.is-sent { background: #ecfeff; border-color: #67e8f9; color: #155e75; }
.qo-action-btn.loc-req.is-replied { background: #f0fdf4; border-color: #86efac; color: #166534; }
.qo-action-btn.loc-req:disabled { opacity: 0.6; cursor: not-allowed; }

/* Per-card "no-location" muted chip — when a customer has no
   verified pin we replace the 📍 with this so the row still shows
   something on the verified column. */
.qo-no-verified { color: #d97706; font-size: 13px; cursor: help; }

/* Bulk-send modal — wider than the print modal because the
   customer-picker table needs room. */
.qo-locreq-modal { max-width: 980px !important; }
.qo-locreq-modal table.qo-locreq-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.qo-locreq-modal .qo-locreq-tbl th { text-align: left; padding: 6px 8px; background: #f9fafb;
                                     border-bottom: 1px solid #e5e7eb; color: #374151;
                                     font-weight: 600; font-size: 11px; text-transform: uppercase;
                                     letter-spacing: 0.03em; position: sticky; top: 0; z-index: 1; }
.qo-locreq-modal .qo-locreq-tbl td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; color: #374151; }
.qo-locreq-modal .qo-locreq-tbl tbody tr:hover { background: #fffbeb; }
.qo-locreq-modal .qo-locreq-tbl tbody tr.is-disabled { opacity: 0.55; }
.qo-locreq-list-wrap { max-height: 360px; overflow: auto; border: 1px solid #e5e7eb;
                       border-radius: 8px; background: #fff; }
.qo-locreq-status-pill { display: inline-block; padding: 1px 7px; border-radius: 9999px;
                         font-size: 10px; font-weight: 700; text-transform: uppercase; }
.qo-locreq-status-pill.s-never  { background: #f3f4f6; color: #6b7280; }
.qo-locreq-status-pill.s-sent   { background: #cffafe; color: #155e75; }
.qo-locreq-status-pill.s-reply  { background: #dcfce7; color: #166534; }
.qo-locreq-status-pill.s-saved  { background: #e0e7ff; color: #3730a3; }
.qo-locreq-status-pill.s-failed { background: #fee2e2; color: #991b1b; }

/* Reviewer drawer — right-side panel that slides in over the table.
   Lives at fixed-right with its own scroll so the staff can sort
   through replies without losing the underlying orders page. */
.qo-locreview-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.25);
                        z-index: 9996; display: none; }
.qo-locreview-drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 480px;
                       max-width: 95vw; background: #fff; box-shadow: -8px 0 24px rgba(0,0,0,.15);
                       z-index: 9997; display: none; flex-direction: column; }
.qo-locreview-hdr { padding: 14px 16px; border-bottom: 1px solid #e5e7eb;
                    display: flex; align-items: center; justify-content: space-between; }
.qo-locreview-hdr h2 { font-size: 16px; font-weight: 700; margin: 0; color: #111827; }
.qo-locreview-body { flex: 1; overflow-y: auto; padding: 8px 12px; }
.qo-locreview-foot { padding: 10px 16px; border-top: 1px solid #e5e7eb;
                     display: flex; gap: 8px; justify-content: space-between;
                     align-items: center; background: #f9fafb; }

.qo-locreview-row { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px;
                    margin-bottom: 8px; background: #fff; }
.qo-locreview-row.is-warn { border-color: #f59e0b; background: #fffbeb; }
.qo-locreview-row.is-done { opacity: 0.6; }
.qo-locreview-row .row-top { display: flex; align-items: center; gap: 8px;
                             margin-bottom: 4px; }
.qo-locreview-row .row-cust { font-weight: 700; color: #111827; flex: 1; }
.qo-locreview-row .row-meta { font-size: 11px; color: #6b7280;
                              margin-bottom: 6px; line-height: 1.5; }
.qo-locreview-row .row-actions { display: flex; gap: 6px; }
.qo-locreview-row .row-warn { font-size: 11px; color: #92400e; background: #fef3c7;
                              border: 1px solid #fcd34d; border-radius: 4px;
                              padding: 4px 6px; margin-bottom: 6px; }
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
            {{-- A4 manual-backup sheet print (May-2026). Opens its own
                 filter modal so the user can scope by Category × Day ×
                 Region × Sub-Region × Slot independently of the page
                 filters. Bulk-prints one print window per (Category ×
                 Day × Region × Sub-Region × Slot) section using the
                 same batched print pipeline as box labels. --}}
            <button class="qo-toolbar-btn secondary" onclick="openPrintSheetModal()" title="Open A4 sheet printer (manual paper backup, per-category)">
                🖨️ Print Sheets
            </button>
            {{-- Phase 6 (May-2026) — Qurbani Location Request.
                 The badge floats top-right showing count of replies
                 waiting for staff review (auto-refreshes every 30s).
                 Clicking the button opens the BULK SEND modal; the
                 badge itself is a separate click target that opens
                 the REVIEWER drawer directly. --}}
            <button class="qo-toolbar-btn secondary" id="locReqToolbarBtn"
                    onclick="openLocReqSendModal()"
                    title="Send WhatsApp template to customers without verified pin / review replies">
                📍 Request Locations
                <span id="locReqBadge" class="qo-loc-badge"
                      style="display:none;cursor:pointer;"
                      onclick="event.stopPropagation(); openLocReqReviewDrawer();"
                      title="Click to open the Reviewer drawer">0</span>
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

{{-- ===== A4 Print Sheets modal (May-2026) ==========================
     Manual paper backup of orders, grouped into sheets per
     (Category × Day × Region × Sub-Region × Slot). Quantity column
     uses CATEGORY-SCOPED bundle math: a customer with 2 hissa +
     2 goats sees "1/2, 2/2" on each category's sheet — NOT
     "1/4...4/4" across categories like box labels do.

     Reuses the existing batched-print pipeline (progress banner,
     cancel button, 1.5s pause between batches, popup-blocker
     handling) — one "batch" here = one section's print window. --}}
<div class="qo-modal-overlay" id="sheetOverlay" onclick="closeSheetModal()"></div>
<div class="qo-modal" id="sheetModal" style="max-width: 720px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h2 style="margin:0;">🖨️ Print A4 Sheets</h2>
        <button onclick="closeSheetModal()" style="background:none;border:none;font-size:20px;color:#6b7280;cursor:pointer;">&times;</button>
    </div>
    <p style="font-size:12px;color:#6b7280;margin:0 0 14px;line-height:1.5;">
        Manager-facing paper backup. <b>One A4 sheet per
        (Category &times; Day &times; Region &times; Sub-Region &times; Slot)</b>
        with Region &amp; Sub Region shown at the top of each sheet
        (not as columns &mdash; keeps the rows readable). <b>One row
        per animal</b>: a customer with 2 hissas gets two rows
        showing <code>1/2</code>, <code>2/2</code>; a customer with
        1 hissa gets one row showing <code>1/1</code>. Quantity is
        scoped per&nbsp;customer per&nbsp;category, so other
        customers on the same sheet don&rsquo;t affect a customer&rsquo;s
        denominator. (Box-label numbering is unaffected.) All sheets
        open in <b>one print preview</b> so you can scroll through
        them all and only print when you&rsquo;re satisfied.
    </p>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px 12px;margin-bottom:14px;">
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Category</label>
            <select id="sheetCategory" class="qo-filter" style="width:100%;" onchange="loadSheetPreview()">
                <option value="">All Categories</option>
                {{-- A4 sheets are dispatch backups for actual qurbani animal
                     items only — Charity Rajanpur and Bhunnay Paaye add-ons
                     don't need printed sheets, so they're hidden here.
                     This is a print-modal display filter only — the
                     underlying category list and other features stay
                     untouched. --}}
                @foreach($categories as $cat)
                    @continue(preg_match('/charity|bhunnay/i', $cat) === 1)
                    <option value="{{ $cat }}">{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Day</label>
            <select id="sheetDay" class="qo-filter" style="width:100%;" onchange="updateSheetSlotDropdown(); loadSheetPreview();">
                <option value="">All Days</option>
                @foreach($days as $d)
                <option value="{{ $d }}">{{ $d }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Delivery Type</label>
            <select id="sheetDeliveryType" class="qo-filter" style="width:100%;" onchange="updateSheetSlotDropdown(); loadSheetPreview();">
                <option value="">All Types</option>
                @foreach($deliveryTypes as $dt)
                <option value="{{ $dt }}">{{ $dt }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Region</label>
            <select id="sheetRegion" class="qo-filter" style="width:100%;" onchange="updateSheetSubRegionDropdown(); loadSheetPreview();">
                <option value="">All Regions</option>
                @foreach($regions as $r)
                <option value="{{ $r }}">{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Sub Region</label>
            <select id="sheetSubRegion" class="qo-filter" style="width:100%;" onchange="loadSheetPreview()">
                <option value="">All Sub Regions</option>
                @foreach($subRegions as $sr)
                <option value="{{ $sr }}">{{ $sr }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Slot</label>
            <select id="sheetSlot" class="qo-filter" style="width:100%;" onchange="loadSheetPreview()">
                <option value="">All Slots</option>
                @foreach($slots as $s)
                <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:#fafafa;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:8px;flex-wrap:wrap;">
        <label style="font-size:12px;color:#374151;display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="sheetIncludeDelivered" onchange="loadSheetPreview()">
            Include delivered orders
        </label>
        <span style="font-size:11px;color:#9ca3af;">(default: exclude — these are dispatch backups)</span>
    </div>
    {{-- Sheet-type picker — replaces the older orientation toggle.
         Each team option carries its own orientation, column set,
         row-height and font sizing inside _buildSheetDocument(). The
         shared filter set above stays exactly the same across all
         three teams; only the printed layout changes.
            • Master Sheet (Landscape)  — full record: order#, customer,
              address, contact, qurbani, qty, type, paaye. The "control
              copy" the user keeps on-page.
            • Delivery Team (Landscape) — driver manifest: order#,
              customer, address (real street), contact, qurbani, no.
              of packs. One row per bundle (May-2026 — collapsed,
              so a 7-pack drop prints once as "Packs 7" instead of
              seven rows).
            • Inhouse Team (Portrait) — kitchen / slaughter copy.
              Wide Type column for animal-detail notes, slim Qty /
              Paaye, no Weight column (May-2026: kitchen records
              weights elsewhere now). --}}
    <div style="display:flex;align-items:stretch;gap:8px;padding:10px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;margin-bottom:14px;flex-wrap:wrap;">
        <span style="font-size:12px;color:#9a3412;font-weight:700;align-self:center;margin-right:4px;">Sheet for:</span>
        <label style="flex:1 1 200px;min-width:200px;display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid #fed7aa;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;color:#374151;" title="Full record sheet kept by the manager. Landscape A4.">
            <input type="radio" name="sheetType" value="master" checked onchange="loadSheetPreview()">
            <span><b>📊 Master Sheet</b><br><span style="color:#6b7280;font-size:11px;">Landscape · full record (8 cols)</span></span>
        </label>
        <label style="flex:1 1 200px;min-width:200px;display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid #fed7aa;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;color:#374151;" title="Driver manifest. Landscape A4. Real street address + contact + packs. One row per bundle.">
            <input type="radio" name="sheetType" value="delivery" onchange="loadSheetPreview()">
            <span><b>🚚 Delivery Team</b><br><span style="color:#6b7280;font-size:11px;">Landscape · driver manifest (6 cols)</span></span>
        </label>
        <label style="flex:1 1 200px;min-width:200px;display:flex;align-items:center;gap:8px;padding:8px 10px;border:1.5px solid #fed7aa;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;color:#374151;" title="Kitchen / slaughter team. Portrait A4. Wide Type column for animal-detail notes; no weight column.">
            <input type="radio" name="sheetType" value="inhouse" onchange="loadSheetPreview()">
            <span><b>🔪 Inhouse Team</b><br><span style="color:#6b7280;font-size:11px;">Portrait · wide Type column (5 cols)</span></span>
        </label>
    </div>
    <div id="sheetPreviewSummary" style="font-size:13px;color:#374151;padding:10px 12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;margin-bottom:14px;">
        <span style="color:#9ca3af;">Loading preview…</span>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
        <button class="qo-toolbar-btn secondary" onclick="closeSheetModal()" style="padding:6px 14px;font-size:12px;">Cancel</button>
        <button class="qo-toolbar-btn primary" id="sheetPrintBtn" onclick="runSheetPrintFromModal()" style="padding:6px 14px;font-size:12px;" disabled>Preview &amp; Print (—)</button>
    </div>
</div>

{{-- ===== Bulk Location-Request modal (May-2026, Phase 6) ============
     Lists every customer matching the filter that does NOT have a
     verified pin. Staff ticks the ones to message → Send → the
     browser polls /bulk/{batchId}/start in 100-row chunks while a
     progress bar fills in. Each row also shows "last request status"
     (Never / Sent X ago, no reply / Replied — pending review) so
     staff knows whether they're re-sending to a non-replier or
     pinging someone fresh. --}}
<div class="qo-modal-overlay" id="locReqSendOverlay" onclick="closeLocReqSendModal()"></div>
<div class="qo-modal qo-locreq-modal" id="locReqSendModal">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h2 style="margin:0;">📍 Request Location via WhatsApp</h2>
        <button onclick="closeLocReqSendModal()" style="background:none;border:none;font-size:20px;color:#6b7280;cursor:pointer;">&times;</button>
    </div>
    <p style="font-size:12px;color:#6b7280;margin:0 0 12px;line-height:1.5;">
        Sends the <code>qurbani_location</code> template
        <b>once per customer</b> — a customer with multiple hissas,
        goats or orders only gets <b>one</b> WhatsApp. Paced to ~5/sec
        on Meta's Cloud API. The verified-pin check is at the
        <b>customer</b> level (shared with regular orders), so anyone
        who already pinned through any other channel is automatically
        excluded.
    </p>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px 10px;margin-bottom:10px;">
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Day</label>
            <select id="locReqDay" class="qo-filter" style="width:100%;" onchange="loadLocReqEligible()">
                <option value="">All Days</option>
                @foreach($days as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Slot</label>
            <select id="locReqSlot" class="qo-filter" style="width:100%;" onchange="loadLocReqEligible()">
                <option value="">All Slots</option>
                @foreach($slots as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Region</label>
            <select id="locReqRegion" class="qo-filter" style="width:100%;" onchange="loadLocReqEligible()">
                <option value="">All Regions</option>
                @foreach($regions as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Sub Region</label>
            <select id="locReqSubRegion" class="qo-filter" style="width:100%;" onchange="loadLocReqEligible()">
                <option value="">All Sub Regions</option>
                @foreach($subRegions as $sr)<option value="{{ $sr }}">{{ $sr }}</option>@endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Delivery Type</label>
            <select id="locReqDeliveryType" class="qo-filter" style="width:100%;" onchange="loadLocReqEligible()">
                <option value="">All Types</option>
                @foreach($deliveryTypes as $dt)<option value="{{ $dt }}">{{ $dt }}</option>@endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:#6b7280;display:block;margin-bottom:3px;">Category</label>
            <select id="locReqCategory" class="qo-filter" style="width:100%;" onchange="loadLocReqEligible()">
                <option value="">All Categories</option>
                @foreach($categories as $cat)<option value="{{ $cat }}">{{ $cat }}</option>@endforeach
            </select>
        </div>
        <div style="grid-column: span 2;display:flex;align-items:end;gap:10px;font-size:11px;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" id="locReqIncludeDelivered" onchange="loadLocReqEligible()">
                Include delivered
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:#6b7280;">
                <input type="checkbox" id="locReqHideRecentlySent" checked onchange="renderLocReqList()">
                Hide customers messaged in last 24h
            </label>
        </div>
    </div>

    {{-- May-2026 stats strip — gives the user the full picture for
         the current filter set without leaving the modal. Four
         compact tiles: Total customers in the filter, Verified pins
         (auto-excluded), Unverified (the table below), and a
         drill-into-list tile for "sent but no reply" so the user can
         call/remind those customers directly. Awaiting-reply count
         doubles as an expand toggle for the inline list. --}}
    <div id="locReqStatsBar" style="display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:8px;font-size:11px;">
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:6px 8px;text-align:center;">
            <div id="locReqStatTotal" style="font-size:16px;font-weight:800;color:#1f2937;">—</div>
            <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">Customers</div>
        </div>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;padding:6px 8px;text-align:center;" title="Already pinned — auto-excluded from the table below">
            <div id="locReqStatVerified" style="font-size:16px;font-weight:800;color:#065f46;">—</div>
            <div style="font-size:10px;color:#065f46;text-transform:uppercase;letter-spacing:.03em;">Verified Pin</div>
        </div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:6px 8px;text-align:center;" title="No pin yet — these are the rows in the table">
            <div id="locReqStatUnverified" style="font-size:16px;font-weight:800;color:#92400e;">—</div>
            <div style="font-size:10px;color:#92400e;text-transform:uppercase;letter-spacing:.03em;">Unverified</div>
        </div>
        <div id="locReqStatWaitingCard"
             role="button" tabindex="0"
             onclick="locReqToggleWaitingPanel()"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();locReqToggleWaitingPanel();}"
             style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:6px 8px;text-align:center;cursor:pointer;"
             title="Customers messaged but no reply yet — click to expand the list with Call / Remind actions">
            <div id="locReqStatWaiting" style="font-size:16px;font-weight:800;color:#991b1b;">—</div>
            <div style="font-size:10px;color:#991b1b;text-transform:uppercase;letter-spacing:.03em;">
                Awaiting Reply <span id="locReqStatWaitingCaret" style="color:#9ca3af;">▾</span>
            </div>
        </div>
        <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;padding:6px 8px;text-align:center;cursor:pointer;"
             onclick="openLocReqReviewDrawer()" title="Open Reviewer drawer to save replies">
            <div id="locReqStatReplied" style="font-size:16px;font-weight:800;color:#3730a3;">—</div>
            <div style="font-size:10px;color:#3730a3;text-transform:uppercase;letter-spacing:.03em;">Replies to Save</div>
        </div>
    </div>

    {{-- Expandable Awaiting-Reply panel. Hidden by default; toggled
         from the Awaiting Reply stat tile. Each row exposes a Call
         link (tel:) and a one-tap Remind button that fires the same
         sendOne endpoint a per-order Request button uses. A "Remind
         All Waiting" button at the top is the bulk shortcut. --}}
    <div id="locReqWaitingPanel" style="display:none;margin-bottom:10px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;max-height:240px;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px;flex-wrap:wrap;">
            <div style="font-size:12px;font-weight:700;color:#991b1b;">
                💤 Awaiting reply — <span id="locReqWaitingCount">0</span> customer(s)
            </div>
            <div style="display:flex;gap:6px;">
                <button class="qo-toolbar-btn secondary" type="button" onclick="locReqRemindAllWaiting()" style="padding:4px 10px;font-size:11px;">
                    💬 Remind All Waiting
                </button>
                <button class="qo-toolbar-btn secondary" type="button" onclick="locReqToggleWaitingPanel(false)" style="padding:4px 10px;font-size:11px;">
                    Close
                </button>
            </div>
        </div>
        <div id="locReqWaitingList" style="display:flex;flex-direction:column;gap:4px;font-size:12px;">
            <div style="color:#9ca3af;font-style:italic;">Loading…</div>
        </div>
    </div>

    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px;color:#374151;">
        <button class="qo-toolbar-btn secondary" type="button" onclick="locReqSelectAll(true)" style="padding:3px 8px;font-size:11px;">Select All</button>
        <button class="qo-toolbar-btn secondary" type="button" onclick="locReqSelectAll(false)" style="padding:3px 8px;font-size:11px;">Deselect</button>
        <button class="qo-toolbar-btn secondary" type="button" onclick="locReqSelectNeverRequested()" style="padding:3px 8px;font-size:11px;">Select Never-Requested</button>
        <span id="locReqSelectionSummary" style="margin-left:auto;color:#6b7280;font-size:12px;">— selected</span>
    </div>

    <div class="qo-locreq-list-wrap">
        <table class="qo-locreq-tbl">
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="locReqSelectHeader" onchange="locReqSelectAll(this.checked)"></th>
                    <th>Customer</th>
                    <th>Orders</th>
                    <th>Region(s)</th>
                    <th>Day · Slot</th>
                    <th>Last Request</th>
                </tr>
            </thead>
            <tbody id="locReqListBody">
                <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px;">
                    Open filters above &mdash; eligible customers will appear here.
                </td></tr>
            </tbody>
        </table>
    </div>

    {{-- Inline progress bar shown while a batch is sending, then
         re-purposed into a live batch dashboard once sending finishes
         so staff can watch replies arrive without leaving the modal.
         Refreshes every 15s while the modal is open by polling
         /batch/{id}. Each tile is clickable to drill into the
         Reviewer drawer scoped to this batch. --}}
    <div id="locReqProgress" style="display:none;margin-top:12px;padding:10px 12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span style="font-size:12px;font-weight:600;color:#1e40af;" id="locReqProgressLabel">Sending…</span>
            <span style="font-size:11px;color:#6b7280;" id="locReqProgressCount">0 / 0</span>
        </div>
        <div style="height:6px;background:#dbeafe;border-radius:3px;overflow:hidden;">
            <div id="locReqProgressBar" style="height:100%;background:#2563eb;width:0%;transition:width .3s;"></div>
        </div>
        <div style="font-size:11px;color:#6b7280;margin-top:4px;" id="locReqProgressDetail"></div>

        {{-- Post-send batch dashboard — only rendered once sending
             has fully drained. Auto-refreshes so the staff sees the
             reply count tick up while they're still on this screen. --}}
        <div id="locReqBatchDashboard" style="display:none;margin-top:10px;padding-top:10px;border-top:1px dashed #bae6fd;">
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;font-size:12px;">
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;text-align:center;">
                    <div style="font-size:18px;font-weight:800;color:#1f2937;" id="locReqDashSent">0</div>
                    <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">Sent</div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;text-align:center;">
                    <div style="font-size:18px;font-weight:800;color:#059669;" id="locReqDashReplied">0</div>
                    <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">Replied</div>
                </div>
                <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:8px 10px;text-align:center;cursor:pointer;"
                     id="locReqDashReviewCard"
                     onclick="openLocReqReviewDrawerForBatch()"
                     title="Open the Reviewer drawer scoped to this batch">
                    <div style="font-size:18px;font-weight:800;color:#92400e;" id="locReqDashReview">0</div>
                    <div style="font-size:10px;color:#92400e;text-transform:uppercase;letter-spacing:.03em;">Awaiting save</div>
                </div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:8px 10px;text-align:center;">
                    <div style="font-size:18px;font-weight:800;color:#2563eb;" id="locReqDashSaved">0</div>
                    <div style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">Saved</div>
                </div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;gap:8px;flex-wrap:wrap;">
                <span style="font-size:11px;color:#6b7280;" id="locReqDashUpdated">Updated just now · auto-refreshes every 15s</span>
                <button class="qo-toolbar-btn primary" type="button" onclick="openLocReqReviewDrawerForBatch()"
                        id="locReqDashReviewBtn" style="padding:6px 14px;font-size:12px;">
                    📋 Review &amp; Save Replies (0)
                </button>
            </div>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
        <button class="qo-toolbar-btn secondary" onclick="closeLocReqSendModal()" style="padding:6px 14px;font-size:12px;">Close</button>
        <button class="qo-toolbar-btn secondary" onclick="openLocReqReviewDrawer()" style="padding:6px 14px;font-size:12px;">📋 Review All Replies</button>
        <button class="qo-toolbar-btn primary" id="locReqSendBtn" onclick="runLocReqSend()" style="padding:6px 14px;font-size:12px;" disabled>Send to Selected (0)</button>
    </div>
</div>

{{-- ===== Reviewer drawer (May-2026, Phase 6) ============
     Right-side drawer that lists every replied-but-not-yet-saved
     row, with one-click Save / Save-All / Dismiss controls. Strict
     safety: rows where the customer already has a NEWER manual pin
     are flagged with an amber warning + a "Force-save" prompt — so
     bulk Save All only writes the safe rows. --}}
<div class="qo-locreview-overlay" id="locReviewOverlay" onclick="closeLocReqReviewDrawer()"></div>
<div class="qo-locreview-drawer" id="locReviewDrawer">
    <div class="qo-locreview-hdr">
        <h2 id="locReviewTitle">📋 Location Replies — Pending Review</h2>
        <button onclick="closeLocReqReviewDrawer()" style="background:none;border:none;font-size:20px;color:#6b7280;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:10px 12px;border-bottom:1px solid #e5e7eb;display:flex;gap:6px;align-items:center;font-size:11px;">
        <button class="qo-toolbar-btn secondary" type="button" onclick="loadLocReviewQueue()" style="padding:3px 8px;font-size:11px;">🔄 Refresh</button>
        <span id="locReviewSummary" style="color:#6b7280;flex:1;">Loading…</span>
    </div>
    <div class="qo-locreview-body" id="locReviewBody">
        <div style="text-align:center;color:#9ca3af;padding:20px;font-size:12px;">Loading…</div>
    </div>
    <div class="qo-locreview-foot">
        <span style="font-size:11px;color:#6b7280;" id="locReviewFootHint">
            One click saves every reply that isn&rsquo;t flagged (amber rows are skipped to protect newer manual pins).
        </span>
        <div style="display:flex;gap:6px;">
            <button class="qo-toolbar-btn secondary" type="button" onclick="locReviewSaveAll(false)" style="padding:5px 12px;font-size:12px;">💾 Save All Safe (0)</button>
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
                // Phase 6 (May-2026) — after items render, hydrate the
                // per-customer location-request status into the cards.
                // Async / non-blocking: the cards render with a generic
                // "Request Location" label first, then we patch them
                // with the actual "Sent / Replied / etc." state once
                // the /statuses round-trip lands.
                try { hydrateLocReqStatuses(); } catch (e) {}
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

        // Phase 6 (May-2026) — Customer-level rollup for the current
        // filtered set. Computed CLIENT-SIDE from allItems so it
        // stays in sync with the on-screen list (server summary is
        // line-item based and would lie when a customer appears on
        // multiple rows). has_verified_location is the same
        // customer-level flag the per-card 📍 badge uses, so what
        // the badge shows and what the rollup counts are guaranteed
        // to agree.
        try {
            const orderIds = new Set();
            const verifiedC = new Set();
            const allC = new Set();
            (allItems || []).forEach(it => {
                if (it.order_id)     orderIds.add(it.order_id);
                if (it.customer_id)  {
                    allC.add(it.customer_id);
                    if (it.has_verified_location) verifiedC.add(it.customer_id);
                }
            });
            const ordersN    = orderIds.size;
            const customersN = allC.size;
            const verifiedN  = verifiedC.size;
            const missingN   = customersN - verifiedN;
            html += '<span style="border-left:1px solid #e5e7eb;padding-left:14px;margin-left:6px;">'
                  + '<b>' + ordersN + '</b> orders</span>';
            html += '<span><b>' + customersN + '</b> unique customers</span>';
            html += '<span style="color:#059669;" title="Customers with a verified lat/lng pin (shared across regular + Qurbani — won\'t be sent again)">'
                  + '<b>' + verifiedN + '</b> 📍 verified</span>';
            html += '<span style="color:#dc2626;" title="Customers without a verified pin — these are the candidates for the bulk location-request">'
                  + '<b>' + missingN + '</b> 📍? need pin</span>';
        } catch (e) { /* non-fatal */ }

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
        } else {
            // Phase 6 (May-2026) — render a muted "no pin" indicator so
            // staff can quickly spot which customers still need their
            // location. The actual Send button sits in row 3 next to
            // the other per-row actions.
            html += '<span class="qo-no-verified" title="No verified location on file">📍?</span>';
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
        // Phase 4 (May-2026) — slot vs ETA / delivered chip. Server
        // pre-computes this per row (QurbaniSlotParser::compareEventToSlot
        // — settings override > parser auto-detect for slot end). Green
        // when ETA / delivered_at lands inside the promised slot,
        // amber/red when it's past slot end.
        if (it.slot_compare && it.slot_compare.label) {
            const sc = it.slot_compare;
            const isWithin = sc.state === 'within';
            const isDeliveredCmp = !!it.qurbani_delivered_at;
            const bg = isWithin ? '#d1fae5' : (isDeliveredCmp ? '#fee2e2' : '#fef3c7');
            const fg = isWithin ? '#065f46' : (isDeliveredCmp ? '#991b1b' : '#92400e');
            const bd = isWithin ? '#10b981' : (isDeliveredCmp ? '#ef4444' : '#f59e0b');
            html += '<span class="qo-slot-chip" style="background:' + bg + ';color:' + fg + ';border:1px solid ' + bd + ';padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;">' + esc(sc.label) + '</span>';
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
        } else {
            // Phase 6 (May-2026) — per-row Request Location button. Only
            // shown when the customer has no pin. The button reflects
            // the latest request status for THIS customer (read from
            // window._qoLocReqStatuses, populated by hydrateLocReqStatuses()
            // after items load) so staff can see at a glance whether they
            // already pinged this customer and what came back.
            const st = (window._qoLocReqStatuses || {})[it.customer_id] || null;
            let lblTxt = '📨 Request Location';
            let cls = 'loc-req';
            let title = 'Send qurbani_location WhatsApp template to this customer';
            if (st) {
                if (st.display === 'replied_pending') {
                    lblTxt = '📥 Reply pending review';
                    cls += ' is-replied';
                    title = 'Customer sent a location pin — open the Reviewer drawer to save it.';
                } else if (st.display === 'sent_no_reply') {
                    lblTxt = '⏳ Resend';
                    cls += ' is-sent';
                    title = 'Sent on ' + (st.sent_at || '—') + ' — no reply yet. Click to resend.';
                } else if (st.display === 'sending' || st.display === 'queued') {
                    lblTxt = '⏳ Queued';
                    cls += ' is-sent';
                } else if (st.display === 'failed') {
                    lblTxt = '⚠️ Retry send';
                    title = 'Last send failed. Click to retry.';
                }
            }
            html += '<button class="qo-action-btn ' + cls + '" '
                  + 'onclick="sendLocReqForLineItem(' + it.line_item_id + ',' + it.customer_id
                  + ',' + (it.order_id || 'null') + ', this)" '
                  + 'title="' + esc(title) + '">' + lblTxt + '</button>';
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
        // Sizing budget for an A4 LANDSCAPE sheet (297mm × 210mm).
        // (May-2026) — flipped from portrait at user request. Landscape
        // gives us extra horizontal width, so we now place the big box
        // number BESIDE the info grid (instead of stacked on top of
        // it) — that uses every inch of the page rather than wasting
        // 60mm of vertical real-estate the portrait layout dedicated
        // to the box-block alone.
        //
        // Vertical budget (210mm tall):
        //   • Padding 8mm + 8mm                    = 16mm
        //   • Brand strip (4mm pad + 22pt)         ≈ 14mm
        //   • Body row (flex: 1)                   ≈ 167mm
        //       └─ Box block (left, ~95mm wide):
        //            box-num 250pt × 0.85 line-h   ≈ 75mm tall, vertically centred
        //       └─ Info grid (right, ~177mm wide):
        //            5 rows (banner + 4 std) × ~33mm avg ≈ 165mm — fills column
        //   • Footer (3mm + 16pt)                  ≈ 13mm
        //   • Total content                        ≈ 210mm ✓
        //
        // The body row's flex: 1 means box-block and info-grid both
        // stretch to fill all space between brand and footer, so the
        // sheet stays edge-to-edge regardless of how long the
        // customer name / instructions are.
        // ────────────────────────────────────────────────────────────
        // ────────────────────────────────────────────────────────────
        // Polish pass — May-2026 (post-landscape).
        // User feedback: "currently its uneven... make text for each
        // item as big as possible inside each square boundary, bold
        // and thick, easily readable from far". Three changes:
        //   1. Tightened the auto-fit tiers from 4-tier (14/24/32/44pt
        //      = 3.1× spread) to 3-tier (17/22/30pt = 1.8× spread) so
        //      every cell reads as the same "weight class" regardless
        //      of value length.
        //   2. Pulled font-weight up to 900 (black) for short/medium
        //      and 800 (extrabold) for long values — gives the page
        //      that crisp, far-readable look.
        //   3. Heavier table borders (3pt outer / 1.5pt inner) +
        //      hero-cell upgrades (Customer Name banner 38pt, No. of
        //      Boxes 36pt) so the data hierarchy is visually clear.
        // Footer unified to a single colour scale (black phone, mid
        // order #, soft-grey print stamp) with consistent letter
        // spacing instead of three random sizes.
        // ────────────────────────────────────────────────────────────
        const css =
            '@page { size: A4 landscape; margin: 0; }' +
            'html, body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background: #fff; color: #111; }' +
            '* { box-sizing: border-box; }' +
            // 297mm × 210mm landscape page.
            // May-2026 polish #2 — pulled the label-page padding from
            // 7mm/9mm down to 3mm/8mm so brand strip + footer hug the
            // paper edges and the body row gets ~8mm extra vertical
            // space to grow the data cells. Browser-injected print
            // header/footer (about:blank, page number) live OUTSIDE
            // this .label-page box so we can\'t reclaim that space
            // from CSS — the user has to disable "Headers and
            // footers" in the print dialog\'s More settings to get a
            // truly edge-to-edge print.
            '.label-page { width: 297mm; height: 210mm; padding: 3mm 8mm; page-break-after: always; page-break-inside: avoid; overflow: hidden; display: flex; flex-direction: column; position: relative; }' +
            '.label-page:last-child { page-break-after: auto; }' +
            '.brand-strip { background: #d97706; color: #fff; padding: 4mm 9mm; display: flex; align-items: center; justify-content: space-between; border-radius: 3mm; flex: 0 0 auto; }' +
            '.brand-name { font-size: 24pt; font-weight: 900; letter-spacing: 1.5pt; }' +
            '.brand-tag  { font-size: 12pt; font-weight: 700; letter-spacing: 1pt; text-transform: uppercase; opacity: 0.95; }' +
            // Body row — left big-box / right info-grid. Tightened
            // top/bottom margins from 3mm to 2mm so the row stretches
            // further into the reclaimed vertical space.
            '.body-row { flex: 1 1 auto; display: flex; flex-direction: row; align-items: stretch; gap: 5mm; margin-top: 2mm; margin-bottom: 2mm; min-height: 0; }' +
            // Left hero block — slimmed from 95mm → 60mm so the info
            // grid on the right gets ~35mm of extra horizontal width.
            // That extra space lets the Customer Name row stay on a
            // single line for longer names (e.g. "Muhammad usman Khan")
            // and keeps the lower cells from being cropped when the
            // banner had to wrap to 2 lines at the old 95mm width.
            // Single-digit positions (the common case) sit comfortably
            // in 52mm internal (60mm - 8mm padding); 2-digit positions
            // shrink via .box-num-narrow below as a safety guard.
            '.box-block { flex: 0 0 60mm; display: flex; flex-direction: column; align-items: center; justify-content: center; border: 3pt solid #000; border-radius: 3mm; background: #fffbeb; padding: 4mm; }' +
            // Weight 600 (was 900) + colour #333 (was #000): at 260pt
            // the "Black" weight prints as a near-solid ink slab that
            // drains toner unnecessarily. Semibold + dark-grey keeps
            // the visual hierarchy (still clearly the dominant element,
            // readable from across a warehouse) with roughly 40% less
            // stroke area per glyph PLUS printer halftoning at #333
            // lays down ~20% fewer toner dots than #000. Customer
            // Name above keeps its 900 + #000 because at 38pt the
            // strokes scale to a proportional "bold" rather than a
            // blocky one.
            '.box-num   { font-size: 260pt; font-weight: 600; line-height: 0.85; color: #333; letter-spacing: -4pt; font-feature-settings: "tnum"; }' +
            // Auto-shrink overrides for multi-digit positions so they
            // still fit inside the slim 60mm box-block. Bundle sizes
            // are usually 1-5, occasionally up to 7, so .narrow is the
            // only realistic fallback; .tiny is a paranoia guard for
            // any future hissa configuration that grows beyond 9.
            '.box-num.box-num-narrow { font-size: 160pt; letter-spacing: -3pt; }' +
            '.box-num.box-num-tiny   { font-size: 100pt; letter-spacing: -2pt; }' +
            '.box-of    { font-size: 40pt; font-weight: 600; color: #555; margin-top: 4mm; letter-spacing: -0.5pt; }' +
            '.box-caption { font-size: 13pt; font-weight: 800; color: #555; text-transform: uppercase; letter-spacing: 1.2pt; margin-top: 6mm; }' +
            // Right info grid — heavier borders, slightly more padding
            // so each value sits comfortably inside its frame.
            '.info-grid { flex: 1 1 auto; width: auto; border-collapse: collapse; border: 3pt solid #000; }' +
            '.info-grid td { border: 1.5pt solid #333; padding: 4mm 5mm; vertical-align: middle; }' +
            // Cell label = the small ALL-CAPS line above each value.
            // Bumped weight + letter-spacing for a tighter look.
            '.cell-label { font-size: 10pt; font-weight: 800; color: #555; text-transform: uppercase; letter-spacing: 1pt; margin-bottom: 2mm; }' +
            // Default cell-value baseline — most sells will fall on a
            // narrower tier below; this is the medium fallback.
            '.cell-value { font-size: 22pt; font-weight: 900; color: #000; line-height: 1.15; word-wrap: break-word; letter-spacing: -0.2pt; }' +
            // Auto-fit tiers — 3-step ladder for visual uniformity.
            //   ≤8 chars   → 30pt (Day 3, Washed, Delivery, 5/5)
            //   9-22 chars → 22pt (QUR26-169, Goat (Bakra), Adyala…)
            //   23-45 chars → 17pt (Standard: All Boti cut, 1 Leg…)
            //   >45 chars   → 13pt (extra long instructions)
            '.cell-value.cv-short  { font-size: 30pt; line-height: 1.05; }' +
            '.cell-value.cv-medium { font-size: 22pt; line-height: 1.15; }' +
            '.cell-value.cv-long   { font-size: 17pt; font-weight: 800; line-height: 1.2; }' +
            '.cell-value.cv-xlong  { font-size: 13pt; font-weight: 800; line-height: 1.3; }' +
            // Hero overrides — Customer Name banner + No. of Boxes.
            // These are intentionally bigger than the auto-fit tiers
            // so the most important values scan at a glance.
            '.cell-value.lg { font-size: 36pt; font-weight: 900; letter-spacing: -1pt; line-height: 1.05; font-feature-settings: "tnum"; }' +
            '.cell-value.xl { font-size: 38pt; font-weight: 900; letter-spacing: -0.5pt; line-height: 1.05; }' +
            // Auto-fit ladder for the Customer Name banner. Default
            // .xl handles names ≤18 chars cleanly inside the new
            // wider info-grid (after the box-block slim). Longer
            // names step down in size so the banner stays on a
            // SINGLE line — preventing the wrap that was pushing
            // the lower info cells off the bottom of the label.
            // Tiers chosen by measurement, not eyeball: at the new
            // ~217mm info-grid width, 38pt fits ~18 chars, 30pt
            // fits ~26, 23pt fits ~38, 18pt fits ~50. Past that
            // the name wraps — rare enough we accept the wrap and
            // the layout still holds because line-height is tight.
            '.cell-value.xl.xl-fit-2 { font-size: 30pt; letter-spacing: -0.3pt; }' +
            '.cell-value.xl.xl-fit-3 { font-size: 23pt; letter-spacing: -0.2pt; }' +
            '.cell-value.xl.xl-fit-4 { font-size: 18pt; letter-spacing: -0.1pt; line-height: 1.15; }' +
            // Footer — single weighted scale, no random sizes.
            '.label-footer { flex: 0 0 auto; border-top: 2.5pt solid #000; padding-top: 4mm; padding-bottom: 1mm; display: flex; justify-content: space-between; align-items: center; font-size: 11pt; color: #555; font-weight: 700; }' +
            '.label-footer .phone { font-size: 14pt; font-weight: 900; color: #000; letter-spacing: 0.4pt; }' +
            '.label-footer .order { font-size: 12pt; font-weight: 800; color: #333; letter-spacing: 0.3pt; }' +
            '.label-footer .stamp { font-size: 9pt; color: #888; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8pt; }' +
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
    // Auto-fit helper — picks a font-size class based on text length
    // so cells read uniformly. Tuned to a 3-step ladder (May-2026
    // polish pass): the spread between shortest and longest values
    // is now ~1.8× instead of 3.1× so the page no longer looks like
    // mismatched font sizes scattered across cells.
    //   ≤8  chars  ("Day 3", "Washed", "Delivery", "5/5") → 30pt
    //   9-22 chars ("QUR26-169", "Goat (Bakra)")          → 22pt
    //   23-45 chars (mid-length instructions)             → 17pt
    //   >45 chars  (extra long instructions)              → 13pt
    function fitClass(text) {
        const t = String(text == null ? '' : text);
        if (!t || t === '—') return 'cell-value cv-short';
        const len = t.trim().length;
        if (len <= 8)  return 'cell-value cv-short';
        if (len <= 22) return 'cell-value cv-medium';
        if (len <= 45) return 'cell-value cv-long';
        return 'cell-value cv-xlong';
    }

    // Same idea as fitClass() but tuned for the Customer Name banner
    // — that row uses the .xl class (38pt) instead of the regular
    // ladder, so it gets its own shrink tiers calibrated for the
    // post-slim info-grid width (~217mm). The earlier behaviour was
    // "always 38pt", which meant long names like "Muhammad usman
    // Khan" wrapped to 2 lines and pushed the lower cells off the
    // bottom of the label. Now the font steps down just enough to
    // keep the banner on ONE line for nearly every real name.
    function fitClassXL(text) {
        const t = String(text == null ? '' : text);
        if (!t || t === '—') return 'cell-value xl';
        const len = t.trim().length;
        if (len <= 18) return 'cell-value xl';                  // 38pt — short names
        if (len <= 26) return 'cell-value xl xl-fit-2';         // 30pt — most common long names
        if (len <= 38) return 'cell-value xl xl-fit-3';         // 23pt — full triple-barrel names
        return 'cell-value xl xl-fit-4';                        // 18pt — extreme edge cases (still single line)
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

        // Body row — landscape split: big box-number on the LEFT,
        // info grid on the RIGHT. The two columns share the vertical
        // space between brand strip and footer via flex.
        html += '<div class="body-row">';

        // Big box number (left column). The "Box" caption ties the
        // huge number back to its meaning since it sits alone in
        // its own framed cell. Auto-shrink the digit's font when
        // position is multi-digit (rare) so it still fits the
        // slimmed 60mm box-block without overflowing.
        const posLen = String(l.position).length;
        let boxNumCls = 'box-num';
        if (posLen >= 3) { boxNumCls += ' box-num-tiny'; }
        else if (posLen >= 2) { boxNumCls += ' box-num-narrow'; }
        html += '<div class="box-block">' +
                '<div class="' + boxNumCls + '">' + l.position + '</div>' +
                '<div class="box-of">of ' + l.bundle_size + '</div>' +
                '<div class="box-caption">Box</div>' +
                '</div>';

        // Info grid (right column).
        // Customer Name banner (full-width row) is the visual hero
        // of the right column. Pass the raw name through fitClassXL
        // so long names auto-shrink to stay on ONE line instead of
        // wrapping and pushing the lower cells off the label.
        html += '<table class="info-grid">';
        html += '<tr><td colspan="3" style="background:#fffbeb;">' +
                '<div class="cell-label">Customer Name</div>' +
                '<div class="' + fitClassXL(l.customer_name) + '">' + customerName + '</div>' +
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
        // When there's no instructions to show, we collapse the row
        // into a single colspan=3 Qurbani Type cell so the value
        // fills the full row instead of leaving "—" in dead space.
        // When instructions ARE present, both cells render with the
        // same auto-fit ladder as everywhere else for consistency.
        const rawInstr = (instructions || '').trim();
        const hasInstr = rawInstr !== '' && rawInstr !== '—';
        if (hasInstr) {
            html += '<tr>' +
                    '<td><div class="cell-label">Qurbani Type</div><div class="' + fitClass(l.qurbani_type) + '">' + qurbaniType + '</div></td>' +
                    '<td colspan="2"><div class="cell-label">Instructions</div><div class="' + fitClass(rawInstr) + '">' + instructions + '</div></td>' +
                    '</tr>';
        } else {
            html += '<tr>' +
                    '<td colspan="3"><div class="cell-label">Qurbani Type</div><div class="' + fitClass(l.qurbani_type) + '">' + qurbaniType + '</div></td>' +
                    '</tr>';
        }

        html += '</table>';

        // Close body-row (box-block + info-grid).
        html += '</div>';

        // Footer — three slots on a single weight scale (black phone,
        // mid order #, soft-grey print stamp). Spacing is enforced by
        // flex space-between so the row stays tidy regardless of
        // phone length.
        html += '<div class="label-footer">';
        html += '<span class="phone">' + (phone ? '☎ ' + phone : '') + '</span>';
        html += '<span class="order">Order #' + orderNo + '</span>';
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

    // ===== A4 Print Sheets (May-2026) ==============================
    // Manual paper-backup print pipeline. Reuses the existing batched
    // print primitives (openPrintWindow analogue, showPrintProgress /
    // updatePrintProgress / hidePrintProgress, _printRunCancelled,
    // cancelPrintRun()) so the user sees the SAME progress banner +
    // Cancel button behaviour they already know from box labels.
    //
    // Key difference from box-label bundle math:
    //   QurbaniBundleService bundles ALL line items in a (order, day,
    //   slot, delivery_type) tuple — so a customer with 2 hissa + 2
    //   goats gets one bundle of size 4 (positions 1-4) for box labels.
    //   For SHEETS the user wants category-scoped bundles: that same
    //   customer gets TWO bundles of size 2 each, so the hissa sheet
    //   reads "1/2, 2/2" and the goat sheet reads "1/2, 2/2"
    //   independently. Computed client-side so we don't have to add a
    //   server endpoint or touch the QurbaniBundleService (which would
    //   risk breaking box-label math).

    // Parses the trailing numeric suffix of an order number ("QUR26-289"
    // → 289) so sorting is numeric instead of lexicographic (otherwise
    // QUR26-1000 would sort BEFORE QUR26-289).
    function _sheetOrderNumberKey(orderNum) {
        if (!orderNum) return 0;
        const m = String(orderNum).match(/(\d+)\s*$/);
        return m ? parseInt(m[1], 10) : 0;
    }

    // Groups items into one section per
    // (Category × Day × Region × Sub-Region × Slot), matching the
    // user's original Google-Sheets reference layout (Region + Sub
    // Region appear at the TOP of each sheet, not as columns).
    //
    // QUANTITY BUNDLE SCOPE — per CUSTOMER, per CATEGORY:
    //   Inside each section we further group by order_id (= one
    //   customer) and sum the quantities of that customer's line
    //   items. Each line item then gets a (start, end) range inside
    //   that customer's 1..bundle_size space. So:
    //     • Customer with qty=2 hissas in one line item
    //         → 2 rows showing "1/2" and "2/2"
    //     • Customer with qty=1 hissa
    //         → 1 row showing "1/1"
    //     • Customer with line item A qty=2 + line item B qty=1
    //       (both hissa, same slot/day/region)
    //         → 3 rows showing "1/3", "2/3", "3/3"
    //
    //   This is DIFFERENT from QurbaniBundleService (box labels),
    //   which bundles ACROSS categories: a customer with 2 hissa +
    //   2 goats gets one bundle of 4 for box-label numbering. For
    //   sheets, that same customer would see "1/2, 2/2" on the
    //   hissa sheet and "1/2, 2/2" on the goat sheet.
    //
    //   The original `bundle_size` / `bundle_position_*` fields from
    //   the server stay UNTOUCHED — box-label printing reads them as
    //   before. We only attach our own `_sheet_*` fields so the two
    //   features are fully decoupled.
    //
    // section_total is still tracked for preview-summary stats
    // ("X animal row(s) total" / page count estimates) but is NOT
    // used as the per-row denominator anymore.
    //
    // No items are dropped: items with NULL day / slot / region /
    // sub-region still land in their own labelled section (e.g.
    // "— No Region —") so they're never silently lost.
    function _groupItemsIntoSheetSections(items) {
        const map = new Map();
        items.forEach(it => {
            const cat = it.category_level_2 || '— Uncategorized —';
            const day = it.qurbani_day || '— No Day —';
            const reg = it.qurbani_region || '— No Region —';
            const sub = it.qurbani_sub_region || '— No Sub-Region —';
            const slt = it.qurbani_slot || '— No Slot —';
            const key = [cat, day, reg, sub, slt].join('||');
            if (!map.has(key)) {
                map.set(key, {
                    category: cat, day: day, region: reg, sub_region: sub, slot: slt,
                    items: [], section_total: 0,
                });
            }
            map.get(key).items.push(it);
        });

        const sections = Array.from(map.values());
        sections.forEach(sec => {
            // Row sort inside a section: Order Number ascending
            // (numeric, smallest first), then line_item_id for
            // stability when one customer has multiple line items
            // in the same section.
            sec.items.sort((a, b) => {
                const oa = _sheetOrderNumberKey(a.order_number);
                const ob = _sheetOrderNumberKey(b.order_number);
                if (oa !== ob) return oa - ob;
                return (parseInt(a.line_item_id) || 0) - (parseInt(b.line_item_id) || 0);
            });

            // Per-customer bundle math inside the section. Sort
            // above guarantees a customer's line items are
            // contiguous, so we can iterate once and emit ranges
            // per customer.
            let total = 0;
            const byOrder = new Map();
            sec.items.forEach(it => {
                const qty = Math.max(1, parseInt(it.quantity) || 0);
                total += qty;
                if (!byOrder.has(it.order_id)) byOrder.set(it.order_id, []);
                byOrder.get(it.order_id).push(it);
            });
            sec.section_total = total;

            byOrder.forEach(customerLis => {
                let bundleSize = 0;
                customerLis.forEach(it => { bundleSize += Math.max(1, parseInt(it.quantity) || 0); });
                let cursor = 1;
                customerLis.forEach(it => {
                    const qty = Math.max(1, parseInt(it.quantity) || 0);
                    it._sheet_pos_start = cursor;
                    it._sheet_pos_end = cursor + qty - 1;
                    it._sheet_bundle_total = bundleSize;
                    cursor += qty;
                });
            });
        });

        // Section ordering — Category → Day → Region → Sub-Region →
        // Slot. Keeps a single category's sheets adjacent in the
        // preview so the manager can scroll through them as a block.
        sections.sort((a, b) => {
            if (a.category !== b.category) return String(a.category).localeCompare(String(b.category));
            if (a.day !== b.day) return String(a.day).localeCompare(String(b.day));
            if (a.region !== b.region) return String(a.region).localeCompare(String(b.region));
            if (a.sub_region !== b.sub_region) return String(a.sub_region).localeCompare(String(b.sub_region));
            return String(a.slot).localeCompare(String(b.slot));
        });
        return sections;
    }

    // Modal state — kept narrowly scoped (not exposed) so it can't
    // accidentally collide with the box-label modal state.
    let _sheetSectionsCache = [];

    function openPrintSheetModal() {
        // Prefill the modal from the page's current filters so the
        // first thing the user sees matches what they're already
        // looking at. They can change anything before printing.
        const cur = collectFilterParams();
        const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
        setVal('sheetCategory', cur.get('category'));
        setVal('sheetDay', cur.get('day'));
        setVal('sheetRegion', cur.get('region'));
        setVal('sheetSubRegion', cur.get('sub_region'));
        setVal('sheetSlot', cur.get('slot'));
        setVal('sheetDeliveryType', cur.get('delivery_type'));
        const incEl = document.getElementById('sheetIncludeDelivered');
        if (incEl) incEl.checked = false;

        document.getElementById('sheetOverlay').style.display = 'block';
        document.getElementById('sheetModal').style.display = 'block';

        // Run the cascade refreshers AFTER setting the pre-filled values
        // so dropdowns get narrowed to valid children of the active
        // Region / Day×DeliveryType. The cascade functions preserve the
        // current value if it's still valid, or clear it otherwise — so
        // a stale "Sub Region X under wrong Region" silently resets.
        updateSheetSubRegionDropdown();
        updateSheetSlotDropdown();

        loadSheetPreview();
    }

    // -----------------------------------------------------------------
    // Cascading filter helpers for the Print A4 Sheets modal.
    // Pattern lifted verbatim from invoices.blade.php so the two pages
    // stay consistent — same FIELD_OPTIONS source, same parent_id /
    // delivery_type_parent_id semantics, same "fall back to all if no
    // children configured" fallback. If staff later edits the field
    // option tree in Qurbani Settings, both pages pick it up via the
    // server-rendered FIELD_OPTIONS without any code change.
    // -----------------------------------------------------------------
    function updateSheetSubRegionDropdown() {
        const sel = document.getElementById('sheetSubRegion');
        if (!sel) return;
        const regionVal = (document.getElementById('sheetRegion') || {}).value || '';
        const current = sel.value;

        // Wipe everything except the leading "All Sub Regions" sentinel.
        while (sel.options.length > 1) sel.remove(1);

        let subRegions = (FIELD_OPTIONS['qurbani_sub_region'] || []).filter(o => o.is_active);
        if (regionVal) {
            const regionOpts = FIELD_OPTIONS['qurbani_region'] || [];
            const regionObj = regionOpts.find(r => r.is_active && r.option_value === regionVal);
            if (regionObj) {
                const filtered = subRegions.filter(s => s.parent_id === regionObj.id);
                // If the region has no configured children, fall back to
                // the full list rather than rendering an empty dropdown
                // (mirrors invoices behaviour for un-mapped regions).
                if (filtered.length > 0) subRegions = filtered;
            }
        }

        const seen = new Set();
        subRegions.forEach(o => {
            if (seen.has(o.option_value)) return;
            seen.add(o.option_value);
            const opt = document.createElement('option');
            opt.value = o.option_value;
            opt.textContent = o.option_value;
            if (o.option_value === current) opt.selected = true;
            sel.appendChild(opt);
        });

        // If the previously-selected sub-region no longer matches the new
        // region, blank it. loadSheetPreview() will run after this and
        // pick up the cleared filter — no stale UI state.
        if (current && !seen.has(current)) sel.value = '';
    }

    function updateSheetSlotDropdown() {
        const sel = document.getElementById('sheetSlot');
        if (!sel) return;
        const dayVal = (document.getElementById('sheetDay') || {}).value || '';
        const dtVal  = (document.getElementById('sheetDeliveryType') || {}).value || '';
        const current = sel.value;

        while (sel.options.length > 1) sel.remove(1);

        let slots = (FIELD_OPTIONS['qurbani_slot'] || []).filter(o => o.is_active);

        let dayObj = null, dtObj = null;
        if (dayVal) {
            const dayOpts = FIELD_OPTIONS['qurbani_day'] || [];
            dayObj = dayOpts.find(d => d.is_active && d.option_value === dayVal);
        }
        if (dtVal) {
            const dtOpts = FIELD_OPTIONS['qurbani_delivery_type'] || [];
            dtObj = dtOpts.find(d => d.is_active && d.option_value === dtVal);
        }

        if (dayObj && dtObj) {
            const filtered = slots.filter(s => s.parent_id === dayObj.id
                                          && s.delivery_type_parent_id === dtObj.id);
            if (filtered.length > 0) {
                slots = filtered;
            } else {
                // Day×DT combo has no slot mapping → fall back to
                // day-only filter so the user still sees something
                // usable instead of an empty dropdown.
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
            if (o.option_value === current) opt.selected = true;
            sel.appendChild(opt);
        });

        if (current && !seen.has(current)) sel.value = '';
    }

    function closeSheetModal() {
        document.getElementById('sheetOverlay').style.display = 'none';
        document.getElementById('sheetModal').style.display = 'none';
    }

    function _collectSheetParams() {
        const params = new URLSearchParams();
        const map = {
            category: 'sheetCategory', day: 'sheetDay', region: 'sheetRegion',
            sub_region: 'sheetSubRegion', slot: 'sheetSlot', delivery_type: 'sheetDeliveryType',
        };
        for (const key of Object.keys(map)) {
            const el = document.getElementById(map[key]);
            if (el && el.value) params.set(key, el.value);
        }
        return params;
    }

    // Hits the existing /qurbani/api/orders-items endpoint with the
    // modal's filters, attaches category-scoped bundle math, groups
    // into sections, and updates the preview summary + enables the
    // Print button. No server-side changes required.
    function loadSheetPreview() {
        const summaryEl = document.getElementById('sheetPreviewSummary');
        const btnEl = document.getElementById('sheetPrintBtn');
        if (!summaryEl || !btnEl) return;
        summaryEl.innerHTML = '<span style="color:#9ca3af;">Loading preview…</span>';
        btnEl.disabled = true;
        btnEl.textContent = 'Preview & Print (—)';

        const params = _collectSheetParams();
        // May-2026 — cache-bust + no-store guard. Edge / some
        // corporate proxies aggressively cache GET responses even
        // without a Cache-Control header, which made the
        // "customer_address" field appear missing after the SQL
        // change even though the server was returning it.
        params.set('_', String(Date.now()));
        fetch('/qurbani/api/orders-items?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
            cache: 'no-store',
        })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed');
                let items = data.items || [];

                // Filter out delivered unless explicitly included —
                // these sheets are dispatch backups, delivered items
                // are already done.
                const includeDelivered = (document.getElementById('sheetIncludeDelivered') || {}).checked;
                if (!includeDelivered) {
                    items = items.filter(it => (it.qurbani_item_status || 'open') !== 'delivered');
                }

                // Group + assign section-wide positions in one pass.
                // No more per-customer bundle attach (that helper was
                // removed when sheet qty switched to section-wide
                // numbering — see _groupItemsIntoSheetSections).
                _sheetSectionsCache = _groupItemsIntoSheetSections(items);

                const totalLineItems = items.length;
                const totalOrders = new Set(items.map(it => it.order_id)).size;
                const totalSections = _sheetSectionsCache.length;
                // May-2026 — preview totals are layout-aware so the
                // "X animal rows" headline matches what the printer
                // actually emits. Master sheet collapses to one row
                // per line item; Inhouse / Delivery still print one
                // row per animal unit.
                const _previewSheetType = (document.querySelector('input[name="sheetType"]:checked') || {}).value || 'master';
                const _previewCfg = _sheetLayoutConfig(_previewSheetType);
                const _previewCollapsed = !!_previewCfg.collapseBundlesToSingleRow;
                const totalPrintedRows = _sheetSectionsCache.reduce(
                    (acc, s) => acc + (_previewCollapsed ? s.items.length : s.section_total),
                    0
                );
                // Rough page estimate — ~10 rows fit comfortably on
                // landscape A4 at the new, larger row height. Each
                // section starts on a fresh A4 page (page-break-before)
                // so we always get ≥1 page per section.
                const ROWS_PER_PAGE = 10;
                const totalPages = _sheetSectionsCache.reduce(
                    (acc, s) => acc + Math.max(
                        1,
                        Math.ceil((_previewCollapsed ? s.items.length : s.section_total) / ROWS_PER_PAGE)
                    ),
                    0
                );

                if (totalSections === 0) {
                    summaryEl.innerHTML = '<span style="color:#dc2626;font-weight:600;">0 orders match these filters. Adjust above.</span>';
                    btnEl.disabled = true;
                    btnEl.textContent = 'Preview & Print (0)';
                } else {
                    // Row label flips with layout — "printed rows"
                    // is accurate both when each animal gets its own
                    // row (inhouse / delivery) and when bundles are
                    // collapsed to one row each (master).
                    const _rowLabel = _previewCollapsed ? 'printed row(s)' : 'animal row(s)';
                    summaryEl.innerHTML =
                        '<b>' + totalSections + '</b> sheet(s) &middot; ' +
                        '<b>' + totalPrintedRows + '</b> ' + _rowLabel + ' &middot; ' +
                        totalLineItems + ' line item(s) &middot; ' +
                        totalOrders + ' customer(s) &middot; ' +
                        '~<b>' + totalPages + '</b> A4 page(s) &middot; ' +
                        '<span style="color:#059669;">opens in one preview window</span>';
                    btnEl.disabled = false;
                    btnEl.textContent = 'Preview & Print (' + totalSections + ' sheet' + (totalSections === 1 ? '' : 's') + ')';
                }
            })
            .catch(err => {
                summaryEl.innerHTML = '<span style="color:#dc2626;">' + esc(err.message) + '</span>';
                btnEl.disabled = true;
                btnEl.textContent = 'Preview & Print (—)';
            });
    }

    // Print runner — opens ONE window containing ALL sections stacked
    // (page-break-before separates them on the printer) so the user
    // gets a single browser print-preview that shows every sheet.
    //
    // Why one window instead of the box-label-style batched runner:
    //   • The manager needs to REVIEW everything before sending to
    //     the printer — that's only possible if the browser preview
    //     contains every sheet at once.
    //   • Sheets are list-style (~12 rows per A4 page) so even a full
    //     season print is ~30-100 pages total — well within what the
    //     OS spooler handles as one job.
    //   • A "Print All Sheets" button inside the preview window
    //     replaces the auto-fire so the user can scroll through the
    //     preview first and only print when they're satisfied.
    function runSheetPrintFromModal() {
        const sections = _sheetSectionsCache;
        if (!sections || !sections.length) { toast('Nothing to print', 'info'); return; }

        // Read the sheet-type picker — defaults to master (landscape)
        // if the radio group can't be found for any reason. The team
        // value drives orientation + columns + row-height entirely
        // inside _buildSheetDocument() — this function stays layout-
        // agnostic.
        const teamEl = document.querySelector('input[name="sheetType"]:checked');
        const sheetType = teamEl ? teamEl.value : 'master';
        const cfg = _sheetLayoutConfig(sheetType);

        // Open the preview window FIRST (in the same click tick) so
        // Chrome/Edge don't flag it as a popup. Portrait gets a
        // narrower preview window matching the page shape.
        const winW = cfg.orientation === 'portrait' ? 900 : 1180;

        closeSheetModal();

        const html = _buildSheetDocument(sections, sheetType);
        const w = window.open('', '_blank', 'width=' + winW + ',height=820');
        if (!w) {
            alert('Popup blocked! Please allow popups for this site to preview / print sheets.');
            return;
        }
        w.document.open();
        w.document.write(html);
        w.document.close();

        // Row count for the success toast — must match the
        // emission mode (collapsed = one row per line item, expanded
        // = one row per animal unit). Mirrors the same logic in the
        // preview-summary `summaryEl` block.
        const _toastRows = cfg.collapseBundlesToSingleRow
            ? sections.reduce((acc, s) => acc + s.items.length, 0)
            : sections.reduce((acc, s) => acc + s.section_total, 0);
        const _toastRowLabel = cfg.collapseBundlesToSingleRow ? 'printed row(s)' : 'animal row(s)';
        toast(
            cfg.label + ' · ' + sections.length + ' sheet(s) ready · ' + _toastRows + ' ' + _toastRowLabel + '. Review the preview and click "Print All Sheets".',
            'success',
            4000
        );
    }

    // -----------------------------------------------------------------
    // Team-aware print-sheet layout config. Three sheet types — each
    // carries its own orientation, column set, row height, and font
    // sizing. The shared filter pipeline / sectioning / per-customer
    // bundle math stays identical across all three; only the printed
    // layout changes between them.
    //
    //   master   — Landscape A4. Full record (8 columns). The "control
    //              copy" the manager keeps on-hand.
    //   delivery — Landscape A4. Driver manifest (6 columns: order#,
    //              customer, real street address, contact, qurbani,
    //              packs). One row per bundle (May-2026 — collapsed
    //              the same way Master collapses, so a 7-pack drop
    //              prints once with Packs = 7 instead of seven rows).
    //   inhouse  — Portrait A4.  Kitchen / slaughter (5 columns).
    //              Wide Type column for animal-detail notes; slim
    //              Qty / Paaye. Weight column was dropped (May-2026)
    //              because the team no longer attaches weight-machine
    //              stickers to the sheet — weights are recorded
    //              elsewhere now. The 2× row height that the sticker
    //              required was also reverted.
    //
    // Column keys are resolved by _sheetCellValue() — keeps row
    // rendering generic. To add a new column type, add a resolver
    // there and reference the key in a layout's `columns` array.
    // -----------------------------------------------------------------
    function _sheetLayoutConfig(sheetType) {
        // Header labels are deliberately SHORT (Order #, Qty, Type,
        // Packs, ...) so the uppercase-letter-spaced <th> text doesn't
        // overflow narrow fixed-width columns and visually collide
        // with adjacent headers. A word-wrap safety net on <th> below
        // catches anything still too long.
        if (sheetType === 'delivery') {
            // May-2026 (revision 2) — brought in line with the
            // Master sheet's recent updates:
            //   • Orientation switched portrait → LANDSCAPE so the
            //     real customer street address has room to breathe
            //     in one line instead of wrapping awkwardly in a
            //     30mm portrait column.
            //   • `address` swapped for `address_full` so the
            //     driver gets the actual street address (order
            //     shipping fields, falling back to customer
            //     profile) — not the region label. Same resolver
            //     the Master sheet uses; truncated at 70 chars.
            //   • `qty` swapped for `qty_total` AND
            //     collapseBundlesToSingleRow turned on so a 7-pack
            //     bundle prints as ONE row with Packs = 7 instead
            //     of seven rows (1/7 … 7/7). Driver reads "drop
            //     7 packs at this stop" in one glance.
            //   • Order # column widened 22mm → 26mm to match the
            //     "no clipping" fix already applied to Master.
            //
            // Landscape A4 usable width @ 10mm margin = 277mm.
            // Fixed cols = 26 + 42 + 34 + 22 + 20 = 144mm → auto
            // (address) gets ~133mm, plenty for full PK addresses.
            return {
                key: 'delivery',
                label: '🚚 Delivery Team Sheet',
                orientation: 'landscape',
                rowHeight: '14mm',
                titleSize: '24pt', metaSize: '12pt',
                thSize: '10pt',    tdSize: '12pt',
                orderSize: '11pt', qtySize: '15pt',
                collapseBundlesToSingleRow: true,
                columns: [
                    { key: 'order',        label: 'Order #',  width: '26mm' },
                    { key: 'name',         label: 'Customer', width: '42mm' },
                    { key: 'address_full', label: 'Address',  width: 'auto' },
                    { key: 'contact',      label: 'Contact',  width: '34mm' },
                    { key: 'qurbani',      label: 'Qurbani',  width: '22mm' },
                    { key: 'qty_total',    label: 'Packs',    width: '20mm' },
                ],
            };
        }
        if (sheetType === 'inhouse') {
            // May-2026 (revision) — switched to PORTRAIT A4, dropped
            // the empty Weight column (kitchen records weights
            // elsewhere now), and rebalanced widths so the Type
            // column gets the lion's share of the remaining space
            // (animal-detail notes are the longest field on this
            // sheet). Qty + Paaye are deliberately the narrowest
            // fixed columns because their values are short (e.g.
            // "1/5", "Yes/No").
            //
            // Portrait usable width @ 10mm margin = 190mm. Fixed
            // columns total = 24 + 50 + 18 + 22 = 114mm, leaving
            // ~76mm for the auto-sized Type column.
            //
            // rowHeight reduced from 28mm → 18mm — the 2× height
            // existed only to fit the weight-machine sticker. 18mm
            // still gives enough vertical breathing room to read
            // each animal's row at arm's length without wasting
            // paper.
            return {
                key: 'inhouse',
                label: '🔪 Inhouse Team Sheet',
                orientation: 'portrait',
                // May-2026 (revision 2) — row height bumped 18mm → 27mm
                // (×1.5). The earlier 18mm felt cramped when the kitchen
                // team writes notes by hand against each row. Type
                // column still gets the lion's share of horizontal
                // space via width:auto.
                rowHeight: '27mm',
                titleSize: '26pt', metaSize: '13pt',
                thSize: '10pt',    tdSize: '13pt',
                orderSize: '12pt', qtySize: '16pt',
                columns: [
                    { key: 'order', label: 'Order #',  width: '24mm' },
                    { key: 'name',  label: 'Customer', width: '50mm' },
                    { key: 'type',  label: 'Type',     width: 'auto' },
                    { key: 'qty',   label: 'Qty',      width: '18mm' },
                    { key: 'paya',  label: 'Paaye',    width: '22mm' },
                ],
            };
        }
        // Default: master sheet — landscape, full record.
        //
        // May-2026 (revision 2):
        //   • Row height 14mm → 21mm (×1.5) for breathing room.
        //   • `address` column swapped for new `address_full` resolver —
        //     prints the real customer street address (address1 + 2),
        //     auto-truncated to keep rows compact. Delivery sheet
        //     still uses the legacy region-based `address` so its
        //     driver manifest behaviour is unchanged.
        //   • `contact` column dropped (manager already has phones
        //     elsewhere; printed copy is now less PII-heavy).
        //   • `type` is now a FIXED 50mm with its own smaller font
        //     (typeSize: 11pt) — was auto, which made it dominate
        //     the row visually.
        //   • `qty` swapped for new `qty_total` (plain integer
        //     count) AND the row emission loop collapses each
        //     line item to ONE row when collapseBundlesToSingleRow
        //     is set, so a customer with a 7-share bundle prints
        //     one row (Qty 7) instead of seven rows (1/7 … 7/7).
        //     Inhouse sheet deliberately stays expanded — kitchen
        //     needs one row per animal unit for tracking.
        return {
            key: 'master',
            label: '📊 Master Sheet',
            orientation: 'landscape',
            rowHeight: '21mm',
            titleSize: '30pt', metaSize: '14pt',
            thSize: '10pt',    tdSize: '13pt',
            orderSize: '12pt', qtySize: '20pt',
            typeSize: '11pt',
            collapseBundlesToSingleRow: true,
            columns: [
                // 30mm (was 26mm) so the full QUR26-XXXX number prints
                // without clipping. white-space:nowrap was also
                // dropped on the order-column style below so any
                // future longer prefix wraps to two lines instead
                // of being silently truncated.
                { key: 'order',        label: 'Order #',  width: '30mm' },
                { key: 'name',         label: 'Customer', width: '42mm' },
                { key: 'address_full', label: 'Address',  width: 'auto' },
                { key: 'qurbani',      label: 'Qurbani',  width: '22mm' },
                { key: 'qty_total',    label: 'Qty',      width: '18mm' },
                { key: 'type',         label: 'Type',     width: '50mm' },
                { key: 'paya',         label: 'Paaye',    width: '28mm' },
            ],
        };
    }

    // Short, customer-facing animal label for the "Qurbani" column.
    // category_level_2 in the catalogue uses long names like
    //   "Goat (Bakra)" / "Cow Share (Hissa)" / "Lamb (Dumba)"
    // — printed copies need the single-word label customers / drivers
    // recognise on the actual delivery (per the user's Google-Sheets
    // reference). Heuristic resolves the four common categories;
    // anything else falls back to product_name → category_level_2 so
    // we never print a blank.
    function _sheetShortAnimalLabel(it) {
        const cat = String(it.category_level_2 || '').toLowerCase();
        if (/cow\s*share|hissa/.test(cat)) return 'Hissa';
        if (/goat|bakra/.test(cat))         return 'Goat';
        if (/lamb|dumba/.test(cat))         return 'Lamb';
        if (/(^|\s)cow(\s|$)/.test(cat))    return 'Cow';
        return (it.product_name || it.category_level_2 || '—');
    }

    // Resolves the value to render for one cell. `pos`/`total` are the
    // per-customer bundle coordinates already computed in
    // _groupItemsIntoSheetSections (e.g. pos=2, total=5 → "2/5").
    function _sheetCellValue(colKey, it, pos, total) {
        switch (colKey) {
            case 'order': {
                // May-2026 — split the order number around the
                // first dash so the prefix (e.g. "QUR26-") prints
                // in the column's base weight (800) while the
                // sequence suffix (e.g. "0361") is rendered
                // visually heavier. Helps the operator scan a
                // column of order numbers — the year prefix is
                // identical on every row, the suffix is the
                // distinguishing part. Applies to every sheet
                // layout because the column emits via this
                // resolver — Master / Inhouse / Delivery all
                // benefit from the same hierarchy. Falls back to
                // plain rendering when the order number has no
                // dash (defensive — never seen on real data).
                const raw = String(it.order_number || '—');
                const dashIdx = raw.indexOf('-');
                if (dashIdx < 0) return esc(raw);
                const prefix = raw.slice(0, dashIdx + 1);
                const suffix = raw.slice(dashIdx + 1);
                return esc(prefix) + '<span style="font-weight:900;">' + esc(suffix) + '</span>';
            }
            case 'name':    return esc(it.customer_name || '—');
            // Legacy region-based "address" — still used by the
            // Delivery Team sheet so its driver manifest behaviour
            // is unchanged. Master sheet uses 'address_full' below.
            case 'address': return esc(it.qurbani_region || it.qurbani_sub_region || '—');
            case 'address_full': {
                // May-2026 — real customer street address built
                // server-side from the order's shipping fields
                // (address_line1 + address_line2 + address_city)
                // with the customer profile address as fallback.
                // See QurbaniWebController::getOrderItems.
                //
                // Three distinct visual outcomes so the operator
                // can tell at a glance WHY a row is short on
                // address info (and reading "the region" no longer
                // silently masks bad data):
                //
                //   1. Field missing from payload → bright marker
                //      tells the user the page is stale and needs
                //      a hard refresh.
                //   2. Field present but empty → the order genuinely
                //      has no address on file; row prints "—" so
                //      the manager can spot it and follow up.
                //   3. Field has a value → print it, truncated at
                //      70 chars so PK-style long addresses stay on
                //      one or two lines in the auto-width column.
                if (typeof it.customer_address === 'undefined') {
                    // Diagnostic — show the first three address-ish
                    // keys we DID receive so we can tell whether the
                    // server-side change is live (just empty data)
                    // vs hasn't been picked up yet (key absent).
                    const candidates = ['customer_address','address_line1','address1','address']
                        .filter(k => Object.prototype.hasOwnProperty.call(it, k));
                    const note = candidates.length
                        ? 'keys present: ' + candidates.join(', ')
                        : 'no address keys in payload';
                    return '<span style="color:#dc2626;font-style:italic;">⚠ ' + esc(note) + '</span>';
                }
                const raw = String(it.customer_address || '').trim();
                if (!raw) return '<span style="color:#9ca3af;">— (empty in DB)</span>';
                const MAX_ADDR_CHARS = 70;
                return esc(raw.length > MAX_ADDR_CHARS
                    ? raw.slice(0, MAX_ADDR_CHARS - 1).trimEnd() + '…'
                    : raw);
            }
            case 'contact': return esc(it.customer_phone || '—');
            case 'qurbani': return esc(_sheetShortAnimalLabel(it));
            case 'qty':     return pos + '/' + total;
            // May-2026 — collapsed-row qty (Master sheet). Shows the
            // line item's quantity as a plain integer so a 7-share
            // hissa prints once as "7" instead of seven rows
            // (1/7 … 7/7). Used when cfg.collapseBundlesToSingleRow
            // is set; the row emission loop in _buildSheetDocument
            // skips the per-animal expansion in that mode.
            case 'qty_total': return String(Math.max(1, parseInt(it.quantity) || 1));
            case 'type':    return esc(it.qurbani_type || '—');
            case 'paya':    return esc(it.qurbani_paya || '—');
            case 'weight':  return '';   // empty — for weight sticker / hand-write
            default:        return '';
        }
    }

    // Self-contained A4 document containing ALL sections. CSS is
    // inlined so the preview window can't inherit the main page's
    // styles (same safety stance as buildPrintDocument).
    //
    // Layout (per section) — matches the user's original Google-Sheets
    // reference image:
    //   • Brand-orange title (CATEGORY in caps) + 2pt underline
    //   • Stacked meta block at the TOP of the sheet:
    //       Region:     <region>
    //       Sub Region: <sub_region>
    //       Day:        <day>
    //       Slot:       <slot>
    //   • Variable-column table driven by _sheetLayoutConfig(sheetType).
    //     Region + Sub Region are intentionally NOT columns — they
    //     already live in the section header above.
    //   • Each line item is expanded to ONE row per animal so qty
    //     reads as `pos/total` per-customer (e.g. 1/5, 2/5, ... 5/5).
    //   • Footer: brand line + print timestamp + sheet X of Y
    //
    // Team parameter drives orientation, columns, font sizes, and
    // row height. See _sheetLayoutConfig() for the exact recipe per
    // team. The shared filter pipeline / sectioning / bundle math
    // is completely untouched.
    function _buildSheetDocument(sections, sheetType) {
        const cfg = _sheetLayoutConfig(sheetType);
        const isPortrait = cfg.orientation === 'portrait';

        // Page geometry per orientation.
        //   Landscape: 297×210mm, 10mm margins → 277×190mm usable
        //   Portrait : 210×297mm, 10mm margins → 190×277mm usable
        const pageRule = isPortrait
            ? '@page { size: A4 portrait; margin: 10mm; }'
            : '@page { size: A4 landscape; margin: 10mm; }';

        // Preview-window page shape — swapped between orientations so
        // each "sheet card" on screen visually matches what comes out
        // of the printer (no mental rotation needed when reviewing).
        const previewPage = isPortrait
            ? '.sheet-page { background: #fff; padding: 10mm; box-shadow: 0 4px 12px rgba(0,0,0,.1); margin: 0 auto 20px; max-width: 210mm; min-height: 297mm; }'
            : '.sheet-page { background: #fff; padding: 10mm; box-shadow: 0 4px 12px rgba(0,0,0,.1); margin: 0 auto 20px; max-width: 297mm; min-height: 210mm; }';

        // Per-column CSS rules generated from the layout config so we
        // never have to update _buildSheetDocument when a layout's
        // column set changes — just the config and the cell resolver.
        // Special-case styling per column key matches the look the
        // previous hard-coded version produced.
        const colRules = cfg.columns.map(c => {
            let extra = '';
            switch (c.key) {
                case 'order':
                    // white-space:nowrap removed (May-2026) — long
                    // order numbers were silently clipped to the
                    // column width. We let them wrap to two lines
                    // instead so the operator always sees the full
                    // QUR26-XXXX value. The 30mm column on Master
                    // fits the current 10-char numbers on one line;
                    // wrap is just the safety net.
                    extra = "font-weight:800; font-family:'Courier New', monospace; font-size:" + cfg.orderSize + ";";
                    break;
                case 'name':
                    extra = 'font-weight:800;';
                    break;
                case 'type':
                    // typeSize is optional per layout (Master sets it
                    // smaller so the long product/type strings don't
                    // visually dominate the row). Other layouts fall
                    // back to the table's default tdSize.
                    extra = 'font-weight:700; line-height:1.3;'
                        + (cfg.typeSize ? (' font-size:' + cfg.typeSize + ';') : '');
                    break;
                case 'qty':
                case 'qty_total':
                    // qty_total prints a single integer (collapsed-row
                    // mode). Same big monospace treatment as qty so the
                    // headline number is easy to scan down a column.
                    extra = "text-align:center; font-weight:900; font-size:" + cfg.qtySize + "; font-family:'Courier New', monospace; color:#000;";
                    break;
                case 'paya':
                    extra = 'font-weight:700;';
                    break;
                case 'weight':
                    // Amber tint + dashed inner border hint so the
                    // weight-sticker slot stands out as "this is where
                    // the sticker / hand-fill goes".
                    extra = 'background:#fffbeb;';
                    break;
                case 'address':
                case 'address_full':
                case 'contact':
                case 'qurbani':
                    extra = 'font-weight:700;';
                    break;
            }
            return '.sheet-table .col-' + c.key + ' { width:' + c.width + '; ' + extra + ' }';
        }).join(' ');

        const css =
            pageRule +
            'html, body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; color: #111; background: #fff; }' +
            '* { box-sizing: border-box; }' +
            // Screen-only floating toolbar at the top of the preview
            // window. Has the "Print All Sheets" + "Close" buttons so
            // the user can review the preview and only print when
            // they\'re satisfied. Hidden in @media print so it never
            // appears on the actual paper.
            '.screen-toolbar { position: sticky; top: 0; z-index: 1000; background: #1f2937; color: #fff; padding: 12px 20px; display: flex; gap: 10px; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,.2); }' +
            '.screen-toolbar .lbl { font-weight: 700; margin-right: 8px; }' +
            '.screen-toolbar .meta { color: #fcd34d; font-size: 13px; margin-right: auto; }' +
            '.screen-toolbar button { padding: 8px 16px; border: none; border-radius: 6px; font-weight: 700; font-size: 13px; cursor: pointer; }' +
            '.screen-toolbar .btn-print { background: #d97706; color: #fff; }' +
            '.screen-toolbar .btn-print:hover { background: #b45309; }' +
            '.screen-toolbar .btn-close { background: #374151; color: #fff; }' +
            '.screen-toolbar .btn-close:hover { background: #4b5563; }' +
            '@media print { .screen-toolbar { display: none !important; } }' +
            '.sheet-page { width: 100%; }' +
            // Page-break controls — every section after the first one
            // starts on a fresh A4 page; long sections still flow onto
            // additional pages naturally.
            '.sheet-page + .sheet-page { page-break-before: always; }' +
            // Header — bigger title, stacked meta block at the TOP of
            // the sheet (Region / Sub Region / Day / Slot) matching the
            // user\'s original Google-Sheets reference.
            '.sheet-header { margin-bottom: 5mm; padding-bottom: 4mm; border-bottom: 2.5pt solid #d97706; }' +
            '.sheet-title { font-size: ' + cfg.titleSize + '; font-weight: 900; color: #d97706; margin: 0 0 5mm; letter-spacing: 1.5pt; text-transform: uppercase; line-height: 1; }' +
            '.sheet-meta { display: grid; grid-template-columns: max-content auto; gap: 2.5mm 8mm; font-size: ' + cfg.metaSize + '; color: #111; align-items: baseline; }' +
            '.sheet-meta .lbl { font-weight: 700; color: #4b5563; }' +
            '.sheet-meta .val { font-weight: 900; color: #000; }' +
            // Subtle team-tag below the meta block so anyone holding
            // the printed sheet knows which copy it is at a glance
            // (manager / driver / kitchen).
            '.sheet-team-tag { display: inline-block; margin-top: 3mm; padding: 1mm 3mm; background: #fff7ed; border: 1pt solid #fed7aa; color: #9a3412; font-size: 10pt; font-weight: 800; letter-spacing: 0.5pt; border-radius: 3pt; }' +
            // Table — bolder borders, comfortable row height, and
            // large body font so each row reads cleanly from arm\'s
            // length. Inhouse no longer reserves a hand-write Weight
            // column (May-2026 — see _sheetLayoutConfig).
            '.sheet-table { width: 100%; border-collapse: collapse; font-size: ' + cfg.tdSize + '; margin-top: 4mm; table-layout: fixed; }' +
            '.sheet-table thead { display: table-header-group; }' +  // repeat thead on each printed page
            // word-wrap + overflow-wrap on <th> are the safety net for
            // any header that ends up wider than its fixed column —
            // without these, uppercase letter-spaced labels in narrow
            // columns would overflow into adjacent <th> cells and
            // appear to "overlap" (e.g. "QUANTITY" bleeding into the
            // next column's header). overflow:hidden as the absolute
            // last-resort clip so we never visually break alignment.
            '.sheet-table th { background: #f3f4f6; border: 1.2pt solid #4b5563; padding: 3mm 3mm; text-align: left; font-weight: 900; text-transform: uppercase; font-size: ' + cfg.thSize + '; letter-spacing: 0.3pt; color: #1f2937; word-wrap: break-word; overflow-wrap: break-word; overflow: hidden; line-height: 1.15; }' +
            '.sheet-table td { border: 1.2pt solid #6b7280; padding: 3mm 3mm; vertical-align: middle; height: ' + cfg.rowHeight + '; word-wrap: break-word; overflow-wrap: break-word; overflow: hidden; font-weight: 700; color: #111; }' +
            '.sheet-table tr { page-break-inside: avoid; }' +
            // Per-column rules generated from the layout config above.
            colRules + ' ' +
            '.sheet-footer { margin-top: 4mm; padding-top: 3mm; border-top: 1pt solid #d1d5db; font-size: 9pt; color: #6b7280; display: flex; justify-content: space-between; }' +
            // Screen preview — each page rendered on its own card with
            // a drop shadow so the user can visually scroll through all
            // sheets before clicking Print.
            '@media screen { body { background: #f3f4f6; } .sheet-pages-wrap { padding: 20px; } ' + previewPage + ' .sheet-page + .sheet-page { page-break-before: always; } }';

        const stamp = new Date().toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        // Total printed rows for the preview window's header strip.
        // Mirrors the emission mode used below so the headline number
        // always matches what's actually in the preview.
        const totalRowsTopBar = cfg.collapseBundlesToSingleRow
            ? sections.reduce((acc, s) => acc + s.items.length, 0)
            : sections.reduce((acc, s) => acc + s.section_total, 0);
        const totalRowsTopBarLabel = cfg.collapseBundlesToSingleRow ? 'printed row(s)' : 'animal row(s)';

        // Screen-only toolbar at the top of the preview window. The
        // "Print All Sheets" button calls window.print() so the user
        // can review the full preview first and only then send to the
        // printer. NO auto-print — user explicitly asked for a
        // "preview before print" flow.
        let body = '<div class="screen-toolbar">' +
            '<span class="lbl">🖨️ ' + esc(cfg.label) + ' — Print Preview</span>' +
            '<span class="meta">' + sections.length + ' sheet(s) · ' + totalRowsTopBar + ' ' + totalRowsTopBarLabel + ' total · ' + (isPortrait ? 'Portrait' : 'Landscape') + ' A4</span>' +
            '<button class="btn-print" onclick="window.print()">🖨️ Print All Sheets</button>' +
            '<button class="btn-close" onclick="window.close()">Close</button>' +
            '</div>';

        body += '<div class="sheet-pages-wrap">';

        // Pre-build the <thead> row once — same for every section.
        const theadHtml = '<thead><tr>' +
            cfg.columns.map(c => '<th class="col-' + c.key + '">' + esc(c.label) + '</th>').join('') +
            '</tr></thead>';

        sections.forEach((section, idx) => {
            body += '<div class="sheet-page">';

            // Stacked header — Region / Sub Region / Day / Slot
            // mirror the user's original Google-Sheets reference.
            body += '<div class="sheet-header">';
            body += '<h1 class="sheet-title">' + esc(String(section.category).toUpperCase()) + '</h1>';
            body += '<div class="sheet-meta">' +
                    '<span class="lbl">Region:</span><span class="val">' + esc(section.region) + '</span>' +
                    '<span class="lbl">Sub Region:</span><span class="val">' + esc(section.sub_region) + '</span>' +
                    '<span class="lbl">Day:</span><span class="val">' + esc(section.day) + '</span>' +
                    '<span class="lbl">Slot:</span><span class="val">' + esc(section.slot) + '</span>' +
                    '</div>';
            body += '<span class="sheet-team-tag">' + esc(cfg.label) + '</span>';
            body += '</div>';

            body += '<table class="sheet-table">' + theadHtml + '<tbody>';

            // Row emission has two modes, picked per layout via
            // cfg.collapseBundlesToSingleRow:
            //
            //   Expanded (default — Inhouse, Delivery):
            //     Each line item produces one row PER ANIMAL UNIT.
            //     Qty denominator = the CUSTOMER's bundle inside this
            //     section (their total hissas / goats etc. for this
            //     Cat × Day × Slot × Region × SubRegion). So a customer
            //     with qty=2 prints "1/2, 2/2"; a customer with qty=1
            //     in the same section just prints "1/1". Other
            //     customers in the same section don't affect their
            //     denominators. Kitchen / driver needs one row per
            //     actual animal so this is the right granularity for
            //     them.
            //
            //   Collapsed (May-2026 — Master sheet):
            //     One row per LINE ITEM, qty cell prints the line
            //     item's quantity as a plain integer ("7"). Cuts a
            //     7-share hissa down from 7 rows to 1, which is what
            //     the manager actually wants on the control copy.
            //     A customer with TWO line items in the same section
            //     (rare but possible — e.g. a hissa + a separate
            //     hissa booking under the same order) still prints
            //     once per line item, preserving the per-item detail.
            if (cfg.collapseBundlesToSingleRow) {
                section.items.forEach(it => {
                    // pos / total args are unused for qty_total but
                    // we pass 1/1 defensively in case a future column
                    // resolver reads them.
                    body += '<tr>' +
                        cfg.columns.map(c =>
                            '<td class="col-' + c.key + '">' + _sheetCellValue(c.key, it, 1, 1) + '</td>'
                        ).join('') +
                        '</tr>';
                });
            } else {
                section.items.forEach(it => {
                    const start = it._sheet_pos_start;
                    const end   = it._sheet_pos_end;
                    const total = it._sheet_bundle_total;
                    for (let pos = start; pos <= end; pos++) {
                        body += '<tr>' +
                            cfg.columns.map(c =>
                                '<td class="col-' + c.key + '">' + _sheetCellValue(c.key, it, pos, total) + '</td>'
                            ).join('') +
                            '</tr>';
                    }
                });
            }

            body += '</tbody></table>';

            body += '<div class="sheet-footer">' +
                    '<span>Nizami Farms &middot; Qurbani \'26 &middot; ' + esc(cfg.label) + ' (' + esc(section.category) + ')</span>' +
                    '<span>Printed ' + esc(stamp) + ' &middot; ' + section.section_total + ' animal(s) &middot; Sheet ' + (idx + 1) + ' of ' + sections.length + '</span>' +
                    '</div>';
            body += '</div>';
        });

        body += '</div>'; // .sheet-pages-wrap

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Qurbani Sheets &mdash; ' + esc(cfg.label) + ' &mdash; ' + sections.length + ' sheet(s)</title>' +
            '<style>' + css + '</style></head><body>' + body +
            '</body></html>';
    }

    // ===== Phase 6 (May-2026) — Qurbani Location Request =============
    // Backs the toolbar "Request Locations" button (bulk send modal +
    // reviewer drawer) and the per-card "Request Location" button on
    // every order row without a verified pin.
    //
    // Three independent UI surfaces, kept synchronized:
    //   1. Toolbar badge       → poll /summary every 30s.
    //   2. Bulk send modal     → /eligible → tick-select → /send-bulk →
    //                            poll /bulk/{id}/start in chunks.
    //   3. Reviewer drawer     → /pending-review → Save / Save-All /
    //                            Dismiss → /save, /save-all, /dismiss.
    // Per-card status pills come from /statuses (bulk-keyed by
    // customer_id) populated into window._qoLocReqStatuses, looked up
    // synchronously inside renderItems() so we never block render on
    // network.
    //
    // SAFETY (matches what the service enforces server-side):
    //   - The save endpoint refuses to overwrite when the customer
    //     already has a NEWER verified_location_saved_at. The Reviewer
    //     drawer surfaces this with an amber warning + a separate
    //     "Force overwrite" button per row so it can only happen
    //     deliberately.

    window._qoLocReqStatuses = {};       // customer_id -> latest request status object
    let _qoLocReqEligibleRows = [];      // current Bulk modal candidate list
    let _qoLocReqSelected = new Set();   // customer_ids ticked in the Bulk modal
    let _qoLocReqActiveBatchId = null;   // batch_id while a send is in progress
    let _qoLocReqBatchPolling = false;   // re-entrancy guard for the chunked send loop
    let _qoLocReqSummaryTimer = null;    // setInterval handle for the badge refresh
    let _qoLocReviewRows = [];           // current Reviewer drawer items

    function _locReqCsrf() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function _locReqFmtRelTime(stamp) {
        if (!stamp) return '';
        const t = new Date(stamp.replace(' ', 'T'));
        if (isNaN(t.getTime())) return stamp;
        const diff = Date.now() - t.getTime();
        const m = Math.round(diff / 60000);
        if (m < 1) return 'just now';
        if (m < 60) return m + 'm ago';
        const h = Math.round(m / 60);
        if (h < 48) return h + 'h ago';
        return Math.round(h / 24) + 'd ago';
    }

    // ── Status hydration for per-card buttons ──────────────────────
    function hydrateLocReqStatuses() {
        const ids = [...new Set(allItems.map(it => it.customer_id).filter(Boolean))];
        if (!ids.length) return;
        fetch('/qurbani/api/loc-request/statuses', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': _locReqCsrf(),
                'Accept': 'application/json',
            },
            body: JSON.stringify({ customer_ids: ids }),
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            window._qoLocReqStatuses = data.data || {};
            // Only re-render the cards if there's at least one
            // customer with a known status — otherwise we'd thrash
            // the DOM on every load for nothing.
            if (Object.keys(window._qoLocReqStatuses).length > 0) {
                renderItems();
            }
        })
        .catch(() => {});
    }

    // ── Toolbar badge ──────────────────────────────────────────────
    function refreshLocReqSummaryBadge() {
        fetch('/qurbani/api/loc-request/summary?days=30', {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const pending = data.data.pending_review || 0;
            const badge = document.getElementById('locReqBadge');
            if (!badge) return;
            if (pending > 0) {
                badge.textContent = pending > 99 ? '99+' : pending;
                badge.style.display = 'inline-block';
                badge.title = pending + ' reply' + (pending === 1 ? '' : 'ies') + ' pending review — click to open the Reviewer drawer';
            } else {
                badge.style.display = 'none';
            }
        })
        .catch(() => {});
    }

    // ── Per-card single send ───────────────────────────────────────
    async function sendLocReqForLineItem(lineItemId, customerId, orderId, btn) {
        if (!customerId) {
            toast('No customer id on this row — cannot send.', 'error');
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Sending…'; }
        try {
            const res = await fetch('/qurbani/api/loc-request/send-one', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': _locReqCsrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    customer_id: customerId,
                    order_id: orderId,
                    line_item_id: lineItemId,
                }),
            });
            const data = await res.json();
            if (data.success) {
                toast('Location request sent ✓', 'success');
                window._qoLocReqStatuses[customerId] = {
                    display: 'sent_no_reply',
                    sent_at: new Date().toISOString(),
                    saved: false,
                };
                renderItems();
                refreshLocReqSummaryBadge();
            } else {
                toast('Send failed: ' + (data.error_message || data.message || data.status || 'unknown'), 'error');
                if (btn) { btn.disabled = false; btn.textContent = '⚠️ Retry send'; }
            }
        } catch (e) {
            toast('Send failed: ' + e.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = '⚠️ Retry send'; }
        }
    }

    // ── Bulk send modal ────────────────────────────────────────────
    function openLocReqSendModal() {
        document.getElementById('locReqSendOverlay').style.display = 'block';
        document.getElementById('locReqSendModal').style.display = 'block';
        // Seed filters from the main page so the user lands on the
        // same scope they were just looking at.
        const get = id => document.getElementById(id);
        const seed = (target, src) => {
            const t = get(target), s = get(src);
            if (t && s) t.value = (s.value && s.value !== '__unassigned__') ? s.value : '';
        };
        seed('locReqDay',          'filterDay');
        seed('locReqSlot',         'filterSlot');
        seed('locReqRegion',       'currentRegionRef');   // may not exist — safe no-op
        seed('locReqSubRegion',    'filterSubRegion');
        seed('locReqDeliveryType', 'filterDeliveryType');
        seed('locReqCategory',     'filterCategory');
        // Region: read from the active chip if present.
        const activeChip = document.querySelector('.qo-region-chip.active');
        if (activeChip && activeChip.dataset.region) {
            const rSel = get('locReqRegion');
            // Skip the "All Regions" chip (empty data-region) and the
            // __unassigned__ marker — neither maps to a select option.
            if (rSel && activeChip.dataset.region
                && activeChip.dataset.region !== '__unassigned__') {
                rSel.value = activeChip.dataset.region;
            }
        }
        _qoLocReqSelected = new Set();
        loadLocReqEligible();
    }

    function closeLocReqSendModal() {
        document.getElementById('locReqSendOverlay').style.display = 'none';
        document.getElementById('locReqSendModal').style.display = 'none';
    }

    function _locReqCollectFilters() {
        const get = id => (document.getElementById(id) || {}).value || '';
        return {
            day: get('locReqDay'),
            slot: get('locReqSlot'),
            region: get('locReqRegion'),
            sub_region: get('locReqSubRegion'),
            delivery_type: get('locReqDeliveryType'),
            category: get('locReqCategory'),
            include_delivered: document.getElementById('locReqIncludeDelivered')?.checked ? 1 : 0,
        };
    }

    // May-2026 — cached server-side stats (total + verified counts).
    // The unverified breakdown is derived from _qoLocReqEligibleRows
    // every render. Stored at module scope so renderLocReqStats() can
    // rebuild after any local mutation (e.g. dismissing a row) without
    // refetching.
    let _qoLocReqStats = { total_customers: 0, verified_customers: 0, unverified_customers: 0 };

    function loadLocReqEligible() {
        const f = _locReqCollectFilters();
        const tbody = document.getElementById('locReqListBody');
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px;">Loading…</td></tr>';
        const qs = new URLSearchParams(f).toString();
        // Reset stat tiles to "—" while we wait, so stale numbers from
        // the previous filter set don't sit on screen for a beat.
        _locReqResetStatsToLoading();
        fetch('/qurbani/api/loc-request/eligible?' + qs, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed to load eligible customers');
                _qoLocReqEligibleRows = data.items || [];
                _qoLocReqStats = data.stats || {
                    total_customers: _qoLocReqEligibleRows.length,
                    verified_customers: 0,
                    unverified_customers: _qoLocReqEligibleRows.length,
                };
                // Default: pre-tick everyone except customers we
                // messaged in the last 24h (matches the "Hide
                // recently sent" checkbox default).
                _qoLocReqSelected = new Set();
                _qoLocReqEligibleRows.forEach(r => {
                    if (_locReqIsRecentlySent(r)) return;
                    _qoLocReqSelected.add(r.customer_id);
                });
                renderLocReqList();
            })
            .catch(err => {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#dc2626;padding:20px;">'
                    + esc(err.message) + '</td></tr>';
                _locReqResetStatsToLoading();
            });
    }

    function _locReqResetStatsToLoading() {
        ['locReqStatTotal','locReqStatVerified','locReqStatUnverified','locReqStatWaiting','locReqStatReplied']
            .forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '—'; });
    }

    function _locReqIsRecentlySent(row) {
        const st = row.last_request;
        if (!st || !st.sent_at) return false;
        const t = new Date(st.sent_at.replace(' ', 'T')).getTime();
        if (isNaN(t)) return false;
        return (Date.now() - t) < (24 * 60 * 60 * 1000);
    }

    function renderLocReqList() {
        // Stats first so they update even when the table is empty.
        renderLocReqStats();

        const tbody = document.getElementById('locReqListBody');
        const hideRecent = document.getElementById('locReqHideRecentlySent')?.checked;
        const visible = _qoLocReqEligibleRows.filter(r => !(hideRecent && _locReqIsRecentlySent(r)));

        if (visible.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px;">'
                + 'No customers match these filters. (Already-verified customers are auto-excluded.)</td></tr>';
            document.getElementById('locReqSendBtn').disabled = true;
            _updateLocReqSelectionSummary();
            return;
        }

        // Rows are now PER-CUSTOMER (collapsed server-side). A customer
        // with multiple qualifying line items / orders / regions shows
        // as ONE row with aggregated context so the count on the
        // "Send to Selected" button always matches the number of
        // WhatsApps that will go out.
        let html = '';
        visible.forEach(r => {
            const checked = _qoLocReqSelected.has(r.customer_id) ? 'checked' : '';
            const st = r.last_request;
            let pill;
            if (!st) {
                pill = '<span class="qo-locreq-status-pill s-never">Never sent</span>';
            } else if (st.display === 'replied_pending') {
                pill = '<span class="qo-locreq-status-pill s-reply">Replied · review pending</span>';
            } else if (st.saved) {
                pill = '<span class="qo-locreq-status-pill s-saved">Saved</span>';
            } else if (st.display === 'sent_no_reply') {
                pill = '<span class="qo-locreq-status-pill s-sent">Sent ' + esc(_locReqFmtRelTime(st.sent_at)) + ', no reply</span>';
            } else if (st.display === 'failed') {
                pill = '<span class="qo-locreq-status-pill s-failed">Last send failed</span>';
            } else {
                pill = '<span class="qo-locreq-status-pill s-sent">' + esc(st.display) + '</span>';
            }

            // Orders cell: show first order # with a +N tag if more.
            const orderNums = r.order_numbers || [];
            const orderIds  = r.order_ids || [];
            let ordersCell = '<span style="color:#9ca3af;">—</span>';
            if (orderNums.length > 0) {
                const head = '<a href="/crm/orders/' + orderIds[0] + '" target="_blank" style="color:#2563eb;">#'
                           + esc(orderNums[0]) + '</a>';
                const tail = orderNums.length > 1
                    ? ' <span title="' + esc(orderNums.slice(1).join(', ')) + '" style="color:#9ca3af;">+'
                      + (orderNums.length - 1) + '</span>'
                    : '';
                ordersCell = head + tail;
            }

            // Region(s): show distinct regions; if many, truncate with title.
            const regions = (r.regions || []).filter(Boolean);
            const subs    = (r.sub_regions || []).filter(Boolean);
            const regBits = [];
            if (regions.length) {
                regBits.push(regions.length <= 2 ? regions.join(' / ')
                    : (regions.slice(0, 2).join(' / ') + ' …'));
            }
            if (subs.length) {
                regBits.push('<span style="color:#9ca3af;">' +
                    esc(subs.length <= 2 ? subs.join(' / ')
                        : (subs.slice(0, 2).join(' / ') + ' …')) + '</span>');
            }
            const regionsCell = regBits.length
                ? '<span title="' + esc([...regions, ...subs].join(', ')) + '">'
                  + regBits.join('<br>') + '</span>'
                : '—';

            // Day · Slot: distinct values, truncated.
            const days  = (r.days  || []).filter(Boolean);
            const slots = (r.slots || []).filter(Boolean);
            const daySlotBits = [];
            if (days.length)  daySlotBits.push(days.length  <= 2 ? days.join(', ')  : (days.slice(0,2).join(', ')  + ' +' + (days.length  - 2)));
            if (slots.length) daySlotBits.push(slots.length <= 1 ? slots.join(', ') : (slots[0] + ' +' + (slots.length - 1)));
            const daySlotCell = daySlotBits.length
                ? '<span title="' + esc('Days: ' + days.join(', ') + (slots.length ? ' · Slots: ' + slots.join(', ') : '')) + '">'
                  + esc(daySlotBits.join(' · ')) + '</span>'
                : '—';

            html += '<tr data-cid="' + r.customer_id + '">'
                + '<td><input type="checkbox" ' + checked + ' onchange="locReqToggleRow(' + r.customer_id + ', this.checked)"></td>'
                + '<td><div style="font-weight:600;color:#111827;">' + esc(r.customer_name || '—') + '</div>'
                +     '<div style="color:#9ca3af;font-size:11px;">' + esc(r.phone || '')
                +       (r.line_items_count > 1 ? ' · <span title="Has ' + r.line_items_count + ' line item(s)">' + r.line_items_count + ' item(s)</span>' : '')
                +     '</div></td>'
                + '<td>' + ordersCell + '</td>'
                + '<td>' + regionsCell + '</td>'
                + '<td>' + daySlotCell + '</td>'
                + '<td>' + pill + '</td>'
                + '</tr>';
        });
        tbody.innerHTML = html;
        _updateLocReqSelectionSummary();
    }

    function _updateLocReqSelectionSummary() {
        const total = _qoLocReqEligibleRows.length;
        const sel = _qoLocReqSelected.size;
        document.getElementById('locReqSelectionSummary').textContent =
            sel + ' of ' + total + ' eligible selected';
        const btn = document.getElementById('locReqSendBtn');
        btn.disabled = (sel === 0);
        btn.textContent = 'Send to Selected (' + sel + ')';
        document.getElementById('locReqSelectHeader').checked = (sel > 0 && sel === total);
    }

    // May-2026 — derive the unverified breakdown from the eligible
    // items array. Three actionable buckets:
    //   - never_asked: no last_request row at all → primary candidates
    //                  to send the first message to.
    //   - awaiting:    sent_no_reply OR failed → these are the ones
    //                  the user wants to chase down (call + remind).
    //   - replied:     replied_pending (reply not yet saved) → links
    //                  to the Reviewer drawer.
    function _locReqDeriveBreakdown() {
        let neverAsked = 0, awaiting = 0, replied = 0;
        const waitingList = [];
        (_qoLocReqEligibleRows || []).forEach(r => {
            const st = r.last_request;
            if (!st) { neverAsked++; return; }
            if (st.display === 'replied_pending') { replied++; return; }
            if (st.display === 'sent_no_reply' || st.display === 'failed') {
                awaiting++;
                waitingList.push(r);
                return;
            }
            // 'saved'/'dismissed'/'queued'/'sending'/'skipped' — count
            // saved/dismissed under verified noise, count the transient
            // ones (queued/sending) under awaiting so the user sees they
            // exist. Keep it simple — they're rare.
            if (st.display === 'queued' || st.display === 'sending') {
                awaiting++;
                waitingList.push(r);
            }
        });
        return { neverAsked, awaiting, replied, waitingList };
    }

    function renderLocReqStats() {
        const total      = _qoLocReqStats.total_customers || 0;
        const verified   = _qoLocReqStats.verified_customers || 0;
        const unverified = _qoLocReqStats.unverified_customers || _qoLocReqEligibleRows.length;
        const b = _locReqDeriveBreakdown();

        const pct = (n) => total > 0 ? Math.round((n / total) * 100) + '%' : '0%';

        const setTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        setTxt('locReqStatTotal',      String(total));
        setTxt('locReqStatVerified',   verified + ' (' + pct(verified) + ')');
        setTxt('locReqStatUnverified', unverified + ' (' + pct(unverified) + ')');
        setTxt('locReqStatWaiting',    String(b.awaiting));
        setTxt('locReqStatReplied',    String(b.replied));

        // If the panel is open, refresh its rows to reflect the latest
        // status (e.g. after a Remind action bumps a row from
        // never_asked → awaiting).
        const panel = document.getElementById('locReqWaitingPanel');
        if (panel && panel.style.display !== 'none') {
            _renderLocReqWaitingList(b.waitingList);
        }
    }

    function locReqToggleWaitingPanel(forceState) {
        const panel = document.getElementById('locReqWaitingPanel');
        const caret = document.getElementById('locReqStatWaitingCaret');
        if (!panel) return;
        const willOpen = (typeof forceState === 'boolean')
            ? forceState
            : (panel.style.display === 'none' || !panel.style.display);
        panel.style.display = willOpen ? 'block' : 'none';
        if (caret) caret.textContent = willOpen ? '▴' : '▾';
        if (willOpen) {
            const { waitingList } = _locReqDeriveBreakdown();
            _renderLocReqWaitingList(waitingList);
        }
    }

    function _renderLocReqWaitingList(list) {
        const countEl = document.getElementById('locReqWaitingCount');
        const box = document.getElementById('locReqWaitingList');
        if (!box) return;
        if (countEl) countEl.textContent = String(list.length);
        if (list.length === 0) {
            box.innerHTML = '<div style="color:#6b7280;font-style:italic;padding:6px 0;">'
                + 'Nobody is currently awaiting reply in this filter. 🎉</div>';
            return;
        }
        // Sort oldest sent first — those are the most overdue chases.
        const sorted = list.slice().sort((a, b) => {
            const ta = (a.last_request && a.last_request.sent_at) || '';
            const tb = (b.last_request && b.last_request.sent_at) || '';
            return ta.localeCompare(tb);
        });
        let html = '';
        sorted.forEach(r => {
            const st = r.last_request || {};
            const phone = (r.phone || '').replace(/\s+/g, '');
            const telHref = phone ? 'tel:' + phone : '#';
            const sentAgo = st.sent_at ? _locReqFmtRelTime(st.sent_at) : '—';
            const failed = st.display === 'failed';
            html +=
                '<div data-cid="' + r.customer_id + '" '
                +     'style="display:flex;align-items:center;gap:8px;padding:6px 8px;background:#fff;border:1px solid #fecaca;border-radius:5px;">'
                +   '<div style="flex:1;min-width:0;">'
                +     '<div style="font-weight:600;color:#111827;">' + esc(r.customer_name || '—')
                +       (failed ? ' <span style="background:#fecaca;color:#7f1d1d;font-size:10px;padding:1px 6px;border-radius:8px;">SEND FAILED</span>' : '')
                +     '</div>'
                +     '<div style="font-size:11px;color:#6b7280;">'
                +       (phone ? esc(phone) : '<i>no phone</i>')
                +       ' · last sent ' + esc(sentAgo)
                +     '</div>'
                +   '</div>'
                +   (phone
                       ? '<a href="' + telHref + '" '
                         + 'style="padding:4px 10px;border-radius:5px;background:#dbeafe;color:#1d4ed8;font-weight:700;text-decoration:none;font-size:11px;border:1px solid #bfdbfe;">📞 Call</a>'
                       : '')
                +   '<button type="button" onclick="locReqRemindOne(' + r.customer_id + ', this)" '
                +     'style="padding:4px 10px;border-radius:5px;background:#fef3c7;color:#92400e;font-weight:700;border:1px solid #fcd34d;cursor:pointer;font-size:11px;">'
                +     '💬 Remind'
                +   '</button>'
                + '</div>';
        });
        box.innerHTML = html;
    }

    // Re-fires the qurbani_location template to a single customer.
    // Uses the existing /send-one endpoint (per-card flow), so the
    // worker drains immediately and the new sent_at stamps onto the
    // most recent request row for this customer.
    function locReqRemindOne(customerId, btn) {
        if (!customerId) return;
        // Pick the customer's most recent order/line item context so
        // the reminder row carries the same region/day/slot — keeps
        // Reviewer-drawer grouping consistent.
        const row = (_qoLocReqEligibleRows || []).find(r => r.customer_id === customerId);
        const orderId = row && row.order_ids && row.order_ids[0] ? row.order_ids[0] : null;
        const lineItemId = row && row.line_item_ids && row.line_item_ids[0] ? row.line_item_ids[0] : null;
        const prevText = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
        fetch('/qurbani/api/loc-request/send-one', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ customer_id: customerId, order_id: orderId, line_item_id: lineItemId }),
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (btn) { btn.textContent = '✓ Sent'; btn.style.background = '#d1fae5'; btn.style.color = '#065f46'; btn.style.borderColor = '#34d399'; }
                // Refresh the eligible list so the new sent_at shows up
                // and the row may drop out of "Hide messaged in last 24h".
                setTimeout(loadLocReqEligible, 1200);
            } else {
                if (btn) { btn.disabled = false; btn.textContent = prevText || '💬 Remind'; }
                alert('Remind failed: ' + ((data && (data.error_message || data.message)) || 'unknown error'));
            }
        })
        .catch(() => {
            if (btn) { btn.disabled = false; btn.textContent = prevText || '💬 Remind'; }
            alert('Remind failed — network error');
        });
    }

    // "Remind All Waiting" — queues the qurbani_location template for
    // every customer currently in the waiting bucket. Reuses sendBulk
    // + startBatch so the existing rate limiter and progress bar do
    // the heavy lifting. Bypasses the 24h-hide UI filter (this is an
    // explicit user action) but still subject to the server-side
    // dedupe / send rules.
    async function locReqRemindAllWaiting() {
        const { waitingList } = _locReqDeriveBreakdown();
        if (waitingList.length === 0) {
            alert('Nobody is currently awaiting reply.');
            return;
        }
        if (!confirm('Send a reminder qurbani_location WhatsApp to ' + waitingList.length + ' waiting customer(s)?')) return;
        const customerIds = waitingList.map(r => r.customer_id);
        const filters = _locReqCollectFilters();
        try {
            const res = await fetch('/qurbani/api/loc-request/send-bulk', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify(Object.assign({ customer_ids: customerIds, batch_label: 'Reminder · awaiting reply' }, filters)),
            });
            const data = await res.json();
            if (!data || !data.success) throw new Error((data && data.message) || 'Failed to queue reminders');
            // Reuse the standard send-drain pipeline so the progress
            // bar, batch dashboard and review jump all just work — this
            // is the same flow runLocReqSend() runs after a successful
            // /send-bulk queue.
            _qoLocReqActiveBatchId = data.batch_id;
            const queued = data.queued || waitingList.length;
            const progress = document.getElementById('locReqProgress');
            if (progress) progress.style.display = 'block';
            const lbl = document.getElementById('locReqProgressLabel');
            if (lbl) lbl.textContent = 'Sending reminders…';
            const cnt = document.getElementById('locReqProgressCount');
            if (cnt) cnt.textContent = '0 / ' + queued;
            const bar = document.getElementById('locReqProgressBar');
            if (bar) bar.style.width = '0%';
            await _runLocReqBatchPoll(data.batch_id, queued);
            // Pull fresh stats so the "Awaiting Reply" tile reflects
            // the freshly bumped sent_at timestamps (some may now fall
            // outside the "no reply yet" window once Meta delivers).
            setTimeout(loadLocReqEligible, 800);
        } catch (err) {
            alert('Remind All failed: ' + (err.message || 'unknown error'));
        }
    }

    function locReqToggleRow(cid, on) {
        if (on) _qoLocReqSelected.add(cid);
        else _qoLocReqSelected.delete(cid);
        _updateLocReqSelectionSummary();
    }

    function locReqSelectAll(on) {
        const hideRecent = document.getElementById('locReqHideRecentlySent')?.checked;
        const visible = _qoLocReqEligibleRows.filter(r => !(hideRecent && _locReqIsRecentlySent(r)));
        _qoLocReqSelected = new Set();
        if (on) visible.forEach(r => _qoLocReqSelected.add(r.customer_id));
        renderLocReqList();
    }

    function locReqSelectNeverRequested() {
        _qoLocReqSelected = new Set();
        _qoLocReqEligibleRows.forEach(r => {
            if (!r.last_request) _qoLocReqSelected.add(r.customer_id);
        });
        renderLocReqList();
    }

    // ── Bulk send execution (chunked polling) ──────────────────────
    async function runLocReqSend() {
        if (_qoLocReqSelected.size === 0) return;
        if (!confirm('Send the qurbani_location WhatsApp template to ' + _qoLocReqSelected.size + ' customer(s)?')) return;

        const sendBtn = document.getElementById('locReqSendBtn');
        sendBtn.disabled = true;
        sendBtn.textContent = 'Queueing…';

        try {
            const filters = _locReqCollectFilters();
            const res = await fetch('/qurbani/api/loc-request/send-bulk', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': _locReqCsrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    customer_ids: [..._qoLocReqSelected],
                    ...filters,
                }),
            });
            const data = await res.json();
            if (!data.success) {
                toast('Queue failed: ' + (data.message || 'unknown'), 'error');
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send to Selected (' + _qoLocReqSelected.size + ')';
                return;
            }
            _qoLocReqActiveBatchId = data.batch_id;
            const queued = data.queued;
            const skippedNoPhone = data.skipped_no_phone || 0;
            const skippedDup = data.skipped_duplicate || 0;
            toast('Batch queued: ' + queued + ' messages'
                  + (skippedNoPhone ? ' (' + skippedNoPhone + ' skipped — no phone)' : ''),
                  'info');

            document.getElementById('locReqProgress').style.display = 'block';
            document.getElementById('locReqProgressLabel').textContent = 'Sending…';
            document.getElementById('locReqProgressCount').textContent = '0 / ' + queued;
            document.getElementById('locReqProgressBar').style.width = '0%';
            document.getElementById('locReqProgressDetail').textContent = 'Polling Meta…';

            await _runLocReqBatchPoll(_qoLocReqActiveBatchId, queued);

            sendBtn.disabled = false;
            sendBtn.textContent = 'Send to Selected (' + _qoLocReqSelected.size + ')';
            refreshLocReqSummaryBadge();
        } catch (e) {
            toast('Bulk send error: ' + e.message, 'error');
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send to Selected (' + _qoLocReqSelected.size + ')';
        }
    }

    async function _runLocReqBatchPoll(batchId, expected) {
        if (_qoLocReqBatchPolling) return;  // re-entrancy guard
        _qoLocReqBatchPolling = true;
        const startedAt = Date.now();
        try {
            let safety = 200;   // 200 chunks * ~100 rows = 20k row hard ceiling
            while (safety-- > 0) {
                const res = await fetch('/qurbani/api/loc-request/bulk/' + batchId + '/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': _locReqCsrf(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({}),
                });
                const data = await res.json();
                if (!data.success) {
                    document.getElementById('locReqProgressDetail').textContent = 'Error: ' + (data.message || 'unknown');
                    break;
                }
                const b = data.batch || {};
                const total = b.total || expected || 0;
                const done = (b.sent || 0) + (b.failed || 0) + (b.skipped || 0);
                const pct = total > 0 ? Math.round((done / total) * 100) : 100;
                document.getElementById('locReqProgressCount').textContent = done + ' / ' + total;
                document.getElementById('locReqProgressBar').style.width = pct + '%';
                document.getElementById('locReqProgressDetail').textContent =
                    'Sent ' + (b.sent || 0) + ' · Failed ' + (b.failed || 0)
                    + ' · Skipped ' + (b.skipped || 0)
                    + ' · Replied ' + (b.replied || 0);
                if (data.done) {
                    document.getElementById('locReqProgressLabel').textContent = '✓ Batch complete — watching for replies…';
                    const elapsedS = Math.round((Date.now() - startedAt) / 1000);
                    toast('Bulk send complete: ' + (b.sent || 0) + ' sent in ' + elapsedS + 's', 'success');
                    // Flip to live dashboard mode so the user can
                    // watch replies tick in without leaving the modal.
                    _showLocReqBatchDashboard(batchId, b);
                    _startLocReqBatchAutoRefresh(batchId);
                    break;
                }
                // Tiny gap between chunks — server already paces inside
                // each chunk via the QurbaniLocationRequestService.
                await new Promise(r => setTimeout(r, 400));
            }
        } finally {
            _qoLocReqBatchPolling = false;
        }
    }

    // ── Post-send live dashboard ───────────────────────────────────
    // After a batch finishes sending we keep polling /batch/{id} so
    // the user sees replies arrive in real time and can jump straight
    // to the Reviewer drawer (scoped to this batch) to bulk-save
    // them. Auto-refresh stops when the modal is closed.
    let _qoLocReqDashTimer = null;

    function _showLocReqBatchDashboard(batchId, batchObj) {
        document.getElementById('locReqBatchDashboard').style.display = 'block';
        _renderLocReqBatchDashboard(batchObj);
    }

    function _renderLocReqBatchDashboard(b) {
        if (!b) return;
        document.getElementById('locReqDashSent').textContent    = b.sent || 0;
        document.getElementById('locReqDashReplied').textContent = b.replied || 0;
        document.getElementById('locReqDashReview').textContent  = b.reviewable || 0;
        document.getElementById('locReqDashSaved').textContent   = b.saved || 0;
        const reviewBtn = document.getElementById('locReqDashReviewBtn');
        reviewBtn.textContent = '📋 Review & Save Replies (' + (b.reviewable || 0) + ')';
        reviewBtn.disabled = !((b.reviewable || 0) > 0);
        // Soften the "Awaiting save" tile when there's nothing to do.
        const card = document.getElementById('locReqDashReviewCard');
        if ((b.reviewable || 0) > 0) {
            card.style.background = '#fffbeb';
            card.style.borderColor = '#fcd34d';
        } else {
            card.style.background = '#f9fafb';
            card.style.borderColor = '#e5e7eb';
        }
        document.getElementById('locReqDashUpdated').textContent =
            'Updated ' + new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'})
            + ' · auto-refreshes every 15s';
    }

    function _startLocReqBatchAutoRefresh(batchId) {
        if (_qoLocReqDashTimer) clearInterval(_qoLocReqDashTimer);
        _qoLocReqDashTimer = setInterval(() => {
            // Stop polling if the modal got closed (user navigated away).
            const modal = document.getElementById('locReqSendModal');
            if (!modal || modal.style.display !== 'block') {
                clearInterval(_qoLocReqDashTimer);
                _qoLocReqDashTimer = null;
                return;
            }
            fetch('/qurbani/api/loc-request/batch/' + batchId, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) _renderLocReqBatchDashboard(data.data);
            })
            .catch(() => {});
        }, 15000);
    }

    function openLocReqReviewDrawerForBatch() {
        // Open the reviewer drawer scoped to the most-recently-sent
        // batch so the user sees ONLY this batch's replies. Falls
        // back to the global "all replies" view if no batch in flight.
        const batchId = _qoLocReqActiveBatchId;
        if (batchId) {
            openLocReqReviewDrawer(batchId);
        } else {
            openLocReqReviewDrawer();
        }
    }

    // ── Reviewer drawer ────────────────────────────────────────────
    let _qoLocReviewScopedBatchId = null;

    function openLocReqReviewDrawer(scopedBatchId) {
        _qoLocReviewScopedBatchId = scopedBatchId || null;
        document.getElementById('locReviewOverlay').style.display = 'block';
        document.getElementById('locReviewDrawer').style.display = 'flex';
        // Update the title to make the scope obvious.
        const titleEl = document.getElementById('locReviewTitle');
        if (titleEl) {
            titleEl.textContent = _qoLocReviewScopedBatchId
                ? '📋 Location Replies — This Batch'
                : '📋 Location Replies — Pending Review';
        }
        loadLocReviewQueue();
    }

    function closeLocReqReviewDrawer() {
        document.getElementById('locReviewOverlay').style.display = 'none';
        document.getElementById('locReviewDrawer').style.display = 'none';
        _qoLocReviewScopedBatchId = null;
    }

    function loadLocReviewQueue() {
        const body = document.getElementById('locReviewBody');
        body.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:20px;font-size:12px;">Loading…</div>';
        document.getElementById('locReviewSummary').textContent = 'Loading…';
        let url = '/qurbani/api/loc-request/pending-review?days=30';
        if (_qoLocReviewScopedBatchId) {
            url += '&batch_id=' + encodeURIComponent(_qoLocReviewScopedBatchId);
        }
        fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to load review queue');
            _qoLocReviewRows = data.items || [];
            renderLocReviewQueue();
        })
        .catch(err => {
            body.innerHTML = '<div style="text-align:center;color:#dc2626;padding:20px;font-size:12px;">'
                + esc(err.message) + '</div>';
            document.getElementById('locReviewSummary').textContent = 'Error';
        });
    }

    function renderLocReviewQueue() {
        const body = document.getElementById('locReviewBody');
        const saveAllBtn = document.querySelector('.qo-locreview-foot .qo-toolbar-btn.secondary');
        if (_qoLocReviewRows.length === 0) {
            body.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:24px;font-size:12px;">'
                + 'No replies waiting. Send some templates from the bulk modal, then come back here.</div>';
            document.getElementById('locReviewSummary').textContent = 'Nothing to review.';
            if (saveAllBtn) {
                saveAllBtn.disabled = true;
                saveAllBtn.textContent = '💾 Save All (safe)';
            }
            return;
        }
        const warnCount = _qoLocReviewRows.filter(r => r.has_newer_pin).length;
        const safeCount = _qoLocReviewRows.length - warnCount;
        document.getElementById('locReviewSummary').textContent =
            _qoLocReviewRows.length + ' reply' + (_qoLocReviewRows.length === 1 ? '' : 'ies') + ' to review'
            + (warnCount ? ' · ' + warnCount + ' would overwrite a newer pin (skipped by Save All)' : '');
        // Surface the safe-save count on the bulk button so the user
        // knows up-front exactly how many will be written.
        if (saveAllBtn) {
            saveAllBtn.disabled = (safeCount === 0);
            saveAllBtn.textContent = '💾 Save All Safe (' + safeCount + ')';
        }

        let html = '';
        _qoLocReviewRows.forEach(r => {
            const ctxBits = [];
            if (r.context.region)     ctxBits.push(r.context.region);
            if (r.context.sub_region) ctxBits.push(r.context.sub_region);
            if (r.context.day)        ctxBits.push(r.context.day);
            if (r.context.slot)       ctxBits.push(r.context.slot);
            const ctxStr = ctxBits.join(' · ');
            html += '<div class="qo-locreview-row ' + (r.has_newer_pin ? 'is-warn' : '') + '" data-rid="' + r.id + '">';
            html += '<div class="row-top">';
            html +=   '<span class="row-cust">' + esc(r.customer_name || ('Customer #' + r.customer_id)) + '</span>';
            html +=   '<span style="font-size:11px;color:#9ca3af;">' + esc(_locReqFmtRelTime(r.replied_at)) + '</span>';
            html += '</div>';
            html += '<div class="row-meta">';
            html +=   '📞 ' + esc(r.wa_phone || '—');
            if (ctxStr) html += ' · ' + esc(ctxStr);
            if (r.reply_address) html += '<br>🏠 ' + esc(r.reply_address);
            html += '<br>📍 <a href="https://www.google.com/maps/search/?api=1&query='
                  + r.lat + ',' + r.lng + '" target="_blank" style="color:#2563eb;">'
                  + r.lat.toFixed(5) + ', ' + r.lng.toFixed(5) + ' (open in maps)</a>';
            html += '</div>';
            if (r.has_newer_pin && r.existing_pin) {
                html += '<div class="row-warn">⚠️ This customer already has a NEWER manual pin '
                     + 'set on ' + esc(r.existing_pin.pinned_at || '—') + '. '
                     + 'Plain "Save" will SKIP this row to protect the existing pin. '
                     + 'Use "Force overwrite" only if you\'re sure.</div>';
            }
            html += '<div class="row-actions">';
            html += '<button class="qo-toolbar-btn primary" style="padding:4px 10px;font-size:11px;" '
                  + 'onclick="locReviewSaveOne(' + r.id + ', false, this)">💾 Save</button>';
            if (r.has_newer_pin) {
                html += '<button class="qo-toolbar-btn secondary" style="padding:4px 10px;font-size:11px;border-color:#dc2626;color:#dc2626;" '
                      + 'onclick="locReviewSaveOne(' + r.id + ', true, this)">⚠️ Force overwrite</button>';
            }
            html += '<button class="qo-toolbar-btn secondary" style="padding:4px 10px;font-size:11px;" '
                  + 'onclick="locReviewDismissOne(' + r.id + ', this)">🗑 Dismiss</button>';
            html += '</div>';
            html += '</div>';
        });
        body.innerHTML = html;
    }

    async function locReviewSaveOne(id, force, btn) {
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
        try {
            const res = await fetch('/qurbani/api/loc-request/save/' + id, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': _locReqCsrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ force: !!force }),
            });
            const data = await res.json();
            if (data.success) {
                toast('Location saved to customer ✓', 'success');
                _markReviewRowDone(id);
            } else if (data.skipped_reason) {
                toast('Skipped: ' + data.skipped_reason, 'info');
                _markReviewRowDone(id);
            } else {
                toast('Save failed: ' + (data.message || 'unknown'), 'error');
                if (btn) { btn.disabled = false; btn.textContent = '💾 Save'; }
            }
            refreshLocReqSummaryBadge();
            // Also re-hydrate per-card status so the affected customer's
            // button on the Orders page flips back to "📍" (verified).
            hydrateLocReqStatuses();
        } catch (e) {
            toast('Save failed: ' + e.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = '💾 Save'; }
        }
    }

    async function locReviewDismissOne(id, btn) {
        if (!confirm('Dismiss this reply? (It won\'t be saved to the customer.)')) return;
        if (btn) { btn.disabled = true; btn.textContent = 'Dismissing…'; }
        try {
            const res = await fetch('/qurbani/api/loc-request/dismiss/' + id, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': _locReqCsrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({}),
            });
            const data = await res.json();
            if (data.success) {
                toast('Reply dismissed.', 'info');
                _markReviewRowDone(id);
                refreshLocReqSummaryBadge();
            } else {
                toast('Dismiss failed.', 'error');
                if (btn) { btn.disabled = false; btn.textContent = '🗑 Dismiss'; }
            }
        } catch (e) {
            toast('Dismiss failed: ' + e.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = '🗑 Dismiss'; }
        }
    }

    function _markReviewRowDone(id) {
        const el = document.querySelector('.qo-locreview-row[data-rid="' + id + '"]');
        if (!el) return;
        el.classList.add('is-done');
        el.querySelectorAll('button').forEach(b => { b.disabled = true; });
        // Remove from local cache so summary count stays accurate.
        _qoLocReviewRows = _qoLocReviewRows.filter(r => r.id !== id);
        const remaining = _qoLocReviewRows.length;
        const warnCount = _qoLocReviewRows.filter(r => r.has_newer_pin).length;
        document.getElementById('locReviewSummary').textContent =
            remaining + ' reply' + (remaining === 1 ? '' : 'ies') + ' to review'
            + (warnCount ? ' · ' + warnCount + ' would overwrite a newer pin' : '');
    }

    async function locReviewSaveAll(force) {
        // "Safe" Save All deliberately omits force, so the server
        // skips rows where the customer already has a newer pin.
        // Those rows stay in the list, flagged for explicit action.
        const safeRows = _qoLocReviewRows.filter(r => force || !r.has_newer_pin);
        if (safeRows.length === 0) {
            toast('No safely-savable replies — every remaining row would overwrite a newer pin.', 'info');
            return;
        }
        if (!confirm('Save ' + safeRows.length + ' location reply' + (safeRows.length === 1 ? '' : 'ies') + ' to their customer records?')) return;
        try {
            const res = await fetch('/qurbani/api/loc-request/save-all', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': _locReqCsrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    ids: safeRows.map(r => r.id),
                    force: !!force,
                }),
            });
            const data = await res.json();
            if (data.success) {
                toast('Saved ' + data.saved + ' · skipped ' + data.skipped, 'success');
                loadLocReviewQueue();
                refreshLocReqSummaryBadge();
                hydrateLocReqStatuses();
            } else {
                toast('Save All failed: ' + (data.message || 'unknown'), 'error');
            }
        } catch (e) {
            toast('Save All failed: ' + e.message, 'error');
        }
    }

    // ── Bootstrap: kick off the summary badge poller on first
    // load. Also fires immediately so the badge shows whatever state
    // there was when the staff opens the page.
    refreshLocReqSummaryBadge();
    if (_qoLocReqSummaryTimer) clearInterval(_qoLocReqSummaryTimer);
    _qoLocReqSummaryTimer = setInterval(refreshLocReqSummaryBadge, 30000);

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
    // A4 print-sheet modal (May-2026) — inline onclick on the toolbar
    // button, the modal backdrop, the close (×) button, the filter
    // <select>s, the Include-Delivered checkbox, and the Print button
    // all reach into window.* so they must be exported the same way
    // every other modal handler is on this page.
    window.openPrintSheetModal = openPrintSheetModal;
    window.closeSheetModal = closeSheetModal;
    window.loadSheetPreview = loadSheetPreview;
    window.runSheetPrintFromModal = runSheetPrintFromModal;
    // Cascading filter helpers — wired from the Region / Day / Delivery
    // Type <select> onchange attributes so the Sub-Region / Slot
    // dropdowns narrow to valid children when their parent changes.
    window.updateSheetSubRegionDropdown = updateSheetSubRegionDropdown;
    window.updateSheetSlotDropdown = updateSheetSlotDropdown;
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

    // Phase 6 (May-2026) — Qurbani Location Request feature. All
    // inline-onclick handlers from the toolbar button, the bulk-send
    // modal, the reviewer drawer, and the per-card "Request Location"
    // button need their handlers reachable on window.* so the browser
    // doesn't throw "is not defined" the first time they're pressed.
    window.openLocReqSendModal = openLocReqSendModal;
    window.closeLocReqSendModal = closeLocReqSendModal;
    window.loadLocReqEligible = loadLocReqEligible;
    window.renderLocReqList = renderLocReqList;
    window.locReqToggleRow = locReqToggleRow;
    window.locReqSelectAll = locReqSelectAll;
    window.locReqSelectNeverRequested = locReqSelectNeverRequested;
    window.runLocReqSend = runLocReqSend;
    window.openLocReqReviewDrawer = openLocReqReviewDrawer;
    window.closeLocReqReviewDrawer = closeLocReqReviewDrawer;
    window.loadLocReviewQueue = loadLocReviewQueue;
    window.locReviewSaveOne = locReviewSaveOne;
    window.locReviewDismissOne = locReviewDismissOne;
    window.locReviewSaveAll = locReviewSaveAll;
    window.sendLocReqForLineItem = sendLocReqForLineItem;
    window.openLocReqReviewDrawerForBatch = openLocReqReviewDrawerForBatch;
    // May-2026 stats strip + Awaiting-Reply expander on the bulk modal.
    window.locReqToggleWaitingPanel = locReqToggleWaitingPanel;
    window.locReqRemindOne = locReqRemindOne;
    window.locReqRemindAllWaiting = locReqRemindAllWaiting;

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
            // Phase 4 (May-2026): slot vs ETA / delivered chip. Server
            // pre-computes this from qurbani_slot_end_minute (settings
            // override > parser auto-detect) and the relevant
            // timestamp (delivered_at when delivered, ETA otherwise).
            // Lives next to the route-position pill so on-time
            // status reads at a glance.
            if (d.slot_compare && d.slot_compare.label) {
                const sc = d.slot_compare;
                const isWithin = sc.state === 'within';
                const isDeliveredCmp = !!(d.line_item && d.line_item.qurbani_item_status === 'delivered');
                const bg = isWithin ? '#d1fae5' : (isDeliveredCmp ? '#fee2e2' : '#fef3c7');
                const fg = isWithin ? '#065f46' : (isDeliveredCmp ? '#991b1b' : '#92400e');
                const bd = isWithin ? '#10b981' : (isDeliveredCmp ? '#ef4444' : '#f59e0b');
                html += '<div style="margin-top:8px;padding:6px 8px;background:' + bg + ';color:' + fg + ';border:1px solid ' + bd + ';border-radius:6px;font-size:12px;font-weight:700;display:inline-block;">'
                    + esc(sc.label) + '</div>';
            }
            html += '</div>';
        } else if (d.dispatch) {
            html += '<div style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:13px;color:#6b7280;">⏱ ETA not yet calculated for this stop.</div>';
            // Phase 4 (May-2026) — even without a calculated ETA, if
            // the row was delivered we still want to show the vs-slot
            // result so the user knows the order met / missed the
            // promised slot.
            if (d.slot_compare && d.slot_compare.label) {
                const sc = d.slot_compare;
                const isWithin = sc.state === 'within';
                const bg = isWithin ? '#d1fae5' : '#fee2e2';
                const fg = isWithin ? '#065f46' : '#991b1b';
                const bd = isWithin ? '#10b981' : '#ef4444';
                html += '<div style="margin-top:-8px;margin-bottom:14px;padding:6px 8px;background:' + bg + ';color:' + fg + ';border:1px solid ' + bd + ';border-radius:6px;font-size:12px;font-weight:700;display:inline-block;">'
                    + esc(sc.label) + '</div>';
            }
        } else if (d.slot_compare && d.slot_compare.label) {
            // Item never went through dispatch but is delivered (e.g.
            // self-collection): still useful to know if it was within
            // the slot. Render a stand-alone chip block.
            const sc = d.slot_compare;
            const isWithin = sc.state === 'within';
            const bg = isWithin ? '#d1fae5' : '#fee2e2';
            const fg = isWithin ? '#065f46' : '#991b1b';
            const bd = isWithin ? '#10b981' : '#ef4444';
            html += '<div style="margin-bottom:14px;padding:6px 8px;background:' + bg + ';color:' + fg + ';border:1px solid ' + bd + ';border-radius:6px;font-size:12px;font-weight:700;display:inline-block;">'
                + esc(sc.label) + '</div>';
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
