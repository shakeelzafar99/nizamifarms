@extends('layouts.app')
@section('title', 'Ledger Hub — ' . $vendor->vendor_name)
@include('fin.hub.partials.styles')

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

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
                <a class="btn" href="/finance/vendors/{{ $vendor->id }}/products" title="Manage this vendor's products">Products ↗</a>
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

    <div class="card">
        <div class="card-head">
            <h3>Purchases &amp; payments</h3>
            <span class="meta">{{ $hasRange ? 'filtered range' : 'all history' }} · running balance = what NF owes</span>
        </div>
        @forelse($groups as $g)
            @php
                $net = $g['purchases'] - $g['payments'];
                if (abs($net) < 0.005) { $netCls = 'balanced'; $netTxt = '✓ Even'; }
                elseif ($net > 0) { $netCls = 'holding'; $netTxt = 'Owed + Rs. ' . number_format($net, 0); }
                else { $netCls = 'balanced'; $netTxt = 'Paid Rs. ' . number_format(abs($net), 0); }
            @endphp
            <div class="day-group">
                <div class="day-head">
                    <b>{{ \Carbon\Carbon::parse($g['date'])->format('D, M d, Y') }}</b>
                    <span>📦 Rs. {{ number_format($g['purchases'], 0) }} · 💵 Rs. {{ number_format($g['payments'], 0) }}</span>
                    <span class="day-net {{ $netCls }}">{{ $netTxt }}</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Time</th><th>Type</th><th>Description</th><th class="r">Purchase</th><th class="r">Payment</th><th class="r">Balance</th><th class="r">Actions</th></tr></thead>
                        <tbody>
                        @foreach($g['items'] as $it)
                            @php
                                $r = $it['row'];
                                $isP = $it['is_purchase'];
                                $desc = trim((string) $r->description);
                                $d = [
                                    'id' => $r->id, 'url' => route('fin.ledger.show', $r->id),
                                    'title' => $isP ? 'Vendor purchase' : 'Vendor payment',
                                    'sub' => $desc !== '' ? \Illuminate\Support\Str::limit($desc, 90) : '—',
                                    'amount' => 'Rs. ' . number_format($r->amount, 2), 'dir' => $isP ? 'owe' : 'in',
                                    'mode' => ucfirst($r->mode ?? 'cash'),
                                    'from' => optional($r->fromAccount)->account_name ?? '—', 'fromsub' => optional($r->fromAccount)->account_code ?? '',
                                    'to' => $vendor->vendor_name, 'tosub' => optional($account)->account_code ?? '',
                                    'status' => 'ok', 'statusLabel' => 'Approved',
                                    'date' => \Carbon\Carbon::parse($r->transaction_date)->format('M d, Y'),
                                    'by' => optional($r->createdBy)->name ?? '—', 'pending' => false,
                                ];
                            @endphp
                            <tr class="t-row" data-d='{{ json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'>
                                <td class="cell-date num">{{ $r->created_at ? $r->created_at->format('H:i') : '' }}</td>
                                <td><span class="type-chip">{{ $isP ? 'Purchase' : 'Payment' }}</span></td>
                                <td class="desc" title="{{ $desc }}">{{ $desc !== '' ? \Illuminate\Support\Str::limit($desc, 46) : '—' }}</td>
                                <td class="r">@if($isP)<span class="amt owe num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                <td class="r">@if(!$isP)<span class="amt in num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                <td class="r num" style="color:{{ $it['running'] > 0.5 ? 'var(--owe)' : 'var(--ink2)' }}">{{ number_format($it['running'], 2) }}</td>
                                <td>
                                    <div class="row-actions" onclick="event.stopPropagation()">
                                        @if(!auth()->user()?->isReadOnly())
                                        @if($isP && $vendor->default_purchase_method === 'by_weight')
                                            <a class="mini-btn" href="{{ $oldUrl }}" title="Edit line items on the full page">Edit ↗</a>
                                        @else
                                            <button class="mini-btn" type="button"
                                                data-edit='{{ json_encode(['id' => $r->id, 'amount' => (float) $r->amount, 'date' => \Carbon\Carbon::parse($r->transaction_date)->format('Y-m-d'), 'desc' => $desc, 'label' => $isP ? 'purchase' : 'payment'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'
                                                onclick="hubOpenEditTxn(JSON.parse(this.dataset.edit))">Edit</button>
                                        @endif
                                        <button class="mini-btn" type="button" onclick="hubDeleteTxn({{ $r->id }})" style="color:var(--out)">Delete</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="empty">No purchases or payments{{ $hasRange ? ' in this range' : '' }} yet.</div>
        @endforelse
    </div>

    @include('fin.hub.partials.vendor-op-modals')
    @include('fin.hub.partials.drawer')
</div>
@endsection
