<?php

namespace App\Services\Assistant;

use App\Models\FIN\PaymentSignal;
use App\Services\Payments\Signals\PaymentSignalMatcher;
use App\Services\Payments\Signals\PayerNameResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens to a bank SMS the moment it is ingested — WITHOUT a human tap.
 *
 * Owner-approved automation boundary (Jul-20):
 *   CREDITS
 *   1. Counterparty account MAPPED to a customer → create a bank_sms payment
 *      signal pre-linked to that customer and run the NORMAL proof matcher,
 *      scoped to the customer's Online-Approvals-queue orders. That reuses the
 *      full existing ladder — single order, bulk "paying all open invoices"
 *      sum-set, mismatch-flag — exactly as WhatsApp proofs behave. ("We already
 *      have this system in place in payment signals" — the owner's ruling.)
 *   2. Unmapped credit → the signal may ONLY corroborate a WhatsApp proof
 *      (pair by ref/amount/date/bank → order flips to Verified, like the
 *      bank email always did). It must NEVER blind-match an order by amount
 *      alone — that's the cross-pairing failure mode from Jul-2026 ("Rs 36").
 *      No proof YET → the signal is HELD unmatched (email-style), so the
 *      screenshot arriving minutes later still auto-verifies; the SMS itself
 *      waits in the money inbox for Taimur either way.
 *   DEBITS
 *   3. Counterparty mapped as IGNORE (remembered personal merchant) → the SMS
 *      is auto-ignored, visible under a collapsed "auto-ignored" row so nothing
 *      silently vanishes.
 *   4. Vendor/expense mappings never auto-post money — they only pre-fill the
 *      inbox card (drafts still require Taimur's Confirm; money-safety rule).
 */
class BankSmsAutoActionService
{
    public function __construct(
        private SmsCounterpartyMap $map,
        private PaymentSignalMatcher $matcher,
    ) {
    }

    /**
     * Run the no-tap pipeline for a freshly-ingested SMS row.
     * Returns a small summary array for the API response, or null (no action).
     */
    public function handle(object $sms): ?array
    {
        try {
            if (($sms->status ?? '') !== 'new' || !($sms->amount > 0)) {
                return null;
            }
            return match ($sms->direction) {
                'credit' => $this->handleCredit($sms),
                'debit'  => $this->handleDebit($sms),
                default  => null,
            };
        } catch (\Throwable $e) {
            // Auto actions are best-effort: a failure must never lose the SMS —
            // it simply stays in the inbox for the human path.
            Log::warning('[BankSmsAutoAction] ' . $e->getMessage(), ['sms_id' => $sms->id ?? null]);
            return null;
        }
    }

    private function handleCredit(object $sms): ?array
    {
        // Without a resolved receiving bank the SMS can't safely corroborate
        // anything (the same-bank pairing gate needs it).
        if (!$sms->receiving_account_id) {
            return null;
        }
        // Idempotence: a signal already represents this SMS (held from an
        // earlier pass, or via teach/restore re-runs) — never create a second.
        if ($sms->linked_signal_id) {
            return null;
        }

        $rule = $this->map->byAccount($sms->counterparty_account ?? null);

        if ($rule && $rule->entity_type === 'customer' && $rule->entity_id) {
            // SHOP customers never go through proofs/signals — their money is
            // recorded as REAL payments, and those post auto-approved with no
            // downstream approval gate. So the automation stops at a PRE-FILLED
            // card (amount + shop + the SMS's own bank, FIFO allocation shown):
            // Taimur's Confirm IS the approval and can never be skipped.
            $isShop = DB::table('t_crm_prod_customer')
                ->where('id', $rule->entity_id)->value('customer_type') === 'shop';
            if ($isShop) {
                return $this->raiseShopCard($sms, $rule);
            }

            $signal = $this->createSignal($sms, (int) $rule->entity_id);
            // A mapped account is a deliberate human teaching, so identity is
            // certain — but the invoice may not exist yet (it is created at
            // DELIVERY, often minutes after the customer pays). Fall through to
            // their still-open orders rather than dead-ending on an empty queue.
            $this->matchWithinCustomer($signal, (int) $rule->entity_id, null);
            $signal->refresh();

            if (in_array($signal->status, [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH], true)) {
                $this->linkSms($sms->id, $signal->id, 'mapped_customer');
                $this->map->bump((int) $rule->id);
                return [
                    'action'   => 'mapped_customer',
                    'customer' => $this->map->entityName($rule),
                    'order_number' => $this->orderNumber($signal->matched_order_id),
                    'verified' => (bool) $signal->paired_signal_id,
                ];
            }

            // Nothing in the approvals queue to attach to (or a duplicate ref) —
            // hold or drop below; the inbox still shows the customer suggestion.
            return $this->holdOrDrop($sms, $signal);
        }

        // Unmapped (or non-customer mapping): corroboration probe. The matcher's
        // bank-side path pairs ONLY with an existing screenshot proof that
        // already carries an order — it never blind-matches an order by amount.
        $signal = $this->createSignal($sms, null);
        $this->matcher->match($signal);
        $signal->refresh();

        if ($signal->paired_signal_id && $signal->matched_order_id) {
            $this->linkSms($sms->id, $signal->id, 'proof_pair');
            return [
                'action'       => 'proof_pair',
                'order_number' => $this->orderNumber($signal->matched_order_id),
                'verified'     => true,
            ];
        }

        // ⭐⭐ NAME ATTACH — ask WHO paid before falling back to "who owes this
        // exact figure". The bank tells us a payer name on most credits, and
        // until now nothing in the SMS path ever read it: identity was only
        // ever taken from a mapped account, so an unmapped-but-named payer went
        // straight to a blind amount guess. That is how a Rs 7,600 credit from
        // "HAFIZ NOUMAN SIDDIQUE" landed on a stranger's Rs 7,533 invoice while
        // Nouman's own Rs 7,400 order sat untouched (his figure was Rs 200 off,
        // so only the NAME could have found it).
        //
        // Name-first also PREVENTS wrong amount guesses, not just enables right
        // ones: on the Aug-2026 backlog it rescued three credits the amount-only
        // rule would have assigned to the wrong customer outright.
        //
        // The resolved customer's own orders are the whole candidate set, queue
        // first then open — so this can only ever move money toward the person
        // the bank named, never toward a stranger.
        if ($signal->status !== PaymentSignal::STATUS_DUPLICATE) {
            $named = $this->attachByPayerName($sms, $signal);
            if ($named) {
                return $named;
            }
        }

        // AMOUNT-UNIQUE ATTACH (owner ruling Jul-2026): no proof to pair and no
        // identity — but if EXACTLY ONE order in the whole approvals queue has
        // this outstanding balance, that's ~certain in practice. Attach the
        // bank credit to it so the approver SEES it (blue "Bank confirmed",
        // never green Verified) with an explicit "matched by amount only —
        // confirm the payer" caption; the SMS stays in the money inbox with
        // its one-tap chip, and Taimur's confirm pairs proof↔SMS → Verified.
        // Zero or several amount-matches → nothing (ambiguity = the Rs-36
        // lesson); the SMS just holds as before.
        if ($signal->status !== PaymentSignal::STATUS_DUPLICATE) {
            $unique = $this->uniqueQueueOrderByAmount((float) $sms->amount, $sms->sms_at ?? null);
            if ($unique) {
                $signal->matched_order_id    = $unique['order_id'];
                $signal->matched_customer_id = $unique['customer_id'];
                $signal->status              = PaymentSignal::STATUS_MATCHED;
                $signal->match_reason        = PaymentSignal::REASON_AMOUNT_UNIQUE;
                $signal->match_confidence    = 0.50;
                $signal->save();

                // Keep the SMS OPEN in the inbox (status 'new') — the chip is
                // Taimur's confirm; only remember which signal represents it.
                DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                    'linked_signal_id' => $signal->id,
                    'updated_at'       => now(),
                ]);

                return [
                    'action'       => 'amount_unique_attach',
                    'order_number' => $unique['order_number'],
                    'customer'     => $unique['customer_name'],
                    'verified'     => false,
                ];
            }
        }

        return $this->holdOrDrop($sms, $signal);
    }

    /**
     * Attach a credit to a customer someone/something else identified (today:
     * the AI payer-name arbiter, during a resweep). Same order-picking as every
     * other path — queue first, then their open orders — and the same guess
     * semantics, so it stays retractable and never reads as verified.
     *
     * @return array|null  API-shaped summary, or null if nothing fitted.
     */
    public function attachResolvedCustomer(object $sms, int $customerId, string $guessReason): ?array
    {
        $signal = $sms->linked_signal_id ? PaymentSignal::find($sms->linked_signal_id) : null;
        if ($signal && ($signal->paired_signal_id || $signal->matched_order_id)) {
            return null; // already settled — don't touch it
        }
        if (!$signal) {
            $signal = $this->createSignal($sms, $customerId);
        }

        if (!$this->matchWithinCustomer($signal, $customerId, $guessReason)) {
            // Leave the fresh signal behind as the held record of this credit,
            // exactly as the normal ladder would.
            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                'linked_signal_id' => $signal->id,
                'updated_at'       => now(),
            ]);
            return null;
        }

        $signal->refresh();
        DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
            'linked_signal_id' => $signal->id,
            'updated_at'       => now(),
        ]);

        return [
            'action'       => 'ai_name_attach',
            'order_number' => $this->orderNumber($signal->matched_order_id),
            'verified'     => (bool) $signal->paired_signal_id,
        ];
    }

    /**
     * Resolve the payer's NAME to a customer and try to attach the credit to
     * one of THEIR orders. Returns the API summary on success, null to fall
     * through to the amount rules.
     *
     * ⚠⚠ A name hit alone is not a match. If the resolved customer has nothing
     * outstanding, we fall through instead of suppressing the amount rules —
     * during design, "A.CHAUDHRY" resolved to a customer literally named
     * "Mrs Chaudhry" who owed nothing, and suppressing on the bare name would
     * have killed the CORRECT amount match to Shaista Jahangir (whose learned
     * alias "Awais Chaudhry" is the real explanation). Identity must produce an
     * order to earn the right to override.
     */
    private function attachByPayerName(object $sms, PaymentSignal $signal): ?array
    {
        $resolved = app(PayerNameResolver::class)->resolve(
            $sms->counterparty ?? null,
            (float) $sms->amount,
            $sms->sms_at ?: null
        );
        if (!$resolved) {
            return null;
        }

        if (!$this->matchWithinCustomer($signal, $resolved['customer_id'], PaymentSignal::REASON_NAME_AMOUNT)) {
            return null; // named, but they owe nothing that fits — not a match
        }

        $signal->refresh();
        DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
            'linked_signal_id' => $signal->id,
            'updated_at'       => now(),
        ]);

        return [
            'action'       => 'name_attach',
            'customer'     => $resolved['customer_name'],
            'order_number' => $this->orderNumber($signal->matched_order_id),
            'verified'     => (bool) $signal->paired_signal_id,
        ];
    }

    /**
     * Attach a credit to the orders of ONE customer — every order of theirs
     * that still owes money, queued for approval or not, in a SINGLE pass.
     *
     * Including the un-queued ones is the pay-before-delivery fix: invoices
     * enter the approvals queue at DELIVERY — a mean of 21 hours after the
     * order is placed on this data — so a customer paying at or before
     * delivery routinely beats their own invoice into the queue. Stopping at
     * the queue is what left those credits homeless.
     *
     * ONE pass, not queue-then-open, on purpose. The matcher's ladder ends
     * with "nothing fits — attach to the newest order and flag the
     * difference", so a queue-scoped first pass would CONSUME the credit
     * there and the wider pass would never run: a customer with a queued
     * Rs 5,000 invoice and an open Rs 7,400 order would get a Rs 7,400
     * credit mismatch-flagged onto the 5,000 invoice while its exact fit sat
     * one scope away. A single pass lets the ladder do its own prioritising —
     * exact fit first, then a COMBINED set (one transfer settling several
     * orders, which may mix queued and unqueued ones), and the flagged
     * fallback only when every real fit has been ruled out.
     *
     * @param  string|null $guessReason  null when identity is established (a
     *   mapped account); a GUESS_REASON when it was inferred from the name.
     * @return bool  true if the signal now points at an order.
     */
    private function matchWithinCustomer(PaymentSignal $signal, int $customerId, ?string $guessReason): bool
    {
        $signal->matched_customer_id = $customerId;
        $signal->matched_order_id    = null;
        $signal->match_reason        = null;
        $signal->match_confidence    = null;
        $signal->status              = PaymentSignal::STATUS_NEW;
        $signal->save();

        $this->matcher->match($signal, null, $guessReason);
        $signal->refresh();

        if ($signal->matched_order_id) {
            return true;
        }

        // Nothing fitted. Tidy the signal so downstream steps read it
        // honestly — EXCEPT a duplicate verdict, which must survive: masking
        // it would let the amount-unique step attach a credit that is already
        // recorded elsewhere.
        if ($signal->status !== PaymentSignal::STATUS_DUPLICATE) {
            $signal->status           = PaymentSignal::STATUS_UNMATCHED;
            $signal->match_reason     = 'bank_credit_unidentified';
            $signal->match_confidence = null;
            // An identity that was itself a guess goes too; a mapped account's
            // identity is a fact and stays.
            if ($guessReason !== null) {
                $signal->matched_customer_id = null;
            }
            $signal->save();
        }

        return false;
    }

    /**
     * EXACTLY ONE approvals-queue order whose outstanding balance equals this
     * amount (within the invoice tolerance) — else null. Mirrors
     * AssistantWorkspaceController::suggestOrderByAmount (the inbox chip), so
     * what the approver sees attached and what Taimur is asked to confirm are
     * always the same order.
     */
    private function uniqueQueueOrderByAmount(float $amount, $paidAt = null): ?array
    {
        if ($amount <= 0) {
            return null;
        }
        $tol = \App\Services\Payments\Signals\PaymentProofStatusService::amountTolerance();

        // ⭐ The order must have EXISTED when the money was sent. This never
        // mattered while the guess was only ever made in the same second the
        // SMS arrived; the resweep now revisits held credits days later, and
        // without a bound a week-old credit could drift onto an invoice raised
        // long after it. The forward edge is a full day because genuine
        // pre-payment happens — see PaymentSignalMatcher::guessOrderDateBounds().
        [$from, $to] = PaymentSignalMatcher::guessOrderDateBounds($paidAt);

        $hits = DB::table('t_fin_ledger as l')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('l.transaction_type', 'invoice')
            ->whereIn('l.approval_status', ['pending', 'pending_l1', 'pending_l2'])
            ->whereRaw('ABS((o.total_price - COALESCE(o.total_paid,0)) - ?) <= ?', [$amount, $tol])
            ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01')
            ->whereBetween('o.order_date', [$from, $to])
            ->distinct()
            ->limit(2)
            ->get(['o.id as order_id', 'o.order_number', 'o.customer_id', 'c.first_name', 'c.last_name']);

        if ($hits->count() !== 1) {
            return null;
        }
        $h = $hits->first();

        // ⭐⭐ NO STACKING — an order that already carries ANY payment signal is
        // spoken for. This used to look only for WhatsApp screenshots, on the
        // reasoning that a screenshot means "pairing will join these properly".
        // But it left the door open to the worse case: a SECOND bank guess
        // piling onto the same invoice. Aug-2026 found SH-20443 wearing both a
        // Rs 7,500 and a Rs 7,600 credit — one invoice cannot be paid twice by
        // two strangers, and neither approver could tell which was real.
        $occupied = PaymentSignal::query()
            ->where('matched_order_id', $h->order_id)
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->exists();
        if ($occupied) {
            return null;
        }

        return [
            'order_id'      => (int) $h->order_id,
            'order_number'  => $h->order_number,
            'customer_id'   => (int) $h->customer_id,
            'customer_name' => trim(($h->first_name ?? '') . ' ' . ($h->last_name ?? '')),
        ];
    }

    /**
     * No pair and no order — the COMMON timeline is SMS FIRST (the bank alert
     * beats the customer's screenshot by minutes), so mirror the email design:
     * HOLD the unmatched bank signal ("bank_credit_unidentified") and let the
     * WhatsApp matcher pair it when the screenshot lands → auto-Verified with
     * no tap, whichever side arrives first. Held signals attach to NO order, so
     * the never-blind-match rule is intact. Only a duplicate-ref probe is
     * dropped (its transaction is already represented by another signal).
     */
    private function holdOrDrop(object $sms, PaymentSignal $signal): ?array
    {
        if ($signal->status === PaymentSignal::STATUS_DUPLICATE) {
            $this->withdraw($signal);
            return null;
        }
        // Remember the held signal on the SMS row (status stays 'new' — Taimur
        // can still act manually; the inbox sweep closes it if a later proof
        // pairs the held signal).
        DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
            'linked_signal_id' => $signal->id,
            'updated_at'       => now(),
        ]);
        return null;
    }

    /**
     * A mapped SHOP account paid — raise the ready-to-confirm shop-payment card
     * in the assistant chat (and the pending-cards inbox), pre-filled from the
     * SMS. If the draft refuses (no open invoices / amount over outstanding),
     * the SMS simply stays in the inbox with the shop suggestion — Taimur
     * decides. No signal is ever created for a known shop account: shop money
     * must never corroborate the proof flow.
     */
    private function raiseShopCard(object $sms, object $rule): ?array
    {
        $drafts = app(AssistantDraftService::class);
        $user = \App\Models\User::find($sms->user_id);
        if (!$user) {
            return null;
        }

        $result = $drafts->draftShopPayment([
            'customer_id'          => (int) $rule->entity_id,
            'amount'               => (float) $sms->amount,
            'reference'            => $sms->reference,
            'receiving_account_id' => (int) $sms->receiving_account_id,
            '_amount_verified'     => true, // amount comes from the bank SMS itself
        ], $user);

        if (!empty($result['error']) || empty($result['draft_id'])) {
            return null; // stays in the inbox; the suggestion chip still shows
        }

        DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
            'status'          => 'recorded',
            'linked_draft_id' => (int) $result['draft_id'],
            'auto_reason'     => 'mapped_shop',
            'updated_at'      => now(),
        ]);
        $this->map->bump((int) $rule->id);

        $drafts->postCardToChat($user, (int) $result['draft_id'],
            (string) ($result['summary'] ?? 'Shop payment'),
            '🏪 ' . $this->map->entityName($rule) . ' paid (bank SMS) —');

        return [
            'action' => 'mapped_shop_card',
            'shop'   => $this->map->entityName($rule),
        ];
    }

    private function handleDebit(object $sms): ?array
    {
        $rule = $this->map->byAccount($sms->counterparty_account ?? null);
        if ($rule && $rule->entity_type === 'ignore') {
            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                'status'      => 'ignored',
                'auto_reason' => 'remembered_merchant',
                'updated_at'  => now(),
            ]);
            $this->map->bump((int) $rule->id);
            return ['action' => 'auto_ignored', 'label' => $this->map->entityName($rule)];
        }
        // Vendor / expense / account rules never auto-POST. But RECOGNISING the
        // SMS counts: bump here (once per ingested SMS) so the review panel's
        // "recognised N×, last seen" is truthful. Before this, only
        // ignore/customer rules ever bumped and a heavily-used vendor rule read
        // "not seen yet" forever.
        if ($rule && in_array($rule->entity_type, ['vendor', 'expense', 'account'], true)) {
            $this->map->bump((int) $rule->id);
            return $this->raiseDebitCard($sms, $rule);
        }
        return null;
    }

    /**
     * A debit from a TAUGHT account raises its confirmation card immediately.
     *
     * ⭐ WHY: the money-safety rule is unchanged — nothing here posts anything,
     * and Taimur's Confirm is still the only thing that moves money. What
     * changes is that the card is already waiting for him instead of needing a
     * tap in the money box to create it. Recognised rows were sitting for days
     * because "one tap" was really two taps on two screens. `raiseShopCard()`
     * has done exactly this for mapped SHOP credits since Jul-2026; this is the
     * same move for the money-out side.
     *
     * Failure is always silent and safe: no card simply means the SMS stays in
     * the money box with its existing buttons, i.e. today's behaviour.
     */
    private function raiseDebitCard(object $sms, object $rule): ?array
    {
        try {
            // Same preconditions the manual inbox path checks before drafting.
            if (!$sms->receiving_account_id || !($sms->amount > 0)) {
                return null;
            }

            // ⚠⚠ ALREADY ON THE BOOKS → NO CARD. Taimur records first and the
            // SMS arrives later — measured on prod data: one T.ALI alert landed
            // 42 HOURS after its payment was entered, and the NF Messages
            // deep-scan posts old SMS in BATCHES, so several already-recorded
            // transfers can arrive together. Raising cards for those (which
            // would CLUB, and a clubbed card carries no duplicate warning) is a
            // straight road to recording a night twice. If an unclaimed ledger
            // row already matches this debit, the answer is the money-inbox
            // sweep — it closes the SMS as "already recorded" on the next box
            // load, with Restore if it is ever wrong. Skipping here costs
            // nothing: the SMS stays in the box exactly as today.
            if ($this->coveredByExistingLedger($sms, $rule)) {
                return null;
            }
            $user = \App\Models\User::find($sms->user_id);
            if (!$user) {
                return null;
            }
            $online = \App\Models\FIN\AccountModel::getByCode('ONLINE');
            if (!$online) {
                return null;
            }

            $drafts = app(AssistantDraftService::class);
            $label  = $this->map->entityName($rule);
            $common = [
                'amount'                    => (float) $sms->amount,
                'payment_source_account_id' => $online->id,
                'receiving_account_id'      => (int) $sms->receiving_account_id,
                // Never let the card adopt whatever image is latest in chat, and
                // (since Aug-2026) mark it as SMS-raised so it gets the long TTL.
                '_from_sms'                 => true,
            ];

            $result = match ($rule->entity_type) {
                'vendor'  => $this->raiseVendorCard($sms, $rule, $common, $user, $drafts),
                'expense' => $drafts->draftExpense($common + [
                    'expense_category' => $label,
                    'title'            => $label,
                    'description'      => $this->smsNote($sms),
                ], $user),
                'account' => $drafts->draftAccountTransfer([
                    'from_account_id'      => $online->id,
                    'to_account_id'        => (int) $rule->entity_id,
                    'amount'               => (float) $sms->amount,
                    'receiving_account_id' => (int) $sms->receiving_account_id,
                    'mode'                 => 'online',
                    'description'          => $this->smsNote($sms),
                    '_from_sms'            => true,
                ], $user),
                default   => null,
            };

            if (!$result || !empty($result['error']) || empty($result['draft_id'])) {
                return null; // stays in the money box — the human path is intact
            }

            $draftId = (int) $result['draft_id'];

            // Claim THIS sms for the card. A clubbed card also inherits the
            // previous card's SMS rows — store() re-points them when it
            // supersedes the old draft, so they are already correct here.
            DB::table('t_ai_bank_sms')->where('id', $sms->id)->update([
                'status'          => 'recorded',
                'linked_draft_id' => $draftId,
                'auto_reason'     => 'auto_card',
                'updated_at'      => now(),
            ]);

            $drafts->postCardToChat($user, $draftId,
                (string) ($result['summary'] ?? 'Ready to record'),
                '📩 From your bank SMS —');

            return ['action' => 'auto_card', 'type' => $rule->entity_type, 'label' => $label];
        } catch (\Throwable $e) {
            Log::warning('[raiseDebitCard] ' . $e->getMessage(), ['sms_id' => $sms->id ?? null]);
            return null;
        }
    }

    /**
     * The vendor card — CLUBBED when this vendor already has an unconfirmed one.
     *
     * A vendor paid twice in one evening should be ONE decision, not two cards
     * racing each other: RAAST caps routinely split a single payment into
     * several transfers (Aug-10: four Rs 150,000 sends to one vendor inside two
     * minutes). The new card supersedes the old via `replaces_draft_id`, and
     * `store()` re-points the old card's SMS rows onto it, so every transfer in
     * the club stays linked to the one card that will record them all.
     *
     * ⚠ If clubbing is refused — most often because the TOTAL now exceeds what
     * we owe the vendor — we do NOT fall back to raising a second single card.
     * Two live cards for one vendor is how the same night gets recorded twice.
     * The existing card stands and the new SMS waits in the money box.
     */
    private function raiseVendorCard(object $sms, object $rule, array $common, $user, AssistantDraftService $drafts): ?array
    {
        $vendorId = (int) $rule->entity_id;
        $pending  = $this->pendingVendorCard($user->id, $vendorId);

        $mine = [
            'sms_id'    => (int) $sms->id,
            'amount'    => (float) $sms->amount,
            'reference' => $sms->reference,
            'time'      => $sms->sms_at ? substr((string) $sms->sms_at, 11, 5) : null,
            'date'      => $sms->sms_at ? substr((string) $sms->sms_at, 0, 10) : null,
        ];

        if (!$pending) {
            return $drafts->draftVendorPayment($common + [
                'vendor_id'        => $vendorId,
                'description'      => $this->smsNote($sms),
                'transaction_date' => $mine['date'],
                '_transfers'       => [$mine], // a single entry is not a batch
            ], $user);
        }

        $payload  = json_decode($pending->payload_json, true) ?: [];

        // ⚠⚠ NEVER CLUB ACROSS BANKS. A card carries ONE receiving bank and the
        // batch commit stamps every ledger row with it — so folding a transfer
        // from a DIFFERENT bank into the club would book that row under the
        // wrong bank and quietly skew both banks' balances. And this is not a
        // corner case: vendors were paid through two banks on the same day nine
        // times since June (Meezan for one transfer, Alfalah for the next).
        // The other-bank SMS simply waits in the money box — recording it there
        // by hand is exactly right, because it IS a separate movement of a
        // different bank's money. (No second auto-card either: two live cards
        // for one vendor is how a night gets recorded twice.)
        if ((int) ($payload['receiving_account_id'] ?? 0) !== (int) $sms->receiving_account_id) {
            return null;
        }
        $existing = $payload['_transfers'] ?? null;

        // An older single-payment card carries no transfer list — rebuild its
        // one entry from the SMS that produced it, so it joins the club rather
        // than being silently dropped from the total.
        if (!is_array($existing) || !$existing) {
            $existing = [];
            foreach (DB::table('t_ai_bank_sms')->where('linked_draft_id', $pending->id)->get() as $prior) {
                $existing[] = [
                    'sms_id'    => (int) $prior->id,
                    'amount'    => (float) $prior->amount,
                    'reference' => $prior->reference,
                    'time'      => $prior->sms_at ? substr((string) $prior->sms_at, 11, 5) : null,
                    'date'      => $prior->sms_at ? substr((string) $prior->sms_at, 0, 10) : null,
                ];
            }
            if (!$existing) {
                return null; // can't reconstruct it — leave both alone
            }
        }

        return $drafts->draftVendorPayment($common + [
            'vendor_id'          => $vendorId,
            'description'        => $this->smsNote($sms),
            'transaction_date'   => $existing[0]['date'] ?? $mine['date'],
            '_transfers'         => array_merge($existing, [$mine]),
            'replaces_draft_id'  => (int) $pending->id,
        ], $user);
    }

    /**
     * Do the ledger rows already on the books COVER this debit?
     *
     * ⭐⭐ COUNT-AWARE, exactly like the sweep's pairing — because split
     * transfers make same-signature debits routine (four Rs 150,000 to one
     * vendor in one night). Two hand-recorded rows must excuse exactly TWO of
     * those transfers, not all four: R matching unclaimed ledger rows against S
     * matching unresolved SMS — covered only while R >= S. Arriving one at a
     * time, that plays out naturally: with 2 rows on the books, arrivals 1 and
     * 2 are covered (skip — the sweep files them as already recorded), arrivals
     * 3 and 4 exceed the rows and get their card.
     *
     * Bounds mirror the sweep (bank + amount ±1 + date ±1, live rows only),
     * scoped by what the rule KNOWS: vendor rules to that vendor's account,
     * account rules to that destination, expense rules to expense rows.
     * "Unclaimed" = no SMS owns the row — one claimed by a different message
     * is a different transfer and must not excuse this one.
     */
    private function coveredByExistingLedger(object $sms, object $rule): bool
    {
        $day = substr((string) $sms->sms_at, 0, 10);

        $rows = DB::table('t_fin_ledger as l')
            ->where('l.mode', 'online')
            ->whereNotIn('l.approval_status', ['rejected', 'reversed'])
            ->where('l.receiving_account_id', $sms->receiving_account_id)
            ->whereRaw('ABS(l.amount - ?) <= 1', [$sms->amount])
            ->whereRaw('ABS(DATEDIFF(l.transaction_date, ?)) <= 1', [$day])
            ->whereNotExists(function ($s) {
                $s->select(DB::raw(1))->from('t_ai_bank_sms as b')
                  ->whereColumn('b.linked_ledger_id', 'l.id');
            });

        if ($rule->entity_type === 'vendor') {
            $accountId = DB::table('t_fin_vendors')->where('id', $rule->entity_id)->value('account_id');
            if (!$accountId) {
                return false; // cannot scope — never suppress on a guess
            }
            $rows->where('l.transaction_type', 'vendor_payment')->where('l.to_account_id', $accountId);
        } elseif ($rule->entity_type === 'account') {
            $rows->where('l.transaction_type', 'transfer')->where('l.to_account_id', (int) $rule->entity_id);
        } else { // expense
            $rows->where('l.transaction_type', 'expense');
        }

        $r = $rows->count();
        if ($r === 0) {
            return false;
        }

        // Unresolved same-signature SMS, THIS one included (it is 'new' during
        // ingest). Keyed on the counterparty account when the SMS carries one —
        // the same identity the rule fired on — else bank+amount+day alone.
        $s = DB::table('t_ai_bank_sms')
            ->where('user_id', $sms->user_id)
            ->where('status', 'new')
            ->whereIn('direction', ['debit', 'unknown'])
            ->where('receiving_account_id', $sms->receiving_account_id)
            ->whereRaw('ABS(COALESCE(amount,0) - ?) <= 1', [$sms->amount])
            ->whereRaw('ABS(DATEDIFF(DATE(sms_at), ?)) <= 1', [$day])
            ->when(!empty($sms->counterparty_account),
                fn($q) => $q->where('counterparty_account', $sms->counterparty_account))
            ->count();

        return $r >= max(1, $s);
    }

    /** This user's live, unconfirmed vendor card for that vendor, or null. */
    private function pendingVendorCard(int $userId, int $vendorId): ?object
    {
        $rows = DB::table('t_ai_drafts')
            ->where('user_id', $userId)
            ->where('type', 'vendor_payment')
            ->where('status', 'pending')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')
            ->get(['id', 'payload_json']);

        foreach ($rows as $row) {
            $payload = json_decode($row->payload_json, true) ?: [];
            // Only ever club cards that came from bank SMS: a payment Taimur
            // typed in chat is his own deliberate entry and must not be
            // rewritten underneath him by an arriving message.
            if ((int) ($payload['vendor_id'] ?? 0) === $vendorId && !empty($payload['_from_sms'])) {
                return $row;
            }
        }
        return null;
    }

    /** The same one-line provenance note the manual inbox path writes. */
    private function smsNote(object $sms): string
    {
        $bits = ['From bank SMS'];
        if ($sms->counterparty) $bits[] = $sms->counterparty;
        if ($sms->reference)    $bits[] = 'ref ' . $sms->reference;
        return mb_substr(implode(' · ', $bits), 0, 490);
    }

    /** A bank-side signal describing this SMS (the bank's own word). */
    private function createSignal(object $sms, ?int $customerId): PaymentSignal
    {
        $chip = DB::table('t_fin_online_receiving_accounts')
            ->where('id', $sms->receiving_account_id)
            ->first(['short_code', 'account_last4']);

        return PaymentSignal::create([
            'source'                          => PaymentSignal::SOURCE_BANK_SMS,
            'extracted_amount'                => $sms->amount,
            'extracted_ref'                   => $sms->reference,
            'extracted_sender_name'           => $sms->counterparty,
            'extracted_sender_account_masked' => $sms->counterparty_account,
            'extracted_to_account_short'      => $chip->short_code ?? null,
            'extracted_to_account_last4'      => $chip->account_last4 ?? null,
            'extracted_txn_datetime'          => $sms->sms_at ?: now(),
            'extraction_raw_text'             => $sms->raw_body,
            'extractor_version'               => 'bank_sms_auto@v1',
            'matched_customer_id'             => $customerId,
            'status'                          => PaymentSignal::STATUS_NEW,
        ]);
    }

    private function linkSms(int $smsId, int $signalId, string $reason): void
    {
        DB::table('t_ai_bank_sms')->where('id', $smsId)->update([
            'status'           => 'matched',
            'linked_signal_id' => $signalId,
            'auto_reason'      => $reason,
            'updated_at'       => now(),
        ]);
    }

    /** Remove a probe signal that found nothing — leave no stray rows behind. */
    private function withdraw(PaymentSignal $signal): void
    {
        DB::table('t_fin_payment_signal_order')->where('signal_id', $signal->id)->delete();
        $signal->delete();
    }

    private function orderNumber(?int $orderId): ?string
    {
        return $orderId
            ? DB::table('t_crm_prod_order')->where('id', $orderId)->value('order_number')
            : null;
    }
}
