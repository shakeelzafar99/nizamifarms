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
    public function match(PaymentSignal $signal): PaymentSignal
    {
        try {
            return $this->doMatch($signal);
        } catch (\Throwable $e) {
            Log::error('PaymentSignalMatcher failed', [
                'signal_id' => $signal->id,
                'error'     => $e->getMessage(),
            ]);
            return $signal;
        }
    }

    private function doMatch(PaymentSignal $signal): PaymentSignal
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
            if ($signal->source === PaymentSignal::SOURCE_EMAIL) {
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

        $candidates = $this->candidateOrders($customerId, $signal);
        $tolerance = (float) config('payment_signals.amount_tolerance', 1.0);

        // Step 2: most-recent order balance match.
        $latest = $candidates->first();
        if ($latest && $this->within($this->balance($latest), $amount, $tolerance)) {
            return $this->link($signal, $latest, 0.95, 'last_order_balance');
        }

        // Step 3/4: other orders whose balance equals the amount.
        $balanceMatches = $candidates->filter(
            fn ($o) => $this->within($this->balance($o), $amount, $tolerance)
        )->values();

        if ($balanceMatches->count() === 1) {
            return $this->link($signal, $balanceMatches->first(), 0.85, 'single_unpaid_match');
        }
        if ($balanceMatches->count() > 1) {
            // Ambiguous — attach to most recent, low confidence, flag for human.
            return $this->link($signal, $balanceMatches->first(), 0.45, 'multiple_candidates');
        }

        // Step 5: bulk hint — amount equals sum of >1 pending balances.
        if ($this->looksLikeBulk($candidates, $amount, $tolerance)) {
            $signal->status = PaymentSignal::STATUS_AMOUNT_MISMATCH;
            $signal->match_reason = 'bulk_sum_hint';
            $signal->match_confidence = 0.30;
            // Attach to the most-recent so it still surfaces on an order row.
            $signal->matched_order_id = $latest?->id;
            $signal->save();
            $this->pair($signal);
            return $signal;
        }

        // Step 6: proof received but nothing fits. Still attach to the most
        // recent pending order so the approver sees the screenshot in context.
        $signal->status = PaymentSignal::STATUS_AMOUNT_MISMATCH;
        $signal->match_reason = 'amount_differs';
        $signal->match_confidence = 0.20;
        $signal->matched_order_id = $latest?->id;
        $signal->save();
        $this->pair($signal);
        return $signal;
    }

    private function link(PaymentSignal $signal, $order, float $confidence, string $reason): PaymentSignal
    {
        $signal->matched_order_id = $order->id;
        $signal->match_confidence = $confidence;
        $signal->match_reason = $reason;
        $signal->status = PaymentSignal::STATUS_MATCHED;
        $signal->save();

        // Touch the alias' usefulness counter when an email matched via alias.
        if ($signal->source === PaymentSignal::SOURCE_EMAIL && $signal->extracted_sender_name) {
            $this->bumpAliasUsage($signal);
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
    private function candidateOrders(int $customerId, PaymentSignal $signal)
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
            ->where('order_date', '>=', $since)
            ->where('order_date', '<=', $until);

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

    private function balance($order): float
    {
        return round((float) $order->total_price - (float) ($order->total_paid ?? 0), 2);
    }

    private function within(float $a, float $b, float $tol): bool
    {
        return abs($a - $b) <= $tol;
    }

    private function looksLikeBulk($candidates, float $amount, float $tol): bool
    {
        $sum = 0.0;
        foreach ($candidates as $o) {
            $sum += $this->balance($o);
        }
        return $candidates->count() > 1 && $this->within($sum, $amount, $tol);
    }

    private function isDuplicateRef(PaymentSignal $signal): bool
    {
        return PaymentSignal::query()
            ->where('id', '!=', $signal->id)
            ->where('extracted_ref', $signal->extracted_ref)
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

        $wa = $this->findOppositeByAmountDate($signal, PaymentSignal::SOURCE_WHATSAPP);
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
        $oppositeSource = $signal->source === PaymentSignal::SOURCE_WHATSAPP
            ? PaymentSignal::SOURCE_EMAIL
            : PaymentSignal::SOURCE_WHATSAPP;

        $opposite = $this->findOppositeByAmountDate($signal, $oppositeSource);
        if (!$opposite) {
            return;
        }

        // The email side usually carries no customer/order — push this
        // screenshot's link onto it (or vice versa) before binding the pair.
        $this->propagateLink($signal, $opposite);
        $this->bindPair($signal, $opposite);
    }

    /**
     * Find the best opposite-source signal describing the SAME payment:
     * same reference (wins outright), else same amount within tolerance AND a
     * transaction time within the configured pairing window.
     */
    private function findOppositeByAmountDate(PaymentSignal $signal, string $oppositeSource): ?PaymentSignal
    {
        if ($signal->extracted_amount === null) {
            return null;
        }
        $tol = (float) config('payment_signals.amount_tolerance', 1.0);
        $windowDays = (int) config('payment_signals.pair_window_days', 3);
        $time = $this->paymentTime($signal);
        $from = $time->copy()->subDays($windowDays);
        $to   = $time->copy()->addDays($windowDays);

        $base = PaymentSignal::query()
            ->where('source', $oppositeSource)
            ->whereNull('paired_signal_id')
            ->where('id', '!=', $signal->id);

        // A shared transaction reference is decisive.
        if ($signal->extracted_ref) {
            $byRef = (clone $base)->where('extracted_ref', $signal->extracted_ref)->first();
            if ($byRef) {
                return $byRef;
            }
        }

        return (clone $base)
            ->whereBetween('extracted_amount', [
                (float) $signal->extracted_amount - $tol,
                (float) $signal->extracted_amount + $tol,
            ])
            ->get()
            ->filter(fn ($s) => $this->paymentTime($s)->between($from, $to))
            ->sortBy(fn ($s) => abs($this->paymentTime($s)->diffInSeconds($time)))
            ->first();
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
            $dst->match_reason = $dst->source === PaymentSignal::SOURCE_EMAIL
                ? 'email_corroborates_whatsapp'
                : 'whatsapp_corroborates_email';
        } elseif ($src->status === PaymentSignal::STATUS_AMOUNT_MISMATCH
            && $dst->status !== PaymentSignal::STATUS_MATCHED) {
            $dst->status = PaymentSignal::STATUS_AMOUNT_MISMATCH;
            $dst->match_reason = 'corroborated_amount_mismatch';
        }

        $dst->save();
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
