<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\PaymentSignal;
use App\Services\Payments\Signals\PaymentProofStatusService;
use Illuminate\Http\Request;

/**
 * Read-only JSON endpoints that feed the payment-proof detail panels on the
 * Online Approvals page (and anywhere else that wants to show the screenshot /
 * parsed email behind a badge). Never mutates finance data.
 */
class PaymentSignalsController extends Controller
{
    /** GET /admin/payments/order/{orderId}/signals */
    public function forOrder(Request $request, int $orderId)
    {
        // Include signals tied to this order DIRECTLY and via a combined (bulk)
        // payment link, so an older invoice in a bundle still shows the proof.
        $linkedSignalIds = \DB::table('t_fin_payment_signal_order')
            ->where('order_id', $orderId)
            ->pluck('signal_id')
            ->all();

        $signals = PaymentSignal::query()
            ->where(function ($q) use ($orderId, $linkedSignalIds) {
                $q->where('matched_order_id', $orderId);
                if (!empty($linkedSignalIds)) {
                    $q->orWhereIn('id', $linkedSignalIds);
                }
            })
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->orderByDesc('id')
            ->get();

        // Pull the order's expected amount + balance for the agreement check.
        $order = \DB::table('t_crm_prod_order')->where('id', $orderId)
            ->first(['total_price', 'total_paid', 'order_number']);
        $expected = $order ? round((float) $order->total_price - (float) ($order->total_paid ?? 0), 2) : null;
        $tolerance = (float) config('payment_signals.amount_tolerance', 10.0);

        // For a combined (bulk) signal the agreement must compare the transfer
        // against the GROUP total (sum of every covered invoice's balance), not
        // this single invoice — otherwise an older invoice would look mismatched.
        $combinedInfo = collect();
        $signalIds = $signals->pluck('id')->all();
        if (!empty($signalIds)) {
            $combinedInfo = \DB::table('t_fin_payment_signal_order')
                ->whereIn('signal_id', $signalIds)
                ->selectRaw('signal_id, COUNT(*) AS c, SUM(balance_at_match) AS total')
                ->groupBy('signal_id')
                ->get()
                ->keyBy('signal_id');
        }

        $payload = $signals->map(function (PaymentSignal $s) use ($expected, $tolerance, $combinedInfo) {
            $combo       = $combinedInfo->get($s->id);
            $isCombined  = $combo && (int) $combo->c > 1;
            $compareBase = $isCombined ? (float) $combo->total : $expected;

            $amountMatch = ($compareBase !== null && $s->extracted_amount !== null)
                ? abs((float) $s->extracted_amount - $compareBase) <= $tolerance
                : null;

            return [
                'id'             => $s->id,
                'source'         => $s->source,
                'status'         => $s->status,
                'match_reason'   => $s->match_reason,
                'match_confidence' => $s->match_confidence,
                'amount'         => $s->extracted_amount,
                'reference'      => $s->extracted_ref,
                'sender_name'    => $s->extracted_sender_name,
                'sender_account' => $s->extracted_sender_account_masked,
                'sender_bank'    => $s->extracted_sender_bank,
                'to_account'     => $s->extracted_to_account_short,
                'txn_datetime'   => optional($s->extracted_txn_datetime)->format('Y-m-d H:i'),
                'received_at'    => $s->source === PaymentSignal::SOURCE_EMAIL
                                    ? optional($s->email_received_at)->format('Y-m-d H:i')
                                    : optional($s->created_at)->format('Y-m-d H:i'),
                'image_url'      => $s->source === PaymentSignal::SOURCE_WHATSAPP ? $s->image_public_url : null,
                'email_subject'  => $s->email_subject,
                'email_from'     => $s->email_from,
                'email_body'     => $s->source === PaymentSignal::SOURCE_EMAIL
                                    ? mb_substr((string) $s->extraction_raw_text, 0, 4000)
                                    : null,
                'paired'         => (bool) $s->paired_signal_id,
                'is_combined'    => $isCombined,
                'combined_count' => $isCombined ? (int) $combo->c : null,
                'combined_total' => $isCombined ? (float) $combo->total : null,
                'agreement'      => [
                    'amount_match'  => $amountMatch,
                    'expected'      => $compareBase,
                ],
            ];
        });

        return response()->json([
            'success'      => true,
            'order_id'     => $orderId,
            'order_number' => $order->order_number ?? null,
            'proof'        => app(PaymentProofStatusService::class)->forOrder($orderId),
            'signals'      => $payload,
            'combined'     => $this->combinedPaymentHint($signals),
        ]);
    }

    /**
     * If a signal was flagged as a possible COMBINED payment (one transfer that
     * equals the SUM of several open invoices), return the customer's open
     * invoices so the manager can see what it likely covers and split manually.
     * Additive/read-only; null when not a bulk case.
     */
    private function combinedPaymentHint($signals): ?array
    {
        // Prefer the authoritative combined links if any of these signals carry
        // them — list exactly the invoices the bundle was tied to.
        $signalIds = $signals->pluck('id')->all();
        if (!empty($signalIds)) {
            $rows = \DB::table('t_fin_payment_signal_order as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->whereIn('l.signal_id', $signalIds)
                ->get(['l.order_id', 'o.order_number', 'o.total_price', 'o.total_paid', 'l.balance_at_match']);

            $byOrder = $rows->groupBy('order_id');
            if ($byOrder->count() >= 2) {
                $sum  = 0.0;
                $list = [];
                foreach ($byOrder as $orderId => $grp) {
                    $inv = $grp->first();
                    $bal = $inv->balance_at_match !== null
                        ? (float) $inv->balance_at_match
                        : round((float) $inv->total_price - (float) ($inv->total_paid ?? 0), 2);
                    $sum += $bal;
                    $list[] = [
                        'order_id'     => (int) $orderId,
                        'order_number' => $inv->order_number,
                        'balance'      => $bal,
                    ];
                }
                $amount = (float) optional(
                    $signals->first(fn (PaymentSignal $s) => $s->extracted_amount !== null)
                )->extracted_amount;

                return [
                    'amount'     => $amount,
                    'open_total' => round($sum, 2),
                    'invoices'   => $list,
                    'confirmed'  => true,
                ];
            }
        }

        // Fallback: legacy heuristic for a bulk-flagged-but-unlinked signal
        // (e.g. an ambiguous bundle) — show the customer's open invoices.
        $bulk = $signals->first(fn (PaymentSignal $s) => str_contains((string) $s->match_reason, 'bulk'));
        if (!$bulk || !$bulk->matched_customer_id || !$bulk->extracted_amount) {
            return null;
        }

        $windowDays   = (int) config('payment_signals.match_window_days', 30);
        $graceDays    = (int) config('payment_signals.match_future_grace_days', 1);
        $openStatuses = config('payment_signals.open_payment_statuses', ['unpaid', 'partial']);

        $anchor = \Carbon\Carbon::parse(
            $bulk->extracted_txn_datetime ?? $bulk->email_received_at ?? $bulk->created_at ?? now()
        );

        $invoices = \DB::table('t_crm_prod_order')
            ->where('customer_id', $bulk->matched_customer_id)
            ->whereIn('payment_status', $openStatuses)
            ->where('order_status', '!=', 'cancelled')
            ->where('order_date', '>=', $anchor->copy()->subDays($windowDays)->startOfDay())
            ->where('order_date', '<=', $anchor->copy()->addDays($graceDays)->endOfDay())
            ->orderByDesc('order_date')
            ->get(['id', 'order_number', 'total_price', 'total_paid']);

        $sum = 0.0;
        $list = [];
        foreach ($invoices as $inv) {
            $bal = round((float) $inv->total_price - (float) ($inv->total_paid ?? 0), 2);
            $sum += $bal;
            $list[] = [
                'order_id'     => (int) $inv->id,
                'order_number' => $inv->order_number,
                'balance'      => $bal,
            ];
        }

        if (count($list) < 2) {
            return null;
        }

        return [
            'amount'     => (float) $bulk->extracted_amount,
            'open_total' => round($sum, 2),
            'invoices'   => $list,
        ];
    }

    /**
     * POST /admin/payments/order/{orderId}/uncombine
     *
     * Reversibility for an auto-detected combined payment. Drops the bulk links
     * for the signal(s) covering this order (and their paired opposite-source
     * signal) and reverts those signals to `amount_mismatch`, so the "Combined"
     * badges clear. Read-feature only — touches no ledger / payment / balance.
     */
    public function uncombine(Request $request, int $orderId)
    {
        $signalIds = \DB::table('t_fin_payment_signal_order')
            ->where('order_id', $orderId)
            ->pluck('signal_id')
            ->unique()
            ->values()
            ->all();

        if (empty($signalIds)) {
            return response()->json(['success' => true, 'changed' => 0]);
        }

        // Revert the whole WhatsApp+email group together.
        $pairedIds = PaymentSignal::query()
            ->whereIn('id', $signalIds)
            ->pluck('paired_signal_id')
            ->filter()
            ->all();
        $allIds = array_values(array_unique(array_merge($signalIds, $pairedIds)));

        \DB::table('t_fin_payment_signal_order')->whereIn('signal_id', $allIds)->delete();

        PaymentSignal::query()
            ->whereIn('id', $allIds)
            ->where('status', PaymentSignal::STATUS_MATCHED)
            ->update([
                'status'       => PaymentSignal::STATUS_AMOUNT_MISMATCH,
                'match_reason' => 'combined_dismissed',
            ]);

        return response()->json(['success' => true, 'changed' => count($allIds)]);
    }
}
