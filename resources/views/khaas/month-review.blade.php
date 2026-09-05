@extends('layouts.app')

@section('title', '📊 Frozen Month Review')

@section('content')
{{--
    Frozen · Month Review (Sep-2026)

    ⭐⭐ Production figures come from FrozenMonthService, the SAME class that
    builds the mobile Inventory Report. If a number here ever disagrees with
    Qasim's phone, the bug is in that service, not in two implementations.

    ⚠ Styling is deliberately self-contained below rather than relying on
    Tailwind utilities — several are purged from the built CSS, and the
    layout only stacks demo1_css.
--}}
<style>
    .mr-wrap { max-width: 1180px; margin: 0 auto; padding: 20px 16px 48px; }
    .mr-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
    .mr-title { font-size: 22px; font-weight: 600; color: #111827; margin: 0; }
    .mr-sub { font-size: 13px; color: #6B7280; margin: 2px 0 0; }
    .mr-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .mr-select { padding: 7px 10px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 13px; background: #fff; min-width: 150px; }
    .mr-toggle { display: inline-flex; border: 1px solid #D1D5DB; border-radius: 8px; overflow: hidden; }
    .mr-toggle a { padding: 7px 12px; font-size: 12px; color: #374151; text-decoration: none; background: #fff; }
    .mr-toggle a.mr-on { background: #B45309; color: #fff; }

    .mr-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 12px; }
    /* ⚠⚠ EVERY class here is mr- prefixed ON PURPOSE — Sep-4 bug, do not undo.
       The global Metronic sheet (assets/css/styles.css line ~303) ships Tailwind
       utilities including .fixed, .sticky, .absolute, .relative and .static.
       The Fixed-cost tile was written with a bare modifier — mr-tile plus the
       word fixed — so it picked up .fixed{position:fixed}. That pinned it to the
       viewport (it scrolled with the page) AND took it out of the grid, so the
       share bar and the paragraph below rendered underneath it. Nothing here
       declared `position`, so the utility had no competitor.
       Never use a bare, generic class name on this page. `position: relative`
       below is the belt-and-braces guard: this <style> comes after the linked
       sheet, so an equal-specificity utility can no longer win. */
    .mr-tile { position: relative; background: #F9FAFB; border-radius: 10px; padding: 14px 16px; }
    .mr-tile .mr-lab { font-size: 12px; color: #6B7280; margin: 0; text-transform: uppercase; letter-spacing: .03em; }
    .mr-tile .mr-big { font-size: 25px; font-weight: 600; margin: 3px 0 0; color: #111827; }
    .mr-tile .mr-tile-sub { font-size: 12px; color: #9CA3AF; margin: 3px 0 0; }
    .mr-tile.mr-t-product { background: #EEF2FF; } .mr-tile.mr-t-product .mr-lab { color: #4338CA; } .mr-tile.mr-t-product .mr-big { color: #312E81; } .mr-tile.mr-t-product .mr-tile-sub { color: #4F46E5; }
    .mr-tile.mr-t-fixed   { background: #ECFDF5; } .mr-tile.mr-t-fixed .mr-lab   { color: #047857; } .mr-tile.mr-t-fixed .mr-big   { color: #064E3B; } .mr-tile.mr-t-fixed .mr-tile-sub   { color: #059669; }
    .mr-tile.mr-t-onetime { background: #F3F4F6; } .mr-tile.mr-t-onetime .mr-lab { color: #4B5563; } .mr-tile.mr-t-onetime .mr-big { color: #1F2937; } .mr-tile.mr-t-onetime .mr-tile-sub { color: #6B7280; }
    .mr-tile.mr-t-unknown { background: #FFFBEB; } .mr-tile.mr-t-unknown .mr-lab { color: #B45309; } .mr-tile.mr-t-unknown .mr-big { color: #78350F; } .mr-tile.mr-t-unknown .mr-tile-sub { color: #D97706; }

    .mr-bar { display: flex; height: 10px; border-radius: 6px; overflow: hidden; margin: 14px 0 6px; background: #F3F4F6; }
    .mr-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; color: #6B7280; margin-bottom: 18px; }
    .mr-dot { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 5px; vertical-align: baseline; }

    .mr-card { border: 1px solid #E5E7EB; border-radius: 12px; background: #fff; margin-bottom: 12px; }
    .mr-card > summary { cursor: pointer; padding: 13px 16px; font-size: 15px; font-weight: 600; color: #111827; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .mr-card > summary::-webkit-details-marker { display: none; }
    .mr-card > summary .mr-amt { font-weight: 600; font-variant-numeric: tabular-nums; }
    .mr-body { padding: 0 16px 14px; }
    .mr-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 13.5px; padding: 9px 0; border-top: 1px solid #F3F4F6; color: #374151; }
    .mr-row .mr-num { font-variant-numeric: tabular-nums; white-space: nowrap; color: #111827; }
    .mr-row .mr-name { flex: 1; min-width: 0; }
    .mr-note { font-size: 12px; color: #9CA3AF; margin-top: 2px; }
    .mr-chip { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 999px; background: #FEF3C7; color: #92400E; margin-left: 6px; }
    .mr-chip.mr-chip-grey { background: #F3F4F6; color: #4B5563; }
    .mr-ct { padding: 4px 7px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 11.5px; background: #fff; color: #374151; }

    /* Wide tables scroll inside their own box; the page itself never scrolls
       sideways on a laptop or a phone.
       ⚠ `contain: inline-size` is doing the real work and is NOT optional. The
       Metronic shell wraps the content in three nested flex items (main.grow,
       .kt-wrapper, .flex.grow) that all have the default min-width:auto, so a
       wide table's min-content width bubbles all the way up and stretches the
       whole document — measured 714px at a 375px viewport, and it did that
       before this box existed too. Containment stops that contribution at the
       box, so the ancestors shrink and the table scrolls here instead.
       Verified: 375px viewport goes from 714 -> 375 with no change on desktop. */
    .mr-scroll { overflow-x: auto; contain: inline-size; }
    .mr-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 620px; }
    .mr-table th { text-align: right; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #6B7280; font-weight: 600; padding: 8px 6px; border-bottom: 1px solid #E5E7EB; }
    .mr-table th:first-child, .mr-table td:first-child { text-align: left; }
    .mr-table td { padding: 9px 6px; border-bottom: 1px solid #F3F4F6; text-align: right; font-variant-numeric: tabular-nums; color: #374151; }
    .mr-table tfoot td { font-weight: 600; color: #111827; border-top: 1px solid #E5E7EB; border-bottom: none; }

    .mr-flow { background: #F9FAFB; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #4B5563; margin-bottom: 12px; }
    .mr-flow b { color: #111827; }
    .mr-info { background: #EFF6FF; border: 1px solid #BFDBFE; color: #1E40AF; border-radius: 10px; padding: 11px 14px; font-size: 12.5px; margin-bottom: 12px; }
    .mr-warn { background: #FFFBEB; border: 1px solid #FDE68A; color: #92400E; border-radius: 10px; padding: 11px 14px; font-size: 12.5px; margin-bottom: 12px; }
    .mr-stale { position: sticky; top: 0; z-index: 30; display: none; background: #B45309; color: #fff; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 12px; align-items: center; justify-content: space-between; gap: 10px; }
    .mr-stale button { background: #fff; color: #B45309; border: none; border-radius: 6px; padding: 5px 12px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
    .mr-meat { background: #FAFAF9; border-radius: 10px; padding: 12px 14px; margin-top: 10px; }
</style>

@php
    $h        = $review['headline'];
    $totals   = $review['production']['totals'];
    $products = collect($review['production']['products'])->sortByDesc('stock_in')->values();
    $meat     = $review['meat'];
    $buckets  = $review['costs']['buckets'] ?? [];
    $made     = (int) $h['made'];

    // [label, colour]. The tiles carry their own mr-t-* class in the markup, so
    // there is deliberately no CSS-class slot here to drift out of sync.
    $bucketMeta = [
        'product'      => ['Product cost', '#6366F1'],
        'fixed'        => ['Fixed cost', '#10B981'],
        'one_time'     => ['One-time cost', '#9CA3AF'],
        'unclassified' => ['Not classified yet', '#F59E0B'],
    ];
    $spend = max((float) ($h['total_spend'] ?? 0), 0.01);
@endphp

<div class="mr-wrap">

    <div class="mr-head">
        <div>
            <h1 class="mr-title">📊 {{ $khaasBU->name }} · Month Review</h1>
            <p class="mr-sub">What the warehouse made in {{ $monthLabel }}, and what the month cost.</p>
        </div>
        <div class="mr-controls">
            <form method="GET" action="{{ route('khaas.month-review') }}" id="mrForm">
                <input type="hidden" name="basis" value="{{ $basis }}">
                <select name="month" class="mr-select" onchange="this.form.submit()">
                    @foreach($availableMonths as $val => $label)
                        <option value="{{ $val }}" {{ $selectedMonth === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            @if($canSeeCosts)
                <span class="mr-toggle">
                    <a href="{{ route('khaas.month-review', ['month' => $selectedMonth, 'basis' => 'bought']) }}"
                       class="{{ $basis === 'bought' ? 'mr-on' : '' }}">Meat as bought</a>
                    <a href="{{ route('khaas.month-review', ['month' => $selectedMonth, 'basis' => 'used']) }}"
                       class="{{ $basis === 'used' ? 'mr-on' : '' }}">as used</a>
                </span>
            @endif
        </div>
    </div>

    <div class="mr-stale" id="mrStale">
        <span>Classification changed — the numbers below are out of date.</span>
        <button type="button" onclick="window.location.reload()">Refresh</button>
    </div>

    @if($canSeeCosts && empty($review['costs']['map_available']))
        <div class="mr-warn">
            The cost-type table is not installed on this server yet, so every bill shows as
            <b>not classified</b>. Run <b>frozen_month_review_sep2026.sql</b>, then reload.
        </div>
    @endif

    {{-- ── Headline ───────────────────────────────────────────── --}}
    <div class="mr-tiles">
        <div class="mr-tile">
            <p class="mr-lab">Packs made</p>
            <p class="mr-big">{{ number_format($made) }}</p>
            <p class="mr-tile-sub">{{ number_format($h['made_batch']) }} through a batch · {{ number_format($h['made_manual']) }} entered by hand</p>
        </div>
        <div class="mr-tile">
            <p class="mr-lab">Shelf value of what was made</p>
            <p class="mr-big">Rs {{ number_format($h['made_value']) }}</p>
            <p class="mr-tile-sub">Rs {{ number_format($h['avg_price']) }} average per pack</p>
        </div>
        @if($canSeeCosts)
            <div class="mr-tile">
                <p class="mr-lab">Total spend</p>
                <p class="mr-big">Rs {{ number_format($h['total_spend']) }}</p>
                <p class="mr-tile-sub">all cost types together</p>
            </div>
        @endif
    </div>

    @if($canSeeCosts)
        <div class="mr-tiles">
            <div class="mr-tile mr-t-product">
                <p class="mr-lab">Product cost</p>
                <p class="mr-big">Rs {{ number_format($h['product']) }}</p>
                <p class="mr-tile-sub">Rs {{ number_format($h['product_per_pack']) }} per pack made</p>
            </div>
            <div class="mr-tile mr-t-fixed">
                <p class="mr-lab">Fixed cost</p>
                <p class="mr-big">Rs {{ number_format($h['fixed']) }}</p>
                <p class="mr-tile-sub">Rs {{ number_format($h['fixed_per_pack']) }} per pack made</p>
            </div>
            <div class="mr-tile mr-t-onetime">
                <p class="mr-lab">One-time cost</p>
                <p class="mr-big">Rs {{ number_format($h['one_time']) }}</p>
                <p class="mr-tile-sub">kept out of the per-pack figures</p>
            </div>
            @if($h['unclassified'] > 0)
                <div class="mr-tile mr-t-unknown">
                    <p class="mr-lab">Not classified yet</p>
                    <p class="mr-big">Rs {{ number_format($h['unclassified']) }}</p>
                    <p class="mr-tile-sub">set a type below to file it</p>
                </div>
            @endif
        </div>

        <div class="mr-bar">
            @foreach($bucketMeta as $key => $meta)
                @php $val = (float) ($buckets[$key]['total'] ?? 0); @endphp
                @if($val > 0)
                    <div style="width: {{ round($val / $spend * 100, 2) }}%; background: {{ $meta[1] }};"></div>
                @endif
            @endforeach
        </div>
        <div class="mr-legend">
            @foreach($bucketMeta as $key => $meta)
                @php $val = (float) ($buckets[$key]['total'] ?? 0); @endphp
                @if($val > 0)
                    <span><span class="mr-dot" style="background: {{ $meta[1] }};"></span>{{ $meta[0] }} · Rs {{ number_format($val) }} ({{ round($val / $spend * 100) }}%)</span>
                @endif
            @endforeach
        </div>

        @if($made > 0)
            <div class="mr-info">
                Every pack made this month carries <b>Rs {{ number_format($h['product_per_pack']) }}</b> of product cost
                and <b>Rs {{ number_format($h['fixed_per_pack']) }}</b> of fixed cost, against an average shelf price of
                <b>Rs {{ number_format($h['avg_price']) }}</b>.
                @if($h['breakeven_packs'])
                    At this month's prices and product cost, <b>{{ number_format($h['breakeven_packs']) }} packs</b> would
                    cover the fixed base.
                @else
                    Product cost per pack is at or above the average shelf price, so no number of packs covers the fixed
                    base until product cost comes down or prices go up.
                @endif
                One-time cost is excluded from both per-pack figures.
            </div>
        @endif
    @endif

    {{-- ── What happened to the packs ─────────────────────────── --}}
    <div class="mr-flow">
        Made <b>{{ number_format($made) }}</b>
        · sent to the shop <b>{{ number_format($totals['transferred']) }}</b>
        · sold <b>{{ number_format($totals['sold']) }}</b>
        @if($totals['sold_free'] > 0)(<b>{{ number_format($totals['sold_free']) }}</b> free)@endif
        · counts and corrections <b>{{ $totals['counts'] > 0 ? '+' : '' }}{{ number_format($totals['counts']) }}</b>
        · stock now <b>{{ number_format($totals['warehouse_stock']) }}</b> warehouse
        and <b>{{ number_format($totals['shop_stock']) }}</b> shop.
        <div class="mr-note">
            Made, sent and sold are the month. Stock is today's live count, not the month-end figure — the same
            numbers, from the same code, as the Inventory Report on the phone.
        </div>
    </div>

    {{-- ── Made by product ────────────────────────────────────── --}}
    <details class="mr-card" open>
        <summary>
            <span>{{ number_format($made) }} packs made · by product</span>
            <span class="mr-amt">Rs {{ number_format($h['made_value']) }}</span>
        </summary>
        <div class="mr-body">
            <div class="mr-scroll">
            <table class="mr-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Made</th>
                        <th>Shelf value</th>
                        <th>To shop</th>
                        <th>Sold</th>
                        <th>Stock now</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($products as $p)
                    <tr>
                        <td>
                            {{ $p['product_name'] }}
                            @if($p['stock_in_manual'] > 0 && $p['stock_in_batch'] > 0)
                                <span class="mr-chip mr-chip-grey">{{ $p['stock_in_manual'] }} by hand</span>
                            @elseif($p['stock_in_manual'] > 0 && $p['stock_in'] > 0)
                                <span class="mr-chip mr-chip-grey">all by hand</span>
                            @endif
                            @if($p['adjustments_counts'] != 0)
                                <span class="mr-chip">{{ $p['adjustments_counts'] > 0 ? '+' : '' }}{{ $p['adjustments_counts'] }} counted</span>
                            @endif
                        </td>
                        <td>{{ number_format($p['selling_price']) }}</td>
                        <td>{{ number_format($p['stock_in']) }}</td>
                        <td>{{ number_format($p['made_value']) }}</td>
                        <td>{{ number_format($p['transferred_to_shop']) }}</td>
                        <td>
                            {{ number_format($p['sold']) }}
                            @if($p['sold_free'] > 0)<span class="mr-chip">{{ number_format($p['sold_free']) }} free</span>@endif
                        </td>
                        <td>{{ number_format($p['current_warehouse_qty'] + $p['current_shop_qty']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td></td>
                        <td>{{ number_format($made) }}</td>
                        <td>{{ number_format($h['made_value']) }}</td>
                        <td>{{ number_format($totals['transferred']) }}</td>
                        <td>{{ number_format($totals['sold']) }}</td>
                        <td>{{ number_format($totals['warehouse_stock'] + $totals['shop_stock']) }}</td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
    </details>

    {{-- ── Cost sections ──────────────────────────────────────── --}}
    @if($canSeeCosts)
        @foreach($bucketMeta as $key => $meta)
            @php $bucket = $buckets[$key] ?? ['total' => 0, 'rows' => []]; @endphp
            @if(!empty($bucket['rows']))
                <details class="mr-card" open>
                    <summary>
                        <span style="color: {{ $meta[1] }};">{{ $meta[0] }}</span>
                        <span class="mr-amt">Rs {{ number_format($bucket['total']) }}</span>
                    </summary>
                    <div class="mr-body">
                        @if($key === 'unclassified')
                            <div class="mr-warn" style="margin: 8px 0 0;">
                                These bills are counted in the total but belong to no type yet. Give each one a type and
                                it files itself, for this month and every past month.
                            </div>
                        @endif

                        @foreach($bucket['rows'] as $row)
                            <div class="mr-row">
                                <span class="mr-name">
                                    {{ $row['label'] }}
                                    @if(!empty($row['bills']))
                                        <span class="mr-chip mr-chip-grey">{{ $row['bills'] }} {{ $row['bills'] == 1 ? 'entry' : 'entries' }}</span>
                                    @endif
                                    @if(!empty($row['detail']))
                                        <div class="mr-note">{{ implode(' · ', array_slice($row['detail'], 0, 4)) }}</div>
                                    @endif
                                </span>
                                <span class="mr-num">Rs {{ number_format($row['amount']) }}</span>
                                <select class="mr-ct" onchange="mrSetCostType(this)"
                                        data-kind="{{ $row['source_kind'] }}"
                                        data-key="{{ $row['source_key'] }}">
                                    @foreach($costTypes as $t)
                                        <option value="{{ $t }}" {{ $row['cost_type'] === $t ? 'selected' : '' }}>
                                            {{ $t === 'product' ? 'Product' : ($t === 'fixed' ? 'Fixed' : 'One-time') }}
                                        </option>
                                    @endforeach
                                    @if($row['cost_type'] === 'unclassified')
                                        <option value="unclassified" selected disabled>Choose a type</option>
                                    @endif
                                </select>
                            </div>
                        @endforeach

                        {{-- Meat bought vs used sits with the product cost it explains. --}}
                        @if($key === 'product' && !empty($meat['rows']))
                            <div class="mr-meat">
                                <div style="font-weight: 600; font-size: 13.5px; color: #111827; margin-bottom: 6px;">
                                    Raw meat this month
                                </div>
                                <div style="font-size: 13px; color: #4B5563;">
                                    Bought <b>{{ number_format($meat['bought_kg'], 2) }} kg</b>
                                    · used in production <b>{{ number_format($meat['used_kg'], 2) }} kg</b>
                                    (worth Rs {{ number_format($meat['used_value']) }})
                                    · in storage now <b>{{ number_format($meat['on_hand_kg'], 2) }} kg</b>.
                                </div>
                                <div class="mr-note">
                                    @if($basis === 'used')
                                        Showing meat <b>as used</b>: the meat line above is what production consumed,
                                        {{ $h['meat_adjustment'] >= 0 ? 'Rs ' . number_format(abs($h['meat_adjustment'])) . ' more than' : 'Rs ' . number_format(abs($h['meat_adjustment'])) . ' less than' }}
                                        what was bought. Every other row is unchanged.
                                    @else
                                        Showing meat <b>as bought</b>, which matches the vendor figure on the HQ screen.
                                        Buying more than you use is stock, not cost — switch to "as used" to value the
                                        month by what production actually consumed.
                                    @endif
                                </div>
                                <div class="mr-scroll" style="margin-top: 10px;">
                                <table class="mr-table">
                                    <thead>
                                        <tr><th>Raw material</th><th>Bought kg</th><th>Used kg</th><th>Rs / kg</th><th>Used value</th></tr>
                                    </thead>
                                    <tbody>
                                    @foreach($meat['rows'] as $m)
                                        <tr>
                                            <td>{{ $m['name'] }}</td>
                                            <td>{{ number_format($m['received_kg'], 2) }}</td>
                                            <td>{{ number_format($m['used_kg'], 2) }}</td>
                                            <td>{{ number_format($m['rate_per_kg']) }}</td>
                                            <td>{{ number_format($m['used_value']) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        @endif
                    </div>
                </details>
            @endif
        @endforeach
    @else
        <div class="mr-info">
            You are seeing the production half of this screen. The cost breakdown includes staff salaries, so it is
            limited to the people who hold the Frozen month-review permission.
        </div>
    @endif

</div>

{{-- Only shipped to someone who may re-classify. The endpoint re-checks the
     permission on its own, so this is presentation, not the actual gate. --}}
@if($canSeeCosts)
<script>
(function () {
    'use strict';

    var CSRF = @json(csrf_token());
    var URL_SET = @json(route('khaas.month-review.cost-type'));

    window.mrSetCostType = function (sel) {
        var kind = sel.getAttribute('data-kind');
        var key = sel.getAttribute('data-key');
        var type = sel.value;
        if (!kind || !key || !type || type === 'unclassified') { return; }

        sel.disabled = true;
        var body = new URLSearchParams();
        body.append('source_kind', kind);
        body.append('source_key', key);
        body.append('cost_type', type);

        fetch(URL_SET, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        })
        .then(function (r) { return r.json().catch(function () { return {success: false, message: 'Unexpected reply'}; }); })
        .then(function (d) {
            sel.disabled = false;
            if (d && d.success) {
                var bar = document.getElementById('mrStale');
                if (bar) { bar.style.display = 'flex'; }
            } else {
                alert((d && d.message) ? d.message : 'Could not save that change.');
            }
        })
        .catch(function () {
            sel.disabled = false;
            alert('Could not save that change. Check your connection and try again.');
        });
    };
})();
</script>
@endif
@endsection
