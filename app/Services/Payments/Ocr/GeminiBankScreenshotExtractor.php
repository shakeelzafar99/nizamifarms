<?php

namespace App\Services\Payments\Ocr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a bank-transfer screenshot using Google's Gemini Vision model and
 * returns structured payment fields as a plain array. One HTTP call, JSON
 * response constrained by a schema, so we never have to write per-bank regex
 * for screenshots.
 *
 * Cost: ~$0.001 per image on gemini-2.5-flash. With the customer pre-filter
 * (only customers with a pending online order are ever sent here) the monthly
 * spend is typically $1-3.
 */
class GeminiBankScreenshotExtractor
{
    public const VERSION = 'gemini-2.5-flash@v1';

    /**
     * @return array{
     *   is_payment_screenshot: bool,
     *   amount: float|null,
     *   reference: string|null,
     *   sender_name: string|null,
     *   sender_account_masked: string|null,
     *   sender_bank: string|null,
     *   receiver_name: string|null,
     *   receiver_bank: string|null,
     *   receiver_account_masked: string|null,
     *   txn_datetime: string|null,
     *   confidence: float,
     *   raw: string
     * }|null  Null on hard failure (network/auth) so the caller can retry later.
     */
    public function extract(string $storageRelativePath): ?array
    {
        $cfg = config('payment_signals.gemini');
        $apiKey = $cfg['api_key'] ?? '';
        if (!$apiKey) {
            Log::warning('GeminiExtractor: no API key configured');
            return null;
        }

        $disk = Storage::disk(config('whatsapp.media_disk', 'public'));
        if (!$disk->exists($storageRelativePath)) {
            Log::warning('GeminiExtractor: image not found', ['path' => $storageRelativePath]);
            return null;
        }

        $bytes = $disk->get($storageRelativePath);
        $mime  = $disk->mimeType($storageRelativePath) ?: 'image/jpeg';

        $endpoint = rtrim($cfg['base_url'], '/')
            . '/v1beta/models/' . $cfg['model'] . ':generateContent';

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $this->prompt()],
                    ['inline_data' => [
                        'mime_type' => $mime,
                        'data'      => base64_encode($bytes),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $this->responseSchema(),
            ],
        ];

        try {
            $response = Http::timeout($cfg['timeout'] ?? 30)
                ->withQueryParameters(['key' => $apiKey])
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::error('GeminiExtractor: request failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            Log::error('GeminiExtractor: non-200 from Gemini', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (!$text) {
            Log::warning('GeminiExtractor: empty candidate text', [
                'body' => mb_substr($response->body(), 0, 500),
            ]);
            return null;
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            Log::warning('GeminiExtractor: candidate text was not JSON', ['text' => mb_substr($text, 0, 500)]);
            return null;
        }

        return [
            'is_payment_screenshot' => (bool) ($parsed['is_payment_screenshot'] ?? false),
            'amount'                => $this->toAmount($parsed['amount'] ?? null),
            'reference'             => $this->clean($parsed['reference'] ?? null),
            'sender_name'           => $this->clean($parsed['sender_name'] ?? null),
            'sender_account_masked' => $this->clean($parsed['sender_account_masked'] ?? null),
            'sender_bank'           => $this->clean($parsed['sender_bank'] ?? null),
            'receiver_name'         => $this->clean($parsed['receiver_name'] ?? null),
            'receiver_bank'         => $this->clean($parsed['receiver_bank'] ?? null),
            'receiver_account_masked' => $this->clean($parsed['receiver_account_masked'] ?? null),
            'txn_datetime'          => $this->clean($parsed['txn_datetime'] ?? null),
            'confidence'            => (float) ($parsed['confidence'] ?? 0),
            'raw'                   => $text,
        ];
    }

    private function prompt(): string
    {
        return <<<'TXT'
You are reading a screenshot a customer sent over WhatsApp to confirm a bank
transfer / mobile-wallet payment to a business called "Nizami Farms" in
Pakistan. Banks include Meezan, HBL, JazzCash, Easypaisa, Bank Alfalah, etc.

Return ONLY the JSON object described by the schema. Rules:
- is_payment_screenshot: true ONLY if this clearly shows a completed money
  transfer/receipt (look for "Transaction Successful", an amount, a reference
  number, from/to accounts). If it's a chat, a product photo, a meme, or
  anything else, set it false and leave other fields null.
- amount: the transferred amount as a plain number, no currency symbol, no
  thousands separators (e.g. "PKR 4,408" -> 4408).
- reference: the transaction / reference / TID number exactly as shown.
- sender_name: the FROM account holder's name exactly as printed.
- sender_account_masked: the FROM account/phone as shown (may be masked like 0312xxx8227).
- sender_bank: the sending bank/wallet name (e.g. "Meezan Bank", "HBL", "JazzCash").
- receiver_name: the TO account holder's name as printed (usually "Nizami Farms").
- receiver_bank: the receiving bank if shown.
- receiver_account_masked: the TO / beneficiary account number or IBAN exactly as
  shown, INCLUDING any masking (e.g. "0305xxx4237", "PK12MEZN...4237"). This is
  OUR account that received the money. Return it verbatim so the last visible
  digits are preserved; null if the receiving account is not shown.
- txn_datetime: ISO 8601 if you can (e.g. 2026-05-19T19:12:00); else the date text as shown; else null.
- confidence: your 0.0-1.0 confidence that the extracted amount + reference are correct.
Never invent values. Use null for anything not visibly present.
TXT;
    }

    private function responseSchema(): array
    {
        $nullableString = ['type' => 'STRING', 'nullable' => true];
        return [
            'type' => 'OBJECT',
            'properties' => [
                'is_payment_screenshot' => ['type' => 'BOOLEAN'],
                'amount'                => ['type' => 'NUMBER', 'nullable' => true],
                'reference'             => $nullableString,
                'sender_name'           => $nullableString,
                'sender_account_masked' => $nullableString,
                'sender_bank'           => $nullableString,
                'receiver_name'         => $nullableString,
                'receiver_bank'         => $nullableString,
                'receiver_account_masked' => $nullableString,
                'txn_datetime'          => $nullableString,
                'confidence'            => ['type' => 'NUMBER'],
            ],
            'required' => ['is_payment_screenshot', 'confidence'],
        ];
    }

    private function toAmount($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $digits = preg_replace('/[^0-9.]/', '', (string) $value);
        return $digits === '' ? null : (float) $digits;
    }

    private function clean($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/\s+/', ' ', trim((string) $value));
        return $value === '' ? null : $value;
    }
}
