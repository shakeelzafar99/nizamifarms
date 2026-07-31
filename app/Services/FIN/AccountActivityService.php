<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;

/**
 * "What actually moved this account's balance, and who moved it."
 *
 * Shared by the mobile Expenses screen (RiderController::getAccountActivity) and the web
 * Frozen Operations → Expenses tab (KhaasController::accountActivity), so the two can never
 * disagree about what counts as a movement.
 *
 * Deliberately NOT the same question as RiderController::getFundTransfers(), which answers
 * "how much was ADDED to the fund this month" (to_account only, type=transfer, current month).
 * On the Frozen fund account that returns 2 rows while the account has hundreds of movements,
 * nearly all of them vendor_payment OUTflows — so the balance moved constantly with nothing
 * on screen explaining why. That endpoint is left untouched; this answers the other question.
 */
class AccountActivityService
{
    /** Statuses a row can hold and still be listed — matches the full account statement. */
    private const LISTED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING,
        LedgerModel::STATUS_PENDING_L1,
        LedgerModel::STATUS_PENDING_L2,
    ];

    /** Statuses whose balance effect has actually been applied. */
    private const APPLIED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2,
    ];

    private const TYPE_LABELS = [
        'transfer'         => 'Fund Transfer',
        'company_transfer' => 'Company Transfer',
        'company_payment'  => 'Company Payment',
        'vendor_payment'   => 'Vendor Payment',
        'vendor_purchase'  => 'Vendor Purchase',
        'employee_deposit' => 'Employee Deposit',
        'expense'          => 'Expense',
        'salary_payment'   => 'Salary Payment',
        'salary_advance'   => 'Salary Advance',
        'asset_purchase'   => 'Asset Purchase',
        'invoice'          => 'Invoice',
        'order_payment'    => 'Order Payment',
    ];

    /**
     * Last $limit movements IN and OUT of $account, newest first, each with date, time,
     * counterparty, who moved it, and a running balance.
     *
     * @return array{account: array, activity: array, summary: array}
     */
    public function recent(AccountModel $account, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $rows = LedgerModel::with([
                'fromAccount:id,account_name,account_code',
                'toAccount:id,account_name,account_code',
                'createdBy:id,fullname',
            ])
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                  ->orWhere('to_account_id', $account->id);
            })
            ->whereIn('approval_status', self::LISTED_STATUSES)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        // Running balance, walking BACK from the account's current balance. A still-pending
        // row has not moved anything, so it carries the balance forward unchanged rather
        // than pretending it did.
        $running = (float) $account->current_balance;

        $items = [];
        foreach ($rows as $r) {
            $isIn = (int) $r->to_account_id === (int) $account->id;
            $amount = (float) $r->amount;
            $isApplied = in_array($r->approval_status, self::APPLIED_STATUSES, true);
            $signed = $isIn ? $amount : -$amount;

            $balanceAfter = $running;
            if ($isApplied) {
                $running -= $signed;
            }

            $items[] = [
                'id' => $r->id,
                'direction' => $isIn ? 'in' : 'out',
                'amount' => $amount,
                'signed_amount' => $signed,
                'type' => $r->transaction_type,
                'type_label' => self::TYPE_LABELS[$r->transaction_type]
                    ?? ucwords(str_replace('_', ' ', (string) $r->transaction_type)),
                'counterparty' => $isIn
                    ? ($r->fromAccount->account_name ?? 'Unknown')
                    : ($r->toAccount->account_name ?? 'Unknown'),
                'description' => $r->description,
                'mode' => $r->mode,
                'approval_status' => $r->approval_status,
                'is_pending' => !$isApplied,
                'moved_by' => $r->createdBy->fullname ?? 'System',
                'date' => $r->transaction_date ? $r->transaction_date->format('Y-m-d') : null,
                'date_display' => $r->transaction_date ? $r->transaction_date->format('d M Y') : '',
                'time_display' => $r->created_at ? $r->created_at->format('h:i A') : '',
                'when_display' => $r->created_at ? $r->created_at->format('d M Y, h:i A') : '',
                'ago' => $r->created_at ? $r->created_at->diffForHumans() : '',
                'balance_after' => $isApplied ? round($balanceAfter, 2) : null,
            ];
        }

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->account_name,
                'code' => $account->account_code,
                'balance' => (float) $account->current_balance,
            ],
            'activity' => $items,
            'summary' => [
                'shown' => count($items),
                'total_in' => round(collect($items)->where('direction', 'in')->sum('amount'), 2),
                'total_out' => round(collect($items)->where('direction', 'out')->sum('amount'), 2),
            ],
        ];
    }
}
