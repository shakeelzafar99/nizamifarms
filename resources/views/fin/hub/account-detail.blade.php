@extends('layouts.app')
@section('title', 'Ledger Hub — ' . $account->account_name)
@include('fin.hub.partials.styles')

@php
    $typeLabels = [
        \App\Models\FIN\LedgerModel::TYPE_INVOICE => 'Invoice', \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT => 'Order Payment',
        \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT => 'Deposit', \App\Models\FIN\LedgerModel::TYPE_EXPENSE => 'Expense',
        \App\Models\FIN\LedgerModel::TYPE_VENDOR_PURCHASE => 'Vendor Purchase', \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT => 'Vendor Payment',
        \App\Models\FIN\LedgerModel::TYPE_SETTLEMENT => 'Settlement', \App\Models\FIN\LedgerModel::TYPE_TRANSFER => 'Transfer',
        \App\Models\FIN\LedgerModel::TYPE_ADJUSTMENT => 'Adjustment', \App\Models\FIN\LedgerModel::TYPE_SALARY_ADVANCE => 'Salary Advance',
        \App\Models\FIN\LedgerModel::TYPE_SALARY_PAYMENT => 'Salary', \App\Models\FIN\LedgerModel::TYPE_OPENING_BALANCE => 'Opening Balance',
    ];
    $balNeg = $balance < -0.005;
    $balHeld = $balance > 0.005;
    $canBreakdown = $isEmployee && $riderMeta && ($riderMeta['open_count'] ?? 0) > 0;
@endphp

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'accounts', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
        'oldNavUrl' => $oldUrl, 'oldNavLabel' => 'Old account page ↗'])

    <a class="back-link" href="{{ route('fin.hub.accounts', ['scope' => $scope]) }}">‹ Accounts</a>

    <div class="bal-head">
        <div class="bal-main">
            <div class="b-label">{{ $isEmployee ? 'Holding right now' : 'Balance right now' }}</div>
            <div class="num-lg num {{ $canBreakdown ? 'tap' : '' }}" @if($canBreakdown) onclick="hubOpenBreakdown()" title="See how this balance is made up" @endif style="color:{{ $balNeg ? 'var(--out)' : ($isEmployee && $balHeld ? 'var(--owe)' : 'var(--in)') }}">Rs. {{ number_format($balance, 2) }}</div>
            <div class="b-note">
                {{ $account->account_name }} <span class="mono" style="color:var(--ink3)">{{ $account->account_code }}</span>
                @if($isEmployee) · company cash only (salary &amp; personal never move this) @endif
            </div>
        </div>
        <div class="bal-chips">
            @if($isEmployee && $riderMeta)
                <div class="stat-chip {{ $canBreakdown ? 'tap' : '' }}" @if($canBreakdown) onclick="hubOpenBreakdown()" @endif>Open invoices<b class="num">{{ $riderMeta['open_count'] }} · Rs. {{ number_format($riderMeta['open_total'], 0) }}</b></div>
                <div class="stat-chip">Last deposit<b>{{ $riderMeta['last_deposit'] ? \Carbon\Carbon::parse($riderMeta['last_deposit'])->format('M d, Y') : '—' }}</b></div>
            @else
                <div class="stat-chip">Opening balance<b class="num">Rs. {{ number_format($account->opening_balance, 0) }}</b></div>
                <div class="stat-chip">Category<b style="text-transform:capitalize">{{ $account->account_category }}</b></div>
            @endif
        </div>
        <div class="bal-actions">
            @if($isEmployee)
                @if($balHeld && !auth()->user()?->isReadOnly())<button class="btn primary" type="button" onclick="hubOpenSettle({{ $account->id }}, @js($account->account_name))">💵 Settle</button>@endif
                <a class="btn" href="{{ $oldUrl }}">Deposit / more ↗</a>
            @else
                @if(!auth()->user()?->isReadOnly())<button class="btn primary" type="button" onclick="hubOpenTransfer()">⇄ Transfer</button>@endif
                <a class="btn" href="{{ $oldUrl }}">More ↗</a>
            @endif
        </div>
    </div>

    {{-- ⏳ Pending balance actions — shared partial (also on the Banks tab, where the ONLINE tile routes). --}}
    @include('fin.hub.partials.pending-actions')

    {{-- 👤 WHO USES THIS ACCOUNT (Aug-2026)
         The account-usage tags that decide whose payment-source picker offers this
         account, for what, and whose default it is. Per account rather than per
         user: ~9 spendable accounts vs ~30 people, so a short list here is readable
         where a users×accounts grid would not be.
         Employee-cash accounts are excluded — a rider's cash account is for HOLDING
         company money; spending from your own float is tagged on the company
         account, not here. --}}
    {{-- ⚠ Gate on the CATEGORY, not on $isAsset. `$isAsset` means account_type ===
         'asset', which every cash and bank account is — using it here hid this
         panel on literally every account it exists for. Payment sources are
         exactly the cash + bank accounts, the same set PaymentSourceService
         offers, so that is what this matches. --}}
    @if(in_array($account->account_category, [\App\Models\FIN\AccountModel::CATEGORY_CASH, \App\Models\FIN\AccountModel::CATEGORY_BANK], true))
    <div class="card" style="margin-top:14px;">
        <div class="card-head">
            <h3>👤 Who uses this account</h3>
            <span class="meta" id="auMeta">Loading…</span>
        </div>
        <div style="padding:4px 2px 10px;">
            <div id="auBody" class="meta" style="padding:10px 2px;">Loading…</div>
            <div id="auAdd" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);">
                <select id="auUser" class="kt-select" style="max-width:260px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-size:13px;"></select>
                <button class="btn" type="button" onclick="auAdd()" style="margin-left:6px;">+ Add person</button>
            </div>
        </div>
    </div>

    <script>
    // Panel state. Kept tiny and re-rendered wholesale from the server's response
    // after every write — the server is the authority on what the tags now say, and
    // re-deriving locally is how the two drift apart.
    const AU_URL   = @js(route('fin.hub.account-users', $account->id));
    const AU_ISBANK= @js($account->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK);
    let auState = null;

    function auEsc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function auLoad(){
        fetch(AU_URL, {headers:{'Accept':'application/json'}})
            .then(r => r.json())
            .then(auRender)
            .catch(() => { document.getElementById('auBody').textContent = 'Could not load the people on this account.'; });
    }

    function auRender(d){
        if (!d || !d.success) { document.getElementById('auBody').textContent = 'Could not load the people on this account.'; return; }
        auState = d;
        const rows = d.rows || [];
        document.getElementById('auMeta').textContent =
            rows.length ? (rows.length + (rows.length === 1 ? ' person' : ' people') + (d.can_manage ? '' : ' · read only')) : 'nobody yet';

        if (!rows.length) {
            document.getElementById('auBody').innerHTML =
                '<i>Nobody is set up to pay from this account yet.' +
                (d.can_manage ? ' Add someone below.' : '') + '</i>';
        } else {
            document.getElementById('auBody').innerHTML = rows.map(auRow).join('');
        }

        // Add-person control, only for those who may manage.
        const add = document.getElementById('auAdd');
        add.style.display = d.can_manage ? 'block' : 'none';
        if (d.can_manage) {
            const sel = document.getElementById('auUser');
            sel.innerHTML = '<option value="">— choose a person —</option>' +
                (d.candidates || []).map(c => '<option value="' + c.id + '">' + auEsc(c.name) + '</option>').join('');
        }
    }

    function auRow(r){
        const dis = auState.can_manage ? '' : ' disabled';
        const tick = (key, label) =>
            '<label style="margin-right:12px;font-size:12.5px;white-space:nowrap;">' +
            '<input type="checkbox"' + (r[key] ? ' checked' : '') + dis +
            ' onchange="auSave(' + r.user_id + ')" id="au_' + key + '_' + r.user_id + '"> ' + label + '</label>';

        // ⚠ A tag on an account whose books the person's ROLE cannot open does
        // nothing. Say so plainly rather than letting it look like a silent bug.
        const blocked = r.bu_blocked
            ? '<div style="font-size:11.5px;color:var(--out);margin-top:3px;">⚠ This person\'s role has no access to ' +
              auEsc(auState.bu_name || 'these books') + ', so this has no effect until that is changed.</div>'
            : '';

        let bank = '';
        if (AU_ISBANK && auState.banks && auState.banks.length) {
            bank = '<div style="margin-top:4px;font-size:12.5px;">Usual bank: <select id="au_bank_' + r.user_id + '"' + dis +
                ' onchange="auSave(' + r.user_id + ')" style="padding:3px 6px;border:1px solid var(--line);border-radius:6px;font-size:12.5px;">' +
                '<option value="">— none —</option>' +
                auState.banks.map(b => '<option value="' + b.id + '"' +
                    (String(b.id) === String(r.preferred_bank_id) ? ' selected' : '') + '>' + auEsc(b.short_code || b.name) + '</option>').join('') +
                '</select></div>';
        }

        return '<div style="display:flex;align-items:flex-start;gap:10px;padding:9px 2px;border-bottom:1px solid var(--line);">' +
            '<button type="button" title="' + (r.is_default ? 'This is their default account' : 'Make this their default account') + '"' +
                (auState.can_manage ? '' : ' disabled') +
                ' onclick="auStar(' + r.user_id + ')"' +
                ' style="background:none;border:none;cursor:' + (auState.can_manage ? 'pointer' : 'default') + ';font-size:16px;line-height:1;padding:2px 0;">' +
                (r.is_default ? '⭐' : '☆') + '</button>' +
            '<div style="flex:1;min-width:0;">' +
                '<b style="font-size:13.5px;">' + auEsc(r.name) + '</b>' +
                (r.is_default ? '<span class="type-chip" style="margin-left:6px;">their default</span>' : '') +
                '<div style="margin-top:3px;">' + tick('can_expense','Expenses') + tick('can_vendor','Vendor payments') + tick('can_advance','Advances') + '</div>' +
                bank + blocked +
            '</div>' +
            (auState.can_manage
                ? '<button class="btn" type="button" onclick="auRemove(' + r.user_id + ')" style="padding:3px 9px;font-size:12px;">Remove</button>'
                : '') +
        '</div>';
    }

    function auPost(body){
        return fetch(AU_URL, {
            method: 'POST',
            headers: {'Content-Type':'application/json','Accept':'application/json',
                      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
            body: JSON.stringify(body)
        }).then(r => r.json()).then(d => {
            if (!d.success && d.message) alert(d.message);
            auRender(d);
        }).catch(() => alert('Could not save. Please try again.'));
    }

    /** Read the row's controls back and send the whole tag — no partial updates. */
    function auSave(userId){
        const bankSel = document.getElementById('au_bank_' + userId);
        const row = (auState.rows || []).find(r => r.user_id === userId) || {};
        auPost({
            user_id: userId,
            can_expense: document.getElementById('au_can_expense_' + userId).checked,
            can_vendor:  document.getElementById('au_can_vendor_'  + userId).checked,
            can_advance: document.getElementById('au_can_advance_' + userId).checked,
            is_default:  !!row.is_default,
            preferred_bank_id: bankSel && bankSel.value ? parseInt(bankSel.value, 10) : null
        });
    }

    /** Starring is a MOVE: the server clears this person's other star in these books. */
    function auStar(userId){
        const row = (auState.rows || []).find(r => r.user_id === userId) || {};
        const bankSel = document.getElementById('au_bank_' + userId);
        auPost({
            user_id: userId,
            can_expense: !!row.can_expense, can_vendor: !!row.can_vendor, can_advance: !!row.can_advance,
            is_default: !row.is_default,
            preferred_bank_id: bankSel && bankSel.value ? parseInt(bankSel.value, 10) : null
        });
    }

    function auAdd(){
        const sel = document.getElementById('auUser');
        if (!sel.value) return;
        auPost({user_id: parseInt(sel.value, 10), can_expense: true, can_vendor: true, can_advance: true, is_default: false});
    }

    function auRemove(userId){
        const row = (auState.rows || []).find(r => r.user_id === userId) || {};
        if (!confirm('Remove ' + (row.name || 'this person') + ' from this account? They will no longer be able to pay from it.')) return;
        fetch(AU_URL + '/' + userId, {
            method: 'DELETE',
            headers: {'Accept':'application/json',
                      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''}
        }).then(r => r.json()).then(d => { if (!d.success && d.message) alert(d.message); auRender(d); })
          .catch(() => alert('Could not remove. Please try again.'));
    }

    auLoad();
    </script>
    @endif

    <div class="card">
        <div class="card-head">
            <h3>Transactions</h3>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="meta">{{ $daysLabel }} · {{ $ledger['count'] }} entries @if($isEmployee)· running balance is the calculated one in the header @endif</span>
                <div class="row-actions">
                    @foreach(['30' => '30d', '90' => '90d', '365' => '1yr', 'all' => 'All'] as $d => $lbl)
                        <a class="mini-btn {{ $daysSel === $d ? 'on' : '' }}" href="{{ route('fin.hub.account', ['id' => $account->id, 'scope' => $scope, 'days' => $d]) }}">{{ $lbl }}</a>
                    @endforeach
                </div>
            </div>
        </div>
        @forelse($ledger['groups'] as $g)
            @php
                $net = $g['net'];
                if (abs($net) < 0.005) { $netCls = 'balanced'; $netTxt = '✓ Balanced'; }
                elseif ($net > 0) { $netCls = 'holding'; $netTxt = '+ Rs. ' . number_format($net, 0); }
                else { $netCls = 'short'; $netTxt = '− Rs. ' . number_format(abs($net), 0); }
            @endphp
            <div class="day-group">
                <div class="day-head">
                    <b>{{ \Carbon\Carbon::parse($g['date'])->format('D, M d') }}</b>
                    {{-- In/Out count only money that actually moved. Anything still unapproved is
                         called out separately rather than folded in — the old behaviour claimed
                         "In Rs. 20,000" on a day whose balance column never moved. --}}
                    <span>In Rs. {{ number_format($g['in'], 0) }} · Out Rs. {{ number_format($g['out'], 0) }}@if(($g['pending'] ?? 0) > 0.005)<span class="day-pending"> · Rs. {{ number_format($g['pending'], 0) }} awaiting approval</span>@endif</span>
                    <span class="day-net {{ $netCls }}">{{ $netTxt }}</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>Time</th><th>Type</th><th>Description</th><th class="r">In</th><th class="r">Out</th>
                            @if($ledger['has_running'])<th class="r">Balance</th>@endif
                            <th>Status</th>
                        </tr></thead>
                        <tbody>
                        @foreach($g['items'] as $it)
                            @php
                                $r = $it['row'];
                                $type = $r->transaction_type;
                                $typeLabel = $typeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type));
                                $st = $r->approval_status;
                                if (in_array($st, ['pending', 'pending_l1'], true)) { $sKey = 'l1'; $sLabel = 'Pending L1'; }
                                elseif ($st === 'pending_l2') { $sKey = 'l2'; $sLabel = 'Pending L2'; }
                                elseif ($st === 'approved') { $sKey = 'ok'; $sLabel = 'Approved'; }
                                elseif ($st === 'rejected') { $sKey = 'rej'; $sLabel = 'Rejected'; }
                                elseif ($st === 'reversed') { $sKey = 'rej'; $sLabel = 'Reversed'; }
                                else { $sKey = 'l1'; $sLabel = ucfirst($st); }
                                $desc = trim((string) $r->description);
                                $other = $it['is_in'] ? optional($r->fromAccount)->account_name : optional($r->toAccount)->account_name;
                                $d = [
                                    'id' => $r->id, 'url' => route('fin.ledger.show', $r->id),
                                    'title' => $typeLabel, 'sub' => $desc !== '' ? \Illuminate\Support\Str::limit($desc, 90) : '—',
                                    'amount' => 'Rs. ' . number_format($r->amount, 2), 'dir' => $it['is_in'] ? 'in' : 'out',
                                    'mode' => ucfirst($r->mode ?? 'cash'),
                                    'from' => optional($r->fromAccount)->account_name ?? '—', 'fromsub' => optional($r->fromAccount)->account_code ?? '',
                                    'to' => optional($r->toAccount)->account_name ?? '—', 'tosub' => optional($r->toAccount)->account_code ?? '',
                                    'status' => $sKey, 'statusLabel' => $sLabel,
                                    'date' => \Carbon\Carbon::parse($r->transaction_date)->format('M d, Y'),
                                    // Which of OUR banks — only tagged on rows touching an online account.
                                    'bank' => optional($r->receivingAccount)->short_code ?: optional($r->receivingAccount)->name,
                                    // fullname, not name — UserModel has no `name` (always NULL otherwise)
                                    'by' => optional($r->createdBy)->fullname ?? '—',
                                    'pending' => in_array($st, ['pending', 'pending_l1', 'pending_l2'], true),
                                ];
                            @endphp
                            <tr class="t-row" data-d='{{ json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'>
                                <td class="cell-date num">{{ $r->created_at ? $r->created_at->format('H:i') : '' }}</td>
                                <td><span class="type-chip">{{ $typeLabel }}</span></td>
                                <td class="desc" title="{{ $desc }}">{{ $desc !== '' ? \Illuminate\Support\Str::limit($desc, 40) : '—' }}@if($other) <span style="color:var(--ink3)">· {{ \Illuminate\Support\Str::limit($other, 18) }}</span>@endif</td>
                                <td class="r">@if($it['is_in'])<span class="amt in num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                <td class="r">@if(!$it['is_in'])<span class="amt out num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                @if($ledger['has_running'])<td class="r num" style="color:{{ $it['running'] < 0 ? 'var(--out)' : 'var(--ink2)' }}">{{ $it['running'] !== null ? number_format($it['running'], 2) : '' }}</td>@endif
                                <td><span class="status {{ $sKey }}">{{ $sLabel }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="empty">No transactions in this period ({{ $daysLabel }}). Try a longer range above.</div>
        @endforelse
    </div>


    @if($canBreakdown)
    {{-- Balance breakdown: the open invoices that add up to what the rider is holding --}}
    <div class="hubmodal" id="hubBreakdown" onclick="if(event.target===this)hubCloseBreakdown()">
        <div class="hubmodal-box">
            <div class="hubmodal-head">
                <div>
                    <h3>How this balance is held</h3>
                    <div class="hm-sub">{{ $account->account_name }} · {{ $riderMeta['open_count'] }} open {{ \Illuminate\Support\Str::plural('invoice', $riderMeta['open_count']) }}</div>
                </div>
                <button class="hubmodal-x" type="button" onclick="hubCloseBreakdown()" aria-label="Close">✕</button>
            </div>
            <div class="hubmodal-body">
                <div class="inv-list">
                    @foreach($riderMeta['invoices'] as $inv)
                        @php
                            $outstanding = (float) $inv->amount - (float) ($inv->settled_amount ?? 0);
                            $ordNo = optional($inv->order)->order_number;
                            $cust = optional(optional($inv->order)->customer)->customer_name ?? optional($inv->order)->customer_name ?? null;
                            $partial = ($inv->settlement_status === 'partial');
                        @endphp
                        <div class="inv-row">
                            <div class="iv-main">
                                <b>{{ $ordNo ? 'Order #' . $ordNo : \Illuminate\Support\Str::limit($inv->description, 40) }}</b>
                                @if($partial)<span class="type-chip" style="margin-left:6px">Partial</span>@endif
                                <div class="iv-sub">{{ \Carbon\Carbon::parse($inv->transaction_date)->format('M d, Y') }}@if($cust) · {{ \Illuminate\Support\Str::limit($cust, 26) }}@endif @if($partial)· of Rs. {{ number_format($inv->amount, 0) }} @endif</div>
                            </div>
                            <div class="iv-amt num">Rs. {{ number_format($outstanding, 2) }}</div>
                        </div>
                    @endforeach
                    <div class="inv-total"><span>Total held</span><span class="num">Rs. {{ number_format($riderMeta['open_total'], 2) }}</span></div>
                </div>
            </div>
            <div class="hubmodal-foot">
                <button class="btn primary" type="button" onclick="hubCloseBreakdown();hubOpenSettle({{ $account->id }}, @js($account->account_name))">Settle these</button>
                <button class="btn" type="button" onclick="hubCloseBreakdown()">Close</button>
            </div>
        </div>
    </div>
    <script>
        function hubOpenBreakdown(){ document.getElementById('hubBreakdown').classList.add('on'); }
        function hubCloseBreakdown(){ document.getElementById('hubBreakdown').classList.remove('on'); }
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') hubCloseBreakdown(); });
    </script>
    @endif

    @include('fin.hub.partials.settle-modal')
    @include('fin.hub.partials.drawer')
</div>
@endsection
