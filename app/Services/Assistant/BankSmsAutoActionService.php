<?php

namespace App\Services\Assistant;

use App\Models\FIN\PaymentSignal;
use App\Services\Payments\Signals\PaymentSignalMatcher;
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
            $onlyIds = app(AssistantDraftService::class)->approvalsQueueOrderIds((int) $rule->entity_id);
            $this->matcher->match($signal, $onlyIds);
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
            $unique = $this->uniqueQueueOrderByAmount((float) $sms->amount);
            if ($unique) {
                $signal->matched_order_id    = $unique['order_id'];
                $signal->matched_customer_id = $unique['customer_id'];
                $signal->status              = PaymentSignal::STATUS_MATCHED;
                $signal->match_reason        = 'amount_unique_sms';
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
     * EXACTLY ONE approvals-queue order whose outstanding balance equals this
     * amount (within the invoice tolerance) — else null. Mirrors
     * AssistantWorkspaceController::suggestOrderByAmount (the inbox chip), so
     * what the approver sees attached and what Taimur is asked to confirm are
     * always the same order.
     */
    private function uniqueQueueOrderByAmount(float $amount): ?array
    {
        if ($amount <= 0) {
            return null;
        }
        $tol = \App\Services\Payments\Signals\PaymentProofStatusService::amountTolerance();

        $hits = DB::table('t_fin_ledger as l')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('l.transaction_type', 'invoice')
            ->whereIn('l.approval_status', ['pending', 'pending_l1', 'pending_l2'])
            ->whereRaw('ABS((o.total_price - COALESCE(o.total_paid,0)) - ?) <= ?', [$amount, $tol])
            ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01')
            ->distinct()
            ->limit(2)
            ->get(['o.id as order_id', 'o.order_number', 'o.customer_id', 'c.first_name', 'c.last_name']);

        if ($hits->count() !== 1) {
            return null;
        }
        $h = $hits->first();

        // If the order ALREADY has a screenshot proof, the proof flow is in
        // progress and PAIRING (with its same-bank gate) is the correct joiner
        // — an amount-only attach could weld a different bank's credit onto it
        // (e.g. a Meezan SMS onto an HBL-proofed order). Attach is only for
        // orders with NO proof yet (the no-screenshot case it was built for).
        $hasProof = PaymentSignal::query()
            ->where('matched_order_id', $h->order_id)
            ->where('source', PaymentSignal::SOURCE_WHATSAPP)
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->exists();
        if ($hasProof) {
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
        // Vendor / expense / account rules only pre-fill the card — never
        // auto-post. But RECOGNISING the SMS still counts: bump here (once per
        // ingested SMS) so the review panel's "recognised N×, last seen" is
        // truthful. Before this, only ignore/customer rules ever bumped and a
        // heavily-used vendor rule read "not seen yet" forever.
        if ($rule && in_array($rule->entity_type, ['vendor', 'expense', 'account'], true)) {
            $this->map->bump((int) $rule->id);
        }
        return null;
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
