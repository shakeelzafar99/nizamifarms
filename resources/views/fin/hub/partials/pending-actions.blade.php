{{-- ⏳ PENDING BALANCE ACTIONS (Aug-2026)
     Why this exists: a Rs 20,000 online transfer sat unapproved for two days on the account page.
     The row was visible with a "Pending L1" chip, but nothing connected that chip to the balance
     printed above it, and the only place to act was the Approvals Dashboard — buried under ~90
     delivered-invoice approvals.

     Included from BOTH the account-detail page and the Banks tab (the ONLINE tile on the accounts
     list routes to Banks, not to the account page, so the card must exist there or the tile's
     "N waiting for approval" chip points at a page that never shows them).

     Expects $pendingActions from PendingLedgerActionsService (forAccount or forAccounts).
     Scope is deliberately narrow — see that service: ledger rows that move balances. No invoices
     (they live in Online Approvals), no leave/equipment requests (not ledger rows at all).
     Renders nothing at all when the list is empty. --}}
@if(($pendingActions['count'] ?? 0) > 0)
<div class="pact" id="pactCard">
    <div class="pact-head">
        <h3>⏳ Waiting for approval</h3>
        <span class="pact-sub">
            @php
                $pIn = $pendingActions['missing_in']; $pOut = $pendingActions['missing_out'];
            @endphp
            @if($pIn > 0.005 && $pOut > 0.005)
                Rs. {{ number_format($pIn, 0) }} in and Rs. {{ number_format($pOut, 0) }} out are not in the balance above.
            @elseif($pIn > 0.005)
                Rs. {{ number_format($pIn, 0) }} is not in the balance above yet.
            @elseif($pOut > 0.005)
                Rs. {{ number_format($pOut, 0) }} has not left the balance above yet.
            @else
                Already reflected in the balance — these still need a final sign-off.
            @endif
        </span>
        <span class="spacer"></span>
        <span class="pact-sub num">{{ $pendingActions['count'] }} {{ \Illuminate\Support\Str::plural('item', $pendingActions['count']) }}</span>
    </div>

    @foreach($pendingActions['rows'] as $pa)
        <div class="pact-row" id="pact-{{ $pa['id'] }}">
            <div class="pact-main">
                <b>{{ $pa['type_label'] }} · {{ \Illuminate\Support\Str::limit($pa['description'] ?: '—', 60) }}</b>
                <div class="pact-meta">
                    {{ $pa['date'] }} · {{ $pa['direction'] === 'in' ? 'from' : 'to' }} {{ $pa['counterparty'] }}
                    · entered by {{ $pa['by'] }}
                    · <span class="status {{ $pa['level'] === 2 ? 'l2' : 'l1' }}">Pending L{{ $pa['level'] }}</span>
                    @if($pa['in_balance']) · already posted to the balance @endif
                </div>
            </div>
            <div class="pact-amt {{ $pa['direction'] }} num">
                {{ $pa['direction'] === 'in' ? '+' : '−' }} Rs. {{ number_format($pa['amount'], 2) }}
            </div>
            <div class="pact-btns">
                @if($pendingActions['can_approve'])
                    <button class="mini-btn solid" type="button"
                            onclick="pactAct({{ $pa['id'] }}, 'approve')">Approve</button>
                    <button class="mini-btn" type="button"
                            onclick="pactAct({{ $pa['id'] }}, 'reject')">Reject</button>
                @endif
                <a class="mini-btn" href="{{ $pa['url'] }}">Details ↗</a>
            </div>
        </div>
    @endforeach

    @unless($pendingActions['can_approve'])
        <div class="pact-note">You don't have approval rights, so these are shown for information only.</div>
    @endunless
</div>

<script>
/* Approve / reject straight from this card. Posts to the SAME endpoints the Approvals Dashboard
   uses — fin.ledger.approve / fin.ledger.reject — so the balance engine, the L1→L2 ladder and the
   server-side rights gate (guardApprovalRights) all behave identically to approving from anywhere
   else. Nothing about approval is re-implemented here.

   force_full_approval mirrors the dashboard: for an approver who holds the level the row needs,
   one click finishes it instead of parking it at L2 for a second click by the same person. The
   server ignores the flag for anyone without L2 rights. */
function pactAct(id, action) {
    if (action === 'reject' && !confirm('Reject this transaction? The money will not move.')) return;

    var row = document.getElementById('pact-' + id);
    var btns = row ? row.querySelectorAll('button') : [];
    btns.forEach(function (b) { b.disabled = true; });

    var url = '/finance/ledger/' + id + '/' + action;
    var body = action === 'approve'
        ? { approval_notes: 'Approved from the Ledger Hub pending card', force_full_approval: true }
        : { rejection_reason: 'Rejected from the Ledger Hub pending card' };

    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(body)
    })
    .then(function (r) { return r.json().catch(function () { return { success: r.ok }; }); })
    .then(function (d) {
        if (d && d.success === false) {
            alert(d.message || 'That could not be completed.');
            btns.forEach(function (b) { b.disabled = false; });
            return;
        }
        /* Balances, day totals and the running-balance column all change on the server, so a
           reload is the honest refresh — patching this card alone would leave the rest of the
           page showing pre-approval numbers. */
        window.location.reload();
    })
    .catch(function () {
        alert('Could not reach the server. Please try again.');
        btns.forEach(function (b) { b.disabled = false; });
    });
}
</script>
@endif
