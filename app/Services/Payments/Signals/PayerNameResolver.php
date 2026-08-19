<?php

namespace App\Services\Payments\Signals;

use App\Models\CRM\CustomerBankAlias;
use Illuminate\Support\Facades\DB;

/**
 * WHO SENT THIS MONEY? — the one place that turns a bank's payer name
 * ("HAFIZ NOUMAN SIDDIQUE", "S.KHAN") into an NF customer, or honestly says
 * it cannot tell.
 *
 * Everything here is READ-ONLY and deterministic. It never writes, never calls
 * an AI, and never returns a "best effort" — a resolution is either confident
 * enough to act on or it is null. The AI arbiter (a separate, last-resort step)
 * only ever runs on what this class refuses to answer.
 *
 * ── Why the ladder is ordered this way ─────────────────────────────────────
 *  1. LEARNED ALIAS (exact string). The strongest signal available, because a
 *     human already tied this exact bank string to this customer. It is also
 *     the ONLY thing that can identify a third-party payer — and 16% of real
 *     payments come from a name that looks nothing like the customer (husbands,
 *     relatives, staff). No amount of clever name-comparison can derive
 *     "Awais Chaudhry pays for Shaista Jahangir"; it can only be remembered.
 *  2. ALIAS TOKENS, then 3. CUSTOMER-NAME TOKENS. Fuzzy fallbacks for names the
 *     bank writes differently ("HAFIZ NOUMAN SIDDIQUE" vs "Nouman Siddique").
 *
 * ── Why the guards are strict ──────────────────────────────────────────────
 * The customer book is messy in ways that punish naive matching: 1,580
 * customers share a normalised name with someone else, and records like
 * "Mrs A", "Khan Khan" and "Ali Ali" exist. So:
 *   • single-character tokens are dropped, and a token match needs >= 2 real
 *     tokens to agree — otherwise "A.CHAUDHRY" resolves to a customer literally
 *     named "Mrs Chaudhry", which is how a correct match nearly got displaced
 *     by a wrong one during design;
 *   • honorifics are stripped, but SURNAMES ARE NOT — Malik, Chaudhry, Sheikh
 *     and Syed are real name parts here, and stripping them manufactures
 *     collisions;
 *   • a tie is never broken by guessing. If several customers fit, the open
 *     order set is allowed to break the tie (a payer with money outstanding is
 *     the plausible one); if that still leaves more than one, we return null.
 */
class PayerNameResolver
{
    /** Titles that carry no identity. Deliberately excludes real surnames. */
    private const TITLES = [
        'mr', 'mrs', 'ms', 'miss', 'dr', 'hafiz', 'engr', 'prof',
        'col', 'lt', 'maj', 'capt', 'mst', 'haji', 'shaheed',
    ];

    /**
     * Resolve a payer name to a customer.
     *
     * @param  string|null $payerName  the bank's sender name, as received
     * @param  float|null  $amount     used only to break ties, never to match
     * @param  string|null $paidAt     ditto — scopes "do they owe anything?"
     * @return array{customer_id:int, customer_name:string, tier:string, alias_id:int|null}|null
     */
    public function resolve(?string $payerName, ?float $amount = null, ?string $paidAt = null): ?array
    {
        $tokens = $this->tokens($payerName);
        if (empty($tokens) || $this->isBlacklisted($payerName)) {
            return null;
        }

        // 1. Exact learned alias. Single-token names ARE allowed here — "S.KHAN"
        //    is not a guess about a surname, it is the precise string a human
        //    confirmed. That is what makes it safe when the same string as a
        //    bare token match would not be.
        $hit = $this->byExactAlias($payerName, $amount, $paidAt);
        if ($hit) {
            return $hit;
        }

        // 2 & 3. Fuzzy, and therefore token-gated.
        $minTokens = (int) config('payment_signals.name_min_tokens', 2);
        if (count($tokens) < $minTokens) {
            return null;
        }

        return $this->byTokens($tokens, $amount, $paidAt);
    }

    /**
     * Would this payer name and this customer look like the same person to a
     * human? Used ONLY to annotate a match ("the payer name doesn't resemble
     * this customer — worth a glance"), NEVER to reject one: paying for someone
     * else is normal and common here.
     */
    public function namesResemble(?string $payerName, ?string $customerName): bool
    {
        $a = $this->tokens($payerName);
        $b = $this->tokens($customerName);
        if (empty($a) || empty($b)) {
            return true; // nothing to disagree about — don't cry wolf
        }
        if (array_intersect($this->fold($a), $this->fold($b))) {
            return true;
        }
        // Initials count as resemblance: "S.KHAN" vs "Sana Khan".
        return $this->initialsAgree($payerName, $customerName);
    }

    /** Is a learned alias already tying this exact payer name to this customer? */
    public function aliasExists(?string $payerName, int $customerId): bool
    {
        $norm = CustomerBankAlias::normaliseName((string) $payerName);
        if ($norm === '') {
            return false;
        }
        return CustomerBankAlias::query()
            ->where('customer_id', $customerId)
            ->whereRaw('LOWER(TRIM(bank_account_name)) = ?', [$norm])
            ->exists();
    }

    // ── ladder steps ────────────────────────────────────────────────────────

    private function byExactAlias(?string $payerName, ?float $amount, ?string $paidAt): ?array
    {
        $norm = CustomerBankAlias::normaliseName((string) $payerName);
        if ($norm === '') {
            return null;
        }

        $rows = CustomerBankAlias::query()
            ->whereRaw('LOWER(TRIM(bank_account_name)) = ?', [$norm])
            ->get(['id', 'customer_id']);
        if ($rows->isEmpty()) {
            return null;
        }

        $ids = $rows->pluck('customer_id')->unique()->values();
        // One string, several customers = a shared/company account or leftover
        // noise. Never pick a side.
        $chosen = $ids->count() === 1
            ? (int) $ids->first()
            : $this->breakTieByOpenOrders($ids->all(), $amount, $paidAt);
        if (!$chosen) {
            return null;
        }

        return $this->describe($chosen, 'alias_exact',
            (int) ($rows->firstWhere('customer_id', $chosen)->id ?? 0) ?: null);
    }

    private function byTokens(array $tokens, ?float $amount, ?string $paidAt): ?array
    {
        $folded = $this->fold($tokens);
        $minTokens = (int) config('payment_signals.name_min_tokens', 2);

        // Aliases first (real bank vocabulary), then customer names.
        foreach ([['alias_tokens', $this->aliasIndex()], ['name_tokens', $this->customerIndex()]] as [$tier, $index]) {
            $matches = [];
            foreach ($index as $entry) {
                $shared = array_intersect($entry['folded'], $folded);
                if (count($shared) < $minTokens) {
                    continue;
                }
                if (!$this->sharedTokensIdentify($shared, $entry['folded'], $folded)) {
                    continue;
                }
                $matches[$entry['customer_id']] = true;
            }
            $ids = array_keys($matches);
            if (count($ids) === 1) {
                return $this->describe((int) $ids[0], $tier, null);
            }
            if (count($ids) > 1) {
                $chosen = $this->breakTieByOpenOrders($ids, $amount, $paidAt);
                if ($chosen) {
                    return $this->describe($chosen, $tier, null);
                }
                // Ambiguous at this tier — a weaker tier cannot do better.
                return null;
            }
        }

        return null;
    }

    /**
     * Do the tokens these two names agree on actually NAME anybody?
     *
     * ⚠⚠ THE FAILURE THIS EXISTS FOR (Aug-2026). A Rs 2,250 credit from
     * "MUHAMMAD ASLAM KHAN" was resolved to **Adnan Khan**, whose confirmed
     * alias is "MUHAMMAD ADNAN KHAN". Two tokens agreed — MUHAMMAD and KHAN —
     * which cleared the >= 2 bar. But in this customer book KHAN appears in
     * 6.65% of names and MUHAMMAD in 5.61%; together they are barely more
     * identifying than "Mr". The one token that carried the identity, ASLAM
     * (0.61%) vs ADNAN (0.60%), is exactly the one that DISAGREED.
     *
     * So: at least one token they share must be distinctive. Frequency is
     * measured from the live corpus rather than hard-coded, because a name list
     * is a moving target and a stale constant would quietly rot.
     *
     * ⚠ This does NOT strip common surnames from matching — Malik, Khan, Syed
     * and Chaudhry are real name parts here and removing them would manufacture
     * collisions (a lesson already paid for). They still count; they just
     * cannot be the ONLY thing two names have in common.
     */
    private function sharedTokensIdentify(array $shared, array $candidate, array $payer): bool
    {
        $generic = $this->genericTokens();
        if (empty($generic)) {
            return true; // corpus too small to judge — behave as before
        }

        // Identical names agree on everything, so genericness is moot: "ALI
        // AHMED" matching "ALI AHMED" is a real match even though both tokens
        // are common. Only PARTIAL overlaps have to earn it.
        $a = array_values($candidate); sort($a);
        $b = array_values($payer);     sort($b);
        if ($a === $b) {
            return true;
        }

        foreach ($shared as $token) {
            if (!isset($generic[$token])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tokens common enough in our own customer/alias corpus that agreeing on
     * them proves nothing, as a [folded token => true] set. Computed from the
     * indexes already loaded for matching, so it costs no extra query.
     */
    private ?array $genericTokensCache = null;

    private function genericTokens(): array
    {
        if ($this->genericTokensCache !== null) {
            return $this->genericTokensCache;
        }

        $share = (float) config('payment_signals.name_generic_token_share', 0.02);
        $entries = array_merge($this->customerIndex(), $this->aliasIndex());
        $total = count($entries);

        // A thin corpus cannot support a frequency argument — every token would
        // look common. Judge nothing rather than judge wrongly.
        if ($share <= 0 || $total < 500) {
            return $this->genericTokensCache = [];
        }

        $freq = [];
        foreach ($entries as $entry) {
            foreach (array_unique($entry['folded']) as $t) {
                $freq[$t] = ($freq[$t] ?? 0) + 1;
            }
        }

        $generic = [];
        foreach ($freq as $token => $count) {
            if ($count / $total >= $share) {
                $generic[$token] = true;
            }
        }
        return $this->genericTokensCache = $generic;
    }

    /**
     * Several people fit the name. Prefer the one who actually owes money that
     * looks like this payment — a real payer almost always has an open order,
     * and the amount is a far better discriminator than the name was.
     * Returns null unless EXACTLY one survives.
     */
    private function breakTieByOpenOrders(array $customerIds, ?float $amount, ?string $paidAt): ?int
    {
        if (empty($customerIds)) {
            return null;
        }
        [$from, $to] = PaymentSignalMatcher::guessOrderDateBounds($paidAt);
        $tol = PaymentProofStatusService::amountTolerance();

        $q = DB::table('t_crm_prod_order')
            ->whereIn('customer_id', $customerIds)
            ->whereIn('payment_status', config('payment_signals.open_payment_statuses', ['unpaid', 'partial']))
            ->where('order_status', '!=', 'cancelled')
            ->whereRaw('(total_price - COALESCE(total_paid, 0)) > 0.01')
            ->whereBetween('order_date', [$from, $to]);

        // With an amount, insist the outstanding balance actually resembles it.
        if ($amount !== null && $amount > 0) {
            $q->whereRaw('ABS((total_price - COALESCE(total_paid, 0)) - ?) <= ?', [$amount, $tol]);
        }

        $ids = $q->distinct()->limit(5)->pluck('customer_id')->unique();
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    // ── indexes (built per request; these tables are small) ──────────────────

    private ?array $aliasIndexCache = null;
    private ?array $customerIndexCache = null;

    private function aliasIndex(): array
    {
        if ($this->aliasIndexCache !== null) {
            return $this->aliasIndexCache;
        }
        $out = [];
        foreach (DB::table('t_crm_customer_bank_alias')->get(['customer_id', 'bank_account_name']) as $r) {
            if ($this->isBlacklisted($r->bank_account_name)) {
                continue;
            }
            $t = $this->tokens($r->bank_account_name);
            if (count($t) < (int) config('payment_signals.name_min_tokens', 2)) {
                continue;
            }
            $out[] = ['customer_id' => (int) $r->customer_id, 'folded' => $this->fold($t)];
        }
        return $this->aliasIndexCache = $out;
    }

    private function customerIndex(): array
    {
        if ($this->customerIndexCache !== null) {
            return $this->customerIndexCache;
        }
        $out = [];
        $rows = DB::table('t_crm_prod_customer')
            ->where(function ($w) {
                $w->whereNull('customer_type')->orWhere('customer_type', '!=', 'shop');
            })
            ->get(['id', 'first_name', 'last_name']);
        foreach ($rows as $r) {
            $t = $this->tokens(trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')));
            if (count($t) < (int) config('payment_signals.name_min_tokens', 2)) {
                continue;
            }
            $out[] = ['customer_id' => (int) $r->id, 'folded' => $this->fold($t)];
        }
        return $this->customerIndexCache = $out;
    }

    // ── text helpers ────────────────────────────────────────────────────────

    /** Real name tokens: lowercase, punctuation-free, de-titled, deduped. */
    private function tokens(?string $name): array
    {
        $s = mb_strtolower(trim((string) $name));
        if ($s === '') {
            return [];
        }
        $s = preg_replace('/[^a-z\s\.\-]/', ' ', $s);
        $parts = preg_split('/[\s\.\-]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $minLen = (int) config('payment_signals.name_min_token_len', 3);
        $parts = array_filter($parts, function ($p) use ($minLen) {
            return mb_strlen($p) >= $minLen && !in_array($p, self::TITLES, true);
        });

        return array_values(array_unique($parts));
    }

    /**
     * Fold spelling variants so Siddique / Siddiqui / Siddiqi agree — all three
     * spellings of the same family exist in this customer book.
     */
    private function fold(array $tokens): array
    {
        return array_values(array_unique(array_map(function ($t) {
            $t = preg_replace('/(ue|ui|i|e|a|y)$/', '', $t);
            return strtr($t, ['ee' => 'i', 'ii' => 'i', 'aa' => 'a', 'uu' => 'u', 'kh' => 'k', 'gh' => 'g']);
        }, $tokens)));
    }

    private function isBlacklisted(?string $name): bool
    {
        $s = mb_strtolower(trim((string) $name));
        if ($s === '') {
            return true;
        }
        foreach ((array) config('payment_signals.name_blacklist', []) as $bad) {
            if ($s === $bad || str_contains($s, (string) $bad)) {
                return true;
            }
        }
        return false;
    }

    /** "S.KHAN" vs "Sana Khan" — an initial standing in for a first name. */
    private function initialsAgree(?string $payerName, ?string $customerName): bool
    {
        $raw = preg_split('/[\s\.\-]+/', mb_strtolower(trim((string) $payerName)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = array_filter($raw, fn ($p) => mb_strlen($p) === 1);
        if (empty($initials)) {
            return false;
        }
        $surnamesShared = array_intersect($this->fold($this->tokens($payerName)), $this->fold($this->tokens($customerName)));
        if (empty($surnamesShared)) {
            return false;
        }
        foreach ($this->tokens($customerName) as $ct) {
            foreach ($initials as $i) {
                if (mb_substr($ct, 0, 1) === $i) {
                    return true;
                }
            }
        }
        return false;
    }

    private function describe(int $customerId, string $tier, ?int $aliasId): ?array
    {
        $c = DB::table('t_crm_prod_customer')->where('id', $customerId)
            ->first(['id', 'first_name', 'last_name', 'customer_type']);
        if (!$c) {
            return null;
        }
        // Shop money never travels the proof/approvals road (their invoices are
        // not in the queue at all) — resolving one here would strand the credit.
        if (($c->customer_type ?? 'regular') === 'shop') {
            return null;
        }

        return [
            'customer_id'   => (int) $c->id,
            'customer_name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
            'tier'          => $tier,
            'alias_id'      => $aliasId,
        ];
    }
}
