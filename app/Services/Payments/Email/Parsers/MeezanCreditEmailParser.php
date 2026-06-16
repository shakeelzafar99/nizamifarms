<?php

namespace App\Services\Payments\Email\Parsers;

/**
 * Parses Meezan Bank credit-alert emails (the "amount credited to your
 * account" notifications that arrive when a customer transfers money to the
 * Nizami Farms Meezan account).
 *
 * NOTE: Meezan's exact wording is not 100% known until we see real forwarded
 * samples in production. The patterns below cover the common phrasings; they
 * are intentionally broad and ordered most-specific-first. When a real sample
 * differs, add a pattern here — the raw body is always stored on the signal so
 * we can refine without re-fetching.
 */
class MeezanCreditEmailParser extends AbstractBankEmailParser
{
    public const VERSION = 'meezan-email@v1';

    public function parse(string $subject, string $body): ?array
    {
        $haystack = $subject . "\n" . $body;

        // Must look like a CREDIT (incoming) alert, not a debit/OTP/marketing mail.
        $looksCredit = preg_match('/\b(credit|credited|received|deposit)\b/i', $haystack);
        if (!$looksCredit) {
            return null;
        }

        $amount = $this->toAmount($this->firstMatch([
            '/(?:PKR|Rs\.?)\s*([0-9][0-9,]*\.?[0-9]*)/i',
            '/amount(?:\s*credited)?[:\s]*\(?(?:PKR|Rs\.?)?\s*([0-9][0-9,]*\.?[0-9]*)/i',
            '/credited(?:\s*with)?[:\s]*(?:PKR|Rs\.?)?\s*([0-9][0-9,]*\.?[0-9]*)/i',
        ], $haystack));

        // No amount => not a usable credit signal.
        if ($amount === null) {
            return null;
        }

        // Per-transaction reference. NOTE: do NOT capture the beneficiary IBAN
        // fragment that appears on RAAST alerts (e.g. "AC# RAAST PYMT PK75...")
        // — it is OUR account and identical on every credit, which would
        // falsely trip the duplicate-reference guard. Only keyworded refs.
        $reference = $this->firstMatch([
            '/(?:reference|ref(?:erence)?\s*(?:no|number|#)|transaction\s*(?:id|no|number|#)|trx|stan)[:\s#]*([A-Za-z0-9\-]{4,})/i',
        ], $haystack);

        // Payer name only from explicit "from/sender/remitter" phrasing. Meezan
        // RAAST credit alerts carry NO payer name (the "Beneficiary Account"
        // line is our own account), so this is intentionally left null there.
        $senderName = $this->firstMatch([
            '/(?:from|sender|remitter|received\s*from|credited\s*by|payer)[:\s]*([A-Z][A-Za-z .\'-]{2,60})/i',
        ], $haystack);

        // Only the payer's account — never the bare "your account" (ours).
        $senderAccount = $this->firstMatch([
            '/(?:from\s*account|sender\s*account|payer\s*account|debit\s*account)[:\s#]*([0-9Xx\*\-]{4,})/i',
        ], $haystack);

        // Date and time often arrive as separate labelled lines
        // ("Transaction Date : 15-Jun-2026" / "Transaction Time : 17:11").
        $txnDate = $this->firstMatch([
            '/(?:transaction\s*date|value\s*date|date(?:\s*&?\s*time)?|dated|on)[:\s]*([0-9]{1,2}[\-\/ ][A-Za-z0-9]{2,9}[\-\/ ][0-9]{2,4})/i',
        ], $haystack);
        $txnTime = $this->firstMatch([
            '/(?:transaction\s*time|time)[:\s]*([0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?\s*(?:AM|PM)?)/i',
        ], $haystack);
        $txnDatetime = trim(($txnDate ?? '') . ' ' . ($txnTime ?? ''));
        $txnDatetime = $txnDatetime !== '' ? $txnDatetime : null;

        // Confidence: amount alone is moderate; amount + ref is high.
        $confidence = 0.6;
        if ($reference) {
            $confidence += 0.2;
        }
        if ($senderName) {
            $confidence += 0.1;
        }

        return [
            'amount'                => $amount,
            'reference'             => $reference,
            'sender_name'           => $senderName ? trim($senderName) : null,
            'sender_account_masked' => $senderAccount,
            'txn_datetime'          => $txnDatetime,
            'confidence'            => min($confidence, 0.95),
        ];
    }
}
