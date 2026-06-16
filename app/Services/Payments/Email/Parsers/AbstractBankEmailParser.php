<?php

namespace App\Services\Payments\Email\Parsers;

/**
 * Contract + shared helpers for bank credit-confirmation email parsers.
 *
 * Bank emails are templated and change rarely, so deterministic regex is the
 * right tool here (cheap, fast, and it fails LOUDLY when a template changes
 * rather than silently mis-extracting). Each bank gets one subclass.
 */
abstract class AbstractBankEmailParser
{
    public const VERSION = 'base@v1';

    /**
     * Parse a plain-text email body (+ subject) into payment fields.
     *
     * @return array{
     *   amount: float|null,
     *   reference: string|null,
     *   sender_name: string|null,
     *   sender_account_masked: string|null,
     *   txn_datetime: string|null,
     *   confidence: float
     * }|null  Null if this body is not a recognisable credit confirmation.
     */
    abstract public function parse(string $subject, string $body): ?array;

    /** Version tag stored on the signal for traceability. */
    public function version(): string
    {
        return static::VERSION;
    }

    /** First regex capture group across a list of patterns, or null. */
    protected function firstMatch(array $patterns, string $haystack): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $haystack, $m) && isset($m[1])) {
                $val = trim($m[1]);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return null;
    }

    protected function toAmount(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/[^0-9.]/', '', $value);
        return $digits === '' ? null : (float) $digits;
    }
}
