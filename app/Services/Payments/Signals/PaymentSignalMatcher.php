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
            if ($signal->match_reason === 'combined_dismissed') {
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

            return $this->doMatch($primary->fresh());
        } catch (\Throwable $e) {
            Log::error('PaymentSignalMatcher rematch failed', [
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
        $tolerance = PaymentProofStatusService::amountTolerance();

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

        // Step 5: combined / bulk payment — ONE transfer that settles SEVERAL
        // open invoices. Anchored on the NEWEST invoice (the customer pays the
        // latest, then tops up older ones), preferring a contiguous run. When a
        // set is found we mark the signal MATCHED and link it to every covered
        // invoice (see findCombinedSet) so all of them show the proof.
        $combined = $this->findCombinedSet($candidates, $amount, $tolerance);
        if (is_array($combined) && !empty($combined['orders'])) {
            return $this->linkCombined($signal, $combined['orders'], $combined['confidence'], $combined['reason']);
        }

        // Step 6: proof received but nothing fits. Still attach to the most
        // recent pending order so the approver sees the screenshot in context.
        // (If we saw an ambiguous bulk — two different invoice sets sum to the
        // same amount — we don't auto-pick one; the proof panel shows the open
        // invoices so the manager can decide.)
        $ambiguousBulk = is_array($combined) && !empty($combined['ambiguous']);
        $signal->status = PaymentSignal::STATUS_AMOUNT_MISMATCH;
        $signal->match_reason = $ambiguousBulk ? 'bulk_ambiguous' : 'amount_differs';
        $signal->match_confidence = $ambiguousBulk ? 0.30 : 0.20;
        $signal->matched_order_id = $latest?->id;
        $signal->save();
        // No combined set survived — make sure no stale links remain on re-match.
        $this->clearLinks($signal->id);
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
        $signal->match_confidence = $confidence;
        $signal->match_reason     = $reason;
        $signal->status           = PaymentSignal::STATUS_MATCHED;
        $signal->save();

        $this->writeLinks($signal, $orders);

        if ($signal->source === PaymentSignal::SOURCE_EMAIL && $signal->extracted_sender_name) {
            $this->bumpAliasUsage($signal);
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
        $tol = PaymentProofStatusService::amountTolerance();
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
