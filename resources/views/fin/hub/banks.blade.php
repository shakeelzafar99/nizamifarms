@extends('layouts.app')
@section('title', 'Ledger Hub — Banks')
@include('fin.hub.partials.styles')

@php
    $money0 = fn ($n) => 'Rs. ' . number_format($n, 0);
    // The meter only reads as a proportion when both parts are non-negative — with a negative pool
    // or an overdrawn bucket a bar would be nonsense, so we fall back to the plain figures.
    $meterOk = !$isQ && $ledgerOnlineBalance > 0 && $sumBalances >= 0 && $unassigned >= 0;
@endphp

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'banks', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    {{-- Health line. Three states, in order of honesty:
         1. Never baselined — every figure mixes in years of untracked history, so the recon formula
            and the old untagged pile are NOISE, not information. One calm setup notice, nothing red.
         2. Baselined but out of balance — the loud tripwire, with the formula. This should be rare.
         3. Baselined and clean — silence here; a small ✓ chip lives in the pool banner instead. --}}
    @if(!$isQ && !$isBaselined)
        <div class="setup-banner">
            <span class="su-ico">⟲</span>
            <div class="su-text">
                <b>One-time setup: tell the system what each bank really holds.</b>
                These figures still include old, untracked history — that’s why they look wrong.
                @if($isTaimur)
                    Run <b>Rebalance banks</b> once with today’s real balances; tracking starts clean from that moment and this notice goes away.
                @else
                    A one-time <b>Rebalance banks</b> (Taimur) starts clean tracking.
                @endif
            </div>
            @if($isTaimur)<button class="btn primary" type="button" onclick="hubOpenRebalance()">⟲ Rebalance banks</button>@endif
        </div>
    @elseif(!$isQ && $reconStatus !== 'green')
        <div class="recon-banner {{ $reconStatus }}">
            <span>{{ $reconStatus === 'amber' ? '⚠ Small gap' : '❗ Unexplained gap' }}</span>
            <span class="formula num">in banks {{ $money0($sumBalances) }} + to distribute {{ $money0($unassigned) }} − ONLINE ledger {{ $money0($ledgerOnlineBalance) }}@if(abs($netManualAdjustments) > 0.5) − manual fixes {{ $money0($netManualAdjustments) }}@endif = <b>{{ $money0($reconGap) }}</b></span>
        </div>
    @endif

    {{-- ONLINE balance + how much of it is actually placed in a bank --}}
    <div class="pool-banner">
        <div class="p-main">
            <div class="p-label">
                {{ $isQ ? 'Qurbani online balance' : 'Online balance' }}
                @if(!$isQ && $isBaselined && $reconStatus === 'green')
                    <span class="ok-chip" title="Banks + to-distribute add up to the online balance{{ $rebalancedAt ? ' · tracking since ' . \Carbon\Carbon::parse($rebalancedAt)->format('M d, Y') : '' }}">✓ Reconciled</span>
                @endif
            </div>
            <div class="p-val num">{{ $money0($ledgerOnlineBalance) }}</div>
            @if(!$isQ && $isBaselined)
                <div class="p-split">
                    <span><i class="dot in"></i>In banks <b class="num">{{ $money0($sumBalances) }}</b></span>
                    <span><i class="dot owe"></i>To distribute <b class="num">{{ $money0($unassigned) }}</b></span>
                    @if(abs($reconGap) >= 1)<span><i class="dot out"></i>Unexplained <b class="num">{{ $money0($reconGap) }}</b></span>@endif
                </div>
                @if($meterOk)
                    @php
                        $inPct = max(0, min(100, $sumBalances / max($ledgerOnlineBalance, 0.01) * 100));
                    @endphp
                    <div class="p-meter" title="Share of the online balance already placed in a bank">
                        <span style="width:{{ $inPct }}%"></span>
                    </div>
                @endif
            @endif
        </div>
        <div class="p-accts">
            @foreach($onlineAccounts as $oa)
                <div class="pool-chip">{{ $oa['name'] }}<b class="num">{{ $money0($oa['balance']) }}</b></div>
            @endforeach
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="filter-bar" style="justify-content:space-between">
        <div class="chip-group" role="group" aria-label="Bank status" style="display:flex;gap:5px">
            @unless($isQ)
                @foreach(['active' => 'Active ('.$activeCount.')', 'all' => 'All', 'inactive' => 'Inactive ('.$inactiveCount.')'] as $st => $lbl)
                    <a class="mini-btn {{ $status === $st ? 'on' : '' }}" href="{{ route('fin.hub.banks', ['scope' => $scope, 'status' => $st, 'days' => $days]) }}">{{ $lbl }}</a>
                @endforeach
            @endunless
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            @if($isTaimur && !$isQ)
                <button class="mini-btn solid" type="button" onclick="hubOpenRebalance()">⟲ Rebalance banks</button>
                @if($bankTransfersReady)
                    <button class="mini-btn" type="button" onclick="hubOpenBankXfer()" title="Money moved between our own banks — the online total stays the same">⇄ Move between banks</button>
                @endif
            @endif
            @if($isTaimur)
                <button class="mini-btn" type="button" onclick="hubOpenSince()">⚙ No-bank date</button>
            @endif
            @unless($isQ)<a class="mini-btn" href="/finance/bank-balances" title="Add / edit physical banks on the full page">Manage banks ↗</a>@endunless
        </div>
    </div>

    @if($isQ)
        <div class="note-card">Qurbani online money isn’t distributed across physical banks — it’s collected into the Qurbani Online account and sits untagged. Open the bucket below to see it.</div>
    @endif

    {{-- Bank cards → each opens its statement as an inline table (bank detail page) --}}
    <div class="bank-grid">
        @foreach($banks as $b)
            <a class="bank-card" style="border-left-color:{{ $b['color_hex'] }};display:block;text-decoration:none;color:inherit" href="{{ route('fin.hub.bank', ['id' => $b['id'], 'scope' => $scope]) }}">
                <div class="b-name">
                    <span>{{ $b['name'] }} @if($b['account_last4'])<span class="mono" style="color:var(--ink3)">••{{ $b['account_last4'] }}</span>@endif</span>
                    @if(!$b['is_active'])<span class="inactive-tag">inactive</span>@endif
                </div>
                <div class="b-bal num" style="color:{{ $b['balance'] < 0 ? 'var(--out)' : 'var(--ink)' }}">{{ $money0($b['balance']) }}</div>
                <div class="b-meta">
                    @if($b['opening_balance_date'])reset {{ \Carbon\Carbon::parse($b['opening_balance_date'])->format('M d') }} · @endif
                    net {{ $b['net_movement'] >= 0 ? '+' : '−' }}{{ number_format(abs($b['net_movement']), 0) }} · statement →
                </div>
            </a>
        @endforeach

        {{-- No-bank bucket. Pre-baseline this is years of history, not a to-do list — present it
             that way, and let the rebalance (not row-by-row tagging) be the fix. --}}
        <a class="bank-card untagged" style="display:block;text-decoration:none;color:inherit" href="{{ route('fin.hub.bank', ['id' => 'unassigned', 'scope' => $scope]) }}">
            <div class="b-name"><span style="color:var(--owe)">⚠ No bank</span></div>
            <div class="b-bal num">{{ $money0($unassigned) }}</div>
            @if(!$isQ && !$isBaselined)
                <div class="b-meta">{{ number_format($unassignedCount) }} old untracked {{ \Illuminate\Support\Str::plural('row', $unassignedCount) }} — the rebalance restarts this bucket</div>
            @else
                <div class="b-meta">{{ number_format($unassignedCount) }} untagged {{ \Illuminate\Support\Str::plural('row', $unassignedCount) }}@if($unassignedSince) · since {{ \Carbon\Carbon::parse($unassignedSince)->format('M d, Y') }}@endif — open to assign</div>
            @endif
        </a>
    </div>

    {{-- ===== Every online movement, tagged and untagged, without drilling into a bank first ===== --}}
    <div class="filter-bar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="row-actions">
            @foreach(['30' => '30d', '90' => '90d', '365' => '1yr', '0' => 'All'] as $d => $lbl)
                <a class="mini-btn {{ (string) $days === $d ? 'on' : '' }}" href="{{ route('fin.hub.banks', ['scope' => $scope, 'status' => $status, 'days' => $d]) }}#feed">{{ $lbl }}</a>
            @endforeach
        </div>
        <span class="stmt-tot" style="margin-left:auto">
            <span class="g">In {{ $money0($feed['total_in']) }}</span> ·
            <span class="r">Out {{ $money0($feed['total_out']) }}</span> ·
            <span>Net {{ $money0($feed['total_in'] - $feed['total_out']) }}</span>
        </span>
    </div>

    <div class="card" id="feed">
        <div class="card-head">
            <h3>All online movements</h3>
            <span class="meta">
                {{ $days == 0 ? 'all history' : 'last '.$days.' days' }} · {{ $feed['count'] }} {{ \Illuminate\Support\Str::plural('entry', $feed['count']) }}
                @if($feed['truncated']) · <b style="color:var(--owe)">showing the newest 1,000 only</b>@endif
            </span>
        </div>
        @forelse($feed['groups'] as $g)
            @php
                $net = $g['in'] - $g['out'];
                if (abs($net) < 0.005) { $nc = 'balanced'; $nt = '✓ Even'; }
                elseif ($net > 0) { $nc = 'holding'; $nt = '+ Rs. ' . number_format($net, 0); }
                else { $nc = 'short'; $nt = '− Rs. ' . number_format(abs($net), 0); }
            @endphp
            <div class="day-group">
                <div class="day-head">
                    <b>{{ $g['date'] === 'unknown' ? 'Undated' : \Carbon\Carbon::parse($g['date'])->format('D, M d, Y') }}</b>
                    <span>In Rs. {{ number_format($g['in'], 0) }} · Out Rs. {{ number_format($g['out'], 0) }}</span>
                    <span class="day-net {{ $nc }}">{{ $nt }}</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>Type</th><th>Description</th><th>Bank</th><th class="r">In</th><th class="r">Out</th>
                        </tr></thead>
                        <tbody>
                        @foreach($g['items'] as $it)
                            <tr>
                                <td><span class="type-chip">{{ ucfirst(str_replace('_', ' ', $it['type'])) }}</span></td>
                                <td class="desc" title="{{ $it['description'] }}">
                                    {{ \Illuminate\Support\Str::limit($it['description'], 52) ?: '—' }}
                                    @if($it['counterparty'])<span class="bank-tag">{{ $it['counterparty'] }}</span>@endif
                                </td>
                                <td>
                                    @if($it['bank_id'])
                                        <a class="bankpill" style="border-left:3px solid {{ $it['bank_color'] }}"
                                           href="{{ route('fin.hub.bank', ['id' => $it['bank_id'], 'scope' => $scope]) }}">{{ $it['bank_name'] }}</a>
                                    @else
                                        <a class="bankpill none" href="{{ route('fin.hub.bank', ['id' => 'unassigned', 'scope' => $scope]) }}">⚠ No bank</a>
                                    @endif
                                </td>
                                <td class="r">@if($it['direction'] === 'in')<span class="amt in num">{{ number_format($it['amount'], 2) }}</span>@else<span style="color:var(--ink3)">–</span>@endif</td>
                                <td class="r">@if($it['direction'] === 'out')<span class="amt out num">{{ number_format($it['amount'], 2) }}</span>@else<span style="color:var(--ink3)">–</span>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="empty">No online movements in this period.</div>
        @endforelse
    </div>

    <p class="footnote" style="font-size:12px;color:var(--ink3);margin-top:12px"><b>How this works:</b> all online money lives in one ledger account; each bank’s balance is its reset figure plus every movement tagged to it. The list above is real money only — attribution-only <b>⚖ Fix balance</b> corrections appear inside each bank’s own statement, because they move a bank’s tracked number without moving the online total.@if($rebalancedAt) <br><b>Last rebalanced</b> {{ \Carbon\Carbon::parse($rebalancedAt)->format('M d, Y') }}@if($rebalancedBy) by {{ $rebalancedBy }}@endif.@endif</p>

    {{-- ===== No-bank tracking-since modal (list-level setting) ===== --}}
    @if($isTaimur)
    <div class="hubmodal" id="hubSince" onclick="if(event.target===this)hubClose('hubSince')">
        <div class="hubmodal-box">
            <div class="hubmodal-head"><div><h3>No-bank tracking date</h3><div class="hm-sub">Count untagged rows only on/after this date</div></div><button class="hubmodal-x" type="button" onclick="hubClose('hubSince')">✕</button></div>
            <div class="hubmodal-body">
                <div class="m-err" id="hubSinceErr"></div>
                <div class="fld"><label>Track from</label><input type="date" id="hubSinceDate" value="{{ $unassignedSince ? \Carbon\Carbon::parse($unassignedSince)->format('Y-m-d') : '' }}"><span class="hint">Leave empty to count all history.</span></div>
            </div>
            <div class="hubmodal-foot"><button class="btn" type="button" onclick="hubClose('hubSince')">Cancel</button><button class="btn primary" type="button" onclick="hubSubmitSince()">Save</button></div>
        </div>
    </div>
    @endif

    {{-- ===== Rebalance wizard (Taimur, operational scope only) ===== --}}
    @if($isTaimur && !$isQ)
    <div class="hubmodal" id="hubRebal" onclick="if(event.target===this)hubClose('hubRebal')">
        <div class="hubmodal-box wide">
            <div class="hubmodal-head">
                <div><h3>Rebalance banks</h3><div class="hm-sub">Declare the true online total and how it is spread, then track cleanly from that date</div></div>
                <button class="hubmodal-x" type="button" onclick="hubClose('hubRebal')">✕</button>
            </div>
            <div class="hubmodal-body">
                <div class="m-err" id="hubRebalErr"></div>

                {{-- Live summary, PINNED — stays visible while scrolling the bank list so the owner
                     always sees what's still left to place. The same bar repeats at the bottom. --}}
                <div class="rb-foot rb-top js-rb-foot">
                    <span>Online total <b class="num js-rb-pool">—</b></span>
                    <span>Placed <b class="num js-rb-dist">—</b></span>
                    <span>Left to place <b class="num js-rb-left">—</b></span>
                </div>

                <div class="note-card" style="margin-bottom:14px">
                    Type what each bank <b>really holds right now</b> — straight off the statement. Everything recorded before today stops counting, but nothing is deleted: the old rows stay readable in each bank’s statement under <b>show earlier history</b>.
                </div>

                <div class="fld">
                    <label>True online total, as of today</label>
                    <input type="number" step="0.01" id="rbPool" value="{{ number_format($ledgerOnlineBalance, 2, '.', '') }}" oninput="rbRecalc()">
                    <span class="hint">The ledger currently says {{ $money0($ledgerOnlineBalance) }}. Change it if the real total differs — the difference gets posted as outside money.</span>
                </div>

                <div class="sec-label" style="margin:6px 0 6px">Spread it across the banks</div>
                <div class="rb-list">
                    @foreach($allBankRows as $b)
                        <div class="rb-row {{ $b['is_active'] ? '' : 'off' }}">
                            <span class="rb-dot" style="background:{{ $b['color_hex'] }}"></span>
                            <span class="rb-name">
                                {{ $b['name'] }}
                                @if(!$b['is_active'])<span class="inactive-tag">inactive</span>@endif
                                <span class="rb-cur">now {{ $money0($b['balance']) }}</span>
                            </span>
                            {{-- First-ever baseline: prefill 0 so only banks that really hold money get
                                 typed (the tracked figures are noise until then). After that, prefill
                                 the tracked balance — a re-run is then just a small true-up. --}}
                            <input class="rb-amt" type="number" step="0.01" data-id="{{ $b['id'] }}"
                                   value="{{ number_format($isBaselined ? $b['balance'] : 0, 2, '.', '') }}" oninput="rbRecalc()">
                        </div>
                    @endforeach
                    @if(abs($untaggedToday) >= 0.01)
                        {{-- Real money in the pool whose bank is unknown. It cannot be wished away at
                             reset, so it is shown as its own line and counts towards the total. --}}
                        <div class="rb-row" style="background:var(--owe-soft)">
                            <span class="rb-dot" style="background:var(--owe)"></span>
                            <span class="rb-name" style="color:var(--owe)">
                                Recorded today with no bank
                                <span class="rb-cur">tag these from the No bank bucket to clear this line</span>
                            </span>
                            <span class="num" style="width:140px;text-align:right;font-weight:700;color:var(--owe)">{{ number_format($untaggedToday, 2) }}</span>
                        </div>
                    @endif
                </div>

                <div class="rb-foot js-rb-foot">
                    <span>Online total <b class="num js-rb-pool">—</b></span>
                    <span>Placed <b class="num js-rb-dist">—</b></span>
                    <span>Left to place <b class="num js-rb-left">—</b></span>
                </div>

                <div class="fld" style="margin-top:12px"><label>Note (optional)</label><input type="text" id="rbNote" placeholder="e.g. matched to statements, 29 Jul"></div>
                <div class="hint" id="rbPoolWarn" style="display:none;color:var(--owe);font-size:12px"></div>
            </div>
            <div class="hubmodal-foot">
                <button class="btn" type="button" onclick="hubClose('hubRebal')">Cancel</button>
                <button class="btn primary" type="button" id="rbSubmit" onclick="hubSubmitRebalance()">Apply rebalance</button>
            </div>
        </div>
    </div>
    @endif

    @if($isTaimur && !$isQ && $bankTransfersReady)
        @include('fin.hub.partials.bank-transfer-modal')
    @endif
</div>

<script>
(function(){
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
    var fmt0 = function(n){ return 'Rs. ' + Number(n).toLocaleString(undefined,{maximumFractionDigits:0}); };
    window.hubClose = function(id){ var e=document.getElementById(id); if(e) e.classList.remove('on'); };

    @if($isTaimur)
    window.hubOpenSince = function(){ document.getElementById('hubSinceErr').classList.remove('on'); document.getElementById('hubSince').classList.add('on'); };
    window.hubSubmitSince = async function(){
        var fd = new FormData(); fd.append('_token', csrf); fd.append('since', document.getElementById('hubSinceDate').value);
        try{
            var r = await fetch(@json(route('fin.bank-balances.unassigned.since')), {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body:fd});
            var j = await r.json();
            if(j.success){ hubToast('Saved'); setTimeout(function(){ location.reload(); }, 600); }
            else { var e=document.getElementById('hubSinceErr'); e.textContent=j.message||'Could not save.'; e.classList.add('on'); }
        }catch(err){ var e=document.getElementById('hubSinceErr'); e.textContent='Network error.'; e.classList.add('on'); }
    };
    @endif

    @if($isTaimur && !$isQ)
    var LEDGER_POOL = @json((float) $ledgerOnlineBalance);
    // Money already recorded today with no bank tag — part of the pool, so it counts as "placed"
    // even though no bank claims it yet.
    var UNTAGGED_TODAY = @json((float) $untaggedToday);

    window.hubOpenRebalance = function(){
        document.getElementById('hubRebalErr').classList.remove('on');
        document.getElementById('hubRebal').classList.add('on');
        rbRecalc();
    };

    window.rbRecalc = function(){
        var pool = parseFloat(document.getElementById('rbPool').value);
        if(isNaN(pool)) pool = 0;
        var dist = UNTAGGED_TODAY;
        document.querySelectorAll('.rb-amt').forEach(function(i){
            var v = parseFloat(i.value); if(!isNaN(v)) dist += v;
        });
        // Round to paisa before comparing so floating-point dust can't block a correct split.
        pool = Math.round(pool*100)/100; dist = Math.round(dist*100)/100;
        var left = Math.round((pool - dist)*100)/100;

        // Sub-rupee slack: owners type whole rupees but the ledger keeps paisa (the prefilled total
        // can end in .53). Anything under one rupee is absorbed server-side — the pool snaps to the
        // placed total — so a 47-paisa leftover must never lock the button. (It once did, displayed
        // as a very convincing "Left to place Rs. -0".)
        var ok = Math.abs(left) < 0.5;
        if(ok && Math.abs(left) < 0.005) left = 0;

        // Show paisa whenever a figure isn't whole — a rounded display was how the -0.47 hid.
        var fmtA = function(n){
            var frac = Math.abs(n % 1) > 0.004 ? 2 : 0;
            return 'Rs. ' + n.toLocaleString(undefined,{minimumFractionDigits:frac,maximumFractionDigits:2});
        };
        document.querySelectorAll('.js-rb-pool').forEach(function(e){ e.textContent = fmtA(pool); });
        document.querySelectorAll('.js-rb-dist').forEach(function(e){ e.textContent = fmtA(dist); });
        document.querySelectorAll('.js-rb-left').forEach(function(e){ e.textContent = fmtA(left); });
        document.querySelectorAll('.js-rb-foot').forEach(function(e){ e.classList.toggle('ok', ok); e.classList.toggle('off', !ok); });
        document.getElementById('rbSubmit').disabled = !ok;

        // Say out loud when the declared pool differs from the ledger — that difference becomes a
        // real posting, not a display tweak.
        var diff = Math.round((pool - LEDGER_POOL)*100)/100;
        var w = document.getElementById('rbPoolWarn');
        if(Math.abs(diff) >= 0.01){
            w.style.display = '';
            w.textContent = (diff > 0 ? '+' : '−') + fmt0(Math.abs(diff)).replace('Rs. ','Rs. ')
                + ' will be posted to the ledger as outside money so the pool matches what you entered.';
        } else { w.style.display = 'none'; }
    };

    window.hubSubmitRebalance = async function(){
        var e = document.getElementById('hubRebalErr'); e.classList.remove('on');
        var pool = parseFloat(document.getElementById('rbPool').value);
        if(isNaN(pool)){ e.textContent='Enter the true online total.'; e.classList.add('on'); return; }

        var fd = new FormData();
        fd.append('_token', csrf);
        fd.append('target_pool', pool);
        fd.append('note', document.getElementById('rbNote').value);
        document.querySelectorAll('.rb-amt').forEach(function(i){
            var v = parseFloat(i.value); if(isNaN(v)) v = 0;
            fd.append('allocations['+i.dataset.id+']', v);
        });

        var btn = document.getElementById('rbSubmit'); btn.disabled = true; btn.textContent = 'Applying…';
        try{
            var r = await fetch(@json(route('fin.hub.rebalance')), {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}, body:fd});
            var j = await r.json().catch(function(){ return {}; });
            if(r.ok && j.success){ hubToast(j.message || 'Rebalanced'); setTimeout(function(){ location.reload(); }, 800); return; }
            e.textContent = j.message || 'Could not rebalance.'; e.classList.add('on');
        }catch(err){ e.textContent='Network error. Nothing was saved.'; e.classList.add('on'); }
        finally{ btn.disabled = false; btn.textContent = 'Apply rebalance'; }
    };
    @endif

    document.addEventListener('keydown', function(e){
        if(e.key==='Escape'){ ['hubSince','hubRebal'].forEach(function(id){ var m=document.getElementById(id); if(m) m.classList.remove('on'); }); }
    });
})();
</script>
@endsection
