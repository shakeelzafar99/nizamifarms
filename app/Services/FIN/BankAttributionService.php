<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\OnlineReceivingAccountModel;

/**
 * "This money moved through a bank — WHICH bank?" — one answer for every writer.
 *
 * ── Why this class exists ─────────────────────────────────────────────────────
 * "Online Bank" is a SINGLE chart account (`ONLINE`, account_category = 'bank').
 * Which physical bank the money actually left or landed in lives in a different
 * table (t_fin_online_receiving_accounts) and is carried on the ledger row as
 * `receiving_account_id`. BankBalanceService computes each bank's balance as:
 *
 *     opening + SUM(tagged, balance-applied rows, signed by which side the
 *                   bank-category account sits on)
 *
 * So a row that touches a bank-category account with `receiving_account_id`
 * NULL is INVISIBLE to the per-bank balances. The ONLINE account total stays
 * right; the split across HBL / Meezan / … silently loses that amount. That is
 * the drift, and it is not self-healing — nothing later goes back and guesses.
 *
 * Before this class each writer decided for itself whether to ask for a bank,
 * and most of them did not even know they should: the Daily Closing approve
 * rows (web AND mobile), the store salary advance, and the loan disbursement all
 * offered "Online Bank" in a picker with no bank field anywhere behind it.
 *
 * ── What belongs here ─────────────────────────────────────────────────────────
 * The RULE, not the UI. A surface asks `requiresBank()` to decide whether to show
 * its bank picker, and `problemWith()` to refuse a submit. `untaggedMovement()`
 * is the same predicate BankBalanceService uses, so the detector in
 * BalancePostingService and the balance query can never disagree about what
 * counts.
 *
 * ⚠ Deliberately NOT a validator that throws. Money endpoints in this system are
 *   called by an installed APK that cannot be updated in step with the server, so
 *   each caller decides whether a missing bank is a refusal or a warning. This
 *   class only ever ANSWERS.
 */
class BankAttributionService
{
    /** Memoised per request — apply() can run in a loop over many rows. */
    private ?array $bankAccountIds = null;
    private ?array $validBankIds = null;

    /**
     * The chart accounts that hold "online" money (ONLINE, QURBANI_ONLINE, …).
     * Same source as BankBalanceService::onlineAccountIds() — asked through it so
     * there is one definition, not two that can drift apart.
     */
    public function bankAccountIds(): array
    {
        if ($this->bankAccountIds === null) {
            $this->bankAccountIds = app(BankBalanceService::class)->onlineAccountIds();
        }
        return $this->bankAccountIds;
    }

    /**
     * Does paying FROM (or into) this chart account require naming a bank?
     * The one test a picker should use — never a hardcoded 'ONLINE' code match,
     * which misses QURBANI_ONLINE and every bank account added later.
     */
    public function requiresBank($accountId): bool
    {
        if (!$accountId) {
            return false;
        }
        return in_array((int) $accountId, $this->bankAccountIds(), true);
    }

    /** Is $bankId a real, active receiving bank? */
    public function isValidBank($bankId): bool
    {
        if (!$bankId) {
            return false;
        }
        if ($this->validBankIds === null) {
            $this->validBankIds = OnlineReceivingAccountModel::active()
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        return in_array((int) $bankId, $this->validBankIds, true);
    }

    /**
     * The submit-side gate. Returns a human message to refuse with, or null when
     * the pairing is fine.
     *
     * Both directions are checked on purpose:
     *   - a bank source with no bank named  → the amount vanishes from the split
     *   - a bank named on a CASH source     → it would be counted against a bank
     *     that never moved, which is drift in the opposite direction
     */
    public function problemWith($accountId, $bankId): ?string
    {
        if ($this->requiresBank($accountId)) {
            if (!$bankId) {
                return 'Select which bank this online payment is made from.';
            }
            if (!$this->isValidBank($bankId)) {
                return 'That bank is not a recognised receiving account.';
            }
            return null;
        }

        if ($bankId) {
            return 'A cash payment cannot name a bank.';
        }
        return null;
    }

    /**
     * The bank id that should be STORED for this pairing — null for every cash
     * source, so a cash-funded row can never carry a stale bank tag left over
     * from an earlier edit.
     */
    public function bankIdToStore($accountId, $bankId)
    {
        return $this->requiresBank($accountId) ? ($bankId ?: null) : null;
    }

    /**
     * Would this ledger row be invisible to the per-bank balances?
     *
     * ⭐ This is BankBalanceService::balancesByBank()'s own predicate, inverted.
     * A row counts toward a bank when it is approved/pending_l2 AND exactly one
     * side is a bank-category account. Rows with a bank account on BOTH sides are
     * the same physical money moving between two chart accounts and net to zero
     * there, so they need no tag — and must not be reported as if they did.
     *
     * @return array|null  null = fine; otherwise a context array for the log.
     */
    public function untaggedMovement(LedgerModel $row): ?array
    {
        if ($row->receiving_account_id) {
            return null;
        }

        if (!in_array($row->approval_status, BankBalanceService::APPROVED_STATUSES, true)) {
            return null;                       // not counted yet; may be tagged at approval
        }

        $ids  = $this->bankAccountIds();
        $from = in_array((int) $row->from_account_id, $ids, true);
        $to   = in_array((int) $row->to_account_id, $ids, true);

        if ($from === $to) {
            return null;                       // neither side, or an internal bank↔bank move
        }

        return [
            'ledger_id'        => $row->id,
            'transaction_type' => $row->transaction_type,
            'amount'           => (float) $row->amount,
            'direction'        => $to ? 'in' : 'out',
            'from_account_id'  => $row->from_account_id,
            'to_account_id'    => $row->to_account_id,
            'request_id'       => $row->request_id,
            'order_id'         => $row->order_id,
        ];
    }

    /**
     * Convenience for pickers: the bank-category account ids the given source
     * list contains, so a Blade/JSON payload can mark its options without each
     * surface re-deriving "is this one online".
     */
    public function markOnline(iterable $accounts, string $idKey = 'id'): array
    {
        $ids = $this->bankAccountIds();
        $out = [];
        foreach ($accounts as $a) {
            $id = is_array($a) ? ($a[$idKey] ?? null) : ($a->{$idKey} ?? null);
            $out[(int) $id] = in_array((int) $id, $ids, true);
        }
        return $out;
    }

    /**
     * Is this chart account a bank one? Kept separate from requiresBank() only so
     * a reader of a model instance does not have to reach for its id.
     */
    public function accountIsBank(?AccountModel $account): bool
    {
        return $account && $account->account_category === AccountModel::CATEGORY_BANK;
    }
}
