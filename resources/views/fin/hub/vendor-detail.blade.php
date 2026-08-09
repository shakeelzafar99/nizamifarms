@extends('layouts.app')
@section('title', 'Ledger Hub — ' . $vendor->vendor_name)
@include('fin.hub.partials.styles')

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
        'oldNavUrl' => $oldUrl, 'oldNavLabel' => 'Old vendor page ↗'])

    <a class="back-link" href="{{ route('fin.hub.vendors', ['scope' => $scope]) }}">‹ Vendors</a>

    @php
        // Same state vocabulary as the vendors list, for ONE vendor.
        // ⚠ $lastPayment is range-scoped by vendorLedger() — under a filter it is the last payment
        // IN THAT RANGE, not overall. So the "silent Nd" judgement is only made on all-history;
        // a filtered view shows plain owes/settled rather than a number that would be a lie.
        $vdStaleDays = 30;
        $vdLastDate  = $lastPayment ? \Carbon\Carbon::parse($lastPayment->transaction_date) : null;
        $vdDays = (!$hasRange && $vdLastDate)
            ? (int) abs($vdLastDate->copy()->startOfDay()->diffInDays(\Carbon\Carbon::now()->startOfDay()))
            : null;
        if ($payable > 0.5) {
            $vdStale = !$hasRange && ($vdDays === null || $vdDays >= $vdStaleDays);
            $vdState = $vdStale ? 'stale' : 'owes';
            $vdPill  = $vdStale
                ? ['stale', $vdDays === null ? 'owes · never paid' : 'owes · silent ' . $vdDays . 'd']
                : ['owes', 'owes'];
        } else {
            $vdState = 'settled';
            $vdPill  = ['ok', abs($payable) < 0.005 ? 'settled' : 'in credit'];
        }
        $vdAgo = $vdDays === null ? null
            : ($vdDays === 0 ? 'today' : ($vdDays === 1 ? 'yesterday' : $vdDays . ' days ago'));
        $vdAgoCls = $vdDays === null ? '' : ($vdDays <= 1 ? 'fresh' : ($vdDays >= $vdStaleDays ? 'old' : ''));
    @endphp

    <div class="bal-head vs-{{ $vdState }}">
        <div class="bal-main">
            <div class="b-label">NF owes <span class="status {{ $vdPill[0] }} b-pill">{{ $vdPill[1] }}</span></div>
            <div class="num-lg num" style="color:{{ $payable > 0.5 ? 'var(--owe)' : 'var(--in)' }}">Rs. {{ number_format($payable, 2) }}</div>
            <div class="b-note">{{ $vendor->vendor_name }} <span class="mono" style="color:var(--ink3)">{{ optional($account)->account_code }}</span> · {{ $vendor->default_purchase_method === 'by_weight' ? 'by weight' : 'by total' }}</div>
        </div>
        <div class="bal-chips">
            <div class="stat-chip sc-owe">Purchases{{ $hasRange ? ' · period' : '' }}<b class="num">Rs. {{ number_format($periodPurchases, 0) }}</b></div>
            <div class="stat-chip sc-in">Payments{{ $hasRange ? ' · period' : '' }}<b class="num">Rs. {{ number_format($periodPayments, 0) }}</b></div>
            <div class="stat-chip {{ $vdState === 'stale' ? 'sc-old' : '' }}">Last payment{{ $hasRange ? ' · period' : '' }}<b>{{ $vdLastDate ? $vdLastDate->format('M d') : '—' }}</b>
                @if($vdAgo)<span class="sc-ago {{ $vdAgoCls }}">{{ $vdAgo }}</span>@endif
            </div>
        </div>
        <div class="bal-actions">
            @if($vendor->default_purchase_method === 'by_weight')
                @if(!auth()->user()?->isReadOnly())<button class="btn solid-owe" type="button" onclick="hubOpenWeighted()">⚖ Purchase</button>@endif
                <button class="btn" type="button" onclick="hubOpenProducts()" title="Manage this vendor's products">📦 Products</button>
            @else
                @if(!auth()->user()?->isReadOnly())<button class="btn solid-owe" type="button" onclick="hubOpenPurchase()">＋ Purchase</button>@endif
            @endif
            @if(!auth()->user()?->isReadOnly())<button class="btn solid-in" type="button" onclick="hubOpenPayment()">💵 Payment</button>@endif
            <button class="btn" type="button" onclick="hubOpenReport()">📊 Report</button>
        </div>
    </div>

    <form class="filter-bar" method="GET" action="{{ route('fin.hub.vendor', ['id' => $vendor->id]) }}">
        <input type="hidden" name="scope" value="{{ $scope }}">
        <div class="filter-row">
            <span class="f-field" style="justify-content:center"><label>&nbsp;</label><a class="mini-btn {{ $hasRange ? '' : 'on' }}" href="{{ route('fin.hub.vendor', ['id' => $vendor->id, 'scope' => $scope]) }}">All history</a></span>
            <div class="f-field"><label>From</label><input type="date" name="start_date" value="{{ $startDate }}"></div>
            <div class="f-field"><label>To</label><input type="date" name="end_date" value="{{ $endDate }}"></div>
            <div class="f-field"><label>&nbsp;</label><button type="submit" class="btn primary">Filter</button></div>
            <span class="f-field grow" style="justify-content:center"><label>&nbsp;</label><span class="hint" style="font-size:11.5px;color:var(--ink3)">Balance carries forward from opening — never restarts inside a filtered range.</span></span>
        </div>
    </form>

    {{-- Which bank accounts the NF Assistant believes belong to this vendor.
         Populated when a bank-SMS debit is tagged to them; reviewable/removable
         here so a wrong tag can be caught instead of silently repeating. --}}
    <div class="card" style="padding:12px 14px">
        <div id="nfcaVendor"></div>
    </div>
    @include('partials.counterparty-accounts')
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        nfCounterpartyAccounts.mount(document.getElementById('nfcaVendor'), 'vendor', {{ (int) $vendor->id }});
      });
    </script>

    <div class="card">
        <div class="card-head">
            <h3>Purchases &amp; payments</h3>
            <span class="meta">
                {{ $hasRange ? 'filtered range' : 'all history' }} ·
                {{ number_format($rowCount) }} {{ \Illuminate\Support\Str::plural('entry', $rowCount) }} in
                {{ count($months) }} {{ \Illuminate\Support\Str::plural('month', count($months)) }} ·
                running balance = what NF owes
            </span>
        </div>
        @forelse($months as $i => $m)
            @php
                $mNet = $m['net'];
                if (abs($mNet) < 0.005) { $mCls = 'balanced'; $mTxt = '✓ Even'; }
                elseif ($mNet > 0) { $mCls = 'holding'; $mTxt = 'Owed + Rs. ' . number_format($mNet, 0); }
                else { $mCls = 'balanced'; $mTxt = 'Paid Rs. ' . number_format(abs($mNet), 0); }
                $isOpen = $m['days'] !== null;
            @endphp
            <div class="month-block {{ $isOpen ? 'open' : '' }}" data-ym="{{ $m['ym'] }}">
                <button class="month-head" type="button" onclick="hubToggleMonth(this)" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                    <span class="m-caret">▸</span>
                    <b class="m-label">{{ $m['label'] }}</b>
                    <span class="m-count">{{ $m['count'] }} {{ \Illuminate\Support\Str::plural('entry', $m['count']) }}</span>
                    <span class="m-sums"><span class="s-pur {{ $m['purchases'] > 0 ? '' : 'z' }}">📦 Rs. {{ number_format($m['purchases'], 0) }}</span> · <span class="s-pay {{ $m['payments'] > 0 ? '' : 'z' }}">💵 Rs. {{ number_format($m['payments'], 0) }}</span></span>
                    <span class="day-net {{ $mCls }}">{{ $mTxt }}</span>
                    <span class="m-closing">balance <b class="num" style="color:{{ $m['closing'] > 0.5 ? 'var(--owe)' : 'var(--ink2)' }}">Rs. {{ number_format($m['closing'], 0) }}</b></span>
                </button>
                <div class="month-body">
                    @if($isOpen)
                        @include('fin.hub.partials.vendor-day-groups', ['days' => $m['days'], 'vendor' => $vendor, 'account' => $account, 'oldUrl' => $oldUrl])
                    @endif
                </div>
            </div>
        @empty
            <div class="empty">No purchases or payments{{ $hasRange ? ' in this range' : '' }} yet.</div>
        @endforelse
    </div>

    <script>
    (function(){
        // A collapsed month has no rows in the page at all — that is the point, a busy vendor's full
        // statement is ~1MB of markup. Its rows are fetched once, on first open, from the same
        // computation that rendered the inline months.
        var URL_BASE = @json(route('fin.hub.vendor', ['id' => $vendor->id])) + '/month/';
        var RANGE = @json($hasRange ? ['start_date' => $startDate, 'end_date' => $endDate] : []);

        window.hubToggleMonth = function(btn){
            var block = btn.closest('.month-block');
            var body = block.querySelector('.month-body');
            var open = block.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!open || block.dataset.loaded === '1' || body.children.length) return;

            block.dataset.loaded = '1';
            body.innerHTML = '<div class="month-loading">Loading…</div>';
            var qs = new URLSearchParams(RANGE).toString();
            fetch(URL_BASE + block.dataset.ym + (qs ? '?' + qs : ''), {headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(function(r){ if(!r.ok) throw new Error(r.status); return r.text(); })
                .then(function(html){
                    body.innerHTML = html.trim() || '<div class="month-loading">Nothing in this month.</div>';
                })
                .catch(function(){
                    // Let it be retried rather than leaving a dead month behind.
                    block.dataset.loaded = '';
                    body.innerHTML = '<div class="month-loading" style="color:var(--out)">Could not load — click the month again to retry.</div>';
                });
        };
    })();
    </script>

    @include('fin.hub.partials.vendor-op-modals')
    @if($vendor->default_purchase_method === 'by_weight')
        @include('fin.hub.partials.vendor-products-modal')
    @endif
    @include('fin.hub.partials.drawer')
</div>
@endsection
