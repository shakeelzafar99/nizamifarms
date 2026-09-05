<?php

namespace App\Services\FIN;

use App\Models\FIN\ConfigModel;

/**
 * ONE definition of "what an order contributed to profit".
 *
 * Sep-2026. Every profit surface (Reports tab web+mobile, HQ Executive, the
 * Ledger Hub Sales card that reuses HQ, and the Dashboard) sums
 * `t_crm_prod_order.total_price` for delivered orders. That number is wrong in
 * two directions:
 *
 *  1. ⭐ ACCOUNT BALANCE SPENT IS MISSING. When a customer pays part of an
 *     invoice from their account balance, the server writes a sentinel discount
 *     row (`coupon_code = ACCOUNT_BALANCE`) and `total_price` drops by that
 *     amount. The sale did not shrink — the customer paid with money we were
 *     already holding for them. The ledger knows this (the invoice row plus the
 *     `customer_credit_consume` row add up to the full sale), but no report
 *     reads the ledger for revenue. So the balance spent must be ADDED BACK.
 *
 *  2. ⭐ TIPS ARE NOT OURS. `total_price = subtotal − discount + shipping + tip`,
 *     so every tip is inside revenue and inside profit. Owner ruling (Sep-3):
 *     from TIPS_FUND_START_DATE tips are money held for the tip pool, not
 *     business income, so they are SUBTRACTED. Deliveries before the cutoff are
 *     left exactly as they are — no month that has already been read and
 *     discussed is allowed to move.
 *
 * So, per order:
 *
 *     profit revenue = total_price + balance_applied − tip (on/after cutoff)
 *
 * ⚠ This class is a REPORTING lens only. It writes nothing and changes no
 * ledger row: the invoice keeps its own amount, the customer keeps seeing the
 * reduced invoice, and receivables ("what is still owed") keep using raw
 * `total_price` — money owed is not the same question as money earned.
 *
 * ⚠⚠ Qurbani is excluded from every caller already (and posts no invoice row),
 * so Qurbani tips never reach this class — matching the Tips Fund, which they
 * also stay out of.
 */
class ProfitRevenueSql
{
    /** The server-owned sentinel discount that marks "paid from account balance". */
    public const DISCOUNT_CODE = 'ACCOUNT_BALANCE';

    /** t_fin_config key holding the date tips stop counting as income. */
    public const CONFIG_KEY = 'TIPS_FUND_START_DATE';

    /**
     * Used when the config row is missing. Owner ruling Sep-4-2026: start from
     * 1 AUGUST (moved back from 1 September the same day, so August's tips are
     * in the pool and out of August's profit — one date drives both sides).
     *
     * Deliberately NOT "do nothing": a missing config row must not silently put
     * tips back into profit months after the owner took them out. The config
     * row exists so the date can be CHANGED, not so the rule can vanish.
     */
    public const DEFAULT_CUTOFF = '2026-08-01';

    /** Alias of the balance-applied subquery this class joins in. */
    public const BAL_ALIAS = 'nf_bal';

    /** Per-request memo. ⚠ The cache driver is the DATABASE — re-reading config in a month loop is a query each time. */
    private static ?string $cutoff = null;

    /**
     * The cutoff date as 'Y-m-d'.
     *
     * ⚠ The value is interpolated into SQL (it has to be — these expressions are
     * built as strings for both raw SQL and query builders), so anything that is
     * not exactly a date is refused and the default is used instead. A config
     * row can be edited by hand in phpMyAdmin; this is what keeps that safe.
     */
    public static function cutoff(): string
    {
        if (self::$cutoff !== null) {
            return self::$cutoff;
        }

        $raw = (string) ConfigModel::get(self::CONFIG_KEY, '');
        $raw = trim($raw);

        // Accept 'YYYY-MM-DD' and 'YYYY-MM-DD HH:MM:SS' (take the date half).
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) && checkdate(
            (int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4)
        )) {
            return self::$cutoff = $m[1];
        }

        return self::$cutoff = self::DEFAULT_CUTOFF;
    }

    /** Test seam / long-running processes: drop the memo. */
    public static function forgetCutoff(): void
    {
        self::$cutoff = null;
    }

    /**
     * The LEFT JOIN that carries each order's account-balance total.
     *
     * ⚠⚠ It MUST stay a pre-aggregated subquery. Joining t_crm_order_discounts
     * straight onto the order is 1:N, and an order with two discount rows would
     * have its `total_price` counted twice by every SUM in the same query.
     */
    public static function joinSql(string $orderAlias = 'o', string $balAlias = self::BAL_ALIAS): string
    {
        return ' LEFT JOIN (SELECT order_id, SUM(discount_amount) AS amt'
             . ' FROM t_crm_order_discounts'
             . " WHERE coupon_code = '" . self::DISCOUNT_CODE . "'"
             . ' GROUP BY order_id) AS ' . $balAlias
             . ' ON ' . $balAlias . '.order_id = ' . $orderAlias . '.id ';
    }

    /**
     * Same join for a query builder (HQ / Dashboard).
     *
     * @param \Illuminate\Database\Query\Builder $query
     */
    public static function join($query, string $orderAlias = 'o', string $balAlias = self::BAL_ALIAS)
    {
        return $query->leftJoinSub(
            \Illuminate\Support\Facades\DB::table('t_crm_order_discounts')
                ->select('order_id')
                ->selectRaw('SUM(discount_amount) AS amt')
                ->where('coupon_code', self::DISCOUNT_CODE)
                ->groupBy('order_id'),
            $balAlias,
            $balAlias . '.order_id',
            '=',
            $orderAlias . '.id'
        );
    }

    /** Account balance spent on this order (0 when none). */
    public static function balance(string $balAlias = self::BAL_ALIAS): string
    {
        return 'COALESCE(' . $balAlias . '.amt, 0)';
    }

    /** Alias of the first-delivered subquery, for callers that need to join it. */
    public const DEL_ALIAS = 'nf_del';

    /**
     * Each order's FIRST delivered timestamp.
     *
     * ⚠⚠ Only needed by callers whose own delivered-date column is NOT the
     * first one. The Reports tab already joins MIN(changed_at); the HQ service
     * joins MAX(changed_at), which is fine for deciding WHICH MONTH an order
     * belongs to but must never decide the tip, or an order delivered twice
     * across the cutoff would have its tip removed from HQ revenue while the
     * Tips Fund (which uses the first delivery) never collected it — money
     * gone from the books rather than moved.
     */
    public static function deliveredJoinSql(string $orderAlias = 'o', string $delAlias = self::DEL_ALIAS): string
    {
        return ' LEFT JOIN (SELECT order_id, MIN(changed_at) AS first_delivered_at'
             . " FROM t_crm_order_status_history WHERE status_code = 'delivered'"
             . ' GROUP BY order_id) AS ' . $delAlias
             . ' ON ' . $delAlias . '.order_id = ' . $orderAlias . '.id ';
    }

    /** Same join for a query builder. */
    public static function joinDelivered($query, string $orderAlias = 'o', string $delAlias = self::DEL_ALIAS)
    {
        return $query->leftJoinSub(
            \Illuminate\Support\Facades\DB::table('t_crm_order_status_history')
                ->select('order_id')
                ->selectRaw('MIN(changed_at) AS first_delivered_at')
                ->where('status_code', 'delivered')
                ->groupBy('order_id'),
            $delAlias,
            $delAlias . '.order_id',
            '=',
            $orderAlias . '.id'
        );
    }

    /**
     * The tip that is NOT income, i.e. tips on deliveries from the cutoff on.
     *
     * ⚠⚠ $firstDeliveredExpr must be the order's FIRST delivered timestamp —
     * the same instant TipsFundService uses to decide whether to collect the
     * tip. Pass `h.delivered_at` from the Reports queries (already a MIN), or
     * join deliveredJoinSql() and pass its column. Never pass a MAX.
     *
     * ⚠ Compared on the DATE only. Production stores these timestamps two hours
     * off local time, so a datetime comparison would move orders delivered late
     * on the 31st across the boundary.
     */
    public static function tipExcluded(string $orderAlias = 'o', string $firstDeliveredExpr = 'h.delivered_at'): string
    {
        return 'CASE WHEN DATE(' . $firstDeliveredExpr . ") >= '" . self::cutoff() . "'"
             . ' THEN COALESCE(' . $orderAlias . '.tip_amount, 0) ELSE 0 END';
    }

    /**
     * What this order contributed to profit. Drop-in replacement for
     * `o.total_price` inside any SUM over delivered orders.
     *
     * ⚠⚠ $excludeTips MUST be false for a Qurbani view. Qurbani tips do not go
     * into the Tips Fund (owner ruling A2 — those orders settle through payments
     * and never post an invoice row, so nothing would ever collect them). Taking
     * them out of Qurbani revenue would delete that money from the books
     * entirely instead of moving it somewhere. The balance add-back still
     * applies: a reduced `total_price` understates the sale whoever the customer
     * is.
     */
    public static function revenue(
        string $orderAlias = 'o',
        string $deliveredExpr = 'h.delivered_at',
        string $balAlias = self::BAL_ALIAS,
        bool $excludeTips = true
    ): string {
        $expr = '(' . $orderAlias . '.total_price + ' . self::balance($balAlias);

        if ($excludeTips) {
            $expr .= ' - ' . self::tipExcluded($orderAlias, $deliveredExpr);
        }

        return $expr . ')';
    }
}
