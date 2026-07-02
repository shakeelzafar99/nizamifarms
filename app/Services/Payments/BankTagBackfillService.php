<?php

namespace App\Services\Payments;

use App\Models\FIN\PaymentSignal;
use App\Services\Payments\Ocr\GeminiBankScreenshotExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HISTORY backfill: re-read WhatsApp proof screenshots that were extracted
 * under the OLD Gemini prompt (which never asked for the receiving account) so
 * they gain a receiving-bank tag and show the bank badge on Online Approvals.
 *
 * Deliberately BUTTON-TRIGGERED ONLY (Operations "catch-up" button / the
 * payments:reextract-bank artisan command). It is NOT part of the automatic
 * tick — history rescanning is an owner decision, not a background job (and on
 * dev, where WhatsApp media doesn't exist, an always-on scan would just burn
 * extraction attempts).
 *
 * Scope: proofs attached (directly or via combined-payment links) to invoices
 * CURRENTLY in the pending-approval queue, still missing a receiving-account
 * last-4. New screenshots get the last-4 natively in ProcessPaymentSignals, so
 * this set only ever shrinks.
 *
 * SAFETY: updates ONLY receiver-side metadata (extracted_to_account_last4 /
 * _short, raw text, extractor version). Never touches amount, reference,
 * status, matched_order_id, or pairing — cannot disturb a verified match or
 * move money.
 */
class BankTagBackfillService
{
    /**
     * Re-read up to $limit eligible signals.
     *
     * @return array{eligible:int, reread:int, tagged:int, remaining:int, skipped_reason?:string}
     */
    public function run(?int $limit = null, ?GeminiBankScreenshotExtractor $extractor = null): array
    {
        $none = ['eligible' => 0, 'reread' => 0, 'tagged' => 0, 'remaining' => 0];

        if (!config('payment_signals.enabled')) {
            return $none + ['skipped_reason' => 'feature_off'];
        }
        if (!config('payment_signals.gemini.api_key')) {
            return $none + ['skipped_reason' => 'no_gemini_key'];
        }

        $extractor = $extractor ?: app(GeminiBankScreenshotExtractor::class);
        $limit = $limit ?: (int) config('payment_signals.gemini.reextract_batch', 20);
        $maxAttempts = (int) config('payment_signals.gemini.max_attempts', 5);

        $eligibleQuery = $this->eligibleQuery($maxAttempts);
        if ($eligibleQuery === null) {
            return $none; // nothing pending approval → nothing to backfill
        }

        $eligibleTotal = (clone $eligibleQuery)->count();
        if ($eligibleTotal === 0) {
            return $none;
        }

        $pending = $eligibleQuery->orderBy('id')->limit($limit)->get();

        $reread = 0;
        $tagged = 0;
        foreach ($pending as $signal) {
            try {
                // Count the attempt so a failing re-read can't loop forever.
                $signal->extraction_attempts = (int) $signal->extraction_attempts + 1;

                $result = $extractor->extract($signal->image_path);

                if ($result === null) {
                    // Transient/hard failure (or missing media, e.g. on dev) —
                    // persist the attempt, it will be retried until the cap.
                    $signal->save();
                    continue;
                }

                $reread++;

                // If Gemini now can't read it as a payment, DO NOT flip status
                // (this is an already-matched proof). Just record the attempt.
                if (!($result['is_payment_screenshot'] ?? false)) {
                    $signal->save();
                    continue;
                }

                $last4 = $this->extractLast4($result['receiver_account_masked'] ?? null);

                // Update ONLY receiver-side metadata. Never touch amount, ref,
                // status, match, or pairing.
                $signal->extracted_to_account_last4 = $last4;
                $signal->extracted_to_account_short = $this->mapReceivingShort($last4, $result['receiver_bank'] ?? null);
                $signal->extractor_version = GeminiBankScreenshotExtractor::VERSION;
                $signal->extraction_raw_text = $result['raw'] ?? $signal->extraction_raw_text;
                $signal->save();

                if ($signal->extracted_to_account_last4 || $signal->extracted_to_account_short) {
                    $tagged++;
                }
            } catch (\Throwable $e) {
                Log::error('BankTagBackfill: signal failed', [
                    'signal_id' => $signal->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $remainingQuery = $this->eligibleQuery($maxAttempts);
        $remaining = $remainingQuery ? $remainingQuery->count() : 0;

        if ($tagged > 0) {
            Log::info('BankTagBackfill: backfilled receiving-bank tags', ['count' => $tagged]);
        }

        return [
            'eligible'  => $eligibleTotal,
            'reread'    => $reread,
            'tagged'    => $tagged,
            'remaining' => $remaining,
        ];
    }

    /**
     * Query for signals still needing a bank re-read, or null when the pending
     * approval queue is empty.
     */
    private function eligibleQuery(int $maxAttempts)
    {
        // Orders currently sitting in the online approval queue.
        $pendingOrderIds = DB::table('t_fin_ledger')
            ->whereIn('transaction_type', ['invoice', 'order_payment'])
            ->whereIn('approval_status', ['pending', 'pending_l1', 'pending_l2'])
            ->whereNotNull('order_id')
            ->distinct()
            ->pluck('order_id')
            ->all();

        if (empty($pendingOrderIds)) {
            return null;
        }

        // Signals covering those orders, directly or via combined-payment links.
        $linkedSignalIds = DB::table('t_fin_payment_signal_order')
            ->whereIn('order_id', $pendingOrderIds)
            ->distinct()
            ->pluck('signal_id')
            ->all();

        return PaymentSignal::query()
            ->where('source', PaymentSignal::SOURCE_WHATSAPP)
            ->whereNotNull('image_path')
            ->whereNotNull('extractor_version')          // already read once (old prompt)
            ->whereNull('extracted_to_account_last4')    // no receiving account yet
            ->where('extraction_attempts', '<', $maxAttempts)
            ->where(function ($q) use ($pendingOrderIds, $linkedSignalIds) {
                $q->whereIn('matched_order_id', $pendingOrderIds);
                if (!empty($linkedSignalIds)) {
                    $q->orWhereIn('id', $linkedSignalIds);
                }
            });
    }

    /** Pull the last 4 digits from a masked receiver account (e.g. "0305xxx4237" -> "4237"). */
    private function extractLast4(?string $masked): ?string
    {
        if (!$masked) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $masked);
        if ($digits === null || strlen($digits) < 4) {
            return null;
        }
        return substr($digits, -4);
    }

    /**
     * Resolve a receiving account (by last-4, then bank-name text) to a chip
     * short_code. Mirror of ProcessPaymentSignals::mapReceivingShort so the
     * live and backfill paths agree exactly.
     */
    private function mapReceivingShort(?string $last4, ?string $bankText): ?string
    {
        $accounts = DB::table('t_fin_online_receiving_accounts')
            ->where('is_active', 1)
            ->get(['short_code', 'name', 'bank_name', 'account_last4']);

        if ($last4) {
            $byLast4 = $accounts->filter(fn ($a) => $a->account_last4 === $last4)->values();
            if ($byLast4->count() === 1) {
                return $byLast4[0]->short_code;
            }
            if ($byLast4->count() > 1 && $bankText) {
                $text = mb_strtolower($bankText);
                foreach ($byLast4 as $a) {
                    foreach ([$a->bank_name, $a->name, $a->short_code] as $needle) {
                        if ($needle && str_contains($text, mb_strtolower($needle))) {
                            return $a->short_code;
                        }
                    }
                }
            }
        }

        if ($bankText) {
            $text = mb_strtolower($bankText);
            foreach ($accounts as $acc) {
                if (str_contains($text, mb_strtolower($acc->short_code))
                    || str_contains($text, mb_strtolower($acc->name))) {
                    return $acc->short_code;
                }
            }
        }

        return null;
    }
}
