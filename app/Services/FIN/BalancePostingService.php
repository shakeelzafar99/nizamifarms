<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use Illuminate\Support\Facades\DB;

/**
 * Ledger L3 — the ONE place allowed to move t_fin_accounts.current_balance.
 *
 * Replaces the three conflicting sign conventions (LedgerPostingService "from−/to+",
 * LedgerController approve/reject "asset-aware", QurbaniWebController "from+/to−") with a
 * single, economically-correct, double-entry rule, applied idempotently and tracked by
 * `balance_updated` so that flag finally means "currently applied".
 *
 * THE ONE CONVENTION (derived + verified against the primary postings, 2026-07-12):
 *   debit-arithmetic accounts  (asset, expense, income*) : TO +amount, FROM −amount
 *   credit-arithmetic accounts (liability, equity)       : TO −amount, FROM +amount
 *   (* income is credit-normal but STORED negative in this system, so arithmetically it
 *      behaves like a debit account — a sale posts revenue −amount = "more negative".)
 * This agrees with the old asset-aware rule for asset+liability rows (vendor / transfer /
 * deposit — incl. the L1 vendor-edit fix) and CORRECTS it for income/expense rows.
 *
 * EMPLOYEE-CASH rule (charter): salary / advance / reimbursement never move an employee-cash
 * account's balance (its displayed balance is company-cash-only, from getCalculatedBalance).
 *
 * MIGRATION NOTE: this does NOT retro-fix past drift — existing stored balances keep their
 * old drift until the L4 one-time re-baseline. The engine only guarantees every FUTURE
 * mutation is consistent, so no new drift can form.
 */
class BalancePostingService
{
    /** account_type values that store/behave with debit arithmetic (to +, from −). */
    private const DEBIT_ARITHMETIC = ['asset', 'expense', 'income'];

    /**
     * Loan money is PERSONAL money to the employee, never company cash they hold — same charter as
     * the salary/advance exclusions, so the engine must skip the employee-cash leg of loan rows too
     * (the old inline writer never touched it either).
     *
     * Deliberately NOT added to AccountModel::EXCLUDED_EMPLOYEE_CASH_TYPES: that constant ALSO
     * drives getCalculatedBalance() — the rider balances shown everywhere — and widening it would
     * silently change those numbers. This list is engine-only.
     */
    private const ENGINE_ONLY_EMPLOYEE_SKIP = ['loan_disbursement', 'loan_cancellation_refund'];

    /**
     * Row types written with from/to REVERSED vs the economic value flow (legacy writers).
     * vendor_purchase is stored as from=expense → to=vendor (VendorController), i.e. the
     * economic flow vendor→expense written backwards. Verified against production data:
     * every vendor's stored balance = SUM(purchases) − SUM(payments) exactly, and
     * EXP_PURCHASES = SUM(purchases) exactly — so a purchase must post BOTH legs +amount
     * (vendor owed more, expense accumulated), which the map only produces after swapping
     * sides. Changing the stored orientation instead would desync 1,882 legacy rows from
     * every reader — the swap here is the safe, compatible answer.
     */
    private const REVERSED_ORIENTATION_TYPES = ['vendor_purchase'];

    /**
     * Apply a ledger row's balance effect to both accounts (idempotent).
     * No-op if already applied (balance_updated = 1).
     */
    public function apply(LedgerModel $row): void
    {
        if ($row->balance_updated) {
            return;
        }
        $this->move($row, +1);
        $row->balance_updated = 1;
        $row->saveQuietly();
    }

    /**
     * Reverse a previously-applied row (idempotent).
     * No-op if not currently applied (balance_updated = 0).
     */
    public function reverse(LedgerModel $row): void
    {
        if (!$row->balance_updated) {
            return;
        }
        $this->move($row, -1);
        $row->balance_updated = 0;
        $row->saveQuietly();
    }

    /**
     * Core mover. $dir = +1 apply, −1 reverse. Locks each account row FOR UPDATE inside the
     * caller's transaction so concurrent writers serialize (no lost read-modify-write).
     */
    private function move(LedgerModel $row, int $dir): void
    {
        $amount = (float) $row->amount * $dir;

        // Legacy reversed-orientation rows: swap which column plays which side.
        $reversed = in_array($row->transaction_type, self::REVERSED_ORIENTATION_TYPES, true);
        $fromSide = $reversed ? 'to' : 'from';
        $toSide   = $reversed ? 'from' : 'to';

        foreach ([['id' => $row->from_account_id, 'side' => $fromSide],
                  ['id' => $row->to_account_id,   'side' => $toSide]] as $leg) {
            if (empty($leg['id'])) {
                continue;
            }
            $acc = AccountModel::lockForUpdate()->find($leg['id']);
            if (!$acc) {
                continue;
            }

            // Employee-cash rule: excluded types never move a company-cash balance.
            if ($acc->account_category === AccountModel::CATEGORY_EMPLOYEE_CASH
                && (in_array($row->transaction_type, AccountModel::EXCLUDED_EMPLOYEE_CASH_TYPES, true)
                    || in_array($row->transaction_type, self::ENGINE_ONLY_EMPLOYEE_SKIP, true))) {
                continue;
            }

            $debit = in_array($acc->account_type, self::DEBIT_ARITHMETIC, true);
            // to  → debit:+amount / credit:−amount ; from → the negation.
            $toSign   = $debit ? +1 : -1;
            $delta    = $amount * ($leg['side'] === 'to' ? $toSign : -$toSign);

            $acc->current_balance = (float) $acc->current_balance + $delta;
            $acc->saveQuietly();
        }
    }

    // NOTE for the writer migration: to "edit" an applied row, the caller pattern is
    //   $svc->reverse($row);  ...mutate from/to/amount...;  $svc->apply($row);
    // There is deliberately no shortcut method — the two calls must bracket the mutation.
}
