<?php

namespace App\Services\Khaas;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a photographed shop receipt and returns its printed lines as structured data.
 *
 * ⭐ Deliberately built in the shape of GeminiBankScreenshotExtractor, which has been
 * reading payment screenshots in production since early 2026: ONE call, temperature 0,
 * a responseSchema so the reply cannot wander, bounded retries, and the API key
 * redacted out of every log line. Nothing here is novel; only the schema is new.
 *
 * ⚠⚠ THIS SERVICE NEVER WRITES ANYTHING. It reads an image and returns an array. The
 *    draft row, the purchase and every rupee are somebody else's job — the purchase is
 *    only ever written by VendorController::recordWeightedPurchase, which stays the one
 *    writer of money. That is the same rule the NF Assistant follows when it replays a
 *    WhatsApp purchase log.
 *
 * ⚠ The model is a reader, not an authority. The server recomputes the arithmetic and
 *   the person confirms every line before anything is saved. A receipt this cannot read
 *   is a blank card to correct, never a wrong purchase.
 *
 * Cost: a receipt is roughly 1,500 input tokens and under 1,000 out — well under a
 * rupee per slip on Gemini Flash.
 */
class ReceiptExtractionService
{
    public const VERSION = 'receipt@v1';

    /**
     * @return array{lines: array, store_name: ?string, receipt_no: ?string,
     *               receipt_date: ?string, grand_total: ?float, subtotal: ?float,
     *               discount_total: ?float, confidence: string, raw: string}|null
     *         Null on a hard failure so the caller can keep the draft and retry.
     */
    public function extract(string $storageRelativePath): ?array
    {
        $cfg    = config('assistant.gemini');
        $apiKey = $cfg['api_key'] ?? config('payment_signals.gemini.api_key') ?? '';

        if (!$apiKey) {
            Log::warning('ReceiptExtraction: no API key configured');
            return null;
        }

        $disk = Storage::disk(config('whatsapp.media_disk', 'public'));
        if (!$disk->exists($storageRelativePath)) {
            Log::warning('ReceiptExtraction: image not found', ['path' => $storageRelativePath]);
            return null;
        }

        $bytes = $disk->get($storageRelativePath);
        $mime  = $disk->mimeType($storageRelativePath) ?: 'image/jpeg';

        $model    = $cfg['model'] ?? 'gemini-3.5-flash';
        $endpoint = rtrim($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com', '/')
            . '/v1beta/models/' . $model . ':generateContent';

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $this->prompt()],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $this->responseSchema(),
                // Reading a printed list is mechanical; thought tokens are billed as
                // output and buy nothing here. Same setting the Assistant uses.
                'thinkingConfig'   => ['thinkingBudget' => 0],
            ],
        ];

        try {
            $response = Http::timeout((int) ($cfg['timeout'] ?? 45))
                ->withQueryParameters(['key' => $apiKey])
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            // ⚠ SECURITY: a cURL error message carries the full request URL, and the
            //   URL carries ?key=<API_KEY>. Redact before it reaches the log.
            Log::error('ReceiptExtraction: request failed', ['error' => $this->redactKey($e->getMessage())]);
            return null;
        }

        if (!$response->successful()) {
            Log::error('ReceiptExtraction: non-200', [
                'status' => $response->status(),
                'body'   => $this->redactKey(mb_substr($response->body(), 0, 400)),
            ]);
            return null;
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (!$text) {
            Log::warning('ReceiptExtraction: empty candidate');
            return null;
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            Log::warning('ReceiptExtraction: reply was not JSON', ['text' => mb_substr($text, 0, 300)]);
            return null;
        }

        $usage = $response->json('usageMetadata') ?: [];

        return [
            'store_name'     => $this->clean($parsed['store_name'] ?? null),
            'receipt_no'     => $this->clean($parsed['receipt_no'] ?? null),
            'receipt_date'   => $this->cleanDate($parsed['receipt_date'] ?? null),
            'lines'          => $this->cleanLines($parsed['lines'] ?? []),
            'subtotal'       => $this->toAmount($parsed['subtotal'] ?? null),
            'discount_total' => $this->toAmount($parsed['discount_total'] ?? null),
            'grand_total'    => $this->toAmount($parsed['grand_total'] ?? null),
            'confidence'     => in_array(($parsed['confidence'] ?? ''), ['low', 'medium', 'high'], true)
                ? $parsed['confidence'] : 'low',
            'raw'            => $text,
            'model'          => $model,
            'tokens_in'      => (int) ($usage['promptTokenCount'] ?? 0),
            'tokens_out'     => (int) ($usage['candidatesTokenCount'] ?? 0),
        ];
    }

    /**
     * The arithmetic check the model is NOT trusted to do.
     *
     * Sums the lines and compares with the printed total. Anything more than Rs 5 apart
     * is shown to the person as a red warning rather than quietly accepted — a receipt
     * that does not add up is exactly the one worth a second look.
     *
     * @return array{lines_total: float, printed_total: ?float, difference: float, matches: bool}
     */
    public function reconcile(array $lines, ?float $printedTotal): array
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            $sum += (float) ($l['line_total'] ?? 0);
        }
        $sum = round($sum, 2);

        if ($printedTotal === null) {
            return ['lines_total' => $sum, 'printed_total' => null, 'difference' => 0.0, 'matches' => true];
        }

        $diff = round($printedTotal - $sum, 2);

        return [
            'lines_total'   => $sum,
            'printed_total' => round($printedTotal, 2),
            'difference'    => $diff,
            'matches'       => abs($diff) <= 5.0,
        ];
    }

    // =================================================================
    //  INTERNALS
    // =================================================================

    private function prompt(): string
    {
        return <<<'TXT'
You are reading a photographed SHOP RECEIPT from Pakistan — a supermarket or grocery
till slip, usually printed in English with rupee amounts.

Return ONE line object for every printed sales line, IN THE ORDER PRINTED. Do not merge
lines, do not split them, do not invent any, and do not skip a line you cannot fully read
— return it with the fields you can read and null for the rest.

For each line:
- raw_name: the product text exactly as printed, including any size or pack wording.
- qty: the printed quantity. Weighed items print a decimal like 0.92 or 5.06; counted
  items print a whole number like 2 or 12.
- unit_price: the printed price per unit.
- line_total: the printed amount for that line.
- discount: the printed discount for that line, 0 when none.
- sold_by: "weight" when the quantity is a decimal weight on a scale, "pack" when it is a
  count of packs or pieces, "unknown" if you genuinely cannot tell.
- pack_size_value and pack_size_unit: ONLY when the product name states a size.
    "Seasons Canola Oil Poly Bag 1Ltr"  -> 1 and "L"
    "Puck Crm Ches 910G"                -> 910 and "g"
    "Ponam Maida 1kg"                   -> 1 and "kg"
    "Farm Fresh Golden D Egg 30's"      -> 30 and "pcs"
    "Non-Woven Large Bag 18\"x18\"x7.5\"" -> null and null (a dimension is not a size)
  Use null for both when the name states no size.

Also return, from the totals block: store_name, receipt_no, receipt_date in YYYY-MM-DD
(null if you cannot read it confidently — NEVER guess a date), subtotal, discount_total
and grand_total.

Lines that are charges rather than goods — "FBR POS Charges", service or delivery fees,
rounding — are still real printed lines: return them, with sold_by "unknown".

Read only what is printed. Do not calculate, correct or complete anything: if a number is
unreadable, return null for it. Somebody will check every line against the paper before
any of it is saved.
TXT;
    }

    private function responseSchema(): array
    {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'store_name'     => ['type' => 'STRING', 'nullable' => true],
                'receipt_no'     => ['type' => 'STRING', 'nullable' => true],
                'receipt_date'   => ['type' => 'STRING', 'nullable' => true],
                'subtotal'       => ['type' => 'NUMBER', 'nullable' => true],
                'discount_total' => ['type' => 'NUMBER', 'nullable' => true],
                'grand_total'    => ['type' => 'NUMBER', 'nullable' => true],
                'confidence'     => ['type' => 'STRING'],
                'lines' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'raw_name'        => ['type' => 'STRING'],
                            'qty'             => ['type' => 'NUMBER', 'nullable' => true],
                            'unit_price'      => ['type' => 'NUMBER', 'nullable' => true],
                            'line_total'      => ['type' => 'NUMBER', 'nullable' => true],
                            'discount'        => ['type' => 'NUMBER', 'nullable' => true],
                            'sold_by'         => ['type' => 'STRING', 'nullable' => true],
                            'pack_size_value' => ['type' => 'NUMBER', 'nullable' => true],
                            'pack_size_unit'  => ['type' => 'STRING', 'nullable' => true],
                        ],
                        'required' => ['raw_name'],
                    ],
                ],
            ],
            'required' => ['lines'],
        ];
    }

    private function cleanLines($lines): array
    {
        if (!is_array($lines)) {
            return [];
        }

        $out = [];
        foreach ($lines as $l) {
            if (!is_array($l)) {
                continue;
            }
            $name = $this->clean($l['raw_name'] ?? null);
            if ($name === null || $name === '') {
                continue;
            }

            $out[] = [
                'raw_name'        => $name,
                'qty'             => $this->toAmount($l['qty'] ?? null),
                'unit_price'      => $this->toAmount($l['unit_price'] ?? null),
                'line_total'      => $this->toAmount($l['line_total'] ?? null),
                'discount'        => $this->toAmount($l['discount'] ?? null) ?? 0.0,
                'sold_by'         => in_array(($l['sold_by'] ?? ''), ['weight', 'pack', 'unknown'], true)
                    ? $l['sold_by'] : 'unknown',
                'pack_size_value' => $this->toAmount($l['pack_size_value'] ?? null),
                'pack_size_unit'  => $this->cleanPackUnit($l['pack_size_unit'] ?? null),
            ];
        }

        return $out;
    }

    /** Normalise the handful of size units a Pakistani receipt actually prints. */
    private function cleanPackUnit($v): ?string
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return null;
        }

        return match ($v) {
            'l', 'ltr', 'litre', 'liter' => 'L',
            'ml'                          => 'ml',
            'kg', 'kgs'                   => 'kg',
            'g', 'gm', 'gms', 'gram', 'grams' => 'g',
            'pcs', 'pc', 'piece', 'pieces', "'s", 's' => 'pcs',
            default => null,
        };
    }

    private function clean($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 255);
    }

    private function cleanDate($v): ?string
    {
        $v = $this->clean($v);
        if (!$v) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function toAmount($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return round((float) $v, 3);
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);
        return is_numeric($clean) ? round((float) $clean, 3) : null;
    }

    private function redactKey(string $s): string
    {
        return preg_replace('/([?&]key=)[^&\s]+/i', '$1***', $s);
    }
}
