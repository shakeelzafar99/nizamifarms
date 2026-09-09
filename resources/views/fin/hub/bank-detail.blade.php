@extends('layouts.app')
@section('title', 'Ledger Hub — ' . $bank['name'])
@include('fin.hub.partials.styles')

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'banks', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    <a class="back-link" href="{{ route('fin.hub.banks', ['scope' => $scope]) }}">‹ Banks</a>

    <div class="bal-head" style="border-left:4px solid {{ $bank['color_hex'] }}">
        <div class="bal-main">
            <div class="b-label">{{ $isUnassigned ? 'Untagged online money' : 'Bank balance' }}</div>
            <div class="num-lg num" style="color:{{ $balance < 0 ? 'var(--out)' : 'var(--ink)' }}">Rs. {{ number_format($balance, 2) }}</div>
            <div class="b-note">
                {{ $bank['name'] }}
                @if(!$isUnassigned && ($bank['account_last4'] ?? null))<span class="mono" style="color:var(--ink3)">••{{ $bank['account_last4'] }}</span>@endif
                @if($isUnassigned)· online movements with no bank picked — assign each below @endif
            </div>
        </div>
        <div class="bal-chips">
            @if(!$isUnassigned && $opening)
                <div class="stat-chip">{{ $resetDate ? 'Reset to' : 'Opening' }}<b class="num">Rs. {{ number_format($opening['amount'], 0) }}</b>@if($opening['date'])<span style="font-size:10px;color:var(--ink3)">{{ \Carbon\Carbon::parse($opening['date'])->format('M d, Y') }}</span>@endif</div>
            @endif
            <div class="stat-chip">{{ $resetDate ? 'Since reset' : 'Net movement' }}<b class="num">{{ $net >= 0 ? '+' : '−' }} Rs. {{ number_format(abs($net), 0) }}</b></div>
        </div>
        @if($isTaimur && !$isUnassigned)
        <div class="bal-actions">
            @if($bankTransfersReady)
                <button class="btn" type="button" onclick="hubOpenBankXfer({{ (int) $bank['id'] }})" title="Money moved to another of our banks — the online total stays the same">⇄ Move money</button>
            @endif
            <button class="btn primary" type="button" onclick="hubOpenFix()">⚖ Fix balance</button>
        </div>
        @endif
    </div>

    {{-- Period selector + 🔍 search.
         Searching is a MODE, not a filter on the window: it looks at all history and drops the
         running balance (see bankDetail()). The period chips stay visible but are disabled while
         a search is up, because they no longer decide anything. --}}
    <div class="filter-bar">
        <div class="row-actions">
            @php
                // NB: not array_filter() — days=0 ("All") is falsy and would be silently dropped.
                $navBase = ['id' => $isUnassigned ? 'unassigned' : $bank['id'], 'scope' => $scope]
                    + (($showHistory && !$isSearch) ? ['history' => 1] : []);
            @endphp
            @foreach(['30' => '30d', '90' => '90d', '365' => '1yr', '0' => 'All'] as $d => $lbl)
                <a class="mini-btn {{ (!$isSearch && (string)$days === $d) ? 'on' : '' }}"
                   @if($isSearch) style="opacity:.45;pointer-events:none" aria-disabled="true" @endif
                   href="{{ route('fin.hub.bank', $navBase + ['days' => $d]) }}">{{ $lbl }}</a>
            @endforeach
        </div>
        <form method="GET" action="{{ route('fin.hub.bank', ['id' => $isUnassigned ? 'unassigned' : $bank['id']]) }}"
              style="display:flex;gap:6px;align-items:center;margin-left:auto">
            <input type="hidden" name="scope" value="{{ $scope }}">
            <input type="hidden" name="days" value="{{ $days }}">
            <input type="search" name="q" value="{{ $search }}" class="stmt-search"
                   placeholder="🔍 amount, name, order #, bank ref…"
                   title="Searches all history — amount (±1), description, customer or vendor name, order number, and the bank's own reference or payer name">
            <button class="mini-btn solid" type="submit">Search</button>
            @if($isSearch)
                <a class="mini-btn" href="{{ route('fin.hub.bank', ['id' => $isUnassigned ? 'unassigned' : $bank['id'], 'scope' => $scope, 'days' => $days]) }}">Clear</a>
            @endif
        </form>
        <span class="stmt-tot" style="width:100%;justify-content:flex-end">
            <span class="g">In Rs. {{ number_format($totalIn, 0) }}</span> · <span class="r">Out Rs. {{ number_format($totalOut, 0) }}</span> · <span>Net Rs. {{ number_format($totalIn - $totalOut, 0) }}</span>
            @if($isSearch)<span style="color:var(--ink3);font-weight:600">· of the matches</span>@endif
        </span>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>{{ $isSearch ? 'Search results' : 'Statement' }}</h3>
            <span class="meta">
                @if($isSearch)
                    {{ $count }} {{ \Illuminate\Support\Str::plural('match', $count) }} for “{{ $search }}” · all history · no running balance
                    @if($searchCapped)· <b style="color:var(--owe)">only the newest 1,000 shown — narrow the search</b>@endif
                @else
                    {{ $days == 0 ? 'all history' : 'last '.$days.' days' }} · {{ $count }} counted {{ \Illuminate\Support\Str::plural('entry', $count) }}
                    @if($resetDate)
                        · running balance from the {{ \Carbon\Carbon::parse($resetDate)->format('M d, Y') }} reset
                    @else
                        · running balance from opening + tagged movements
                    @endif
                    @if($bankDated > 0)· 🏦 {{ $bankDated }} with the bank's own date @endif
                @endif
            </span>
        </div>
        @if($isSearch)
            {{-- Said plainly, because the Balance column vanishing is the kind of thing that reads
                 as a bug unless the screen owns it. --}}
            <div class="note-card" style="margin:0 14px 12px">
                Searching <b>all history</b> for “{{ $search }}” — the period buttons and the reset
                baseline are ignored, so hits from before the reset show here too. There is
                <b>no running balance</b> on a search: a balance is only true over an unbroken run
                of entries, and these are picked out of the middle. Clear the search to get it back.
            </div>
        @endif
        @forelse($groups as $g)
            @php
                // A day made up entirely of pre-reset rows contributes nothing to the balance, so it
                // reports its own (historic) sums rather than a misleading "Even". The reset day
                // itself is never "historic" — it carries the declared balance.
                $isHistoricDay = ($g['counted'] ?? 0) === 0 && empty($g['has_reset'])
                    && (($g['pre_in'] ?? 0) > 0 || ($g['pre_out'] ?? 0) > 0);
                $net = $g['in'] - $g['out'];
                if (abs($net) < 0.005) { $nc = 'balanced'; $nt = '✓ Even'; }
                elseif ($net > 0) { $nc = 'holding'; $nt = '+ Rs. ' . number_format($net, 0); }
                else { $nc = 'short'; $nt = '− Rs. ' . number_format(abs($net), 0); }
            @endphp
            <div class="day-group">
                <div class="day-head">
                    <b>{{ $g['date'] === 'unknown' ? 'Undated' : \Carbon\Carbon::parse($g['date'])->format('D, M d, Y') }}</b>
                    @if($isSearch)
                        {{-- Deliberately NOT the day's In/Out/Net. These rows are the matches, not
                             the day — printing a day total here would state a figure for a day the
                             screen is only showing part of. --}}
                        <span style="color:var(--ink3)">{{ count($g['items']) }} {{ \Illuminate\Support\Str::plural('match', count($g['items'])) }} on this day</span>
                    @elseif($isHistoricDay)
                        <span style="opacity:.6">In Rs. {{ number_format($g['pre_in'], 0) }} · Out Rs. {{ number_format($g['pre_out'], 0) }}</span>
                        <span class="day-net historic">before reset — not counted</span>
                    @else
                        <span>In Rs. {{ number_format($g['in'], 0) }} · Out Rs. {{ number_format($g['out'], 0) }}</span>
                        @if(($g['pre_in'] ?? 0) > 0 || ($g['pre_out'] ?? 0) > 0)
                            {{-- Rows from earlier that day, already inside the declared figure — named
                                 here so the day's totals don't look like they've lost something. --}}
                            <span style="opacity:.55;font-size:11px">· already in the reset: {{ ($g['pre_in'] ?? 0) > 0 ? 'in Rs. ' . number_format($g['pre_in'], 0) : '' }}{{ ($g['pre_in'] ?? 0) > 0 && ($g['pre_out'] ?? 0) > 0 ? ' · ' : '' }}{{ ($g['pre_out'] ?? 0) > 0 ? 'out Rs. ' . number_format($g['pre_out'], 0) : '' }}</span>
                        @endif
                        <span class="day-net {{ $nc }}">{{ $nt }}</span>
                    @endif
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th class="col-time">Time</th><th>Type</th><th>Description</th><th class="r">In</th><th class="r">Out</th>@if(!$isSearch)<th class="r">Balance</th>@endif
                            @if($isUnassigned && $isTaimur)<th class="r">Assign</th>@endif
                        </tr></thead>
                        <tbody>
                        @foreach($g['items'] as $it)
                            @php
                                $isReset = !empty($it['is_reset']);
                                $isPre   = !empty($it['is_pre']);
                                $isXfer = ($it['type'] ?? '') === 'bank_transfer';
                                $typeLabel = $isReset ? '⟲ Reset'
                                    : ($isXfer ? '⇄ Bank transfer'
                                        : ($it['is_adjustment'] ? '⚖ Adjustment' : ucfirst(str_replace('_', ' ', $it['type']))));
                                // Every counted row is openable, including the ones with no ledger
                                // entry behind them — for those the drawer IS the record.
                                $d = $it['drawer'] ?? null;
                                $rowClass = trim(($isReset ? 'reset-row' : ($isPre ? 'pre-reset' : '')) . ($d ? ' t-row' : ''));

                                // Clock time when the row was recorded on the day it belongs to. A
                                // backdated row shows the day it was ENTERED instead — a time alone
                                // would read as if it happened on the day the row is filed under.
                                $rowTime = null; $rowTimeLate = false;
                                if (!empty($it['created_at']) && !empty($it['date']) && $it['date'] !== 'unknown') {
                                    if (substr($it['created_at'], 0, 10) === $it['date']) {
                                        $rowTime = \Carbon\Carbon::parse($it['created_at'])->format('g:i A');
                                    } else {
                                        $rowTime = '↩ ' . \Carbon\Carbon::parse($it['created_at'])->format('M d');
                                        $rowTimeLate = true;
                                    }
                                }
                            @endphp
                            <tr class="{{ $rowClass }}" @if($d) data-d='{{ json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}' @endif>
                                <td class="col-time">
                                    @if($rowTime)
                                        <span class="row-time {{ $rowTimeLate ? 'late' : '' }}" title="Entered {{ \Carbon\Carbon::parse($it['created_at'])->format('M d, Y · g:i A') }}{{ $rowTimeLate ? ' — dated ' . \Carbon\Carbon::parse($it['date'])->format('M d, Y') : '' }}">{{ $rowTime }}</span>
                                    @else
                                        <span style="color:var(--ink3)">–</span>
                                    @endif
                                </td>
                                <td><span class="type-chip">{{ $typeLabel }}</span></td>
                                <td class="desc" title="{{ $it['description'] }}">{{ \Illuminate\Support\Str::limit($it['description'], 48) ?: '—' }}@if($it['counterparty']) <span class="bank-tag">{{ $it['counterparty'] }}</span>@endif
                                    @if($isPre)<span class="bank-tag" style="background:var(--surface2);color:var(--ink3)">{{ !empty($it['pre_same_day']) ? 'already included in the reset figure' : 'before reset — not counted' }}</span>@endif
                                    {{-- 🏦 The bank's OWN date/reference for this row, read off the SMS,
                                         bank email or screenshot behind it. The row above is filed under
                                         the day we RECORDED it; this is the line to match against a real
                                         bank statement. Amber when the two are different days. --}}
                                    @if(!empty($it['bank_at']))
                                        @php
                                            $bankAt = \Carbon\Carbon::parse($it['bank_at']);
                                            $bankDrift = substr((string) $it['bank_at'], 0, 10) !== (string) $it['date'];
                                            $srcLabel = ['bank_sms' => 'bank SMS', 'email' => 'bank email', 'whatsapp' => 'customer screenshot'][$it['bank_src'] ?? ''] ?? 'proof';
                                        @endphp
                                        <div class="bank-date {{ $bankDrift ? 'drift' : '' }}"
                                             title="From the {{ $srcLabel }} behind this payment — the bank's own date and time{{ $bankDrift ? '. It is NOT the day this row is filed under.' : '' }}">
                                            🏦 {{ $bankAt->format('M d') }} · {{ $bankAt->format('g:i A') }}@if(!empty($it['bank_ref'])) · ref <span class="mono">{{ \Illuminate\Support\Str::limit($it['bank_ref'], 18) }}</span>@endif
                                            @if($bankDrift)<span class="bd-flag">bank's date</span>@endif
                                        </div>
                                    @endif
                                </td>
                                <td class="r">@if(!$isReset && $it['direction'] === 'in')<span class="amt in num">{{ number_format($it['amount'], 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                <td class="r">@if(!$isReset && $it['direction'] === 'out')<span class="amt out num">{{ number_format($it['amount'], 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                @if(!$isSearch)
                                <td class="r num" style="color:{{ $it['running'] !== null && $it['running'] < 0 ? 'var(--out)' : 'var(--ink2)' }}">
                                    {{ $it['running'] === null ? '—' : number_format($it['running'], 2) }}
                                </td>
                                @endif
                                @if($isUnassigned && $isTaimur)
                                <td class="r" onclick="event.stopPropagation()">
                                    @if(!$isReset && !$isPre)
                                    <div style="display:flex;gap:4px;justify-content:flex-end">
                                        <select class="hub-assign-sel" style="border:1px solid var(--line);border-radius:6px;padding:3px 6px;background:var(--surface);color:var(--ink);font-size:11.5px">
                                            <option value="">bank…</option>
                                            @foreach($assignBanks as $ab)<option value="{{ $ab->id }}">{{ $ab->short_code ?: $ab->name }}</option>@endforeach
                                        </select>
                                        <button class="mini-btn solid" type="button" onclick="hubAssign(this, {{ $it['id'] }})">Set</button>
                                    </div>
                                    @endif
                                </td>
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            @if($isSearch)
                <div class="empty">
                    <div>Nothing on {{ $isUnassigned ? 'the untagged bucket' : $bank['name'] }} matches “{{ $search }}”.</div>
                    @if(count($elsewhere))
                        {{-- The whole point of the search: the money IS in the ledger, just filed
                             against another bank. Say so, and offer the jump. --}}
                        <div class="found-elsewhere">
                            <b>Found on {{ count($elsewhere) === 1 ? 'another bank' : 'other banks' }}:</b>
                            <div class="fe-list">
                                @foreach($elsewhere as $e)
                                    <a class="mini-btn" href="{{ route('fin.hub.bank', ['id' => $e['id'], 'scope' => $scope, 'days' => $days, 'q' => $search]) }}">
                                        {{ $e['label'] }} · {{ $e['count'] }} {{ \Illuminate\Support\Str::plural('match', $e['count']) }}@if($e['last']) · latest {{ $e['last'] }}@endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div style="font-size:12px;margin-top:6px">It isn't on any other bank either. Try the amount on its own, the customer or vendor name, the order number, or the bank's reference.</div>
                    @endif
                </div>
            @else
                <div class="empty">No movements in this period.</div>
            @endif
        @endforelse

        {{-- Pre-reset history is never deleted — just excluded from the balance and folded away.
             Never shown during a search: a search already reads all history, so the show/hide
             toggle would be offering something it has already done. --}}
        @if(!$isSearch && $resetDate && ($preCount > 0 || $showHistory))
            <div class="hist-bar">
                @if($showHistory)
                    <span>Showing {{ $preShown }} earlier {{ \Illuminate\Support\Str::plural('row', $preShown) }} from before the {{ \Carbon\Carbon::parse($resetDate)->format('M d, Y') }} reset. They are greyed out because they do not count towards this balance.</span>
                    <a class="mini-btn" style="margin-left:auto" href="{{ route('fin.hub.bank', ['id' => $isUnassigned ? 'unassigned' : $bank['id'], 'scope' => $scope, 'days' => $days]) }}">Hide earlier history</a>
                @else
                    <span>{{ number_format($preCount) }} earlier {{ \Illuminate\Support\Str::plural('row', $preCount) }} from before the {{ \Carbon\Carbon::parse($resetDate)->format('M d, Y') }} reset {{ $preCount === 1 ? 'is' : 'are' }} not counted towards this balance.</span>
                    <a class="mini-btn" style="margin-left:auto" href="{{ route('fin.hub.bank', ['id' => $isUnassigned ? 'unassigned' : $bank['id'], 'scope' => $scope, 'days' => $days, 'history' => 1]) }}">Show earlier history</a>
                @endif
            </div>
        @endif
    </div>

    @if($isTaimur && !$isUnassigned)
    {{-- Fix-balance (attribution-only) modal --}}
    <div class="hubmodal" id="hubFix" onclick="if(event.target===this)document.getElementById('hubFix').classList.remove('on')">
        <div class="hubmodal-box">
            <div class="hubmodal-head"><div><h3>Fix bank balance</h3><div class="hm-sub">{{ $bank['name'] }}</div></div><button class="hubmodal-x" type="button" onclick="document.getElementById('hubFix').classList.remove('on')">✕</button></div>
            <div class="hubmodal-body">
                <div class="note-card" style="margin-bottom:12px">An <b>attribution-only</b> correction to match the real statement. It does <b>not</b> create a ledger entry, move the online pool, or touch any account.</div>
                <div class="m-err" id="hubFixErr"></div>
                <div class="fld"><label>Current tracked balance</label><input type="text" value="Rs. {{ number_format($balance, 2) }}" disabled></div>
                <div class="fld-row">
                    <div class="fld"><label>Adjust by (Rs., + or −)</label><input type="number" step="0.01" id="hubFixAmt" placeholder="e.g. 1500 or −250" oninput="hubFixPreview()"></div>
                    <div class="fld"><label>Date</label><input type="date" id="hubFixDate" value="{{ now()->format('Y-m-d') }}"></div>
                </div>
                <div class="fld"><label>Reason / note</label><input type="text" id="hubFixNote" placeholder="bank statement reconciliation"></div>
                <div class="inv-total"><span>New tracked balance</span><span class="num" id="hubFixNew">—</span></div>
            </div>
            <div class="hubmodal-foot"><button class="btn" type="button" onclick="document.getElementById('hubFix').classList.remove('on')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitFix()">Apply fix</button></div>
        </div>
    </div>
    @endif

    @if($isTaimur && !$isUnassigned && $bankTransfersReady)
        @include('fin.hub.partials.bank-transfer-modal')
    @endif

    @include('fin.hub.partials.drawer')
</div>

<script>
(function(){
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    var CUR = @json((float)$balance);
    var fmt2 = function(n){ return Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); };

    window.hubAssign = function(btn, ledgerId){
        var sel = btn.parentNode.querySelector('.hub-assign-sel');
        if(!sel.value){ alert('Pick a bank first.'); return; }
        var fd = new FormData(); fd.append('_token', csrf); fd.append('receiving_account_id', sel.value);
        fetch('/finance/bank-balances/assign/'+ledgerId, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body:fd})
            .then(function(r){ return r.json(); })
            .then(function(j){ if(j.success){ hubToast('Bank assigned'); setTimeout(function(){ location.reload(); }, 600); } else alert(j.message||'Could not assign.'); })
            .catch(function(){ alert('Network error.'); });
    };

    @if($isTaimur && !$isUnassigned)
    window.hubOpenFix = function(){ document.getElementById('hubFixErr').classList.remove('on'); document.getElementById('hubFixAmt').value=''; document.getElementById('hubFixNote').value=''; document.getElementById('hubFixNew').textContent='—'; document.getElementById('hubFix').classList.add('on'); };
    window.hubFixPreview = function(){ var d=parseFloat(document.getElementById('hubFixAmt').value); document.getElementById('hubFixNew').textContent = isNaN(d)?'—':'Rs. '+fmt2(CUR+d); };
    window.hubSubmitFix = async function(){
        var e=document.getElementById('hubFixErr'); e.classList.remove('on');
        var d=parseFloat(document.getElementById('hubFixAmt').value);
        if(isNaN(d)||d===0){ e.textContent='Enter a non-zero amount (+ to add, − to reduce).'; e.classList.add('on'); return; }
        var fd=new FormData(); fd.append('_token',csrf); fd.append('amount',d); fd.append('note',document.getElementById('hubFixNote').value); fd.append('adjustment_date',document.getElementById('hubFixDate').value);
        try{
            var r=await fetch('/finance/bank-balances/{{ $bank['id'] }}/adjustments', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body:fd});
            var j=await r.json();
            if(j.success){ hubToast('Balance fixed'); setTimeout(function(){ location.reload(); }, 700); }
            else { e.textContent=j.message||'Could not save.'; e.classList.add('on'); }
        }catch(err){ e.textContent='Network error.'; e.classList.add('on'); }
    };
    document.addEventListener('keydown', function(ev){ if(ev.key==='Escape'){ var m=document.getElementById('hubFix'); if(m) m.classList.remove('on'); } });
    @endif
})();
</script>
@endsection
