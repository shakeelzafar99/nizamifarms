@extends('layouts.app')

@section('title', 'Banks')

@section('content')
<div class="container-fluid px-6 py-6">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">🏦 Banks</h1>
            <p class="text-gray-600 mt-2">Current balance in each online bank — one window, split by bank.</p>
        </div>
        <div style="display:flex; align-items:center; gap:12px;">
            @if($isTaimur ?? false)
                <button type="button" onclick="mbOpenManageBanks()" style="background:#F0F9FF; color:#0369A1; font-weight:600; padding:8px 16px; border-radius:8px; border:1px solid #BAE6FD; cursor:pointer; font-size:13px;">🏦 Manage Banks</button>
            @endif
            <a href="{{ route('approvals.online') }}" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                ← Online Approvals
            </a>
        </div>
    </div>

    {{-- Online pool to distribute: the ONLINE chart-of-accounts balances that
         should be split across the physical banks below. --}}
    <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-radius:12px; padding:16px; margin-bottom:20px; display:flex; flex-wrap:wrap; align-items:center; gap:24px;">
        <div>
            <div style="font-size:12px; color:#1E40AF; font-weight:600; text-transform:uppercase; letter-spacing:.03em;">💧 Online ledger balance — to distribute across banks</div>
            <div style="font-size:28px; font-weight:800; color:{{ $ledgerOnlineBalance < 0 ? '#B91C1C' : '#1D4ED8' }};">Rs. {{ number_format($ledgerOnlineBalance, 0) }}</div>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            @foreach($onlineAccounts as $acct)
                <div style="background:#fff; border:1px solid #DBEAFE; border-radius:10px; padding:8px 12px;">
                    <div style="font-size:11px; color:#6B7280;">{{ $acct['name'] }}</div>
                    <div style="font-size:15px; font-weight:700; color:{{ $acct['balance'] < 0 ? '#B91C1C' : '#111827' }};">Rs. {{ number_format($acct['balance'], 0) }}</div>
                </div>
            @endforeach
        </div>
        <div style="flex-basis:100%; font-size:11px; color:#3B82F6;">
            This is the money the ledger says is "online". Your bank cards below should add up to it (plus the "No bank" bucket until history is tagged).
        </div>
    </div>

    {{-- Active / All / Inactive toggle --}}
    <div style="display:flex; gap:6px; margin-bottom:16px;">
        @php
            $toggle = function ($key, $label) use ($status) {
                $active = $status === $key;
                $url = request()->fullUrlWithQuery(['status' => $key === 'active' ? null : $key]);
                return '<a href="' . e($url) . '" style="padding:6px 14px; border-radius:16px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid ' . ($active ? '#2563EB' : '#D1D5DB') . '; background:' . ($active ? '#2563EB' : '#fff') . '; color:' . ($active ? '#fff' : '#374151') . ';">' . $label . '</a>';
            };
        @endphp
        {!! $toggle('active', 'Active (' . $activeCount . ')') !!}
        {!! $toggle('all', 'All (' . ($activeCount + $inactiveCount) . ')') !!}
        @if($inactiveCount > 0)
            {!! $toggle('inactive', 'Inactive (' . $inactiveCount . ')') !!}
        @endif
    </div>

    {{-- Per-bank cards --}}
    @if(count($banks) === 0)
        <div class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
            @if($status === 'inactive')
                No inactive banks.
            @elseif($activeCount + $inactiveCount === 0)
                No banks configured yet. Add them from Online Approvals → Manage Banks.
            @else
                No {{ $status === 'active' ? 'active' : '' }} banks to show.
            @endif
        </div>
    @else
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:16px;">
            @foreach($banks as $b)
                <div onclick="openBankStatement({{ $b['id'] }}, '{{ addslashes($b['short_code']) }}', {{ $b['balance'] }})"
                     style="cursor:pointer; background:#fff; border:1px solid #E5E7EB; border-left:4px solid {{ $b['color_hex'] }}; border-radius:12px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,0.04); {{ $b['is_active'] ? '' : 'opacity:0.55;' }}"
                     title="Click for the last 30 days">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                        <span style="font-weight:700; color:#111827; font-size:15px;">{{ $b['name'] }}</span>
                        <span style="background:#F3F4F6; color:#6B7280; padding:1px 6px; border-radius:4px; font-size:11px; font-weight:600;">{{ $b['short_code'] }}</span>
                        @if($b['account_last4'])
                            <span style="background:#EFF6FF; color:#1D4ED8; padding:1px 6px; border-radius:4px; font-size:11px; font-weight:600;">&bull;&bull;{{ $b['account_last4'] }}</span>
                        @endif
                    </div>
                    <div style="font-size:24px; font-weight:800; color:{{ $b['balance'] < 0 ? '#B91C1C' : '#065F46' }};">
                        Rs. {{ number_format($b['balance'], 0) }}
                    </div>
                    <div style="font-size:11px; color:#9CA3AF; margin-top:6px;">
                        @if($b['opening_balance_date'])
                            Opening Rs. {{ number_format($b['opening_balance'], 0) }} · {{ $b['opening_balance_date'] }}
                            @if($b['net_movement'] != 0)
                                <br>{{ $b['net_movement'] >= 0 ? '+' : '−' }} Rs. {{ number_format(abs($b['net_movement']), 0) }} since
                            @endif
                        @else
                            <span style="color:#B45309;">No opening balance set — edit in Manage Banks</span>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- "No bank" — untagged online movements to check and fix --}}
            <div onclick="openBankStatement('unassigned', 'No bank')"
                 style="cursor:pointer; background:#FFFBEB; border:1px dashed #F59E0B; border-left:4px solid #F59E0B; border-radius:12px; padding:16px;"
                 title="Online money moved without a bank tag — click to review and fix">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <span style="font-weight:700; color:#92400E; font-size:15px;">⚠️ No bank</span>
                    @if(($unassignedCount ?? 0) > 0)
                        <span style="background:#FEF3C7; color:#92400E; padding:1px 6px; border-radius:4px; font-size:11px; font-weight:700;">{{ $unassignedCount }} entries</span>
                    @endif
                    @if($isTaimur ?? false)
                        <span style="margin-left:auto;"></span>
                        <button type="button" onclick="event.stopPropagation(); openTrackingSince();" title="Set the date to start tracking untagged online money from" style="background:none; border:none; cursor:pointer; font-size:14px; color:#92400E; padding:0;">⚙</button>
                    @endif
                </div>
                <div style="font-size:24px; font-weight:800; color:{{ $unassigned < 0 ? '#B91C1C' : '#92400E' }};">
                    Rs. {{ number_format($unassigned, 0) }}
                </div>
                <div style="font-size:11px; color:#B45309; margin-top:6px;">
                    @if($unassignedSince ?? null)
                        Tracking from {{ $unassignedSince }} · older untagged money is treated as history.
                    @else
                        Online movements without a bank tag — open to review{{ ($isTaimur ?? false) ? ' and assign banks. Use ⚙ to only track from a chosen date.' : '.' }}
                    @endif
                </div>
            </div>
        </div>

        {{-- Reconciliation / honesty line --}}
        <div style="margin-top:20px; background:#F9FAFB; border:1px solid #E5E7EB; border-radius:12px; padding:16px;">
            <div style="display:flex; flex-wrap:wrap; gap:24px; font-size:13px;">
                <div>
                    <div style="color:#6B7280;">Tracked across banks</div>
                    <div style="font-weight:700; color:#111827;">Rs. {{ number_format($sumBalances, 0) }}</div>
                </div>
                <div>
                    <div style="color:#6B7280;">Unassigned online <span title="Online money received/spent with no bank tagged (mostly historical rows from before per-bank tracking).">ⓘ</span></div>
                    <div style="font-weight:700; color:{{ $unassigned < 0 ? '#B91C1C' : '#111827' }};">Rs. {{ number_format($unassigned, 0) }}</div>
                </div>
                <div>
                    <div style="color:#6B7280;">Ledger online balance <span title="Sum of the ONLINE (and Qurbani online) chart-of-accounts balances the app already maintains.">ⓘ</span></div>
                    <div style="font-weight:700; color:#111827;">Rs. {{ number_format($ledgerOnlineBalance, 0) }}</div>
                </div>
            </div>
            <p style="font-size:11px; color:#9CA3AF; margin-top:10px;">
                Once every bank has an opening balance and all online movements are tagged, the tracked
                figure plus unassigned should track the ledger online balance. A growing gap means some
                online movements are missing a bank tag. Manual ⚖ adjustments deliberately move a bank's
                figure toward its REAL statement, so they widen this gap by design — that's the
                correction, not an error.
            </p>
        </div>
    @endif
</div>

{{-- Statement modal (per-bank or "No bank") --}}
<div id="bankStmtModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; padding:20px;" onclick="if(event.target===this) closeBankStatement()">
    <div style="background:#fff; border-radius:14px; width:100%; max-width:760px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden;" onclick="event.stopPropagation()">
        <div style="padding:16px 20px; border-bottom:1px solid #E5E7EB; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <h3 id="bankStmtTitle" style="font-size:16px; font-weight:700; color:#111827; margin:0;">Statement</h3>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="bankAdjustBtn" onclick="toggleAdjustForm()" style="display:none; padding:4px 10px; border-radius:8px; border:1px solid #FCD34D; background:#FFFBEB; color:#92400E; font-size:12px; font-weight:600; cursor:pointer;">⚖ Adjust</button>
                <span id="bankStmtPeriods" style="display:flex; gap:4px;"></span>
                <button onclick="closeBankStatement()" style="background:none; border:none; font-size:22px; color:#9CA3AF; cursor:pointer; margin-left:8px;">&times;</button>
            </div>
        </div>
        {{-- ⚖ Manual adjustment form (Taimur only, per-bank view only) --}}
        <div id="bankAdjustForm" style="display:none; padding:12px 20px; background:#FFFBEB; border-bottom:1px solid #FDE68A;">
            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:600; color:#92400E; margin-bottom:2px;">Adjust by (±)</label>
                    <input type="number" step="0.01" id="adjAmount" placeholder="-698000 or 5000" style="width:150px; padding:6px 8px; border:1px solid #FCD34D; border-radius:6px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:600; color:#92400E; margin-bottom:2px;">…or set balance to</label>
                    <input type="number" step="0.01" id="adjTarget" placeholder="real bank balance" oninput="adjTargetChanged()" style="width:150px; padding:6px 8px; border:1px solid #FCD34D; border-radius:6px; font-size:13px;">
                </div>
                <div style="flex:1; min-width:160px;">
                    <label style="display:block; font-size:11px; font-weight:600; color:#92400E; margin-bottom:2px;">Note</label>
                    <input type="text" id="adjNote" maxlength="255" placeholder="e.g. initial true-up to bank statement" style="width:100%; padding:6px 8px; border:1px solid #FCD34D; border-radius:6px; font-size:13px;">
                </div>
                <button onclick="saveAdjustment()" style="padding:7px 14px; background:#B45309; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer;">Save</button>
            </div>
            <p style="font-size:11px; color:#B45309; margin:6px 0 0;">Shows as an "adjustment" entry in this bank's statement. Attribution only — never touches the ledger or real money. Deletable if mis-entered.</p>
        </div>
        <div id="bankStmtBody" style="padding:16px 20px; overflow-y:auto;">
            <div style="text-align:center; color:#9CA3AF;">Loading…</div>
        </div>
    </div>
</div>

<script>
function fmtMoney(n) { return 'Rs. ' + Math.round(Number(n) || 0).toLocaleString(); }
function escBank(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// Active banks (for the "assign bank" fix action on No-bank rows).
const stmtBanks = @json(collect($banks ?? [])->where('is_active', true)->map(fn ($b) => ['id' => $b['id'], 'short_code' => $b['short_code']])->values());
const stmtIsTaimur = @json($isTaimur ?? false);
const PERIODS = [{d:30, label:'30d'}, {d:90, label:'90d'}, {d:365, label:'1y'}, {d:0, label:'All'}];

let stmtCurrentId = null;    // bank id or 'unassigned'
let stmtCurrentCode = '';
let stmtCurrentDays = 30;
let stmtCurrentBalance = null; // card balance — used by "set balance to"

function openBankStatement(id, code, balance) {
    stmtCurrentId = id;
    stmtCurrentCode = code;
    stmtCurrentDays = 30;
    stmtCurrentBalance = (balance === undefined || balance === null) ? null : Number(balance);
    // ⚖ Adjust is Taimur-only and per-bank only (not for the No-bank view).
    const adjBtn = document.getElementById('bankAdjustBtn');
    if (adjBtn) adjBtn.style.display = (stmtIsTaimur && id !== 'unassigned') ? 'inline-block' : 'none';
    const adjForm = document.getElementById('bankAdjustForm');
    if (adjForm) adjForm.style.display = 'none';
    document.getElementById('bankStmtModal').style.display = 'flex';
    loadBankStatement();
}

function toggleAdjustForm() {
    const form = document.getElementById('bankAdjustForm');
    if (!form) return;
    const show = form.style.display === 'none';
    form.style.display = show ? 'block' : 'none';
    if (show) {
        document.getElementById('adjAmount').value = '';
        document.getElementById('adjTarget').value = '';
        document.getElementById('adjNote').value = '';
    }
}

// Typing a target balance auto-fills the +/- delta from the card balance.
function adjTargetChanged() {
    const target = parseFloat(document.getElementById('adjTarget').value);
    if (!isNaN(target) && stmtCurrentBalance !== null) {
        document.getElementById('adjAmount').value = (target - stmtCurrentBalance).toFixed(2);
    }
}

async function saveAdjustment() {
    const amount = parseFloat(document.getElementById('adjAmount').value);
    if (isNaN(amount) || amount === 0) {
        alert('Enter a non-zero adjustment amount (or type the target balance).');
        return;
    }
    const note = document.getElementById('adjNote').value.trim();
    try {
        const res = await fetch(`/finance/bank-balances/${stmtCurrentId}/adjustments`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'
            },
            body: JSON.stringify({ amount: amount, note: note || null })
        });
        const data = await res.json();
        if (data.success) {
            window._bankFixed = true; // reload cards when the modal closes
            if (stmtCurrentBalance !== null) stmtCurrentBalance += amount;
            toggleAdjustForm();
            loadBankStatement();
        } else {
            alert(data.message || 'Could not save adjustment.');
        }
    } catch (e) {
        alert('Could not save adjustment.');
    }
}

async function deleteAdjustment(adjustmentId, amount) {
    if (!confirm('Remove this balance adjustment? The bank balance will change back.')) return;
    try {
        const res = await fetch(`/finance/bank-balances/adjustments/${adjustmentId}/delete`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'
            }
        });
        const data = await res.json();
        if (data.success) {
            window._bankFixed = true;
            if (stmtCurrentBalance !== null && amount !== undefined) stmtCurrentBalance -= amount;
            loadBankStatement();
        } else {
            alert(data.message || 'Could not remove adjustment.');
        }
    } catch (e) {
        alert('Could not remove adjustment.');
    }
}

function setStmtPeriod(days) {
    stmtCurrentDays = days;
    loadBankStatement();
}

function renderStmtPeriods() {
    const box = document.getElementById('bankStmtPeriods');
    box.innerHTML = PERIODS.map(p =>
        `<button onclick="setStmtPeriod(${p.d})" style="padding:4px 10px; border-radius:8px; border:1px solid ${p.d === stmtCurrentDays ? '#2563EB' : '#D1D5DB'}; background:${p.d === stmtCurrentDays ? '#2563EB' : '#fff'}; color:${p.d === stmtCurrentDays ? '#fff' : '#374151'}; font-size:12px; font-weight:600; cursor:pointer;">${p.label}</button>`
    ).join('');
}

function loadBankStatement() {
    const body = document.getElementById('bankStmtBody');
    const isUnassigned = stmtCurrentId === 'unassigned';
    document.getElementById('bankStmtTitle').textContent =
        stmtCurrentCode + ' — ' + (stmtCurrentDays === 0 ? 'all history' : 'last ' + stmtCurrentDays + ' days');
    renderStmtPeriods();
    body.innerHTML = '<div style="text-align:center; color:#9CA3AF;">Loading…</div>';

    const url = isUnassigned
        ? `/finance/bank-balances/unassigned/transactions?days=${stmtCurrentDays}`
        : `/finance/bank-balances/${stmtCurrentId}/transactions?days=${stmtCurrentDays}`;

    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (!res || !res.success) { body.innerHTML = '<div style="color:#B91C1C; text-align:center;">Could not load.</div>'; return; }
            const rows = res.transactions || [];
            let html = `<div style="display:flex; gap:20px; margin-bottom:12px; font-size:13px;">
                <div><span style="color:#6B7280;">In</span> <b style="color:#065F46;">${fmtMoney(res.total_in)}</b></div>
                <div><span style="color:#6B7280;">Out</span> <b style="color:#B91C1C;">${fmtMoney(res.total_out)}</b></div>
                <div><span style="color:#6B7280;">Net</span> <b>${fmtMoney((res.total_in||0)-(res.total_out||0))}</b></div>
                <div style="margin-left:auto; color:#9CA3AF;">${rows.length} entr${rows.length === 1 ? 'y' : 'ies'}</div>
            </div>`;
            if (isUnassigned && rows.length > 0) {
                html += `<div style="font-size:12px; color:#B45309; background:#FFFBEB; border:1px solid #FDE68A; border-radius:8px; padding:8px 10px; margin-bottom:10px;">
                    These online movements have no bank tag — their money is in the total but not attributed to any bank.${stmtIsTaimur ? ' Pick a bank on a row to fix it.' : ''}
                </div>`;
            }
            if (rows.length === 0) {
                html += '<div style="text-align:center; color:#9CA3AF; padding:20px;">No transactions in this period.</div>';
            } else {
                html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
                rows.forEach(t => {
                    const isIn = t.direction === 'in';
                    const sign = isIn ? '+' : (t.direction === 'out' ? '−' : '');
                    const color = isIn ? '#065F46' : (t.direction === 'out' ? '#B91C1C' : '#6B7280');
                    // Adjustments get an amber chip + (Taimur) a remove ✕.
                    const typeChip = t.is_adjustment
                        ? `<span style="display:inline-block; background:#FFFBEB; color:#92400E; border:1px solid #FDE68A; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:600; white-space:nowrap;">⚖ adjustment</span>`
                        : `<span style="display:inline-block; background:#F3F4F6; color:#6B7280; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:600; white-space:nowrap;">${escBank(t.type || '')}</span>`;
                    const adjDelete = (t.is_adjustment && stmtIsTaimur)
                        ? ` <button onclick="deleteAdjustment(${t.adjustment_id}, ${isIn ? t.amount : -t.amount})" title="Remove this adjustment" style="border:none; background:#FEF2F2; color:#B91C1C; border-radius:4px; padding:0 5px; font-size:11px; cursor:pointer;">✕</button>`
                        : '';
                    // Counterparty chip (vendor/employee) — resolved from the
                    // ledger accounts, so old rows with free-text descriptions
                    // still show WHO was paid.
                    const whoChip = t.counterparty
                        ? ` <span style="display:inline-block; background:#EEF2FF; color:#4338CA; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:600; white-space:nowrap;">→ ${escBank(t.counterparty)}</span>`
                        : '';
                    // "Set bank" fix action — only on No-bank rows, Taimur only.
                    let fixCell = '';
                    if (isUnassigned && stmtIsTaimur) {
                        const opts = stmtBanks.map(b => `<option value="${b.id}">${escBank(b.short_code)}</option>`).join('');
                        fixCell = `<td style="padding:6px 4px; white-space:nowrap;">
                            <select id="fixBank-${t.id}" style="font-size:11px; padding:2px 4px; border:1px solid #D1D5DB; border-radius:6px;">
                                <option value="">bank…</option>${opts}
                            </select>
                            <button onclick="assignRowBank(${t.id})" style="font-size:11px; padding:2px 8px; border:1px solid #A7F3D0; background:#ECFDF5; color:#065F46; border-radius:6px; cursor:pointer;">Set</button>
                        </td>`;
                    }
                    html += `<tr style="border-bottom:1px solid #F3F4F6;">
                        <td style="padding:6px 4px; color:#6B7280; white-space:nowrap;">${t.date || ''}</td>
                        <td style="padding:6px 4px; white-space:nowrap;">${typeChip}${whoChip}</td>
                        <td style="padding:6px 4px; color:#374151;">${escBank(t.description || '')}</td>
                        <td style="padding:6px 4px; text-align:right; font-weight:600; color:${color}; white-space:nowrap;">${sign} ${fmtMoney(t.amount)}${adjDelete}</td>
                        ${fixCell}
                    </tr>`;
                });
                html += '</table>';
            }
            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<div style="color:#B91C1C; text-align:center;">Could not load.</div>'; });
}

// Fix an untagged row: assign its receiving bank, then refresh statement + page
// totals (reload keeps the per-bank cards honest).
async function assignRowBank(ledgerId) {
    const sel = document.getElementById(`fixBank-${ledgerId}`);
    const bankId = sel ? sel.value : '';
    if (!bankId) { alert('Pick a bank first.'); return; }
    try {
        const res = await fetch(`/finance/bank-balances/assign/${ledgerId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'
            },
            body: JSON.stringify({ receiving_account_id: parseInt(bankId) })
        });
        const data = await res.json();
        if (data.success) {
            window._bankFixed = true; // reload cards when the modal closes
            loadBankStatement();
        } else {
            alert(data.message || 'Could not assign bank.');
        }
    } catch (e) {
        alert('Could not assign bank.');
    }
}

function closeBankStatement() {
    document.getElementById('bankStmtModal').style.display = 'none';
    // Balances may have moved between banks after fixes — refresh the cards.
    if (window._bankFixed) { window.location.reload(); }
}

// ⚙ Set (or clear) the date from which to track the No-bank bucket.
function openTrackingSince() {
    const current = @json($unassignedSince ?? '');
    const val = prompt(
        'Track untagged online money from which date? (YYYY-MM-DD)\nLeave blank to count all history.',
        current || ''
    );
    if (val === null) return; // cancelled
    const date = val.trim();
    fetch('{{ route("fin.bank-balances.unassigned.since") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}'
        },
        body: JSON.stringify({ since: date || null })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { window.location.reload(); }
        else { alert(d.message || 'Could not save the date.'); }
    })
    .catch(() => alert('Could not save the date.'));
}
</script>

@if($isTaimur ?? false)
    @include('fin.partials.manage-banks-modal')
@endif
@endsection
