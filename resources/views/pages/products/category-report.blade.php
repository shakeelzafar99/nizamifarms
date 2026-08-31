@extends('layouts.app')

@section('title', 'Category Report')

{{--
    ⚠ custom_css, NOT styles — the layout renders no @stack('styles').
    Everything here is scoped, self-contained CSS on purpose: most Tailwind
    utilities are purged from the loaded stylesheet, so class-based colours
    and spacing silently do nothing.
--}}
@push('custom_css')
<style>
    .cr-page { --cr-sold:#1379F0; --cr-bought:#F59E0B; --cr-pos:#059669; --cr-neg:#DC2626;
               --cr-line:#E5E7EB; --cr-muted:#6B7280; --cr-head:#F9FAFB; --cr-text:#111827;
               padding-bottom:48px; color:var(--cr-text); }

    .cr-header { background:linear-gradient(135deg,#1e3a8a 0%,#1379F0 100%); border-radius:14px;
                 padding:18px 22px; margin-bottom:18px; display:flex; flex-wrap:wrap;
                 align-items:center; justify-content:space-between; gap:14px; }
    .cr-header h1 { color:#fff; font-size:20px; font-weight:600; margin:0; }
    .cr-header p  { color:rgba(255,255,255,.82); font-size:12.5px; margin:3px 0 0; }
    .cr-header-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .cr-hbtn { background:rgba(255,255,255,.18); color:#fff; border:1px solid rgba(255,255,255,.28);
               border-radius:9px; padding:7px 13px; font-size:12.5px; cursor:pointer;
               text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
    .cr-hbtn:hover { background:rgba(255,255,255,.3); color:#fff; }

    .cr-card { background:#fff; border:1px solid var(--cr-line); border-radius:12px;
               margin-bottom:16px; overflow:hidden; }
    .cr-card-head { padding:12px 16px; border-bottom:1px solid var(--cr-line); background:var(--cr-head);
                    display:flex; align-items:center; justify-content:space-between; gap:12px;
                    flex-wrap:wrap; }
    .cr-card-head h2 { font-size:14px; font-weight:600; margin:0; }
    .cr-card-head .cr-sub { font-size:11.5px; color:var(--cr-muted); font-weight:400; }
    .cr-card-body { padding:14px 16px; }

    /* ---- filters ---- */
    .cr-filters { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
    .cr-field { display:flex; flex-direction:column; gap:4px; }
    .cr-field label { font-size:11px; font-weight:600; color:var(--cr-muted);
                      text-transform:uppercase; letter-spacing:.03em; }
    .cr-field select, .cr-field input[type=date] {
        border:1px solid #D1D5DB; border-radius:8px; padding:7px 10px; font-size:13px;
        background:#fff; color:var(--cr-text); min-width:140px; height:36px; }
    .cr-apply { background:#1379F0; color:#fff; border:none; border-radius:8px; padding:0 18px;
                height:36px; font-size:13px; font-weight:600; cursor:pointer; }
    .cr-apply:hover { background:#0f63c9; }
    .cr-seg { display:inline-flex; border:1px solid #D1D5DB; border-radius:8px; overflow:hidden; height:36px; }
    .cr-seg button { border:none; background:#fff; padding:0 13px; font-size:12.5px; cursor:pointer;
                     color:var(--cr-muted); }
    .cr-seg button.on { background:#1379F0; color:#fff; font-weight:600; }

    /* ---- notices ---- */
    .cr-note { border-radius:10px; padding:11px 14px; font-size:12.5px; margin-bottom:14px;
               display:flex; gap:9px; align-items:flex-start; line-height:1.5; }
    .cr-note-warn { background:#FFFBEB; border:1px solid #FDE68A; color:#92400E; }
    .cr-note-info { background:#EFF6FF; border:1px solid #BFDBFE; color:#1E40AF; }
    .cr-note b { font-weight:600; }

    /* ---- stat tiles ---- */
    .cr-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px;
                margin-bottom:16px; }
    .cr-stat { background:#fff; border:1px solid var(--cr-line); border-radius:12px; padding:14px 16px; }
    .cr-stat .k { font-size:11px; text-transform:uppercase; letter-spacing:.04em;
                  color:var(--cr-muted); font-weight:600; }
    .cr-stat .v { font-size:22px; font-weight:700; margin-top:5px; letter-spacing:-.01em; }
    .cr-stat .s { font-size:11.5px; color:var(--cr-muted); margin-top:3px; }
    .cr-stat.sold   .v { color:var(--cr-sold); }
    .cr-stat.bought .v { color:var(--cr-bought); }

    /* ---- tables ----
       ⚠ The sticky header only works if THIS box is the thing that scrolls.
       `overflow-x:auto` alone already makes it a scroll container (the spec
       computes overflow-y to `auto` too), but with no height it never scrolls,
       so the header stuck to a box that never moved and slid away with the page.
       Capping the height of the long table gives the header something to stick
       to — see .cr-scroll-tall. */
    .cr-scroll { overflow-x:auto; }
    .cr-scroll-tall { max-height:calc(100vh - 190px); overflow:auto; }
    .cr-table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
    .cr-table th, .cr-table td { padding:9px 12px; text-align:right; white-space:nowrap;
                                 border-bottom:1px solid #F3F4F6; }
    .cr-table th { background:var(--cr-head); font-size:11px; text-transform:uppercase;
                   letter-spacing:.03em; color:var(--cr-muted); font-weight:600;
                   position:sticky; top:0; z-index:3; box-shadow:inset 0 -1px 0 #E5E7EB; }
    .cr-table th.l, .cr-table td.l { text-align:left; }
    .cr-table tbody tr:hover { background:#FAFBFC; }
    .cr-table .num { font-variant-numeric:tabular-nums; }
    .cr-qty { color:var(--cr-muted); font-size:11.5px; display:block; margin-top:2px; }
    /* ⚠ The day/week/month band is deliberately NOT sticky. Tried it both on the
       <td> and on the <tr>, with one <tbody> per period; in every variant Chrome
       pinned EVERY band at the same offset instead of letting each one push the
       last out (measured in the browser: 22 bands all at y=33). Since they paint
       in DOM order, the OLDEST date ended up on top — a wrong date pinned over
       the rows you are reading, which is worse than no band at all. The sticky
       header alone (verified working) is what makes the columns readable. */
    .cr-group td { background:#F3F4F6; font-weight:600; font-size:12px; color:#374151;
                   border-top:1px solid #E5E7EB; }
    /* ❄️ Freezer column — tinted so it stays identifiable while scrolling, and
       so the eye can jump straight from "sold qty" to it. */
    .cr-frz { background:#F0F9FF; color:#0369A1; }
    th.cr-frz { background:#E0F2FE; color:#075985; }
    .cr-group td.cr-frz { background:#E0F2FE; }
    .cr-total-row td.cr-frz { background:#E0F2FE; }

    /* Chiller sits beside the freezer: same family, a shade cooler/greener so
       the two storage columns are never confused at a glance. */
    .cr-chl { background:#F0FDFA; color:#0F766E; }
    th.cr-chl { background:#CCFBF1; color:#115E59; }
    .cr-group td.cr-chl { background:#CCFBF1; }
    .cr-total-row td.cr-chl { background:#CCFBF1; }
    /* Into the freezer vs out of it — green in, red out, so a day reads at a
       glance ("we put a lot in, took a little out"). */
    .cr-in  { color:#047857; font-weight:600; }
    .cr-out { color:#B91C1C; font-weight:600; }
    .cr-drill-grp { display:flex; justify-content:space-between; gap:10px; align-items:baseline;
                    padding:9px 2px 5px; border-bottom:1px solid #E5E7EB; margin-top:6px; }
    .cr-drill-grp .n { font-weight:700; font-size:12.5px; }
    .cr-drill-grp .t { font-weight:600; font-size:12.5px; white-space:nowrap; }
    .cr-drill-row { display:flex; justify-content:space-between; gap:10px; align-items:baseline;
                    padding:5px 2px 5px 14px; border-bottom:1px solid #F3F4F6; font-size:12.5px; }
    .cr-drill-row .s { color:#6B7280; font-size:11.5px; white-space:nowrap; }
    .cr-total-row td { font-weight:700; background:#F9FAFB; border-top:2px solid #E5E7EB; }
    .cr-pos { color:var(--cr-pos); font-weight:600; }
    .cr-neg { color:var(--cr-neg); font-weight:600; }
    .cr-dim { color:#9CA3AF; }
    .cr-chip { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px;
               font-weight:600; background:#F3F4F6; color:#374151; }
    .cr-chip-warn { background:#FEF3C7; color:#92400E; }
    .cr-empty { padding:34px 16px; text-align:center; color:var(--cr-muted); font-size:13px; }

    /* ---- tagging ---- */
    .cr-tag-select { border:1px solid #D1D5DB; border-radius:7px; padding:4px 8px; font-size:12px;
                     background:#fff; min-width:130px; height:30px; }
    .cr-tag-select.untagged { border-color:#F59E0B; background:#FFFBEB; }
    .cr-tag-vendor td { background:#F9FAFB; font-weight:600; }
    .cr-tag-prod td:first-child { padding-left:32px; color:#374151; }
    .cr-hide { display:none; }
    .cr-toast { position:fixed; right:20px; bottom:20px; background:#111827; color:#fff;
                padding:10px 16px; border-radius:9px; font-size:13px; z-index:9999; opacity:0;
                transition:opacity .2s; pointer-events:none; }
    .cr-toast.on { opacity:1; }

    /* ---- purchase drill ---- */
    .cr-drillable { cursor:pointer; text-decoration:underline; text-decoration-style:dotted;
                    text-underline-offset:3px; text-decoration-color:#9CA3AF; }
    .cr-drillable:hover { color:#1379F0; text-decoration-color:#1379F0; }
    .cr-drill-vendor { display:flex; justify-content:space-between; align-items:baseline; gap:10px;
                       padding:9px 2px 7px; border-bottom:1px solid #E5E7EB; margin-top:6px; }
    .cr-drill-vendor .n { font-weight:700; font-size:13.5px; }
    .cr-drill-vendor .t { font-weight:700; font-size:13.5px; white-space:nowrap; }
    .cr-drill-line { display:flex; justify-content:space-between; gap:10px; padding:5px 2px 5px 14px;
                     font-size:12.5px; border-bottom:1px solid #F3F4F6; }
    .cr-drill-line .d { color:#9CA3AF; margin-right:8px; white-space:nowrap; }
    .cr-drill-line .q { color:#6B7280; white-space:nowrap; }
    .cr-drill-line .a { white-space:nowrap; font-variant-numeric:tabular-nums; }
    .cr-drill-lump { color:#92400E; }
</style>
@endpush

@section('content')
@php
    /* Formatting helpers — kept here so the table markup stays readable. */
    $rs = function ($v) {
        $v = (float) $v;
        return ($v < 0 ? '-' : '') . 'Rs ' . number_format(abs(round($v)));
    };
    $qty = function ($kg, $pcs) {
        $parts = [];
        if (round((float) $kg, 1) != 0)  { $parts[] = number_format((float) $kg, 1) . ' kg'; }
        if (round((float) $pcs) != 0)    { $parts[] = number_format((float) $pcs) . ' pc'; }
        return $parts ? implode(' · ', $parts) : '';
    };

    $cells    = $report['cells'];
    $periods  = $report['periods'];
    $cats     = $report['categories'];
    $totals   = $report['totals'];
    $grand    = $totals['__grand'];
    $cov      = $report['coverage'];
    /* Summary = one row per category. Detail = the period breakdown.
       Falls back to the old granularity test so this still renders if the
       view is ever built without $view. */
    $isSummary = (($view ?? ($report['granularity'] === 'total' ? 'summary' : 'detail')) === 'summary');

    $grandMargin = $grand['sold_rs'] - $grand['bought_rs'];

    /* ❄️ FREEZER — the overnight FREEZER section only (never the chiller).
       Two different questions, so two different displays:
         · the period holding TODAY  -> what is STILL in the freezer (a level)
         · every other period        -> what was TAKEN OUT then (a flow)
       A level can only ever be true for now: no historical freezer balance
       exists, so it is never painted on a past row. */
    /* Aug-27: the freezer is now read exactly like the chiller — HELD stock,
       dated by the day it went in. `stock` (a live level painted only on
       today's row) and `flow` (in/out movement) are no longer displayed:
       take-outs leave this report because they become sales. Only the
       history-start and quiet-day hints survive, and they explain empty
       cells rather than carrying numbers. */
    $frz          = $report['freezer'] ?? ['history_start' => null, 'quiet_days' => []];
    $frzStart     = $frz['history_start'] ?? null;
    $frzQuiet     = array_flip($frz['quiet_days'] ?? []);

    /* 🧊 CHILLER — HELD stock only, dated by the day it went IN. Once a packet
       is taken out it vanishes from here on purpose: it is on its way to being
       a sale, and the Sold columns already carry it. Valued at SELLING price
       (cost_price is empty for every stocked item), so it is "worth this if we
       sell it", never "money tied up" — and it is NEVER added to Bought. */
    /* Both storage sections are read the SAME way (owner, Aug-27), so they are
       also rolled up the same way — one closure, no chance of the two drifting. */
    $rollHeld = function (array $held) {
        $byCat = [];
        $range = ['kg' => 0.0, 'packets' => 0, 'value' => 0.0];
        foreach ($held as $k => $v) {
            $cat = explode('|', $k, 2)[1] ?? '';
            if (!isset($byCat[$cat])) {
                $byCat[$cat] = ['kg' => 0.0, 'packets' => 0, 'value' => 0.0];
            }
            foreach (['kg', 'packets', 'value'] as $f) {
                $byCat[$cat][$f] += $v[$f];
                $range[$f]       += $v[$f];
            }
        }
        return [$byCat, $range];
    };

    $chl      = $report['chiller'] ?? ['held' => [], 'total' => []];
    $chlHeld  = $chl['held'] ?? [];
    $chlTotal = $chl['total'] ?? ['kg' => 0, 'packets' => 0, 'value' => 0, 'unpriced' => 0, 'oldest' => null];
    [$chlByCat, $chlRange] = $rollHeld($chlHeld);
    /* Held stock whose entry date falls outside the range. */
    $chlOutside = max(0, (float) ($chlTotal['value'] ?? 0) - $chlRange['value']);

    /* ❄️ Freezer, now identical in shape to the chiller. */
    $frzHeld  = $frz['held'] ?? [];
    $frzTotal = $frz['total'] ?? ['kg' => 0, 'packets' => 0, 'value' => 0, 'unpriced' => 0, 'oldest' => null];
    [$frzByCat, $frzRange] = $rollHeld($frzHeld);
    $frzOutside = max(0, (float) ($frzTotal['value'] ?? 0) - $frzRange['value']);

    /* kg, trimmed. Freezer weights are all kg — packets are a count, never
       added to the weight (they are two different measurements). */
    $kgFmt = function ($kg) {
        $kg = (float) $kg;
        return round($kg, 2) == 0 ? '' : number_format($kg, 2) . ' kg';
    };
@endphp

<div class="cr-page">

    <div class="cr-header">
        <div>
            <h1>Category Report — Sales vs Purchases</h1>
            <p>Level 1 categories · sales by delivery date · purchases by vendor purchase date</p>
        </div>
        <div class="cr-header-actions">
            <button type="button" class="cr-hbtn" onclick="crVisOpen()">
                <i class="ki-filled ki-eye"></i> Categories shown
                @if(count($hiddenCategories))
                    <span style="background:#fff; color:#1379F0; border-radius:999px; padding:0 7px;
                                 font-size:11px; font-weight:700;">{{ count($hiddenCategories) }} hidden</span>
                @endif
            </button>
            <button type="button" class="cr-hbtn" onclick="crTagOpen(false)">
                <i class="ki-filled ki-tag"></i> Categories &amp; tagging
            </button>
            <button type="button" class="cr-hbtn" onclick="window.print()">
                <i class="ki-filled ki-printer"></i> Print
            </button>
            <a href="{{ route('products.index') }}" class="cr-hbtn">
                <i class="ki-filled ki-arrow-left"></i> Products
            </a>
        </div>
    </div>

    {{-- ------------------------------------------------------ filters --}}
    <div class="cr-card">
        <div class="cr-card-body">
            <form method="GET" action="{{ route('products.category_report') }}" id="crForm">
                <input type="hidden" name="granularity" id="crGranularity" value="{{ $granularity }}">
                <input type="hidden" name="view" id="crView" value="{{ $isSummary ? 'summary' : 'detail' }}">
                <div class="cr-filters">
                    <div class="cr-field">
                        <label>Range</label>
                        <select name="preset" id="crPreset" onchange="crPresetChanged()">
                            <option value="today"      @selected($preset==='today')>Today</option>
                            <option value="yesterday"  @selected($preset==='yesterday')>Yesterday</option>
                            <option value="this_week"  @selected($preset==='this_week')>This week (from Wed)</option>
                            <option value="last_week"  @selected($preset==='last_week')>Last week (Wed–Tue)</option>
                            <option value="last_7"     @selected($preset==='last_7')>Last 7 days</option>
                            <option value="last_30"    @selected($preset==='last_30')>Last 30 days</option>
                            <option value="last_90"    @selected($preset==='last_90')>Last 90 days</option>
                            <option value="this_month" @selected($preset==='this_month')>This month</option>
                            <option value="last_month" @selected($preset==='last_month')>Last month</option>
                            <option value="custom"     @selected($preset==='custom')>Custom…</option>
                        </select>
                    </div>

                    <div class="cr-field {{ $preset === 'custom' ? '' : 'cr-hide' }}" id="crCustomStart">
                        <label>From</label>
                        <input type="date" name="start" value="{{ $start->toDateString() }}">
                    </div>
                    <div class="cr-field {{ $preset === 'custom' ? '' : 'cr-hide' }}" id="crCustomEnd">
                        <label>To</label>
                        <input type="date" name="end" value="{{ $end->toDateString() }}">
                    </div>

                    {{-- View and Group by are orthogonal: WHAT you look at, then
                         how finely it is sliced. "Total only" used to sit in
                         Group by, which made two controls mean the same thing. --}}
                    <div class="cr-field">
                        <label>View</label>
                        <div class="cr-seg">
                            <button type="button"
                                    class="{{ $isSummary ? '' : 'on' }}"
                                    onclick="crSetView('detail')">
                                {{ $granularity === 'week' ? 'Week by week'
                                   : ($granularity === 'month' ? 'Month by month' : 'Day by day') }}
                            </button>
                            <button type="button"
                                    class="{{ $isSummary ? 'on' : '' }}"
                                    onclick="crSetView('summary')">Summary</button>
                        </div>
                    </div>

                    <div class="cr-field {{ $isSummary ? 'cr-hide' : '' }}" id="crGroupByField">
                        <label>Group by</label>
                        <div class="cr-seg">
                            @foreach(['day'=>'Daily','week'=>'Weekly','month'=>'Monthly'] as $g => $lbl)
                                <button type="button"
                                        class="{{ $granularity === $g ? 'on' : '' }}"
                                        onclick="crSetGranularity('{{ $g }}')">{{ $lbl }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="cr-field">
                        <label>Qurbani</label>
                        <select name="qurbani">
                            <option value="exclude" @selected($excludeQurbani)>Excluded</option>
                            <option value="include" @selected(!$excludeQurbani)>Included</option>
                        </select>
                    </div>

                    <button type="submit" class="cr-apply">Apply</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ------------------------------------------------- health notices --}}
    @if($cov['untagged_pct'] > 0)
        <div class="cr-note cr-note-warn">
            <span>⚠</span>
            <div>
                <b>{{ $cov['untagged_pct'] }}% of purchases in this range are untagged</b>
                ({{ $rs($cov['untagged_rs']) }}). They appear in the <b>Untagged</b> row rather than
                against a category. Tagging a vendor product re-files its whole purchase history at
                once.
                <button type="button" onclick="crTagOpen(true)"
                        style="margin-left:6px; background:#92400E; color:#fff; border:none;
                               border-radius:7px; padding:4px 11px; font-size:12px; font-weight:600;
                               cursor:pointer;">Tag them now</button>
            </div>
        </div>
    @endif

    <div class="cr-note cr-note-info">
        <span>ℹ</span>
        <div>
            This is deliberately <b>not</b> a like-for-like comparison. Meat bought on one day is
            delivered on another, and you buy carcass but sell trimmed cuts — so purchased kg will
            normally exceed sold kg. Read the rupee columns as the reliable signal and use weekly or
            monthly grouping for a fair picture.
            {{ $cov['itemised_pct'] < 100 ? ' Only ' . $cov['itemised_pct'] . '% of purchase value in this range is itemised by weight; the rest is a lump-sum amount with no quantity, shown as “—”.' : '' }}
        </div>
    </div>

    {{-- Hidden categories are never silently dropped: the money they hold
         is stated here, next to the totals they were excluded from. --}}
    @if(!empty($report['hidden']['count']))
        @php $h = $report['hidden']; @endphp
        <div class="cr-note" style="background:#F3F4F6; border:1px solid #E5E7EB; color:#374151;">
            <span>👁</span>
            <div>
                <b>{{ $h['count'] }}
                    {{ $h['count'] === 1 ? 'category is' : 'categories are' }} hidden</b>
                from your view —
                {{ collect($h['categories'])->pluck('category')->implode(', ') }}.
                Excluded from every figure below:
                <b>{{ $rs($h['sold_rs']) }} sold</b>, <b>{{ $rs($h['bought_rs']) }} bought</b>.
                <button type="button" onclick="crVisOpen()"
                        style="margin-left:6px; background:#374151; color:#fff; border:none;
                               border-radius:7px; padding:4px 11px; font-size:12px; font-weight:600;
                               cursor:pointer;">Change</button>
            </div>
        </div>
    @endif

    {{-- Chiller stock whose entry date is outside the chosen dates would
         otherwise just vanish when the range narrows, reading as stock that had
         left. Say so instead. --}}
    @foreach([
        ['icon' => '🧊', 'name' => 'chiller', 'outside' => $chlOutside, 'total' => $chlTotal,
         'bg' => '#F0FDFA', 'br' => '#99F6E4', 'fg' => '#115E59'],
        ['icon' => '❄️', 'name' => 'freezer', 'outside' => $frzOutside, 'total' => $frzTotal,
         'bg' => '#F0F9FF', 'br' => '#BAE6FD', 'fg' => '#075985'],
    ] as $note)
        @if($note['outside'] > 0)
            <div class="cr-note" style="background:{{ $note['bg'] }}; border:1px solid {{ $note['br'] }}; color:{{ $note['fg'] }};">
                <span>{{ $note['icon'] }}</span>
                <div>
                    <b>{{ $rs($note['outside']) }} of {{ $note['name'] }} stock went in before this date range</b>
                    and is still sitting there, so it is not in the column below.
                    Everything currently held is <b>{{ $kgFmt($note['total']['kg']) ?: '0 kg' }}</b>
                    ({{ $note['total']['packets'] }} packets, {{ $rs($note['total']['value']) }})
                    @if(!empty($note['total']['oldest'])) — oldest went in {{ \Carbon\Carbon::parse($note['total']['oldest'])->format('j M Y') }}@endif.
                </div>
            </div>
        @endif
    @endforeach

    {{-- ----------------------------------------------------- stat tiles --}}
    <div class="cr-stats">
        <div class="cr-stat sold">
            <div class="k">Sold</div>
            <div class="v">{{ $rs($grand['sold_rs']) }}</div>
            <div class="s">{{ $qty($grand['sold_kg'], $grand['sold_pcs']) ?: '—' }}</div>
        </div>
        <div class="cr-stat bought">
            <div class="k">Bought</div>
            <div class="v">{{ $rs($grand['bought_rs']) }}</div>
            <div class="s">{{ $qty($grand['bought_kg'], $grand['bought_pcs']) ?: '—' }}</div>
        </div>
        <div class="cr-stat">
            <div class="k">Difference</div>
            <div class="v {{ $grandMargin >= 0 ? 'cr-pos' : 'cr-neg' }}">{{ $rs($grandMargin) }}</div>
            <div class="s">
                {{ $grand['sold_rs'] > 0 ? round($grandMargin / $grand['sold_rs'] * 100, 1) . '% of sales' : '—' }}
            </div>
        </div>
        <div class="cr-stat">
            <div class="k">Range</div>
            <div class="v" style="font-size:15px;">
                {{ $start->format('j M Y') }} – {{ $end->format('j M Y') }}
            </div>
            <div class="s">{{ $start->diffInDays($end) + 1 }} days · {{ $cov['purchases'] }} purchases</div>
        </div>
    </div>

    {{-- ------------------------------------------------ category totals --}}
    @if($isSummary)
    <div class="cr-card">
        <div class="cr-card-head">
            <h2>By category <span class="cr-sub">— whole range</span></h2>
        </div>
        <div class="cr-scroll">
            <table class="cr-table">
                <thead>
                    <tr>
                        <th class="l">Category</th>
                        <th>Sold</th>
                        <th>Sold qty</th>
                        {{-- Sits next to "Sold qty" on purpose: sold-vs-still-in-the-freezer
                             is the comparison this column exists for, and two numbers you
                             compare should not be four columns apart. --}}
                        <th class="cr-frz" title="Still sitting in the FREEZER, counted on the day it went in. A packet that has been taken out is gone from here on purpose — it becomes a sale. Valued at SELLING price (cost is not recorded). Click for the packets.">❄️ In freezer</th>
                        <th class="cr-chl" title="Still sitting in the CHILLER, counted on the day it went in. A packet that has been taken out is gone from here on purpose — it becomes a sale. Valued at SELLING price (cost is not recorded), so this is what it is worth if sold, not money tied up. Click for the packets.">🧊 In chiller</th>
                        <th>Bought</th>
                        <th>Bought qty</th>
                        <th>Difference</th>
                        <th>Orders</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cats as $cat)
                        @php $t = $totals[$cat]; @endphp
                        <tr>
                            <td class="l">
                                <b>{{ $cat }}</b>
                                @if($cat === 'Untagged')
                                    <span class="cr-chip cr-chip-warn" style="cursor:pointer;"
                                          title="Open tagging"
                                          onclick="crTagOpen(true)">needs tagging →</span>
                                @endif
                            </td>
                            @php $oneSided = ($t['sold_rs'] == 0) !== ($t['bought_rs'] == 0); @endphp
                            <td class="num">
                                @if($t['sold_rs'] != 0)
                                    <span class="cr-drillable" title="Show the products sold"
                                          onclick="crSalesDrill('__range', {{ json_encode($cat) }})">{{ $rs($t['sold_rs']) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="num cr-dim">{{ $qty($t['sold_kg'], $t['sold_pcs']) ?: '—' }}</td>
                            @php $fs = $frzByCat[$cat] ?? null; @endphp
                            <td class="num cr-frz {{ $fs ? 'cr-drillable' : '' }}"
                                @if($fs) title="{{ $fs['packets'] }} packet{{ $fs['packets'] == 1 ? '' : 's' }} still in the freezer — {{ $rs($fs['value']) }} at selling price"
                                         onclick="crHeldDrill('freezer', '__range', {{ json_encode($cat) }})" @endif>
                                {{ $fs && $kgFmt($fs['kg']) ? $kgFmt($fs['kg']) : '—' }}
                                @if($fs)
                                    <span class="cr-qty">{{ $rs($fs['value']) }} · {{ $fs['packets'] }} pkt</span>
                                @endif
                            </td>
                            @php $ch = $chlByCat[$cat] ?? null; @endphp
                            <td class="num cr-chl {{ $ch ? 'cr-drillable' : '' }}"
                                @if($ch) title="{{ $ch['packets'] }} packet{{ $ch['packets'] == 1 ? '' : 's' }} still in the chiller — {{ $rs($ch['value']) }} at selling price"
                                         onclick="crHeldDrill('chiller', '__range', {{ json_encode($cat) }})" @endif>
                                {{ $ch && $kgFmt($ch['kg']) ? $kgFmt($ch['kg']) : '—' }}
                                @if($ch)
                                    <span class="cr-qty">{{ $rs($ch['value']) }} · {{ $ch['packets'] }} pkt</span>
                                @endif
                            </td>
                            <td class="num">
                                @if($t['bought_rs'] != 0)
                                    <span class="cr-drillable" title="Show vendors"
                                          onclick="crDrill('__range', {{ json_encode($cat) }})">{{ $rs($t['bought_rs']) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="num cr-dim">
                                {{ $qty($t['bought_kg'], $t['bought_pcs']) ?: '—' }}
                                {!! $t['bought_rs_noqty'] > 0 ? '<span class="cr-qty">' . $rs($t['bought_rs_noqty']) . ' lump sum, no qty</span>' : '' !!}
                            </td>
                            {{-- A one-sided figure (sales with no purchases, or the
                                 reverse) is not a margin — show it muted. --}}
                            <td class="num {{ $oneSided ? 'cr-dim' : (($t['sold_rs'] - $t['bought_rs']) >= 0 ? 'cr-pos' : 'cr-neg') }}"
                                @if($oneSided) title="One-sided — no {{ $t['sold_rs'] == 0 ? 'sales' : 'purchases' }} recorded for this category" @endif>
                                {{ $rs($t['sold_rs'] - $t['bought_rs']) }}{{ $oneSided ? ' *' : '' }}
                            </td>
                            <td class="num cr-dim">{{ $t['orders'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="cr-empty">No sales or purchases in this range.</td></tr>
                    @endforelse
                </tbody>
                @if(count($cats))
                    <tfoot>
                        <tr class="cr-total-row">
                            <td class="l">Total</td>
                            <td class="num">{{ $rs($grand['sold_rs']) }}</td>
                            <td class="num cr-dim">{{ $qty($grand['sold_kg'], $grand['sold_pcs']) ?: '—' }}</td>
                            <td class="num cr-frz"
                                title="Still in the freezer, from stock that went in during this range — {{ $rs($frzRange['value']) }} at selling price. Not a cost and never added to Bought.">
                                {{ $kgFmt($frzRange['kg']) ?: '—' }}
                                @if($frzRange['packets'])
                                    <span class="cr-qty">{{ $rs($frzRange['value']) }} · {{ $frzRange['packets'] }} pkt</span>
                                @endif
                            </td>
                            <td class="num cr-chl"
                                title="Still in the chiller, from stock that went in during this range — {{ $rs($chlRange['value']) }} at selling price. Not a cost and never added to Bought.">
                                {{ $kgFmt($chlRange['kg']) ?: '—' }}
                                @if($chlRange['packets'])
                                    <span class="cr-qty">{{ $rs($chlRange['value']) }} · {{ $chlRange['packets'] }} pkt</span>
                                @endif
                            </td>
                            <td class="num">{{ $rs($grand['bought_rs']) }}</td>
                            <td class="num cr-dim">{{ $qty($grand['bought_kg'], $grand['bought_pcs']) ?: '—' }}</td>
                            <td class="num {{ $grandMargin >= 0 ? 'cr-pos' : 'cr-neg' }}">{{ $rs($grandMargin) }}</td>
                            {{-- Distinct orders — NOT the column sum, which double-counts
                                 orders that span more than one category. --}}
                            <td class="num cr-dim" title="Distinct orders (an order can span several categories, so the column does not add up to this)">
                                {{ number_format($report['orders_total']) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div style="padding:7px 16px 10px; font-size:11px; color:#9CA3AF;">
            * one-sided — only sales or only purchases recorded, so it isn't a margin.
            Click any <span class="cr-drillable">Bought</span> figure to see the vendors behind it.
        </div>
    </div>

    @endif

    {{-- --------------------------------------------- period breakdown --}}
    @if(!$isSummary)
        <div class="cr-card">
            <div class="cr-card-head">
                <h2>
                    @if($granularity === 'day')      Day by day
                    @elseif($granularity === 'week') Week by week
                    @else                            Month by month
                    @endif
                </h2>
                <span class="cr-sub">
                    {{ $granularity === 'week' ? 'Weeks run Wednesday → Tuesday' : 'Newest first' }}
                </span>
            </div>
            {{-- Tall scroller: this is what makes the sticky header + sticky day
                 band work. Without a height cap the page scrolls instead and the
                 header slides away, which is exactly what it used to do. --}}
            <div class="cr-scroll cr-scroll-tall">
                <table class="cr-table">
                    <thead>
                        <tr>
                            <th class="l">Category</th>
                            <th>Sold</th>
                            <th>Sold qty</th>
                            <th class="cr-frz" title="Of the stock that went into the FREEZER on this date, what is STILL sitting there. Anything taken out has left this report on purpose — it becomes a sale. Valued at SELLING price (cost is not recorded). The +/− underneath is that period's movement. Click for the packets.">❄️ In freezer</th>
                            <th class="cr-chl" title="Of the stock that went into the CHILLER on this date, what is STILL sitting there. Anything taken out has left this report on purpose — it becomes a sale. Valued at SELLING price (cost is not recorded). Click for the packets.">🧊 In chiller</th>
                            <th>Bought</th>
                            <th>Bought qty</th>
                            <th>Difference</th>
                        </tr>
                    </thead>
                    {{-- ⚠ ONE <tbody> PER PERIOD, on purpose. A sticky <tr> is
                         confined by its tbody, so with a single tbody every day
                         band pinned at the same offset and they stacked on top of
                         each other (verified in the browser: three bands all at
                         33px). A tbody per period makes each band get pushed out
                         by the next one, which is the behaviour we want. --}}
                    @forelse($periods as $p)
                        <tbody>
                            @php
                                $rows = array_values(array_filter($cells, fn ($c) => $c['period'] === $p['key']));

                                /* Held stock entered in THIS period, per section. Both
                                   are read identically now (owner, Aug-27): only what is
                                   still there, dated by the day it went in. Take-outs are
                                   deliberately not shown — a packet that left has become
                                   a sale and the Sold columns already carry it. */
                                $pFrz = [];
                                foreach ($frzHeld as $fk => $fv) {
                                    [$fper, $fcat] = array_pad(explode('|', $fk, 2), 2, '');
                                    if ($fper === $p['key']) { $pFrz[$fcat] = $fv; }
                                }
                                $pChl = [];
                                foreach ($chlHeld as $fk => $fv) {
                                    [$fper, $fcat] = array_pad(explode('|', $fk, 2), 2, '');
                                    if ($fper === $p['key']) { $pChl[$fcat] = $fv; }
                                }

                                /* A category can be holding stock with no sale or purchase
                                   in this period — without this union its number would be
                                   invisible, which is exactly how stock goes missing from
                                   a report. Covers BOTH sections. */
                                $haveCats = array_column($rows, 'category');
                                foreach (array_unique(array_merge(array_keys($pFrz), array_keys($pChl))) as $fcat) {
                                    if (in_array($fcat, $haveCats, true)) { continue; }
                                    $sKg = (float) ($pFrz[$fcat]['kg'] ?? 0) + (float) ($pChl[$fcat]['kg'] ?? 0);
                                    $sPk = (int) ($pFrz[$fcat]['packets'] ?? 0) + (int) ($pChl[$fcat]['packets'] ?? 0);
                                    if (round($sKg, 2) == 0 && $sPk === 0) { continue; }
                                    $rows[] = [
                                        'period' => $p['key'], 'category' => $fcat,
                                        'sold_rs' => 0.0, 'sold_kg' => 0.0, 'sold_pcs' => 0.0, 'orders' => 0,
                                        'bought_rs' => 0.0, 'bought_kg' => 0.0, 'bought_pcs' => 0.0,
                                        'bought_rs_noqty' => 0.0, 'margin_rs' => 0.0,
                                        'storage_only' => true,
                                    ];
                                }

                                usort($rows, fn ($a, $b) => ($b['sold_rs'] + $b['bought_rs']) <=> ($a['sold_rs'] + $a['bought_rs']));
                                $pSold   = array_sum(array_column($rows, 'sold_rs'));
                                $pBought = array_sum(array_column($rows, 'bought_rs'));

                                /* Day granularity only: the tracker recorded nothing at
                                   all that day. "Nothing moved" and "nobody scanned" are
                                   indistinguishable in the data — say so rather than
                                   letting an empty cell imply the first. */
                                $isQuiet = $granularity === 'day' && isset($frzQuiet[$p['key']]);
                            @endphp
                            <tr class="cr-group">
                                <td class="l">
                                    {{ $p['label'] }}
                                    @if($isQuiet)
                                        <span class="cr-chip cr-chip-warn" title="No freezer activity was recorded on this day — it may mean nothing moved, or that nothing was scanned">no freezer activity recorded</span>
                                    @endif
                                </td>
                                <td class="num">{{ $rs($pSold) }}</td>
                                <td></td>
                                {{-- ❄️ Both directions: what went IN that period and what
                                     came OUT. Seeing the in-flow is what tells you extra
                                     was bought/stored that day. --}}
                                @php
                                    $pFrzKg  = array_sum(array_column($pFrz, 'kg'));
                                    $pFrzVal = array_sum(array_column($pFrz, 'value'));
                                    $pFrzPkt = array_sum(array_column($pFrz, 'packets'));
                                @endphp
                                <td class="num cr-frz">
                                    @if($pFrzPkt)
                                        {{ $kgFmt($pFrzKg) ?: '—' }}
                                        <span class="cr-qty">{{ $rs($pFrzVal) }} · {{ $pFrzPkt }} pkt</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                {{-- 🧊 Everything from this date still sitting in the chiller. --}}
                                @php
                                    $pChKg  = 0.0; $pChVal = 0.0; $pChPkt = 0;
                                    foreach ($chlHeld as $ck => $cv) {
                                        if (str_starts_with($ck, $p['key'] . '|')) {
                                            $pChKg  += $cv['kg'];
                                            $pChVal += $cv['value'];
                                            $pChPkt += $cv['packets'];
                                        }
                                    }
                                @endphp
                                <td class="num cr-chl">
                                    @if($pChPkt)
                                        {{ $kgFmt($pChKg) ?: '—' }}
                                        <span class="cr-qty">{{ $rs($pChVal) }} · {{ $pChPkt }} pkt</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="num">{{ $rs($pBought) }}</td>
                                <td></td>
                                <td class="num {{ ($pSold - $pBought) >= 0 ? 'cr-pos' : 'cr-neg' }}">
                                    {{ $rs($pSold - $pBought) }}
                                </td>
                            </tr>
                            @foreach($rows as $c)
                                @php $oneSided = ($c['sold_rs'] == 0) !== ($c['bought_rs'] == 0); @endphp
                                <tr>
                                    <td class="l" style="padding-left:26px;">{{ $c['category'] }}</td>
                                    <td class="num">
                                        @if($c['sold_rs'] != 0)
                                            <span class="cr-drillable" title="Show the products sold"
                                                  onclick="crSalesDrill({{ json_encode($c['period']) }}, {{ json_encode($c['category']) }})">{{ $rs($c['sold_rs']) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="num cr-dim">{{ $qty($c['sold_kg'], $c['sold_pcs']) ?: '—' }}</td>
                                    {{-- ❄️ Identical to the chiller: of what went into the
                                         freezer on THIS date, what is still there. Take-outs
                                         are deliberately not shown — that stock became a
                                         sale and the Sold columns already carry it. --}}
                                    @php $fh = $frzHeld[$c['period'] . '|' . $c['category']] ?? null; @endphp
                                    <td class="num cr-frz {{ $fh ? 'cr-drillable' : '' }}"
                                        @if($fh) title="{{ $fh['packets'] }} packet{{ $fh['packets'] == 1 ? '' : 's' }} from this date still in the freezer — {{ $rs($fh['value']) }} at selling price"
                                                 onclick="crHeldDrill('freezer', {{ json_encode($c['period']) }}, {{ json_encode($c['category']) }})" @endif>
                                        @if($fh)
                                            {{ $kgFmt($fh['kg']) ?: '—' }}
                                            <span class="cr-qty">{{ $rs($fh['value']) }} · {{ $fh['packets'] }} pkt</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    {{-- 🧊 Of what went into the chiller on this date, what is
                                         STILL there. Taken-out stock is deliberately absent. --}}
                                    @php $ch = $chlHeld[$c['period'] . '|' . $c['category']] ?? null; @endphp
                                    <td class="num cr-chl {{ $ch ? 'cr-drillable' : '' }}"
                                        @if($ch) title="{{ $ch['packets'] }} packet{{ $ch['packets'] == 1 ? '' : 's' }} from this date still in the chiller — {{ $rs($ch['value']) }} at selling price"
                                                 onclick="crHeldDrill('chiller', {{ json_encode($c['period']) }}, {{ json_encode($c['category']) }})" @endif>
                                        {{ $ch && $kgFmt($ch['kg']) ? $kgFmt($ch['kg']) : '—' }}
                                        @if($ch)
                                            <span class="cr-qty">{{ $rs($ch['value']) }} · {{ $ch['packets'] }} pkt</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if($c['bought_rs'] != 0)
                                            <span class="cr-drillable" title="Show vendors"
                                                  onclick="crDrill({{ json_encode($c['period']) }}, {{ json_encode($c['category']) }})">{{ $rs($c['bought_rs']) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="num cr-dim">{{ $qty($c['bought_kg'], $c['bought_pcs']) ?: '—' }}</td>
                                    {{-- A storage-only row (held stock, no trade) has no money at
                                         "Rs 0" difference would read like a real
                                         break-even rather than "nothing traded". --}}
                                    <td class="num {{ !empty($c['storage_only']) || $oneSided ? 'cr-dim' : ($c['margin_rs'] >= 0 ? 'cr-pos' : 'cr-neg') }}">
                                        @if(!empty($c['storage_only']))
                                            —
                                        @else
                                            {{ $rs($c['margin_rs']) }}{{ $oneSided ? ' *' : '' }}
                                        @endif
                                    </td>

                                </tr>
                            @endforeach
                        </tbody>
                    @empty
                        <tbody>
                            <tr><td colspan="8" class="cr-empty">Nothing to show for this range.</td></tr>
                        </tbody>
                    @endforelse
                </table>
            </div>
            <div style="padding:7px 16px 10px; font-size:11px; color:#9CA3AF;">
                ❄️ Freezer column — the <b>freezer</b> section of overnight storage only; the chiller
                is not counted. <span class="cr-in">+</span> went in, <span class="cr-out">−</span> came out
                (a packet moved to the chiller counts as out, one moved in from it counts as in). The current
                {{ $granularity === 'day' ? 'day' : $granularity }} also shows what is
                <b>still in the freezer</b>. Click any freezer or <b>Sold</b> figure for the detail behind it.
                @if($frzStart)
                    Freezer tracking began {{ \Carbon\Carbon::parse($frzStart)->format('j M Y') }} —
                    nothing was recorded before that date.
                @endif
            </div>
        </div>
    @endif

</div>

<div class="cr-toast" id="crToast"></div>

{{-- Purchase drill modal. Shell is INLINE-styled on purpose: the purged
     stylesheet drops inset-0 / max-h / overflow utilities, so a class-built
     modal shrink-wraps to the top-left with no backdrop. --}}
<div id="crDrillModal"
     style="display:none; position:fixed; top:0; right:0; bottom:0; left:0; z-index:9998;
            background:rgba(0,0,0,.5); align-items:center; justify-content:center; padding:16px;"
     onclick="if (event.target === this) crDrillClose()">
    <div style="background:#fff; border-radius:14px; max-width:34rem; width:100%; display:flex;
                flex-direction:column; max-height:85vh; box-shadow:0 20px 50px rgba(0,0,0,.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;
                    padding:14px 18px; border-bottom:1px solid #E5E7EB;">
            <div>
                <div id="crDrillTitle" style="font-size:15px; font-weight:700;"></div>
                <div id="crDrillSub" style="font-size:11.5px; color:#6B7280; margin-top:2px;"></div>
            </div>
            <button onclick="crDrillClose()"
                    style="border:none; background:none; font-size:24px; line-height:1; color:#9CA3AF;
                           cursor:pointer; padding:2px 6px;">&times;</button>
        </div>
        <div id="crDrillBody" style="flex:1 1 auto; min-height:0; overflow-y:auto; padding:8px 18px 16px;">
        </div>
        {{-- Only true of the PURCHASE drill (the one with tag dropdowns), so the
             other drills hide it rather than promising something they don't do. --}}
        <div id="crDrillFoot" style="padding:9px 18px; border-top:1px solid #E5E7EB; font-size:11.5px; color:#6B7280;">
            Change a category here and it re-files that vendor's whole history.
        </div>
    </div>
</div>

{{-- Tagging modal — same inline-styled shell, same reason. --}}
<div id="crTagModal"
     style="display:none; position:fixed; top:0; right:0; bottom:0; left:0; z-index:9998;
            background:rgba(0,0,0,.5); align-items:center; justify-content:center; padding:16px;"
     onclick="if (event.target === this) crTagClose()">
    <div style="background:#fff; border-radius:14px; max-width:44rem; width:100%; display:flex;
                flex-direction:column; max-height:88vh; box-shadow:0 20px 50px rgba(0,0,0,.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;
                    padding:14px 18px; border-bottom:1px solid #E5E7EB;">
            <div>
                <div style="font-size:15px; font-weight:700;">Categories &amp; tagging</div>
                <div style="font-size:11.5px; color:#6B7280; margin-top:2px;">
                    What each vendor purchase counts as · saves immediately
                </div>
            </div>
            <button onclick="crTagClose()"
                    style="border:none; background:none; font-size:24px; line-height:1; color:#9CA3AF;
                           cursor:pointer; padding:2px 6px;">&times;</button>
        </div>

        <div style="padding:11px 18px; border-bottom:1px solid #E5E7EB; display:flex; gap:10px;
                    flex-wrap:wrap; align-items:center;">
            <input type="search" id="crTagSearch" placeholder="Search vendor or product…"
                   oninput="crTagFilter()"
                   style="flex:1 1 200px; border:1px solid #D1D5DB; border-radius:8px;
                          padding:7px 10px; font-size:13px; height:34px;">
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px; cursor:pointer;">
                <input type="checkbox" id="crTagOnlyUntagged" onchange="crTagFilter()">
                Untagged only
            </label>
            <span id="crTagCount" style="font-size:11.5px; color:#6B7280;"></span>
        </div>

        <div style="flex:1 1 auto; min-height:0; overflow-y:auto;">
            <table class="cr-table" id="crTagTable">
                <thead>
                    <tr>
                        <th class="l">Vendor / product</th>
                        <th class="l">Category</th>
                        <th class="l">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tagging as $row)
                        @php
                            $v         = $row['vendor'];
                            $vUntagged = trim((string) $v->default_category_level_1) === '';
                            $byWeight  = $v->default_purchase_method === 'by_weight';
                            /* A by_weight vendor's products carry the category, so a
                               missing default is normal there — don't cry wolf. */
                            $vFlag     = $vUntagged && !$byWeight ? '1' : '0';
                        @endphp
                        <tr class="cr-tag-vendor" data-tagrow="1" data-untagged="{{ $vFlag }}"
                            data-search="{{ mb_strtolower($v->vendor_name) }}">
                            <td class="l">
                                {{ $v->vendor_name }}
                                {!! $v->is_active ? '' : ' <span class="cr-chip">inactive</span>' !!}
                            </td>
                            <td class="l">
                                <select class="cr-tag-select {{ $vFlag === '1' ? 'untagged' : '' }}"
                                        onchange="crSaveTag(this,'vendor',{{ $v->id }})">
                                    <option value="">— no default —</option>
                                    @foreach($vocabulary as $opt)
                                        <option value="{{ $opt }}" @selected($v->default_category_level_1 === $opt)>{{ $opt }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="l cr-dim" style="white-space:normal; font-size:11.5px;">
                                {{ $byWeight
                                    ? 'Bills by weight — its products below decide the category.'
                                    : 'Bills a lump sum — this default decides the category.' }}
                            </td>
                        </tr>
                        @foreach($row['products'] as $p)
                            @php $pUntagged = trim((string) $p->category_level_1) === ''; @endphp
                            <tr class="cr-tag-prod" data-tagrow="1" data-untagged="{{ $pUntagged ? '1' : '0' }}"
                                data-search="{{ mb_strtolower($v->vendor_name . ' ' . $p->product_name) }}">
                                <td class="l">
                                    {{ $p->product_name }}
                                    {!! $p->is_active ? '' : ' <span class="cr-chip">inactive</span>' !!}
                                </td>
                                <td class="l">
                                    <select class="cr-tag-select {{ $pUntagged ? 'untagged' : '' }}"
                                            onchange="crSaveTag(this,'product',{{ $p->id }})">
                                        <option value="">— untagged —</option>
                                        @foreach($vocabulary as $opt)
                                            <option value="{{ $opt }}" @selected($p->category_level_1 === $opt)>{{ $opt }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="l cr-dim" style="font-size:11.5px;">per {{ $p->unit }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Categories-shown picker. Personal display preference, saved per user. --}}
<div id="crVisModal"
     style="display:none; position:fixed; top:0; right:0; bottom:0; left:0; z-index:9998;
            background:rgba(0,0,0,.5); align-items:center; justify-content:center; padding:16px;"
     onclick="if (event.target === this) crVisClose()">
    <div style="background:#fff; border-radius:14px; max-width:30rem; width:100%; display:flex;
                flex-direction:column; max-height:85vh; box-shadow:0 20px 50px rgba(0,0,0,.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;
                    padding:14px 18px; border-bottom:1px solid #E5E7EB;">
            <div>
                <div style="font-size:15px; font-weight:700;">Categories shown</div>
                <div style="font-size:11.5px; color:#6B7280; margin-top:2px;">
                    Your own view only — nobody else's report changes
                </div>
            </div>
            <button onclick="crVisClose()"
                    style="border:none; background:none; font-size:24px; line-height:1; color:#9CA3AF;
                           cursor:pointer; padding:2px 6px;">&times;</button>
        </div>

        @if(!$prefsAvailable)
            <div class="cr-note cr-note-warn" style="margin:14px 18px;">
                <span>⚠</span>
                <div>
                    Preferences aren't set up on this server yet, so this list can't be saved.
                    Run the report's SQL script (it creates <code>t_sys_user_setting</code>) and
                    this will start working.
                </div>
            </div>
        @endif

        <div style="padding:10px 18px 4px; display:flex; gap:8px;">
            <button type="button" onclick="crVisAll(true)"
                    style="border:1px solid #D1D5DB; background:#fff; border-radius:7px;
                           padding:5px 11px; font-size:12px; cursor:pointer;">Select all</button>
            <button type="button" onclick="crVisAll(false)"
                    style="border:1px solid #D1D5DB; background:#fff; border-radius:7px;
                           padding:5px 11px; font-size:12px; cursor:pointer;">Clear all</button>
            <span id="crVisCount" style="margin-left:auto; align-self:center; font-size:11.5px; color:#6B7280;"></span>
        </div>

        <div style="flex:1 1 auto; min-height:0; overflow-y:auto; padding:6px 18px 12px;">
            @foreach($allCategories as $catName)
                @php $isHidden = in_array($catName, $hiddenCategories, true); @endphp
                <label style="display:flex; align-items:center; gap:9px; padding:7px 2px;
                              border-bottom:1px solid #F3F4F6; cursor:pointer; font-size:13px;">
                    <input type="checkbox" class="cr-vis-box" value="{{ $catName }}"
                           onchange="crVisCount()" @checked(!$isHidden)>
                    <span>{{ $catName }}</span>
                    @if($catName === 'Untagged' || $catName === 'Uncategorized')
                        <span class="cr-chip" style="margin-left:auto;">data quality</span>
                    @endif
                </label>
            @endforeach
        </div>

        <div style="padding:12px 18px; border-top:1px solid #E5E7EB; display:flex; gap:9px;
                    align-items:center;">
            <span style="font-size:11.5px; color:#6B7280; flex:1 1 auto;">
                Hidden categories are excluded from the totals, and the page says how much.
            </span>
            <button type="button" onclick="crVisClose()"
                    style="border:1px solid #D1D5DB; background:#fff; border-radius:8px;
                           padding:7px 14px; font-size:13px; cursor:pointer;">Cancel</button>
            <button type="button" id="crVisSave" onclick="crVisSave()"
                    style="border:none; background:#1379F0; color:#fff; border-radius:8px;
                           padding:7px 16px; font-size:13px; font-weight:600; cursor:pointer;">
                Save &amp; refresh
            </button>
        </div>
    </div>
</div>

{{-- Shown after any tag change: the table on screen is now stale. --}}
<div id="crStale"
     style="display:none; position:fixed; left:50%; transform:translateX(-50%); bottom:22px; z-index:9999;
            background:#1379F0; color:#fff; padding:10px 14px 10px 16px; border-radius:10px;
            box-shadow:0 8px 24px rgba(0,0,0,.25); font-size:13px; align-items:center; gap:12px;">
    <span>Categories changed — the numbers above are out of date.</span>
    <button onclick="window.location.reload()"
            style="background:#fff; color:#1379F0; border:none; border-radius:7px; padding:5px 12px;
                   font-size:12.5px; font-weight:600; cursor:pointer;">Refresh</button>
</div>

<script>
(function () {
    'use strict';

    window.crPresetChanged = function () {
        var custom = document.getElementById('crPreset').value === 'custom';
        document.getElementById('crCustomStart').classList.toggle('cr-hide', !custom);
        document.getElementById('crCustomEnd').classList.toggle('cr-hide', !custom);
        if (!custom) { document.getElementById('crForm').submit(); }
    };

    // Switching view keeps the chosen bucket size, so Summary -> Detail
    // returns you to Weekly/Monthly rather than snapping back to Daily.
    window.crSetView = function (v) {
        document.getElementById('crView').value = v;
        document.getElementById('crForm').submit();
    };

    window.crSetGranularity = function (g) {
        document.getElementById('crView').value = 'detail';
        document.getElementById('crGranularity').value = g;
        document.getElementById('crForm').submit();
    };

    // ---------------- categories-shown picker ----------------

    function crVisBoxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.cr-vis-box'));
    }

    window.crVisOpen = function () {
        crVisCount();
        document.getElementById('crVisModal').style.display = 'flex';
    };

    window.crVisClose = function () {
        document.getElementById('crVisModal').style.display = 'none';
    };

    window.crVisAll = function (on) {
        crVisBoxes().forEach(function (b) { b.checked = on; });
        crVisCount();
    };

    window.crVisCount = function () {
        var boxes = crVisBoxes();
        var shown = boxes.filter(function (b) { return b.checked; }).length;
        document.getElementById('crVisCount').textContent =
            shown + ' of ' + boxes.length + ' shown';
        // Hiding everything would render an empty report that looks broken.
        document.getElementById('crVisSave').disabled = shown === 0;
        document.getElementById('crVisSave').style.opacity = shown === 0 ? '.5' : '1';
    };

    window.crVisSave = function () {
        var hidden = crVisBoxes()
            .filter(function (b) { return !b.checked; })
            .map(function (b) { return b.value; });

        var btn = document.getElementById('crVisSave');
        btn.disabled = true;
        btn.textContent = 'Saving…';

        fetch(CR_URL_VIS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CR_CSRF },
            body: JSON.stringify({ hidden: hidden })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                window.location.reload();
                return;
            }
            btn.disabled = false;
            btn.textContent = 'Save & refresh';
            toast(d.message || 'Could not save');
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Save & refresh';
            toast('Could not save — check your connection');
        });
    };

    // ---------------- tagging popup ----------------

    window.crTagOpen = function (untaggedOnly) {
        document.getElementById('crTagOnlyUntagged').checked = !!untaggedOnly;
        document.getElementById('crTagSearch').value = '';
        crTagFilter();
        document.getElementById('crTagModal').style.display = 'flex';
        if (!untaggedOnly) { document.getElementById('crTagSearch').focus(); }
    };

    window.crTagClose = function () {
        document.getElementById('crTagModal').style.display = 'none';
    };

    window.crTagFilter = function () {
        var q    = document.getElementById('crTagSearch').value.trim().toLowerCase();
        var only = document.getElementById('crTagOnlyUntagged').checked;
        var rows = document.querySelectorAll('#crTagTable tr[data-tagrow]');
        var shown = 0, untagged = 0;

        rows.forEach(function (tr) {
            var isUntagged = tr.getAttribute('data-untagged') === '1';
            if (isUntagged) { untagged++; }
            var ok = (!only || isUntagged) &&
                     (q === '' || tr.getAttribute('data-search').indexOf(q) !== -1);
            tr.style.display = ok ? '' : 'none';
            if (ok) { shown++; }
        });

        document.getElementById('crTagCount').textContent =
            shown + ' shown · ' + untagged + ' untagged';
    };

    function toast(msg) {
        var t = document.getElementById('crToast');
        t.textContent = msg;
        t.classList.add('on');
        clearTimeout(t._h);
        t._h = setTimeout(function () { t.classList.remove('on'); }, 2200);
    }

    // ---------------- purchase drill ----------------

    // The report's own filter state, so the drill re-derives the exact
    // same window server-side.
    //
    // These MUST be emitted with the json directive, never with escaped
    // echo: inside a script block the browser does NOT decode HTML
    // entities, so Blade's escaping turns every quote into an entity and
    // the whole script dies of a syntax error - silently taking every
    // handler on the page down with it.
    // (Careful: writing those directives literally in a comment here would
    //  make Blade compile THEM too. Hence the prose.)
    var CR_FILTERS = {
        preset:      @json($preset),
        start:       @json($start->toDateString()),
        end:         @json($end->toDateString()),
        granularity: @json($granularity),
        qurbani:     @json($excludeQurbani ? 'exclude' : 'include')
    };
    var CR_VOCAB    = @json($vocabulary);
    var CR_URL_TAG  = @json(route('products.category_report.tag'));
    var CR_URL_DRL  = @json(route('products.category_report.drill'));
    var CR_URL_HELD = @json(route('products.category_report.stock_drill'));
    var CR_URL_SALE = @json(route('products.category_report.sales_drill'));
    var CR_URL_VIS  = @json(route('products.category_report.visibility'));
    var CR_CSRF     = @json(csrf_token());

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }
    function fmtRs(v) {
        var n = Math.round(Math.abs(v));
        return (v < 0 ? '-' : '') + 'Rs ' + n.toLocaleString('en-PK');
    }

    window.crDrillClose = function () {
        document.getElementById('crDrillModal').style.display = 'none';
    };

    /* Shared plumbing for every drill: same modal, same filter params, so a
       new drill is only a URL and a renderer. */
    function crDrillOpen(title, url, params, render) {
        document.getElementById('crDrillTitle').textContent = title;
        document.getElementById('crDrillSub').textContent = 'Loading…';
        document.getElementById('crDrillBody').innerHTML =
            '<div style="padding:24px; text-align:center; color:#9CA3AF; font-size:13px;">Loading…</div>';
        document.getElementById('crDrillModal').style.display = 'flex';
        document.getElementById('crDrillFoot').style.display = 'none';

        var q = new URLSearchParams(CR_FILTERS);
        Object.keys(params).forEach(function (k) { q.set(k, params[k]); });
        // Custom ranges need the explicit dates; presets re-derive server-side.
        if (CR_FILTERS.preset !== 'custom') { q.delete('start'); q.delete('end'); }

        fetch(url + '?' + q.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) {
                    document.getElementById('crDrillBody').innerHTML =
                        '<div style="padding:24px; text-align:center; color:#DC2626; font-size:13px;">' +
                        esc(d.message || 'Could not load') + '</div>';
                    return;
                }
                render(d.drill);
            })
            .catch(function (e) {
                document.getElementById('crDrillBody').innerHTML =
                    '<div style="padding:24px; text-align:center; color:#DC2626; font-size:13px;">' +
                    esc(e.message) + '</div>';
            });
    }

    function crNum(n, d) {
        return (Number(n) || 0).toLocaleString('en-PK', { maximumFractionDigits: d == null ? 2 : d });
    }

    /* ❄️ Every packet in or out of the freezer behind one cell. */
    // HELD packets only, chiller or freezer — anything taken out is
    // deliberately absent (it has become a sale), so this list is always
    // "what is still there". One function for both sections, like the service.
    window.crHeldDrill = function (section, period, category) {
        var where = section === 'freezer' ? 'the freezer' : 'the chiller';
        crDrillOpen(category + ' — still in ' + where, CR_URL_HELD,
            { period: period, category: category, section: section }, function (dr) {
                document.getElementById('crDrillSub').textContent =
                    dr.from + (dr.from === dr.to ? '' : ' → ' + dr.to) +
                    ' · ' + crNum(dr.kg) + ' kg · ' + fmtRs(dr.value) + ' at selling price';

                if (!dr.items.length) {
                    document.getElementById('crDrillBody').innerHTML =
                        '<div style="padding:24px; text-align:center; color:#9CA3AF; font-size:13px;">Nothing from this date is still in ' + where + '.</div>';
                    return;
                }
                var html = '';
                dr.items.forEach(function (it) {
                    html += '<div class="cr-drill-row">' +
                            '<span>' +
                              '<span style="font-weight:600;">' + esc(it.product) + '</span>' +
                              '<span class="s"> · ' + crNum(it.qty, 3) + ' ' + esc(it.unit) +
                              (it.price ? ' @ ' + fmtRs(it.price) : ' · no price on file') + '</span>' +
                            '</span>' +
                            '<span style="white-space:nowrap;">' +
                              '<span style="font-weight:600;">' + fmtRs(it.value) + '</span>' +
                              '<span class="s"> · ' + esc(it.by || '—') + ' · ' + esc(it.entered) + '</span>' +
                            '</span></div>';
                });
                document.getElementById('crDrillBody').innerHTML = html;
            });
    };

    // NOTE: the freezer MOVEMENT drill (in/out events) was retired here on
    // Aug-27 when the column switched to held-stock-only. Its server side is
    // still in place — CategoryReportController::freezerDrill and
    // CategorySalesPurchaseService::freezerFlowByPeriod/freezerDrill — so the
    // movement view can be brought back without rebuilding it.

    /* 💰 What was actually sold in one cell — Level 2, then the products. */
    window.crSalesDrill = function (period, category) {
        crDrillOpen(category + ' — products sold', CR_URL_SALE,
            { period: period, category: category }, function (dr) {
                document.getElementById('crDrillSub').textContent =
                    dr.from + (dr.from === dr.to ? '' : ' → ' + dr.to) + ' · ' + fmtRs(dr.total) +
                    ' across ' + dr.groups.length + ' group' + (dr.groups.length === 1 ? '' : 's');

                if (!dr.groups.length) {
                    document.getElementById('crDrillBody').innerHTML =
                        '<div style="padding:24px; text-align:center; color:#9CA3AF; font-size:13px;">Nothing sold in this bucket.</div>';
                    return;
                }
                var qtyText = function (kg, pcs) {
                    var q = [];
                    if (Number(kg)  > 0) { q.push(crNum(kg, 1) + ' kg'); }
                    if (Number(pcs) > 0) { q.push(crNum(pcs, 0) + ' pc'); }
                    return q.join(' · ');
                };
                var html = '';
                dr.groups.forEach(function (g) {
                    var gq = qtyText(g.qty_kg, g.qty_pcs);
                    html += '<div class="cr-drill-grp">' +
                            '<span class="n">' + esc(g.level2) + '</span>' +
                            '<span class="t">' + fmtRs(g.revenue) +
                            (gq ? ' <span style="font-weight:400;color:#6B7280;font-size:11.5px;">· ' + gq + '</span>' : '') +
                            '</span></div>';
                    g.products.forEach(function (p) {
                        var pq = qtyText(p.qty_kg, p.qty_pcs);
                        html += '<div class="cr-drill-row">' +
                                '<span>' + esc(p.product_name) + '</span>' +
                                '<span style="white-space:nowrap;">' +
                                  '<span style="font-weight:600;">' + fmtRs(p.revenue) + '</span>' +
                                  (pq ? '<span class="s"> · ' + pq + '</span>' : '') +
                                  '<span class="s"> · ' + p.orders + ' order' + (p.orders === 1 ? '' : 's') + '</span>' +
                                '</span></div>';
                    });
                });
                document.getElementById('crDrillBody').innerHTML = html;
            });
    };

    window.crDrill = function (period, category) {
        var modal = document.getElementById('crDrillModal');
        document.getElementById('crDrillFoot').style.display = '';
        document.getElementById('crDrillTitle').textContent = category + ' — purchases';
        document.getElementById('crDrillSub').textContent = 'Loading…';
        document.getElementById('crDrillBody').innerHTML =
            '<div style="padding:24px; text-align:center; color:#9CA3AF; font-size:13px;">Loading…</div>';
        modal.style.display = 'flex';

        var params = new URLSearchParams(CR_FILTERS);
        params.set('period', period);
        params.set('category', category);
        // Custom ranges need the explicit dates; presets re-derive server-side.
        if (CR_FILTERS.preset !== 'custom') { params.delete('start'); params.delete('end'); }

        fetch(CR_URL_DRL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) {
                document.getElementById('crDrillBody').innerHTML =
                    '<div style="padding:24px; text-align:center; color:#DC2626; font-size:13px;">' +
                    esc(d.message || 'Could not load') + '</div>';
                return;
            }
            var dr = d.drill;
            document.getElementById('crDrillSub').textContent =
                dr.from + ' → ' + dr.to + ' · ' + fmtRs(dr.total) + ' across ' +
                dr.vendors.length + ' vendor' + (dr.vendors.length === 1 ? '' : 's');

            if (!dr.vendors.length) {
                document.getElementById('crDrillBody').innerHTML =
                    '<div style="padding:24px; text-align:center; color:#9CA3AF; font-size:13px;">No purchases in this bucket.</div>';
                return;
            }

            var num = function (n, d) {
                return n.toLocaleString('en-PK', { maximumFractionDigits: d == null ? 1 : d });
            };

            var html = '';
            dr.vendors.forEach(function (v) {
                var vq = [];
                if (v.qty_kg  > 0) { vq.push(num(v.qty_kg) + ' kg'); }
                if (v.qty_pcs > 0) { vq.push(num(v.qty_pcs, 0) + ' pc'); }

                html += '<div class="cr-drill-vendor">' +
                        '<span class="n">' + esc(v.vendor_name) + '</span>' +
                        '<span class="t">' + fmtRs(v.total) +
                        (vq.length ? ' <span style="font-weight:400;color:#6B7280;font-size:11.5px;">· ' +
                            vq.join(' · ') + '</span>' : '') +
                        '</span></div>';

                v.products.forEach(function (p) {
                    var q = [];
                    if (p.qty_kg  > 0) { q.push(num(p.qty_kg) + ' kg'); }
                    if (p.qty_pcs > 0) { q.push(num(p.qty_pcs, 0) + ' pc'); }
                    var qtyText = q.length ? q.join(' · ')
                                : (p.kind === 'adjustment' ? 'adjustment' : 'no qty');

                    // Tag control, right where the money is.
                    var sel = '<select class="cr-tag-select' + (p.tag_value ? '' : ' untagged') + '"' +
                              ' style="min-width:112px; height:26px; font-size:11.5px;"' +
                              ' onchange="crSaveTag(this,\'' + p.tag_scope + '\',' + p.tag_id + ')">' +
                              '<option value="">— untagged —</option>' +
                              CR_VOCAB.map(function (o) {
                                  return '<option value="' + esc(o) + '"' +
                                         (o === p.tag_value ? ' selected' : '') + '>' + esc(o) + '</option>';
                              }).join('') + '</select>';

                    html += '<div class="cr-drill-line" style="align-items:center;">' +
                            '<span style="min-width:0; flex:1 1 auto;">' +
                              '<span class="' + (p.kind === 'item' ? '' : 'cr-drill-lump') + '"' +
                              ' style="font-weight:600;">' + esc(p.name) + '</span>' +
                              '<span class="d" style="margin:0 0 0 7px;">×' + p.count + '</span>' +
                              '<br><span class="q">' + qtyText + '</span>' +
                              '<span class="d" style="margin-left:7px;">' +
                                (p.tag_scope === 'vendor' ? 'tagged on the vendor' : '') + '</span>' +
                            '</span>' +
                            '<span style="flex-shrink:0; text-align:right;">' +
                              '<span class="a" style="font-weight:600;">' + fmtRs(p.amount) + '</span>' +
                              '<br>' + sel +
                            '</span></div>';
                });
            });
            document.getElementById('crDrillBody').innerHTML = html;
        })
        .catch(function () {
            document.getElementById('crDrillBody').innerHTML =
                '<div style="padding:24px; text-align:center; color:#DC2626; font-size:13px;">Could not load — check your connection.</div>';
        });
    };

    window.crSaveTag = function (sel, scope, id) {
        var value = sel.value;
        sel.disabled = true;

        fetch(CR_URL_TAG, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CR_CSRF
            },
            body: JSON.stringify({ scope: scope, id: id, category: value })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            sel.disabled = false;
            if (!d.success) {
                toast('Could not save: ' + (d.message || 'unknown error'));
                return;
            }
            sel.classList.toggle('untagged', value === '');

            // Keep the tagging table's own row state in step, so the
            // "untagged only" filter and the counter stay truthful.
            var row = sel.closest('tr[data-tagrow]');
            if (row) {
                row.setAttribute('data-untagged', value === '' ? '1' : '0');
                crTagFilter();
            }

            toast(d.message);
            document.getElementById('crStale').style.display = 'flex';
        })
        .catch(function () {
            sel.disabled = false;
            toast('Could not save — check your connection');
        });
    };

    // Esc closes whichever popup is open.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        crDrillClose();
        crTagClose();
        crVisClose();
    });
})();
</script>
@endsection
