@extends('layouts.app')
@section('title', 'Ledger Hub — Accounts')
@include('fin.hub.partials.styles')

@php
    $money0 = fn ($n) => 'Rs. ' . number_format($n, 0);
    $initials = function ($name) {
        $parts = preg_split('/\s+/', trim($name));
        $a = strtoupper(substr($parts[0] ?? '', 0, 1));
        $b = strtoupper(substr($parts[1] ?? ($parts[0] ?? ''), count($parts) > 1 ? 0 : 1, 1));
        return $a . $b;
    };
@endphp

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'accounts', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    {{-- ===== Company accounts ===== --}}
    <div class="sec-label">Company accounts <span class="sl-meta">balances right now — click to open the ledger</span></div>
    @if($companyAccounts->isEmpty())
        <div class="note-card">No company accounts in this scope.</div>
    @else
    <div class="tiles">
        @foreach($companyAccounts as $acc)
            @php
                $isOnline = $acc->account_category === \App\Models\FIN\AccountModel::CATEGORY_BANK;
                // The multi-bank ONLINE pool opens the Banks tab; a standalone bank account (e.g.
                // Qurbani Online) opens its own ledger instead.
                $toBanks = $isOnline && $scope !== 'qurbani';
                $href = $toBanks ? route('fin.hub.banks', ['scope' => $scope]) : route('fin.hub.account', ['id' => $acc->id, 'scope' => $scope]);
                $neg = $acc->current_balance < 0;
            @endphp
            <a class="tile acct-card" href="{{ $href }}">
                <div class="t-label">{{ $isOnline ? '🏦' : '💵' }} {{ \Illuminate\Support\Str::limit($acc->account_name, 24) }}</div>
                <div class="t-value num" style="color:{{ $neg ? 'var(--out)' : 'var(--ink)' }}">{{ $money0($acc->current_balance) }}</div>
                <div class="t-sub">
                    <div class="row"><span class="t-cat">{{ $acc->account_category }}</span><span style="color:var(--accent);font-weight:700">{{ $toBanks ? 'Banks →' : 'Ledger →' }}</span></div>
                </div>
            </a>
        @endforeach
    </div>
    @endif

    {{-- ===== Riders cash ===== --}}
    @if($riders && count($riders['rows']))
    <div class="sec-label">Riders cash
        <span class="sl-meta">{{ $money0($riders['held_total']) }} held · {{ $riders['holding'] }} holding · {{ $riders['need'] }} need settlement — balances match the mobile app</span>
    </div>
    <div class="card">
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>Rider</th><th class="r">Holding</th><th class="r">Open invoices</th><th>Last deposit</th><th>Status</th><th class="r">Actions</th>
                </tr></thead>
                <tbody>
                @foreach($riders['rows'] as $r)
                    <tr class="t-row" onclick="window.location='{{ route('fin.hub.account', ['id' => $r['id'], 'scope' => $scope]) }}'">
                        <td><span class="rider-name"><span class="avatar">{{ $initials($r['name']) }}</span>{{ $r['name'] }}</span></td>
                        <td class="r"><span class="amt num" style="color:{{ $r['calc'] < -1 ? 'var(--out)' : ($r['calc'] > 1 ? 'var(--owe)' : 'var(--in)') }}">{{ number_format($r['calc'], 2) }}</span></td>
                        <td class="r num">{{ $r['open_count'] ?: '—' }}</td>
                        <td class="cell-date">{{ $r['last_deposit'] ? \Carbon\Carbon::parse($r['last_deposit'])->format('M d') : '—' }}</td>
                        <td><span class="rstate {{ $r['state'] }}">{{ $r['state_label'] }}</span></td>
                        <td>
                            <div class="row-actions" onclick="event.stopPropagation()">
                                @if($r['calc'] > 1 && !auth()->user()?->isReadOnly())
                                    <button class="mini-btn solid" type="button" onclick="hubOpenSettle({{ $r['id'] }}, @js($r['name']))">Settle</button>
                                @endif
                                <a class="mini-btn" href="{{ route('fin.hub.account', ['id' => $r['id'], 'scope' => $scope]) }}">Ledger →</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ===== Assets ===== --}}
    @if($assets)
    <div class="sec-label">Assets <span class="sl-meta">{{ $money0($assets['total']) }} book value · {{ $assets['count'] }} assets — manage on the existing page</span></div>
    <div class="tiles">
        @foreach($assets['by_cat']->take(4) as $cat => $info)
        <div class="tile">
            <div class="t-label">📦 {{ \Illuminate\Support\Str::limit($cat, 22) }}</div>
            <div class="t-value num">{{ $money0($info['value']) }}</div>
            <div class="t-sub"><div class="row"><span>{{ $info['count'] }} {{ \Illuminate\Support\Str::plural('asset', $info['count']) }}</span></div></div>
        </div>
        @endforeach
        <a class="tile" href="/finance/assets" style="display:flex;flex-direction:column;justify-content:center;align-items:flex-start">
            <div class="t-label">All assets</div>
            <div class="t-value num">{{ $money0($assets['total']) }}</div>
            <div class="t-sub"><div class="row"><span style="color:var(--accent);font-weight:700">Manage assets →</span></div></div>
        </a>
    </div>
    @endif

    @if($companyAccounts->isEmpty() && !$riders && !$assets)
        <div class="note-card">Nothing to show in this scope yet.</div>
    @endif

    @include('fin.hub.partials.settle-modal')
</div>
@endsection
