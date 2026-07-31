<?php

namespace App\Services\Assistant;

/**
 * Parse a raw bank SMS into {direction, amount, reference, counterparty,
 * counterparty_account}.
 *
 * Deterministic regex on purpose — no LLM. Bank SMS are templated, so regex is
 * cheap, private (nothing leaves the server) and, crucially, fails to 'unknown'
 * rather than guessing. See NF-MESSAGES-ENHANCEMENTS-PLAN-JUL2026.md Phase 4.
 *
 * ⚠️ DIRECTION IS THE DANGEROUS FIELD. A debit alert mis-read as a credit is
 * exactly the Meezan incident that produced false "Verified" payments. So the
 * rule here is conservative to a fault:
 *   • an explicit debit word  → debit
 *   • an explicit credit word → credit
 *   • BOTH or NEITHER present → 'unknown' (never a coin-flip)
 * Downstream, only debits become expense/payment candidates and only credits
 * are ever considered for customer-payment matching; 'unknown' does neither
 * automatically — it waits for the human.
 *
 * v2 (Jul-20): counterparty extraction is now ZONE-based and structured, built
 * from Taimur's real SMS from all four banks (Meezan 8079, Askari 8870,
 * HBL 14250, Alfalah 8287):
 *   • the counterparty lives between "from" and "in/to your/as/via" on credits
 *     (and after "to" on transfer debits) — searching only that zone is what
 *     stops OUR OWN account number ("...in NIZAMI FARMS AKBL AC# 008*0971")
 *     from being read as the sender's.
 *   • counterparty_account is a normalized identity key (AKBL*9400, MBL*4602,
 *     EASYPAISA*8150, PK56UNIL01090, PK*ALFH*2041, MERCHANT:JAMIL SWEETS...)
 *     used by t_ai_counterparty_map to recognize repeat senders.
 *   • HBL credits carry NO account — name only. Alfalah appends RAAST
 *     participant codes (TMICFBPK) that must be stripped from the name.
 */
class BankSmsParser
{
    // Word-boundaried so "credited" doesn't accidentally satisfy a naive
    // "credit" search inside "debited"/etc. Ordered most-common first.
    private const DEBIT_WORDS = [
        'debited', 'debit', 'withdrawn', 'withdrawal', 'transferred to',
        'paid to', 'payment of', 'sent to', 'spent', 'purchase of', ' dr ',
        'charged', // debit-card: "is charged for PKR 3,006.00 ... at JAMIL SWEETS"
        // Outgoing IBFT/RAAST wording. Any genuinely-inbound "funds transfer"
        // SMS also says "credited"/"received", which the both-words rule below
        // then collapses to 'unknown' — so these can't mislabel a credit.
        'funds transfer', 'fund transfer', 'transfer to', 'ibft to', 'raast to',
    ];
    private const CREDIT_WORDS = [
        'credited', 'credit', 'received', 'deposited', 'deposit of',
        'transferred from', 'transfer from', 'inflow', ' cr ',
    ];

    // Bank / wallet short codes seen in Pakistani alert SMS. Used to (a) strip
    // a trailing bank token off a person's name ("NADEEM ROSHAN ALI SIYAL BAF")
    // and (b) reject a "name" that is only a bank ("received from BAFL" — HBL
    // writes the SENDING BANK when it has no payer name).
    private const BANK_TOKENS = [
        'AKBL', 'MBL', 'HBL', 'BAF', 'BAFL', 'UBL', 'MCB', 'ABL', 'SCB', 'NBP',
        'FBL', 'BOP', 'JS', 'SONERI', 'MEEZAN', 'ASKARI', 'ALFALAH', 'HABB',
        'EASYPAISA', 'JAZZCASH', 'SADAPAY', 'NAYAPAY', 'IBAN', 'BANK',
    ];

    /**
     * @return array{direction:string, amount:?float, reference:?string,
     *               counterparty:?string, counterparty_account:?string, method:string}
     */
    public function parse(string $body): array
    {
        $direction = $this->direction(' ' . mb_strtolower(trim($body)) . ' ');
        [$name, $account] = $this->counterpartyParts($body, $direction);

        return [
            'direction'            => $direction,
            'amount'               => $this->amount($body),
            'reference'            => $this->reference($body),
            'counterparty'         => $name,
            'counterparty_account' => $account,
            'method'               => 'regex',
        ];
    }

    /** Conservative debit/credit detection — 'unknown' whenever it's not clear. */
    private function direction(string $lowerPadded): string
    {
        $hasDebit  = $this->containsAny($lowerPadded, self::DEBIT_WORDS);
        $hasCredit = $this->containsAny($lowerPadded, self::CREDIT_WORDS);

        if ($hasDebit && !$hasCredit) return 'debit';
        if ($hasCredit && !$hasDebit) return 'credit';
        // Both present (e.g. "debited ... credited to beneficiary") or neither:
        // refuse to guess. The Meezan lesson — a wrong direction is worse than
        // no direction.
        return 'unknown';
    }

    /**
     * The transacted amount. Prefers a value adjacent to a currency token
     * (PKR/Rs) so an account number or a balance figure isn't mistaken for it.
     * Skips a value that is clearly a balance ("avail bal", "balance").
     */
    private function amount(string $body): ?float
    {
        // 1) Currency-tagged amount: "PKR 12,500.00", "Rs. 5,000", "RS 3200",
        //    and Askari's "PKR. 8,320.00" (dot AFTER the currency token — real,
        //    from Taimur's 8870 messages; the dot must not break the capture).
        if (preg_match_all('/(?:PKR|Rs|RS)\.?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            // If several currency figures exist, drop any that immediately
            // follow a "balance" word — that's the running balance, not the txn.
            foreach ($m[1] as $i => $cap) {
                $offset = $m[0][$i][1];
                $before = mb_strtolower(mb_substr($body, max(0, $offset - 24), 24));
                if (str_contains($before, 'bal') || str_contains($before, 'balance')) {
                    continue;
                }
                $val = $this->toFloat($cap[0]);
                if ($val !== null) return $val;
            }
            // Everything looked like a balance? Fall back to the first figure.
            $val = $this->toFloat($m[1][0][0]);
            if ($val !== null) return $val;
        }

        // 2) "amount 12,500" / "amt 12500" without a currency token.
        if (preg_match('/\b(?:amount|amt)\.?\s*[:\-]?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $body, $m)) {
            return $this->toFloat($m[1]);
        }

        return null;
    }

    /**
     * A transaction id / reference number, if the SMS states one.
     * Covers: Ref# 198624944361 · TID:992542 · TXN ID SM17150656208CB6 ·
     * FT Tx ID FT261990R7YG1V4C (Alfalah writes "Tx", not "txn").
     */
    private function reference(string $body): ?string
    {
        $patterns = [
            '/\b(?:ref(?:erence)?|txn|tx|transaction|trx|tid|rrn)\s*(?:no\.?|id|#)?\s*[:\-]?\s*([A-Za-z0-9]{4,})/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $body, $m) && !empty($m[1])) {
                return mb_substr(trim($m[1]), 0, 60);
            }
        }
        return null;
    }

    /**
     * Structured counterparty: [display name | null, normalized account key | null].
     *
     * Credits: the zone between "from" and the first of "in/to your/as/via" is
     * the OTHER party; everything after belongs to OUR side and must never be
     * read as theirs. Transfer debits: the zone after "to". Debit-card charges:
     * the merchant after the LAST " at " ("...at 16:06:13 at JAMIL SWEETS...").
     */
    private function counterpartyParts(string $body, string $direction): array
    {
        // Debit-card purchase → merchant identity (used for auto-ignore rules).
        if ($direction === 'debit' && preg_match('/\bcharged\b/i', $body)) {
            $merchant = $this->merchantName($body);
            return $merchant
                ? [$merchant, mb_substr('MERCHANT:' . $merchant, 0, 64)]
                : [null, null];
        }

        $zone = $this->counterpartyZone($body, $direction);
        if ($zone === null) {
            return [null, null];
        }

        $account = $this->extractAccount($zone);
        $name    = $this->cleanName($zone, $account['raw'] ?? null);

        return [$name, $account['key'] ?? null];
    }

    /** The substring that belongs to the OTHER party, or null. */
    private function counterpartyZone(string $body, string $direction): ?string
    {
        if ($direction === 'credit') {
            // "received from <ZONE> in/to your/as RAAST/via ..."
            // Terminators, earliest wins: "to your A/C", "to A/C xxx4237"
            // (Meezan omits "your" on EasyPaisa credits), "in NIZAMI/AKBL...",
            // "as RAAST payment", "via", or a date.
            if (preg_match('/\bfrom\s+(.{2,90}?)(?:\s+(?:in|to)\s+your\b|\s+(?:in|to)\s+A\s*\/?\s*C\b|\s+in\s+[A-Z]|\s+as\s+RAAST\b|\s+via\b|\s+on\s+\d|$)/is', $body, $m)) {
                return trim($m[1]);
            }
            return null;
        }

        // Transfer debit: "transferred/paid/sent to <ZONE> on/via/ref ..."
        //
        // The zone must stop where OUR side of the sentence begins, exactly like
        // the credit branch above. Meezan's RAAST debit reads:
        //   "PKR 100,000.00 sent to I.SAEED PK26ABPAxx001 as RAAST payment from
        //    your AC# xxx4237 of AABPARA BR ISD on 27-Jul-2026 at 02:05 TID:..."
        // Without the "as RAAST" / "from your" terminators the zone ran to the
        // date and the stored name came out as the whole tail
        // ("I.SAEED as RAAST payment from your of AABPARA BR ISD") — ugly on the
        // card and useless as a name_key. The ACCOUNT key is unaffected either
        // way (it is matched inside the zone), so existing map rules keep working.
        if (preg_match('/\b(?:transferred|paid|sent|transfer)\s+to\s+(.{2,90}?)(?:\s+as\s+RAAST\b|\s+(?:from|in)\s+your\b|\s+from\s+A\s*\/?\s*C\b|\s+on\s+\d|\s+via\b|\s+ref\b|\s+txn\b|$)/is', $body, $m)) {
            return trim($m[1]);
        }
        // Generic "to <NAME>" fallback (older wording), boundary-limited.
        if (preg_match('/\b(?:beneficiary|payee)\s*[:\-]?\s*(.{2,60}?)(?:\s+on\s+\d|\s+via\b|\s+ref\b|$)/is', $body, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Normalize a MANUALLY-TYPED account fragment into the same key space the
     * SMS parser produces — used by the "Known bank accounts" panel's Add form.
     *
     * ⚠ Keys must live in the PARSER's space or they will never match: the SMS
     * shows masked fragments ("PK96UNILxx322", "MBL ACxxx4602"), so a full
     * 24-char IBAN typed from a bank statement can never equal what an SMS
     * carries. We therefore run the input through the SAME extraction patterns
     * first ("MBL ACxxx4602" → MBL*4602), and only fall back to an
     * uppercased/space-stripped literal when it already looks like one of our
     * keys (contains a digit, 5+ chars). Anything else → null, refuse to save.
     */
    public function normalizeKey(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $hit = $this->extractAccount($raw);
        if (!empty($hit['key'])) {
            return mb_substr($hit['key'], 0, 64);
        }
        $flat = strtoupper(preg_replace('/\s+/', '', $raw));
        // MERCHANT:… labels (debit-card rules) pass through as-is.
        if (str_starts_with($flat, 'MERCHANT:')) {
            return mb_substr(strtoupper(trim($raw)), 0, 64);
        }
        if (strlen($flat) >= 5 && preg_match('/^[A-Z0-9*:\-]+$/', $flat) && preg_match('/\d/', $flat)) {
            return mb_substr($flat, 0, 64);
        }
        return null;
    }

    /**
     * The counterparty's account fragment inside the zone, as
     * ['raw' => matched text, 'key' => normalized identity key], or [].
     * Ordered most-specific first.
     */
    private function extractAccount(string $zone): array
    {
        // 1) Starred masked IBAN: "PK**ALFH**2041" → PK*ALFH*2041.
        if (preg_match('/\bPK\*+([A-Z]{3,4})\*+(\d{3,6})\b/i', $zone, $m)) {
            return ['raw' => $m[0], 'key' => strtoupper('PK*' . $m[1] . '*' . $m[2])];
        }
        // 2) Full masked IBAN: "PK56UNIL01090" (PK + 2 digits + 4-letter bank + rest).
        if (preg_match('/\bPK\d{2}[A-Z]{4}[A-Z0-9]{4,}\b/i', $zone, $m)) {
            return ['raw' => $m[0], 'key' => strtoupper($m[0])];
        }
        // 3) EasyPaisa wallet: "EASYPAISA-xxx8150" → EASYPAISA*8150.
        if (preg_match('/\bEASYPAISA[\s\-]*x*(\d{3,6})\b/i', $zone, $m)) {
            return ['raw' => $m[0], 'key' => 'EASYPAISA*' . $m[1]];
        }
        // 4) Bank code + masked digits: "(MBL ACxxx4602)", "AKBL A C *9400",
        //    "AKBL A/C *7635". The digits are the visible tail of their account.
        if (preg_match('/\b([A-Z]{2,6})\s*[\.\s]*A\s*\/?\s*C\s*[#:]?\s*[x\*]+\s*(\d{3,6})\b/i', $zone, $m)) {
            return ['raw' => $m[0], 'key' => strtoupper($m[1]) . '*' . $m[2]];
        }
        return [];
    }

    /** The person's display name from the zone, cleaned of account/bank noise. */
    private function cleanName(string $zone, ?string $accountRaw): ?string
    {
        // Cut trailing SMS noise first ("on 19-Jul", "Avail Bal PKR ...") — the
        // zone terminator list can't catch every bank's phrasing (v1 rule kept).
        $name = preg_split('/\b(?:on|dated|avail|available|bal|balance|ref|txn|tid)\b/i', $zone)[0];
        if ($accountRaw) {
            $name = str_replace($accountRaw, ' ', $name);
        }
        // Drop bracketed fragments "(MBL ...)" and masked tokens "xxxPYMT", "AC#".
        $name = preg_replace('/\([^)]*\)/', ' ', $name);
        $name = preg_replace('/\b\w*x{2,}\w*\b/i', ' ', $name);
        $name = preg_replace('/\bA\s*\/?\s*C\s*[#:]?/i', ' ', $name);
        $name = preg_replace('/[^A-Za-z .\'\-]/', ' ', $name);

        // Strip trailing bank / RAAST-participant tokens, repeatedly:
        // "NADEEM ROSHAN ALI SIYAL BAF" → BAF is a bank; "SAIMA ASIF TMICFBPK" →
        // TMICFBPK is a RAAST participant code (long, contains PK).
        $tokens = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        while (!empty($tokens)) {
            $last = strtoupper(rtrim(end($tokens), '.'));
            $isBank = in_array($last, self::BANK_TOKENS, true);
            $isParticipantCode = strlen($last) >= 7 && ctype_upper($last) && str_contains($last, 'PK');
            if ($isBank || $isParticipantCode) {
                array_pop($tokens);
                continue;
            }
            break;
        }
        if (empty($tokens)) {
            return null;
        }
        $name = trim(implode(' ', $tokens), " .,-'");
        // A "name" that is ONLY a bank token is the sending bank, not a person
        // (HBL: "received from BAFL in your HBL A/C").
        if ($name === '' || in_array(strtoupper($name), self::BANK_TOKENS, true)) {
            return null;
        }
        return mb_substr($name, 0, 60);
    }

    /** Debit-card merchant: the text after the LAST " at " (first "at" is the time). */
    private function merchantName(string $body): ?string
    {
        if (!preg_match_all('/\bat\s+([A-Z][A-Za-z0-9 .&\'\-]{2,50})/', $body, $m)) {
            return null;
        }
        $candidate = trim(end($m[1]));
        // Trim the country suffix ("... ISLAMABAD PK.") and trailing noise.
        $candidate = preg_replace('/\s+PK\.?$/i', '', $candidate);
        $candidate = trim($candidate, " .,-'");
        return $candidate !== '' ? mb_substr($candidate, 0, 50) : null;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) return true;
        }
        return false;
    }

    private function toFloat(string $value): ?float
    {
        $digits = str_replace(',', '', trim($value));
        if ($digits === '' || !is_numeric($digits)) return null;
        $f = (float) $digits;
        return $f > 0 ? $f : null;
    }
}
