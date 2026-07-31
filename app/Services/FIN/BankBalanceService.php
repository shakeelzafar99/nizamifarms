<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use Illuminate\Support\Facades\DB;

/**
 * Computes the CURRENT BALANCE of each individual receiving bank on top of the
 * single ONLINE ledger account.
 *
 * Model: the bank is a TAG (t_fin_ledger.receiving_account_id) on online
 * movements; a bank's balance = its opening_balance + the net of every tagged,
 * balance-applied movement since its opening_balance_date. Direction comes from
 * the double-entry sides: money landing IN an online (bank-category) account is
 * an inflow (+), money leaving one is an outflow (−).
 *
 * Single source of truth — used by the Bank Balances page, the Manage Banks
 * payload, and the expense-form bank chips, so every surface shows the same
 * number.
 */
class BankBalanceService
{
    /** Only rows whose balance actually applied count (L1-approved onward). */
    public const APPROVED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2, // balance applies at L1; L2 is verification
    ];

    /** IDs of chart-of-accounts rows that hold "online" money (ONLINE, QURBANI_ONLINE, ...). */
    public function onlineAccountIds(): array
    {
        return AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Balances for ALL banks in one grouped query.
     *
     * balance = opening_balance + net tagged ledger movement + manual
     * adjustments (both since the bank's opening_balance_date, when set).
     *
     * @return array<int, array{net: float, adjustments: float, balance: float, opening_balance: float, opening_balance_date: ?string}>
     *         keyed by t_fin_online_receiving_accounts.id
     */
    public function balancesByBank(): array
    {
        $onlineIds = $this->onlineAccountIds();
        $banks = DB::table('t_fin_online_receiving_accounts')
            ->get(['id', 'opening_balance', 'opening_balance_date']);

        // Net tagged movement per bank, honouring each bank's own opening date
        // via the JOIN condition (rows before the date don't count).
        $nets = collect();
        if (!empty($onlineIds)) {
            $in = implode(',', array_map('intval', $onlineIds));
            $statuses = "'" . implode("','", self::APPROVED_STATUSES) . "'";
            // NOTE: a row whose BOTH sides are online chart accounts (e.g. a
            // transfer ONLINE -> QURBANI_ONLINE) is the same physical money —
            // it must net to ZERO for the tagged bank, hence the first branch.
            $nets = collect(DB::select(
                "SELECT l.receiving_account_id AS bank_id,
                        COALESCE(SUM(CASE WHEN l.to_account_id IN ($in) AND l.from_account_id IN ($in) THEN 0
                                          WHEN l.to_account_id IN ($in) THEN l.amount
                                          WHEN l.from_account_id IN ($in) THEN -l.amount
                                          ELSE 0 END), 0) AS net
                 FROM t_fin_ledger l
                 JOIN t_fin_online_receiving_accounts b ON b.id = l.receiving_account_id
                 WHERE l.approval_status IN ($statuses)
                   AND (b.opening_balance_date IS NULL OR l.transaction_date >= b.opening_balance_date)
                 GROUP BY l.receiving_account_id"
            ))->keyBy('bank_id');
        }

        // Manual per-bank adjustments (Taimur true-ups), honouring each bank's
        // opening date the same way. try/catch: degrade to zero if the SQL for
        // t_fin_bank_balance_adjustment hasn't been run yet.
        $adjustments = collect();
        try {
            $adjustments = collect(DB::select(
                "SELECT a.receiving_account_id AS bank_id,
                        COALESCE(SUM(a.amount), 0) AS adj
                 FROM t_fin_bank_balance_adjustment a
                 JOIN t_fin_online_receiving_accounts b ON b.id = a.receiving_account_id
                 WHERE (b.opening_balance_date IS NULL OR a.adjustment_date >= b.opening_balance_date)
                 GROUP BY a.receiving_account_id"
            ))->keyBy('bank_id');
        } catch (\Throwable $e) {
            // Table not created yet — adjustments are simply zero.
        }

        $out = [];
        foreach ($banks as $b) {
            $net = (float) (($nets[$b->id]->net ?? 0));
            $adj = (float) (($adjustments[$b->id]->adj ?? 0));
            $out[(int) $b->id] = [
                'net' => $net,
                'adjustments' => $adj,
                'balance' => (float) $b->opening_balance + $net + $adj,
                'opening_balance' => (float) $b->opening_balance,
                'opening_balance_date' => $b->opening_balance_date
                    ? substr((string) $b->opening_balance_date, 0, 10)
                    : null,
            ];
        }
        return $out;
    }

    /**
     * Net tagged movement per bank for rows dated on/after $sinceDate, ignoring each bank's own
     * opening date. Same CASE rule as balancesByBank() so the two can be composed.
     *
     * Used by the rebalance: a bank's baseline has to be the figure the owner typed MINUS whatever
     * is already on the books for that same day, or the day's movements are counted twice (the
     * typed statement balance already contains them).
     *
     * @return array<int,float> keyed by t_fin_online_receiving_accounts.id
     */
    public function netsSince(string $sinceDate): array
    {
        $onlineIds = $this->onlineAccountIds();
        if (empty($onlineIds)) {
            return [];
        }
        $in = implode(',', array_map('intval', $onlineIds));
        $statuses = "'" . implode("','", self::APPROVED_STATUSES) . "'";

        $rows = DB::select(
            "SELECT l.receiving_account_id AS bank_id,
                    COALESCE(SUM(CASE WHEN l.to_account_id IN ($in) AND l.from_account_id IN ($in) THEN 0
                                      WHEN l.to_account_id IN ($in) THEN l.amount
                                      WHEN l.from_account_id IN ($in) THEN -l.amount
                                      ELSE 0 END), 0) AS net
             FROM t_fin_ledger l
             WHERE l.approval_status IN ($statuses)
               AND l.receiving_account_id IS NOT NULL
               AND l.transaction_date >= ?
             GROUP BY l.receiving_account_id",
            [$sinceDate]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->bank_id] = (float) $r->net;
        }

        // Manual ⚖ fixes count towards a bank's balance exactly like tagged movements do (see
        // balancesByBank), so "what already contributes from this date" MUST include them. Leaving
        // them out made a same-day fix survive a rebalance uncancelled — the bank then settled a
        // fix-sized distance away from the figure that was typed, re-opening the recon gap.
        try {
            $adj = DB::select(
                "SELECT receiving_account_id AS bank_id, COALESCE(SUM(amount), 0) AS net
                 FROM t_fin_bank_balance_adjustment
                 WHERE adjustment_date >= ?
                 GROUP BY receiving_account_id",
                [$sinceDate]
            );
            foreach ($adj as $r) {
                $out[(int) $r->bank_id] = ($out[(int) $r->bank_id] ?? 0.0) + (float) $r->net;
            }
        } catch (\Throwable $e) {
            // Table not created yet — nothing to cancel.
        }

        return $out;
    }

    /**
     * Optional "start tracking the No-bank bucket from" date (config). Lets the
     * owner ignore the large pre-go-live untagged history and watch only leaks
     * since a chosen date. Null = count all history.
     */
    public function unassignedSince(): ?string
    {
        $val = \App\Models\FIN\ConfigModel::get('bank_unassigned_tracking_since', null);
        return $val ? substr((string) $val, 0, 10) : null;
    }

    /**
     * Net of online movements that were NEVER tagged to a bank — the honest
     * "Unassigned" bucket (mostly rows from before per-bank tracking). Honours
     * the optional tracking-since floor.
     */
    public function unassignedNet(): float
    {
        $onlineIds = $this->onlineAccountIds();
        if (empty($onlineIds)) {
            return 0.0;
        }
        $in = implode(',', array_map('intval', $onlineIds));
        $since = $this->unassignedSince();

        $q = LedgerModel::whereNull('receiving_account_id')
            ->whereIn('approval_status', self::APPROVED_STATUSES)
            ->where(function ($q) use ($onlineIds) {
                $q->whereIn('to_account_id', $onlineIds)
                    ->orWhereIn('from_account_id', $onlineIds);
            });
        if ($since) {
            $q->whereDate('transaction_date', '>=', $since);
        }

        return (float) $q->selectRaw(
            "COALESCE(SUM(CASE WHEN to_account_id IN ($in) AND from_account_id IN ($in) THEN 0 "
            . "WHEN to_account_id IN ($in) THEN amount "
            . "WHEN from_account_id IN ($in) THEN -amount ELSE 0 END), 0) AS net"
        )->value('net');
    }
}
