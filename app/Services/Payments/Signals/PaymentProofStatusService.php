<?php

namespace App\Services\Payments\Signals;

use App\Models\FIN\PaymentSignal;
use Illuminate\Support\Collection;

/**
 * Read-only helper that turns the raw payment signals for an order into a
 * single, UI-friendly "payment proof status" used by the badges on Online
 * Approvals, Open Orders, Messages, and Daily Closing.
 *
 * States (highest precedence first):
 *   verified        — both a WhatsApp screenshot AND a bank email matched (green)
 *   amount_mismatch — proof received but amount doesn't fit an order (orange)
 *   proof_received  — customer WhatsApp screenshot matched (yellow)
 *   bank_confirmed  — bank email matched, no screenshot yet (blue)
 *   none            — nothing (gray / blank)
 */
class PaymentProofStatusService
{
    public const NONE           = 'none';
    public const PROOF_RECEIVED = 'proof_received';
    public const BANK_CONFIRMED = 'bank_confirmed';
    public const VERIFIED       = 'verified';
    public const AMOUNT_MISMATCH = 'amount_mismatch';

    /** Build the status payload for a single order id. */
    public function forOrder(int $orderId): array
    {
        // Once the order's online payment has been approved the badge is no
        // longer an action item — hide it until the next order.
        if (in_array($orderId, self::settledOrderIds([$orderId]), true)) {
            return $this->summarise(collect());
        }

        $signals = PaymentSignal::query()
            ->where('matched_order_id', $orderId)
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->orderByDesc('id')
            ->get();

        return $this->summarise($signals);
    }

    /**
     * Of the given order ids, returns the subset whose online payment has
     * already been approved (an approved online-mode ledger entry exists).
     *
     * The proof badge is a prompt to act; once the Online Approval is approved
     * the action is complete, so callers use this to drop the badge for that
     * order until the customer's next order produces a fresh signal.
     *
     * @param  array<int>  $orderIds
     * @return array<int>
     */
    public static function settledOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(
            static fn ($id) => (int) $id,
            $orderIds
        ))));

        if (empty($orderIds)) {
            return [];
        }

        return \App\Models\FIN\LedgerModel::query()
            ->where('mode', 'online')
            ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
            ->whereIn('order_id', $orderIds)
            ->pluck('order_id')
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Bulk variant: returns [orderId => statusPayload] for many orders in one
     * query. Use this on list pages to avoid N+1.
     *
     * @param  array<int>  $orderIds
     */
    public function forOrders(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $byOrder = PaymentSignal::query()
            ->whereIn('matched_order_id', $orderIds)
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->orderByDesc('id')
            ->get()
            ->groupBy('matched_order_id');

        $settled = array_flip(self::settledOrderIds($orderIds));

        $out = [];
        foreach ($orderIds as $id) {
            if (isset($settled[(int) $id])) {
                $out[$id] = $this->summarise(collect());
                continue;
            }
            $out[$id] = $this->summarise($byOrder->get($id) ?? collect());
        }
        return $out;
    }

    private function summarise(Collection $signals): array
    {
        $hasWa    = $signals->where('source', PaymentSignal::SOURCE_WHATSAPP)->isNotEmpty();
        $hasEmail = $signals->where('source', PaymentSignal::SOURCE_EMAIL)->isNotEmpty();
        $hasMismatch = $signals->where('status', PaymentSignal::STATUS_AMOUNT_MISMATCH)->isNotEmpty();
        $hasMatched  = $signals->where('status', PaymentSignal::STATUS_MATCHED)->isNotEmpty();

        if ($hasWa && $hasEmail && $hasMatched) {
            $status = self::VERIFIED;
        } elseif ($hasMismatch && !$hasMatched) {
            $status = self::AMOUNT_MISMATCH;
        } elseif ($hasWa) {
            $status = self::PROOF_RECEIVED;
        } elseif ($hasEmail) {
            $status = self::BANK_CONFIRMED;
        } else {
            $status = self::NONE;
        }

        return [
            'status'       => $status,
            'label'        => self::label($status),
            'color'        => self::color($status),
            'has_whatsapp' => $hasWa,
            'has_email'    => $hasEmail,
            'signal_count' => $signals->count(),
        ];
    }

    public static function label(string $status): string
    {
        return [
            self::VERIFIED        => 'Verified',
            self::AMOUNT_MISMATCH => 'Proof received — amount differs',
            self::PROOF_RECEIVED  => 'Proof received',
            self::BANK_CONFIRMED  => 'Bank confirmed',
            self::NONE            => 'No proof yet',
        ][$status] ?? 'No proof yet';
    }

    /** Returns a hex color for the status (kept neutral; theme-friendly). */
    public static function color(string $status): string
    {
        return [
            self::VERIFIED        => '#16A34A', // green
            self::AMOUNT_MISMATCH => '#EA580C', // orange
            self::PROOF_RECEIVED  => '#CA8A04', // yellow
            self::BANK_CONFIRMED  => '#2563EB', // blue
            self::NONE            => '#9CA3AF', // gray
        ][$status] ?? '#9CA3AF';
    }
}
