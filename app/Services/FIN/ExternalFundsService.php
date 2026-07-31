<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\OnlineReceivingAccountModel;

/**
 * Money crossing the boundary of the books — an owner injection, an outside refund, or a
 * correction of a figure that was simply wrong. This is the ONE sanctioned way to change a
 * pool/till total without an internal counterparty.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this service there was no legitimate path for "add outside money to Online":
 *   - AccountController::adjustBalance() deliberately BLOCKS bank-category accounts and
 *     redirects to the per-bank ⚖ Fix balance,
 *   - but ⚖ Fix balance is attribution-only — it moves a bank's tracked number and never
 *     touches the ONLINE ledger.
 * So every real outside deposit could only be recorded as an attribution fix, and the banks
 * drifted away from the pool they are supposed to add up to. That is exactly the residue the
 * Banks tab's "unexplained gap" banner was reporting.
 *
 * THE POSTING
 * -----------
 * One ledger row, Opening-Equity <-> the account, transaction_type `adjustment` (an existing
 * type every filter/report map already renders — no new vocabulary), applied through
 * BalancePostingService so `balance_updated` is stamped and the row stays reversible. It is
 * NOT counted by any P&L or KPI figure: LedgerKpiService and ExecutiveClosingService both
 * select on invoice / expense / vendor_* / salary_* types, never `adjustment`. It DOES move the
 * balance-sheet cash position, which is the whole point.
 *
 * mode = cash always, matching AccountController::adjustBalance()'s precedent. `mode` is only a
 * descriptor here; approvals queues key off approval_status (always `approved` for these rows).
 *
 * BANK TAG: when the account is bank-category, the caller MUST supply the physical bank, or the
 * row lands untagged in the "No bank" bucket and re-opens the very drift this fixes. The one
 * allowed exception is a deliberate "don't know yet" — which is visible in that bucket and
 * assignable later, never silent.
 *
 * Callers must wrap this in their own DB transaction (BalancePostingService locks account rows
 * FOR UPDATE and expects an enclosing transaction).
 */
class ExternalFundsService
{
    public const DIR_IN = 'in';
    public const DIR_OUT = 'out';

    /**
     * Post an external money movement and apply it to balances.
     *
     * @param  AccountModel $account    The company account receiving (in) or releasing (out) the money.
     * @param  float        $amount     Always positive; $direction carries the sign.
     * @param  string       $direction  self::DIR_IN | self::DIR_OUT
     * @param  string       $date       Y-m-d transaction date.
     * @param  string       $description Free-text purpose (required by the callers).
     * @param  int|null     $bankId     Physical bank tag — required for bank-category accounts
     *                                  unless $allowUntagged is true.
     * @param  string|null  $sourceName Optional "who from / who to" label, prefixed into the description.
     * @param  bool         $allowUntagged Explicit opt-in to leave a bank-category row untagged.
     *
     * @throws \InvalidArgumentException on any rule violation (callers surface the message).
     */
    public function post(
        AccountModel $account,
        float $amount,
        string $direction,
        string $date,
        string $description,
        ?int $bankId = null,
        ?string $sourceName = null,
        bool $allowUntagged = false
    ): LedgerModel {
        if (!in_array($direction, [self::DIR_IN, self::DIR_OUT], true)) {
            throw new \InvalidArgumentException('Direction must be in or out.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Enter an amount greater than zero.');
        }
        $description = trim($description);
        if ($description === '') {
            throw new \InvalidArgumentException('Add a short description so this is explainable later.');
        }

        $equity = ConfigModel::getOpeningEquityAccount();
        if (!$equity) {
            throw new \InvalidArgumentException(
                'The Opening Balance Equity account is missing — it is required to record outside money.'
            );
        }
        if ((int) $equity->id === (int) $account->id) {
            throw new \InvalidArgumentException('Outside money cannot be posted against the equity account itself.');
        }

        $isBankAccount = $account->account_category === AccountModel::CATEGORY_BANK;
        $bank = null;

        if ($isBankAccount) {
            if (!$bankId && !$allowUntagged) {
                throw new \InvalidArgumentException('Pick which bank this money goes through.');
            }
            if ($bankId) {
                $bank = OnlineReceivingAccountModel::find($bankId);
                if (!$bank) {
                    throw new \InvalidArgumentException('That bank no longer exists.');
                }
                // A row dated before the bank's baseline is invisible to BankBalanceService
                // (its JOIN filters on opening_balance_date) but still moves the pool — an
                // instant, silent reconciliation gap. Refuse rather than create one.
                $openingDate = $bank->opening_balance_date
                    ? $bank->opening_balance_date->toDateString()
                    : null;
                if ($openingDate && $date < $openingDate) {
                    throw new \InvalidArgumentException(
                        $bank->name . ' was rebalanced on ' . \Carbon\Carbon::parse($openingDate)->format('M d, Y')
                        . '. Use a date on or after that, or this money would move the pool without showing in the bank.'
                    );
                }
            }
        } elseif ($bankId) {
            // Non-bank account (a cash till): a bank tag would be meaningless and would pollute
            // the per-bank net. Drop it silently rather than mis-attribute.
            $bankId = null;
        }

        if ($sourceName !== null && trim($sourceName) !== '') {
            $label = $direction === self::DIR_IN ? 'Received from' : 'Paid to';
            $description = $label . ': ' . trim($sourceName) . ' — ' . $description;
        }
        if ($bank && !str_contains($description, '· via ')) {
            // Same naming convention storeTransfer/assignBank use, so the ledger listing reads alike.
            $description .= ' · via ' . ($bank->short_code ?: $bank->name);
        }

        $row = LedgerModel::create([
            'transaction_date'     => $date,
            'transaction_type'     => LedgerModel::TYPE_ADJUSTMENT,
            'description'          => $description,
            'from_account_id'      => $direction === self::DIR_IN ? $equity->id : $account->id,
            'to_account_id'        => $direction === self::DIR_IN ? $account->id : $equity->id,
            'amount'               => $amount,
            'mode'                 => LedgerModel::MODE_CASH,
            'receiving_account_id' => $bankId ?: null,
            'approval_status'      => LedgerModel::STATUS_APPROVED,
            'approval_date'        => now()->toDateString(),
            'approved_by'          => auth()->id(),
            'created_by'           => auth()->id(),
            'comments'             => 'Outside money (' . $direction . ') recorded from the Ledger Hub.',
        ]);

        (new BalancePostingService())->apply($row);

        return $row;
    }
}
