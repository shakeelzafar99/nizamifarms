@extends('layouts.app')
@section('title', 'Ledger Hub — Overview')
@include('fin.hub.partials.styles')

@php
    // Semantic direction per transaction type (overall view has no single "viewed account",
    // so colour follows the nature of the movement — see the conventions charter).
    $dirIn  = [\App\Models\FIN\LedgerModel::TYPE_INVOICE, \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT, \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT, \App\Models\FIN\LedgerModel::TYPE_REIMBURSEMENT_PAYMENT];
    $dirOut = [\App\Models\FIN\LedgerModel::TYPE_EXPENSE, \App\Models\FIN\LedgerModel::TYPE_SETTLEMENT, \App\Models\FIN\LedgerModel::TYPE_SALARY_PAYMENT, \App\Models\FIN\LedgerModel::TYPE_SALARY_ADVANCE, \App\Models\FIN\LedgerModel::TYPE_REIMBURSEMENT_ACCRUAL, \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT];
    $dirOwe = [\App\Models\FIN\LedgerModel::TYPE_VENDOR_PURCHASE, \App\Models\FIN\LedgerModel::TYPE_ADJUSTMENT];
@endphp

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'overview', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    {{-- Two separate queues, never blended (Aug-2026). Balance actions — transfers, vendor
         payments, expenses, deposits — are a handful of items that hold real money out of the
         accounts. Invoice approvals are a daily stream of dozens. Showing one total let a stuck
         Rs 20,000 transfer hide behind ~90 invoices, so each queue now gets its own line and its
         own link to the screen that actually clears it. --}}
    @if(($pendingSplit['actions'] ?? 0) > 0)
    <div class="pending-strip">
        <span class="pdot"></span>
        <span>
            <b>{{ $pendingSplit['actions'] }} balance {{ \Illuminate\Support\Str::plural('action', $pendingSplit['actions']) }} waiting for approval</b>
            — Rs. {{ number_format($pendingSplit['actions_amount'], 0) }} in transfers, payments &amp; deposits not yet posted
        </span>
        <span class="spacer"></span>
        <a class="btn primary" style="padding:4px 12px" href="{{ route('approvals.index') }}">Review these</a>
    </div>
    @endif

    @if(($pendingSplit['invoices'] ?? 0) > 0)
    <div class="pending-strip" style="background:var(--info-soft);border-color:color-mix(in srgb,var(--info) 25%,transparent)">
        <span class="pdot" style="background:var(--info)"></span>
        <span style="color:var(--ink2)">
            <b style="color:var(--info)">{{ $pendingSplit['invoices'] }} invoice approvals</b>
            — the daily delivered-order queue, reviewed against payment proof
        </span>
        <span class="spacer"></span>
        <a class="btn" style="padding:4px 12px" href="{{ route('approvals.online') }}">Online Approvals ↗</a>
    </div>
    @endif

    {{-- Position + flow cards: headline = balance RIGHT NOW (scopes with the BU switcher),
         sub-lines = movement in the selected period. --}}
    @if($scope === 'qurbani')
        <div class="note-card">
            <b>Qurbani book.</b> Seasonal collections against the Qurbani Cash / Online accounts — kept separate from the operational ledger.
        </div>
        @if(!empty($positions['qurbani']) || $pnl)
        <div class="tiles">
            @foreach(($positions['qurbani'] ?? []) as $card)
            <div class="tile">
                <div class="t-label">🕌 {{ $card['label'] }} · now</div>
                <div class="t-value num">Rs. {{ number_format($card['balance'], 0) }}</div>
                <div class="t-sub">
                    <div class="row"><span>In this period</span><span class="g num">+ {{ number_format($card['in'], 0) }}</span></div>
                    <div class="row"><span>Out this period</span><span class="r num">− {{ number_format($card['out'], 0) }}</span></div>
                </div>
            </div>
            @endforeach
            {{-- Qurbani P&L — season-to-date, reconciled with the HQ dashboard (revenue − qurbani expenses) --}}
            @if($pnl)
            <div class="tile">
                <div class="t-label">📄 Qurbani P&amp;L · season</div>
                <div class="t-value num">Rs. {{ number_format($pnl['revenue'], 0) }}</div>
                <div class="t-sub">
                    <div class="row"><span>− Qurbani expenses</span><span class="r num">Rs. {{ number_format($pnl['expenses'], 0) }}</span></div>
                    <div class="row"><span>Net · <a href="/hq" style="color:var(--accent);font-weight:700;text-decoration:none">full P&amp;L →</a></span><span class="num" style="color:{{ $pnl['net_profit'] < 0 ? 'var(--out)' : 'var(--in)' }}">Rs. {{ number_format($pnl['net_profit'], 0) }}</span></div>
                </div>
            </div>
            @endif
        </div>
        @endif
    @else
        <div class="tiles">
            {{-- 1 · Cash tills --}}
            @if($positions['tills'])
            <a class="tile" href="{{ route('fin.hub.accounts', ['scope' => $scope]) }}" title="Open Accounts">
                <div class="t-label">💵 Cash tills · now</div>
                <div class="t-value num" style="color:{{ $positions['tills']['total'] < 0 ? 'var(--out)' : 'var(--ink)' }}">Rs. {{ number_format($positions['tills']['total'], 0) }}</div>
                <div class="t-sub">
                    @foreach($positions['tills']['accounts']->take(3) as $acc)
                        <div class="row"><span>{{ \Illuminate\Support\Str::limit($acc->account_name, 20) }}</span><span class="num" style="color:{{ $acc->current_balance < 0 ? 'var(--out)' : 'inherit' }}">{{ number_format($acc->current_balance, 0) }}</span></div>
                    @endforeach
                    <div class="row"><span>This period</span><span class="num"><span class="g">+ {{ number_format($positions['tills']['in'], 0) }}</span> · <span class="r">− {{ number_format($positions['tills']['out'], 0) }}</span></span></div>
                </div>
            </a>
            @endif

            {{-- 2 · Online / banks --}}
            @if($positions['online'])
            <a class="tile" href="{{ route('fin.hub.banks', ['scope' => $scope]) }}" title="Open Banks">
                <div class="t-label">🏦 Online pool · now</div>
                <div class="t-value num">Rs. {{ number_format($positions['online']['total'], 0) }}</div>
                <div class="t-sub">
                    <div class="row"><span>This period</span><span class="num"><span class="g">+ {{ number_format($positions['online']['in'], 0) }}</span> · <span class="r">− {{ number_format($positions['online']['out'], 0) }}</span></span></div>
                    @if($kpis)
                    <div class="row"><span>⏳ Pending L1 · L2</span><span class="o num">{{ number_format($kpis['online_pending_l1'], 0) }} · {{ number_format($kpis['online_pending_l2'], 0) }}</span></div>
                    @endif
                    <div class="row"><span>By bank</span><span style="color:var(--accent);font-weight:700">Banks →</span></div>
                </div>
            </a>
            @endif

            {{-- 3 · Cash with riders (CALCULATED — same formula as mobile + daily-close sheet) --}}
            @if($positions['riders'])
            <a class="tile" href="{{ route('fin.hub.accounts', ['scope' => $scope]) }}" title="Open Accounts">
                <div class="t-label">👥 With riders · now</div>
                <div class="t-value num" style="color:{{ $positions['riders']['total'] > 0.005 ? 'var(--owe)' : 'var(--in)' }}">Rs. {{ number_format($positions['riders']['total'], 0) }}</div>
                <div class="t-sub">
                    <div class="row"><span>Riders holding</span><span class="num">{{ $positions['riders']['holding'] }} of {{ $positions['riders']['count'] }}</span></div>
                    @if($kpis)
                    <div class="row"><span>⏳ Deposits pending</span><span class="g num">Rs. {{ number_format($kpis['pending_deposits'], 0) }}</span></div>
                    <div class="row"><span>⏳ Expenses pending</span><span class="r num">Rs. {{ number_format($kpis['pending_expenses'], 0) }}</span></div>
                    @endif
                </div>
            </a>
            @endif

            {{-- 4 · Vendors (TRUE payable, not the period net) --}}
            @if($positions['vendors'])
            <a class="tile" href="{{ route('fin.hub.vendors', ['scope' => $scope]) }}" title="Open Vendors">
                <div class="t-label">🏪 Vendors owed · now</div>
                <div class="t-value num" style="color:{{ $positions['vendors']['payable'] > 0 ? 'var(--owe)' : 'var(--in)' }}">Rs. {{ number_format($positions['vendors']['payable'], 0) }}</div>
                <div class="t-sub">
                    <div class="row"><span>Purchases (period)</span><span class="o num">Rs. {{ number_format($positions['vendors']['purchases'], 0) }}</span></div>
                    <div class="row"><span>Payments (period)</span><span class="g num">Rs. {{ number_format($positions['vendors']['payments'], 0) }}</span></div>
                </div>
            </a>
            @endif

            {{-- 5 · Tips held for staff. A liability like vendors owed — money we are holding, not
                 money we can spend — so it sits here and never inside the cash or bank pools. --}}
            @if($positions['tips'] ?? null)
            <a class="tile" href="{{ route('fin.hub.tips', ['scope' => $scope]) }}" title="Open Tips">
                <div class="t-label">💵 Tips held · now</div>
                <div class="t-value num" style="color:{{ $positions['tips']['balance'] > 0 ? 'var(--owe)' : 'var(--ink)' }}">Rs. {{ number_format($positions['tips']['balance'], 0) }}</div>
                <div class="t-sub">
                    <div class="row"><span>Collected (period)</span><span class="g num">Rs. {{ number_format($positions['tips']['collected'], 0) }}</span></div>
                    <div class="row"><span>Paid out (period)</span><span class="r num">Rs. {{ number_format($positions['tips']['paid_out'], 0) }}</span></div>
                    @if($positions['tips']['pending'] > 0)
                        <div class="row"><span>Awaiting invoice approval</span><span class="o num">Rs. {{ number_format($positions['tips']['pending'], 0) }}</span></div>
                    @endif
                </div>
            </a>
            @endif

            {{-- 6 · Sales & profit — SCOPED and reconciled with the HQ dashboard (Frozen = line-item
                 split, NF = total − Frozen, vendor/expenses by unit). The real P&L view lives on HQ. --}}
            @if($pnl)
            <div class="tile">
                <div class="t-label">📄 Sales · this period</div>
                <div class="t-value num">Rs. {{ number_format($pnl['revenue'], 0) }}</div>
                <div class="t-sub">
                    <div class="row"><span>− Vendor purchases</span><span class="r num">Rs. {{ number_format($pnl['vendor_purchases'], 0) }}</span></div>
                    <div class="row"><span>− Expenses</span><span class="r num">Rs. {{ number_format($pnl['expenses'], 0) }}</span></div>
                    <div class="row"><span>Net · <a href="/hq" style="color:var(--accent);font-weight:700;text-decoration:none">full P&amp;L →</a></span><span class="num" style="color:{{ $pnl['net_profit'] < 0 ? 'var(--out)' : 'var(--in)' }}">Rs. {{ number_format($pnl['net_profit'], 0) }}</span></div>
                </div>
            </div>
            @endif
        </div>
    @endif

    {{-- Filter bar (GET; preserves scope) --}}
    <form class="filter-bar" method="GET" action="{{ route('fin.hub.overview') }}">
        <input type="hidden" name="scope" value="{{ $scope }}">
        <div class="filter-row">
            <div class="f-field"><label>From</label><input type="date" name="start_date" value="{{ $startDate }}"></div>
            <div class="f-field"><label>To</label><input type="date" name="end_date" value="{{ $endDate }}"></div>
            <div class="f-field"><label>Type</label>
                <select name="type">
                    <option value="">All types</option>
                    @foreach($transactionTypes as $val => $label)
                        <option value="{{ $val }}" {{ ($filters['type'] ?? '') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="f-field"><label>Mode</label>
                <select name="mode">
                    <option value="">All</option>
                    <option value="cash" {{ ($filters['mode'] ?? '') === 'cash' ? 'selected' : '' }}>Cash</option>
                    <option value="online" {{ ($filters['mode'] ?? '') === 'online' ? 'selected' : '' }}>Online</option>
                </select>
            </div>
            <div class="f-field"><label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="pending_l2" {{ ($filters['status'] ?? '') === 'pending_l2' ? 'selected' : '' }}>Pending L2</option>
                    <option value="approved" {{ ($filters['status'] ?? '') === 'approved' ? 'selected' : '' }}>Approved</option>
                    <option value="rejected" {{ ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>
            <div class="f-field"><label>Account</label>
                <select name="account_id">
                    <option value="">All accounts</option>
                    @foreach($accounts as $acc)
                        <option value="{{ $acc->id }}" {{ (string)($filters['account_id'] ?? '') === (string)$acc->id ? 'selected' : '' }}>{{ $acc->account_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="f-field grow"><label>Search description</label><input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="order #, name, note…"></div>
            <div class="f-field"><label>&nbsp;</label>
                <div style="display:flex;gap:6px">
                    <button type="submit" class="btn primary">Filter</button>
                    <a class="btn" href="{{ route('fin.hub.overview', ['scope' => $scope]) }}">Clear</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-head">
            <h3>All transactions</h3>
            <span class="meta">
                @if($ledger->total() > 0)
                    Showing {{ $ledger->firstItem() }}–{{ $ledger->lastItem() }} of {{ number_format($ledger->total()) }}
                @else
                    No transactions
                @endif
                · {{ \Carbon\Carbon::parse($startDate)->format('M d') }} – {{ \Carbon\Carbon::parse($endDate)->format('M d, Y') }}
            </span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>Date</th><th>Type</th><th>Flow</th><th>Description</th><th>Status</th><th class="r">Amount</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($ledger as $row)
                    @php
                        $type = $row->transaction_type;
                        $typeLabel = $transactionTypes[$type] ?? ucfirst(str_replace('_', ' ', $type));
                        $st = $row->approval_status;
                        if (in_array($st, ['pending', 'pending_l1'], true)) { $sKey = 'l1'; $sLabel = 'Pending L1'; }
                        elseif ($st === 'pending_l2') { $sKey = 'l2'; $sLabel = 'Pending L2'; }
                        elseif ($st === 'approved') { $sKey = 'ok'; $sLabel = 'Approved'; }
                        elseif ($st === 'rejected') { $sKey = 'rej'; $sLabel = 'Rejected'; }
                        elseif ($st === 'reversed') { $sKey = 'rej'; $sLabel = 'Reversed'; }
                        else { $sKey = 'l1'; $sLabel = ucfirst($st); }

                        if (in_array($st, ['rejected', 'reversed'], true)) { $dir = 'neutral'; }
                        elseif (in_array($type, $dirIn, true)) { $dir = 'in'; }
                        elseif (in_array($type, $dirOut, true)) { $dir = 'out'; }
                        elseif (in_array($type, $dirOwe, true)) { $dir = 'owe'; }
                        else { $dir = 'neutral'; }
                        $prefix = $dir === 'in' ? '+ ' : ($dir === 'out' ? '− ' : '');

                        $dateBasis = ($type === \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT && $row->posted_date) ? $row->posted_date : $row->transaction_date;
                        $fromName = optional($row->fromAccount)->account_name ?? '—';
                        $toName = optional($row->toAccount)->account_name ?? '—';
                        $desc = trim((string) $row->description);

                        $d = [
                            'id' => $row->id,
                            'url' => route('fin.ledger.show', $row->id),
                            'title' => $typeLabel,
                            'sub' => $desc !== '' ? \Illuminate\Support\Str::limit($desc, 90) : '—',
                            'amount' => 'Rs. ' . number_format($row->amount, 2),
                            'dir' => $dir,
                            'mode' => ucfirst($row->mode ?? 'cash'),
                            'from' => $fromName,
                            'fromsub' => optional($row->fromAccount)->account_code ?? '',
                            'to' => $toName,
                            'tosub' => optional($row->toAccount)->account_code ?? '',
                            'status' => $sKey,
                            'statusLabel' => $sLabel,
                            'date' => \Carbon\Carbon::parse($dateBasis)->format('M d, Y'),
                            // Which of OUR banks this went through. Present only on rows that touch
                            // an online account — cash-to-cash rows carry no tag, so the drawer
                            // simply shows nothing extra for them.
                            'bank' => optional($row->receivingAccount)->short_code
                                ?: optional($row->receivingAccount)->name,
                            // fullname, not name — UserModel has no `name` (always NULL otherwise)
                            'by' => optional($row->createdBy)->fullname ?? '—',
                            'pending' => in_array($st, ['pending', 'pending_l1', 'pending_l2'], true),
                        ];
                    @endphp
                    <tr class="t-row" data-d='{{ json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}'>
                        <td class="cell-date num"><b>{{ \Carbon\Carbon::parse($dateBasis)->format('M d') }}</b>@if($row->created_at) · {{ $row->created_at->format('H:i') }}@endif</td>
                        <td><span class="type-chip">{{ $typeLabel }}</span></td>
                        <td class="flow">{{ \Illuminate\Support\Str::limit($fromName, 18) }} <span class="arr">→</span> <b>{{ \Illuminate\Support\Str::limit($toName, 18) }}</b></td>
                        <td class="desc" title="{{ $desc }}">{{ $desc !== '' ? \Illuminate\Support\Str::limit($desc, 46) : '—' }}</td>
                        <td><span class="status {{ $sKey }}">{{ $sLabel }}</span></td>
                        <td class="r"><span class="amt {{ $dir }} num">{{ $prefix }}{{ number_format($row->amount, 2) }}</span></td>
                        <td class="chev">›</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty">No transactions match these filters.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($ledger->hasPages())
            <div class="hub-pager">{{ $ledger->links() }}</div>
        @endif
    </div>

    @include('fin.hub.partials.drawer')
</div>
@endsection
