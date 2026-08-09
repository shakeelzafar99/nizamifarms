@extends('layouts.app')
@section('title', 'Ledger Hub — Vendors')
@include('fin.hub.partials.styles')

@php $money0 = fn ($n) => 'Rs. ' . number_format($n, 0); @endphp

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    @if($noVendors)
        <div class="note-card"><b>No vendors in Qurbani.</b> Qurbani has no vendor purchases — its costs are booked as Qurbani expenses (see the Overview P&amp;L in Qurbani scope).</div>
    @else
        @php
            // ONE definition of a vendor's state, used by the tiles, the chips and every row.
            // Owing is normal; owing with no payment for a month is what actually needs a decision,
            // so it gets its own (red) state instead of hiding inside the amber pile.
            $staleDays = 30;
            $stateFn = function (array $v) use ($staleDays) {
                if ($v['payable'] > 0.5) {
                    $days = $v['last_pay']
                        ? (int) abs(\Carbon\Carbon::parse($v['last_pay'])->startOfDay()->diffInDays(\Carbon\Carbon::now()->startOfDay()))
                        : null;
                    return ($days === null || $days >= $staleDays) ? 'stale' : 'owes';
                }
                return ($v['purchases'] > 0 || $v['payments'] > 0) ? 'settled' : 'idle';
            };
            $byState = $vendors->groupBy(fn ($v) => $stateFn($v));
            $stCount = fn (string $k) => ($byState[$k] ?? collect())->count();
            $stSum   = fn (string $k) => (float) ($byState[$k] ?? collect())->sum('payable');

            $nStale   = $stCount('stale');
            $nOwesAll = $stCount('owes') + $nStale;                 // same rule as $totals['with_balance']
            $mOwesAll = $stSum('owes') + $stSum('stale');
            $nSettled = $stCount('settled') + $stCount('idle');

            $nWeight = $vendors->where('method', 'by_weight')->count();
            $nTotal  = $vendors->count() - $nWeight;
        @endphp

        @if(!auth()->user()?->isReadOnly())
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
            <button class="btn primary" type="button" onclick="hubOpenVendorCreate()">＋ New vendor</button>
        </div>
        @endif

        {{-- State tiles. These are the old info tiles, now also the primary filter — one click
             answers "who do we owe?" without scanning the table. --}}
        <div class="vstate">
            <button class="vstile s-all on" type="button" data-vfilter="all">
                <div class="v-label">🏪 All vendors</div>
                <div class="v-count num">{{ $vendors->count() }}</div>
                <div class="v-money num">owed {{ $money0($totals['owed']) }}</div>
            </button>
            <button class="vstile s-owes" type="button" data-vfilter="owes" data-empty="{{ $nOwesAll ? 0 : 1 }}">
                <div class="v-label">🟠 Owes now</div>
                <div class="v-count num">{{ $nOwesAll }}</div>
                <div class="v-money num">{{ $money0($mOwesAll) }}</div>
            </button>
            <button class="vstile s-stale" type="button" data-vfilter="stale" data-empty="{{ $nStale ? 0 : 1 }}">
                <div class="v-label">🔴 Owes · {{ $staleDays }}d+ silent</div>
                <div class="v-count num">{{ $nStale }}</div>
                <div class="v-money num">{{ $money0($stSum('stale')) }}</div>
            </button>
            <button class="vstile s-settled" type="button" data-vfilter="settled" data-empty="{{ $nSettled ? 0 : 1 }}">
                <div class="v-label">🟢 Settled / idle</div>
                <div class="v-count num">{{ $nSettled }}</div>
                <div class="v-money num">nothing outstanding</div>
            </button>
        </div>

        {{-- Period figures (the old Purchases / Payments tiles) — same numbers, one quiet line. --}}
        <div class="period-strip num">
            <span class="pchip">📦 Purchases · period <b>{{ $money0($totals['purchases']) }}</b></span>
            <span class="pchip">💵 Payments · period <b class="g">{{ $money0($totals['payments']) }}</b></span>
            @php $net = $totals['purchases'] - $totals['payments']; @endphp
            @if(abs($net) >= 1)
                <span class="pchip">net <b class="{{ $net > 0 ? 'o' : 'g' }}">{{ $net > 0 ? '+' : '−' }} {{ $money0(abs($net)) }} {{ $net > 0 ? 'on account' : 'paid down' }}</b></span>
            @endif
        </div>

        <form class="filter-bar" method="GET" action="{{ route('fin.hub.vendors') }}">
            <input type="hidden" name="scope" value="{{ $scope }}">
            <div class="filter-row">
                <div class="f-field grow"><label>Search vendor</label><input type="text" name="search" value="{{ $search }}" placeholder="name, contact…"></div>
                <div class="f-field"><label>From</label><input type="date" name="start_date" value="{{ $startDate }}"></div>
                <div class="f-field"><label>To</label><input type="date" name="end_date" value="{{ $endDate }}"></div>
                <div class="f-field"><label>&nbsp;</label>
                    <div style="display:flex;gap:6px">
                        <button type="submit" class="btn primary">Filter</button>
                        <a class="btn" href="{{ route('fin.hub.vendors', ['scope' => $scope]) }}">Clear</a>
                    </div>
                </div>
            </div>
            {{-- Chips narrow the list already on screen (no reload). The search/date fields above
                 still do the server-side filtering. --}}
            <div class="chip-rows">
                <div class="chip-row">
                    <span class="c-label">Type</span>
                    <button class="vchip on" type="button" data-vmethod="all">All</button>
                    <button class="vchip c-weight" type="button" data-vmethod="by_weight">⚖ Weight <span class="cnt num">{{ $nWeight }}</span></button>
                    <button class="vchip c-total" type="button" data-vmethod="by_total">📦 Total <span class="cnt num">{{ $nTotal }}</span></button>
                </div>
                @if($vendorGroups)
                <div class="chip-row">
                    <span class="c-label">Unit</span>
                    <button class="vchip on" type="button" data-vbu="all">Both</button>
                    @foreach($vendorGroups as $g)
                        @php
                            $gBu   = $g['rows']->first()['bu'] ?? 0;
                            $gOwed = (float) $g['rows']->sum('payable');
                        @endphp
                        <button class="vchip" type="button" data-vbu="{{ $gBu }}">
                            <span class="cdot" style="background:{{ $gBu == 1 ? 'var(--accent)' : 'var(--info)' }}"></span>{{ $g['label'] }}
                            @if($gOwed > 0.5)· {{ $money0($gOwed) }}@endif
                            <span class="cnt num">{{ $g['rows']->count() }}</span>
                        </button>
                    @endforeach
                </div>
                @endif
            </div>
        </form>

        <div class="card">
            <div class="card-head">
                <h3>Vendors <span class="meta" id="hubVenShown">· {{ $vendors->count() }} shown</span></h3>
                <div class="legend">
                    <span class="l-owe"><i></i>owes</span>
                    <span class="l-stale"><i></i>owes · {{ $staleDays }}d+ no payment</span>
                    <span class="l-ok"><i></i>settled</span>
                    <span class="l-idle"><i></i>idle this period</span>
                </div>
            </div>
            <div class="table-wrap">
                <table class="vendors-table" id="hubVendorTable">
                    <thead><tr>
                        <th>Vendor</th><th>Status</th><th class="r">NF owes</th><th class="r">Purchases</th><th class="r">Payments</th><th>Last payment</th><th class="r">Actions</th>
                    </tr></thead>
                    <tbody>
                    @if($vendors->isEmpty())
                        <tr><td colspan="7"><div class="empty">No vendors match.</div></td></tr>
                    @elseif($vendorGroups)
                        {{-- Combined scope: NF first, then Frozen, with a quiet unit header between. --}}
                        @foreach($vendorGroups as $g)
                            @php $gBu = $g['rows']->first()['bu'] ?? 0; @endphp
                            <tr class="bu-sep{{ $loop->first ? ' first' : '' }}" data-bu-head="{{ $gBu }}" data-bu-total="{{ $g['rows']->count() }}">
                                <td colspan="7">
                                    <span class="bu-name">{{ $g['label'] }}</span>
                                    <span class="bu-count">{{ $g['rows']->count() }} {{ \Illuminate\Support\Str::plural('vendor', $g['rows']->count()) }}</span>
                                    @php $gOwed = (float) $g['rows']->sum('payable'); @endphp
                                    @if($gOwed > 0.5)<span class="bu-owed num">owes Rs. {{ number_format($gOwed, 0) }}</span>@endif
                                </td>
                            </tr>
                            @include('fin.hub.partials.vendor-rows', ['rows' => $g['rows'], 'scope' => $scope, 'stateFn' => $stateFn])
                        @endforeach
                    @else
                        @include('fin.hub.partials.vendor-rows', ['rows' => $vendors, 'scope' => $scope, 'stateFn' => $stateFn])
                    @endif
                    </tbody>
                </table>
                {{-- Sits outside the tbody so it can never disturb the last row's border. --}}
                <div class="empty" id="hubVenNoMatch" style="display:none">No vendors in this filter. <b>Clear the chips above</b> to see them all.</div>
            </div>
        </div>
        <p class="footnote" style="font-size:12px;color:var(--ink3);margin-top:12px"><b>Note:</b> “NF owes” is the true current payable (all-history), not the period net.</p>
        @include('fin.hub.partials.vendor-crud-modals')

        <script>
        // Vendors list filters. Client-side only — the whole list is already on the page (it is not
        // paginated), so hiding rows can never hide a vendor the server did not send. The search and
        // date fields above still round-trip to the server.
        (function () {
            var table = document.getElementById('hubVendorTable');
            if (!table) return;
            var rows    = table.querySelectorAll('tr.v-row');
            var heads   = table.querySelectorAll('tr.bu-sep');
            var shownEl = document.getElementById('hubVenShown');
            var noMatch = document.getElementById('hubVenNoMatch');
            var f = { state: 'all', method: 'all', bu: 'all' };

            function matchState(rowState) {
                if (f.state === 'all')     return true;
                if (f.state === 'owes')    return rowState === 'owes' || rowState === 'stale';
                if (f.state === 'stale')   return rowState === 'stale';
                if (f.state === 'settled') return rowState === 'settled' || rowState === 'idle';
                return true;
            }

            function apply() {
                var shown = 0, perBu = {};
                rows.forEach(function (r) {
                    var ok = matchState(r.dataset.state)
                        && (f.method === 'all' || r.dataset.method === f.method)
                        && (f.bu === 'all' || r.dataset.bu === f.bu);
                    r.style.display = ok ? '' : 'none';
                    if (ok) { shown++; perBu[r.dataset.bu] = (perBu[r.dataset.bu] || 0) + 1; }
                });
                heads.forEach(function (h) {
                    var n = perBu[h.dataset.buHead] || 0;
                    h.style.display = n ? '' : 'none';
                    var c = h.querySelector('.bu-count');
                    if (c) {
                        var total = h.dataset.buTotal;
                        c.textContent = (n === Number(total) ? n : n + ' of ' + total)
                            + (Number(total) === 1 ? ' vendor' : ' vendors');
                    }
                });
                shownEl.textContent = '· ' + shown + ' shown';
                // rows.length guard: with a server-side empty result the table already shows its
                // own "No vendors match." row — don't stack a second message under it.
                noMatch.style.display = (shown || !rows.length) ? 'none' : 'block';
            }

            function wire(selector, attr, key) {
                var btns = document.querySelectorAll(selector);
                btns.forEach(function (b) {
                    b.addEventListener('click', function () {
                        btns.forEach(function (x) { x.classList.remove('on'); });
                        b.classList.add('on');
                        f[key] = b.dataset[attr];
                        apply();
                    });
                });
            }
            wire('.vstile[data-vfilter]', 'vfilter', 'state');
            wire('.vchip[data-vmethod]', 'vmethod', 'method');
            wire('.vchip[data-vbu]',     'vbu',     'bu');
        })();
        </script>
    @endif
</div>
@endsection
