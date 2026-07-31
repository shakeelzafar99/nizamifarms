@extends('layouts.app')
@section('title', 'Ledger Hub — ' . $vendor->vendor_name)
@include('fin.hub.partials.styles')

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
        'oldNavUrl' => $oldUrl, 'oldNavLabel' => 'Old vendor page ↗'])

    <a class="back-link" href="{{ route('fin.hub.vendors', ['scope' => $scope]) }}">‹ Vendors</a>

    <div class="bal-head">
        <div class="bal-main">
            <div class="b-label">NF owes</div>
            <div class="num-lg num" style="color:{{ $payable > 0.5 ? 'var(--owe)' : 'var(--in)' }}">Rs. {{ number_format($payable, 2) }}</div>
            <div class="b-note">{{ $vendor->vendor_name }} <span class="mono" style="color:var(--ink3)">{{ optional($account)->account_code }}</span> · {{ $vendor->default_purchase_method === 'by_weight' ? 'by weight' : 'by total' }}</div>
        </div>
        <div class="bal-chips">
            <div class="stat-chip">Purchases{{ $hasRange ? ' · period' : '' }}<b class="num">Rs. {{ number_format($periodPurchases, 0) }}</b></div>
            <div class="stat-chip">Payments{{ $hasRange ? ' · period' : '' }}<b class="num">Rs. {{ number_format($periodPayments, 0) }}</b></div>
            <div class="stat-chip">Last payment<b>{{ $lastPayment ? \Carbon\Carbon::parse($lastPayment->transaction_date)->format('M d') : '—' }}</b></div>
        </div>
        <div class="bal-actions">
            @if($vendor->default_purchase_method === 'by_weight')
                @if(!auth()->user()?->isReadOnly())<button class="btn primary" type="button" onclick="hubOpenWeighted()">⚖ Purchase</button>@endif
                <button class="btn" type="button" onclick="hubOpenProducts()" title="Manage this vendor's products">📦 Products</button>
            @else
                @if(!auth()->user()?->isReadOnly())<button class="btn primary" type="button" onclick="hubOpenPurchase()">＋ Purchase</button>@endif
            @endif
            @if(!auth()->user()?->isReadOnly())<button class="btn" type="button" onclick="hubOpenPayment()">💵 Payment</button>@endif
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
                    <span class="m-sums">📦 Rs. {{ number_format($m['purchases'], 0) }} · 💵 Rs. {{ number_format($m['payments'], 0) }}</span>
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
