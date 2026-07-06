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
            // SECURITY: a cURL/connection error message contains the full request
            // URL, which includes ?key=<API_KEY>. Redact it so the key never
            // reaches the log (a leaked key gets auto-disabled by Google).
            Log::error('GeminiExtractor: request failed', ['error' => $this->redactKey($e->getMessage())]);
            return null;
        }

        if (!$response->successful()) {
            Log::error('GeminiExtractor: non-200 from Gemini', [
                'status' => $response->status(),
                'body'   => $this->redactKey(mb_substr($response->body(), 0, 500)),
            ]);
            return null;
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (!$text) {
            Log::warning('GeminiExtractor: empty candidate text', [
                'body' => $this->redactKey(mb_substr($response->body(), 0, 500)),
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
You are checking an image a customer sent over WhatsApp. It MAY be proof of a
bank transfer / mobile-wallet payment to a business called "Nizami Farms" /
"Nizami Meat" in Pakistan (banks: Meezan, HBL, JazzCash, Easypaisa, Bank
Alfalah, Askari, Standard Chartered, MCB, etc.), OR it may be something
unrelated (a photo of meat/products, a menu or price list, a chat, a meme, a
random picture).

IMPORTANT — the proof may be EITHER a clean digital SCREENSHOT or a PHOTO taken
with a camera of a phone screen or a printed receipt. The MEDIUM does not
matter: a clear photograph of a payment receipt counts EXACTLY like a
screenshot. Do not reject an image just because it is a photo rather than a
screenshot.

Return ONLY the JSON object described by the schema. Rules:
- is_payment_screenshot: judge by CONTENT, not by whether it is a screenshot or
  a photo. Set TRUE only when the image clearly shows a COMPLETED money transfer
  / payment receipt — it must have transaction hallmarks: an amount AND at least
  one of {a success line like "Transaction Successful"/"successful"/"sent"/
  "paid"/"credited", a reference / transaction ID, a from/to account, or a
  sender/receiver name}. Set FALSE for anything that is NOT a payment receipt:
  product or food photos, menus, price lists, chats, memes, screenshots of other
  apps, or random pictures — even if they are clear. When FALSE, leave the other
  fields null.
- amount: the transferred amount as a plain number, no currency symbol, no
  thousands separators (e.g. "PKR 4,408" -> 4408). Report it ONLY if its digits
  are CLEARLY legible. If the image is too blurry, dark, or angled to read the
  amount with confidence, set amount to null — do NOT guess. A payment receipt
  whose amount you cannot read is still is_payment_screenshot=true with amount
  null (it will simply be ignored downstream).
- reference: the transaction / reference / TID number exactly as shown.
- sender_name: the FROM account holder's name exactly as printed.
- sender_account_masked: the FROM account/phone as shown (may be masked like 0312xxx8227).
- sender_bank: the sending bank/wallet name (e.g. "Meezan Bank", "HBL", "JazzCash").
- receiver_name: the TO account holder's name as printed (usually "Nizami Farms").
- receiver_bank: the receiving bank if shown.
- receiver_account_masked: the TO / beneficiary account number, IBAN, OR
  mobile-wallet number that RECEIVED the money, exactly as shown (including any
  masking). Bank example: "0305xxx4237", "PK12MEZN...4237". Mobile-wallet example
  (Easypaisa / JazzCash): the number shown under "Sent to" / "To" / "Received by"
  / "Account Details" — e.g. "03455242624". This is OUR receiving account. Do NOT
  put the SENDER's / "Sent by" / "Funding Source" number here. Return it verbatim
  so the last digits are preserved; null only if no receiving number is shown.
- txn_datetime: ISO 8601 if you can (e.g. 2026-05-19T19:12:00); else the date text as shown; else null.
- confidence: your 0.0-1.0 confidence that the extracted amount + reference are
  correct. Lower it for blurry, dark, glare-heavy, or steeply-angled photos.
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

    /**
     * Strip an API key from any text before it is logged. Guzzle connection
     * errors embed the full request URL (…?key=AIza…); this ensures the key
     * can never be written to a log file (and then leaked via a committed log).
     */
    private function redactKey(string $text): string
    {
        return (string) preg_replace('/([?&]key=)[A-Za-z0-9_\-]+/i', '$1***REDACTED***', $text);
    }
}
