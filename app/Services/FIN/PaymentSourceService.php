<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\OnlineReceivingAccountModel;

/**
 * "Which account is this money coming out of?" — ONE answer for every screen.
 *
 * Before this class the same question was answered in four different places
 * (the mobile expense endpoint, the web Expense page's Blade, the Fleet
 * approval strip, and the Daily Closing approve row), and they did NOT agree:
 *   - the mobile endpoint hid everything but Exp Fund from a user without
 *     `expense_all_payment_sources`;
 *   - the web Expense page listed EVERY accessible company account to everyone
 *     and let the server 403 the submit;
 *   - Fleet/Daily Closing hardcoded four account codes and defaulted to NF Cash.
 * A picker that offers an account the server will reject is worse than no
 * picker, so new surfaces must ask here.
 *
 * The rules encoded below are exactly the ones RequestController::store() and
 * RiderController::createRequest() ENFORCE on submit — that symmetry is the
 * whole point. If you change a rule here, change it in both of those too.
 */
class PaymentSourceService
{
    /**
     * The accounts $user may legitimately fund an expense from.
     *
     * ⚠ CURRENT-USER ONLY. `$user` reads naturally, but the business-unit filter
     * underneath (AccountModel::getAccessibleCompanyAccounts) resolves the user
     * from auth() and ignores what it is handed — so asking this for somebody
     * OTHER than the logged-in user silently returns the caller's own accounts.
     * Pass auth()->user(). The warning below exists so a future caller that
     * forgets finds out from the log instead of from a mis-booked expense.
     *
     * @param  \App\Models\User|null $user
     * @param  int|null $businessUnitId  Khaas etc. BU 1 / null = Nizami Farms.
     * @return array<int, array<string, mixed>>
     */
    public function sourcesFor($user, ?int $businessUnitId = null): array
    {
        if (!$user) {
            return [];
        }

        $authUser = auth()->user();
        if ($authUser && $authUser->id !== $user->id) {
            \Log::warning('PaymentSourceService asked for a non-authenticated user; BU filtering will follow the logged-in user instead.', [
                'asked_for' => $user->id,
                'auth_user' => $authUser->id,
            ]);
        }

        // In a non-NF business unit, the Khaas approver right stands in for the
        // expense permission — same substitution the submit-side check makes.
        $isNonNfBu = $businessUnitId && (int) $businessUnitId !== 1;
        $canUseAll = $this->canUseAllSources($user, $businessUnitId);

        $isTaimurRole = $user->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();

        $sources = [];

        if ($isNonNfBu) {
            // BU-specific mode. A regular user gets exactly the one account the
            // BU is configured to spend from; an admin gets the whole BU.
            $defaultBuAccount = ConfigModel::getBuDefaultExpenseAccount((int) $businessUnitId);
            $defaultAccountId = $defaultBuAccount->id ?? null;

            if ($canUseAll) {
                $accounts = AccountModel::where('is_active', 1)
                    ->where('business_unit_id', $businessUnitId)
                    ->visibleTo($user)
                    ->whereNotIn('account_category', [
                        AccountModel::CATEGORY_EMPLOYEE_CASH,
                        AccountModel::CATEGORY_VENDOR_PAYABLE,
                    ])
                    ->orderBy('account_name')
                    ->get();
            } else {
                $accounts = $defaultBuAccount ? collect([$defaultBuAccount]) : collect();
            }

            foreach ($accounts as $account) {
                $sources[] = $this->shape($account, $defaultAccountId, $account->account_name);
            }
        } else {
            // Standard Nizami Farms mode.
            $expenseFund = ConfigModel::getExpenseFundingAccount()
                ?? AccountModel::where('account_code', 'EXP_FUND')->first();

            // Taimur spends from ONLINE by default; everyone else from the fund.
            // ⚠ This is the ONLY place the default is decided — a per-user
            // default account (owner request, Aug-2026) replaces this line and
            // nothing else.
            $onlineAccount = $isTaimurRole
                ? AccountModel::where('account_code', 'ONLINE')->where('is_active', 1)->first()
                : null;

            $defaultAccountId = ($isTaimurRole && $onlineAccount)
                ? $onlineAccount->id
                : ($expenseFund->id ?? null);

            if ($expenseFund) {
                // "Exp Fund" not the full account name — this is the label the
                // mobile expense form has always shown.
                $sources[] = $this->shape($expenseFund, $defaultAccountId, 'Exp Fund');
            }

            if ($canUseAll) {
                foreach (AccountModel::getAccessibleCompanyAccounts() as $account) {
                    if ($expenseFund && $account->id === $expenseFund->id) {
                        continue;                       // already listed above
                    }
                    if ($account->is_private && !$isTaimurRole) {
                        continue;                       // Taimur-only accounts
                    }
                    $sources[] = $this->shape($account, $defaultAccountId, $account->account_name);
                }
            }
        }

        // A bank source forces a receiving-bank pick, so the form has to know
        // which of these are banks. Resolved in one query, not N.
        if ($sources) {
            $bankIds = AccountModel::whereIn('id', array_column($sources, 'id'))
                ->where('account_category', AccountModel::CATEGORY_BANK)
                ->pluck('id')->map(fn ($v) => (int) $v)->flip();
            foreach ($sources as &$s) {
                $s['is_online'] = isset($bankIds[(int) $s['id']]);
            }
            unset($s);
        }

        // Defensive: if the default fell on an account the user cannot see (a
        // stale config, or ONLINE deactivated), promote the first option rather
        // than pre-selecting nothing and letting a blank submit through.
        if ($sources && !array_filter($sources, fn ($s) => $s['is_default'])) {
            $sources[0]['is_default'] = true;
        }

        return $sources;
    }

    /**
     * The banks an ONLINE expense can be attributed to, each with its computed
     * balance so the user sees what actually sits in the bank they're paying
     * from. Per-bank attribution is mandatory for bank sources — see
     * RequestController::store().
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
     * Whether this user may fund an expense from anything other than Exp Fund.
     * Mirrors the submit-side gate in RequestController::store().
     */
    public function canUseAllSources($user, ?int $businessUnitId = null): bool
    {
        if (!$user || !method_exists($user, 'hasMobilePermission')) {
            return false;
        }
        $isNonNfBu = $businessUnitId && (int) $businessUnitId !== 1;

        return $user->hasMobilePermission('expense_all_payment_sources')
            || ($isNonNfBu && $user->hasMobilePermission('approve_khaas_transfer'));
    }

    private function shape(AccountModel $account, $defaultAccountId, string $displayName): array
    {
        return [
            'id'               => $account->id,
            'code'             => $account->account_code,
            'name'             => $account->account_name,
            'display_name'     => $displayName,
            'balance'          => (float) $account->current_balance,
            'business_unit_id' => $account->business_unit_id,
            'is_default'       => ($account->id === $defaultAccountId),
            'is_online'        => false,                 // filled in by the caller above
        ];
    }
}
