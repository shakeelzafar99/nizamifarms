<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\SysAdmin\RoleApprovalLevelModel;

/**
 * "What is waiting on MY approval that is holding money out of this balance?"
 *
 * Aug-2026, owner ruling. A Rs 20,000 online transfer sat pending for two days in plain sight on
 * the Ledger Hub account page — the row was right there with a "Pending L1" chip, but nothing
 * said the money was therefore NOT in the balance printed at the top of the same page, and
 * nothing offered a way to act on it. The only queue that listed it was the Approvals Dashboard,
 * where it was one line among ~90 delivered-invoice approvals.
 *
 * So this answers a deliberately narrow question, and the narrowness IS the feature:
 *
 *   • BALANCE ACTIONS ONLY. Ledger rows whose approval is the thing standing between money and
 *     an account — transfers, vendor payments, expenses, deposits, salary advances.
 *   • NOT invoices / order payments. Those are the customer-collection queue: they are reviewed
 *     against payment proof in Online Approvals, they arrive by the dozen every day, and mixing
 *     them in is exactly what buried the transfer. Excluded by type, permanently.
 *   • NOT requests. Leave, equipment and other t_req_request rows are not ledger rows at all and
 *     never appear here — they belong to the Approvals Dashboard.
 *
 * WINDOW-INDEPENDENT ON PURPOSE. The account page's transaction list is capped to a period
 * (60 days by default); this service ignores that period completely. Money that has been stuck
 * outside the window is precisely the money nobody is looking at — the 23 duplicate May deposits
 * are the proof. A count that silently dropped them would be worse than no count.
 *
 * "IS THIS MONEY IN THE BALANCE?" IS DECIDED BY STATUS FIRST, FLAG SECOND. A row at `pending_l2`
 * has already had its balance posted (L1 posts it; L2 is verification only), so it still needs a
 * click but is NOT missing from the headline balance. `balance_updated` says the same thing on a
 * healthy row — but that flag needed a historical backfill that has not necessarily run on every
 * environment, so depending on it alone risks reporting settled money as missing. We therefore
 * treat a row as applied when EITHER signal says so: over-reporting "waiting" is a nuisance,
 * over-reporting "money missing from your balance" is alarming and wrong.
 */
class PendingLedgerActionsService
{
    /** Anything still awaiting a human decision. */
    public const PENDING_STATUSES = [
        LedgerModel::STATUS_PENDING,
        LedgerModel::STATUS_PENDING_L1,
        LedgerModel::STATUS_PENDING_L2,
    ];

    /**
     * Reviewed in Online Approvals against payment proof, not here. Kept as an EXCLUSION rather
     * than an allow-list so a new balance-moving type is surfaced by default — the failure mode
     * we want is "an unexpected row shows up", never "money goes missing quietly".
     */
    public const EXCLUDED_TYPES = [
        LedgerModel::TYPE_INVOICE,
        LedgerModel::TYPE_ORDER_PAYMENT,
    ];

    private const TYPE_LABELS = [
        LedgerModel::TYPE_TRANSFER         => 'Transfer',
        LedgerModel::TYPE_VENDOR_PAYMENT   => 'Vendor Payment',
        LedgerModel::TYPE_VENDOR_PURCHASE  => 'Vendor Purchase',
        LedgerModel::TYPE_EXPENSE          => 'Expense',
        LedgerModel::TYPE_EMPLOYEE_DEPOSIT => 'Deposit',
        LedgerModel::TYPE_SALARY_ADVANCE   => 'Salary Advance',
        LedgerModel::TYPE_SALARY_PAYMENT   => 'Salary',
        LedgerModel::TYPE_ADJUSTMENT       => 'Adjustment',
        LedgerModel::TYPE_SETTLEMENT       => 'Settlement',
    ];

    /**
     * Everything waiting on this one account, newest first, with the effect approving it would
     * have ON THIS ACCOUNT (a row is "in" when this account is the destination).
     *
     * @return array{count:int, missing_in:float, missing_out:float, missing_net:float,
     *               can_approve:bool, rows:array}
     */
    public function forAccount(AccountModel $account, ?int $viewerId = null): array
    {
        $rows = $this->baseQuery()
            ->with(['fromAccount:id,account_name', 'toAccount:id,account_name', 'createdBy:id,fullname'])
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                  ->orWhere('to_account_id', $account->id);
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $missingIn = 0.0;
        $missingOut = 0.0;
        $items = [];

        foreach ($rows as $r) {
            $isIn = (int) $r->to_account_id === (int) $account->id;
            $amount = (float) $r->amount;

            // Absent from the headline balance only if NEITHER signal says it was posted.
            $inBalance = $r->approval_status === LedgerModel::STATUS_PENDING_L2
                || (bool) $r->balance_updated;
            if (!$inBalance) {
                $isIn ? $missingIn += $amount : $missingOut += $amount;
            }

            $items[] = [
                'id'          => $r->id,
                'type'        => $r->transaction_type,
                'type_label'  => self::TYPE_LABELS[$r->transaction_type]
                                    ?? ucwords(str_replace('_', ' ', (string) $r->transaction_type)),
                'description' => (string) $r->description,
                'amount'      => $amount,
                'direction'   => $isIn ? 'in' : 'out',
                'counterparty' => $isIn
                    ? ($r->fromAccount->account_name ?? '—')
                    : ($r->toAccount->account_name ?? '—'),
                'date'        => $r->transaction_date
                                    ? \Carbon\Carbon::parse($r->transaction_date)->format('M d, Y')
                                    : '—',
                'by'          => $r->createdBy->fullname ?? 'System',
                'level'       => $this->levelFor($r),
                'status'      => $r->approval_status,
                'in_balance'  => $inBalance,
                'url'         => route('fin.ledger.show', $r->id),
            ];
        }

        return [
            'count'        => count($items),
            'missing_in'   => round($missingIn, 2),
            'missing_out'  => round($missingOut, 2),
            'missing_net'  => round($missingIn - $missingOut, 2),
            'can_approve'  => $this->viewerCanApprove($viewerId),
            'rows'         => $items,
        ];
    }

    /**
     * Global split for the Hub overview strip: balance actions (this service's business) versus
     * the invoice queue (Online Approvals' business). Shown separately so one can never hide the
     * other — a single blended "91 waiting" is what let the transfer disappear.
     *
     * @return array{actions:int, actions_amount:float, invoices:int}
     */
    public function summary(): array
    {
        $actions = $this->baseQuery()
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t')
            ->first();

        $invoices = LedgerModel::whereIn('approval_status', self::PENDING_STATUSES)
            ->whereIn('transaction_type', self::EXCLUDED_TYPES)
            ->count();

        return [
            'actions'        => (int) ($actions->c ?? 0),
            'actions_amount' => (float) ($actions->t ?? 0),
            'invoices'       => $invoices,
        ];
    }

    /** Per-account pending counts for a set of accounts, for list-page chips. id => count. */
    public function countsByAccount(array $accountIds): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $counts = [];
        foreach (['from_account_id', 'to_account_id'] as $side) {
            $rows = $this->baseQuery()
                ->whereIn($side, $accountIds)
                ->selectRaw("{$side} AS acct, COUNT(*) AS c")
                ->groupBy($side)
                ->get();

            foreach ($rows as $row) {
                $counts[(int) $row->acct] = ($counts[(int) $row->acct] ?? 0) + (int) $row->c;
            }
        }

        return $counts;
    }

    /**
     * The whole pool at once — for the Banks tab, whose headline is the combined online pool, not
     * one account. Same shape as forAccount(). Rows are deduped by id: a transfer BETWEEN two pool
     * accounts (ONLINE → QURBANI_ONLINE) matches twice but is one action; it keeps the direction
     * of its first match, and its in/out contribution nets out inside the pool anyway.
     *
     * @param \Illuminate\Support\Collection<AccountModel> $accounts
     */
    public function forAccounts($accounts, ?int $viewerId = null): array
    {
        $merged = [
            'count' => 0, 'missing_in' => 0.0, 'missing_out' => 0.0, 'missing_net' => 0.0,
            'can_approve' => $this->viewerCanApprove($viewerId), 'rows' => [],
        ];

        $seen = [];
        foreach ($accounts as $account) {
            $one = $this->forAccount($account, $viewerId);
            foreach ($one['rows'] as $row) {
                if (isset($seen[$row['id']])) {
                    continue;
                }
                $seen[$row['id']] = true;
                $merged['rows'][] = $row;
                if (!$row['in_balance']) {
                    $row['direction'] === 'in'
                        ? $merged['missing_in'] += $row['amount']
                        : $merged['missing_out'] += $row['amount'];
                }
            }
        }

        // Re-sort: per-account results are newest-first, but interleaving accounts breaks that.
        usort($merged['rows'], fn ($a, $b) => $b['id'] <=> $a['id']);

        $merged['count'] = count($merged['rows']);
        $merged['missing_in'] = round($merged['missing_in'], 2);
        $merged['missing_out'] = round($merged['missing_out'], 2);
        $merged['missing_net'] = round($merged['missing_in'] - $merged['missing_out'], 2);

        return $merged;
    }

    /**
     * The single definition of "a pending balance action" — every method above builds on this.
     *
     * request_id must be NULL, same convention as the Approvals Dashboard: a ledger row tied to an
     * internal request is DRIVEN by that request's own approval flow, and approving the ledger row
     * directly from here would leave the request's status behind. (No such pending rows exist
     * today — every request-linked posting is written approved or pending_l2 — but the guard keeps
     * that a fact about the data rather than a load-bearing assumption.)
     */
    private function baseQuery()
    {
        return LedgerModel::whereIn('approval_status', self::PENDING_STATUSES)
            ->whereNotIn('transaction_type', self::EXCLUDED_TYPES)
            ->whereNull('request_id');
    }

    /** Which approval level this row is sitting at (matches ApprovalController's reading). */
    private function levelFor(LedgerModel $row): int
    {
        return $row->approval_status === LedgerModel::STATUS_PENDING_L2 ? 2 : 1;
    }

    /**
     * Whether to offer approve/reject controls at all. Level 1 is the floor for acting on
     * anything; the SERVER (LedgerController::guardApprovalRights) is what actually enforces
     * per-row rights, including the L2-only rule for already-posted rows. This is purely about
     * not showing a button that is guaranteed to 403.
     */
    private function viewerCanApprove(?int $viewerId): bool
    {
        if (!$viewerId) {
            return false;
        }

        try {
            return RoleApprovalLevelModel::userHasApprovalLevel($viewerId, 1)
                || RoleApprovalLevelModel::userHasApprovalLevel($viewerId, 2);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
