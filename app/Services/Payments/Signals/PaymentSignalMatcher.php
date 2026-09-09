<?php

namespace App\Services\Payments\Signals;

use App\Models\FIN\PaymentSignal;
use App\Models\CRM\CustomerBankAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Ties a PaymentSignal to a customer's pending online order. WRITES ONLY to
 * t_fin_payment_signal — never to the ledger, order payments, or balances.
 *
 * Strategy (per product decision):
 *   1. Identify the customer.
 *      - WhatsApp signal: from the conversation (already mapped to customer).
 *      - Email signal: look up the payer's bank name in the learned alias table.
 *   2. Most-recent unpaid/partial online order (within match window): does the
 *      amount equal its remaining balance? -> match.
 *   3. Else any single other unpaid online order whose balance equals the
 *      amount -> match.
 *   4. Else multiple candidates with that amount -> match most-recent, flag
 *      ambiguous (low confidence).
 *   5. Else amount equals the SUM of >1 pending balances -> bulk hint (kept
 *      unmatched-but-noted; bulk is handled manually).
 *   6. Else proof received but nothing fits -> amount_mismatch / unmatched.
 */
class PaymentSignalMatcher
{
    /**
     * Set for the duration of one match when the CUSTOMER behind the payment was
     * itself inferred (resolved from the payer's name) rather than established.
     * The order-picking below is unchanged and still runs on real balances — but
     * its verdict inherits the uncertainty of that identity, so the match is
     * recorded under the given guess reason and is never allowed to displace
     * anyone else's guess. Reset after every match; see match().
     */
    private ?string $guessReason = null;

    /**
     * @param array|null $onlyOrderIds  When non-null, restrict candidate orders
     *   to this set (the assistant scopes a forwarded proof to the customer's
     *   Online-Approvals-queue orders). Null = every open order, the existing
     *   behaviour for WhatsApp/email proofs — untouched.
     * @param string|null $guessReason  One of PaymentSignal::GUESS_REASONS when
     *   the payer's identity was inferred. Any resulting match is stamped with
     *   it, so the UI explains how we got here and every retraction path can
     *   undo it. Null (the default) = identity is established; behaviour is
     *   exactly as before.
     */
    public function match(PaymentSignal $signal, ?array $onlyOrderIds = null, ?string $guessReason = null): PaymentSignal
    {
        try {
            $this->guessReason = $guessReason;
            return $this->doMatch($signal, $onlyOrderIds);
        } catch (\Throwable $e) {
            Log::error('PaymentSignalMatcher failed', [
                'signal_id' => $signal->id,
                'error'     => $e->getMessage(),
            ]);
            return $signal;
        } finally {
            $this->guessReason = null;
        }
    }

    /**
     * Re-evaluate an ALREADY-processed signal under the CURRENT rules (e.g. after
     * the amount tolerance was widened), so existing "amount differs" proofs can
     * be promoted to matched/verified without re-sending the screenshot. Safe and
     * idempotent.
     *
     * It re-runs the NORMAL matcher on the WhatsApp side of the pair and lets
     * corroboration propagate the verdict (and any combined links) to the email
     * side — so it never trips the duplicate-ref guard or double-links. A signal a
     * human explicitly dismissed (`combined_dismissed`) is left untouched.
     */
    public function rematch(PaymentSignal $signal): PaymentSignal
    {
        try {
            // ⚠⚠ A human already ruled on this one — re-matching it would undo
            // their correction behind their back. Covers a dismissed bulk set,
            // a rejected guess, and a detached proof alike.
            if (in_array((string) $signal->match_reason, PaymentSignal::TERMINAL_REASONS, true)) {
                return $signal;
            }

            $partner = $signal->paired_signal_id
                ? PaymentSignal::find($signal->paired_signal_id)
                : null;

            // Re-run through the side that resolves the customer reliably — the
            // WhatsApp screenshot (the email usually only inherits via pairing).
            $primary = $signal;
            if ($signal->source !== PaymentSignal::SOURCE_WHATSAPP
                && $partner && $partner->source === PaymentSignal::SOURCE_WHATSAPP) {
                $primary = $partner;
                $partner = $signal;
            }

            // ⚠⚠ A GUESSED identity is re-validated, never re-trusted (Aug-2026).
            // resolveCustomerId trusts a pre-set matched_customer_id on bank-side
            // signals — right when the auto-action service set it from a mapped
            // account (a human teaching), WRONG when it was inferred from the
            // payer's name: re-matching then stays imprisoned inside the guessed
            // customer's orders forever, and a later exact-balance coincidence
            // there would even re-attach it as a clean NON-guess match — a guess
            // laundered into certainty. The Rs 2,250 "MUHAMMAD ASLAM KHAN" credit
            // could never leave Adnan Khan this way, however much the name rules
            // improved underneath it.
            //
            // So: re-run the CURRENT name rules. Same customer → proceed, still
            // stamped a guess. Different customer or nobody → the old identity
            // goes; a re-resolution carries the guess reason forward, and no
            // resolution leaves the credit honestly unidentified (held — the
            // money-in box and the human path take it from there).
            $guessReason = null;
            if ($primary->isGuess()
                && in_array($primary->source, PaymentSignal::BANK_SIDE_SOURCES, true)) {
                $guessReason = (string) $primary->match_reason;
                $identityBefore = (int) $primary->matched_customer_id;

                if ($primary->matched_customer_id && $primary->extracted_sender_name) {
                    $resolved = app(PayerNameResolver::class)->resolve(
                        $primary->extracted_sender_name,
                        (float) $primary->extracted_amount,
                        $primary->extracted_txn_datetime
                    );
                    if ((int) ($resolved['customer_id'] ?? 0) !== $identityBefore) {
                        $primary->matched_customer_id = $resolved['customer_id'] ?? null;
                        $guessReason = PaymentSignal::REASON_NAME_AMOUNT;
                    }
                } elseif ($primary->matched_customer_id && !$primary->extracted_sender_name) {
                    // An amount-only guess has no name to re-validate with — the
                    // identity was never more than "who owed this figure", so it
                    // does not survive a re-match on its own authority.
                    $primary->matched_customer_id = null;
                }

                // Identity dropped or replaced → every trace of the old verdict
                // goes with it. The corroboration hold path below doMatch never
                // touches these fields (a fresh unidentified credit never had
                // them), so a released guess would otherwise keep pointing at
                // the wrong customer's ORDER while claiming to be unmatched —
                // exactly the half-cleaned state found in verification.
                if ((int) $primary->matched_customer_id !== $identityBefore) {
                    $primary->matched_order_id  = null;
                    $primary->match_reason      = null;
                    $primary->match_confidence  = null;
                }
            }

            // Detach the pair so the matcher re-pairs + re-propagates cleanly. We
            // clear only the match RESULT (the extracted facts stay), and never
            // reset status to 'new' — that would make the Gemini worker re-extract.
            $this->clearLinks($primary->id);
            $primary->paired_signal_id = null;
            $primary->save();

            if ($partner) {
                $this->clearLinks($partner->id);
                $partner->matched_order_id = null;
                $partner->paired_signal_id = null;
                $partner->match_reason     = null;
                $partner->match_confidence = null;
                $partner->save();
            }

            // Persist any identity change BEFORE the fresh() below discards it.
            $primary->save();

            // Through match(), not doMatch(): a guess must re-enter as a guess
            // (uncertainty stamp, no-stacking guard, capped confidence — and it
            // can never displace someone else's evidence).
            return $this->match($primary->fresh(), null, $guessReason);
        } catch (\Throwable $e) {
            Log::error('PaymentSignalMatcher rematch failed', [
                'signal_id' => $signal->id,
                'error'     => $e->getMessage(),
            ]);
            return $signal;
        }
    }

    /**
     * ⭐ PROOF-BEFORE-ORDER (Sep-2026). Re-run ONE customer's own unresolved
     * screenshots now that a new order of theirs exists.
     *
     * Matching otherwise happens exactly once, when the proof arrives. In the
     * Shopify flow the customer pays at checkout and sends the screenshot
     * minutes before the team approves the order into t_crm_prod_order — so
     * at match time their order is not there yet, the step-6 fallback parks the
     * proof on an OLD invoice, and nothing ever looks again. 3-Sep: Zahida's
     * Rs 8,007 sat on her July order as "amount differs" while SH-22582 (created
     * 43 min after her screenshot) read "No proof yet" and she was asked to pay
     * twice. Four such cases in 90 days, every one a Shopify pre-payment.
     *
     * Deliberately narrow: this customer only, WhatsApp side only (the bank
     * twin follows by pairing), still amount_mismatch / unmatched, recent, and
     * never anything a human ruled on (rematch() enforces TERMINAL_REASONS).
     * It re-enters through rematch() → match(), so the no-stacking guard,
     * pairing, displacement and the movement log all apply unchanged. Writes
     * only to the signal tables. Fail-open: callers are order-creation paths
     * and must never be blocked by proof bookkeeping.
     *
     * @return array{candidates:int, rematched:int, now_matched:int}
     */
    public function rematchCustomerProofs(int $customerId, string $trigger, int $days = 7): array
    {
        $out = ['candidates' => 0, 'rematched' => 0, 'now_matched' => 0];
        if ($customerId <= 0 || !config('payment_signals.enabled')) {
            return $out;
        }

        try {
            $signals = PaymentSignal::query()
                ->where('source', PaymentSignal::SOURCE_WHATSAPP)
                ->where('matched_customer_id', $customerId)
                ->whereIn('status', [PaymentSignal::STATUS_AMOUNT_MISMATCH, PaymentSignal::STATUS_UNMATCHED])
                ->where('extracted_amount', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('match_reason')
                      ->orWhereNotIn('match_reason', PaymentSignal::TERMINAL_REASONS);
                })
                ->where('created_at', '>=', now()->subDays(max(1, $days)))
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            $out['candidates'] = $signals->count();
            foreach ($signals as $signal) {
                $before = (int) $signal->matched_order_id;
                $result = $this->rematch($signal->fresh());
                $out['rematched']++;
                if ($result && $result->status === PaymentSignal::STATUS_MATCHED) {
                    $out['now_matched']++;
                }
                if ($result && (int) $result->matched_order_id !== $before) {
                    Log::info('PaymentSignalMatcher: customer proof re-matched', [
                        'trigger'     => $trigger,
                        'customer_id' => $customerId,
                        'signal_id'   => $signal->id,
                        'from_order'  => $before ?: null,
                        'to_order'    => $result->matched_order_id,
                        'status'      => $result->status,
                        'reason'      => $result->match_reason,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('rematchCustomerProofs failed', [
                'trigger' => $trigger, 'customer_id' => $customerId, 'error' => $e->getMessage(),
            ]);
        }

        return $out;
    }

    /**
     * READ-ONLY dry-run of the match for a given customer + amount. Mirrors
     * doMatch's decision (steps 2–6) using the SAME candidateOrders +
     * findCombinedSet, but saves NOTHING. Drives the assistant's confirmation
     * card so what the user confirms is exactly what commit will link.
     *
     * @return array{
     *   status: 'matched'|'combined'|'ambiguous'|'amount_mismatch'|'no_orders',
     *   orders: array<int, array{id:int, order_number:string, balance:float}>,
     *   reason: string,
     *   open_orders: array,   // every candidate, for the "which one?" ask
     * }
     */
    public function preview(int $customerId, float $amount, ?Carbon $anchor = null, ?array $onlyOrderIds = null): array
    {
        // A throwaway signal purely to carry the anchor time into candidateOrders.
        // The amount rides along too, so preferUnspokenFor()'s twin test reads
        // the same here as it will for the real signal at commit time.
        $probe = new PaymentSignal();
        $probe->extracted_txn_datetime = $anchor ?? Carbon::now();
        $probe->extracted_amount = $amount;

        $candidates = $this->candidateOrders($customerId, $probe, $onlyOrderIds);
        $openOrders = $candidates->map(fn ($o) => [
            'id' => (int) $o->id,
            'order_number' => $o->order_number,
            'balance' => $this->balance($o),
        ])->values()->all();

        if ($candidates->isEmpty()) {
            return ['status' => 'no_orders', 'orders' => [], 'reason' => 'no_open_orders', 'open_orders' => []];
        }

        $tolerance = PaymentProofStatusService::amountTolerance();
        $one = fn ($o) => ['id' => (int) $o->id, 'order_number' => $o->order_number, 'balance' => $this->balance($o)];

        // Invoices that already have their answer go to the back of the line,
        // exactly as in doMatch. `open_orders` above stays the FULL list — the
        // human may still deliberately pick a settled invoice from the card.
        $pool = $this->preferUnspokenFor($candidates, $probe);

        // Step 2: newest order balance matches.
        $latest = $pool->first();
        if ($latest && $this->within($this->balance($latest), $amount, $tolerance)) {
            return ['status' => 'matched', 'orders' => [$one($latest)], 'reason' => 'last_order_balance', 'open_orders' => $openOrders];
        }

        // Step 3/4: other orders whose balance equals the amount.
        $balanceMatches = $pool->filter(fn ($o) => $this->within($this->balance($o), $amount, $tolerance))->values();
        if ($balanceMatches->count() === 1) {
            return ['status' => 'matched', 'orders' => [$one($balanceMatches->first())], 'reason' => 'single_unpaid_match', 'open_orders' => $openOrders];
        }
        if ($balanceMatches->count() > 1) {
            return ['status' => 'ambiguous', 'orders' => $balanceMatches->map($one)->all(), 'reason' => 'multiple_candidates', 'open_orders' => $openOrders];
        }

        // Step 5: combined / bulk — over still-open invoices only, exactly as
        // doMatch does. This preview is used to explain the matcher to a human,
        // so any divergence here would be a lie about what will happen.
        $openInvoices = $this->unsettledOnly($pool);
        $combined = $this->findCombinedSet($openInvoices, $amount, $tolerance);
        if (is_array($combined) && !empty($combined['orders'])) {
            return ['status' => 'combined', 'orders' => array_map($one, $combined['orders']), 'reason' => $combined['reason'] ?? 'bulk_combined', 'open_orders' => $openOrders];
        }
        if (is_array($combined) && !empty($combined['ambiguous'])) {
            return ['status' => 'ambiguous', 'orders' => $openOrders, 'reason' => 'bulk_ambiguous', 'open_orders' => $openOrders];
        }

        // Step 6: amount fits nothing — would attach to the newest OPEN invoice
        // as a mismatch (the newest overall only if none is open).
        $fallback = $openInvoices->first() ?: $latest;
        return ['status' => 'amount_mismatch', 'orders' => [$one($fallback)], 'reason' => 'amount_differs', 'open_orders' => $openOrders];
    }

    private function doMatch(PaymentSignal $signal, ?array $onlyOrderIds = null): PaymentSignal
    {
        // Duplicate guard: same reference already linked on another signal.
        if ($signal->extracted_ref && $this->isDuplicateRef($signal)) {
            $signal->status = PaymentSignal::STATUS_DUPLICATE;
            $signal->save();
            return $signal;
        }

        $customerId = $this->resolveCustomerId($signal);
        if (!$customerId) {
            // A bank email frequently carries NO payer name (e.g. Meezan RAAST
            // credit alerts only show the amount, our beneficiary account and
            // the date). Rather than dead-end, corroborate an existing
            // WhatsApp screenshot by amount + date and inherit its order.
            if (in_array($signal->source, PaymentSignal::BANK_SIDE_SOURCES, true)) {
                return $this->matchEmailByCorroboration($signal);
            }
            $signal->status = PaymentSignal::STATUS_UNMATCHED;
            $signal->match_reason = 'customer_not_found';
            $signal->save();
            return $signal;
        }
        $signal->matched_customer_id = $customerId;

        $amount = (float) $signal->extracted_amount;
        if ($amount <= 0) {
            $signal->status = PaymentSignal::STATUS_UNMATCHED;
            $signal->match_reason = 'no_amount';
            $signal->save();
            $this->pair($signal);
            return $signal;
        }

        $candidates = $this->candidateOrders($customerId, $signal, $onlyOrderIds);
        $tolerance = PaymentProofStatusService::amountTolerance();

        // ⭐⭐ ALREADY ANSWERED (Sep-2026) — an invoice that already carries the
        // payer's own proof goes to the back of the line, for the exact-fit
        // steps too.
        //
        // Until now only the speculative steps narrowed the pool; steps 2-4 were
        // allowed the FULL list because "the amount EQUALS a balance" felt
        // self-proving. At a 10 PKR tolerance it was. At the 100 the owner runs,
        // "equals" means "within 100" — and an invoice paid weeks ago can
        // out-compete the invoice the customer is actually paying:
        //   • 5-Sep, Hashmi Rs 5,426 → his open SH-22626 was 299.5 away, but
        //     SH-22158 (paid 21-Aug, VERIFIED, invoice approved 24-Aug) was 87
        //     away, so step 3 gave it a SECOND "Verified" and SH-22626 read
        //     "No proof yet" — the customer was chased for money he had sent.
        //   • 6-Sep, Ghulam Mustafa Rs 2,873 → landed on SH-22613, which his
        //     Rs 2,880 of the previous day had already paid (7 away), instead of
        //     NF-19519 (300.5 away) — the invoice he was answering. It even drew
        //     a phantom "discount needed · Rs 7".
        //
        // So invoices already carrying a matched proof are moved to the back —
        // and the pool FALLS BACK to the full list when that leaves nothing, so
        // a customer re-sending the same screenshot still lands exactly where it
        // does today. Approval status is deliberately not part of the test; see
        // preferUnspokenFor() for why that would break late payments.
        $pool = $this->preferUnspokenFor($candidates, $signal);

        // Step 2: most-recent order balance match.
        $latest = $pool->first();
        if ($latest && $this->within($this->balance($latest), $amount, $tolerance)) {
            return $this->link($signal, $latest, 0.95, 'last_order_balance');
        }

        // Step 3/4: other orders whose balance equals the amount.
        $balanceMatches = $pool->filter(
            fn ($o) => $this->within($this->balance($o), $amount, $tolerance)
        )->values();

        if ($balanceMatches->count() === 1) {
            return $this->link($signal, $balanceMatches->first(), 0.85, 'single_unpaid_match');
        }
        if ($balanceMatches->count() > 1) {
            // Ambiguous — attach to most recent, low confidence, flag for human.
            return $this->link($signal, $balanceMatches->first(), 0.45, 'multiple_candidates');
        }

        // ── Everything below is SPECULATIVE ──────────────────────────────────
        // Steps 2-4 above are self-proving: the amount EQUALS an invoice's
        // balance. From here we start proposing answers the figure alone does
        // not establish, so the pool narrows to invoices that are still open.
        //
        // ⭐⭐ WHY (owner ruling Aug-2026, and the data agrees emphatically):
        // approving an invoice does NOT flip the order's payment_status or
        // total_paid — measured, 1,303 of 1,303 approved invoices still read
        // 'unpaid' with total_paid 0.00. So an APPROVED invoice stays a
        // candidate forever, and 97% of the "open" pool (5,879 → 175 rows) is
        // in fact already settled. A speculative rule rummaging through that is
        // how a payment gets welded onto an invoice Taimur approved weeks ago,
        // and it is what let a combined search consider nine of Naveed
        // Solaija's invoices when only three were genuinely owed.
        //
        // Exact matches still keep the settled invoices — a proof landing on a
        // settled invoice by exact balance is late record-keeping, harmless and
        // right (5 such matches in history), and preferUnspokenFor() only ever
        // demotes invoices that are ALREADY ANSWERED, never merely approved.
        // unsettledOnly() below is unchanged: from here the pool narrows to what
        // is genuinely still open, because everything after this line is a guess.
        $openInvoices = $this->unsettledOnly($pool);

        // Step 5: combined / bulk payment — ONE transfer that settles SEVERAL
        // open invoices. Anchored on the NEWEST invoice (the customer pays the
        // latest, then tops up older ones), preferring a contiguous run, and
        // falling back to an unanchored search when that assumption fails.
        // When a set is found we mark the signal MATCHED and link it to every
        // covered invoice (see findCombinedSet) so all of them show the proof.
        $combined = $this->findCombinedSet($openInvoices, $amount, $tolerance);
        if (is_array($combined) && !empty($combined['orders'])) {
            return $this->linkCombined($signal, $combined['orders'], $combined['confidence'], $combined['reason']);
        }

        // Step 6: proof received but nothing fits. Still attach to the most
        // recent pending order so the approver sees the screenshot in context.
        // (If we saw an ambiguous bulk — two different invoice sets sum to the
        // same amount — we don't auto-pick one; the proof panel shows the open
        // invoices so the manager can decide.)
        $ambiguousBulk = is_array($combined) && !empty($combined['ambiguous']);

        // The fallback lands on the newest invoice that is still OPEN — an
        // approved one has no approver left to inform, so flagging "amount
        // differs" on it is noise on settled business. If every invoice is
        // settled we keep today's behaviour (newest overall) rather than
        // hiding the proof entirely: a customer's screenshot must stay visible
        // somewhere, and holding it would be a step backwards.
        $fallback = $openInvoices->first() ?: $latest;

        // ⭐ PLAUSIBILITY BOUND (bank-side only). A bank credit that dwarfs the
        // order it would land on is not that order's payment — it is capital,
        // a loan, or someone else's business. Aug-2026: a held Rs 400,000
        // credit resolved by payer name to a customer whose open orders were
        // Rs 1k–35k; attaching it would have painted a nonsense "proof" on a
        // small invoice. Historically only 17 of 1,114 matched signals exceed
        // 2x their order's balance, so a 3x ceiling costs nothing real.
        //
        // WhatsApp screenshots are EXEMPT: the customer deliberately sent that
        // image about their own account, so the approver should see it in
        // context even when the figure is odd. The dumping-ground risk there
        // was the zero-balance order, already fixed in candidateOrders().
        // ⭐ …and the bound is TWO-SIDED. The ceiling alone was blind to the
        // opposite error: Aug-2026, a Rs 2,250 credit from "MUHAMMAD ASLAM
        // KHAN" was read as Adnan Khan's and painted onto his Rs 19,921
        // invoice — 0.11x, a ninth of the bill, and nothing about it says
        // "this is that payment". A part-payment is real, but a figure this
        // far below the balance is a different transaction.
        //
        // Calibrated, not guessed: 95.2% of all real matches sit at 0.8-1.25x,
        // and across the whole history a 0.5 floor refuses exactly ONE
        // bank-side speculative attach — that Aslam→Adnan one. Nouman's
        // 7,600-on-7,400 (1.03x), the case this feature exists for, is
        // untouched, as is every 0.5-0.8x part-payment.
        if ($fallback && in_array($signal->source, PaymentSignal::BANK_SIDE_SOURCES, true)) {
            $maxRatio = (float) config('payment_signals.mismatch_attach_max_ratio', 3.0);
            $minRatio = (float) config('payment_signals.mismatch_attach_min_ratio', 0.5);
            $bal = $this->balance($fallback);
            $tooLarge = $maxRatio > 0 && $bal > 0.01 && $amount > $maxRatio * $bal;
            $tooSmall = $minRatio > 0 && $bal > 0.01 && $amount < $minRatio * $bal;
            if ($tooLarge || $tooSmall) {
                $signal->status = PaymentSignal::STATUS_UNMATCHED;
                $signal->match_reason = 'amount_far_from_balance';
                $signal->match_confidence = null;
                $signal->matched_order_id = null;
                $signal->save();
                $this->clearLinks($signal->id);
                $this->pair($signal);
                return $signal;
            }
        }

        $signal->status = PaymentSignal::STATUS_AMOUNT_MISMATCH;
        // Guess context: this is the Nouman shape — we believe WHO paid, and
        // their own order is the only sensible home even though the figure is
        // off by a little. Keep the guess reason so it stays retractable and
        // the approver is told the difference rather than sold a clean match.
        $signal->match_reason = $this->guessReason
            ?: ($ambiguousBulk ? 'bulk_ambiguous' : 'amount_differs');
        $signal->match_confidence = $ambiguousBulk ? 0.30 : 0.20;
        $signal->matched_order_id = $fallback?->id;
        $signal->save();
        // No combined set survived — make sure no stale links remain on re-match.
        $this->clearLinks($signal->id);
        $this->pair($signal);
        return $signal;
    }

    private function link(PaymentSignal $signal, $order, float $confidence, string $reason): PaymentSignal
    {
        $signal->matched_order_id = $order->id;
        // An inferred identity keeps its own reason/confidence: the ORDER was
        // picked by balance, but who paid is still only our best reading.
        $signal->match_confidence = $this->guessReason ? min($confidence, 0.60) : $confidence;
        $signal->match_reason = $this->guessReason ?: $reason;
        $signal->status = PaymentSignal::STATUS_MATCHED;
        $signal->save();

        // Touch the alias' usefulness counter when a bank-side signal matched
        // via a learned payer name (email always did; bank SMS now does too).
        if ($signal->extracted_sender_name
            && in_array($signal->source, PaymentSignal::BANK_SIDE_SOURCES, true)) {
            $this->bumpAliasUsage($signal);
        }

        // Evidence about the payer evicts any stranger's guess on this order.
        if (!$signal->isGuess()) {
            $this->displaceGuessesOn((int) $order->id, (int) $signal->id);
        }

        $this->pair($signal);
        return $signal;
    }

    /** Resolve the customer behind a signal. */
    private function resolveCustomerId(PaymentSignal $signal): ?int
    {
        if ($signal->source === PaymentSignal::SOURCE_WHATSAPP && $signal->wa_conversation_id) {
            $cid = DB::table('t_wa_conversations')->where('id', $signal->wa_conversation_id)->value('customer_id');
            return $cid ? (int) $cid : null;
        }

        // Assistant-ingested screenshot: it's a WhatsApp-type image proof but it
        // arrived through the assistant (Taimur named the customer), so there is
        // NO wa_conversation_id — the customer is already resolved on the signal.
        // Trust it. Real WhatsApp signals always carry a conversation, so they
        // never reach this branch; nothing about the webhook path changes.
        if ($signal->source === PaymentSignal::SOURCE_WHATSAPP
            && !$signal->wa_conversation_id
            && $signal->matched_customer_id) {
            return (int) $signal->matched_customer_id;
        }

        // Bank SMS: the counterparty account in the SMS is mapped to a customer
        // (t_ai_counterparty_map), so the auto-action service pre-set the
        // customer before matching — same trust rule as the assistant branch.
        // Unmapped SMS never carry matched_customer_id, so they can only match
        // via corroboration with an existing screenshot, never blind by amount.
        if ($signal->source === PaymentSignal::SOURCE_BANK_SMS
            && $signal->matched_customer_id) {
            return (int) $signal->matched_customer_id;
        }

        // Email: identify the customer from the learned bank-name alias.
        if ($signal->source === PaymentSignal::SOURCE_EMAIL && $signal->extracted_sender_name) {
            $norm = CustomerBankAlias::normaliseName($signal->extracted_sender_name);
            $matches = CustomerBankAlias::query()
                ->whereRaw('LOWER(TRIM(bank_account_name)) = ?', [$norm])
                ->get();
            // Exactly one customer => confident identification.
            $customerIds = $matches->pluck('customer_id')->unique();
            if ($customerIds->count() === 1) {
                return (int) $customerIds->first();
            }
        }

        return null;
    }

    /** Customer's open online orders, most-recent first, within the window. */
    private function candidateOrders(int $customerId, PaymentSignal $signal, ?array $onlyOrderIds = null)
    {
        $windowDays = (int) config('payment_signals.match_window_days', 30);
        $methods = config('payment_signals.online_payment_methods', ['online', 'bank_transfer']);
        $openStatuses = config('payment_signals.open_payment_statuses', ['unpaid', 'partial']);

        // Anchor the window on the signal time (when the customer paid), not now.
        $anchor = $signal->extracted_txn_datetime
            ?? $signal->email_received_at
            ?? $signal->created_at
            ?? Carbon::now();
        $anchor = Carbon::parse($anchor);
        $since = $anchor->copy()->subDays($windowDays)->startOfDay();

        // Upper bound: a payment cannot belong to an order created AFTER it was
        // sent. Allow a small grace for timezone / clock skew.
        $graceDays = (int) config('payment_signals.match_future_grace_days', 1);
        $until = $anchor->copy()->addDays($graceDays)->endOfDay();

        $query = DB::table('t_crm_prod_order')
            ->where('customer_id', $customerId)
            ->whereIn('payment_status', $openStatuses)
            ->where('order_status', '!=', 'cancelled')
            // ⭐⭐ MUST STILL OWE MONEY. `payment_status` alone is not enough:
            // a Rs 0 order (or one whose total_paid already covers it) stays
            // 'unpaid' forever, and because candidates are ordered newest-first
            // such a row becomes `$latest` — the step-6 mismatch fallback then
            // welds EVERY unexplained proof for that customer onto it. Aug-2026
            // audit: NF-18759 (total_price 0.00) had accumulated NINE unrelated
            // signals (4,640 / 11,950 / 3,420 / 14,350 …), and 111 zero-value
            // orders were live proof targets. Nothing is owed on them, so they
            // are never the answer.
            ->whereRaw('(total_price - COALESCE(total_paid, 0)) > 0.01')
            ->where('order_date', '>=', $since)
            ->where('order_date', '<=', $until);

        // Caller-supplied restriction (assistant: only Approvals-queue orders).
        // Empty set → match nothing rather than everything.
        if ($onlyOrderIds !== null) {
            $query->whereIn('id', $onlyOrderIds ?: [0]);
        }

        // ⭐⭐ NO STACKING. An order already carrying a payment signal has its
        // answer; a second INFERRED credit landing on it would be claiming the
        // same invoice was paid twice by different people. Aug-2026: SH-20443
        // (Sameeha, Rs 7,533) had accumulated BOTH a Rs 7,500 and a Rs 7,600
        // credit, because the old guard only looked for WhatsApp screenshots
        // and so never saw another bank guess sitting there.
        //
        // Only guesses are held to this. Real evidence may still land on an
        // occupied order — that is how a customer's own screenshot arrives to
        // correct a wrong guess, and displaceGuessesOn() then clears the squatter.
        if ($this->guessReason) {
            $query->whereNotExists(function ($q) use ($signal) {
                $q->select(DB::raw(1))
                  ->from('t_fin_payment_signal as ps')
                  ->whereColumn('ps.matched_order_id', 't_crm_prod_order.id')
                  ->where('ps.id', '!=', (int) $signal->id)
                  ->whereIn('ps.status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH]);
            });
        }

        // The screenshot/email proves an online payment, so the order's recorded
        // payment_method is not a reliable gate (see config note). Only restrict
        // by method when explicitly required.
        if (config('payment_signals.require_online_payment_method', false)) {
            $query->whereIn('payment_method', $methods);
        }

        return $query
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->get(['id', 'order_number', 'total_price', 'total_paid', 'order_date']);
    }

    /**
     * ⭐ The order_date window an INFERRED match may consider for money that
     * arrived at $paidAt, as [from, to] datetime strings.
     *
     * An order that did not exist when the money was sent cannot be what the
     * money paid for. This never mattered while guesses were only made at the
     * instant the SMS arrived; the resweep re-runs them days later, so without
     * a bound a credit could drift onto an order raised long after it.
     *
     * ⚠⚠ The forward edge is a DAY, not an hour or two. Customers really do pay
     * before the order exists — of 1,089 matched signals, 1,080 had the order
     * first but 8 of the remaining 9 were paid within 24h of it (one paid 06:26
     * for an order placed 09:55). A tight bound would reject genuine
     * pre-payment, which is exactly the behaviour this whole project exists to
     * support. A date-only payment time (midnight) is naturally covered because
     * the edge lands at the END of the following day.
     */
    public static function guessOrderDateBounds($paidAt): array
    {
        $anchor = $paidAt ? Carbon::parse($paidAt) : Carbon::now();
        $graceDays = (int) config('payment_signals.match_future_grace_days', 1);
        $backDays  = (int) config('payment_signals.guess_lookback_days', 60);

        return [
            $anchor->copy()->subDays($backDays)->startOfDay()->toDateTimeString(),
            $anchor->copy()->addDays($graceDays)->endOfDay()->toDateTimeString(),
        ];
    }

    private function balance($order): float
    {
        return round((float) $order->total_price - (float) ($order->total_paid ?? 0), 2);
    }

    private function within(float $a, float $b, float $tol): bool
    {
        return abs($a - $b) <= $tol;
    }

    /**
     * Find the set of open invoices a single transfer settles, anchored on the
     * NEWEST invoice. Returns one of:
     *   ['orders' => [Order, ...], 'confidence' => float, 'reason' => 'bulk_combined']
     *   ['orders' => [], 'ambiguous' => true]   (two distinct sets tie — don't auto-pick)
     *   null                                    (not a combined payment)
     *
     * Strategy: the newest invoice is always included (the customer is paying
     * the latest first). We then look for a CONTIGUOUS run of the next-newest
     * invoices that covers the remainder (most intuitive, deterministic). If no
     * contiguous run fits, we fall back to any single subset of the older
     * invoices — but if more than one distinct subset matches the same amount we
     * report it ambiguous instead of guessing.
     */
    private function findCombinedSet($candidates, float $amount, float $tol): ?array
    {
        $orders = $candidates->values();
        if ($orders->count() < 2) {
            return null;
        }

        // Preferred reading: the newest invoice is part of the payment.
        $anchored = $this->anchoredCombinedSet($orders, $amount, $tol);
        if ($anchored !== null) {
            return $anchored; // includes the deliberate "ambiguous" verdict
        }

        // ⚠⚠ …but the anchor is an ASSUMPTION, not a fact, and it fails on the
        // most ordinary event there is: the customer places a NEW order before
        // paying the delivered ones. Aug-14, Naveed Solaija ordered SH-21996
        // (Rs 11,900) at 12:17 and sent Rs 35,444 at 14:30 for two DELIVERED
        // invoices — NF-19152 (16,916.80) + SH-21869 (18,527.20), exact to the
        // rupee. Both were in the candidate set. But the anchor forced the
        // 11,900 into every combination, so the search hunted for 23,544,
        // found nothing, and dumped the proof on the brand-new order as
        // "amount differs". A two-hour-old order silently poisoned it.
        //
        // So when the anchored reading yields nothing, look for the set
        // WITHOUT insisting on the newest. Only reachable over still-open
        // invoices (see doMatch), which is what keeps this safe: that pool is
        // small — 8 customers company-wide have 3+ open invoices, against 679
        // before the settled ones are excluded — so a coincidental subset is
        // close to impossible, and the exactly-one rule still guards it.
        return $this->unanchoredCombinedSet($orders, $amount, $tol);
    }

    /** The newest invoice must be part of the set (the original reading). */
    private function anchoredCombinedSet($orders, float $amount, float $tol): ?array
    {
        $newest    = $orders->first();
        $newestBal = $this->balance($newest);

        // If the newest invoice alone already exceeds the transfer, this isn't a
        // "latest + older" combination — let it fall through to mismatch.
        if ($newestBal - $amount > $tol) {
            return null;
        }

        $target = $amount - $newestBal;                 // remainder for older invoices
        $older  = $orders->slice(1)->values();

        // 1) Preferred: a contiguous run from the next-newest invoice onward.
        //    Balances are non-negative, so the running sum is monotonic — exactly
        //    one run length can hit the target, and we stop once we overshoot.
        $running = 0.0;
        $run     = [$newest];
        foreach ($older as $o) {
            $running += $this->balance($o);
            $run[]    = $o;
            if ($this->within($running, $target, $tol)) {
                return ['orders' => $run, 'confidence' => 0.75, 'reason' => 'bulk_combined'];
            }
            if ($running - $target > $tol) {
                break;
            }
        }

        // 2) Fall back to any subset of the older invoices (bounded search).
        $olderArr = $older->all();
        if (count($olderArr) > 14) {
            return null; // too many to enumerate safely; contiguous already tried
        }
        $subsets = $this->subsetsSummingTo($olderArr, $target, $tol);
        if (count($subsets) === 1) {
            return [
                'orders'     => array_merge([$newest], $subsets[0]),
                'confidence' => 0.55,
                'reason'     => 'bulk_combined',
            ];
        }
        if (count($subsets) > 1) {
            return ['orders' => [], 'ambiguous' => true];
        }

        return null;
    }

    /**
     * Any set of two or more open invoices summing to the payment — the newest
     * is not privileged. Lower confidence than the anchored reading because it
     * rests on arithmetic alone, and still refuses on ambiguity: if two
     * different sets of invoices sum to the same figure, nobody can say which
     * the customer meant, so it stays a question for a human.
     *
     * ⚠ Two or more by definition. A one-invoice "set" is a plain balance
     * match, already settled by steps 2-4 — allowing it here would sneak past
     * their exactly-one-candidate rule.
     */
    private function unanchoredCombinedSet($orders, float $amount, float $tol): ?array
    {
        $arr = $orders->all();
        if (count($arr) < 2 || count($arr) > 14) {
            return null; // same enumeration bound as the anchored search
        }

        $subsets = array_values(array_filter(
            $this->subsetsSummingTo($arr, $amount, $tol),
            fn ($s) => count($s) >= 2
        ));

        if (count($subsets) === 1) {
            // $orders is newest-first and subsetsSummingTo preserves that
            // order, so linkCombined receives the list it documents.
            return ['orders' => $subsets[0], 'confidence' => 0.50, 'reason' => 'bulk_combined'];
        }
        if (count($subsets) > 1) {
            return ['orders' => [], 'ambiguous' => true];
        }
        return null;
    }

    /**
     * Drop invoices that are already SETTLED — their invoice entry has reached
     * `approved` or `pending_l2`, so the money is accounted for and a new
     * payment cannot be for them.
     *
     * ⚠ An order can carry SEVERAL invoice ledger rows (reversed, then raised
     * again — Naveed's NF-18863 and NF-19036 both do), so this asks whether a
     * LIVE approved row exists; it must never join on "the" invoice row.
     */
    private function unsettledOnly($candidates)
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $settled = DB::table('t_fin_ledger')
            ->whereIn('order_id', $candidates->pluck('id')->all())
            ->where('transaction_type', 'invoice')
            ->whereIn('approval_status', ['approved', 'pending_l2'])
            ->distinct()
            ->pluck('order_id')
            ->all();

        if (empty($settled)) {
            return $candidates;
        }
        $settled = array_flip($settled);

        return $candidates->reject(fn ($o) => isset($settled[$o->id]))->values();
    }

    /**
     * ⭐⭐ Prefer the invoices that do NOT already have their answer.
     *
     * "Answered" means ONE thing: the invoice already carries a MATCHED proof
     * for a DIFFERENT payment. Nothing else.
     *
     * ⚠⚠ APPROVAL IS DELIBERATELY *NOT* PART OF THIS — it looks like the
     * obvious second test and it is a trap. Invoices are posted at DELIVERY and
     * approved one to five days later, while the customer pays within minutes of
     * delivery: measured over all 1,164 WhatsApp proofs, the invoice was still
     * pending_l1 at payment time in every single case, so the test would almost
     * never fire when it is right. When it DID fire it would be the one case it
     * must not touch — a customer paying LATE against an already-approved
     * invoice — and it would shove that proof onto whatever stale never-approved
     * invoice the customer still has lying around (a Rs 6.3 gap traded for a
     * Rs 8,367 one, in the replay). The Hashmi case is caught by the proof test
     * alone, which is the honest signal: SH-22158 was not merely approved, it was
     * VERIFIED by his own Aug-21 screenshot.
     *
     * ⚠⚠ FALLS BACK TO THE FULL LIST when nothing is left. This is the other
     * half of the safety: the rule can only ever REORDER a choice the customer
     * already had, never remove their last candidate. Fail-open on any error —
     * a matching decision must not be lost to bookkeeping.
     */
    private function preferUnspokenFor($candidates, PaymentSignal $signal)
    {
        if ($candidates->count() < 2) {
            return $candidates; // nothing to prefer between — same answer either way
        }

        try {
            $free = $this->withoutAnsweredOrders($candidates, $signal);
            return $free->isNotEmpty() ? $free : $candidates;
        } catch (\Throwable $e) {
            Log::warning('preferUnspokenFor failed — using the full candidate list', [
                'signal_id' => $signal->id, 'error' => $e->getMessage(),
            ]);
            return $candidates;
        }
    }

    /**
     * Drop candidates that already carry a MATCHED proof belonging to a
     * DIFFERENT payment. Three deliberate exclusions:
     *
     * ⚠⚠ THE SIGNAL'S OWN TWIN IS NOT A RIVAL. One transfer produces a
     * screenshot AND a bank SMS/email; whichever lands first matches the order,
     * and the second must be free to land on the SAME order or the pair binds
     * pointing at two different invoices (the failure retractAmountGuess()
     * exists for). describesSamePayment() keeps the twin out of the way.
     *
     * ⚠⚠ A GUESS DOES NOT ANSWER AN INVOICE. Blocking on one would stop the
     * payer's own screenshot from ever reaching the order a stranger's credit is
     * squatting on — and displaceGuessesOn() would then never fire.
     *
     * ⚠ AMOUNT_MISMATCH DOES NOT COUNT EITHER: "we could not fit it" is not an
     * answer, and a corrected second screenshot must still be able to land there.
     */
    private function withoutAnsweredOrders($candidates, PaymentSignal $signal)
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $proofs = PaymentSignal::query()
            ->whereIn('matched_order_id', $candidates->pluck('id')->all())
            ->where('status', PaymentSignal::STATUS_MATCHED)
            ->where('id', '!=', (int) $signal->id)
            ->where(function ($q) {
                // Guesses never answer an invoice — see the note above.
                $q->whereNull('match_reason')
                  ->orWhereNotIn('match_reason', PaymentSignal::GUESS_REASONS);
            })
            ->get([
                'id', 'source', 'matched_order_id', 'extracted_amount', 'extracted_ref',
                'extracted_txn_datetime', 'email_received_at', 'created_at', 'paired_signal_id',
            ]);

        if ($proofs->isEmpty()) {
            return $candidates;
        }

        $answered = [];
        foreach ($proofs as $proof) {
            if ($this->describesSamePayment($signal, $proof)) {
                continue; // our own twin
            }
            $answered[(int) $proof->matched_order_id] = true;
        }

        if (empty($answered)) {
            return $candidates;
        }

        // ⭐⭐ AN EXACT FIGURE STILL WINS. An answered invoice is only moved to
        // the back when the amount merely LANDS NEAR it; if the transfer equals
        // its balance to the rupee, that is overwhelming evidence and no
        // stale neighbouring proof may displace it. Jul-2026: a Rs 10,043
        // transfer sat exactly on NF-18967's Rs 10,043 balance while an
        // unrelated Rs 8,217 proof was wrongly parked on the same invoice —
        // without this guard the exact match would have been pushed onto an
        // invoice Rs 2,148 away. Bound to the TIGHT pairing tolerance, never the
        // loose invoice one: the bugs this rule exists for sit at Rs 7 (Ghulam
        // Mustafa) and Rs 87 (Hashmi), both far outside it.
        $exact  = PaymentProofStatusService::pairAmountTolerance();
        $amount = (float) $signal->extracted_amount;

        return $candidates->reject(function ($o) use ($answered, $amount, $exact) {
            if (!isset($answered[(int) $o->id])) {
                return false;
            }
            return !($amount > 0 && $this->within($this->balance($o), $amount, $exact));
        })->values();
    }

    /**
     * Do these two signals describe the SAME bank transfer? Bound to the tight
     * pairing tolerance (never the loose invoice one) plus the pairing window,
     * so it answers the same question pair() does. Deliberately errs towards
     * TRUE: a false "same payment" only means we keep today's behaviour, while a
     * false "different payment" would split a pair across two invoices.
     */
    private function describesSamePayment(PaymentSignal $signal, $other): bool
    {
        // Already bound as a pair, in either direction — decisive.
        if ((int) ($signal->paired_signal_id ?? 0) === (int) $other->id
            || (int) ($other->paired_signal_id ?? 0) === (int) $signal->id) {
            return true;
        }

        // One bank reference = one transaction.
        if ($signal->extracted_ref && $other->extracted_ref
            && (string) $signal->extracted_ref === (string) $other->extracted_ref) {
            return true;
        }

        if ($signal->extracted_amount === null || $other->extracted_amount === null) {
            return false;
        }
        if (abs((float) $signal->extracted_amount - (float) $other->extracted_amount)
            > PaymentProofStatusService::pairAmountTolerance()) {
            return false;
        }

        $windowDays = (int) config('payment_signals.pair_window_days', 3);
        // abs(): Carbon 3 diffs are signed.
        return abs($this->paymentTime($signal)->diffInSeconds($this->paymentTime($other)))
            <= $windowDays * 86400;
    }

    /**
     * Every non-empty subset of $orders whose balances sum to $target (±$tol).
     * Bounded by the caller to a small N, so the 2^N scan is cheap.
     *
     * @return array<int, array> list of order-sets
     */
    private function subsetsSummingTo(array $orders, float $target, float $tol): array
    {
        $n      = count($orders);
        $result = [];
        $total  = 1 << $n;
        for ($mask = 1; $mask < $total; $mask++) {
            $sum = 0.0;
            $set = [];
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $sum  += $this->balance($orders[$i]);
                    $set[] = $orders[$i];
                }
            }
            if ($this->within($sum, $target, $tol)) {
                $result[] = $set;
            }
        }
        return $result;
    }

    /**
     * Mark a signal as a combined match: status MATCHED, matched_order_id = the
     * newest invoice (backward-compatible single link), plus a link row for
     * EVERY covered invoice so all of them surface the proof. Idempotent.
     *
     * @param  array  $orders  newest-first list of covered orders
     */
    private function linkCombined(PaymentSignal $signal, array $orders, float $confidence, string $reason): PaymentSignal
    {
        $newest = $orders[0];
        $signal->matched_order_id = $newest->id;
        $signal->match_confidence = $this->guessReason ? min($confidence, 0.60) : $confidence;
        $signal->match_reason     = $this->guessReason ?: $reason;
        $signal->status           = PaymentSignal::STATUS_MATCHED;
        $signal->save();

        $this->writeLinks($signal, $orders);

        if ($signal->extracted_sender_name
            && in_array($signal->source, PaymentSignal::BANK_SIDE_SOURCES, true)) {
            $this->bumpAliasUsage($signal);
        }

        if (!$signal->isGuess()) {
            foreach ($orders as $covered) {
                $this->displaceGuessesOn((int) $covered->id, (int) $signal->id);
            }
        }

        $this->pair($signal);
        return $signal;
    }

    /** Replace a signal's covered-invoice links with exactly $orders. */
    private function writeLinks(PaymentSignal $signal, array $orders): void
    {
        $this->clearLinks($signal->id);

        $now  = now();
        $rows = [];
        foreach ($orders as $o) {
            $rows[] = [
                'signal_id'        => $signal->id,
                'order_id'         => $o->id,
                'balance_at_match' => $this->balance($o),
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }
        if (!empty($rows)) {
            DB::table('t_fin_payment_signal_order')->insert($rows);
        }
    }

    /** Remove all covered-invoice links for a signal (keeps re-matching clean). */
    private function clearLinks(int $signalId): void
    {
        DB::table('t_fin_payment_signal_order')->where('signal_id', $signalId)->delete();
    }

    /** Mirror one signal's combined links onto another (WhatsApp ⇄ email). */
    private function copyLinks(PaymentSignal $from, PaymentSignal $to): void
    {
        $links = DB::table('t_fin_payment_signal_order')->where('signal_id', $from->id)->get();
        if ($links->isEmpty()) {
            return;
        }

        $this->clearLinks($to->id);
        $now  = now();
        $rows = [];
        foreach ($links as $l) {
            $rows[] = [
                'signal_id'        => $to->id,
                'order_id'         => $l->order_id,
                'balance_at_match' => $l->balance_at_match,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }
        DB::table('t_fin_payment_signal_order')->insert($rows);
    }

    private function isDuplicateRef(PaymentSignal $signal): bool
    {
        // SAME-SIDE only (Jul-2026): a duplicate is the same EVIDENCE recorded
        // twice — two screenshots of one payment, or two bank alerts of one
        // credit. A WhatsApp screenshot and a BANK confirmation (email/SMS)
        // sharing a reference are the OPPOSITE of a duplicate: they are the two
        // sides pairing exists for. The old cross-source check would have
        // marked a customer's screenshot DUPLICATE the moment a bank-SMS
        // signal was already matched with the same reference — killing the
        // proof and the Verified upgrade.
        $sameSide = $signal->source === PaymentSignal::SOURCE_WHATSAPP
            ? [PaymentSignal::SOURCE_WHATSAPP]
            : PaymentSignal::BANK_SIDE_SOURCES;

        return PaymentSignal::query()
            ->where('id', '!=', $signal->id)
            ->where('extracted_ref', $signal->extracted_ref)
            ->whereIn('source', $sameSide)
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_DUPLICATE])
            ->exists();
    }

    /**
     * Email with no identifiable payer: corroborate an existing WhatsApp
     * screenshot by amount + date, and inherit its order/customer so the order
     * surfaces as "Verified". If none yet, keep the credit on record so a
     * later screenshot of the same amount/date links to it automatically.
     */
    private function matchEmailByCorroboration(PaymentSignal $signal): PaymentSignal
    {
        $amount = (float) $signal->extracted_amount;
        if ($amount <= 0) {
            $signal->status = PaymentSignal::STATUS_UNMATCHED;
            $signal->match_reason = 'no_amount';
            $signal->save();
            return $signal;
        }

        $wa = $this->findOppositeByAmountDate($signal, [PaymentSignal::SOURCE_WHATSAPP]);
        if ($wa && $wa->matched_order_id) {
            $this->propagateLink($wa, $signal);
            $this->bindPair($signal, $wa);
            return $signal->refresh();
        }

        // A genuine credit landed, but we can't yet say whose it is. Hold it so
        // the WhatsApp matcher links it when the customer's screenshot arrives.
        $signal->status = PaymentSignal::STATUS_UNMATCHED;
        $signal->match_reason = 'bank_credit_unidentified';
        $signal->save();
        return $signal;
    }

    /** Pair this signal with an opposite-source signal that corroborates it. */
    private function pair(PaymentSignal $signal): void
    {
        if ($signal->paired_signal_id) {
            return;
        }
        // The whatsapp side pairs with EITHER bank confirmation (email or SMS);
        // a bank-side signal only ever pairs with a screenshot — two bank
        // confirmations of the same credit must never pair with each other.
        $oppositeSources = $signal->source === PaymentSignal::SOURCE_WHATSAPP
            ? PaymentSignal::BANK_SIDE_SOURCES
            : [PaymentSignal::SOURCE_WHATSAPP];

        $opposite = $this->findOppositeByAmountDate($signal, $oppositeSources);
        if (!$opposite) {
            return;
        }

        // A PAIR IDENTIFIES THE PAYER — an amount-only guess must yield to it
        // (Jul-23 Hanna/Shaukat incident). A bank SMS that arrived while this
        // screenshot's OCR was still queued can have amount-unique-attached
        // itself to a DIFFERENT order with the same balance (the payer's own
        // invoice wasn't in the approvals queue yet). Without this retract,
        // propagateLink() would skip (it never overwrites an existing
        // matched_order) and the pair would bind pointing at two different
        // orders: the real payer stuck at "proof received", the guessed order
        // wearing a false "Bank confirmed". Retract the guess first so the
        // screenshot's ref-certain order flows onto the bank signal.
        $this->retractAmountGuess($signal, $opposite);

        // ⭐ THE TWIN (Sep-2026). One bank credit routinely produces TWO
        // bank-side signals — the bank's SMS and its email alert, seconds apart.
        // The screenshot pairs with only the nearest of them, so the retract
        // above reaches that one alone; the other twin kept whatever stranger's
        // invoice it had amount-guessed onto. Zahida's Rs 8,007 (3-Sep): the
        // screenshot paired with the email (0 s) and the SMS twin (39 s) stayed
        // "Bank confirmed" on Kashif Nazir's NF-19297. The pair proves whose
        // money this is, so every guess about this same credit must go.
        $this->retractTwinGuesses(
            $signal->source === PaymentSignal::SOURCE_WHATSAPP ? $opposite : $signal,
            $signal->source === PaymentSignal::SOURCE_WHATSAPP ? $signal : $opposite
        );

        // The email side usually carries no customer/order — push this
        // screenshot's link onto it (or vice versa) before binding the pair.
        $this->propagateLink($signal, $opposite);
        $this->bindPair($signal, $opposite);
    }

    /**
     * Release every OTHER bank-side GUESS that describes the same credit as
     * $bankSide — same amount (tight pairing tolerance), the same receiving
     * bank when both sides know it, and a transaction time within minutes.
     * Only inferred matches (GUESS_REASONS) on unpaired signals are touched:
     * a paired signal is a verification in its own right, and anything a
     * human ruled on is not a guess. Non-fatal — pairing must never fail on
     * this bookkeeping.
     */
    private function retractTwinGuesses(PaymentSignal $bankSide, PaymentSignal $evidence): void
    {
        if ($bankSide->extracted_amount === null) {
            return;
        }

        try {
            $tol    = PaymentProofStatusService::pairAmountTolerance();
            $time   = $this->paymentTime($bankSide);
            $myBank = $this->receivingBankKey($bankSide);
            $windowSeconds = 5 * 60;

            $twins = PaymentSignal::query()
                ->whereIn('source', PaymentSignal::BANK_SIDE_SOURCES)
                ->whereNotIn('id', [(int) $bankSide->id, (int) $evidence->id])
                ->whereNull('paired_signal_id')
                ->whereIn('match_reason', PaymentSignal::GUESS_REASONS)
                ->whereBetween('extracted_amount', [
                    (float) $bankSide->extracted_amount - $tol,
                    (float) $bankSide->extracted_amount + $tol,
                ])
                ->get()
                // abs(): Carbon 3 diffs are signed.
                ->filter(fn ($s) => abs($this->paymentTime($s)->diffInSeconds($time)) <= $windowSeconds)
                // Same-bank gate, as in findOppositeByAmountDate(): block only on
                // a CONFIDENT mismatch, never on an unread bank.
                ->filter(function ($s) use ($myBank) {
                    $theirBank = $this->receivingBankKey($s);
                    return !($myBank !== null && $theirBank !== null && $myBank !== $theirBank);
                });

            foreach ($twins as $twin) {
                // Same one-strike unlearn rule as retractAmountGuess(): only a
                // guess that named a DIFFERENT customer disproves its alias.
                if ($twin->matched_customer_id
                    && $evidence->matched_customer_id
                    && (int) $twin->matched_customer_id !== (int) $evidence->matched_customer_id) {
                    app(CustomerBankAliasService::class)->unlearnFromSignal($twin);
                }
                $this->releaseGuess($twin, 'amount_guess_retracted');
                Log::info('PaymentSignalMatcher: twin guess retracted', [
                    'twin_signal_id' => $twin->id,
                    'paired_bank_signal_id' => $bankSide->id,
                    'evidence_signal_id' => $evidence->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('retractTwinGuesses failed', [
                'signal_id' => $bankSide->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * If either side of a pair-to-be is an amount-only GUESS
     * (match_reason 'amount_unique_sms', 0.50 by design), clear it before
     * propagation: the opposite signal is the customer's own screenshot of the
     * SAME transfer, which outranks a queue-wide amount match. Even when the
     * screenshot carries no order yet, the guess's premise ("no proof exists
     * for this credit") is broken by the pair itself — it must come off the
     * guessed order either way. The retracted reason is kept when nothing
     * re-fills the link, so the trail stays auditable.
     */
    private function retractAmountGuess(PaymentSignal $a, PaymentSignal $b): void
    {
        // Any INFERRED match yields to the payer's own evidence — not just the
        // original amount-unique one (see PaymentSignal::GUESS_REASONS).
        $guess = $a->isGuess() ? $a : ($b->isGuess() ? $b : null);
        if (!$guess) {
            return;
        }

        // One-strike unlearn, pair edition (Aug-2026). This was the ONE
        // guess-correction path that kept the alias: manual re-points, ignore,
        // unmark and displacement all unlearn, so a wrong guess that got
        // APPROVED (teaching "SENDER -> wrong customer") and was later exposed
        // by the payer's own screenshot kept its poisoned lesson and would
        // misroute that payer's next credit too.
        //
        // Only when the evidence names a DIFFERENT customer. A guess that
        // picked the right PERSON but the wrong ORDER proves nothing against
        // the alias - deleting it there would strip a legitimate, often
        // approver-confirmed lesson every time a customer's payment lands one
        // scope away from where the ladder first put it.
        $evidence = $guess === $a ? $b : $a;
        if ($guess->matched_customer_id
            && $evidence->matched_customer_id
            && (int) $guess->matched_customer_id !== (int) $evidence->matched_customer_id) {
            app(CustomerBankAliasService::class)->unlearnFromSignal($guess);
        }

        $this->releaseGuess($guess, 'amount_guess_retracted');
    }

    /**
     * Strip an inferred match off a signal and hand the money back to the
     * humans: no order, no customer, status unmatched, and — when the guess
     * came from a bank SMS — that SMS returns to the money inbox so it is
     * visibly unresolved again rather than silently closed.
     *
     * The audit trail stays: the reason records WHY it was released.
     */
    private function releaseGuess(PaymentSignal $guess, string $reason): void
    {
        $this->clearLinks($guess->id);
        $guess->matched_order_id    = null;
        $guess->matched_customer_id = null;
        $guess->status              = PaymentSignal::STATUS_UNMATCHED;
        $guess->match_reason        = $reason;
        $guess->match_confidence    = null;
        $guess->save();

        try {
            DB::table('t_ai_bank_sms')
                ->where('linked_signal_id', $guess->id)
                ->whereIn('status', ['matched', 'recorded'])
                ->update(['status' => 'new', 'auto_reason' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // Inbox bookkeeping only — never let it break the retraction.
        }
    }

    /**
     * ⭐⭐ DISPLACEMENT — real evidence evicts a stranger's guess.
     *
     * When a match backed by the payer's OWN evidence (their screenshot, a
     * corroborated pair, an approver's explicit pick) lands on order O, any
     * OTHER signal sitting on O purely because the system INFERRED it must let
     * go: the premise of every guess is "nothing better explains this order",
     * and that premise just died. The guess goes back to unmatched and its SMS
     * reopens in the money inbox, where the resweep re-places it — usually
     * correctly, because by then the name ladder has more to work with.
     *
     * This is the missing half of retractAmountGuess(): that one fires when the
     * guessed signal is itself the one being paired; this fires when the truth
     * arrives about the ORDER the guess was squatting on. Aug-2026 case: a
     * Rs 7,600 credit from an unrelated payer sat on SH-20443 (Sameeha) — the
     * moment Sameeha's own Rs 7,533 proof lands, the stranger's credit leaves.
     *
     * Never touches a paired signal (that verification is real), and never the
     * signal doing the displacing.
     */
    private function displaceGuessesOn(?int $orderId, int $exceptSignalId): void
    {
        if (!$orderId) {
            return;
        }

        try {
            $squatters = PaymentSignal::query()
                ->where('matched_order_id', $orderId)
                ->where('id', '!=', $exceptSignalId)
                ->whereNull('paired_signal_id')
                ->whereIn('match_reason', PaymentSignal::GUESS_REASONS)
                ->get();

            foreach ($squatters as $squatter) {
                // A guess that was approved may have taught a name — that
                // lesson rested on this same broken premise, so it goes too.
                app(CustomerBankAliasService::class)->unlearnFromSignal($squatter);
                $this->releaseGuess($squatter, 'guess_displaced');
            }
        } catch (\Throwable $e) {
            Log::warning('displaceGuessesOn failed', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find the best opposite-source signal describing the SAME payment:
     * same reference (wins outright), else the SAME amount (within the TIGHT
     * pairAmountTolerance — NOT the loose invoice tolerance) AND a transaction
     * time within the configured pairing window. Requiring the amounts to agree
     * is what stops an unrelated credit of a nearby amount from being welded on
     * as false "corroboration" (the Jul-2026 "Rs 36 short" bug).
     */
    private function findOppositeByAmountDate(PaymentSignal $signal, array $oppositeSources): ?PaymentSignal
    {
        if ($signal->extracted_amount === null) {
            return null;
        }
        // Pairing tolerance is deliberately tight: a screenshot and the bank
        // email describe the SAME transfer, so their amounts must match bar
        // sub-rupee rounding. This is distinct from the loose invoice-matching
        // tolerance used in doMatch() (customer's transfer vs their bill(s)).
        $tol = PaymentProofStatusService::pairAmountTolerance();
        $windowDays = (int) config('payment_signals.pair_window_days', 3);
        $slopHours  = (int) config('payment_signals.pair_slop_hours', 24);
        $time = $this->paymentTime($signal);

        // DIRECTIONAL window (see config/payment_signals.php). A screenshot
        // (whatsapp) can be SENT well after the payment, so it looks BACK up to
        // windowDays for its bank mate and only a slop margin forward. A bank
        // confirmation (email/sms) fires AT the payment, so it looks FORWARD up
        // to windowDays for a late screenshot and only a slop margin back.
        // Removing the meaningless half of the old symmetric window also cuts
        // cross-pairing risk (an unrelated same-amount credit days on the wrong
        // side can no longer be welded on).
        if ($signal->source === PaymentSignal::SOURCE_WHATSAPP) {
            $from = $time->copy()->subDays($windowDays);
            $to   = $time->copy()->addHours($slopHours);
        } else {
            $from = $time->copy()->subHours($slopHours);
            $to   = $time->copy()->addDays($windowDays);
        }

        $base = PaymentSignal::query()
            ->whereIn('source', $oppositeSources)
            ->whereNull('paired_signal_id')
            ->where('id', '!=', $signal->id);

        // A shared transaction reference is decisive.
        if ($signal->extracted_ref) {
            $byRef = (clone $base)->where('extracted_ref', $signal->extracted_ref)->first();
            if ($byRef) {
                return $byRef;
            }
        }

        $myBank = $this->receivingBankKey($signal);

        return (clone $base)
            ->whereBetween('extracted_amount', [
                (float) $signal->extracted_amount - $tol,
                (float) $signal->extracted_amount + $tol,
            ])
            ->get()
            ->filter(fn ($s) => $this->paymentTime($s)->between($from, $to))
            // Same-receiving-bank gate: a screenshot tags OUR receiving account and
            // an email arrives FROM the bank that received the money — a real pair
            // hit the SAME NF account. Block ONLY on a confident mismatch (both
            // sides resolve to a receiving account and they differ), so an unread
            // bank on either side never suppresses a genuine pair. (Jul-2026: an
            // HBL-4403 screenshot must not corroborate with a Meezan credit alert.)
            ->filter(function ($s) use ($myBank) {
                $theirBank = $this->receivingBankKey($s);
                return !($myBank !== null && $theirBank !== null && $myBank !== $theirBank);
            })
            ->sortBy(fn ($s) => abs($this->paymentTime($s)->diffInSeconds($time)))
            ->first();
    }

    /**
     * Canonical "which of OUR bank accounts received this money" for a signal, as
     * an uppercased t_fin_online_receiving_accounts.short_code — or null when it
     * can't be determined. A screenshot tags our receiving account directly
     * (extracted_to_account_last4 / _short); an email credit alert arrives FROM the
     * bank that received it (extracted_sender_bank = the route short_code). Both
     * are normalised to the same chip vocabulary so the two sources compare
     * apples-to-apples (e.g. an email "MEEZAN" and a screenshot last-4 "4237" both
     * resolve to the chip "MBL-4237"). Returns null (→ never blocks a pair) rather
     * than guess when nothing resolves.
     */
    private function receivingBankKey(PaymentSignal $signal): ?string
    {
        $accounts = DB::table('t_fin_online_receiving_accounts')
            ->where('is_active', 1)
            ->get(['short_code', 'name', 'account_last4']);
        if ($accounts->isEmpty()) {
            return null;
        }

        // 1) Screenshot: exact receiving-account last-4 (deterministic).
        if ($signal->extracted_to_account_last4) {
            $hit = $accounts->firstWhere('account_last4', $signal->extracted_to_account_last4);
            if ($hit) {
                return mb_strtoupper($hit->short_code);
            }
        }

        // 2) A bank token — the screenshot's chip short_code, or the email's route
        //    short_code. Resolve to a chip by exact short_code or a name substring
        //    (bridges "MEEZAN" → the "MBL-4237" chip named "Meezan Bank Limited").
        $token = $signal->source === PaymentSignal::SOURCE_EMAIL
            ? $signal->extracted_sender_bank
            : $signal->extracted_to_account_short;
        if ($token !== null && trim((string) $token) !== '') {
            $t = mb_strtolower(trim((string) $token));
            $hit = $accounts->first(function ($a) use ($t) {
                return mb_strtolower((string) $a->short_code) === $t
                    || ($a->name && str_contains(mb_strtolower((string) $a->name), $t));
            });
            return $hit ? mb_strtoupper($hit->short_code) : mb_strtoupper($t);
        }

        return null;
    }

    /** When the customer actually sent the money (best available signal). */
    private function paymentTime(PaymentSignal $signal): Carbon
    {
        $t = $signal->extracted_txn_datetime
            ?? $signal->email_received_at
            ?? $signal->created_at
            ?? Carbon::now();
        return Carbon::parse($t);
    }

    /**
     * Copy the matched order/customer from whichever side has it onto the side
     * that lacks it, mirroring the source's matched/mismatch verdict.
     */
    private function propagateLink(PaymentSignal $a, PaymentSignal $b): void
    {
        [$src, $dst] = $a->matched_order_id ? [$a, $b] : [$b, $a];
        if (!$src->matched_order_id || $dst->matched_order_id) {
            return;
        }

        $dst->matched_order_id    = $src->matched_order_id;
        $dst->matched_customer_id = $dst->matched_customer_id ?: $src->matched_customer_id;

        if ($src->status === PaymentSignal::STATUS_MATCHED) {
            $dst->status = PaymentSignal::STATUS_MATCHED;
            $dst->match_confidence = max((float) $dst->match_confidence, 0.90);
            $dst->match_reason = match ($dst->source) {
                PaymentSignal::SOURCE_EMAIL    => 'email_corroborates_whatsapp',
                PaymentSignal::SOURCE_BANK_SMS => 'bank_sms_corroborates_whatsapp',
                default                        => 'whatsapp_corroborates_email',
            };
        } elseif ($src->status === PaymentSignal::STATUS_AMOUNT_MISMATCH
            && $dst->status !== PaymentSignal::STATUS_MATCHED) {
            $dst->status = PaymentSignal::STATUS_AMOUNT_MISMATCH;
            $dst->match_reason = 'corroborated_amount_mismatch';
        }

        $dst->save();

        // If the source is a combined (bulk) match, mirror its covered-invoice
        // links onto the corroborating signal so EVERY invoice in the group
        // shows both the screenshot and the email (→ "Verified" across them all).
        if ($src->status === PaymentSignal::STATUS_MATCHED) {
            $this->copyLinks($src, $dst);
        }
    }

    private function bindPair(PaymentSignal $a, PaymentSignal $b): void
    {
        $a->paired_signal_id = $b->id;
        $a->save();
        $b->paired_signal_id = $a->id;
        $b->save();
    }

    private function bumpAliasUsage(PaymentSignal $signal): void
    {
        try {
            $norm = CustomerBankAlias::normaliseName($signal->extracted_sender_name);
            CustomerBankAlias::query()
                ->where('customer_id', $signal->matched_customer_id)
                ->whereRaw('LOWER(TRIM(bank_account_name)) = ?', [$norm])
                ->update([
                    'use_count'    => DB::raw('use_count + 1'),
                    'last_used_at' => now(),
                ]);
        } catch (\Throwable $e) {
            // non-fatal
        }
    }
}
