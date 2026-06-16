<?php

namespace App\Services\Payments\Signals;

use App\Models\CRM\CustomerBankAlias;
use App\Models\FIN\PaymentSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Learns customer bank-account-name -> customer_id mappings. The matcher uses
 * these so a bank email alone can identify a customer even when no WhatsApp
 * screenshot was sent.
 *
 * Aliases are created/strengthened ONLY when a human approves a payment, which
 * gates every alias behind an explicit "yes this is correct" click.
 */
class CustomerBankAliasService
{
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
        if ($name === '') {
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
}
