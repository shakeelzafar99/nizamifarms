{{--
    ONE payment-proof card — the shared renderer for every screen that shows a
    proof.

    ⭐ WHY THIS EXISTS: Online Approvals had a mature renderer (three sources,
    the SMS text behind a fold, how-was-this-matched warnings, the signed amount
    gap, which of OUR banks received it, corroboration). Daily Closing carried an
    OLDER, PRIMITIVE COPY that branched only "whatsapp or else" — so every one of
    the 500+ bank-SMS proofs was captioned "Bank email" and its text was offered
    as "Show raw email text". Two copies of a money-evidence renderer will always
    drift; there is now one.

    Lifted verbatim from approvals/online.blade.php (Aug-2026) — proven
    byte-identical against 19 signal shapes before the swap. Both pages now call
    nfProofSignalCard(); neither has its own copy.

    ⭐ The card is EVIDENCE ONLY. Buttons that change what a proof is attached to
    (detach / delete) are NOT here — they stay on Online Approvals, which owns
    re-pointing a payment. A caller that has them passes them in as opts.actions,
    so this file never has to know who may do what.

    Usage:  nfProofSignalCard(signal, { orderId: 123, actions: '<html>' })
    Self-contained: its own escaping/formatting helpers, no page globals.
--}}
<script>
(function () {
    // Private helpers — deliberately NOT the pages' escapeHtml/numberFormat, so
    // this partial renders the same on any screen that includes it.
    function esc(v) {
        return v == null ? '' : String(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function num(n) {
        const v = parseFloat(n);
        return isNaN(v) ? '0' : v.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    // A screenshot that will not load says so, instead of leaving a broken-image
    // icon that reads as "the app is broken".
    window.nfProofImgFailed = function (img) {
        if (!img || img.dataset.nfFailed) return;
        img.dataset.nfFailed = '1';
        img.style.display = 'none';
        const note = document.createElement('div');
        note.style.cssText = 'padding:10px 12px; margin-bottom:10px; background:#F9FAFB; ' +
            'border:1px dashed #D1D5DB; border-radius:8px; color:#6B7280; font-size:12px;';
        note.textContent = 'The screenshot could not be loaded. Try opening it in a new tab; ' +
            'the payment details below still apply.';
        // After the <a> that wraps the thumbnail (or the image itself), so the
        // note lands where the picture was — inside the card, not after it.
        var anchor = (img.closest && img.closest('a')) || img;
        anchor.insertAdjacentElement('afterend', note);
        anchor.style.display = 'none';
    };

    /**
     * Render one proof card.
     *
     * @param {Object} s     a signal from /admin/payments/order/{id}/signals
     * @param {Object} opts  { orderId, actions }  actions = trailing button row html
     * @returns {string} html
     */
    window.nfProofSignalCard = function (s, opts) {
        opts = opts || {};
        const orderId = opts.orderId;
        const escapeHtml = esc;         // the extracted block calls these names
        const numberFormat = num;
        let out = '';

        const isWa = s.source === 'whatsapp';
        const asst = s.assistant; // recorded via NF Assistant, not sent by the customer
        const am = s.agreement || {};
        const amountColor = am.amount_match === true ? '#16A34A' : (am.amount_match === false ? '#DC2626' : '#9CA3AF');
        // Amount note: show the signed gap even when it matches within tolerance,
        // so the approver sees how much short/over the transfer is.
        let amountNote = '';
        if (am.amount_match === true) {
            const d = am.difference;
            amountNote = (d && Math.abs(d) >= 1)
                ? (d < 0 ? ` ✓ matches (Rs ${numberFormat(Math.abs(d))} short)` : ` ✓ matches (Rs ${numberFormat(d)} over)`)
                : ' ✓ matches';
        } else if (am.amount_match === false) {
            amountNote = ' (differs from balance Rs. ' + numberFormat(am.expected) + ')';
        }
        const isSms = s.source === 'bank_sms';
        const proofTitle = asst ? '✨ Recorded in NF Assistant'
            : (isWa ? '📷 Customer WhatsApp screenshot'
                : (isSms ? '📱 Bank confirmation SMS' : '✉️ Bank confirmation email'));
        out += `<div style="border:1px solid #E5E7EB; border-radius:10px; padding:14px; margin-bottom:12px;">
            <div style="font-weight:600; margin-bottom:8px;">${proofTitle}
                <span style="font-weight:400; color:#9CA3AF; font-size:12px; margin-left:6px;">${escapeHtml(s.received_at || '')}</span></div>`;

        // Provenance: this proof was entered by a manager via the assistant, not
        // received from the customer — flag it so it's weighed accordingly.
        if (asst) {
            out += `<div style="background:#F5F3FF; border:1px solid #DDD6FE; color:#5B21B6; border-radius:8px; padding:8px 10px; font-size:12px; margin-bottom:10px;">
                Recorded from ${escapeHtml(asst.method)} by <b>${escapeHtml(asst.by || 'a manager')}</b> — not sent by the customer. Confirm against the bank before approving.</div>`;
        }

        // A credit alert SMS the NF Messages app captured from the bank's own
        // sender number — this IS the bank's word that the money landed.
        if (isSms && s.bank_sms && s.bank_sms.auto) {
            out += `<div style="background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; border-radius:8px; padding:8px 10px; font-size:12px; margin-bottom:10px;">
                Captured automatically from the bank's credit alert SMS — no manual entry.</div>`;
        }

        // ⭐ HOW DID THIS PAYMENT GET HERE? Every match the system INFERRED says
        // so plainly, in its own words, and offers the way out. Real money,
        // probably this order — but the payer is unconfirmed until someone says
        // so or a screenshot pairs. A pair-verified proof shows none of this.
        const GUESS_REASONS = ['amount_unique_sms', 'name_amount_sms', 'name_ai_sms'];
        if (GUESS_REASONS.includes(s.match_reason) && !s.paired) {
            const payer = s.sender_name ? escapeHtml(s.sender_name) : 'an unnamed account';
            const explain = {
                amount_unique_sms:
                    `⚠ Matched by AMOUNT only — this was the single open invoice with this balance. The payer (${payer}) is unconfirmed.`,
                name_amount_sms:
                    `⚠ Matched by PAYER NAME — the bank's sender (${payer}) resolves to this customer, and this was their order that fits. The amount alone didn't decide it.`,
                name_ai_sms:
                    `⚠ Payer name read by AI — "${payer}" was matched to this customer from a shortlist of open orders. A best reading, not a confirmation.`,
            }[s.match_reason];

            out += `<div style="background:#FFFBEB; border:1px solid #FCD34D; color:#92400E; border-radius:8px; padding:8px 10px; font-size:12px; margin-bottom:10px;">
                <div>${explain} Confirm it in the NF Assistant money inbox, or wait for the customer's screenshot — approving is your call.</div>
            </div>`;
        }

        // A human said outright who paid. Not a guess, so no way out is offered
        // — undoing it means re-pointing the credit in the money inbox.
        if (s.match_reason === 'manual_confirmed') {
            out += `<div style="background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; border-radius:8px; padding:8px 10px; font-size:12px; margin-bottom:10px;">
                ✓ Payer confirmed by a manager — this bank name is now remembered for this customer.</div>`;
        }

        if (isWa && s.image_url) {
            out += `<a href="${s.image_url}" target="_blank"><img src="${s.image_url}" style="max-width:100%; border-radius:8px; border:1px solid #E5E7EB; margin-bottom:10px;" onerror="nfProofImgFailed(this)"/></a>`;
        }

        out += `<table style="width:100%; font-size:13px; border-collapse:collapse;">
            <tr><td style="color:#6B7280; padding:2px 0; width:140px;">Amount read</td><td style="font-weight:600; color:${amountColor};">Rs. ${s.amount != null ? numberFormat(s.amount) : '—'}${amountNote}</td></tr>
            <tr><td style="color:#6B7280; padding:2px 0;">Reference</td><td>${escapeHtml(s.reference || '—')}</td></tr>
            <tr><td style="color:#6B7280; padding:2px 0;">Sender name</td><td>${escapeHtml(s.sender_name || '—')}</td></tr>
            <tr><td style="color:#6B7280; padding:2px 0;">Sender bank</td><td>${escapeHtml(s.sender_bank || '—')}${s.sender_account ? ' · ' + escapeHtml(s.sender_account) : ''}</td></tr>
            ${s.to_account ? `<tr><td style="color:#6B7280; padding:2px 0;">To (our bank)</td><td>${escapeHtml(s.to_account)}</td></tr>` : ''}
            <tr><td style="color:#6B7280; padding:2px 0;">Txn time</td><td>${escapeHtml(s.txn_datetime || '—')}</td></tr>
            ${s.paired ? `<tr><td style="color:#6B7280; padding:2px 0;">Corroboration</td><td style="color:#16A34A;">✓ matched by the other source too</td></tr>` : ''}
        </table>`;

        if (!isWa && s.email_body) {
            out += `<details style="margin-top:8px;"><summary style="cursor:pointer; color:#2563EB; font-size:12px;">${isSms ? 'Show the SMS' : 'Show raw email'}</summary><pre style="white-space:pre-wrap; font-size:11px; color:#374151; background:#F9FAFB; padding:8px; border-radius:6px; margin-top:6px;">${escapeHtml(s.email_body)}</pre></details>`;
        }
        // Caller-supplied action row (Online Approvals passes its detach/delete
        // buttons here; Daily Closing passes nothing — it reads and approves,
        // it does not re-point payments).
        if (opts.actions) out += opts.actions;

        out += `</div>`;
        return out;
    };
})();
</script>
