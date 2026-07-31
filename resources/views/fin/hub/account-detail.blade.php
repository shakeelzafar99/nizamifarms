@extends('layouts.app')
@section('title', 'Ledger Hub — ' . $account->account_name)
@include('fin.hub.partials.styles')

@php
    $typeLabels = [
        \App\Models\FIN\LedgerModel::TYPE_INVOICE => 'Invoice', \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT => 'Order Payment',
        \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT => 'Deposit', \App\Models\FIN\LedgerModel::TYPE_EXPENSE => 'Expense',
        \App\Models\FIN\LedgerModel::TYPE_VENDOR_PURCHASE => 'Vendor Purchase', \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT => 'Vendor Payment',
        \App\Models\FIN\LedgerModel::TYPE_SETTLEMENT => 'Settlement', \App\Models\FIN\LedgerModel::TYPE_TRANSFER => 'Transfer',
        \App\Models\FIN\LedgerModel::TYPE_ADJUSTMENT => 'Adjustment', \App\Models\FIN\LedgerModel::TYPE_SALARY_ADVANCE => 'Salary Advance',
        \App\Models\FIN\LedgerModel::TYPE_SALARY_PAYMENT => 'Salary', \App\Models\FIN\LedgerModel::TYPE_OPENING_BALANCE => 'Opening Balance',
    ];
    $balNeg = $balance < -0.005;
    $balHeld = $balance > 0.005;
    $canBreakdown = $isEmployee && $riderMeta && ($riderMeta['open_count'] ?? 0) > 0;
@endphp

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'accounts', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
        'oldNavUrl' => $oldUrl, 'oldNavLabel' => 'Old account page ↗'])

    <a class="back-link" href="{{ route('fin.hub.accounts', ['scope' => $scope]) }}">‹ Accounts</a>

    <div class="bal-head">
        <div class="bal-main">
            <div class="b-label">{{ $isEmployee ? 'Holding right now' : 'Balance right now' }}</div>
            <div class="num-lg num {{ $canBreakdown ? 'tap' : '' }}" @if($canBreakdown) onclick="hubOpenBreakdown()" title="See how this balance is made up" @endif style="color:{{ $balNeg ? 'var(--out)' : ($isEmployee && $balHeld ? 'var(--owe)' : 'var(--in)') }}">Rs. {{ number_format($balance, 2) }}</div>
            <div class="b-note">
                {{ $account->account_name }} <span class="mono" style="color:var(--ink3)">{{ $account->account_code }}</span>
                @if($isEmployee) · company cash only (salary &amp; personal never move this) @endif
            </div>
        </div>
        <div class="bal-chips">
            @if($isEmployee && $riderMeta)
                <div class="stat-chip {{ $canBreakdown ? 'tap' : '' }}" @if($canBreakdown) onclick="hubOpenBreakdown()" @endif>Open invoices<b class="num">{{ $riderMeta['open_count'] }} · Rs. {{ number_format($riderMeta['open_total'], 0) }}</b></div>
                <div class="stat-chip">Last deposit<b>{{ $riderMeta['last_deposit'] ? \Carbon\Carbon::parse($riderMeta['last_deposit'])->format('M d, Y') : '—' }}</b></div>
            @else
                <div class="stat-chip">Opening balance<b class="num">Rs. {{ number_format($account->opening_balance, 0) }}</b></div>
                <div class="stat-chip">Category<b style="text-transform:capitalize">{{ $account->account_category }}</b></div>
            @endif
        </div>
        <div class="bal-actions">
            @if($isEmployee)
                @if($balHeld && !auth()->user()?->isReadOnly())<button class="btn primary" type="button" onclick="hubOpenSettle({{ $account->id }}, @js($account->account_name))">💵 Settle</button>@endif
                <a class="btn" href="{{ $oldUrl }}">Deposit / more ↗</a>
            @else
                @if(!auth()->user()?->isReadOnly())<button class="btn primary" type="button" onclick="hubOpenTransfer()">⇄ Transfer</button>@endif
                <a class="btn" href="{{ $oldUrl }}">More ↗</a>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h3>Transactions</h3>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="meta">{{ $daysLabel }} · {{ $ledger['count'] }} entries @if($isEmployee)· running balance is the calculated one in the header @endif</span>
                <div class="row-actions">
                    @foreach(['30' => '30d', '90' => '90d', '365' => '1yr', 'all' => 'All'] as $d => $lbl)
                        <a class="mini-btn {{ $daysSel === $d ? 'on' : '' }}" href="{{ route('fin.hub.account', ['id' => $account->id, 'scope' => $scope, 'days' => $d]) }}">{{ $lbl }}</a>
                    @endforeach
                </div>
            </div>
        </div>
        @forelse($ledger['groups'] as $g)
            @php
                $net = $g['net'];
                if (abs($net) < 0.005) { $netCls = 'balanced'; $netTxt = '✓ Balanced'; }
                elseif ($net > 0) { $netCls = 'holding'; $netTxt = '+ Rs. ' . number_format($net, 0); }
                else { $netCls = 'short'; $netTxt = '− Rs. ' . number_format(abs($net), 0); }
            @endphp
            <div class="day-group">
                <div class="day-head">
                    <b>{{ \Carbon\Carbon::parse($g['date'])->format('D, M d') }}</b>
                    <span>In Rs. {{ number_format($g['in'], 0) }} · Out Rs. {{ number_format($g['out'], 0) }}</span>
                    <span class="day-net {{ $netCls }}">{{ $netTxt }}</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>Time</th><th>Type</th><th>Description</th><th class="r">In</th><th class="r">Out</th>
                            @if($ledger['has_running'])<th class="r">Balance</th>@endif
                            <th>Status</th>
                        </tr></thead>
                        <tbody>
                        @foreach($g['items'] as $it)
                            @php
                                $r = $it['row'];
                                $type = $r->transaction_type;
                                $typeLabel = $typeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type));
                                $st = $r->approval_status;
                                if (in_array($st, ['pending', 'pending_l1'], true)) { $sKey = 'l1'; $sLabel = 'Pending L1'; }
                                elseif ($st === 'pending_l2') { $sKey = 'l2'; $sLabel = 'Pending L2'; }
                                elseif ($st === 'approved') { $sKey = 'ok'; $sLabel = 'Approved'; }
                                elseif ($st === 'rejected') { $sKey = 'rej'; $sLabel = 'Rejected'; }
                                elseif ($st === 'reversed') { $sKey = 'rej'; $sLabel = 'Reversed'; }
                                else { $sKey = 'l1'; $sLabel = ucfirst($st); }
                                $desc = trim((string) $r->description);
                                $other = $it['is_in'] ? optional($r->fromAccount)->account_name : optional($r->toAccount)->account_name;
                                $d = [
                                    'id' => $r->id, 'url' => route('fin.ledger.show', $r->id),
                                    'title' => $typeLabel, 'sub' => $desc !== '' ? \Illuminate\Support\Str::limit($desc, 90) : '—',
                                    'amount' => 'Rs. ' . number_format($r->amount, 2), 'dir' => $it['is_in'] ? 'in' : 'out',
                                    'mode' => ucfirst($r->mode ?? 'cash'),
                                    'from' => optional($r->fromAccount)->account_name ?? '—', 'fromsub' => optional($r->fromAccount)->account_code ?? '',
                                    'to' => optional($r->toAccount)->account_name ?? '—', 'tosub' => optional($r->toAccount)->account_code ?? '',
                                    'status' => $sKey, 'statusLabel' => $sLabel,
                                    'date' => \Carbon\Carbon::parse($r->transaction_date)->format('M d, Y'),
                                    'by' => optional($r->createdBy)->name ?? '—',
                                    'pending' => in_array($st, ['pending', 'pending_l1', 'pending_l2'], true),
                                ];
                            @endphp
                            <tr class="t-row" data-d='{{ json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'>
                                <td class="cell-date num">{{ $r->created_at ? $r->created_at->format('H:i') : '' }}</td>
                                <td><span class="type-chip">{{ $typeLabel }}</span></td>
                                <td class="desc" title="{{ $desc }}">{{ $desc !== '' ? \Illuminate\Support\Str::limit($desc, 40) : '—' }}@if($other) <span style="color:var(--ink3)">· {{ \Illuminate\Support\Str::limit($other, 18) }}</span>@endif</td>
                                <td class="r">@if($it['is_in'])<span class="amt in num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                <td class="r">@if(!$it['is_in'])<span class="amt out num">{{ number_format($r->amount, 2) }}</span>@else <span style="color:var(--ink3)">–</span>@endif</td>
                                @if($ledger['has_running'])<td class="r num" style="color:{{ $it['running'] < 0 ? 'var(--out)' : 'var(--ink2)' }}">{{ $it['running'] !== null ? number_format($it['running'], 2) : '' }}</td>@endif
                                <td><span class="status {{ $sKey }}">{{ $sLabel }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="empty">No transactions in this period ({{ $daysLabel }}). Try a longer range above.</div>
        @endforelse
    </div>

    @if($canBreakdown)
    {{-- Balance breakdown: the open invoices that add up to what the rider is holding --}}
    <div class="hubmodal" id="hubBreakdown" onclick="if(event.target===this)hubCloseBreakdown()">
        <div class="hubmodal-box">
            <div class="hubmodal-head">
                <div>
                    <h3>How this balance is held</h3>
                    <div class="hm-sub">{{ $account->account_name }} · {{ $riderMeta['open_count'] }} open {{ \Illuminate\Support\Str::plural('invoice', $riderMeta['open_count']) }}</div>
                </div>
                <button class="hubmodal-x" type="button" onclick="hubCloseBreakdown()" aria-label="Close">✕</button>
            </div>
            <div class="hubmodal-body">
                <div class="inv-list">
                    @foreach($riderMeta['invoices'] as $inv)
                        @php
                            $outstanding = (float) $inv->amount - (float) ($inv->settled_amount ?? 0);
                            $ordNo = optional($inv->order)->order_number;
                            $cust = optional(optional($inv->order)->customer)->customer_name ?? optional($inv->order)->customer_name ?? null;
                            $partial = ($inv->settlement_status === 'partial');
                        @endphp
                        <div class="inv-row">
                            <div class="iv-main">
                                <b>{{ $ordNo ? 'Order #' . $ordNo : \Illuminate\Support\Str::limit($inv->description, 40) }}</b>
                                @if($partial)<span class="type-chip" style="margin-left:6px">Partial</span>@endif
                                <div class="iv-sub">{{ \Carbon\Carbon::parse($inv->transaction_date)->format('M d, Y') }}@if($cust) · {{ \Illuminate\Support\Str::limit($cust, 26) }}@endif @if($partial)· of Rs. {{ number_format($inv->amount, 0) }} @endif</div>
                            </div>
                            <div class="iv-amt num">Rs. {{ number_format($outstanding, 2) }}</div>
                        </div>
                    @endforeach
                    <div class="inv-total"><span>Total held</span><span class="num">Rs. {{ number_format($riderMeta['open_total'], 2) }}</span></div>
                </div>
            </div>
            <div class="hubmodal-foot">
                <button class="btn primary" type="button" onclick="hubCloseBreakdown();hubOpenSettle({{ $account->id }}, @js($account->account_name))">Settle these</button>
                <button class="btn" type="button" onclick="hubCloseBreakdown()">Close</button>
            </div>
        </div>
    </div>
    <script>
        function hubOpenBreakdown(){ document.getElementById('hubBreakdown').classList.add('on'); }
        function hubCloseBreakdown(){ document.getElementById('hubBreakdown').classList.remove('on'); }
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') hubCloseBreakdown(); });
    </script>
    @endif

    @include('fin.hub.partials.settle-modal')
    @include('fin.hub.partials.drawer')
</div>
@endsection
