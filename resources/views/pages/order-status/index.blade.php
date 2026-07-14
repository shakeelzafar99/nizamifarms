@extends('layouts.app')

@section('title', 'Order Status Management')

@push('custom_css')
<style>
/* Order Status Management Styles */
.status-management-container {
    background: #f8fafc;
    min-height: 100vh;
}

.status-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.status-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: translateY(-1px);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid;
}

.status-badge.yellow { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.status-badge.orange { background: #fed7aa; color: #c2410c; border-color: #fb923c; }
.status-badge.blue { background: #dbeafe; color: #1d4ed8; border-color: #60a5fa; }
.status-badge.purple { background: #e9d5ff; color: #7c3aed; border-color: #a78bfa; }
.status-badge.green { background: #d1fae5; color: #065f46; border-color: #34d399; }
.status-badge.red { background: #fee2e2; color: #dc2626; border-color: #f87171; }
.status-badge.gray { background: #f3f4f6; color: #374151; border-color: #d1d5db; }

.drag-handle {
    cursor: grab;
    color: #9ca3af;
    transition: color 0.2s ease;
}

.drag-handle:hover {
    color: #6b7280;
}

.drag-handle:active {
    cursor: grabbing;
}

.sortable-ghost {
    opacity: 0.5;
}

.sortable-chosen {
    background: #f3f4f6;
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    max-width: 90vw;
    max-height: 90vh;
    overflow-y: auto;
    width: 600px;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-outline {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.btn-outline:hover {
    background: #f9fafb;
    border-color: #9ca3af;
}

.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 6px;
}

.form-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}

.form-input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

.form-select {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 8px center;
    background-repeat: no-repeat;
    background-size: 16px 12px;
    padding-right: 40px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    text-align: center;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 14px;
    color: #6b7280;
    font-weight: 500;
}

.loading {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #6b7280;
}

.spinner {
    width: 16px;
    height: 16px;
    border: 2px solid #e5e7eb;
    border-top: 2px solid #2563eb;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 14px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #34d399;
}

.alert-error {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #f87171;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.table tbody tr:hover {
    background: #f9fafb;
}

/* ---- Journey Rail (visual overview of how statuses flow) ---- */
.rail-lane { display: flex; align-items: stretch; flex-wrap: wrap; gap: 6px; }
.rail-chip {
    display: flex; flex-direction: column; align-items: center; gap: 5px;
    padding: 10px 12px 8px; border-radius: 10px; background: #fff;
    border: 1px solid #e5e7eb; border-top: 3px solid #e5e7eb; min-width: 96px;
}
.rail-chip.rail-counted { border-top-color: #34d399; }      /* green = counts in Quantities */
.rail-chip.rail-excluded { opacity: 0.6; }                   /* dimmed = not counted */
.rail-chip.rail-outdoor { border-color: #fbbf24; background: #fffbeb; }
.rail-chip-meta { font-size: 15px; line-height: 1; }
.rail-chip-meta .muted { opacity: 0.25; }
.rail-arrow { display: flex; align-items: center; color: #9ca3af; font-size: 14px; }
.rail-line {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    color: #b45309; font-weight: 700; font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.03em; padding: 0 8px; border-left: 2px dashed #f59e0b; text-align: center;
}
.rail-sub {
    display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
    margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e5e7eb;
}
.rail-lane-label {
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em;
    color: #9ca3af; font-weight: 600; margin-right: 8px;
}
.rail-legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: #6b7280; }
.rail-legend .k { display: inline-flex; align-items: center; gap: 5px; }
.rail-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }

/* ---- Edit modal: toggle switches, sections, live preview ---- */
.switch { position: relative; display: inline-block; width: 40px; height: 22px; flex: none; }
.switch input { opacity: 0; width: 0; height: 0; }
.switch .slider { position: absolute; inset: 0; background: #d1d5db; border-radius: 999px; transition: .2s; cursor: pointer; }
.switch .slider:before { content: ''; position: absolute; height: 16px; width: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.switch input:checked + .slider { background: #2563eb; }
.switch input:checked + .slider:before { transform: translateX(18px); }
.switch input:focus-visible + .slider { outline: 2px solid #2563eb; outline-offset: 2px; }
.setting-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 10px 0; }
.setting-row + .setting-row { border-top: 1px solid #f3f4f6; }
.setting-row .txt b { display: block; font-size: 14px; font-weight: 600; color: #111827; }
.setting-row .txt span { font-size: 12px; color: #6b7280; line-height: 1.45; display: block; margin-top: 2px; }
.modal-section { border-top: 1px solid #e5e7eb; padding-top: 14px; margin-top: 16px; }
.modal-section > h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #9ca3af; font-weight: 700; margin-bottom: 8px; }
.edit-preview { display: flex; align-items: center; gap: 10px; background: #f9fafb; border: 1px solid #eef0f3; border-radius: 10px; padding: 12px 14px; margin-bottom: 4px; }
.edit-preview .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; font-weight: 700; margin-right: auto; }

/* ---- Status table (compact, glanceable) ---- */
.status-table { width: 100%; border-collapse: collapse; min-width: 920px; }
.status-table th {
    text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
    color: #9ca3af; font-weight: 700; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.status-table td { padding: 7px 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; white-space: nowrap; }
.status-table tr.lane-row td {
    background: #f9fafb; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
    color: #6b7280; font-weight: 700; padding: 6px 10px; border-bottom: 1px solid #e5e7eb;
}
.status-table tr.lane-row td .sub { text-transform: none; letter-spacing: 0; font-weight: 400; color: #9ca3af; margin-left: 8px; }
.status-table tbody tr:not(.lane-row):hover td { background: #fafbfc; }
.status-table .code { font-family: ui-monospace, Consolas, monospace; font-size: 11px; color: #9ca3af; display: block; margin-top: 1px; }
.status-table .cnt { font-variant-numeric: tabular-nums; font-weight: 600; color: #374151; }
.tpill { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; border-radius: 6px; padding: 2px 8px; }
.tp-green  { background: #d1fae5; color: #065f46; }
.tp-gray   { background: #f3f4f6; color: #6b7280; }
.tp-amber  { background: #fef3c7; color: #92400e; }
.tp-blue   { background: #dbeafe; color: #1e40af; font-family: ui-monospace, Consolas, monospace; font-size: 11.5px; }
.tp-red    { background: #fee2e2; color: #b91c1c; }
.tp-orange { background: #ffedd5; color: #9a3412; }
.tp-dim    { opacity: .55; }
.status-table .row-actions { display: flex; gap: 6px; justify-content: flex-end; }
.status-table .row-actions .btn { padding: 5px 10px; font-size: 13px; }

/* ---- Tailwind-utility backfill ----
   These utility classes are PURGED from the app's compiled CSS (the Vite/Tailwind build is
   disabled site-wide), so the Status Hub's coloured chips, buttons and paddings rendered as
   flat unstyled text. custom_css loads last in <head> and wins, so define them here. Values
   are standard Tailwind, page-scoped (this stack only renders on this page). */
.py-0\.5 { padding-top: .125rem; padding-bottom: .125rem; }
.bg-gray-100 { background-color: #f3f4f6; } .bg-gray-200 { background-color: #e5e7eb; }
.bg-blue-50 { background-color: #eff6ff; } .bg-blue-100 { background-color: #dbeafe; }
.bg-red-50 { background-color: #fef2f2; } .bg-red-100 { background-color: #fee2e2; }
.bg-amber-100 { background-color: #fef3c7; } .bg-orange-100 { background-color: #ffedd5; }
.bg-purple-100 { background-color: #f3e8ff; } .bg-green-100 { background-color: #d1fae5; }
.text-gray-400 { color: #9ca3af; } .text-gray-500 { color: #6b7280; } .text-gray-600 { color: #4b5563; }
.text-blue-700 { color: #1d4ed8; } .text-blue-800 { color: #1e40af; }
.text-red-600 { color: #dc2626; } .text-red-700 { color: #b91c1c; }
.text-amber-800 { color: #92400e; } .text-orange-800 { color: #9a3412; }
.text-purple-800 { color: #6b21a8; } .text-green-800 { color: #065f46; }
.hover\:bg-blue-100:hover { background-color: #dbeafe; }
.hover\:bg-blue-200:hover { background-color: #bfdbfe; }
.hover\:bg-red-100:hover { background-color: #fee2e2; }
.hover\:bg-red-200:hover { background-color: #fecaca; }
.hover\:bg-gray-50:hover { background-color: #f9fafb; }
.hover\:bg-gray-200:hover { background-color: #e5e7eb; }
</style>
@endpush

@section('content')
<div class="status-management-container">
    <div class="container-fixed">
        <!-- Header Section -->
        <div class="flex flex-wrap items-center lg:items-end justify-between gap-6 py-6">
            <div class="flex flex-col justify-center gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="ki-filled ki-setting-2 text-white text-lg"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold leading-tight text-gray-900">Order Status Management</h1>
                        <div class="flex items-center gap-2 text-sm font-medium text-gray-600 mt-1">
                            <i class="ki-filled ki-information-2 text-purple-500"></i>
                            Manage order statuses and workflow
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <button onclick="openCreateStatusModal()" class="btn btn-primary">
                    <i class="ki-filled ki-plus"></i>
                    Add New Status
                </button>
                <button onclick="openCustomerAppDoc()" class="btn btn-outline bg-white text-gray-700 border-gray-300 hover:bg-gray-50">
                    <i class="ki-filled ki-document"></i>
                    Customer-App Doc
                </button>
                <a href="/order-status/history" class="btn btn-outline bg-white text-gray-700 border-gray-300 hover:bg-gray-50">
                    <i class="ki-filled ki-time"></i>
                    Status History
                </a>
                <button onclick="refreshData()" class="btn btn-secondary">
                    <i class="ki-filled ki-arrows-circle"></i>
                    Refresh
                </button>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alertContainer"></div>

        <!-- Journey Rail (visual overview) -->
        <div class="status-card p-6 mb-6">
            <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                <h2 class="text-lg font-semibold text-gray-900">Order Journey</h2>
                <div class="rail-legend">
                    <span class="k"><span class="rail-swatch" style="background:#34d399"></span> Counts in Quantities</span>
                    <span class="k">📊 counted</span>
                    <span class="k">🚪 out the door (prepared)</span>
                    <span class="k"><span class="rail-swatch" style="background:#fffbeb;border:1px solid #fbbf24"></span> out-the-door step</span>
                </div>
            </div>
            <div id="journeyRail">
                <div class="loading"><div class="spinner"></div> Loading journey…</div>
            </div>
        </div>

        <!-- Status Management Section -->
        <div class="status-card p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-semibold text-gray-900">Order Statuses</h2>
                <div class="text-sm text-gray-500">
                    Drag to reorder • Click to edit
                </div>
            </div>

            <div id="statusesContainer">
                <div class="loading">
                    <div class="spinner"></div>
                    Loading statuses...
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Status Modal -->
<div id="statusModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900" id="modalTitle">Create New Status</h3>
        </div>
        
        <form id="statusForm" class="p-6">
            <input type="hidden" id="statusId" name="id">

            <!-- Live preview -->
            <div class="edit-preview">
                <span class="lbl">Preview</span>
                <span id="previewBadge" class="status-badge gray">New status</span>
                <code id="previewCode" class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">code</code>
            </div>

            <!-- Basics -->
            <div class="modal-section" style="border-top:none;padding-top:14px;margin-top:8px">
                <h4>Basics</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group" style="margin-bottom:12px">
                        <label class="form-label" for="statusName">Display Name *</label>
                        <input type="text" id="statusName" name="status_name" class="form-input"
                               placeholder="e.g. Processing" oninput="updateStatusPreview()" required>
                    </div>
                    <div class="form-group" style="margin-bottom:12px">
                        <label class="form-label" for="statusCode">Status Code *</label>
                        <input type="text" id="statusCode" name="status_code" class="form-input"
                               pattern="[a-z_]+" placeholder="e.g. processing" oninput="updateStatusPreview()" required>
                        <div class="text-xs text-gray-500 mt-1">Lowercase + underscores. Set once — don't change on a live status.</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group" style="margin-bottom:12px">
                        <label class="form-label" for="colorClass">Colour</label>
                        <select id="colorClass" name="color_class" class="form-input form-select" onchange="updateStatusPreview()">
                            <option value="yellow">Yellow</option>
                            <option value="orange">Orange</option>
                            <option value="blue">Blue</option>
                            <option value="purple">Purple</option>
                            <option value="green">Green</option>
                            <option value="red">Red</option>
                            <option value="gray">Gray</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:12px">
                        <label class="form-label" for="icon">Icon</label>
                        <div class="flex gap-2">
                            <select id="icon" name="icon" class="form-input form-select" onchange="updateStatusPreview()" style="flex:1">
                                <option value="">None</option>
                                <option value="⏳">⏳ Pending</option>
                                <option value="🆕">🆕 New</option>
                                <option value="⏸️">⏸️ On Hold</option>
                                <option value="⚡">⚡ Processing</option>
                                <option value="🔄">🔄 In Progress</option>
                                <option value="📦">📦 Packing</option>
                                <option value="🚚">🚚 Out for Delivery</option>
                                <option value="✅">✅ Delivered</option>
                                <option value="✓">✓ Completed</option>
                                <option value="❌">❌ Cancelled</option>
                                <option value="↩️">↩️ Refunded</option>
                                <option value="⚠️">⚠️ Issue</option>
                                <option value="📋">📋 Review</option>
                                <option value="💳">💳 Payment</option>
                                <option value="🎯">🎯 Priority</option>
                            </select>
                            <input type="text" id="iconCustom" class="form-input" style="width:64px;text-align:center" placeholder="emoji" maxlength="5"
                                   oninput="if(this.value){document.getElementById('icon').value=this.value;} updateStatusPreview();">
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label" for="description">Description <span class="text-xs text-gray-400 font-normal">(optional)</span></label>
                    <textarea id="description" name="description" class="form-input" rows="2" placeholder="What this status means…"></textarea>
                </div>
            </div>

            <!-- Behaviour -->
            <div class="modal-section">
                <h4>Behaviour</h4>
                <div class="setting-row">
                    <div class="txt"><b>Count in the Quantities tab</b><span>Off hides this status's orders from the "to prepare" list. Ignored while "Out the door" is on — dispatched orders never show in Quantities either way.</span></div>
                    <label class="switch"><input type="checkbox" id="countsInQuantities" name="counts_in_quantities" checked><span class="slider"></span></label>
                </div>
                <div class="setting-row">
                    <div class="txt"><b>Out the door — items already prepared</b><span>Also hides the order from the Prepared view (like Delivered / Out for Delivery). Auto-marking on entry activates with the next app update.</span></div>
                    <label class="switch"><input type="checkbox" id="autoPrepares" name="auto_prepares"><span class="slider"></span></label>
                </div>
                <div class="setting-row">
                    <div class="txt"><b>Closes the order (final)</b><span>Order leaves the open boards and can no longer be edited.</span></div>
                    <label class="switch"><input type="checkbox" id="isFinal" name="is_final"><span class="slider"></span></label>
                </div>
            </div>

            <!-- Placement -->
            <div class="modal-section">
                <h4>Placement</h4>
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label" for="laneSelect">Lane</label>
                    <select id="laneSelect" name="lane" class="form-input form-select">
                        <option value="journey">Journey — a normal step in the flow</option>
                        <option value="offtrack">Off-track — cancelled / refunded</option>
                        <option value="legacy">Legacy — retired, hidden from pickers</option>
                    </select>
                </div>
            </div>

            <!-- Staff app -->
            <div class="modal-section">
                <h4>Staff app</h4>
                <div class="setting-row">
                    <div class="txt"><b>Show in the staff mobile app</b><span>Off = staff can't pick this status on their phones.</span></div>
                    <label class="switch"><input type="checkbox" id="showInMobile" name="show_in_mobile" checked><span class="slider"></span></label>
                </div>
                <details id="roleVisibilitySection" style="margin-top:4px">
                    <summary class="text-sm text-gray-600 cursor-pointer py-1">Restrict to specific roles <span class="text-xs text-gray-400">(optional — default: all)</span></summary>
                    <div id="roleCheckboxes" class="max-h-32 overflow-y-auto border border-gray-200 rounded-lg p-2 mt-2">
                        <p class="text-xs text-gray-400">Loading roles...</p>
                    </div>
                </details>
            </div>

            <!-- Customer app -->
            <div class="modal-section">
                <h4>Customer app</h4>
                <div class="setting-row">
                    <div class="txt"><b>Send this status to the customer app</b><span>Off = customers get no update for this step (they keep seeing the last one).</span></div>
                    <label class="switch"><input type="checkbox" id="sendToCustomerApp" name="send_to_customer_app"><span class="slider"></span></label>
                </div>
                <div class="form-group mt-2" style="margin-bottom:0">
                    <label class="form-label" for="customerAppAlias">Show customer a different name</label>
                    <input type="text" id="customerAppAlias" name="customer_app_alias" class="form-input"
                           list="customerAliasOptions"
                           placeholder="Leave blank to send as-is" maxlength="50">
                    <datalist id="customerAliasOptions">
                        <option value="processing">Customer sees: Preparing</option>
                        <option value="out_for_delivery">Customer sees: Out for Delivery (live map)</option>
                        <option value="dispatch">Customer sees: Out for Delivery</option>
                        <option value="delivered">Customer sees: Delivered</option>
                        <option value="new">Customer sees: Accepted</option>
                        <option value="pending">Customer sees: In Progress</option>
                        <option value="on_hold">Customer sees: In Progress</option>
                        <option value="cancelled">Customer sees: Cancelled</option>
                        <option value="refunded">Customer sees: Refunded</option>
                    </datalist>
                    <p class="text-xs text-gray-500 mt-1">Pick a value the customer app already understands (its approved list) — anything else shows as a vague "In Progress".</p>
                </div>
            </div>
        </form>
        
        <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
            <button type="button" onclick="closeStatusModal()" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="saveStatus()" class="btn btn-primary" id="saveStatusBtn">
                <span id="saveStatusText">Create Status</span>
                <div id="saveStatusSpinner" class="spinner" style="display: none;"></div>
            </button>
        </div>
    </div>
</div>

<!-- Bulk Status Change Modal -->
<div id="bulkStatusModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Bulk Status Change</h3>
        </div>
        
        <div class="p-6">
            <div class="form-group">
                <label class="form-label">Select Orders</label>
                <div id="bulkOrdersContainer" class="max-h-60 overflow-y-auto border border-gray-200 rounded-lg p-4">
                    <!-- Orders will be loaded here -->
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="bulkStatusSelect">New Status</label>
                <select id="bulkStatusSelect" class="form-input form-select">
                    <option value="">Select status...</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="bulkNotes">Notes (Optional)</label>
                <textarea id="bulkNotes" class="form-input form-textarea" 
                          placeholder="Reason for status change..."></textarea>
            </div>
        </div>
        
        <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
            <button type="button" onclick="closeBulkStatusModal()" class="btn btn-secondary">Cancel</button>
            <button type="button" onclick="executeBulkStatusChange()" class="btn btn-primary">
                Update Selected Orders
            </button>
        </div>
    </div>
</div>

<!-- Customer-App Handoff Doc Modal -->
<div id="customerDocModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="width: 760px; max-width: 94vw;">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Customer-App Status Handoff</h3>
                <p class="text-xs text-gray-500 mt-1">Send this to the customer-app developer so their mapping matches exactly what we send.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="copyCustomerDoc()" class="btn btn-outline bg-white text-gray-700 border-gray-300 hover:bg-gray-50"><i class="ki-filled ki-copy"></i> <span id="copyDocLabel">Copy</span></button>
                <button type="button" onclick="downloadCustomerDoc()" class="btn btn-outline bg-white text-gray-700 border-gray-300 hover:bg-gray-50"><i class="ki-filled ki-file-down"></i> Download</button>
            </div>
        </div>
        <div class="p-6">
            <textarea id="customerDocText" class="form-input" readonly
                      style="width:100%; height: 52vh; font-family: ui-monospace, Consolas, monospace; font-size: 12px; line-height: 1.5; white-space: pre; overflow: auto;"></textarea>
        </div>
        <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
            <button type="button" onclick="closeCustomerDoc()" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>
@endsection

@push('page_js')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// Global variables
let statuses = [];
let statistics = [];
let sortable = null;

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    console.log('Order Status Management page loaded');
    setTimeout(() => {
        loadStatistics();
        loadStatuses();
    }, 100);
});

// Also try to load immediately if DOM is already ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializePage);
} else {
    initializePage();
}

function initializePage() {
    console.log('Initializing Order Status Management');
    loadStatistics();
    loadStatuses();
    loadRoles();  // Load available roles for visibility settings
}

// Global roles array
let availableRoles = [];

// Load available roles for visibility settings
async function loadRoles() {
    try {
        const response = await fetch('/order-status/api/roles', {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        
        if (data.success) {
            availableRoles = data.roles;
            populateRoleCheckboxes();
        }
    } catch (error) {
        console.error('Error loading roles:', error);
    }
}

// Populate role checkboxes in the modal
function populateRoleCheckboxes(selectedRoles = []) {
    const container = document.getElementById('roleCheckboxes');
    if (!container) return;
    
    if (availableRoles.length === 0) {
        container.innerHTML = '<p class="text-xs text-gray-400">No roles available</p>';
        return;
    }
    
    container.innerHTML = availableRoles.map(role => `
        <label class="flex items-center gap-2 py-1 hover:bg-gray-50 rounded px-1 cursor-pointer">
            <input type="checkbox" 
                   class="role-checkbox rounded" 
                   value="${role.id}" 
                   data-role-name="${role.urole_name}"
                   ${selectedRoles.includes(role.id) ? 'checked' : ''}>
            <span class="text-sm text-gray-700">${role.urole_name}</span>
        </label>
    `).join('');
}

// Load statistics — the stats CARDS were removed, but the per-status order counts now feed
// the "Orders" column of the status table, so we still fetch and then re-render the table.
async function loadStatistics() {
    try {
        const response = await fetch('/order-status/api/statistics', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        });
        const data = await response.json();
        
        if (data.success) {
            statistics = data.data;
            // Counts arrived — refresh the table's Orders column if statuses already rendered.
            if (Array.isArray(statuses) && statuses.length) renderStatuses();
        }
        // No alert on failure — the table just shows blank counts.
    } catch (error) {
        console.error('Error loading statistics:', error);
    }
}

// Render statistics (legacy — the stats cards were removed; kept as a guarded no-op)
function renderStatistics() {
    const container = document.getElementById('statisticsContainer');
    if (!container) return;

    if (statistics.length === 0) {
        container.innerHTML = '<div class="stat-card"><div class="text-gray-500">No data available</div></div>';
        return;
    }
    
    container.innerHTML = statistics.map(stat => `
        <div class="stat-card">
            <div class="stat-value">${stat.order_count || 0}</div>
            <div class="stat-label">
                <span class="status-badge ${stat.color_class}">
                    ${stat.icon} ${stat.status_name}
                </span>
            </div>
            <div class="text-xs text-gray-500 mt-2">
                Total: $${parseFloat(stat.total_value || 0).toFixed(2)}
            </div>
        </div>
    `).join('');
}

// Load statuses
async function loadStatuses() {
    try {
        const response = await fetch('/order-status/api/statuses?all=1', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        });
        const data = await response.json();

        if (data.success) {
            statuses = data.data;
            renderStatuses();
        } else {
            showAlert('Failed to load statuses', 'error');
        }
    } catch (error) {
        console.error('Error loading statuses:', error);
        showAlert('Error loading statuses', 'error');
    }
}

// Render the visual journey rail from the loaded statuses
function renderRail() {
    const el = document.getElementById('journeyRail');
    if (!el) return;
    if (!statuses || statuses.length === 0) {
        el.innerHTML = '<div class="text-gray-500">No statuses to show</div>';
        return;
    }

    const laneOf   = (s) => s.lane || 'journey';
    const counts   = (s) => !(s.counts_in_quantities === false || s.counts_in_quantities === 0);
    const outDoor  = (s) => !!s.auto_prepares;
    const bySeq    = (a, b) => (a.sequence_order || 0) - (b.sequence_order || 0);

    const journey  = statuses.filter(s => laneOf(s) === 'journey' && s.is_active !== false).sort(bySeq);
    const offtrack = statuses.filter(s => laneOf(s) === 'offtrack').sort(bySeq);
    const legacy   = statuses.filter(s => laneOf(s) === 'legacy').sort(bySeq);

    const chip = (s) => {
        // Effective quantities behaviour: out-the-door statuses never show in Quantities,
        // regardless of the counts flag.
        const effCounts = counts(s) && !outDoor(s);
        // Out-the-door chips keep their amber look (not dimmed); others show green when counted.
        const cls = `rail-chip ${outDoor(s) ? 'rail-outdoor' : (effCounts ? 'rail-counted' : 'rail-excluded')}`;
        const meta = `${effCounts ? '📊' : '<span class="muted">📊</span>'} ${outDoor(s) ? '🚪' : '<span class="muted">🚪</span>'}`;
        return `<div class="${cls}" title="${effCounts ? 'Counts in Quantities' : (outDoor(s) ? 'Never in Quantities — out the door' : 'Excluded from Quantities')}${outDoor(s) ? ' • Out the door' : ''}">
            <span class="status-badge ${normalizeColorName(s.color_class)}">${s.icon || ''} ${s.status_name}</span>
            <div class="rail-chip-meta">${meta}</div>
        </div>`;
    };

    // Journey lane, with a dashed orange marker before the first "out the door" step
    const firstOut = journey.findIndex(outDoor);
    let html = '<div class="rail-lane">';
    journey.forEach((s, i) => {
        if (i === firstOut && firstOut > 0) {
            html += `<div class="rail-line">🚪<span>out the<br>door</span></div>`;
        }
        html += chip(s);
        if (i < journey.length - 1) html += '<span class="rail-arrow">→</span>';
    });
    html += '</div>';

    if (offtrack.length) {
        html += `<div class="rail-sub"><span class="rail-lane-label">Off-track</span>${offtrack.map(chip).join('')}</div>`;
    }
    if (legacy.length) {
        html += `<div class="rail-sub"><span class="rail-lane-label">Legacy · hidden from pickers</span>${legacy.map(chip).join('')}</div>`;
    }

    el.innerHTML = html;
}

// ---- Compact status table ----
function orderCountFor(statusCode) {
    if (!Array.isArray(statistics)) return null;
    const row = statistics.find(x => x.status_code === statusCode);
    return row ? (row.order_count ?? null) : null;
}

// One table row: every property gets its own column so the list reads at a glance.
function statusRowHtml(status, draggable) {
    const counts   = !(status.counts_in_quantities === false || status.counts_in_quantities === 0);
    const outDoor  = !!status.auto_prepares;
    const isFinal  = !!status.is_final;
    const inMobile = status.show_in_mobile !== false;
    const hasRoles = status.visible_to_roles && status.visible_to_roles.length > 0;
    const sends    = !(status.send_to_customer_app === false || status.send_to_customer_app === 0);
    const alias    = (status.customer_app_alias || '').trim();
    const n        = orderCountFor(status.status_code);

    // Quantities column shows the EFFECTIVE behaviour: an "out the door" status never appears
    // in the Quantities tab (its items are auto-prepared and both views drop it), even though
    // the underlying excluded-statuses setting may not list it.
    let qtyCell;
    if (outDoor) qtyCell = '<span class="tpill tp-gray" title="Never shows in Quantities — orders here are out the door (items auto-prepared)">Excluded · 🚪</span>';
    else qtyCell = counts ? '<span class="tpill tp-green">Counted</span>' : '<span class="tpill tp-gray">Excluded</span>';
    const doorCell  = outDoor ? '<span class="tpill tp-amber">🚪 Yes</span>' : '<span class="tpill tp-gray tp-dim">—</span>';
    const stateCell = isFinal ? '<span class="tpill tp-gray">Closed</span>' : '<span class="tpill tp-green">Open</span>';
    let staffCell   = inMobile ? '<span class="tpill tp-green">Visible</span>' : '<span class="tpill tp-orange">Hidden</span>';
    if (inMobile && hasRoles) staffCell = `<span class="tpill tp-orange">🔒 ${status.visible_to_roles.length} roles</span>`;
    let custCell;
    if (!sends) custCell = '<span class="tpill tp-red">Not sent</span>';
    else if (alias) custCell = `<span class="tpill tp-blue">→ ${alias}</span>`;
    else custCell = '<span class="tpill tp-gray tp-dim">as-is</span>';

    const handle = draggable
        ? '<div class="drag-handle"><i class="ki-filled ki-menu text-lg"></i></div>'
        : '';
    const inactive = status.is_active === false ? ' <span class="tpill tp-red">Inactive</span>' : '';

    return `
        <tr data-id="${status.id}">
            <td style="width:28px">${handle}</td>
            <td><span class="status-badge ${normalizeColorName(status.color_class)}">${status.icon || ''} ${status.status_name}</span>${inactive}<span class="code">${status.status_code}</span></td>
            <td class="cnt">${n === null ? '' : Number(n).toLocaleString()}</td>
            <td>${qtyCell}</td>
            <td>${doorCell}</td>
            <td>${stateCell}</td>
            <td>${staffCell}</td>
            <td>${custCell}</td>
            <td>
                <div class="row-actions">
                    <button onclick="editStatus(${status.id})" class="btn bg-blue-50 text-blue-700 hover:bg-blue-100"><i class="ki-filled ki-pencil"></i> Edit</button>
                    <button onclick="deleteStatus(${status.id})" class="btn bg-red-50 text-red-600 hover:bg-red-100"><i class="ki-filled ki-trash"></i></button>
                </div>
            </td>
        </tr>`;
}

function laneHeaderRow(title, subtitle) {
    return `<tbody><tr class="lane-row"><td colspan="9">${title}<span class="sub">${subtitle}</span></td></tr></tbody>`;
}

// Render statuses — one compact table, grouped Journey (sortable) / Off-track / Legacy
function renderStatuses() {
    const container = document.getElementById('statusesContainer');

    renderRail();

    if (statuses.length === 0) {
        container.innerHTML = '<div class="text-gray-500 text-center py-8">No statuses found</div>';
        return;
    }

    const laneOf = (s) => s.lane || 'journey';
    const bySeq  = (a, b) => (a.sequence_order || 0) - (b.sequence_order || 0);
    const journey  = statuses.filter(s => laneOf(s) === 'journey').sort(bySeq);
    const offtrack = statuses.filter(s => laneOf(s) === 'offtrack').sort(bySeq);
    const legacy   = statuses.filter(s => laneOf(s) === 'legacy').sort(bySeq);

    container.innerHTML = `
        <div style="overflow-x:auto">
        <table class="status-table">
            <thead>
                <tr>
                    <th></th><th>Status</th><th>Orders</th><th>Quantities</th><th>Out the door</th>
                    <th>Order state</th><th>Staff app</th><th>Customer app</th><th></th>
                </tr>
            </thead>
            ${laneHeaderRow('Journey', 'drag to reorder the flow')}
            <tbody id="journeyBody">${journey.map(s => statusRowHtml(s, true)).join('')}</tbody>
            ${offtrack.length ? laneHeaderRow('Off-track', 'never counted · closes the order') + `<tbody>${offtrack.map(s => statusRowHtml(s, false)).join('')}</tbody>` : ''}
            ${legacy.length ? laneHeaderRow('Legacy', 'retired · hidden from every picker · kept for history') + `<tbody>${legacy.map(s => statusRowHtml(s, false)).join('')}</tbody>` : ''}
        </table>
        </div>`;

    initializeSortable();
}

// Initialize sortable — only the Journey rows are reorderable (sets sequence).
function initializeSortable() {
    const journeyBody = document.getElementById('journeyBody');
    if (!journeyBody) return;

    sortable = new Sortable(journeyBody, {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function() {
            const statusOrder = Array.from(journeyBody.children)
                .map(item => parseInt(item.getAttribute('data-id')))
                .filter(n => !isNaN(n));
            updateStatusOrder(statusOrder);
        }
    });
}

// Update status order
async function updateStatusOrder(statusOrder) {
    try {
        const response = await fetch('/order-status/api/reorder', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ status_order: statusOrder })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('Status order updated successfully', 'success');
            loadStatuses(); // Reload to get updated sequence numbers
        } else {
            showAlert(data.message || 'Failed to update status order', 'error');
            loadStatuses(); // Reload to reset order
        }
    } catch (error) {
        console.error('Error updating status order:', error);
        showAlert('Error updating status order', 'error');
        loadStatuses(); // Reload to reset order
    }
}

// Normalize a stored color_class ('bg-green-100' or 'green') to the plain dropdown value ('green')
function normalizeColorName(colorClass) {
    if (!colorClass) return 'gray';
    const m = String(colorClass).match(/^bg-([a-z]+)-\d+$/);
    return m ? m[1] : colorClass;
}

// Live preview badge in the edit modal
function updateStatusPreview() {
    const name = (document.getElementById('statusName').value || 'Status name').trim();
    const code = (document.getElementById('statusCode').value || 'code').trim();
    const color = normalizeColorName(document.getElementById('colorClass').value || 'gray');
    const icon = (document.getElementById('iconCustom').value || document.getElementById('icon').value || '').trim();
    const badge = document.getElementById('previewBadge');
    if (badge) {
        badge.className = 'status-badge ' + color;
        badge.textContent = (icon ? icon + ' ' : '') + name;
    }
    const codeEl = document.getElementById('previewCode');
    if (codeEl) codeEl.textContent = code;
}

// Modal functions
function openCreateStatusModal() {
    document.getElementById('modalTitle').textContent = 'Create New Status';
    document.getElementById('saveStatusText').textContent = 'Create Status';
    document.getElementById('statusForm').reset();
    document.getElementById('statusId').value = '';
    document.getElementById('iconCustom').value = '';  // Reset custom icon field
    document.getElementById('showInMobile').checked = true;  // Default to shown in mobile
    // Hub defaults for a new status: counts in quantities, not out-the-door, journey lane,
    // customer updates OFF until deliberately turned on.
    document.getElementById('countsInQuantities').checked = true;
    document.getElementById('autoPrepares').checked = false;
    document.getElementById('laneSelect').value = 'journey';
    document.getElementById('sendToCustomerApp').checked = false;
    document.getElementById('customerAppAlias').value = '';
    populateRoleCheckboxes([]);  // Clear role selections
    updateStatusPreview();
    document.getElementById('statusModal').style.display = 'flex';
}

function editStatus(statusId) {
    const status = statuses.find(s => s.id === statusId);
    if (!status) return;
    
    document.getElementById('modalTitle').textContent = 'Edit Status';
    document.getElementById('saveStatusText').textContent = 'Update Status';
    document.getElementById('statusId').value = status.id;
    document.getElementById('statusCode').value = status.status_code;
    document.getElementById('statusName').value = status.status_name;
    document.getElementById('description').value = status.description || '';
    document.getElementById('colorClass').value = normalizeColorName(status.color_class);
    document.getElementById('isFinal').checked = status.is_final;
    
    // Mobile visibility settings
    document.getElementById('showInMobile').checked = status.show_in_mobile !== false;  // Default true if not set

    // Quantities & workflow settings
    document.getElementById('countsInQuantities').checked = (status.counts_in_quantities !== false && status.counts_in_quantities !== 0);
    document.getElementById('autoPrepares').checked = !!status.auto_prepares;
    document.getElementById('laneSelect').value = status.lane || 'journey';
    document.getElementById('sendToCustomerApp').checked = !!status.send_to_customer_app;
    document.getElementById('customerAppAlias').value = status.customer_app_alias || '';

    // Role visibility - parse if string, otherwise use as-is
    let selectedRoles = status.visible_to_roles || [];
    if (typeof selectedRoles === 'string') {
        try {
            selectedRoles = JSON.parse(selectedRoles);
        } catch (e) {
            selectedRoles = [];
        }
    }
    populateRoleCheckboxes(selectedRoles);
    
    // Handle icon - check if it's in the dropdown options
    const iconSelect = document.getElementById('icon');
    const iconCustom = document.getElementById('iconCustom');
    const statusIcon = status.icon || '';
    
    // Try to find in dropdown
    let foundInDropdown = false;
    for (let option of iconSelect.options) {
        if (option.value === statusIcon) {
            iconSelect.value = statusIcon;
            iconCustom.value = '';
            foundInDropdown = true;
            break;
        }
    }
    
    // If not found in dropdown, put in custom field
    if (!foundInDropdown && statusIcon) {
        iconSelect.value = '';
        iconCustom.value = statusIcon;
    } else if (!statusIcon) {
        iconSelect.value = '';
        iconCustom.value = '';
    }

    updateStatusPreview();
    document.getElementById('statusModal').style.display = 'flex';
}

function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
}

// ---- Customer-App handoff document ----
// The customer app maps our value -> its stage. This mirrors CUSTOMER_APP_INTEGRATION.md §4.1.
const CUSTOMER_STAGE_MAP = {
    accepted: 'Accepted', new: 'Accepted', priority: 'Preparing', processing: 'Preparing',
    pending: 'In Progress', on_hold: 'In Progress', 'on-hold': 'In Progress',
    dispatch: 'Out for Delivery', out_for_delivery: 'Out for Delivery',
    delivered: 'Delivered', completed: 'Delivered', cancelled: 'Cancelled', refunded: 'Refunded'
};
function customerStageFor(value) {
    return CUSTOMER_STAGE_MAP[value] || 'In Progress (catch-all — please add a mapping for this value)';
}

function generateCustomerAppDoc() {
    const today = new Date().toISOString().slice(0, 10);
    const sends = (s) => !(s.send_to_customer_app === false || s.send_to_customer_app === 0);
    const active = statuses.filter(s => s.is_active !== false);

    const L = [];
    L.push('# Nizami Farms → Customer App — Order Status Contract');
    L.push('');
    L.push('Generated: ' + today);
    L.push('');
    L.push('This lists every order status and exactly what your app will receive for it.');
    L.push('');
    L.push('Rules that always apply:');
    L.push('- Only orders whose number starts with "SH-" emit status webhooks.');
    L.push('- The FIRST event for an order is always sent as `accepted` (order accepted), whatever the status.');
    L.push('- "Not sent" = we fire no webhook for that step; the customer keeps seeing the previous status.');
    L.push('- Map each value we send to the stage shown. Keep an `in_progress` catch-all for anything unrecognised.');
    L.push('');
    L.push('| NF status | We send | Your stage | Notes |');
    L.push('|-----------|---------|------------|-------|');
    active.forEach(s => {
        let sendVal, stage, note = '';
        if (!sends(s)) {
            sendVal = '— (not sent)';
            stage = '(unchanged)';
            note = 'Internal step — hidden from customers';
        } else {
            const alias = (s.customer_app_alias && s.customer_app_alias.trim()) ? s.customer_app_alias.trim() : '';
            const v = alias || s.status_code;
            sendVal = '`' + v + '`';
            stage = customerStageFor(v);
            if (alias) note = 'alias of "' + s.status_code + '"';
        }
        if (s.lane === 'legacy') note = (note ? note + '; ' : '') + 'legacy — only appears on old orders';
        L.push('| ' + s.status_name + ' (`' + s.status_code + '`) | ' + sendVal + ' | ' + stage + ' | ' + note + ' |');
    });
    L.push('');
    L.push('## Complete set of values you may receive');
    const vals = active.filter(sends).map(s => (s.customer_app_alias && s.customer_app_alias.trim()) ? s.customer_app_alias.trim() : s.status_code);
    vals.push('accepted');
    Array.from(new Set(vals)).sort().forEach(v => L.push('- `' + v + '` → ' + customerStageFor(v)));
    L.push('');
    L.push('_Anything showing "catch-all" above needs a mapping decision on your side. — Nizami Farms ops_');
    return L.join('\n');
}

function openCustomerAppDoc() {
    document.getElementById('customerDocText').value = generateCustomerAppDoc();
    document.getElementById('copyDocLabel').textContent = 'Copy';
    document.getElementById('customerDocModal').style.display = 'flex';
}
function closeCustomerDoc() { document.getElementById('customerDocModal').style.display = 'none'; }
function copyCustomerDoc() {
    const ta = document.getElementById('customerDocText');
    ta.focus(); ta.select();
    if (navigator.clipboard) { navigator.clipboard.writeText(ta.value).catch(() => {}); }
    else { try { document.execCommand('copy'); } catch (e) {} }
    document.getElementById('copyDocLabel').textContent = 'Copied!';
    setTimeout(() => { document.getElementById('copyDocLabel').textContent = 'Copy'; }, 2000);
}
function downloadCustomerDoc() {
    const blob = new Blob([generateCustomerAppDoc()], { type: 'text/markdown' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'nf-customer-app-status-contract.md';
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
}

// Save status
async function saveStatus() {
    const statusId = document.getElementById('statusId').value;
    
    // Collect form data as JSON (better for emoji handling)
    const statusCode = document.getElementById('statusCode').value.trim();
    const statusName = document.getElementById('statusName').value.trim();
    const description = document.getElementById('description').value.trim();
    const colorClass = document.getElementById('colorClass').value;
    const iconCustom = document.getElementById('iconCustom').value.trim();
    const iconSelect = document.getElementById('icon').value;
    const icon = iconCustom || iconSelect; // Custom icon takes priority
    const isFinal = document.getElementById('isFinal').checked;
    
    // Mobile visibility settings
    const showInMobile = document.getElementById('showInMobile').checked;
    const selectedRoles = Array.from(document.querySelectorAll('.role-checkbox:checked'))
        .map(cb => parseInt(cb.value));

    // Quantities & workflow + customer-app settings
    const countsInQuantities = document.getElementById('countsInQuantities').checked;
    const autoPrepares = document.getElementById('autoPrepares').checked;
    const lane = document.getElementById('laneSelect').value;
    const sendToCustomerApp = document.getElementById('sendToCustomerApp').checked;
    const customerAppAlias = document.getElementById('customerAppAlias').value.trim();
    
    // Validate
    if (!statusCode) {
        showAlert('Status code is required', 'error');
        return;
    }
    if (!/^[a-z_]+$/.test(statusCode)) {
        showAlert('Status code must be lowercase letters and underscores only', 'error');
        return;
    }
    if (!statusName) {
        showAlert('Display name is required', 'error');
        return;
    }
    
    // Show loading
    document.getElementById('saveStatusText').style.display = 'none';
    document.getElementById('saveStatusSpinner').style.display = 'block';
    document.getElementById('saveStatusBtn').disabled = true;
    
    try {
        const url = statusId ? `/order-status/api/statuses/${statusId}` : '/order-status/api/statuses';
        const method = statusId ? 'PUT' : 'POST';
        
        const payload = {
            status_code: statusCode,
            status_name: statusName,
            description: description || null,
            color_class: colorClass,
            icon: icon || null,
            is_final: isFinal,
            show_in_mobile: showInMobile,
            visible_to_roles: selectedRoles.length > 0 ? selectedRoles : null,  // null = visible to all
            counts_in_quantities: countsInQuantities,
            auto_prepares: autoPrepares,
            lane: lane,
            send_to_customer_app: sendToCustomerApp,
            customer_app_alias: customerAppAlias || null
        };
        
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(payload)
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert(data.message || 'Status saved successfully', 'success');
            closeStatusModal();
            loadStatuses();
            loadStatistics();
        } else {
            // Show detailed error message
            let errorMsg = data.message || 'Failed to save status';
            if (data.errors) {
                errorMsg = Object.values(data.errors).flat().join(', ');
            }
            showAlert(errorMsg, 'error');
        }
    } catch (error) {
        console.error('Error saving status:', error);
        showAlert('Error saving status: ' + error.message, 'error');
    } finally {
        // Hide loading
        document.getElementById('saveStatusText').style.display = 'block';
        document.getElementById('saveStatusSpinner').style.display = 'none';
        document.getElementById('saveStatusBtn').disabled = false;
    }
}

// Delete status
async function deleteStatus(statusId) {
    if (!confirm('Are you sure you want to delete this status? This action cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch(`/order-status/api/statuses/${statusId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert(data.message || 'Status deleted successfully', 'success');
            loadStatuses();
            loadStatistics();
        } else {
            showAlert(data.message || 'Failed to delete status', 'error');
        }
    } catch (error) {
        console.error('Error deleting status:', error);
        showAlert('Error deleting status', 'error');
    }
}

// Utility functions
function showAlert(message, type = 'success') {
    const container = document.getElementById('alertContainer');
    const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
    
    container.innerHTML = `
        <div class="alert ${alertClass}">
            ${message}
        </div>
    `;
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

function refreshData() {
    loadStatistics();
    loadStatuses();
    showAlert('Data refreshed', 'success');
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
    }
});
</script>
@endpush
