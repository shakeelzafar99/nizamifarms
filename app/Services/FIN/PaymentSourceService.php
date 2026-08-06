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
 * 1. TAGS DECIDE, EVERYWHERE (ruling revised Aug-6-2026). An account you are
 *    tagged on in `t_fin_account_users` may fund a request filed against ANY
 *    business unit your role can reach — the request's own business unit stamps
 *    the books (LedgerPostingService writes the REQUEST's unit; vendor payments
 *    the VENDOR's), so where the money physically left changes no report. This
 *    replaced one day of "only accounts flagged is_shared_across_bu cross" —
 *    the owner then ruled the flag redundant: being tagged IS the access
 *    ("Qasim can pay maintenance from the khaas account he has access to").
 *    A borrowed row is labelled with its home unit and sorts after the unit's own.
 * 2. TAGS DECIDE, not the permission. `t_fin_account_users` says who may pay from
 *    what, per purpose. `expense_all_payment_sources` survives only as a safety
 *    fallback for a user who has no tags at all (see resolve() below) — and that
 *    fallback stays UNIT-SCOPED like the pre-tag world it preserves: crossing is
 *    for the explicitly tagged, never for "sees everything".
 * 3. The DEFAULT is a per-user, per-business-unit star, stored as data. The old
 *    rule was hardcoded here: `isTaimurRole ? ONLINE : EXP_FUND`. A borrowed
 *    account's star counts only in its HOME unit (see defaultAccountId).
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

        // Names for the units a cross-unit account is being borrowed FROM, so the
        // row can say so. One query, and only when there is something to label —
        // "Online Bank" alone in a Frozen picker looks like Frozen has its own.
        $foreignBuNames = [];
        $foreignBuIds = $accounts->pluck('business_unit_id')->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v !== $bu)->unique()->values();
        if ($foreignBuIds->isNotEmpty()) {
            try {
                $foreignBuNames = \App\Models\FIN\BusinessUnitModel::whereIn('id', $foreignBuIds)
                    ->pluck('name', 'id')->all();
            } catch (\Throwable $e) {
                $foreignBuNames = [];                       // a label, never a blocker
            }
        }

        $out = $accounts->map(function (AccountModel $a) use ($bankIds, $defaultId, $tagsByAccount, $expenseFundId, $bu, $foreignBuNames) {
            $tag = $tagsByAccount[$a->id] ?? null;
            // Only a tagged account can appear outside its own unit (resolve()).
            $crossBu = (int) $a->business_unit_id !== $bu;
            $borrowedFrom = $crossBu ? ($foreignBuNames[(int) $a->business_unit_id] ?? null) : null;
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
                // for the fund; everything else uses its real name. A borrowed
                // account names its home unit — carried in display_name rather than
                // a new field on purpose, so every existing picker (web Blade,
                // mobile, the approve strips) shows it with no client change and
                // no APK.
                'display_name'     => (($expenseFundId && $a->id === $expenseFundId) ? 'Exp Fund' : $a->account_name)
                                      . ($borrowedFrom ? ' (' . $borrowedFrom . ')' : ''),
                'balance'          => (float) $a->current_balance,
                // The account's OWN books. For a borrowed shared account this is
                // NOT the unit being filed against — use for_business_unit_id for
                // "which picker does this row belong in", or a Frozen row keyed on
                // this one silently files itself back under Nizami Farms.
                'business_unit_id' => $a->business_unit_id,
                'for_business_unit_id' => $bu,
                'is_default'       => ($a->id === $defaultId),
                'is_online'        => isset($bankIds[(int) $a->id]),
                'is_tagged'        => (bool) $tag,
                'is_shared'        => $crossBu,
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
     *   1. The user's role must reach the unit being FILED at all.
     *   2. TAGGED users get their tagged accounts — from EVERY unit, not just the
     *      one being filed (ruling revised Aug-6-2026). The request's own unit
     *      stamps the books, so a khaas-tagged account funding an NF maintenance
     *      claim changes no report — only which balance the money left. Untagging
     *      is still how the owner hides an account: nothing is added back after.
     *   3. Only if he has NO tags for this purpose do we fall back, and then to
     *      exactly what this user saw before batch 10 shipped: everything IN THIS
     *      UNIT for a holder of expense_all_payment_sources, otherwise the Expense
     *      Fund alone (or the unit's configured default account outside Nizami
     *      Farms). The fallback never crosses units — crossing is a tag privilege.
     *
     * That last branch is why an un-migrated or un-tagged production behaves
     * identically to today rather than locking everyone out.
     */
    private function resolve($user, int $bu, string $purpose): array
    {
        // ⚠ The ROLE's business-unit access gates the unit being FILED. A tag
        // cannot grant the right to file against Frozen/Khaas on its own — if it
        // could, tagging would quietly become a second, weaker permission system
        // for books the user cannot otherwise open. (What a tag DOES grant, since
        // Aug-6-2026, is funding: a reachable unit's request may be paid from a
        // tagged account that lives elsewhere.) Before Aug-2026 an unreachable
        // unit still handed back that unit's configured default account, so a
        // crafted business_unit_id got a fundable account out of the server;
        // returning nothing closes that.
        if (!$this->buIsReachable($bu)) {
            return [collect(), []];
        }

        $tags = $this->tagsFor($user, $purpose);

        if ($tags->isEmpty()) {
            return [$this->fallback($user, $bu, $this->companyAccountsIn($bu, $user)), []];
        }

        // Every account this user is tagged on, whatever unit it lives in:
        // company cash + bank anywhere, and an employee-cash account only if it
        // is HIS OWN (those exist for holding company cash rather than spending
        // it, so they are never offered unless explicitly tagged — owner ruling,
        // Aug-2026). `visibleTo` keeps private accounts for Taimur. Sorted so the
        // unit's own accounts lead and borrowed ones follow, each alphabetical —
        // a Frozen picker starts with Frozen's money.
        $tagged = AccountModel::where('is_active', 1)
            ->whereIn('id', $tags->keys()->all())
            ->where(function ($q) use ($user) {
                $q->whereIn('account_category', [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK])
                  ->orWhere(function ($own) use ($user) {
                      $own->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
                          ->where('user_id', $user->id);
                  });
            })
            ->visibleTo($user)
            ->get()
            ->sortBy(fn ($a) => ((int) $a->business_unit_id === $bu ? '0|' : '1|') . mb_strtolower($a->account_name))
            ->values();

        if ($tagged->isEmpty()) {
            // Tags exist but none survive — every tagged account deactivated or
            // out of category. Give the unit's baseline, not everything.
            return [$this->fallback($user, $bu, $this->companyAccountsIn($bu, $user), true), []];
        }

        $byAccount = [];
        foreach ($tagged as $a) {
            $byAccount[$a->id] = $tags->get($a->id);
        }

        return [$tagged, $byAccount];
    }

    /** The unit's own company cash + bank accounts — the untagged fallback's world. */
    private function companyAccountsIn(int $bu, $user)
    {
        return AccountModel::where('is_active', 1)
            ->where('business_unit_id', $bu)
            ->whereIn('account_category', [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK])
            ->visibleTo($user)
            ->orderBy('account_name')
            ->get();
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
        foreach ($accounts as $a) {
            $tag = $tagsByAccount[$a->id] ?? null;
            // ⚠ …and the star must belong to THIS unit. Tagged accounts now appear
            // in every unit's picker, but a star is per-unit data
            // (AccountUserModel::setDefault clears siblings by the ACCOUNT's
            // unit), so without this test starring Online Bank in Nizami Farms
            // would silently re-point Frozen's default at it too, and every
            // Frozen expense would default to the wrong books' money. In its home
            // unit it stars exactly as before.
            if ($tag && $tag->is_default && (int) $a->business_unit_id === $bu) {
                return (int) $a->id;
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
