<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\AccountUserModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\OnlineReceivingAccountModel;
use Illuminate\Support\Facades\Schema;

/**
 * "Which account is this money coming out of?" — ONE answer for every screen.
 *
 * Before this class the same question was answered in six different places (the
 * mobile expense endpoint, the web Expense page's Blade, the request-detail Blade,
 * the Fleet approval strip, the Daily Closing approve rows, and the asset form),
 * and they did NOT agree — different lists, and different defaults on the create
 * side (Expense Fund) versus the approve side (NF Cash). A picker that offers an
 * account the server will reject, or silently substitutes a different account than
 * the one that was filed, is worse than no picker at all. So new surfaces ask here.
 *
 * ── The model (owner rulings, Aug-2026) ────────────────────────────────────────
 * 1. BUSINESS UNIT SCOPES FIRST, for everybody. Khaas accounts appear only in a
 *    Khaas context, Nizami Farms accounts only in an NF context. Before this,
 *    Taimur and Shabib were offered "khaas taimur" and "NF food" while filing an
 *    ordinary NF petrol claim, and the request was stamped BU 1 regardless.
 * 2. TAGS DECIDE, not the permission. `t_fin_account_users` says who may pay from
 *    what, per purpose. `expense_all_payment_sources` survives only as a safety
 *    fallback for a user who has no tags at all (see resolve() below).
 * 3. The DEFAULT is a per-user, per-business-unit star, stored as data. The old
 *    rule was hardcoded here: `isTaimurRole ? ONLINE : EXP_FUND`.
 *
 * ⚠ QURBANI: QURBANI_CASH / QURBANI_ONLINE live in business unit 1 BY DESIGN, so
 *   business-unit scoping alone can never keep them out of an NF expense picker.
 *   They are excluded by UNTAGGING them, never by moving their business unit —
 *   other reports exclude them by account CODE and would break.
 *
 * The rules encoded below are exactly the ones RequestController::store() and
 * RiderController::createRequest() ENFORCE on submit — that symmetry is the whole
 * point. Both call allows() here rather than re-implementing anything.
 */
class PaymentSourceService
{
    public const PURPOSE_EXPENSE = AccountUserModel::PURPOSE_EXPENSE;
    public const PURPOSE_VENDOR  = AccountUserModel::PURPOSE_VENDOR;
    public const PURPOSE_ADVANCE = AccountUserModel::PURPOSE_ADVANCE;

    /** Nizami Farms. Anything else is a separate books (Khaas/Frozen). */
    private const NF_BU = 1;

    // Deliberately NOT memoised. The Ledger Hub saves tags and re-reads the
    // resulting list in the same request, and a cached answer there would show the
    // owner the state BEFORE his own edit. The queries are two small indexed reads.

    /**
     * The accounts $user may legitimately fund $purpose from, in $businessUnitId.
     *
     * ⚠ CURRENT-USER ONLY. `$user` reads naturally, but the business-unit filter
     * underneath (AccountModel::getUserAccessibleBusinessUnits) resolves the user
     * from auth() and ignores what it is handed — so asking this for somebody OTHER
     * than the logged-in user silently returns the caller's own accounts. Pass
     * auth()->user(). The warning below exists so a future caller that forgets finds
     * out from the log instead of from a mis-booked expense.
     *
     * @param  \App\Models\User|null $user
     * @param  int|null $businessUnitId  null / 1 = Nizami Farms, 2 = Khaas.
     * @param  string   $purpose         expense | vendor | advance
     * @return array<int, array<string, mixed>>
     */
    public function sourcesFor($user, ?int $businessUnitId = null, string $purpose = self::PURPOSE_EXPENSE): array
    {
        if (!$user) {
            return [];
        }

        $authUser = auth()->user();
        if ($authUser && (int) $authUser->id !== (int) $user->id) {
            \Log::warning('PaymentSourceService asked for a non-authenticated user; BU filtering will follow the logged-in user instead.', [
                'asked_for' => $user->id,
                'auth_user' => $authUser->id,
            ]);
        }

        $bu = $businessUnitId ?: self::NF_BU;

        [$accounts, $tagsByAccount] = $this->resolve($user, $bu, $purpose);

        // Which accounts are banks? A bank source forces a receiving-bank pick.
        // Resolved in one query, not N.
        $bankIds = $accounts->isEmpty()
            ? collect()
            : AccountModel::whereIn('id', $accounts->pluck('id'))
                ->where('account_category', AccountModel::CATEGORY_BANK)
                ->pluck('id')->map(fn ($v) => (int) $v)->flip();

        $defaultId = $this->defaultAccountId($user, $bu, $accounts, $tagsByAccount);

        $expenseFundId = optional($this->expenseFund())->id;

        $out = $accounts->map(function (AccountModel $a) use ($bankIds, $defaultId, $tagsByAccount, $expenseFundId) {
            $tag = $tagsByAccount[$a->id] ?? null;
            return [
                'id'               => $a->id,
                'code'             => $a->account_code,
                'name'             => $a->account_name,
                // ⚠ BACKWARD-COMPAT ALIAS — do not remove. The Bikes approve
                // dialog in every APK already in the field reads `account_name`
                // (the old hardcoded list returned raw account rows). Web files
                // deploy BEFORE any APK, so dropping this would show "undefined"
                // as the account name on installed apps until they update.
                'account_name'     => $a->account_name,
                // "Exp Fund" is the label the mobile expense form has always shown
                // for the fund; everything else uses its real name.
                'display_name'     => ($expenseFundId && $a->id === $expenseFundId) ? 'Exp Fund' : $a->account_name,
                'balance'          => (float) $a->current_balance,
                'business_unit_id' => $a->business_unit_id,
                'is_default'       => ($a->id === $defaultId),
                'is_online'        => isset($bankIds[(int) $a->id]),
                'is_tagged'        => (bool) $tag,
                'preferred_bank_id'=> $tag->preferred_bank_id ?? null,
            ];
        })->values()->all();

        // Defensive: if the star fell on an account the user cannot see (a stale
        // tag, or ONLINE deactivated), promote the first option rather than
        // pre-selecting nothing and letting a blank submit through.
        if ($out && !array_filter($out, fn ($s) => $s['is_default'])) {
            $out[0]['is_default'] = true;
        }

        return $out;
    }

    /**
     * May $user fund $purpose from account $accountId in $businessUnitId?
     * The submit-side gate. Deliberately re-derives the list rather than trusting
     * anything the form sent.
     */
    public function allows($user, $accountId, ?int $businessUnitId = null, string $purpose = self::PURPOSE_EXPENSE): bool
    {
        if (!$user || !$accountId) {
            return false;
        }
        foreach ($this->sourcesFor($user, $businessUnitId, $purpose) as $s) {
            if ((int) $s['id'] === (int) $accountId) {
                return true;
            }
        }
        return false;
    }

    /**
     * The banks an ONLINE payment can be attributed to, each with its computed
     * balance so the user sees what actually sits in the bank they're paying from.
     * Per-bank attribution is mandatory for bank sources — see
     * RequestController::store() and RequestApprovalController::approve().
     *
     * @return array<int, array<string, mixed>>
     */
    public function banks(): array
    {
        try {
            $balances = app(BankBalanceService::class)->balancesByBank();
        } catch (\Throwable $e) {
            $balances = [];                              // balances are a nicety
        }

        return OnlineReceivingAccountModel::active()->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex', 'opening_balance'])
            ->map(fn ($acc) => [
                'id'         => $acc->id,
                'name'       => $acc->name,
                'short_code' => $acc->short_code,
                'color_hex'  => $acc->color_hex,
                'balance'    => $balances[(int) $acc->id]['balance'] ?? (float) $acc->opening_balance,
            ])
            ->toArray();
    }

    /**
     * Legacy "can this user see more than the Expense Fund" flag.
     *
     * Kept because the mobile expense screen still ships it in its payload and uses
     * it to decide whether to render the picker at all. It no longer BUILDS the
     * list — sourcesFor() does, from tags.
     */
    public function canUseAllSources($user, ?int $businessUnitId = null): bool
    {
        if (!$user || !method_exists($user, 'hasMobilePermission')) {
            return false;
        }
        $isNonNfBu = $businessUnitId && (int) $businessUnitId !== self::NF_BU;

        return $user->hasMobilePermission('expense_all_payment_sources')
            || ($isNonNfBu && $user->hasMobilePermission('approve_khaas_transfer'));
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * The heart of it. Returns [Collection<AccountModel>, array<accountId, tagRow>].
     *
     * Order matters:
     *   1. Business unit scopes the candidates (and the user must be able to reach
     *      that unit at all).
     *   2. Tags filter them. If the user has ANY tag for this purpose in this unit,
     *      that IS the list — untagging is how the owner hides an account he never
     *      uses, so nothing may be added back afterwards.
     *   3. Only if he has NO tags anywhere do we fall back, and then to exactly
     *      what this user saw before batch 10 shipped: everything for a holder of
     *      expense_all_payment_sources, otherwise the Expense Fund alone (or the
     *      unit's configured default account outside Nizami Farms).
     *
     * That last branch is why an un-migrated or un-tagged production behaves
     * identically to today rather than locking everyone out.
     */
    private function resolve($user, int $bu, string $purpose): array
    {
        // ⚠ The ROLE's business-unit access gates everything, tags included. A tag
        // cannot grant reach into Frozen/Khaas on its own — if it could, tagging
        // would quietly become a second, weaker permission system for books the
        // user cannot otherwise open. Before Aug-2026 an unreachable unit still
        // handed back that unit's configured default account, so a crafted
        // business_unit_id got a fundable account out of the server; returning
        // nothing closes that.
        // (The Hub tag panel warns when a tagged user's role cannot reach the
        // account's unit, so this never looks like the tag silently failing.)
        if (!$this->buIsReachable($bu)) {
            return [collect(), []];
        }

        // Company accounts in this unit. `visibleTo` keeps private accounts for Taimur.
        $companyCandidates = AccountModel::where('is_active', 1)
            ->where('business_unit_id', $bu)
            ->whereIn('account_category', [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK])
            ->visibleTo($user)
            ->orderBy('account_name')
            ->get();

        $tags = $this->tagsFor($user, $purpose);

        if ($tags->isEmpty()) {
            return [$this->fallback($user, $bu, $companyCandidates), []];
        }

        // A manager may fund from an employee-cash account only if it is HIS OWN.
        // These accounts exist for holding company cash rather than spending it, so
        // they are never offered unless explicitly tagged (owner ruling, Aug-2026).
        $ownCash = AccountModel::where('is_active', 1)
            ->where('business_unit_id', $bu)
            ->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
            ->where('user_id', $user->id)
            ->visibleTo($user)
            ->get();

        $candidates = $companyCandidates->concat($ownCash);
        $tagged     = $candidates->filter(fn ($a) => $tags->has($a->id))->values();

        if ($tagged->isEmpty()) {
            // Tagged elsewhere but not in this unit — e.g. Taimur untagged both
            // Khaas accounts. Give the unit's baseline, not everything.
            return [$this->fallback($user, $bu, $companyCandidates, true), []];
        }

        $byAccount = [];
        foreach ($tagged as $a) {
            $byAccount[$a->id] = $tags->get($a->id);
        }

        return [$tagged, $byAccount];
    }

    /**
     * Pre-batch-10 behaviour, preserved exactly.
     *
     * @param bool $configuredElsewhere True when the user HAS tags, just none here —
     *                                  then the "sees everything" holder fallback
     *                                  must not apply, or untagging a unit would
     *                                  silently restore the full list for it.
     */
    private function fallback($user, int $bu, $companyCandidates, bool $configuredElsewhere = false)
    {
        if (!$configuredElsewhere && $this->canUseAllSources($user, $bu === self::NF_BU ? null : $bu)) {
            return $companyCandidates;
        }

        if ($bu !== self::NF_BU) {
            $buDefault = ConfigModel::getBuDefaultExpenseAccount($bu);
            return $buDefault ? collect([$buDefault]) : collect();
        }

        $fund = $this->expenseFund();
        return $fund ? collect([$fund]) : collect();
    }

    /** This user's tags for one purpose, keyed by account id. */
    private function tagsFor($user, string $purpose)
    {
        if (!$this->tagsTableExists()) {
            return collect();                 // batch 10 not run yet → legacy path
        }

        $column = AccountUserModel::columnForPurpose($purpose);
        if (!$column) {
            \Log::warning('PaymentSourceService: unknown purpose, falling back to legacy list.', ['purpose' => $purpose]);
            return collect();
        }

        return AccountUserModel::where('user_id', $user->id)
            ->where($column, 1)
            ->get()
            ->keyBy('account_id');
    }

    /**
     * The pre-selected account: the user's star for this unit if it is in the list,
     * else the pre-batch-10 rule (Taimur → Online Bank, everyone else → the fund),
     * else nothing (the caller promotes the first row).
     */
    private function defaultAccountId($user, int $bu, $accounts, array $tagsByAccount): ?int
    {
        foreach ($tagsByAccount as $accountId => $tag) {
            if ($tag && $tag->is_default) {
                return (int) $accountId;
            }
        }

        if ($bu !== self::NF_BU) {
            return optional(ConfigModel::getBuDefaultExpenseAccount($bu))->id;
        }

        $isTaimurRole = $user->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();

        if ($isTaimurRole) {
            $online = AccountModel::where('account_code', 'ONLINE')->where('is_active', 1)->first();
            if ($online && $accounts->contains('id', $online->id)) {
                return $online->id;
            }
        }

        return optional($this->expenseFund())->id;
    }

    private function expenseFund(): ?AccountModel
    {
        return ConfigModel::getExpenseFundingAccount()
            ?? AccountModel::where('account_code', 'EXP_FUND')->first();
    }

    /** Can this user reach this business unit at all? */
    private function buIsReachable(int $bu): bool
    {
        try {
            return AccountModel::getUserAccessibleBusinessUnits()
                ->contains(fn ($b) => (int) $b->id === $bu);
        } catch (\Throwable $e) {
            return true;      // BU columns missing → don't lock anyone out
        }
    }

    /**
     * Guarded so the code can be uploaded before batch 10 is run without a 500.
     * Cached per request — Schema::hasTable is a real query.
     */
    private ?bool $tagsTableExists = null;

    private function tagsTableExists(): bool
    {
        if ($this->tagsTableExists === null) {
            try {
                $this->tagsTableExists = Schema::hasTable('t_fin_account_users');
            } catch (\Throwable $e) {
                $this->tagsTableExists = false;
            }
        }
        return $this->tagsTableExists;
    }

    /**
     * Tag every current holder of `expense_all_payment_sources` onto a newly
     * created company account, all purposes.
     *
     * Without this, "tagged to all" would be true only of the accounts that existed
     * the day batch 10 ran: the owner creates a new company cash account, and the
     * two people who are supposed to see everything cannot see it — with no error
     * to explain why. Called from AccountModel's created event. Best-effort: a
     * failure here must never break account creation.
     */
    public static function autoTagNewAccount(AccountModel $account): void
    {
        try {
            if (!in_array($account->account_category, [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK], true)) {
                return;                        // employee-cash / vendor / expense: never auto-tagged
            }
            if (!Schema::hasTable('t_fin_account_users')) {
                return;
            }

            $userIds = \DB::table('t_sys_user_role as ur')
                ->whereIn('ur.role_id', function ($q) {
                    $q->select('rmp.role_id')
                      ->from('t_sys_role_mobile_permission as rmp')
                      ->join('t_sys_mobile_permission as mp', 'mp.id', '=', 'rmp.mobile_permission_id')
                      ->where('mp.permission_code', 'expense_all_payment_sources')
                      ->where('mp.is_active', 1);
                })
                ->distinct()
                ->pluck('ur.user_id');

            foreach ($userIds as $uid) {
                AccountUserModel::firstOrCreate(
                    ['account_id' => $account->id, 'user_id' => (int) $uid],
                    [
                        'is_default'  => 0,
                        'can_expense' => 1,
                        'can_vendor'  => 1,
                        'can_advance' => 1,
                        'created_by'  => auth()->id(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('autoTagNewAccount failed (non-fatal)', [
                'account_id' => $account->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
