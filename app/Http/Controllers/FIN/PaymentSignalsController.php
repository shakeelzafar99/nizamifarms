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
        ]);
    }
}
