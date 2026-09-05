{{-- Ledger Hub — Tips.

     Tips ride inside the invoice total, so every profit figure used to count them as ours. They are
     not: they are money we hold for the staff until it is handed over. From TIPS_FUND_START_DATE
     each tipped invoice moves its tip out of Sales Revenue into the TIPS_FUND liability, and a
     payout takes it back out of a real cash or bank account.

         pool = opening + collected − paid out

     ⭐ The pool is ONE number, not a set of buckets. Tips arrive in whichever account the customer
     paid into; a payout draws the whole amount from whichever account is chosen. The "how it
     arrived" line explains the number — it never constrains a payout. --}}
@extends('layouts.app')
@section('title', 'Ledger Hub — Tips')
@include('fin.hub.partials.styles')

@push('custom_css')
<style>
  .nfhub .tips-note{font-size:12.5px;color:var(--ink2);margin:-4px 0 14px;line-height:1.5}
  .nfhub .tips-actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
  .nfhub .tips-empty{padding:26px 18px;text-align:center;color:var(--ink2);font-size:13px}
  .nfhub .chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
  .nfhub .chip-in{background:var(--in-soft);color:var(--in)}
  .nfhub .chip-out{background:var(--out-soft);color:var(--out)}
  .nfhub .chip-open{background:var(--accent-soft);color:var(--accent)}
  .nfhub .tips-desc{color:var(--ink2);font-size:12px}
  .nfhub .setup-note{background:var(--owe-soft);color:var(--owe);border-radius:10px;padding:12px 15px;
                     font-size:13px;margin-bottom:14px;line-height:1.55}
  .nfhub .pending-note{background:var(--accent-soft);color:var(--accent);border-radius:10px;
                       padding:10px 14px;font-size:12.5px;margin-bottom:14px}
</style>
@endpush

@section('content')
<div class="nfhub">
    @include('fin.hub.partials.nav', ['active' => 'tips', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti])

    @php
        $money0 = fn ($n) => 'Rs. ' . number_format((float) $n, 0);
        $money2 = fn ($n) => number_format((float) $n, 2);
    @endphp

    @if(!$ready)
        <div class="setup-note">
            <b>The Tips Fund is not set up yet.</b>
            The database step has not been run, so nothing is being collected. Ask for
            <code>database/migrations/tips_fund_sep2026.sql</code> to be run, then reload this page.
        </div>
    @else

    <p class="tips-note">
        Tips are held for the staff, not counted as our profit — this is the pool, from
        <b>{{ \Carbon\Carbon::parse($allTime['cutoff'])->format('j M Y') }}</b> onwards.
        Every tipped invoice adds to it automatically; paying it out takes money from a real
        cash or bank account. Deliveries before that date are unchanged.
    </p>

    <div class="tiles">
        <div class="tile">
            <div class="t-label">💵 Pool holds · now</div>
            <div class="t-value num" style="color:{{ $allTime['balance'] > 0 ? 'var(--owe)' : 'var(--ink)' }}">
                {{ $money0($allTime['balance']) }}
            </div>
            <div class="t-sub">
                <div class="row"><span>Opening</span><span class="num">{{ $money0($allTime['opening']) }}</span></div>
                <div class="row"><span>Collected (all time)</span><span class="g num">{{ $money0($allTime['collected']) }}</span></div>
                <div class="row"><span>Paid out (all time)</span><span class="r num">{{ $money0($allTime['paid_out']) }}</span></div>
            </div>
        </div>

        <div class="tile">
            <div class="t-label">↓ Collected · this period</div>
            <div class="t-value num" style="color:var(--in)">{{ $money0($summary['collected']) }}</div>
            <div class="t-sub">
                {{-- How the tips arrived. Informational: a payout is never split to match this. --}}
                <div class="row"><span>On online orders</span><span class="num">{{ $money0($summary['collected_online']) }}</span></div>
                <div class="row"><span>On cash orders</span><span class="num">{{ $money0($summary['collected_cash']) }}</span></div>
            </div>
        </div>

        <div class="tile">
            <div class="t-label">↑ Paid out · this period</div>
            <div class="t-value num" style="color:var(--out)">{{ $money0($summary['paid_out']) }}</div>
            <div class="t-sub">
                <div class="row"><span>Window</span><span>{{ \Carbon\Carbon::parse($startDate)->format('j M') }} – {{ \Carbon\Carbon::parse($endDate)->format('j M Y') }}</span></div>
            </div>
        </div>
    </div>

    @if(($allTime['uncollected'] ?? 0) > 0)
        {{-- Approved invoices the pool has not collected yet (delivered before the
             code existed, or after the start date moved). These never collect
             themselves — say so, and point at the button. --}}
        <div class="setup-note">
            <b>{{ $money0($allTime['uncollected']) }}</b> of tips are on approved invoices delivered since
            {{ \Carbon\Carbon::parse($allTime['cutoff'])->format('j M Y') }} that are not in the pool yet.
            @if($canOpen)
                Press <b>Collect missing tips</b> below to bring them in — once is enough.
            @else
                Taimur needs to press <b>Collect missing tips</b> once to bring them in.
            @endif
        </div>
    @endif

    @if($allTime['pending'] > 0)
        <div class="pending-note">
            <b>{{ $money0($allTime['pending']) }}</b> of tips are on delivered orders whose invoice has
            not been approved yet. They join the pool by themselves the moment those invoices are
            approved — nothing to do here.
        </div>
    @endif

    <div class="tips-actions">
        @if($canPay)
            <button class="btn primary" type="button" onclick="tipsOpenPayout()">↑ Record payout</button>
        @endif
        @if($canOpen && !$allTime['opening_set'])
            <button class="btn" type="button" onclick="tipsOpenOpening()">Set opening balance</button>
        @endif
        @if($canOpen)
            {{-- Prod has no shell for the artisan backfill. Idempotent — pressing it
                 twice collects nothing twice. Run once after deploy and whenever the
                 start date is moved. --}}
            <button class="btn ghost" type="button" id="tipsBackfillBtn" onclick="tipsBackfill()"
                    title="Collect tips on invoices delivered since {{ \Carbon\Carbon::parse($allTime['cutoff'])->format('j M Y') }} that the pool does not hold yet">
                ⟳ Collect missing tips
            </button>
        @endif
        @if(!$canPay)
            <span class="tips-desc" style="align-self:center">Paying tips out needs Shabib or Taimur.</span>
        @endif
    </div>

    @if($canOpen && !$allTime['opening_set'])
        <div class="setup-note">
            <b>One-time setup.</b> Tell the system what the tip pool is already holding today. Everything
            collected from {{ \Carbon\Carbon::parse($allTime['cutoff'])->format('j M Y') }} onwards is
            added automatically on top of it.
        </div>
    @endif

    {{-- ── By month ──────────────────────────────────────────────────
         The pool's own figures next to what the Reports tab says left profit
         that month. They agree unless an invoice is still awaiting approval
         (collects itself) or has not been collected yet (needs the button). --}}
    @if(!empty($months))
    <div class="card" style="margin-bottom:14px">
        <div class="card-head">
            <h3>By month</h3>
            <span class="meta">"Tips in Reports" is the tips figure taken out of that month's profit on the Reports tab · click a month to filter this page</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th style="text-align:right">Tips in Reports</th>
                        <th style="text-align:right">Collected into pool</th>
                        <th style="text-align:right">Paid out</th>
                        <th style="text-align:right">Difference</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($months as $m)
                    @php $isOn = $hasWindow && $startDate === $m['start']; @endphp
                    <tr style="{{ $isOn ? 'background:var(--accent-soft)' : '' }}">
                        <td>
                            <a href="{{ route('fin.hub.tips', ['scope' => $scope, 'start_date' => $m['start'], 'end_date' => $m['end']]) }}"
                               style="color:var(--accent);font-weight:700;text-decoration:none">{{ $m['label'] }}</a>
                        </td>
                        <td class="num" style="text-align:right">{{ $money2($m['reports_tips']) }}</td>
                        <td class="num" style="text-align:right;color:var(--in)">{{ $money2($m['collected']) }}</td>
                        <td class="num" style="text-align:right;color:var(--out)">{{ $m['paid_out'] > 0 ? $money2($m['paid_out']) : '' }}</td>
                        <td class="num" style="text-align:right">
                            @if(abs($m['gap']) < 0.01)
                                <span class="chip chip-in">matches</span>
                            @else
                                <span class="chip" style="background:var(--owe-soft);color:var(--owe)" title="Tips the Reports tab has taken out of profit that the pool does not hold yet — awaiting an invoice approval, or not collected yet">{{ $money2($m['gap']) }} not in pool</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div class="card">
        <div class="card-head">
            <h3>Every movement{{ $hasWindow ? ' · ' . \Carbon\Carbon::parse($startDate)->format('j M') . ' – ' . \Carbon\Carbon::parse($endDate)->format('j M Y') : '' }}</h3>
            <span class="meta">
                Newest first · in, out and what the pool held after each
                @if($hasWindow)
                    · <a href="{{ route('fin.hub.tips', ['scope' => $scope]) }}" style="color:var(--accent);font-weight:700;text-decoration:none">show all</a>
                @endif
            </span>
        </div>

        @if(empty($rows))
            <div class="tips-empty">
                Nothing yet. The first tipped invoice delivered on or after
                {{ \Carbon\Carbon::parse($allTime['cutoff'])->format('j M Y') }} will appear here.
            </div>
        @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>What</th>
                        <th>Invoice</th>
                        <th>Details</th>
                        <th style="text-align:right">In</th>
                        <th style="text-align:right">Out</th>
                        <th style="text-align:right">Pool after</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($rows as $r)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($r['date'])->format('j M Y') }}</td>
                        <td>
                            @if($r['type'] === 'tip_payout')
                                <span class="chip chip-out">Paid out</span>
                            @elseif($r['type'] === 'opening_balance')
                                <span class="chip chip-open">Opening</span>
                            @else
                                <span class="chip chip-in">Collected</span>
                            @endif
                        </td>
                        <td>
                            @if($r['order_id'])
                                {{-- The invoice behind this tip, one click away. --}}
                                <a href="{{ route('orders.index') }}?edit_order_id={{ $r['order_id'] }}"
                                   style="color:var(--accent);font-weight:600;text-decoration:none">
                                    {{ $r['order_number'] ?: ('#' . $r['order_id']) }}
                                </a>
                                @if($r['paid_by'])
                                    <span class="tips-desc"> · {{ $r['paid_by'] }}</span>
                                @endif
                            @else
                                <span class="tips-desc">—</span>
                            @endif
                        </td>
                        <td class="tips-desc">
                            {{ $r['description'] }}
                            @if($r['type'] === 'tip_payout' && $r['from_account'])
                                <br>from {{ $r['from_account'] }}@if($r['by']) · {{ $r['by'] }}@endif
                            @endif
                        </td>
                        <td class="num" style="text-align:right;color:var(--in)">{{ $r['in'] > 0 ? $money2($r['in']) : '' }}</td>
                        <td class="num" style="text-align:right;color:var(--out)">{{ $r['out'] > 0 ? $money2($r['out']) : '' }}</td>
                        <td class="num" style="text-align:right;font-weight:700">{{ $money2($r['running']) }}</td>
                        <td style="text-align:right">
                            @if($r['type'] === 'tip_payout' && $canOpen)
                                <button class="btn ghost" type="button"
                                        style="padding:3px 9px;font-size:11.5px"
                                        onclick="tipsUndoPayout({{ $r['id'] }}, '{{ $money2($r['out']) }}')">Undo</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- ── Record payout ─────────────────────────────────────────────── --}}
    <div class="hubmodal" id="tipsPayoutModal" onclick="if(event.target===this)tipsClosePayout()">
        <div class="hubmodal-box">
            <div class="hubmodal-head">
                <div>
                    <h3>Pay tips out</h3>
                    <div class="hm-sub">The pool goes down and the money leaves the account you choose</div>
                </div>
                <button class="hubmodal-x" type="button" onclick="tipsClosePayout()" aria-label="Close">✕</button>
            </div>
            <div class="hubmodal-body">
                <div class="m-err" id="tipsPayErr"></div>
                <div class="fld-row">
                    <div class="fld">
                        <label>Amount (Rs.)</label>
                        <input type="number" step="0.01" min="1" id="tipsPayAmount" placeholder="0.00">
                    </div>
                    <div class="fld">
                        <label>Date</label>
                        <input type="date" id="tipsPayDate" value="{{ now()->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}">
                    </div>
                </div>
                <div class="fld">
                    <label>Paid from</label>
                    <select id="tipsPayFrom" onchange="tipsPayFromChanged()">
                        <option value="">Choose an account…</option>
                        @foreach($payAccounts as $a)
                            <option value="{{ $a->id }}" data-cat="{{ $a->account_category }}">
                                {{ $a->account_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                {{-- ⚠ A bank movement that does not name WHICH bank is invisible to the per-bank
                     balances, and nothing downstream goes back and guesses. --}}
                <div class="fld" id="tipsPayBankWrap" style="display:none">
                    <label>Which bank</label>
                    <select id="tipsPayBank">
                        <option value="">Choose the bank…</option>
                        @foreach($banks as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fld">
                    <label>What is this for</label>
                    <input type="text" id="tipsPayReason" placeholder="e.g. monthly tip distribution" maxlength="255">
                </div>
                <div class="fld">
                    <label>Given to (optional)</label>
                    <input type="text" id="tipsPayGivenTo" placeholder="e.g. Kitchen staff" maxlength="120">
                </div>
            </div>
            <div class="hubmodal-foot">
                <button class="btn" type="button" onclick="tipsClosePayout()">Cancel</button>
                <button class="btn primary" type="button" id="tipsPaySubmit" onclick="tipsSubmitPayout()">Record payout</button>
            </div>
        </div>
    </div>

    {{-- ── Opening balance ───────────────────────────────────────────── --}}
    <div class="hubmodal" id="tipsOpeningModal" onclick="if(event.target===this)tipsCloseOpening()">
        <div class="hubmodal-box">
            <div class="hubmodal-head">
                <div>
                    <h3>Opening balance</h3>
                    <div class="hm-sub">What the tip pool is already holding today</div>
                </div>
                <button class="hubmodal-x" type="button" onclick="tipsCloseOpening()" aria-label="Close">✕</button>
            </div>
            <div class="hubmodal-body">
                <div class="m-err" id="tipsOpenErr"></div>
                <div class="fld">
                    <label>Amount (Rs.)</label>
                    <input type="number" step="0.01" min="1" id="tipsOpenAmount" placeholder="0.00">
                </div>
                <div class="fld">
                    <label>Note (optional)</label>
                    <input type="text" id="tipsOpenNote" placeholder="e.g. counted on 4 Sep" maxlength="255">
                </div>
                <p class="tips-desc" style="margin:8px 0 0">
                    This is recorded once. Tips collected from
                    {{ \Carbon\Carbon::parse($allTime['cutoff'])->format('j M Y') }} onwards are added
                    on top of it automatically.
                </p>
            </div>
            <div class="hubmodal-foot">
                <button class="btn" type="button" onclick="tipsCloseOpening()">Cancel</button>
                <button class="btn primary" type="button" id="tipsOpenSubmit" onclick="tipsSubmitOpening()">Set opening balance</button>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection

@push('custom_js')
<script>
(function () {
    'use strict';

    function csrf() {
        var el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }

    function showErr(id, msg) {
        var box = document.getElementById(id);
        if (!box) { return; }
        box.textContent = msg || '';
        box.style.display = msg ? 'block' : 'none';
    }

    function post(url, body, btnId, errId, busyLabel, idleLabel) {
        var btn = document.getElementById(btnId);
        if (btn) { btn.disabled = true; btn.textContent = busyLabel; }
        showErr(errId, '');

        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
            },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) {
                showErr(errId, (d && d.message) || 'That did not work.');
                if (btn) { btn.disabled = false; btn.textContent = idleLabel; }
                return;
            }
            if (typeof hubToast === 'function') { hubToast(d.message); }
            window.location.reload();
        })
        .catch(function (e) {
            showErr(errId, 'Could not reach the server: ' + e.message);
            if (btn) { btn.disabled = false; btn.textContent = idleLabel; }
        });
    }

    window.tipsOpenPayout = function () {
        showErr('tipsPayErr', '');
        document.getElementById('tipsPayoutModal').classList.add('on');
    };
    window.tipsClosePayout = function () {
        document.getElementById('tipsPayoutModal').classList.remove('on');
    };

    // Only a bank payout needs the bank named; cash and staff cash do not.
    window.tipsPayFromChanged = function () {
        var sel = document.getElementById('tipsPayFrom');
        var opt = sel.options[sel.selectedIndex];
        var isBank = opt && opt.getAttribute('data-cat') === 'bank';
        document.getElementById('tipsPayBankWrap').style.display = isBank ? '' : 'none';
    };

    window.tipsSubmitPayout = function () {
        var amount = parseFloat(document.getElementById('tipsPayAmount').value || '0');
        var from   = document.getElementById('tipsPayFrom').value;
        var reason = (document.getElementById('tipsPayReason').value || '').trim();
        var bankEl = document.getElementById('tipsPayBank');
        var bank   = document.getElementById('tipsPayBankWrap').style.display === 'none'
            ? null : (bankEl ? bankEl.value : null);

        if (!(amount > 0))  { showErr('tipsPayErr', 'Enter how much is being paid out.'); return; }
        if (!from)          { showErr('tipsPayErr', 'Choose which account the money is coming from.'); return; }
        if (!reason)        { showErr('tipsPayErr', 'Say what this payout is for.'); return; }

        post('{{ route('fin.hub.tips.payout') }}', {
            amount: amount,
            from_account_id: from,
            receiving_account_id: bank || null,
            reason: reason,
            given_to: (document.getElementById('tipsPayGivenTo').value || '').trim(),
            date: document.getElementById('tipsPayDate').value || null
        }, 'tipsPaySubmit', 'tipsPayErr', 'Recording…', 'Record payout');
    };

    window.tipsOpenOpening = function () {
        showErr('tipsOpenErr', '');
        document.getElementById('tipsOpeningModal').classList.add('on');
    };
    window.tipsCloseOpening = function () {
        document.getElementById('tipsOpeningModal').classList.remove('on');
    };

    window.tipsSubmitOpening = function () {
        var amount = parseFloat(document.getElementById('tipsOpenAmount').value || '0');
        if (!(amount > 0)) { showErr('tipsOpenErr', 'Enter what the pool is holding today.'); return; }

        post('{{ route('fin.hub.tips.opening') }}', {
            amount: amount,
            note: (document.getElementById('tipsOpenNote').value || '').trim()
        }, 'tipsOpenSubmit', 'tipsOpenErr', 'Saving…', 'Set opening balance');
    };

    window.tipsBackfill = function () {
        var btn = document.getElementById('tipsBackfillBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Checking every invoice…'; }
        fetch('{{ route('fin.hub.tips.backfill') }}', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
            }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            window.alert((d && d.message) || 'That did not work.');
            if (d && d.success) { window.location.reload(); return; }
            if (btn) { btn.disabled = false; btn.textContent = '⟳ Collect missing tips'; }
        })
        .catch(function (e) {
            window.alert('Could not reach the server: ' + e.message);
            if (btn) { btn.disabled = false; btn.textContent = '⟳ Collect missing tips'; }
        });
    };

    window.tipsUndoPayout = function (id, amount) {
        if (!window.confirm('Undo this payout of Rs ' + amount + '?\n\nThe money goes back into the tip pool and returns to the account it came from.')) {
            return;
        }
        fetch('{{ url('finance/hub/tips/payout') }}/' + id + '/undo', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf()
            }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) {
                window.alert((d && d.message) || 'Could not undo that payout.');
                return;
            }
            if (typeof hubToast === 'function') { hubToast(d.message); }
            window.location.reload();
        })
        .catch(function (e) { window.alert('Could not reach the server: ' + e.message); });
    };
})();
</script>
@endpush
