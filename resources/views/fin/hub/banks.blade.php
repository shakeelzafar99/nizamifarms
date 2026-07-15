@extends('layouts.app')
@section('title', 'Ledger Hub — Banks')
@include('fin.hub.partials.styles')

@php $money0 = fn ($n) => 'Rs. ' . number_format($n, 0); @endphp

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'banks', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    {{-- Reconciliation tripwire --}}
    <div class="recon-banner {{ $reconStatus }}">
        <span>{{ $reconStatus === 'green' ? '✓ Reconciled' : ($reconStatus === 'amber' ? '⚠ Small gap' : '❗ Unexplained gap') }}</span>
        <span class="formula num">tracked {{ $money0($sumBalances) }} + untagged {{ $money0($unassigned) }} − ONLINE ledger {{ $money0($ledgerOnlineBalance) }}@if(abs($netManualAdjustments) > 0.5) − manual fixes {{ $money0($netManualAdjustments) }}@endif = <b>{{ $money0($reconGap) }}</b></span>
    </div>

    {{-- ONLINE pool --}}
    <div class="pool-banner">
        <div class="p-main">
            <div class="p-label">{{ $isQ ? 'Qurbani online balance' : 'Online pool to distribute' }}</div>
            <div class="p-val num">{{ $money0($ledgerOnlineBalance) }}</div>
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
                    <a class="mini-btn {{ $status === $st ? 'on' : '' }}" href="{{ route('fin.hub.banks', ['scope' => $scope, 'status' => $st]) }}">{{ $lbl }}</a>
                @endforeach
            @endunless
        </div>
        <div style="display:flex;gap:8px;align-items:center">
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
                    @if($b['opening_balance_date'])opening {{ \Carbon\Carbon::parse($b['opening_balance_date'])->format('M d') }} · @endif
                    net {{ $b['net_movement'] >= 0 ? '+' : '−' }}{{ number_format(abs($b['net_movement']), 0) }} · statement →
                </div>
            </a>
        @endforeach

        {{-- No-bank bucket --}}
        <a class="bank-card untagged" style="display:block;text-decoration:none;color:inherit" href="{{ route('fin.hub.bank', ['id' => 'unassigned', 'scope' => $scope]) }}">
            <div class="b-name"><span style="color:var(--owe)">⚠ No bank</span></div>
            <div class="b-bal num">{{ $money0($unassigned) }}</div>
            <div class="b-meta">{{ $unassignedCount }} untagged {{ \Illuminate\Support\Str::plural('row', $unassignedCount) }}@if($unassignedSince) · since {{ \Carbon\Carbon::parse($unassignedSince)->format('M d, Y') }}@endif — open to assign</div>
        </a>
    </div>

    <p class="footnote" style="font-size:12px;color:var(--ink3)"><b>How this works:</b> all online money lives in one ledger account; each bank’s balance is the opening balance plus every movement tagged to it. Open a bank to see its statement and (Taimur) <b>⚖ Fix balance</b> — an attribution-only correction that does <b>not</b> create a ledger entry or move the online total.</p>

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

    @include('fin.hub.partials.transfer-modal')
</div>

<script>
(function(){
    var csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
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
    document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ var m=document.getElementById('hubSince'); if(m) m.classList.remove('on'); } });
    @endif
})();
</script>
@endsection
