@extends('layouts.app')

@section('title', 'Customer Balances')

{{--
    💰 Customer Balances — the audit screen for the account-balance bucket.

    Three questions, three tabs: who holds our customers' money, what happened
    to it (and who did it), and how much moved each day. Nothing on this page
    writes: every correction posts to the same /customer-credit/* endpoints the
    customer panel uses, through the shared partial included at the bottom.
--}}

@push('custom_css')
<style>
/* Page-scoped only — every selector is prefixed, so nothing here can reach
   another page's markup (see the "bare class in a page <style>" trap). */
.nfbal-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; }
.nfbal-tab { padding:8px 16px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600;
             border:1px solid #e5e7eb; background:#fff; color:#374151; }
.nfbal-tab.is-on { background:#065f46; border-color:#065f46; color:#fff; }
.nfbal-stat { padding:10px 14px; border-radius:10px; background:#fff; border:1px solid #e5e7eb; min-width:150px; }
.nfbal-stat .lbl { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; }
.nfbal-stat .val { font-size:19px; font-weight:800; color:#111827; line-height:1.35; }
.nfbal-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.nfbal-tbl th { text-align:left; padding:9px 12px; background:#f9fafb; border-bottom:1px solid #e5e7eb;
                font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; white-space:nowrap; }
.nfbal-tbl td { padding:9px 12px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
.nfbal-tbl tbody tr:hover { background:#fafafa; }
.nfbal-chip { display:inline-block; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:700; white-space:nowrap; }
.nfbal-chip.danger { color:#991b1b; background:#fef2f2; border:1px solid #fecaca; }
.nfbal-chip.info   { color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; }
.nfbal-chip.muted  { color:#4b5563; background:#f3f4f6; border:1px solid #e5e7eb; }
.nfbal-chip.good   { color:#065f46; background:#ecfdf5; border:1px solid #a7f3d0; }
.nfbal-btn { padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:600; cursor:pointer;
             border:1px solid #d1d5db; background:#fff; color:#374151; }
.nfbal-btn.danger { color:#b91c1c; border-color:#fca5a5; }
.nfbal-btn.go { background:#059669; border-color:#059669; color:#fff; }
.nfbal-daysep td { background:#f9fafb; font-weight:700; font-size:11.5px; color:#374151; }
.nfbal-input { padding:6px 9px; border:1px solid #d1d5db; border-radius:6px; font-size:12.5px; }
.nfbal-scroll { overflow-x:auto; }
</style>
@endpush

@section('content')
<div class="container-fixed" style="padding-left:44px;">
    <div class="flex flex-wrap items-end justify-between gap-4 pb-5">
        <div>
            <h1 class="text-xl font-semibold leading-none text-foreground">💰 Customer Balances</h1>
            <div class="text-sm text-muted-foreground mt-2">
                Money customers have paid us beyond their invoices, held for their next order.
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="/customers" class="kt-btn kt-btn-sm kt-btn-outline">← All customers</a>
            <a href="/approvals/online" class="kt-btn kt-btn-sm kt-btn-outline">Online approvals</a>
        </div>
    </div>
</div>

<div class="container-fixed">

    @if(!$tableReady)
        <div class="nfbal-card" style="padding:16px; border-color:#fecaca; background:#fef2f2; margin-bottom:14px;">
            <b style="color:#991b1b;">Account balance is not set up on this server yet.</b>
            <div style="font-size:13px; color:#7f1d1d; margin-top:4px;">
                The customer-credit table is missing, so there is nothing to show.
                Run <code>customer_credit_bucket_aug2026.sql</code> first.
            </div>
        </div>
    @endif

    {{-- ── Totals strip ───────────────────────────────────────────────── --}}
    <div id="nfbalOverview" class="flex flex-wrap items-stretch gap-2 mb-4"></div>

    {{-- ── Tabs ───────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-2 mb-3">
        <button type="button" class="nfbal-tab is-on" data-tab="balances" onclick="nfBalTab('balances')">Balances</button>
        <button type="button" class="nfbal-tab" data-tab="activity" onclick="nfBalTab('activity')">Activity log</button>
        <button type="button" class="nfbal-tab" data-tab="daily" onclick="nfBalTab('daily')">Daily summary</button>
        <span style="flex:1;"></span>
        <span id="nfbalManageNote" style="font-size:11.5px; color:#6b7280;">
            @if($canManage)
                You can correct entries from this page.
            @else
                Read-only — correcting a balance needs approval rights.
            @endif
        </span>
    </div>

    {{-- ══ TAB A — Balances ══════════════════════════════════════════════ --}}
    <div id="nfbalPane-balances">
        <div class="nfbal-card" style="padding:10px 12px; margin-bottom:10px;">
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" id="nfbalSearch" class="nfbal-input" placeholder="Search name, phone or #id" style="min-width:220px;">

                <select id="nfbalBand" class="nfbal-input">
                    <option value="">Any balance</option>
                    <option value="1000">Rs 1,000 or more</option>
                    <option value="5000">Rs 5,000 or more</option>
                    <option value="20000">Rs 20,000 or more</option>
                </select>

                <input type="number" id="nfbalMin" class="nfbal-input" placeholder="Min" style="width:90px;">
                <input type="number" id="nfbalMax" class="nfbal-input" placeholder="Max" style="width:90px;">

                <select id="nfbalDays" class="nfbal-input">
                    <option value="">Any time</option>
                    <option value="7">Active in 7 days</option>
                    <option value="30">Active in 30 days</option>
                    <option value="90">Active in 90 days</option>
                </select>

                <select id="nfbalAddedBy" class="nfbal-input">
                    <option value="">Added by anyone</option>
                    @foreach($actors as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>

                <select id="nfbalSort" class="nfbal-input">
                    <option value="balance_desc">Balance ↓ highest</option>
                    <option value="balance_asc">Balance ↑ lowest</option>
                    <option value="last_added">Most recently added</option>
                    <option value="last_used">Most recently used</option>
                    <option value="name">Name A–Z</option>
                </select>

                <label style="font-size:12px; color:#374151; display:flex; align-items:center; gap:5px;">
                    <input type="checkbox" id="nfbalFlagged"> Needs review only
                </label>
                <label style="font-size:12px; color:#374151; display:flex; align-items:center; gap:5px;">
                    <input type="checkbox" id="nfbalZero"> Include zero balances
                </label>

                <button type="button" class="nfbal-btn" onclick="nfBalResetFilters()">Clear</button>
            </div>
        </div>

        <div class="nfbal-card nfbal-scroll">
            <table class="nfbal-tbl">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th style="text-align:right;">Balance</th>
                        <th style="text-align:right;">Awaiting</th>
                        <th>Last added</th>
                        <th>Last used</th>
                        <th style="text-align:center;">Entries</th>
                        <th>Review</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="nfbalBody">
                    <tr><td colspan="8" style="padding:24px; text-align:center; color:#9ca3af;">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="nfbalPager" class="flex items-center justify-between gap-3 mt-3"></div>
    </div>

    {{-- ══ TAB B — Activity log ══════════════════════════════════════════ --}}
    <div id="nfbalPane-activity" style="display:none;">
        <div class="nfbal-card" style="padding:10px 12px; margin-bottom:10px;">
            <div class="flex flex-wrap items-center gap-2">
                <label style="font-size:12px; color:#6b7280;">From</label>
                <input type="date" id="nfactFrom" class="nfbal-input">
                <label style="font-size:12px; color:#6b7280;">To</label>
                <input type="date" id="nfactTo" class="nfbal-input">

                <select id="nfactType" class="nfbal-input">
                    <option value="">All movements</option>
                    <option value="grant">Added</option>
                    <option value="consume">Used on an order</option>
                    <option value="adjust">Cleared / adjusted</option>
                </select>

                <select id="nfactSource" class="nfbal-input">
                    <option value="">Any source</option>
                    <option value="overpayment">Paid more than the order</option>
                    <option value="manual">Entered by hand</option>
                    <option value="cancellation">Order cancelled</option>
                    <option value="zero_out">Written off</option>
                </select>

                <select id="nfactStatus" class="nfbal-input">
                    <option value="">Any status</option>
                    <option value="counting">Counting now</option>
                    <option value="pending">Awaiting approval</option>
                    <option value="reserved">Held for an order</option>
                    <option value="active">Counted</option>
                    <option value="voided">Removed</option>
                </select>

                <select id="nfactActor" class="nfbal-input">
                    <option value="">Anyone</option>
                    @foreach($actors as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>

                <input type="number" id="nfactCustomer" class="nfbal-input" placeholder="Customer #" style="width:110px;">

                <label style="font-size:12px; color:#374151; display:flex; align-items:center; gap:5px;">
                    <input type="checkbox" id="nfactFlagged"> Needs review only
                </label>

                <button type="button" class="nfbal-btn" onclick="nfBalResetActivity()">Clear</button>
                <button type="button" class="nfbal-btn" onclick="nfBalExport()">Download CSV</button>
            </div>
            <div id="nfactScopeNote" style="margin-top:6px; font-size:11.5px; color:#6b7280;"></div>
        </div>

        <div class="nfbal-card nfbal-scroll">
            <table class="nfbal-tbl">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Customer</th>
                        <th>What happened</th>
                        <th style="text-align:right;">Amount</th>
                        <th>Order / invoice</th>
                        <th>Bank</th>
                        <th>By whom</th>
                        <th style="text-align:right;">Balance after</th>
                        <th>Review</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="nfactBody">
                    <tr><td colspan="10" style="padding:24px; text-align:center; color:#9ca3af;">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div id="nfactPager" class="flex items-center justify-between gap-3 mt-3"></div>
    </div>

    {{-- ══ TAB C — Daily summary ═════════════════════════════════════════ --}}
    <div id="nfbalPane-daily" style="display:none;">
        <div class="nfbal-card" style="padding:10px 12px; margin-bottom:10px;">
            <div class="flex flex-wrap items-center gap-2">
                <label style="font-size:12px; color:#6b7280;">From</label>
                <input type="date" id="nfdayFrom" class="nfbal-input">
                <label style="font-size:12px; color:#6b7280;">To</label>
                <input type="date" id="nfdayTo" class="nfbal-input">
                <button type="button" class="nfbal-btn" onclick="nfLoadDaily()">Show</button>
                <span style="font-size:11.5px; color:#6b7280;">Click a day to open its entries in the activity log.</span>
            </div>
        </div>

        <div class="nfbal-card nfbal-scroll">
            <table class="nfbal-tbl">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th style="text-align:right;">Added</th>
                        <th style="text-align:right;">Used</th>
                        <th style="text-align:right;">Cleared</th>
                        <th style="text-align:right;">Net</th>
                        <th style="text-align:right;">Held after that day</th>
                    </tr>
                </thead>
                <tbody id="nfdayBody">
                    <tr><td colspan="6" style="padding:24px; text-align:center; color:#9ca3af;">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Customer panel modal — the SAME panel as the customers screen ──── --}}
<div id="nfbalPanelModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; overflow-y:auto;">
    <div style="max-width:820px; margin:40px auto; background:#fff; border-radius:12px; overflow:hidden;">
        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div style="font-weight:700; color:#111827;" id="nfbalPanelTitle">Account balance</div>
            <button type="button" class="nfbal-btn" onclick="nfBalClosePanel()">Close</button>
        </div>
        <div id="nfbalPanelBody" style="padding:14px 16px; min-height:120px;"></div>
    </div>
</div>

<script>
{{-- 💰 The shared balance panel + every balance action, identical to the
     customers screen. Included INSIDE this <script> block on purpose — the
     partial is raw JS with no tags of its own. --}}
@include('partials.customer-credit-panel')

const NFBAL_CAN_MANAGE = @json($canManage);

let nfbalPage = 1, nfactPage = 1, nfbalTab = 'balances';
let nfbalSearchTimer = null;

function nfbalEsc(s) { return nfCredEsc(s); }
function nfbalMoney(n) {
    const v = Number(n || 0);
    return v.toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ─── Tabs ────────────────────────────────────────────────────────────────
function nfBalTab(tab) {
    nfbalTab = tab;
    document.querySelectorAll('.nfbal-tab').forEach(b => b.classList.toggle('is-on', b.dataset.tab === tab));
    ['balances', 'activity', 'daily'].forEach(t => {
        const pane = document.getElementById('nfbalPane-' + t);
        if (pane) pane.style.display = (t === tab) ? 'block' : 'none';
    });
    if (tab === 'balances') nfLoadBalances();
    if (tab === 'activity') nfLoadActivity();
    if (tab === 'daily') nfLoadDaily();
}

// ─── Totals strip ────────────────────────────────────────────────────────
async function nfLoadOverview() {
    let d;
    try {
        const res = await fetch('/customers/balances/overview', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (!json || !json.success) return;
        d = json.data;
    } catch (e) { return; }

    const rec = d.reconciliation || {};
    // Green only when the bucket and the books agree to the paisa. A red dot
    // here is the one thing on this page that means "stop and investigate".
    const recChip = rec.dormant
        ? '<span class="nfbal-chip muted">not set up</span>'
        : (rec.ok
            ? '<span class="nfbal-chip good">✓ matches the ledger</span>'
            : `<span class="nfbal-chip danger">off by Rs ${nfbalMoney(rec.difference)}</span>`);

    const reviewChip = d.flagged_count > 0
        ? `<span class="nfbal-chip danger">${d.flagged_count} to review</span>`
        : '<span class="nfbal-chip good">nothing flagged</span>';

    document.getElementById('nfbalOverview').innerHTML = `
        <div class="nfbal-stat">
            <div class="lbl">Held for customers</div>
            <div class="val" style="color:#065f46;">Rs ${nfbalMoney(d.held)}</div>
            <div style="font-size:11.5px; color:#6b7280;">${d.held_customers} customer${d.held_customers === 1 ? '' : 's'}</div>
        </div>
        <div class="nfbal-stat">
            <div class="lbl">Awaiting approval</div>
            <div class="val" style="color:${d.pending_count ? '#b45309' : '#9ca3af'};">Rs ${nfbalMoney(d.pending_total)}</div>
            <div style="font-size:11.5px; color:#6b7280;">${d.pending_count} entr${d.pending_count === 1 ? 'y' : 'ies'} · not counted yet</div>
        </div>
        <div class="nfbal-stat">
            <div class="lbl">This month</div>
            <div class="val" style="font-size:14px;">
                <span style="color:#059669;">+${nfbalMoney(d.added)}</span>
                <span style="color:#b45309;"> −${nfbalMoney(d.used)}</span>
            </div>
            <div style="font-size:11.5px; color:#6b7280;">added · used${Number(d.cleared) > 0 ? ' · cleared ' + nfbalMoney(d.cleared) : ''}</div>
        </div>
        <div class="nfbal-stat">
            <div class="lbl">Needs a look</div>
            <div class="val" style="font-size:14px; padding-top:3px;">${reviewChip}</div>
            <div style="font-size:11.5px; color:#6b7280;">entries that look wrong</div>
        </div>
        <div class="nfbal-stat">
            <div class="lbl">Books check</div>
            <div class="val" style="font-size:14px; padding-top:3px;">${recChip}</div>
            <div style="font-size:11.5px; color:#6b7280;">
                bucket Rs ${nfbalMoney(rec.bucket)} vs ledger Rs ${nfbalMoney(rec.ledger)}${Number(rec.reserved) > 0 ? ' · Rs ' + nfbalMoney(rec.reserved) + ' held for orders' : ''}
            </div>
        </div>`;
}

// ─── Tab A ───────────────────────────────────────────────────────────────
function nfBalFilters() {
    const band = document.getElementById('nfbalBand').value;
    const min  = document.getElementById('nfbalMin').value;
    const p = new URLSearchParams();
    if (min) p.set('min', min);
    else if (band) p.set('min', band);
    const max = document.getElementById('nfbalMax').value;
    if (max) p.set('max', max);
    const days = document.getElementById('nfbalDays').value;
    if (days) p.set('days', days);
    const by = document.getElementById('nfbalAddedBy').value;
    if (by) p.set('added_by', by);
    const search = document.getElementById('nfbalSearch').value.trim();
    if (search) p.set('search', search);
    p.set('sort', document.getElementById('nfbalSort').value);
    if (document.getElementById('nfbalFlagged').checked) p.set('flagged', '1');
    if (document.getElementById('nfbalZero').checked) p.set('include_zero', '1');
    p.set('page', nfbalPage);
    return p;
}

async function nfLoadBalances() {
    const body = document.getElementById('nfbalBody');
    try {
        const res = await fetch('/customers/balances/data?' + nfBalFilters().toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (!json || !json.success) throw new Error('bad response');
        const d = json.data;

        if (!d.rows.length) {
            body.innerHTML = '<tr><td colspan="8" style="padding:26px; text-align:center; color:#9ca3af;">No customer matches these filters.</td></tr>';
            document.getElementById('nfbalPager').innerHTML = '';
            return;
        }

        d.rows.forEach(r => { nfbalNames[r.customer_id] = r.name; });

        body.innerHTML = d.rows.map(r => {
            const la = r.last_added
                ? `<div>${nfbalEsc(r.last_added.date)}</div>
                   <div style="font-size:11.5px; color:#6b7280;">Rs ${nfbalMoney(r.last_added.amount)}${r.last_added.by ? ' · by ' + nfbalEsc(r.last_added.by) : ''}</div>
                   ${r.last_added.order ? `<div style="font-size:11.5px; color:#9ca3af;">${nfbalEsc(r.last_added.order)}${r.last_added.order_total !== null && r.last_added.order_total !== undefined ? ' · invoice Rs ' + nfbalMoney(r.last_added.order_total) : ''}</div>` : ''}`
                : '<span style="color:#9ca3af;">—</span>';
            const lu = r.last_used
                ? `<div>${nfbalEsc(r.last_used.date)}</div>
                   <div style="font-size:11.5px; color:#6b7280;">Rs ${nfbalMoney(r.last_used.amount)}${r.last_used.order ? ' · ' + nfbalEsc(r.last_used.order) : ''}${r.last_used.order_total !== null && r.last_used.order_total !== undefined ? ' · invoice Rs ' + nfbalMoney(r.last_used.order_total) : ''}</div>`
                : '<span style="color:#9ca3af;">—</span>';

            return `<tr>
                <td>
                    <div style="font-weight:600; color:#111827;">${nfbalEsc(r.name)}</div>
                    <div style="font-size:11.5px; color:#9ca3af;">#${r.customer_id}${r.phone ? ' · ' + nfbalEsc(r.phone) : ''}</div>
                </td>
                <td style="text-align:right; font-weight:700; color:${Number(r.balance) >= 0.01 ? '#065f46' : '#9ca3af'};">Rs ${nfbalMoney(r.balance)}</td>
                <td style="text-align:right; color:${r.pending_count ? '#b45309' : '#d1d5db'};">${r.pending_count ? 'Rs ' + nfbalMoney(r.pending_total) : '—'}</td>
                <td>${la}</td>
                <td>${lu}</td>
                <td style="text-align:center; color:#6b7280;">${r.entries}</td>
                <td>${r.flagged ? '<span class="nfbal-chip danger">check</span>' : ''}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <button type="button" class="nfbal-btn" onclick="nfBalOpenLog(${r.customer_id})">Log</button>
                    <button type="button" class="nfbal-btn go" onclick="nfBalOpenPanel(${r.customer_id})">Open</button>
                </td>
            </tr>`;
        }).join('');

        document.getElementById('nfbalPager').innerHTML = nfBalPagerHtml(d, 'nfBalGoto');
    } catch (e) {
        body.innerHTML = '<tr><td colspan="8" style="padding:26px; text-align:center; color:#b91c1c;">Could not load balances.</td></tr>';
    }
}

function nfBalPagerHtml(d, gotoFn) {
    const from = d.total === 0 ? 0 : ((d.page - 1) * d.per_page) + 1;
    const to   = Math.min(d.page * d.per_page, d.total);
    return `<div style="font-size:12px; color:#6b7280;">Showing ${from}–${to} of ${d.total}</div>
        <div style="display:flex; gap:6px;">
            <button type="button" class="nfbal-btn" ${d.page <= 1 ? 'disabled style="opacity:.45;"' : ''} onclick="${gotoFn}(${d.page - 1})">Previous</button>
            <span style="font-size:12px; color:#6b7280; padding:5px 4px;">Page ${d.page} of ${d.last_page}</span>
            <button type="button" class="nfbal-btn" ${d.page >= d.last_page ? 'disabled style="opacity:.45;"' : ''} onclick="${gotoFn}(${d.page + 1})">Next</button>
        </div>`;
}

function nfBalGoto(p) { nfbalPage = Math.max(1, p); nfLoadBalances(); }

function nfBalResetFilters() {
    ['nfbalSearch', 'nfbalMin', 'nfbalMax'].forEach(id => document.getElementById(id).value = '');
    ['nfbalBand', 'nfbalDays', 'nfbalAddedBy'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('nfbalSort').value = 'balance_desc';
    document.getElementById('nfbalFlagged').checked = false;
    document.getElementById('nfbalZero').checked = false;
    nfbalPage = 1;
    nfLoadBalances();
}

// ─── Tab B ───────────────────────────────────────────────────────────────
function nfActFilters() {
    const p = new URLSearchParams();
    const map = { from: 'nfactFrom', to: 'nfactTo', type: 'nfactType', source: 'nfactSource',
                  status: 'nfactStatus', actor: 'nfactActor', customer_id: 'nfactCustomer' };
    Object.keys(map).forEach(k => {
        const v = document.getElementById(map[k]).value;
        if (v) p.set(k, v);
    });
    if (document.getElementById('nfactFlagged').checked) p.set('flagged', '1');
    return p;
}

async function nfLoadActivity() {
    const body = document.getElementById('nfactBody');
    const p = nfActFilters();
    p.set('page', nfactPage);

    document.getElementById('nfactScopeNote').textContent = document.getElementById('nfactCustomer').value
        ? 'Filtered to one customer — the "balance after" column shows their running balance.'
        : 'Pick a customer number to see a running balance down the column.';

    try {
        const res = await fetch('/customers/balances/activity?' + p.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (!json || !json.success) throw new Error('bad response');
        const d = json.data;

        if (!d.rows.length) {
            body.innerHTML = '<tr><td colspan="10" style="padding:26px; text-align:center; color:#9ca3af;">Nothing matches these filters.</td></tr>';
            document.getElementById('nfactPager').innerHTML = '';
            return;
        }

        d.rows.forEach(r => { nfbalNames[r.customer_id] = r.customer_name; });

        let html = '', lastDay = null;
        d.rows.forEach(r => {
            // A day header carries that day's own totals, so a manager reading
            // down the log always knows what the day itself did.
            if (r.day !== lastDay) {
                lastDay = r.day;
                const s = d.days[r.day] || { added: 0, used: 0, cleared: 0, net: 0 };
                html += `<tr class="nfbal-daysep"><td colspan="10">
                    ${nfbalEsc(r.date)} &nbsp;·&nbsp;
                    added <span style="color:#059669;">Rs ${nfbalMoney(s.added)}</span> &nbsp;
                    used <span style="color:#b45309;">Rs ${nfbalMoney(s.used)}</span> &nbsp;
                    cleared <span style="color:#6b7280;">Rs ${nfbalMoney(s.cleared)}</span> &nbsp;
                    net <b style="color:${Number(s.net) >= 0 ? '#059669' : '#b45309'};">Rs ${nfbalMoney(s.net)}</b>
                    <span style="color:#9ca3af; font-weight:400;"> (on this page)</span>
                </td></tr>`;
            }

            const struck = r.counts ? '' : 'text-decoration:line-through; opacity:.6;';
            const flags = (r.flags || []).map(f =>
                `<span class="nfbal-chip ${f.level}" title="${nfbalEsc(f.label)}">${nfbalEsc(f.label)}</span>`
            ).join(' ');

            const who = [
                r.entered_by ? 'by ' + nfbalEsc(r.entered_by) : null,
                (r.approved_by && r.approved_by !== r.entered_by) ? 'approved ' + nfbalEsc(r.approved_by) : null,
                r.voided_by ? 'removed ' + nfbalEsc(r.voided_by) : null
            ].filter(Boolean).join('<br>');

            let actions = '';
            if (NFBAL_CAN_MANAGE) {
                if (r.status === 'pending') {
                    actions = `<button type="button" class="nfbal-btn go" onclick="nfApproveCredit(${r.customer_id}, ${r.id})">Approve</button>
                               <button type="button" class="nfbal-btn danger" onclick="nfRejectCredit(${r.customer_id}, ${r.id})">Reject</button>`;
                } else if (r.counts && r.entry_type === 'grant') {
                    actions = `<button type="button" class="nfbal-btn danger"
                                 title="Undo just this entry — the rest of the balance is untouched"
                                 onclick="nfVoidCredit(${r.customer_id}, ${r.id}, '${nfbalMoney(r.amount_abs)}')">Remove</button>`;
                }
            }

            html += `<tr>
                <td style="white-space:nowrap; color:#6b7280; font-size:12px;">${nfbalEsc(r.date_full)}</td>
                <td>
                    <a href="#" onclick="nfBalOpenPanel(${r.customer_id}); return false;"
                       style="color:#1d4ed8; font-weight:600;">${nfbalEsc(r.customer_name)}</a>
                    <div style="font-size:11px; color:#9ca3af;">#${r.customer_id}</div>
                </td>
                <td style="${struck}">
                    ${nfbalEsc(r.type_label)}
                    <div style="font-size:11.5px; color:#6b7280;">${nfbalEsc(r.status_label)} · ${nfbalEsc(r.source_label)}</div>
                    ${r.reason ? `<div style="font-size:11.5px; color:#6b7280;">${nfbalEsc(r.reason)}</div>` : ''}
                    ${r.voided_reason ? `<div style="font-size:11.5px; color:#b45309;">Removed — ${nfbalEsc(r.voided_reason)}</div>` : ''}
                </td>
                <td style="text-align:right; white-space:nowrap; font-weight:700; ${struck} color:${r.is_credit ? '#059669' : '#b45309'};">
                    ${r.is_credit ? '+' : '−'} Rs ${nfbalMoney(r.amount_abs)}
                </td>
                <td style="white-space:nowrap;">
                    ${r.order_number ? nfbalEsc(r.order_number) : '<span style="color:#d1d5db;">—</span>'}
                    ${r.order_total !== null && r.order_total !== undefined
                        ? `<div style="font-size:11.5px; color:#6b7280;">invoice Rs ${nfbalMoney(r.order_total)}</div>` : ''}
                    ${r.implied_paid !== null && r.implied_paid !== undefined
                        ? `<div style="font-size:11.5px; color:#9ca3af;">implies paid Rs ${nfbalMoney(r.implied_paid)}</div>` : ''}
                </td>
                <td style="white-space:nowrap; font-size:12px; color:#6b7280;">${r.bank ? nfbalEsc(r.bank) : '—'}</td>
                <td style="font-size:11.5px; color:#6b7280;">${who || '—'}</td>
                <td style="text-align:right; white-space:nowrap; font-size:12px;">
                    ${r.running_balance !== null && r.running_balance !== undefined
                        ? 'Rs ' + nfbalMoney(r.running_balance)
                        : '<span style="color:#9ca3af;">Rs ' + nfbalMoney(r.balance_now) + ' now</span>'}
                </td>
                <td>${flags}</td>
                <td style="text-align:right; white-space:nowrap;">${actions}</td>
            </tr>`;
        });

        body.innerHTML = html;
        document.getElementById('nfactPager').innerHTML = nfBalPagerHtml(d, 'nfActGoto');
    } catch (e) {
        body.innerHTML = '<tr><td colspan="10" style="padding:26px; text-align:center; color:#b91c1c;">Could not load the activity log.</td></tr>';
    }
}

function nfActGoto(p) { nfactPage = Math.max(1, p); nfLoadActivity(); }

function nfBalResetActivity() {
    ['nfactFrom', 'nfactTo', 'nfactCustomer'].forEach(id => document.getElementById(id).value = '');
    ['nfactType', 'nfactSource', 'nfactStatus', 'nfactActor'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('nfactFlagged').checked = false;
    nfactPage = 1;
    nfLoadActivity();
}

function nfBalExport() {
    window.location.href = '/customers/balances/export?' + nfActFilters().toString();
}

/** Jump from a customer row straight into their own log. */
function nfBalOpenLog(customerId) {
    document.getElementById('nfactCustomer').value = customerId;
    nfactPage = 1;
    nfBalTab('activity');
}

// ─── Tab C ───────────────────────────────────────────────────────────────
async function nfLoadDaily() {
    const body = document.getElementById('nfdayBody');
    const p = new URLSearchParams();
    const f = document.getElementById('nfdayFrom').value, t = document.getElementById('nfdayTo').value;
    if (f) p.set('from', f);
    if (t) p.set('to', t);

    try {
        const res = await fetch('/customers/balances/daily?' + p.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (!json || !json.success) throw new Error('bad response');
        const d = json.data;

        if (!d.rows.length) {
            body.innerHTML = '<tr><td colspan="6" style="padding:26px; text-align:center; color:#9ca3af;">Nothing moved in this period.</td></tr>';
            return;
        }

        body.innerHTML = d.rows.map(r => `<tr style="cursor:pointer;" onclick="nfDayDrill('${r.day}')">
            <td style="font-weight:600;">${nfbalEsc(r.day)}</td>
            <td style="text-align:right; color:#059669;">${Number(r.added) ? 'Rs ' + nfbalMoney(r.added) : '—'}</td>
            <td style="text-align:right; color:#b45309;">${Number(r.used) ? 'Rs ' + nfbalMoney(r.used) : '—'}</td>
            <td style="text-align:right; color:#6b7280;">${Number(r.cleared) ? 'Rs ' + nfbalMoney(r.cleared) : '—'}</td>
            <td style="text-align:right; font-weight:700; color:${Number(r.net) >= 0 ? '#059669' : '#b45309'};">Rs ${nfbalMoney(r.net)}</td>
            <td style="text-align:right; font-weight:700;">Rs ${nfbalMoney(r.held)}</td>
        </tr>`).join('')
        + `<tr><td colspan="6" style="font-size:11.5px; color:#6b7280; background:#f9fafb;">
             Held before ${nfbalEsc(d.from)}: Rs ${nfbalMoney(d.opening)}
           </td></tr>`;
    } catch (e) {
        body.innerHTML = '<tr><td colspan="6" style="padding:26px; text-align:center; color:#b91c1c;">Could not load the daily summary.</td></tr>';
    }
}

function nfDayDrill(day) {
    document.getElementById('nfactFrom').value = day;
    document.getElementById('nfactTo').value = day;
    document.getElementById('nfactCustomer').value = '';
    nfactPage = 1;
    nfBalTab('activity');
}

// ─── The shared panel, in a modal ────────────────────────────────────────
/**
 * ⚠ The customer NAME is never passed through the onclick attribute. Names
 * carry apostrophes ("Mrs O'Brien"), the escaper turns one into &#39;, the HTML
 * parser turns it back into a quote before JS ever sees it, and the handler
 * stops parsing. The id travels in the attribute; the name is looked up here.
 */
const nfbalNames = {};

async function nfBalOpenPanel(customerId) {
    const name = nfbalNames[customerId] || '';
    document.getElementById('nfbalPanelTitle').textContent = name ? (name + ' — account balance') : 'Account balance';
    const bodyEl = document.getElementById('nfbalPanelBody');
    bodyEl.innerHTML = '<div style="padding:20px; text-align:center; color:#9ca3af;">Loading…</div>';
    document.getElementById('nfbalPanelModal').style.display = 'block';

    // The SAME renderer the customers screen uses, so every button, rule and
    // wording is identical on both pages by construction.
    bodyEl.innerHTML = '';
    await nfRenderCustomerCredit(customerId, bodyEl);
    if (!bodyEl.children.length) {
        bodyEl.innerHTML = '<div style="padding:20px; color:#6b7280;">This customer cannot hold an account balance (shop customers settle through their invoices).</div>';
    }
}

function nfBalClosePanel() {
    document.getElementById('nfbalPanelModal').style.display = 'none';
    document.getElementById('nfbalPanelBody').innerHTML = '';
}

document.getElementById('nfbalPanelModal').addEventListener('click', function (e) {
    if (e.target === this) nfBalClosePanel();
});

// ⭐ The hook the shared partial calls after ANY successful correction, from
// either the modal panel or an inline button. One place, so the totals strip
// and whichever tab is open can never be left showing the old number.
window.nfCreditOnChange = async function () {
    await nfLoadOverview();
    if (nfbalTab === 'balances') await nfLoadBalances();
    if (nfbalTab === 'activity') await nfLoadActivity();
    if (nfbalTab === 'daily') await nfLoadDaily();
};

// ─── Wiring ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    ['nfbalBand', 'nfbalDays', 'nfbalAddedBy', 'nfbalSort'].forEach(id =>
        document.getElementById(id).addEventListener('change', () => { nfbalPage = 1; nfLoadBalances(); }));
    ['nfbalFlagged', 'nfbalZero'].forEach(id =>
        document.getElementById(id).addEventListener('change', () => { nfbalPage = 1; nfLoadBalances(); }));
    ['nfbalMin', 'nfbalMax'].forEach(id =>
        document.getElementById(id).addEventListener('change', () => { nfbalPage = 1; nfLoadBalances(); }));
    document.getElementById('nfbalSearch').addEventListener('input', function () {
        clearTimeout(nfbalSearchTimer);
        nfbalSearchTimer = setTimeout(() => { nfbalPage = 1; nfLoadBalances(); }, 300);
    });

    ['nfactFrom', 'nfactTo', 'nfactType', 'nfactSource', 'nfactStatus', 'nfactActor', 'nfactCustomer', 'nfactFlagged']
        .forEach(id => document.getElementById(id).addEventListener('change', () => { nfactPage = 1; nfLoadActivity(); }));

    // Deep links: /customers/balances?tab=activity&customer_id=123
    const qs = new URLSearchParams(window.location.search);
    if (qs.get('customer_id')) document.getElementById('nfactCustomer').value = qs.get('customer_id');
    if (qs.get('flagged')) {
        document.getElementById('nfactFlagged').checked = true;
        document.getElementById('nfbalFlagged').checked = true;
    }

    nfLoadOverview();
    nfBalTab(qs.get('tab') === 'activity' ? 'activity' : (qs.get('tab') === 'daily' ? 'daily' : 'balances'));
});
</script>
@endsection
