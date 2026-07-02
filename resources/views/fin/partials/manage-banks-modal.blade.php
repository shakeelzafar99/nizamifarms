{{--
  Self-contained "Manage Banks" modal for the Banks page.

  Deliberately standalone (functions namespaced `mb*`, own helpers) so it does
  NOT touch the mature Online Approvals page, which has its own copy of this
  modal. Both talk to the SAME /online-receiving-accounts CRUD endpoints
  (Taimur-gated server-side), so they stay functionally consistent. This copy
  reloads the page after any change so the balance cards refresh.
--}}
<div id="mbManageBanksModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; padding:20px;" onclick="if(event.target===this) mbCloseManageBanks()">
    <div style="background:#fff; border-radius:16px; width:100%; max-width:560px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden;" onclick="event.stopPropagation()">
        <div style="padding:18px 22px; border-bottom:1px solid #E5E7EB; display:flex; align-items:center; justify-content:space-between;">
            <h3 style="font-size:17px; font-weight:700; color:#111827; margin:0;">🏦 Manage Bank Accounts</h3>
            <button onclick="mbCloseManageBanks()" style="background:none; border:none; font-size:22px; color:#9CA3AF; cursor:pointer;">&times;</button>
        </div>

        {{-- Add form --}}
        <div style="padding:14px 22px; background:#F0F9FF; border-bottom:1px solid #BAE6FD;">
            <div style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
                <div style="flex:1.4; min-width:120px;">
                    <label style="display:block; font-size:11px; font-weight:600; color:#6B7280; margin-bottom:4px;">Name</label>
                    <input type="text" id="mbNewName" placeholder="e.g. Meezan Bank" style="width:100%; padding:8px 10px; border:1px solid #D1D5DB; border-radius:6px; font-size:13px;">
                </div>
                <div style="flex:1; min-width:90px;">
                    <label style="display:block; font-size:11px; font-weight:600; color:#6B7280; margin-bottom:4px;">Short Code</label>
                    <input type="text" id="mbNewCode" placeholder="e.g. MBL-4237" style="width:100%; padding:8px 10px; border:1px solid #D1D5DB; border-radius:6px; font-size:13px;">
                </div>
                <div style="flex:0.6; min-width:60px;">
                    <label style="display:block; font-size:11px; font-weight:600; color:#6B7280; margin-bottom:4px;" title="Last 4 digits — used to auto-detect the bank from a proof">Last 4</label>
                    <input type="text" id="mbNewLast4" maxlength="4" inputmode="numeric" placeholder="4237" style="width:100%; padding:8px 10px; border:1px solid #D1D5DB; border-radius:6px; font-size:13px;">
                </div>
                <div style="flex:0.5; min-width:44px;">
                    <label style="display:block; font-size:11px; font-weight:600; color:#6B7280; margin-bottom:4px;">Color</label>
                    <input type="color" id="mbNewColor" value="#3B82F6" style="width:100%; height:36px; border:1px solid #D1D5DB; border-radius:6px; cursor:pointer; padding:2px;">
                </div>
                <button onclick="mbAdd()" style="background:#0369A1; color:#fff; font-weight:600; padding:8px 14px; border-radius:6px; border:none; cursor:pointer; font-size:13px; height:36px; white-space:nowrap;">+ Add</button>
            </div>
        </div>

        {{-- List --}}
        <div id="mbBanksList" style="padding:14px 22px; overflow-y:auto; flex:1;">
            <div style="text-align:center; color:#9CA3AF; padding:20px;">Loading…</div>
        </div>
    </div>
</div>

<script>
let mbBanksData = [];
function mbEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function mbNum(n) { return Math.round(Number(n) || 0).toLocaleString(); }
function mbCsrf() { return document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'; }

function mbOpenManageBanks() {
    document.getElementById('mbManageBanksModal').style.display = 'flex';
    mbLoad();
}
function mbCloseManageBanks() {
    document.getElementById('mbManageBanksModal').style.display = 'none';
    // If anything changed, refresh the balance cards.
    if (window._mbChanged) { window.location.reload(); }
}

async function mbLoad() {
    const box = document.getElementById('mbBanksList');
    box.innerHTML = '<div style="text-align:center; color:#9CA3AF; padding:20px;">Loading…</div>';
    try {
        const res = await fetch('/online-receiving-accounts', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': mbCsrf() }
        });
        const data = await res.json();
        if (data.success) { mbBanksData = data.data || []; mbRender(); }
        else { box.innerHTML = '<div style="text-align:center; color:#EF4444; padding:20px;">Failed to load.</div>'; }
    } catch (e) {
        box.innerHTML = '<div style="text-align:center; color:#EF4444; padding:20px;">Failed to load.</div>';
    }
}

function mbRender() {
    const box = document.getElementById('mbBanksList');
    if (!mbBanksData.length) {
        box.innerHTML = '<div style="text-align:center; color:#9CA3AF; padding:20px;">No bank accounts yet. Add one above.</div>';
        return;
    }
    box.innerHTML = mbBanksData.map(a => `
        <div id="mb-row-${a.id}" style="display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #F3F4F6; ${!a.is_active ? 'opacity:0.55;' : ''}">
            <div style="width:14px; height:14px; border-radius:50%; background:${mbEsc(a.color_hex || '#3B82F6')}; flex-shrink:0;"></div>
            <div style="flex:1; min-width:0;">
                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                    <span style="font-weight:600; color:#1F2937; font-size:14px;">${mbEsc(a.name)}</span>
                    <span style="background:#F3F4F6; color:#6B7280; padding:1px 6px; border-radius:4px; font-size:11px; font-weight:600;">${mbEsc(a.short_code)}</span>
                    ${a.account_last4 ? `<span style="background:#EFF6FF; color:#1D4ED8; padding:1px 6px; border-radius:4px; font-size:11px; font-weight:600;">••${mbEsc(a.account_last4)}</span>` : ''}
                    ${!a.is_active ? '<span style="background:#FEE2E2; color:#DC2626; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:600;">INACTIVE</span>' : ''}
                </div>
                ${(a.opening_balance && parseFloat(a.opening_balance) !== 0) || a.opening_balance_date ? `<div style="font-size:11px; color:#9CA3AF; margin-top:2px;">Opening: Rs. ${mbNum(parseFloat(a.opening_balance || 0))}${a.opening_balance_date ? ' · ' + mbEsc(String(a.opening_balance_date).slice(0,10)) : ''}</div>` : ''}
            </div>
            <button onclick="mbEdit(${a.id})" style="background:#F3F4F6; color:#374151; padding:4px 8px; border-radius:4px; border:none; cursor:pointer; font-size:11px;" title="Edit">✏️</button>
            <button onclick="mbToggle(${a.id})" style="background:${a.is_active ? '#FEF3C7' : '#D1FAE5'}; color:${a.is_active ? '#92400E' : '#065F46'}; padding:4px 8px; border-radius:4px; border:none; cursor:pointer; font-size:11px;" title="${a.is_active ? 'Deactivate' : 'Activate'}">${a.is_active ? '⏸' : '▶'}</button>
            <button onclick="mbDelete(${a.id}, '${mbEsc(a.name)}')" style="background:#FEE2E2; color:#DC2626; padding:4px 8px; border-radius:4px; border:none; cursor:pointer; font-size:11px;" title="Delete">🗑</button>
        </div>
    `).join('');
}

async function mbAdd() {
    const name = document.getElementById('mbNewName').value.trim();
    const code = document.getElementById('mbNewCode').value.trim();
    const last4 = (document.getElementById('mbNewLast4').value || '').replace(/\D/g, '').slice(-4);
    const color = document.getElementById('mbNewColor').value;
    if (!name) { alert('Enter a bank name'); return; }
    if (!code) { alert('Enter a short code'); return; }
    try {
        const res = await fetch('/online-receiving-accounts', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': mbCsrf() },
            body: JSON.stringify({ name, short_code: code, account_last4: last4 || null, color_hex: color })
        });
        const data = await res.json();
        if (data.success) {
            window._mbChanged = true;
            document.getElementById('mbNewName').value = '';
            document.getElementById('mbNewCode').value = '';
            document.getElementById('mbNewLast4').value = '';
            document.getElementById('mbNewColor').value = '#3B82F6';
            mbLoad();
        } else { alert(data.message || 'Failed to add.'); }
    } catch (e) { alert('Failed to add: ' + e.message); }
}

function mbEdit(id) {
    const a = mbBanksData.find(x => x.id === id);
    if (!a) return;
    document.getElementById(`mb-row-${id}`).innerHTML = `
        <div style="width:100%; display:flex; flex-direction:column; gap:6px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <input type="text" id="mb-name-${id}" value="${mbEsc(a.name)}" placeholder="Name" style="flex:1.5; padding:6px 8px; border:1px solid #3B82F6; border-radius:4px; font-size:13px;">
                <input type="text" id="mb-code-${id}" value="${mbEsc(a.short_code)}" placeholder="Code" style="flex:1; padding:6px 8px; border:1px solid #3B82F6; border-radius:4px; font-size:13px;">
                <input type="text" id="mb-last4-${id}" value="${mbEsc(a.account_last4 || '')}" maxlength="4" inputmode="numeric" placeholder="Last4" style="width:60px; padding:6px 8px; border:1px solid #3B82F6; border-radius:4px; font-size:13px;">
                <input type="color" id="mb-color-${id}" value="${a.color_hex || '#3B82F6'}" style="width:36px; height:30px; border:1px solid #D1D5DB; border-radius:4px; cursor:pointer; padding:1px;">
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <input type="number" step="0.01" id="mb-opening-${id}" value="${a.opening_balance != null ? parseFloat(a.opening_balance) : ''}" placeholder="Opening bal (START of date)" title="Balance as of the START of the date on the right — transactions on that date are added on top" style="flex:1.2; padding:6px 8px; border:1px solid #CBD5E1; border-radius:4px; font-size:13px;">
                <input type="date" id="mb-opening-date-${id}" value="${a.opening_balance_date ? String(a.opening_balance_date).slice(0,10) : ''}" title="Balance counts from the START of this date (transactions on this date are included)" style="flex:1; padding:6px 8px; border:1px solid #CBD5E1; border-radius:4px; font-size:13px;">
                <button onclick="mbSave(${id})" style="background:#16A34A; color:#fff; padding:6px 10px; border-radius:4px; border:none; cursor:pointer; font-size:12px; font-weight:600;">Save</button>
                <button onclick="mbRender()" style="background:#E5E7EB; color:#374151; padding:6px 10px; border-radius:4px; border:none; cursor:pointer; font-size:12px;">Cancel</button>
            </div>
        </div>`;
}

async function mbSave(id) {
    const name = document.getElementById(`mb-name-${id}`).value.trim();
    const code = document.getElementById(`mb-code-${id}`).value.trim();
    const last4 = (document.getElementById(`mb-last4-${id}`).value || '').replace(/\D/g, '').slice(-4);
    const color = document.getElementById(`mb-color-${id}`).value;
    const openingRaw = document.getElementById(`mb-opening-${id}`).value;
    const openingDate = document.getElementById(`mb-opening-date-${id}`).value;
    if (!name || !code) { alert('Name and short code are required'); return; }
    const body = { name, short_code: code, account_last4: last4 || null, color_hex: color };
    if (openingRaw !== '') body.opening_balance = parseFloat(openingRaw);
    if (openingDate) body.opening_balance_date = openingDate;
    try {
        const res = await fetch(`/online-receiving-accounts/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': mbCsrf() },
            body: JSON.stringify(body)
        });
        const data = await res.json();
        if (data.success) { window._mbChanged = true; mbLoad(); }
        else { alert(data.message || 'Failed to update.'); }
    } catch (e) { alert('Failed to update: ' + e.message); }
}

async function mbToggle(id) {
    try {
        const res = await fetch(`/online-receiving-accounts/${id}/toggle-active`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': mbCsrf() }
        });
        const data = await res.json();
        if (data.success) { window._mbChanged = true; mbLoad(); }
        else { alert(data.message || 'Failed.'); }
    } catch (e) { alert('Failed: ' + e.message); }
}

async function mbDelete(id, name) {
    if (!confirm(`Delete bank "${name}"? (Only allowed if no ledger entries use it.)`)) return;
    try {
        const res = await fetch(`/online-receiving-accounts/${id}`, {
            method: 'DELETE',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': mbCsrf() }
        });
        const data = await res.json();
        if (data.success) { window._mbChanged = true; mbLoad(); }
        else { alert(data.message || 'Cannot delete.'); }
    } catch (e) { alert('Failed: ' + e.message); }
}
</script>
