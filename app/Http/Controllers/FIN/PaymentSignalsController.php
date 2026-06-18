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
        $signals = PaymentSignal::query()
            ->where('matched_order_id', $orderId)
            ->whereIn('status', [PaymentSignal::STATUS_MATCHED, PaymentSignal::STATUS_AMOUNT_MISMATCH])
            ->orderByDesc('id')
            ->get();

        // Pull the order's expected amount + balance for the agreement check.
        $order = \DB::table('t_crm_prod_order')->where('id', $orderId)
            ->first(['total_price', 'total_paid', 'order_number']);
        $expected = $order ? round((float) $order->total_price - (float) ($order->total_paid ?? 0), 2) : null;
        $tolerance = (float) config('payment_signals.amount_tolerance', 1.0);

        $payload = $signals->map(function (PaymentSignal $s) use ($expected, $tolerance) {
            $amountMatch = ($expected !== null && $s->extracted_amount !== null)
                ? abs((float) $s->extracted_amount - $expected) <= $tolerance
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
                'agreement'      => [
                    'amount_match'  => $amountMatch,
                    'expected'      => $expected,
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
}
