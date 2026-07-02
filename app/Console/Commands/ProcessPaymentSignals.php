<?php

namespace App\Console\Commands;

use App\Models\FIN\PaymentSignal;
use App\Services\Payments\Ocr\GeminiBankScreenshotExtractor;
use App\Services\Payments\Signals\PaymentSignalMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reads pending WhatsApp screenshot signals through Gemini Vision, fills the
 * extracted fields, then runs the matcher. Scheduled every minute; self-
 * throttles to a no-op when the feature switch is off or no Gemini key is set.
 *
 * The WhatsApp webhook only INSERTS a cheap signal row (status='new') so the
 * webhook stays fast; the expensive Gemini call happens here, off the request
 * thread — mirroring the customer-app outbox pattern.
 */
class ProcessPaymentSignals extends Command
{
    protected $signature = 'payments:process-signals {--limit=}';
    protected $description = 'Extract WhatsApp payment screenshots via Gemini and match them to orders';

    public function handle(
        GeminiBankScreenshotExtractor $extractor,
        PaymentSignalMatcher $matcher
    ): int {
        if (!config('payment_signals.enabled')) {
            return self::SUCCESS; // master switch off
        }
        if (!config('payment_signals.gemini.api_key')) {
            return self::SUCCESS; // not configured yet
        }

        $limit = (int) ($this->option('limit')
            ?: config('payment_signals.gemini.batch_size', 15));

        // A screenshot that keeps failing Gemini extraction (corrupt/oversized
        // image, auth, or a rate-limit burst) must not be retried forever — the
        // queue is processed oldest-first, so a handful of stuck rows would
        // block every newer screenshot. After this many attempts we stop
        // selecting it (it stays 'new' but is parked).
        $maxAttempts = (int) config('payment_signals.gemini.max_attempts', 5);

        $pending = PaymentSignal::query()
            ->where('source', PaymentSignal::SOURCE_WHATSAPP)
            ->where('status', PaymentSignal::STATUS_NEW)
            ->whereNotNull('image_path')
            ->where('extraction_attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($pending as $signal) {
            try {
                // Count the attempt up-front so a persistently failing image
                // can't loop forever (see $maxAttempts above).
                $signal->extraction_attempts = (int) $signal->extraction_attempts + 1;

                $result = $extractor->extract($signal->image_path);

                if ($result === null) {
                    // Hard failure (network/auth/rate-limit). Persist the
                    // incremented attempt and leave as 'new' to retry next tick
                    // (until the cap), then move on.
                    $signal->save();
                    continue;
                }

                $signal->extractor_version = GeminiBankScreenshotExtractor::VERSION;
                $signal->extraction_raw_text = $result['raw'] ?? null;

                if (!$result['is_payment_screenshot']) {
                    $signal->status = PaymentSignal::STATUS_IRRELEVANT;
                    $signal->save();
                    $processed++;
                    continue;
                }

                $signal->extracted_amount = $result['amount'];
                $signal->extracted_ref = $result['reference'];
                $signal->extracted_sender_name = $result['sender_name'];
                $signal->extracted_sender_account_masked = $result['sender_account_masked'];
                $signal->extracted_sender_bank = $result['sender_bank'];
                // Which of OUR banks received this: prefer the receiver account's
                // last-4 (deterministic → exact chip); fall back to the receiver
                // bank NAME text for wallet chips that carry no digits.
                $last4 = $this->extractLast4($result['receiver_account_masked'] ?? null);
                $signal->extracted_to_account_last4 = $last4;
                $signal->extracted_to_account_short = $this->mapReceivingShort($last4, $result['receiver_bank'] ?? null);
                $signal->extracted_txn_datetime = $this->parseDate($result['txn_datetime'] ?? null);
                $signal->extraction_confidence = $result['confidence'];
                $signal->save();

                $matcher->match($signal->fresh());
                $processed++;
            } catch (\Throwable $e) {
                Log::error('ProcessPaymentSignals: signal failed', [
                    'signal_id' => $signal->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($processed > 0) {
            Log::info('ProcessPaymentSignals: processed WhatsApp signals', ['count' => $processed]);
        }

        return self::SUCCESS;
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
     * Resolve which of our receiving accounts a payment landed in, to a chip
     * short_code. Strategy (most reliable first):
     *   1) exact last-4 match against t_fin_online_receiving_accounts.account_last4
     *      — unique for every account that carries digits; if two ever share a
     *      last-4, disambiguate by the receiver bank/name text.
     *   2) fall back to the original free-text bank-NAME substring match, which
     *      is all wallet chips without digits (EP, etc.) can offer.
     * Returns null rather than guess when nothing resolves.
     */
    private function mapReceivingShort(?string $last4, ?string $bankText): ?string
    {
        $accounts = \DB::table('t_fin_online_receiving_accounts')
            ->where('is_active', 1)
            ->get(['short_code', 'name', 'bank_name', 'account_last4']);

        // 1) Strongest signal: exact last-4 match.
        if ($last4) {
            $byLast4 = $accounts->filter(fn ($a) => $a->account_last4 === $last4)->values();
            if ($byLast4->count() === 1) {
                return $byLast4[0]->short_code;
            }
            if ($byLast4->count() > 1 && $bankText) {
                // Rare collision: two accounts share a last-4. Break the tie
                // using the receiver bank/name text from the screenshot.
                $text = mb_strtolower($bankText);
                foreach ($byLast4 as $a) {
                    foreach ([$a->bank_name, $a->name, $a->short_code] as $needle) {
                        if ($needle && str_contains($text, mb_strtolower($needle))) {
                            return $a->short_code;
                        }
                    }
                }
            }
            // last-4 present but not uniquely resolvable → don't guess; fall through.
        }

        // 2) Fallback: free-text bank-name match (original behaviour, best-effort).
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

    private function parseDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
