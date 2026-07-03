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

    /**
     * Runtime amount tolerance in PKR (Jun-2026). Admins can change it from the
     * Operations page; it's stored in t_fin_config and falls back to the config
     * default. Clamped to a sane range so a typo can't make matching absurdly
     * loose. Used by the matcher (single + combined) and the proof panel.
     */
    public static function amountTolerance(): float
    {
        $val = (float) \App\Models\FIN\ConfigModel::get(
            'payment_signals_amount_tolerance',
            config('payment_signals.amount_tolerance', 10)
        );
        return max(0.0, min(1000.0, $val));
    }

    /** Build the status payload for a single order id. */
    public function forOrder(int $orderId): array
    {
        // Delegate to the bulk path so combined-payment links are handled in one
        // place (an order can carry a proof either directly or via a bulk link).
        return $this->forOrders([$orderId])[$orderId] ?? $this->summarise(collect());
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

        $statuses = [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH];

        // 1) Signals tied DIRECTLY to these orders (the normal single-invoice case).
        $direct = PaymentSignal::query()
            ->whereIn('matched_order_id', $orderIds)
            ->whereIn('status', $statuses)
            ->orderByDesc('id')
            ->get();

        // 2) Signals tied to these orders via a COMBINED (bulk) payment link.
        $links = \DB::table('t_fin_payment_signal_order')
            ->whereIn('order_id', $orderIds)
            ->get(['signal_id', 'order_id']);

        $linkSignalIds = $links->pluck('signal_id')->unique()->values();
        $linkedSignals = $linkSignalIds->isNotEmpty()
            ? PaymentSignal::query()
                ->whereIn('id', $linkSignalIds)
                ->whereIn('status', $statuses)
                ->get()
                ->keyBy('id')
            : collect();

        // How many invoices each linked signal covers → drives the "(1 of N)" badge.
        $groupCounts = $linkSignalIds->isNotEmpty()
            ? \DB::table('t_fin_payment_signal_order')
                ->whereIn('signal_id', $linkSignalIds)
                ->selectRaw('signal_id, COUNT(*) AS c')
                ->groupBy('signal_id')
                ->pluck('c', 'signal_id')
            : collect();

        // Build per-order signal collections (direct + linked), de-duped by id.
        $byOrder = [];
        foreach ($direct as $s) {
            $byOrder[(int) $s->matched_order_id][$s->id] = $s;
        }
        foreach ($links as $l) {
            $sig = $linkedSignals->get($l->signal_id);
            if ($sig) {
                $byOrder[(int) $l->order_id][$sig->id] = $sig;
            }
        }

        $settled = array_flip(self::settledOrderIds($orderIds));

        // ── "Discount needed" hint: a MATCHED proof that's still SHORT of the
        // covered invoice total by a small (within-tolerance) amount, with no
        // balancing discount applied yet. Computed in bulk (a few queries), so the
        // list can show a marker without opening each proof.
        $discountNeededBySignal = []; // signal_id => short amount
        $matchedSignals = collect($byOrder)
            ->flatMap(fn ($m) => array_values($m))
            ->filter(fn ($s) => $s->status === PaymentSignal::STATUS_MATCHED && $s->extracted_amount !== null)
            ->unique('id')
            ->values();

        if ($matchedSignals->isNotEmpty()) {
            $msIds = $matchedSignals->pluck('id')->all();
            $coverRows = \DB::table('t_fin_payment_signal_order')->whereIn('signal_id', $msIds)->get(['signal_id', 'order_id']);
            $coveredBySignal = [];
            foreach ($coverRows as $r) { $coveredBySignal[(int) $r->signal_id][] = (int) $r->order_id; }
            foreach ($matchedSignals as $s) {
                if (empty($coveredBySignal[(int) $s->id])) {
                    $coveredBySignal[(int) $s->id] = [(int) $s->matched_order_id]; // single match
                }
            }
            $allCovered = collect($coveredBySignal)->flatten()->unique()->values()->all();
            $balById = \DB::table('t_crm_prod_order')->whereIn('id', $allCovered)
                ->get(['id', 'total_price', 'total_paid'])
                ->mapWithKeys(fn ($o) => [(int) $o->id => round((float) $o->total_price - (float) ($o->total_paid ?? 0), 2)]);
            $balancedOrders = \DB::table('t_crm_order_discounts')
                ->whereIn('order_id', $allCovered)->where('coupon_code', 'AUTO_BALANCE')
                ->pluck('order_id')->map(fn ($v) => (int) $v)->flip();

            $tolerance = self::amountTolerance();
            foreach ($matchedSignals as $s) {
                $covered = $coveredBySignal[(int) $s->id] ?? [];
                $groupTotal = 0.0; $alreadyBalanced = false;
                foreach ($covered as $oid) {
                    $groupTotal += (float) ($balById[$oid] ?? 0);
                    if (isset($balancedOrders[$oid])) { $alreadyBalanced = true; }
                }
                $short = round($groupTotal - (float) $s->extracted_amount, 2);
                if (!$alreadyBalanced && $short > 0.5 && $short <= $tolerance) {
                    $discountNeededBySignal[(int) $s->id] = $short;
                }
            }
        }

        // ── Detected receiving bank. We map at READ time (not extraction) so a
        // bank whose last-4 was configured AFTER a proof was read still resolves
        // without re-scanning. Match priority: (1) the proof's receiving-account
        // last-4 against a CURRENT active chip's account_last4 (only when exactly
        // one active bank carries that last-4 — never guess on a collision);
        // (2) the stored short_code, for backward-compat / wallet chips.
        $allSigs = collect($byOrder)->flatMap(fn ($m) => array_values($m));
        $shortCodes = $allSigs->pluck('extracted_to_account_short')->filter()->unique()->values();
        $last4s = $allSigs->pluck('extracted_to_account_last4')->filter()->unique()->values();

        $banks = ($shortCodes->isNotEmpty() || $last4s->isNotEmpty())
            ? \DB::table('t_fin_online_receiving_accounts')
                ->where('is_active', 1)
                ->where(function ($q) use ($shortCodes, $last4s) {
                    if ($shortCodes->isNotEmpty()) {
                        $q->whereIn('short_code', $shortCodes->all());
                    }
                    if ($last4s->isNotEmpty()) {
                        $q->orWhereIn('account_last4', $last4s->all());
                    }
                })
                ->get(['id', 'short_code', 'name', 'color_hex', 'account_last4'])
            : collect();
        $bankByShort = $banks->keyBy('short_code');
        $last4Counts = $banks->filter(fn ($b) => $b->account_last4 !== null && $b->account_last4 !== '')
            ->groupBy('account_last4')->map->count();
        $bankByLast4 = $banks->filter(fn ($b) => $b->account_last4 && ($last4Counts[$b->account_last4] ?? 0) === 1)
            ->keyBy('account_last4');

        $out = [];
        foreach ($orderIds as $id) {
            $key = (int) $id;
            if (isset($settled[$key])) {
                $out[$id] = $this->summarise(collect());
                continue;
            }

            $sigs    = collect($byOrder[$key] ?? [])->values();
            $payload = $this->summarise($sigs);

            // Detected receiving bank → auto-select the chip + row badge.
            // Prefer the last-4 match (resilient to chips added after the proof
            // was read); fall back to the stored short_code.
            $bankSig = $sigs->first(fn ($s) => !empty($s->extracted_to_account_last4)
                && $bankByLast4->has($s->extracted_to_account_last4));
            $acct = $bankSig ? $bankByLast4->get($bankSig->extracted_to_account_last4) : null;
            if (!$acct) {
                $bankSig = $sigs->first(fn ($s) => !empty($s->extracted_to_account_short)
                    && $bankByShort->has($s->extracted_to_account_short));
                $acct = $bankSig ? $bankByShort->get($bankSig->extracted_to_account_short) : null;
            }
            if ($acct) {
                $payload['suggested_receiving_account_id']    = (int) $acct->id;
                $payload['suggested_receiving_account_short']  = $acct->short_code;
                $payload['suggested_receiving_account_color']  = $acct->color_hex;
            }

            // The already-received proof screenshot (first WhatsApp signal with an
            // image) so the approve modal can show it inline, not just an upload.
            $imgSig = $sigs->first(fn ($s) => $s->source === PaymentSignal::SOURCE_WHATSAPP
                && !empty($s->image_path));
            if ($imgSig) {
                $payload['proof_image_url'] = $imgSig->image_public_url;
            }

            // Raw receiving-account last-4 read from the proof, even when it did
            // NOT resolve to one of our banks — lets the modal flag an
            // unrecognized account so Taimur can add it.
            $last4Sig = $sigs->first(fn ($s) => !empty($s->extracted_to_account_last4));
            if ($last4Sig) {
                $payload['detected_last4'] = $last4Sig->extracted_to_account_last4;
            }

            // Mark combined when any contributing signal covers more than one invoice.
            $combinedSig = $sigs->first(fn ($s) => (int) ($groupCounts[$s->id] ?? 1) > 1);
            if ($combinedSig) {
                $payload['is_combined']    = true;
                $payload['combined_count'] = (int) $groupCounts[$combinedSig->id];
            }

            // "Discount needed" marker for the list badge.
            $needSig = $sigs->first(fn ($s) => isset($discountNeededBySignal[(int) $s->id]));
            if ($needSig) {
                $payload['needs_discount'] = true;
                $payload['short_amount']   = $discountNeededBySignal[(int) $needSig->id];
            }

            $out[$id] = $payload;
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
