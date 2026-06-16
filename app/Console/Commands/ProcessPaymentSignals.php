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

        $pending = PaymentSignal::query()
            ->where('source', PaymentSignal::SOURCE_WHATSAPP)
            ->where('status', PaymentSignal::STATUS_NEW)
            ->whereNotNull('image_path')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($pending as $signal) {
            try {
                $result = $extractor->extract($signal->image_path);

                if ($result === null) {
                    // Hard failure (network/auth). Leave as 'new' to retry next tick.
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
                $signal->extracted_to_account_short = $this->mapReceivingShort($result['receiver_bank'] ?? null);
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

    /** Map a free-text receiving bank to one of our receiving-account short codes. */
    private function mapReceivingShort(?string $bankText): ?string
    {
        if (!$bankText) {
            return null;
        }
        $text = mb_strtolower($bankText);
        $accounts = \DB::table('t_fin_online_receiving_accounts')->get(['short_code', 'name']);
        foreach ($accounts as $acc) {
            if (str_contains($text, mb_strtolower($acc->short_code))
                || str_contains($text, mb_strtolower($acc->name))) {
                return $acc->short_code;
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
