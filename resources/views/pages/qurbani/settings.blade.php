@extends('layouts.app')

@section('title', 'Qurbani Settings')

@push('custom_css')
<style>
.qurbani-settings-container { background: #f8fafc; min-height: 100vh; }
.settings-header { background: linear-gradient(135deg, #92400e, #b45309); color: white; padding: 24px 32px; border-radius: 12px; margin-bottom: 24px; }
.settings-header h1 { font-size: 24px; font-weight: 700; margin: 0; }
.settings-header p { font-size: 14px; opacity: 0.85; margin: 6px 0 0; }
.field-section { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; }
.field-header { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
.field-title { font-size: 16px; font-weight: 700; color: #111; }
.field-subtitle { font-size: 12px; color: #6b7280; }
.field-body { padding: 16px 20px; }
.option-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #f9fafb; border-radius: 8px; margin-bottom: 8px; transition: background 0.15s; }
.option-row:hover { background: #f3f4f6; }
.option-value { flex: 1; font-size: 14px; font-weight: 500; color: #374151; }
.option-order { font-size: 12px; color: #9ca3af; min-width: 24px; text-align: center; }
.option-actions { display: flex; gap: 6px; }
.btn-sm { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
.btn-edit { background: #eff6ff; color: #2563eb; }
.btn-edit:hover { background: #dbeafe; }
.btn-delete { background: #fef2f2; color: #dc2626; }
.btn-delete:hover { background: #fee2e2; }
.btn-add { background: #b45309; color: white; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
.btn-add:hover { background: #92400e; }
.add-form { display: flex; gap: 8px; margin-top: 12px; }
.add-input { flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
.add-input:focus { outline: none; border-color: #b45309; box-shadow: 0 0 0 3px rgba(180,83,9,0.1); }
.empty-state { text-align: center; padding: 24px; color: #9ca3af; font-size: 14px; }
.inactive-row { opacity: 0.5; }
.btn-restore { background: #d1fae5; color: #065f46; }
.btn-restore:hover { background: #a7f3d0; }
.toast { position: fixed; top: 20px; right: 20px; z-index: 10000; padding: 12px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); animation: toastIn 0.3s ease; }
.toast-success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.toast-error { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
@keyframes toastIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
</style>
@endpush

@section('content')
<div class="qurbani-settings-container" style="padding: 24px;">
    <div class="settings-header">
        <h1>🐄 Qurbani Settings</h1>
        <p>Manage dropdown values for qurbani order fields. Changes are reflected immediately on the mobile app.</p>
    </div>

    @php
        $riderDeliveredEnabled = \App\Models\FIN\ConfigModel::get('qurbani_rider_delivered_enabled', '0') === '1';
        $qurbaniShippingPrice = \App\Models\FIN\ConfigModel::get('qurbani_shipping_price', '1000');
        $deleteEnabled = \App\Models\FIN\ConfigModel::get('qurbani_delete_enabled', '0') === '1';
        $defaultPaymentMethod = \App\Models\FIN\ConfigModel::get('qurbani_default_payment_method', 'cash');
        if (!in_array($defaultPaymentMethod, ['cash','online'], true)) { $defaultPaymentMethod = 'cash'; }
    @endphp

    {{-- Delivery Fee Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">💰 Delivery Fee</div>
                <div class="field-subtitle">Default delivery fee for all qurbani orders (web and mobile)</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <label style="font-weight: 600; font-size: 14px; color: #374151; white-space: nowrap;">Rs.</label>
                <input type="number" id="qurbaniShippingPrice" value="{{ $qurbaniShippingPrice }}" min="0" step="1" style="width: 140px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; font-weight: 600;">
                <button onclick="saveShippingPrice()" class="btn-add">Save</button>
                <span id="shippingPriceSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓ Saved</span>
            </div>
        </div>
    </div>

    {{-- Default Payment Method Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">💳 Default Payment Method</div>
                <div class="field-subtitle">Pre-selected method when a new qurbani order is created (web and mobile). Users can still change it per order.</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <div style="display:flex; gap:8px;">
                    <button type="button" id="dpmCashBtn" onclick="setDefaultPaymentMethod('cash')"
                            style="padding:8px 18px; border-radius:8px; font-weight:700; font-size:13px; border:2px solid {{ $defaultPaymentMethod === 'cash' ? '#10b981' : '#d1d5db' }}; background:{{ $defaultPaymentMethod === 'cash' ? '#10b981' : '#f3f4f6' }}; color:{{ $defaultPaymentMethod === 'cash' ? '#fff' : '#374151' }}; cursor:pointer;">
                        Cash
                    </button>
                    <button type="button" id="dpmOnlineBtn" onclick="setDefaultPaymentMethod('online')"
                            style="padding:8px 18px; border-radius:8px; font-weight:700; font-size:13px; border:2px solid {{ $defaultPaymentMethod === 'online' ? '#3b82f6' : '#d1d5db' }}; background:{{ $defaultPaymentMethod === 'online' ? '#3b82f6' : '#f3f4f6' }}; color:{{ $defaultPaymentMethod === 'online' ? '#fff' : '#374151' }}; cursor:pointer;">
                        Online
                    </button>
                </div>
                <span id="dpmSaved" style="display:none; font-size: 12px; color: #059669; font-weight: 600;">✓ Saved</span>
            </div>
        </div>
    </div>

    {{-- Rider Controls Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">🏍️ Rider Controls</div>
                <div class="field-subtitle">Control what riders can do with qurbani orders</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <div>
                    <div style="font-weight: 600; font-size: 14px; color: #374151;">Allow Riders to Mark Delivered</div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">When disabled, riders can only collect payments on qurbani orders. Enable this during the event to allow delivery marking.</div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span id="riderDeliveredBadge" style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; {{ $riderDeliveredEnabled ? 'background:#d1fae5; color:#065f46;' : 'background:#f3f4f6; color:#6b7280;' }}">
                        {{ $riderDeliveredEnabled ? 'ENABLED' : 'DISABLED' }}
                    </span>
                    <button id="riderDeliveredBtn" onclick="toggleRiderDelivered()" style="padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; {{ $riderDeliveredEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#d1fae5; color:#065f46;' }}">
                        {{ $riderDeliveredEnabled ? 'Disable' : 'Enable' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Order Deletion Section --}}
    <div class="field-section" style="margin-bottom: 20px;">
        <div class="field-header">
            <div>
                <div class="field-title">🗑️ Order Deletion</div>
                <div class="field-subtitle">Allow Taimur role to permanently delete qurbani orders (including payments and ledger entries)</div>
            </div>
        </div>
        <div class="field-body">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f9fafb; border-radius: 8px;">
                <div>
                    <div style="font-weight: 600; font-size: 14px; color: #374151;">Allow Order Deletion</div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">When enabled, a delete button will appear in qurbani orders for the Taimur role only. This permanently removes the order and all associated data.</div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span id="deleteEnabledBadge" style="padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; {{ $deleteEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#f3f4f6; color:#6b7280;' }}">
                        {{ $deleteEnabled ? 'ENABLED' : 'DISABLED' }}
                    </span>
                    <button id="deleteEnabledBtn" onclick="toggleDeleteEnabled()" style="padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; {{ $deleteEnabled ? 'background:#fee2e2; color:#991b1b;' : 'background:#d1fae5; color:#065f46;' }}">
                        {{ $deleteEnabled ? 'Disable' : 'Enable' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="fieldsContainer">
        <div style="text-align: center; padding: 40px;"><span style="font-size: 24px;">⏳</span> Loading...</div>
    </div>
</div>
@endsection

@push('custom_js')
<script>
function toggleDeleteEnabled() {
    var btn = document.getElementById('deleteEnabledBtn');
    btn.disabled = true; btn.textContent = 'Updating...';
    fetch('/qurbani/api/toggle-delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            var badge = document.getElementById('deleteEnabledBadge');
            if (data.enabled) {
                badge.textContent = 'ENABLED'; badge.style.background = '#fee2e2'; badge.style.color = '#991b1b';
                btn.textContent = 'Disable'; btn.style.background = '#fee2e2'; btn.style.color = '#991b1b';
            } else {
                badge.textContent = 'DISABLED'; badge.style.background = '#f3f4f6'; badge.style.color = '#6b7280';
                btn.textContent = 'Enable'; btn.style.background = '#d1fae5'; btn.style.color = '#065f46';
            }
            showToast(data.message, 'success');
        } else {
            showToast('Failed to update', 'error');
        }
        btn.disabled = false;
    })
    .catch(() => { showToast('Error updating setting', 'error'); btn.disabled = false; btn.textContent = 'Retry'; });
}

function toggleRiderDelivered() {
    var btn = document.getElementById('riderDeliveredBtn');
    btn.disabled = true; btn.textContent = 'Updating...';
    fetch('/qurbani/api/toggle-rider-delivered', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            var badge = document.getElementById('riderDeliveredBadge');
            if (data.enabled) {
                badge.textContent = 'ENABLED'; badge.style.background = '#d1fae5'; badge.style.color = '#065f46';
                btn.textContent = 'Disable'; btn.style.background = '#fee2e2'; btn.style.color = '#991b1b';
            } else {
                badge.textContent = 'DISABLED'; badge.style.background = '#f3f4f6'; badge.style.color = '#6b7280';
                btn.textContent = 'Enable'; btn.style.background = '#d1fae5'; btn.style.color = '#065f46';
            }
            showToast(data.message, 'success');
        } else {
            showToast('Failed to update', 'error');
        }
        btn.disabled = false;
    })
    .catch(() => { showToast('Error updating setting', 'error'); btn.disabled = false; btn.textContent = 'Retry'; });
}

// NOTE: Order here also drives the render sequence on the settings page.
// qurbani_type / qurbani_paya are simple flat dropdowns (no parent) so
// they slot in with the same renderer used for qurbani_delivery_type.
const FIELD_CONFIG = {
    qurbani_day: { label: 'Qurbani Day', icon: '📅', description: 'Day options for qurbani delivery' },
    qurbani_slot: { label: 'Qurbani Slot', icon: '🕐', description: 'Time slots (assigned per day)' },
    qurbani_region: { label: 'Qurbani Region', icon: '📍', description: 'Delivery region options' },
    qurbani_sub_region: { label: 'Sub Region', icon: '📌', description: 'Sub-regions (assigned per region)' },
    qurbani_delivery_type: { label: 'Delivery Type', icon: '🚚', description: 'Delivery or self collection' },
    qurbani_type: { label: 'Qurbani Type', icon: '🐐', description: 'Standard, custom, or your own values' },
    qurbani_paya: { label: 'Paya', icon: '🦵', description: 'Paya handling (standard, bhunnay paye, ...)' },
};
const FIELD_LABELS = {
    qurbani_day: 'Day', qurbani_slot: 'Slot', qurbani_region: 'Region',
    qurbani_sub_region: 'Sub Region', qurbani_delivery_type: 'Type',
    qurbani_type: 'Qurbani Type', qurbani_paya: 'Paya',
};

let allOptions = {};

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// Default payment method (cash/online) used when a brand-new qurbani
// order is created. Two buttons act as a radio — clicking saves
// immediately so there's no ambiguous unsaved-state. The styling
// below mirrors the server-rendered initial state for consistency.
async function setDefaultPaymentMethod(method) {
    if (method !== 'cash' && method !== 'online') return;
    const cashBtn = document.getElementById('dpmCashBtn');
    const onlineBtn = document.getElementById('dpmOnlineBtn');
    const paint = (m) => {
        if (m === 'cash') {
            cashBtn.style.background = '#10b981'; cashBtn.style.color = '#fff'; cashBtn.style.borderColor = '#10b981';
            onlineBtn.style.background = '#f3f4f6'; onlineBtn.style.color = '#374151'; onlineBtn.style.borderColor = '#d1d5db';
        } else {
            onlineBtn.style.background = '#3b82f6'; onlineBtn.style.color = '#fff'; onlineBtn.style.borderColor = '#3b82f6';
            cashBtn.style.background = '#f3f4f6'; cashBtn.style.color = '#374151'; cashBtn.style.borderColor = '#d1d5db';
        }
    };
    paint(method);
    try {
        const r = await fetch('{{ route("qurbani-settings.api.default-payment-method") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ method }),
        });
        const data = await r.json();
        if (data.success) {
            const badge = document.getElementById('dpmSaved');
            badge.style.display = '';
            setTimeout(() => badge.style.display = 'none', 2000);
            showToast('Default payment method: ' + method.toUpperCase());
        } else {
            showToast('Failed to save default', 'error');
        }
    } catch (e) { showToast('Error saving', 'error'); }
}

async function saveShippingPrice() {
    const val = document.getElementById('qurbaniShippingPrice').value;
    try {
        const r = await fetch('{{ route("qurbani-settings.api.shipping-price") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ price: parseFloat(val) || 0 }),
        });
        const data = await r.json();
        if (data.success) {
            const badge = document.getElementById('shippingPriceSaved');
            badge.style.display = '';
            setTimeout(() => badge.style.display = 'none', 2000);
            showToast('Delivery fee updated');
        }
    } catch (e) { showToast('Error saving', 'error'); }
}

async function loadOptions() {
    try {
        const response = await fetch('{{ route("qurbani-settings.api.options") }}');
        const data = await response.json();
        if (data.success) {
            allOptions = data.options || {};
            renderAll();
        }
    } catch (error) {
        console.error('Failed to load options:', error);
        document.getElementById('fieldsContainer').innerHTML = '<div class="empty-state">Failed to load settings. Please refresh.</div>';
    }
}

function renderAll() {
    const container = document.getElementById('fieldsContainer');
    let html = '';
    const dayOptions = (allOptions['qurbani_day'] || []).filter(o => o.is_active);
    const regionOptions = (allOptions['qurbani_region'] || []).filter(o => o.is_active);
    const deliveryTypeOptions = (allOptions['qurbani_delivery_type'] || []).filter(o => o.is_active);

    for (const [fieldName, config] of Object.entries(FIELD_CONFIG)) {
        if (fieldName === 'qurbani_slot') {
            html += renderSlotSection(dayOptions, deliveryTypeOptions);
            continue;
        }
        if (fieldName === 'qurbani_sub_region') {
            html += renderDependentSection('qurbani_sub_region', '📌', 'Sub Regions', 'Sub-regions assigned per region', regionOptions, 'region', 'sub-region');
            continue;
        }
        const options = allOptions[fieldName] || [];
        const activeOptions = options.filter(o => o.is_active);
        const inactiveOptions = options.filter(o => !o.is_active);
        const showInInvoice = activeOptions.length > 0 ? (activeOptions[0].show_in_invoice ? true : false) : false;

        html += `<div class="field-section">
            <div class="field-header">
                <div>
                    <div class="field-title">${config.icon} ${config.label}</div>
                    <div class="field-subtitle">${config.description} · ${activeOptions.length} active option${activeOptions.length !== 1 ? 's' : ''}</div>
                </div>
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; cursor:pointer;" title="Show this field on invoices and WhatsApp messages">
                    <input type="checkbox" ${showInInvoice ? 'checked' : ''} onchange="toggleInvoiceVisibility('${fieldName}', this.checked)" style="accent-color:#b45309;">
                    Show in Invoice
                </label>
            </div>
            <div class="field-body">`;

        if (activeOptions.length === 0 && inactiveOptions.length === 0) {
            html += '<div class="empty-state">No options yet. Add one below.</div>';
        }

        activeOptions.sort((a, b) => a.display_order - b.display_order);
        activeOptions.forEach((opt, idx) => {
            const isDefault = opt.is_default ? true : false;
            html += `<div class="option-row" data-id="${opt.id}">
                <span class="option-order">${idx + 1}</span>
                <span class="option-value" id="val-${opt.id}">${escapeHtml(opt.option_value)}</span>
                <div class="option-actions">
                    <button class="btn-sm" style="background:${isDefault ? '#d1fae5' : '#f3f4f6'}; color:${isDefault ? '#065f46' : '#6b7280'}; font-size:11px;" onclick="toggleDefault(${opt.id}, ${isDefault ? 'false' : 'true'})">${isDefault ? '★ Default' : '☆ Set Default'}</button>
                    <button class="btn-sm btn-edit" onclick="editOption(${opt.id}, '${escapeAttr(opt.option_value)}')">Edit</button>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${opt.id}, '${escapeAttr(opt.option_value)}')">Remove</button>
                </div>
            </div>`;
        });

        inactiveOptions.forEach(opt => {
            html += `<div class="option-row inactive-row" data-id="${opt.id}">
                <span class="option-order">—</span>
                <span class="option-value">${escapeHtml(opt.option_value)} <em style="font-size:11px;color:#dc2626;">(inactive)</em></span>
                <div class="option-actions">
                    <button class="btn-sm btn-restore" onclick="restoreOption(${opt.id})">Restore</button>
                </div>
            </div>`;
        });

        html += `<div class="add-form">
                <input type="text" class="add-input" id="add-${fieldName}" placeholder="New ${config.label.toLowerCase()} value..." onkeydown="if(event.key==='Enter')addOption('${fieldName}')">
                <button class="btn-add" onclick="addOption('${fieldName}')">+ Add</button>
            </div>
            </div>
        </div>`;
    }

    container.innerHTML = html;
}

function renderDependentSection(fieldName, icon, title, subtitle, parentOptions, parentLabel, childLabel) {
    const allItems = (allOptions[fieldName] || []);
    const activeItems = allItems.filter(o => o.is_active);
    const inactiveItems = allItems.filter(o => !o.is_active);
    const showInInvoice = activeItems.length > 0 ? (activeItems[0].show_in_invoice ? true : false) : false;

    let html = `<div class="field-section">
        <div class="field-header">
            <div>
                <div class="field-title">${icon} ${title}</div>
                <div class="field-subtitle">${subtitle} · ${activeItems.length} active ${childLabel}${activeItems.length !== 1 ? 's' : ''}</div>
            </div>
            <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; cursor:pointer;" title="Show this field on invoices and WhatsApp messages">
                <input type="checkbox" ${showInInvoice ? 'checked' : ''} onchange="toggleInvoiceVisibility('${fieldName}', this.checked)" style="accent-color:#b45309;">
                Show in Invoice
            </label>
        </div>
        <div class="field-body">`;

    if (parentOptions.length === 0) {
        html += `<div class="empty-state">Add ${parentLabel} options first, then assign ${childLabel}s to each ${parentLabel}.</div>`;
    }

    parentOptions.sort((a, b) => a.display_order - b.display_order);
    parentOptions.forEach(parent => {
        const children = activeItems.filter(s => s.parent_id === parent.id).sort((a, b) => a.display_order - b.display_order);
        const parentIcon = fieldName === 'qurbani_slot' ? '📅' : '📍';
        html += `<div style="margin-bottom: 16px; padding: 12px; background: #fefce8; border: 1px solid #fde68a; border-radius: 8px;">
            <div style="font-weight: 700; font-size: 14px; color: #92400e; margin-bottom: 8px;">${parentIcon} ${escapeHtml(parent.option_value)}</div>`;

        if (children.length === 0) {
            html += `<div style="font-size: 12px; color: #9ca3af; padding: 4px 0;">No ${childLabel}s assigned yet</div>`;
        }

        children.forEach((child, idx) => {
            const isDefault = child.is_default ? true : false;
            html += `<div class="option-row" data-id="${child.id}" style="margin-bottom: 4px;">
                <span class="option-order">${idx + 1}</span>
                <span class="option-value">${escapeHtml(child.option_value)}</span>
                <div class="option-actions">
                    <button class="btn-sm" style="background:${isDefault ? '#d1fae5' : '#f3f4f6'}; color:${isDefault ? '#065f46' : '#6b7280'}; font-size:11px;" onclick="toggleDefault(${child.id}, ${isDefault ? 'false' : 'true'})">${isDefault ? '★ Default' : '☆ Set Default'}</button>
                    <button class="btn-sm btn-edit" onclick="editOption(${child.id}, '${escapeAttr(child.option_value)}')">Edit</button>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${child.id}, '${escapeAttr(child.option_value)}')">Remove</button>
                </div>
            </div>`;
        });

        html += `<div class="add-form" style="margin-top: 8px;">
                <input type="text" class="add-input" id="add-${fieldName}-${parent.id}" placeholder="New ${childLabel} for ${escapeAttr(parent.option_value)}..." onkeydown="if(event.key==='Enter')addChildForParent('${fieldName}', ${parent.id})">
                <button class="btn-add" onclick="addChildForParent('${fieldName}', ${parent.id})" style="padding: 6px 12px; font-size: 12px;">+ Add</button>
            </div>
        </div>`;
    });

    // Orphan items (no parent_id)
    const orphans = activeItems.filter(s => !s.parent_id);
    if (orphans.length > 0) {
        html += `<div style="margin-top: 12px; padding: 12px; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 8px;">
            <div style="font-weight: 600; font-size: 13px; color: #9a3412; margin-bottom: 6px;">⚠️ Unassigned (assign to a ${parentLabel})</div>`;
        orphans.forEach(item => {
            html += `<div class="option-row" style="margin-bottom: 4px;">
                <span class="option-value">${escapeHtml(item.option_value)}</span>
                <div class="option-actions">
                    <select onchange="reassignChild(${item.id}, this.value)" style="padding: 3px 6px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 12px;">
                        <option value="">Assign to ${parentLabel}...</option>
                        ${parentOptions.map(d => `<option value="${d.id}">${escapeHtml(d.option_value)}</option>`).join('')}
                    </select>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${item.id}, '${escapeAttr(item.option_value)}')">Remove</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    if (inactiveItems.length > 0) {
        html += '<div style="margin-top: 12px;">';
        inactiveItems.forEach(opt => {
            html += `<div class="option-row inactive-row" data-id="${opt.id}">
                <span class="option-order">—</span>
                <span class="option-value">${escapeHtml(opt.option_value)} <em style="font-size:11px;color:#dc2626;">(inactive)</em></span>
                <div class="option-actions">
                    <button class="btn-sm btn-restore" onclick="restoreOption(${opt.id})">Restore</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    html += '</div></div>';
    return html;
}

function renderSlotSection(dayOptions, deliveryTypeOptions) {
    const allSlots = (allOptions['qurbani_slot'] || []);
    const activeSlots = allSlots.filter(o => o.is_active);
    const inactiveSlots = allSlots.filter(o => !o.is_active);
    const showInInvoice = activeSlots.length > 0 ? (activeSlots[0].show_in_invoice ? true : false) : false;

    let html = `<div class="field-section">
        <div class="field-header">
            <div>
                <div class="field-title">🕐 Qurbani Slots</div>
                <div class="field-subtitle">Time slots assigned per day and delivery type · ${activeSlots.length} active slot${activeSlots.length !== 1 ? 's' : ''}</div>
            </div>
            <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#6b7280; cursor:pointer;" title="Show this field on invoices and WhatsApp messages">
                <input type="checkbox" ${showInInvoice ? 'checked' : ''} onchange="toggleInvoiceVisibility('qurbani_slot', this.checked)" style="accent-color:#b45309;">
                Show in Invoice
            </label>
        </div>
        <div class="field-body">`;

    if (dayOptions.length === 0) {
        html += `<div class="empty-state">Add day options first, then assign slots to each day and delivery type.</div>`;
    }

    dayOptions.sort((a, b) => a.display_order - b.display_order);
    dayOptions.forEach(day => {
        const daySlotsAll = activeSlots.filter(s => s.parent_id === day.id);

        html += `<div style="margin-bottom: 16px; padding: 12px; background: #fefce8; border: 1px solid #fde68a; border-radius: 8px;">
            <div style="font-weight: 700; font-size: 14px; color: #92400e; margin-bottom: 10px;">📅 ${escapeHtml(day.option_value)}</div>`;

        if (deliveryTypeOptions.length === 0) {
            html += renderSlotGroupForParent(daySlotsAll, day.id, null, null, day.option_value, 'all');
        } else {
            deliveryTypeOptions.sort((a, b) => a.display_order - b.display_order);
            deliveryTypeOptions.forEach(dt => {
                const dtSlots = daySlotsAll.filter(s => s.delivery_type_parent_id === dt.id);
                html += renderSlotGroupForParent(dtSlots, day.id, dt.id, dt.option_value, day.option_value, dt.option_value);
            });

            const unlinkedSlots = daySlotsAll.filter(s => !s.delivery_type_parent_id);
            if (unlinkedSlots.length > 0) {
                html += `<div style="margin-top: 8px; padding: 8px; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 6px;">
                    <div style="font-size: 12px; font-weight: 600; color: #9a3412; margin-bottom: 6px;">⚠️ Unlinked slots (assign to delivery type)</div>`;
                unlinkedSlots.forEach(slot => {
                    html += `<div class="option-row" data-id="${slot.id}" style="margin-bottom: 4px;">
                        <span class="option-value">${escapeHtml(slot.option_value)}</span>
                        <div class="option-actions">
                            <select onchange="reassignSlotDeliveryType(${slot.id}, this.value)" style="padding: 3px 6px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 12px;">
                                <option value="">Assign type...</option>
                                ${deliveryTypeOptions.map(d => `<option value="${d.id}">${escapeHtml(d.option_value)}</option>`).join('')}
                            </select>
                            <button class="btn-sm btn-delete" onclick="deleteOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Remove</button>
                        </div>
                    </div>`;
                });
                html += `</div>`;
            }
        }

        html += `</div>`;
    });

    const orphanSlots = activeSlots.filter(s => !s.parent_id);
    if (orphanSlots.length > 0) {
        html += `<div style="margin-top: 12px; padding: 12px; background: #fff7ed; border: 1px dashed #fdba74; border-radius: 8px;">
            <div style="font-weight: 600; font-size: 13px; color: #9a3412; margin-bottom: 6px;">⚠️ Unassigned slots (assign to a day)</div>`;
        orphanSlots.forEach(item => {
            html += `<div class="option-row" style="margin-bottom: 4px;">
                <span class="option-value">${escapeHtml(item.option_value)}</span>
                <div class="option-actions">
                    <select onchange="reassignChild(${item.id}, this.value)" style="padding: 3px 6px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 12px;">
                        <option value="">Assign to day...</option>
                        ${dayOptions.map(d => `<option value="${d.id}">${escapeHtml(d.option_value)}</option>`).join('')}
                    </select>
                    <button class="btn-sm btn-delete" onclick="deleteOption(${item.id}, '${escapeAttr(item.option_value)}')">Remove</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    if (inactiveSlots.length > 0) {
        html += '<div style="margin-top: 12px;">';
        inactiveSlots.forEach(opt => {
            html += `<div class="option-row inactive-row" data-id="${opt.id}">
                <span class="option-order">—</span>
                <span class="option-value">${escapeHtml(opt.option_value)} <em style="font-size:11px;color:#dc2626;">(inactive)</em></span>
                <div class="option-actions">
                    <button class="btn-sm btn-restore" onclick="restoreOption(${opt.id})">Restore</button>
                </div>
            </div>`;
        });
        html += '</div>';
    }

    html += '</div></div>';
    return html;
}

function renderSlotGroupForParent(slots, dayId, dtId, dtLabel, dayLabel, groupKey) {
    let html = `<div style="margin-bottom: 8px; padding: 8px 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px;">`;
    if (dtLabel) {
        html += `<div style="font-weight: 600; font-size: 13px; color: #78350f; margin-bottom: 6px;">🚚 ${escapeHtml(dtLabel)}</div>`;
    }

    if (slots.length === 0) {
        html += `<div style="font-size: 12px; color: #9ca3af; padding: 2px 0;">No slots assigned yet</div>`;
    }

    slots.forEach((slot, idx) => {
        const isDefault = slot.is_default ? true : false;
        html += `<div class="option-row" data-id="${slot.id}" style="margin-bottom: 4px;">
            <span class="option-order">${idx + 1}</span>
            <span class="option-value">${escapeHtml(slot.option_value)}</span>
            <div class="option-actions">
                <button class="btn-sm" style="background:${isDefault ? '#d1fae5' : '#f3f4f6'}; color:${isDefault ? '#065f46' : '#6b7280'}; font-size:11px;" onclick="toggleDefault(${slot.id}, ${isDefault ? 'false' : 'true'})">${isDefault ? '★ Default' : '☆ Set Default'}</button>
                <button class="btn-sm btn-edit" onclick="editOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Edit</button>
                <button class="btn-sm btn-delete" onclick="deleteOption(${slot.id}, '${escapeAttr(slot.option_value)}')">Remove</button>
            </div>
        </div>`;
    });

    const inputId = dtId ? `add-slot-${dayId}-${dtId}` : `add-slot-${dayId}`;
    const placeholderSuffix = dtLabel ? ` for ${escapeAttr(dayLabel)} / ${escapeAttr(dtLabel)}` : ` for ${escapeAttr(dayLabel)}`;
    html += `<div class="add-form" style="margin-top: 6px;">
            <input type="text" class="add-input" id="${inputId}" placeholder="New slot${placeholderSuffix}..." onkeydown="if(event.key==='Enter')addSlotForDayAndType(${dayId}, ${dtId || 'null'})">
            <button class="btn-add" onclick="addSlotForDayAndType(${dayId}, ${dtId || 'null'})" style="padding: 6px 12px; font-size: 12px;">+ Add</button>
        </div>`;

    html += `</div>`;
    return html;
}

async function addSlotForDayAndType(dayId, dtId) {
    const inputId = dtId ? `add-slot-${dayId}-${dtId}` : `add-slot-${dayId}`;
    const input = document.getElementById(inputId);
    const value = input.value.trim();
    if (!value) return;
    try {
        const body = { field_name: 'qurbani_slot', option_value: value, parent_id: dayId };
        if (dtId) body.delivery_type_parent_id = dtId;
        const response = await fetch('{{ route("qurbani-settings.api.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify(body),
        });
        const data = await response.json();
        if (data.success) { showToast(data.message); input.value = ''; loadOptions(); }
        else { showToast(data.message || 'Failed', 'error'); }
    } catch (e) { showToast('Network error', 'error'); }
}

async function reassignSlotDeliveryType(slotId, deliveryTypeId) {
    if (!deliveryTypeId) return;
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${slotId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ delivery_type_parent_id: parseInt(deliveryTypeId) }),
        });
        const data = await response.json();
        if (data.success) { showToast('Delivery type assigned'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function addChildForParent(fieldName, parentId) {
    const input = document.getElementById(`add-${fieldName}-${parentId}`);
    const value = input.value.trim();
    if (!value) return;
    try {
        const response = await fetch('{{ route("qurbani-settings.api.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ field_name: fieldName, option_value: value, parent_id: parentId }),
        });
        const data = await response.json();
        if (data.success) { showToast(data.message); input.value = ''; loadOptions(); }
        else { showToast(data.message || 'Failed', 'error'); }
    } catch (e) { showToast('Network error', 'error'); }
}

async function reassignChild(itemId, parentId) {
    if (!parentId) return;
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${itemId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ parent_id: parseInt(parentId) }),
        });
        const data = await response.json();
        if (data.success) { showToast('Assigned successfully'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function toggleInvoiceVisibility(fieldName, checked) {
    const options = allOptions[fieldName] || [];
    const firstActive = options.find(o => o.is_active);
    if (!firstActive) { showToast('No options to update', 'error'); return; }
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${firstActive.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ show_in_invoice: checked }),
        });
        const data = await response.json();
        if (data.success) { showToast(checked ? 'Will show in invoice' : 'Hidden from invoice'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function toggleDefault(id, setDefault) {
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ is_default: setDefault === 'true' || setDefault === true }),
        });
        const data = await response.json();
        if (data.success) { showToast(setDefault ? 'Set as default' : 'Default removed'); loadOptions(); }
    } catch (e) { showToast('Error', 'error'); }
}

async function addOption(fieldName) {
    const input = document.getElementById(`add-${fieldName}`);
    const value = input.value.trim();
    if (!value) return;

    try {
        const response = await fetch('{{ route("qurbani-settings.api.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ field_name: fieldName, option_value: value }),
        });
        const data = await response.json();
        if (data.success) {
            showToast(data.message);
            input.value = '';
            loadOptions();
        } else {
            showToast(data.message || 'Failed to add', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

async function editOption(id, currentValue) {
    const newValue = prompt('Edit value:', currentValue);
    if (!newValue || newValue.trim() === currentValue) return;

    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ option_value: newValue.trim() }),
        });
        const data = await response.json();
        if (data.success) {
            showToast('Option updated');
            loadOptions();
        } else {
            showToast(data.message || 'Failed', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

async function deleteOption(id, value) {
    if (!confirm(`Remove "${value}"? It will be deactivated (not deleted).`)) return;

    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        });
        const data = await response.json();
        if (data.success) {
            showToast('Option deactivated');
            loadOptions();
        } else {
            showToast(data.message || 'Failed', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

async function restoreOption(id) {
    try {
        const response = await fetch(`{{ url("qurbani-settings/api/options") }}/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ is_active: true }),
        });
        const data = await response.json();
        if (data.success) {
            showToast('Option restored');
            loadOptions();
        } else {
            showToast(data.message || 'Failed', 'error');
        }
    } catch (error) {
        showToast('Network error', 'error');
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function escapeAttr(str) {
    return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', loadOptions);
</script>
@endpush
