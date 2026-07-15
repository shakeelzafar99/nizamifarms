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
        @if(!auth()->user()?->isReadOnly())
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
            <button class="btn primary" type="button" onclick="hubOpenVendorCreate()">＋ New vendor</button>
        </div>
        @endif
        <div class="tiles">
            <div class="tile">
                <div class="t-label">🏪 Total owed · now</div>
                <div class="t-value num" style="color:{{ $totals['owed'] > 0 ? 'var(--owe)' : 'var(--in)' }}">{{ $money0($totals['owed']) }}</div>
                <div class="t-sub"><div class="row"><span>{{ $totals['with_balance'] }} {{ \Illuminate\Support\Str::plural('vendor', $totals['with_balance']) }} with a balance</span></div></div>
            </div>
            <div class="tile">
                <div class="t-label">📦 Purchases · period</div>
                <div class="t-value num">{{ $money0($totals['purchases']) }}</div>
                <div class="t-sub"><div class="row"><span>on account</span></div></div>
            </div>
            <div class="tile">
                <div class="t-label">💵 Payments · period</div>
                <div class="t-value num">{{ $money0($totals['payments']) }}</div>
                <div class="t-sub"><div class="row"><span class="g">paid down</span></div></div>
            </div>
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
        </form>

        <div class="card">
            <div class="card-head">
                <h3>Vendors</h3>
                <span class="meta">amber = NF owes · {{ $vendors->count() }} shown · purchases/payments = selected period</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Vendor</th><th class="r">NF owes</th><th class="r">Purchases</th><th class="r">Payments</th><th>Last payment</th><th class="r">Actions</th>
                    </tr></thead>
                    <tbody>
                    @forelse($vendors as $v)
                        <tr class="t-row" onclick="window.location='{{ route('fin.hub.vendor', ['id' => $v['id'], 'scope' => $scope]) }}'">
                            <td>
                                <span class="rider-name" style="gap:8px">{{ $v['name'] }}
                                    <span class="type-chip">{{ $v['method'] === 'by_weight' ? '⚖ weight' : '📦 total' }}</span>
                                </span>
                                @if($v['code'])<div class="iv-sub mono" style="color:var(--ink3);margin-left:0">{{ $v['code'] }}</div>@endif
                            </td>
                            <td class="r"><span class="amt num" style="color:{{ $v['payable'] > 0.5 ? 'var(--owe)' : 'var(--in)' }}">{{ number_format($v['payable'], 2) }}</span></td>
                            <td class="r num">{{ $v['purchases'] ? number_format($v['purchases'], 0) : '—' }}</td>
                            <td class="r num">{{ $v['payments'] ? number_format($v['payments'], 0) : '—' }}</td>
                            <td class="cell-date">{{ $v['last_pay'] ? \Carbon\Carbon::parse($v['last_pay'])->format('M d, Y') : '—' }}</td>
                            <td><div class="row-actions" onclick="event.stopPropagation()">
                                @if(!auth()->user()?->isReadOnly())
                                <button class="mini-btn" type="button" onclick="hubOpenVendorEdit(@js($v))">Edit</button>
                                @if($v['deletable'])<button class="mini-btn" type="button" onclick="hubDeleteVendor({{ $v['id'] }}, @js($v['name']))" style="color:var(--out)">Delete</button>@endif
                                @endif
                                <a class="mini-btn" href="{{ route('fin.hub.vendor', ['id' => $v['id'], 'scope' => $scope]) }}">Ledger →</a>
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">No vendors match.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <p class="footnote" style="font-size:12px;color:var(--ink3);margin-top:12px"><b>Note:</b> “NF owes” is the true current payable (all-history), not the period net.</p>
        @include('fin.hub.partials.vendor-crud-modals')
    @endif
</div>
@endsection
