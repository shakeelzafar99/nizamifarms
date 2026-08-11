<?php

namespace App\Services\Payments\Signals;

use App\Models\CRM\CustomerBankAlias;
use App\Models\FIN\PaymentSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The system's memory of WHO a bank-account name belongs to.
 *
 * This is the single most valuable table in payment matching, because it is the
 * only thing that can know a payer whose name looks nothing like the customer —
 * and 16% of real payments are exactly that (a husband, a relative, an office
 * account). No name-comparison algorithm can derive those; they can only be
 * remembered.
 *
 * ── How it learns (owner ruling, Aug-2026) ─────────────────────────────────
 * Silently, from approvals, including bulk ones. The manager's job is to
 * approve invoices, not to answer questions about payer names — a system that
 * interrogates him on every batch has defeated its own purpose.
 *
 * ── Why that is safe: ONE-STRIKE UNLEARN ───────────────────────────────────
 * Learning quietly means a rushed approval can teach something wrong. The cap
 * is unlearn(): the moment a human corrects a tag — untags it, re-points it at
 * someone else, or a real proof displaces it — the alias that produced that tag
 * is DELETED. So a bad lesson costs exactly one correction, once, and can never
 * repeat; and because the correction itself teaches the right answer at full
 * confidence, the system converges instead of drifting.
 *
 * That trade replaced a stricter design (refuse to learn from anything but
 * verified evidence), which was rejected for a real reason: passive suggestions
 * nobody is prompted to confirm are never confirmed, so the memory would have
 * stayed empty and every payer would be re-solved forever.
 *
 * ⚠ HISTORY: until Aug-2026 this learned from EVERY signal on an approved
 * order, including pure amount guesses, with no unlearn to cap it. That is how
 * 15 wrong payer names accumulated (audited and deleted by
 * database/migrations/payment_signal_name_matching_aug10_2026.sql).
 */
class CustomerBankAliasService
{
    /** A human explicitly said "this payment is that customer's". Strongest. */
    public const VIA_PAYER_CONFIRM = 'payer_confirm';

    /**
     * Called from the approve action for a ledger row tied to an order.
     * Upserts an alias for every signal matched to that order which carries a
     * payer bank-account name.
     */
    public function learnFromApprovedOrder(int $orderId, ?int $customerId, ?int $approverUserId): int
    {
        if (!$customerId) {
            return 0;
        }

        try {
            $signals = PaymentSignal::query()
                ->where('matched_order_id', $orderId)
                ->whereNotNull('extracted_sender_name')
                ->get();

            $learned = 0;
            foreach ($signals as $signal) {
                if ($this->upsert(
                    $customerId,
                    $signal->extracted_sender_name,
                    $signal->extracted_sender_account_masked,
                    $signal->extracted_sender_bank,
                    CustomerBankAlias::VIA_APPROVER,
                    $approverUserId
                )) {
                    $learned++;
                }
            }
            return $learned;
        } catch (\Throwable $e) {
            Log::error('CustomerBankAliasService::learnFromApprovedOrder failed', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * A human named the payer outright (money-inbox chip, the "whose payment is
     * this?" picker, or the confirm button on the approval check). This is the
     * gold-standard lesson — recorded distinctly so it is never mistaken for
     * something the system merely inferred.
     */
    public function learnFromConfirmation(int $customerId, ?string $payerName, ?string $maskedAccount, ?int $userId): bool
    {
        return $this->upsert($customerId, $payerName, $maskedAccount, null, self::VIA_PAYER_CONFIRM, $userId);
    }

    /**
     * ⭐⭐ ONE-STRIKE UNLEARN — forget the payer→customer link that produced a
     * tag a human has just rejected.
     *
     * Called from every correction path: untagging a wrong match, re-pointing a
     * credit at a different customer, ignoring it as "not a customer payment",
     * and when real proof displaces a squatting guess. Without this, a single
     * careless approval would keep repeating itself forever, and the manager
     * would have to correct the SAME wrong name every time that person paid.
     *
     * Explicit human confirmations are NOT unlearned by a later correction on a
     * different order — the earlier "yes, this is them" was a direct statement,
     * not an inference, and correcting one payment does not retract it.
     */
    public function unlearn(?int $customerId, ?string $payerName): bool
    {
        $norm = CustomerBankAlias::normaliseName((string) $payerName);
        if (!$customerId || $norm === '') {
            return false;
        }

        try {
            $deleted = CustomerBankAlias::query()
                ->where('customer_id', $customerId)
                ->whereRaw('LOWER(TRIM(bank_account_name)) = ?', [$norm])
                ->where(function ($w) {
                    $w->whereNull('confirmed_via')
                      ->orWhere('confirmed_via', '!=', self::VIA_PAYER_CONFIRM);
                })
                ->delete();

            if ($deleted) {
                Log::info('Payer alias unlearned after a human correction', [
                    'customer_id' => $customerId,
                    'payer_name'  => $payerName,
                ]);
            }
            return $deleted > 0;
        } catch (\Throwable $e) {
            Log::warning('CustomerBankAliasService::unlearn failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Convenience: unlearn whatever a given signal would have taught. */
    public function unlearnFromSignal(?PaymentSignal $signal): bool
    {
        if (!$signal || !$signal->matched_customer_id || !$signal->extracted_sender_name) {
            return false;
        }
        return $this->unlearn((int) $signal->matched_customer_id, $signal->extracted_sender_name);
    }

    /** Insert a new alias or bump confirmation on an existing one. */
    public function upsert(
        int $customerId,
        ?string $bankAccountName,
        ?string $maskedAccount,
        ?string $senderBank,
        string $via,
        ?int $confirmedBy
    ): bool {
        $name = trim((string) $bankAccountName);
        if ($name === '' || !$this->isLearnableName($name)) {
            return false;
        }

        $norm = CustomerBankAlias::normaliseName($name);

        $existing = CustomerBankAlias::query()
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(TRIM(bank_account_name)) = ?', [$norm])
            ->when($senderBank, fn ($q) => $q->where('sender_bank', $senderBank))
            ->first();

        if ($existing) {
            $existing->bank_account_masked = $existing->bank_account_masked ?: $maskedAccount;
            // An explicit human confirmation UPGRADES a previously inferred
            // alias, so it stops being one-strike material.
            if ($via === self::VIA_PAYER_CONFIRM && $existing->confirmed_via !== self::VIA_PAYER_CONFIRM) {
                $existing->confirmed_via = self::VIA_PAYER_CONFIRM;
                $existing->confirmed_by  = $confirmedBy;
                $existing->confirmed_at  = now();
            }
            $existing->save();
            return false; // strengthened, not newly learned
        }

        CustomerBankAlias::create([
            'customer_id'         => $customerId,
            'bank_account_name'   => $name,
            'bank_account_masked' => $maskedAccount,
            'sender_bank'         => $senderBank,
            'confirmed_via'       => $via,
            'confirmed_by'        => $confirmedBy,
            'confirmed_at'        => now(),
            'use_count'           => 0,
        ]);
        return true;
    }

    /**
     * Is this string a person's name at all?
     *
     * Bank feeds carry plenty of text in the payer slot that names nobody —
     * "at the above address" (email-parser debris that had been recorded
     * against 125 different customers before this guard existed), product
     * labels like "CA PAYROLL ACCOUNT", and bare fragments. Learning those
     * poisons the table for everyone, because the next payment carrying the
     * same boilerplate then "resolves" to whoever was approved first.
     */
    private function isLearnableName(string $name): bool
    {
        $s = mb_strtolower(trim($name));
        if (mb_strlen($s) < 3) {
            return false;
        }
        foreach ((array) config('payment_signals.name_blacklist', []) as $bad) {
            if ($s === $bad || str_contains($s, (string) $bad)) {
                return false;
            }
        }
        // Needs at least one letter run — pure digits/punctuation is a reference.
        return (bool) preg_match('/[a-z]{3}/', $s);
    }
}
