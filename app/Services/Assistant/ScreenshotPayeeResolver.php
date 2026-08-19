<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHO does this transfer screenshot pay, and WHICH WAY is the money going?
 *
 * Taimur forwards a bank-app screenshot minutes before the SMS arrives (often
 * before it arrives at all). The amount, date and bank are readable off the
 * image by the model — but "who was paid" was not, so the assistant was
 * instructed to ASK every single time, even for the vendors it already knows
 * by account. This turns that into a lookup.
 *
 * ── What screenshots actually contain (measured on stored extractions) ──
 * Bank apps show a MASKED TAIL, not a full account number:
 *   receiver_account_masked: "**** ...4237"   sender_account_masked: "*4343"
 * Which is the same shape `t_ai_counterparty_map` already stores — its keys are
 * masked fragments too (MBL*2969, PK16FAYSXX564). ⚠ That is why matching here
 * is STRUCTURAL (bank + trailing digits) rather than string equality: the two
 * sides mask to different widths, and CounterpartyAccountController already
 * refuses to store a full IBAN precisely because it "would never fire".
 *
 * ── Direction is decided by OUR accounts, never by the model ──
 * Every one of our banks is registered with its own last-4 in
 * `t_fin_online_receiving_accounts`. If the RECEIVER side is one of ours the
 * money came IN (a customer's proof); if the SENDER side is ours it went OUT (a
 * payment we made). Both or neither ⇒ 'unknown', and the caller must ask.
 * ⚠⚠ THIS IS THE SAFETY RULE OF THIS WHOLE FEATURE: a customer's payment proof
 * must NEVER be drafted as a vendor payment. Reading it off our own registry
 * makes the answer a fact rather than a judgement.
 *
 * Everything here is READ-ONLY and returns null rather than a best guess.
 */
class ScreenshotPayeeResolver
{
    public const DIR_IN      = 'in';       // money arrived — a customer proof
    public const DIR_OUT     = 'out';      // money left — a payment we made
    public const DIR_UNKNOWN = 'unknown';  // cannot tell — ask

    /**
     * Which way is this transfer going, judged against our own bank registry.
     *
     * @param string|null $receiverAccount  e.g. "**** ...4237"
     * @param string|null $senderAccount    e.g. "*4343"
     */
    public function direction(?string $receiverAccount, ?string $senderAccount, ?string $receiverName = null): string
    {
        $receiverIsOurs = $this->matchesOurBank($receiverAccount);
        $senderIsOurs   = $this->matchesOurBank($senderAccount);

        if ($receiverIsOurs && !$senderIsOurs) return self::DIR_IN;
        if ($senderIsOurs && !$receiverIsOurs) return self::DIR_OUT;

        // Neither side carried a recognisable account. A receiver NAME that is
        // plainly us is a weaker but real signal — bank apps print "NIZAMI
        // FARMS" / "NIZAMI MEAT" as the beneficiary on a customer's proof.
        // Only ever used to call something money IN, which is the safe verdict:
        // it routes to the proof flow, which cannot move money out.
        if (!$receiverIsOurs && !$senderIsOurs && $this->looksLikeUs($receiverName)) {
            return self::DIR_IN;
        }

        return self::DIR_UNKNOWN;
    }

    /**
     * The vendor / own-account / expense category this beneficiary is taught to
     * be, or null. Account first (a deliberate teaching), name only as a last
     * resort and only when it is unambiguous.
     *
     * @return array{entity_type:string, entity_id:int, label:string, matched_key:?string, how:string}|null
     */
    public function resolvePayee(?string $accountMasked, ?string $bankHint = null, ?string $name = null): ?array
    {
        try {
            $map = app(SmsCounterpartyMap::class);

            // 1. The screenshot happens to show the exact fragment we stored.
            if ($accountMasked) {
                $key = app(BankSmsParser::class)->normalizeKey($accountMasked);
                if ($key) {
                    $rule = $map->byAccount($key);
                    if ($rule) {
                        return $this->describe($rule, $key, 'account_exact');
                    }
                }
            }

            // 2. Structural: same bank, and the stored key's visible tail is the
            //    tail of what the screenshot shows. This is the normal path —
            //    the two sides mask differently.
            $structural = $this->byStructure($accountMasked, $bankHint);
            if ($structural) {
                return $structural;
            }

            // 3. Name, and only when exactly one rule claims it. Held to the
            //    same generic-token discipline as payer-name matching: agreeing
            //    on MUHAMMAD and KHAN alone identifies nobody here either.
            if ($name) {
                $rule = $map->byName($name);
                if ($rule && $this->nameIsDistinctive($name, $map->entityName($rule))) {
                    return $this->describe($rule, null, 'name_unique');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[ScreenshotPayee] ' . $e->getMessage());
        }
        return null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** Is this masked account one of OUR registered banks? */
    private function matchesOurBank(?string $masked): bool
    {
        $tail = $this->tailDigits($masked);
        if ($tail === null || strlen($tail) < 4) {
            return false;
        }
        $last4 = substr($tail, -4);

        return DB::table('t_fin_online_receiving_accounts')
            ->whereNotNull('account_last4')
            ->where('account_last4', '<>', '')
            ->where('account_last4', $last4)
            ->exists();
    }

    /** "NIZAMI FARMS" / "NIZAMI MEAT" on the receiving side = us. */
    private function looksLikeUs(?string $name): bool
    {
        $n = strtolower(trim((string) $name));
        return $n !== '' && (str_contains($n, 'nizami') || str_contains($n, 'nf '));
    }

    /**
     * Match on (bank code, trailing digits) instead of the whole string.
     * A stored `MBL*2969` and a screenshot's "**** ...2969" are the same
     * account shown two ways; a stored `PK16FAYSXX564` and "...1564" are not
     * necessarily, so the bank must agree too.
     *
     * ⚠ Ambiguity returns null. Four digits plus a bank is strong but not
     * unique by construction, and a wrong vendor here would pre-fill a payment
     * card with somebody else's name on it.
     */
    private function byStructure(?string $accountMasked, ?string $bankHint): ?array
    {
        $tail = $this->tailDigits($accountMasked);
        if ($tail === null || strlen($tail) < 3) {
            return null;
        }
        $bankTokens = $this->bankTokens($bankHint, $accountMasked);

        $hits = [];
        $rows = DB::table('t_ai_counterparty_map')
            ->where('is_active', 1)->whereNotNull('account_key')
            ->get(['id', 'account_key', 'entity_type', 'entity_id', 'entity_label', 'name_key']);

        foreach ($rows as $rule) {
            $storedTail = $this->tailDigits($rule->account_key);
            if ($storedTail === null || strlen($storedTail) < 3) {
                continue;
            }
            // Either side may be the shorter mask, so accept a suffix match in
            // whichever direction — "…564" against "…1564" is the same account.
            if (!str_ends_with($tail, $storedTail) && !str_ends_with($storedTail, $tail)) {
                continue;
            }
            // Bank must not CONTRADICT. An unknown bank on either side is not a
            // contradiction — plenty of stored keys carry no readable code.
            $storedBank = $this->bankTokens(null, $rule->account_key);
            if ($bankTokens && $storedBank && !$this->banksAgree($bankTokens, $storedBank)) {
                continue;
            }
            $hits[] = $rule;
        }

        if (count($hits) !== 1) {
            return null; // nothing, or a choice that is not ours to make
        }
        return $this->describe($hits[0], $hits[0]->account_key, 'account_structural');
    }

    /**
     * IBAN bank codes → the word a bank APP prints. A screenshot says "Faysal
     * Bank" or "myABL"; an IBAN says FAYS or ALFH. Without this the two never
     * agree and every structural match is thrown away as a contradiction — the
     * common case, not an edge one.
     *
     * ⚠ Only banks we actually hold or have been paid through. An unlisted code
     * simply falls through to prefix matching, and a genuine non-match ends in
     * "ask the user" — which is safe. Never add a mapping on a hunch: a WRONG
     * one lets a tail match the wrong bank's vendor.
     */
    private const BANK_ALIASES = [
        'FAYS' => 'FAYSAL',  'MEZN' => 'MEEZAN',   'MBL'  => 'MEEZAN',
        'ALFH' => 'ALFALAH', 'BAF'  => 'ALFALAH',  'BAFL' => 'ALFALAH',
        'HABB' => 'HABIB',   'HBL'  => 'HABIB',    'BAHL' => 'HABIB',
        'UNIL' => 'UNITED',  'UBL'  => 'UNITED',
        'MUCB' => 'MCB',     'ASCM' => 'ASKARI',   'AKBL' => 'ASKARI',
        'SCBL' => 'STANDARD','SCB'  => 'STANDARD',
        'ABPA' => 'ALLIED',  'ABL'  => 'ALLIED',
        'TMFB' => 'TELENOR', 'NBPA' => 'NATIONAL',
    ];

    /**
     * Do these two sets name the same bank? Aliases first, then a prefix test
     * (FAYS ⊂ FAYSAL). Anything else counts as disagreement, and disagreement
     * only ever costs us a question.
     */
    private function banksAgree(array $a, array $b): bool
    {
        $canon = fn(array $set) => array_values(array_unique(array_map(
            fn($t) => self::BANK_ALIASES[$t] ?? $t, $set)));
        $a = $canon($a); $b = $canon($b);

        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x === $y) return true;
                if (strlen($x) >= 3 && strlen($y) >= 3
                    && (str_starts_with($x, $y) || str_starts_with($y, $x))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The digits at the end of a masked account fragment, or null. */
    private function tailDigits(?string $s): ?string
    {
        if (!$s) {
            return null;
        }
        return preg_match('/(\d{3,})\s*$/', trim($s), $m) ? $m[1] : null;
    }

    /**
     * Bank identifiers visible in a hint or an account fragment, uppercased.
     * "myABL" → ABL; "MBL*2969" → MBL; "PK16FAYSXX564" → FAYS.
     */
    private function bankTokens(?string $hint, ?string $account): array
    {
        $out = [];
        $text = strtoupper(trim((string) $hint));
        if ($text !== '') {
            if (preg_match_all('/[A-Z]{3,6}/', $text, $m)) {
                foreach ($m[0] as $t) {
                    if ($t !== 'BANK' && $t !== 'LIMITED') {
                        $out[] = $t;
                    }
                }
            }
        }
        $acct = strtoupper(trim((string) $account));
        if ($acct !== '') {
            if (preg_match('/^PK\d{2}([A-Z]{4})/', $acct, $m)) {
                $out[] = $m[1];
            } elseif (preg_match('/^([A-Z]{2,6})[\*\s]/', $acct, $m)) {
                $out[] = $m[1];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Does the screenshot's name share a DISTINCTIVE token with the entity it
     * would resolve to? Reuses the payer-name resolver's corpus-derived notion
     * of "generic", so the two never disagree about what a name proves.
     */
    private function nameIsDistinctive(?string $name, ?string $entityName): bool
    {
        try {
            return app(\App\Services\Payments\Signals\PayerNameResolver::class)
                ->namesResemble($name, $entityName);
        } catch (\Throwable $e) {
            return false; // cannot check ⇒ do not claim a match
        }
    }

    private function describe(object $rule, ?string $key, string $how): array
    {
        return [
            'entity_type' => $rule->entity_type,
            'entity_id'   => (int) $rule->entity_id,
            'label'       => app(SmsCounterpartyMap::class)->entityName($rule),
            'matched_key' => $key,
            'how'         => $how,
        ];
    }
}
