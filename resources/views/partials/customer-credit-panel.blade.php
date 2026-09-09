{{--
    💰 Customer account balance ("bucket") — THE shared panel + actions.

    ⚠⚠ RAW JAVASCRIPT, no <script> tags. Include it INSIDE an existing
    <script> block:  @include('partials.customer-credit-panel')

    Lifted verbatim out of pages/customers/index.blade.php (Sep-2026) when the
    Customer Balances page needed the same panel. There is now exactly ONE
    implementation of every balance action in the web app — grant, approve,
    reject, void (remove one entry) and zero-out — so no screen can drift from
    another about what a balance is or how it is corrected. Every function here
    posts to the same CustomerCreditController endpoints, and the SERVER decides
    what is allowed; hiding a button is never the gate.

    A host page may set  window.nfCreditOnChange = fn(customerId)  to be told
    after any successful change (the Balances page repaints its table with it).
    The customer modal needs no hook — it still repaints #nfCustomerCreditPanel.
--}}
// =====================================================================
// 💰 Customer account balance ("bucket")
//
// Money a customer has paid us beyond their invoices, held for their next
// order. Everything here is server-decided — this only paints the answer and
// posts the manager's intent. Shop customers are excluded by the server, and
// the panel simply does not appear for them.
// =====================================================================

function nfCredCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}
// Shabib/Taimur's own grants are approved on the spot (server rule in
// CustomerCreditService::userCanAutoApproveGrant) — the form's wording and
// button label follow, so the screen never promises a queue that won't happen.
const NF_CREDIT_AUTO_APPROVES = {{ app(\App\Services\CustomerCreditService::class)->userCanAutoApproveGrant(auth()->user()) ? 'true' : 'false' }};
function nfCredEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * How a result is announced. The approvals screen has a proper toast; the
 * customer modal and the Balances page do not. Using the host's toast when it
 * exists means sharing these functions never downgrades a screen that already
 * had the nicer feedback.
 */
function nfCreditNotify(message, ok) {
    if (typeof showToast === 'function') {
        showToast(message, ok ? 'success' : 'error');
    } else {
        alert(message);
    }
}

async function nfRenderCustomerCredit(customerId, content) {
    let d;
    try {
        const res = await fetch(`/customer-credit/${customerId}/summary?limit=15`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (!json || !json.success) return;
        d = json.data;
    } catch (e) { return; }

    // Shop customers (and unknown customers) get no panel at all.
    if (!d.eligible) return;

    const box = document.createElement('div');
    box.id = 'nfCustomerCreditPanel';
    box.style.cssText = 'margin:14px 0 18px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#fafbfa;';
    box.innerHTML = nfCreditPanelMarkup(customerId, d);

    const ordersWrap = content.querySelector('#customerOrdersInlineWrap');
    if (ordersWrap && ordersWrap.parentNode) {
        ordersWrap.parentNode.insertBefore(box, ordersWrap);
    } else {
        content.appendChild(box);
    }
}

function nfCreditPanelMarkup(customerId, d) {
    const bal = parseFloat(d.balance || 0);
    const hasBal = bal >= 0.01;

    const history = (d.history || []).map(h => {
        const sign = h.is_credit ? '+' : '−';
        const colour = h.is_credit ? '#059669' : '#b45309';
        const struck = h.counts ? '' : 'text-decoration:line-through;opacity:.55;';
        // The invoice total belongs beside the amount: "Added Rs 8,260.60" looks
        // ordinary until you can see the order was Rs 2,589.40.
        const invoice = (h.order_total !== null && h.order_total !== undefined)
            ? ` <span style="color:#9ca3af;">(invoice Rs. ${Number(h.order_total).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})</span>`
            : '';
        const where = h.order_number ? ` · order ${nfCredEsc(h.order_number)}${invoice}` : '';
        const why = h.reason ? ` · ${nfCredEsc(h.reason)}` : '';
        const state = h.status === 'pending' ? ' <em style="color:#b45309;">(awaiting approval)</em>'
                    : h.status === 'reserved' ? ' <em style="color:#2563eb;">(held for an order)</em>'
                    : h.status === 'voided'   ? ' <em style="color:#6b7280;">(cancelled)</em>' : '';

        // A pending entry is only real money once someone with Level 2 rights
        // approves it, so the buttons appear for them and nobody else.
        //
        // An entry that is ALREADY counting gets "Remove" instead — one wrong
        // entry can be undone on its own, without wiping the customer's real
        // money the way Clear-to-zero does. Only grants: taking credit back off
        // an ORDER has to move that order's totals, which is a different job.
        let actions = '';
        if (h.status === 'pending' && d.can_approve) {
            actions = `<div style="margin-top:3px;display:flex;gap:6px;">
                 <button type="button" onclick="nfApproveCredit(${customerId}, ${h.id})"
                   style="padding:3px 9px;background:#059669;color:#fff;border:0;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;">Approve</button>
                 <button type="button" onclick="nfRejectCredit(${customerId}, ${h.id})"
                   style="padding:3px 9px;background:#fff;color:#b91c1c;border:1px solid #fca5a5;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;">Reject</button>
               </div>`;
        } else if (d.can_approve && h.counts && h.entry_type === 'grant') {
            actions = `<div style="margin-top:3px;">
                 <button type="button" onclick="nfVoidCredit(${customerId}, ${h.id}, '${nfCredEsc(h.amount_abs)}')"
                   style="padding:3px 9px;background:#fff;color:#b91c1c;border:1px solid #fca5a5;border-radius:4px;cursor:pointer;font-size:11px;font-weight:600;"
                   title="Undo just this entry — the rest of the balance is untouched">Remove this entry</button>
               </div>`;
        }
        const voidedNote = (h.status === 'voided' && h.voided_reason)
            ? `<div style="margin-top:2px;font-size:11px;color:#6b7280;">Removed${h.voided_by_name ? ' by ' + nfCredEsc(h.voided_by_name) : ''} — ${nfCredEsc(h.voided_reason)}</div>`
            : '';

        return `<tr>
            <td style="padding:5px 8px 5px 0;white-space:nowrap;color:#6b7280;font-size:12px;vertical-align:top;">${nfCredEsc(h.date || '')}</td>
            <td style="padding:5px 8px 5px 0;font-size:12px;"><span style="${struck}">${nfCredEsc(h.type_label)}${where}${why}</span>${state}${voidedNote}${actions}</td>
            <td style="padding:5px 0;text-align:right;white-space:nowrap;font-weight:600;font-size:12px;color:${colour};${struck}vertical-align:top;">
                ${sign} Rs. ${nfCredEsc(h.amount_abs)}
            </td>
        </tr>`;
    }).join('');

    const pending = d.pending_count > 0
        ? `<div style="margin-top:6px;font-size:12px;color:#b45309;">
             ${d.pending_count} entr${d.pending_count === 1 ? 'y' : 'ies'} worth
             Rs. ${parseFloat(d.pending_total).toFixed(2)} waiting for approval —
             <strong>not</strong> included in the balance above.
           </div>`
        : '';

    return `
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
            <div>
                <div style="font-weight:700;color:#111827;font-size:14px;">💰 Account balance</div>
                <div style="font-size:26px;font-weight:800;color:${hasBal ? '#059669' : '#9ca3af'};line-height:1.3;">
                    Rs. ${nfCredEsc(d.balance_display)}
                </div>
                <div style="font-size:12px;color:#6b7280;">
                    ${hasBal ? 'Available to use on their next order.' : 'Nothing held for this customer.'}
                </div>
                ${pending}
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="button" onclick="nfOpenGrantForm(${customerId})"
                        style="padding:8px 14px;background:#059669;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">
                    + Received extra
                </button>
                ${hasBal ? `<button type="button" onclick="nfZeroOutCredit(${customerId})"
                        style="padding:8px 14px;background:#fff;color:#b91c1c;border:1px solid #fca5a5;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">
                    Clear to zero
                </button>` : ''}
            </div>
        </div>

        <div id="nfGrantForm" style="display:none;margin-top:12px;padding:12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">
            <div style="font-size:12px;color:#065f46;margin-bottom:8px;">
                Record money received from this customer beyond their invoices.
                ${NF_CREDIT_AUTO_APPROVES
                    ? 'It becomes usable balance immediately.'
                    : 'It goes for approval first, then becomes usable balance.'}
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="number" step="0.01" min="1" id="nfGrantAmount" placeholder="Amount"
                       style="width:130px;padding:8px 10px;border:1px solid #6ee7b7;border-radius:6px;font-size:14px;font-weight:600;">
                <select id="nfGrantMode" style="padding:8px 10px;border:1px solid #6ee7b7;border-radius:6px;font-size:13px;">
                    <option value="online">Online / bank</option>
                    <option value="cash">Cash</option>
                </select>
                <input type="text" id="nfGrantReason" placeholder="Note (e.g. paid extra on NF-19304)"
                       style="flex:1;min-width:200px;padding:8px 10px;border:1px solid #6ee7b7;border-radius:6px;font-size:13px;">
                <button type="button" onclick="nfSubmitGrant(${customerId})"
                        style="padding:8px 14px;background:#059669;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">
                    ${NF_CREDIT_AUTO_APPROVES ? 'Add to balance' : 'Send for approval'}
                </button>
                <button type="button" onclick="document.getElementById('nfGrantForm').style.display='none'"
                        style="padding:8px 12px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:13px;">
                    Cancel
                </button>
            </div>
        </div>

        ${history ? `
        <details style="margin-top:12px;" ${hasBal ? 'open' : ''}>
            <summary style="cursor:pointer;font-size:12px;font-weight:600;color:#374151;">History</summary>
            <table style="width:100%;border-collapse:collapse;margin-top:6px;">${history}</table>
            <div style="margin-top:8px;">
                <a href="/customers/balances?tab=activity&customer_id=${customerId}"
                   style="font-size:11.5px;color:#047857;text-decoration:underline;">
                    Full history, running balance and day totals →
                </a>
            </div>
        </details>` : ''}
    `;
}

function nfOpenGrantForm(customerId) {
    const f = document.getElementById('nfGrantForm');
    if (f) { f.style.display = f.style.display === 'none' ? 'block' : 'none'; }
}

async function nfSubmitGrant(customerId) {
    const amount = parseFloat(document.getElementById('nfGrantAmount')?.value || 0);
    const mode   = document.getElementById('nfGrantMode')?.value || 'online';
    const reason = document.getElementById('nfGrantReason')?.value || '';

    if (!(amount > 0)) { alert('Enter the amount received.'); return; }

    try {
        const res = await fetch(`/customer-credit/${customerId}/grant`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json', 'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': nfCredCsrf()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ amount, mode, reason })
        });
        const json = await res.json();
        nfCreditNotify(json.message || (json.success ? 'Recorded.' : 'Could not record it.'), json.success);
        if (json.success) { nfRefreshCreditPanel(customerId); }
    } catch (e) {
        nfCreditNotify('Could not record it: ' + e.message, false);
    }
}

async function nfZeroOutCredit(customerId) {
    const reason = prompt(
        'Clear this customer\'s balance to zero.\n\n' +
        'No money leaves the business — the balance is written off and this note is kept ' +
        'in the history.\n\nWhy are you clearing it?'
    );
    if (reason === null) return;
    if (!reason || reason.trim().length < 3) { alert('Please give a short reason.'); return; }

    try {
        const res = await fetch(`/customer-credit/${customerId}/zero-out`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json', 'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': nfCredCsrf()
            },
            credentials: 'same-origin',
            body: JSON.stringify({ reason: reason.trim() })
        });
        const json = await res.json();
        nfCreditNotify(json.message || (json.success ? 'Cleared.' : 'Could not clear it.'), json.success);
        if (json.success) { nfRefreshCreditPanel(customerId); }
    } catch (e) {
        nfCreditNotify('Could not clear it: ' + e.message, false);
    }
}

async function nfCreditAction(customerId, creditId, action, body) {
    try {
        const res = await fetch(`/customer-credit/${creditId}/${action}`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json', 'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': nfCredCsrf()
            },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        });
        const json = await res.json();
        nfCreditNotify(json.message || (json.success ? 'Done.' : 'Could not do that.'), json.success);
        if (json.success) { nfRefreshCreditPanel(customerId); }
    } catch (e) {
        nfCreditNotify('Failed: ' + e.message, false);
    }
}

function nfApproveCredit(customerId, creditId) {
    if (!confirm('Approve this amount and add it to the customer\'s balance?')) return;
    nfCreditAction(customerId, creditId, 'approve', { mode: 'online' });
}

function nfRejectCredit(customerId, creditId) {
    const reason = prompt('Reject this entry? No balance will be added.\n\nReason (optional):');
    if (reason === null) return;
    nfCreditAction(customerId, creditId, 'reject', { reason });
}

/**
 * Undo ONE wrong entry. Unlike "Clear to zero" this leaves the rest of the
 * customer's balance alone, and it is refused outright if the money has
 * already been used on an order (the server checks — take it off that order
 * first, or the balance would go negative).
 */
function nfVoidCredit(customerId, creditId, amountText) {
    const reason = prompt(
        `Remove this Rs. ${amountText} entry from the customer's balance?\n\n` +
        `Use this when the entry itself was wrong — a typo, or a payment that turned out ` +
        `to be someone else's. The rest of the balance is not affected.\n\n` +
        `Why are you removing it?`
    );
    if (reason === null) return;
    if (!reason || reason.trim().length < 3) { alert('Please give a short reason.'); return; }
    nfCreditAction(customerId, creditId, 'void', { reason: reason.trim() });
}

async function nfRefreshCreditPanel(customerId) {
    const panel = document.getElementById('nfCustomerCreditPanel');
    if (panel) {
        try {
            const res = await fetch(`/customer-credit/${customerId}/summary?limit=15`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const json = await res.json();
            if (json && json.success) { panel.innerHTML = nfCreditPanelMarkup(customerId, json.data); }
        } catch (e) { /* leave the old panel rather than blanking it */ }
    }

    // Host-page hook. The Balances page repaints its table and totals here, so
    // a correction made from either screen leaves both screens telling the
    // truth. Wrapped: a broken host hook must not swallow the action's result.
    if (typeof window.nfCreditOnChange === 'function') {
        try { await window.nfCreditOnChange(customerId); } catch (e) { console.error(e); }
    }
}
