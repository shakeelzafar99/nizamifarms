<?php

namespace App\Services;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Single source of truth for "what counts as a Qurbani financial row?"
 *
 * The codebase has THREE different financial surfaces that all need to
 * agree on the same Qurbani definition:
 *
 *   1. Order-side  (`t_crm_prod_order` + `t_crm_prod_order_line_item`)
 *      — used by /qurbani/invoices and the new Qurbani revenue card.
 *   2. Request-side (`t_req_master`)
 *      — drives the web + mobile Expense Management screens.
 *   3. Ledger-side  (`t_fin_ledger`)
 *      — drives the Monthly Reports + Dashboard analytics.
 *
 * Before this service these definitions lived in 5+ places. Centralising
 * them here means a future tweak to "what is a Qurbani transaction"
 * propagates everywhere, instead of leaving stale filters behind.
 *
 * Each helper accepts a Query Builder + table alias + mode (include /
 * exclude). All helpers are no-ops in 'include' mode when the caller
 * doesn't want to discriminate (so callers can wire them
 * unconditionally and toggle behaviour at the call site).
 *
 * Usage:
 *   QurbaniFinanceFilter::applyToRequestQuery($q, 'r', 'exclude');
 *   QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', 'include');
 *   QurbaniFinanceFilter::applyToOrderQuery($q, 'o', 'include');
 */
class QurbaniFinanceFilter
{
    public const MODE_INCLUDE = 'include';
    public const MODE_EXCLUDE = 'exclude';

    /**
     * Order-side Qurbani filter. Mirrors the OR-rule used by
     * QurbaniWebController::getInvoices so /qurbani/invoices and the
     * new Qurbani revenue card return the same numbers.
     *
     * An order is "Qurbani" if it carries any qurbani_* metadata OR
     * has at least one line item whose product is tagged
     * `attribute_1 = 'qurbani'`.
     *
     * @param QueryBuilder|EloquentBuilder $query
     * @param string                       $alias  Alias of the orders table (e.g. 'o').
     * @param string                       $mode   'include' or 'exclude'.
     */
    public static function applyToOrderQuery($query, string $alias = 'o', string $mode = self::MODE_INCLUDE): void
    {
        if ($mode === self::MODE_INCLUDE) {
            $query->where(function ($outer) use ($alias) {
                $outer->where(function ($inner) use ($alias) {
                    $inner->whereNotNull("$alias.qurbani_day")
                          ->orWhereNotNull("$alias.qurbani_slot")
                          ->orWhereNotNull("$alias.qurbani_region")
                          ->orWhereNotNull("$alias.qurbani_delivery_type");
                })->orWhereExists(function ($sub) use ($alias) {
                    $sub->select(\DB::raw(1))
                        ->from('t_crm_prod_order_line_item as li')
                        ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                        ->whereColumn('li.order_id', "$alias.id")
                        ->whereRaw("LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'");
                });
            });
            return;
        }
        // Exclude — push the entire OR-rule into a NOT.
        $query->where(function ($outer) use ($alias) {
            $outer->whereNull("$alias.qurbani_day")
                  ->whereNull("$alias.qurbani_slot")
                  ->whereNull("$alias.qurbani_region")
                  ->whereNull("$alias.qurbani_delivery_type");
        })->whereNotExists(function ($sub) use ($alias) {
            $sub->select(\DB::raw(1))
                ->from('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                ->whereColumn('li.order_id', "$alias.id")
                ->whereRaw("LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'");
        });
    }

    /**
     * Request-side Qurbani filter. Anchors on the request type's
     * `category_code = 'qurbani'`. Sub-categories of qurbani requests
     * are still differentiated by `t_req_master.expense_category`
     * (e.g. "Petrol", "Helper") but the discriminator at this level
     * is the request type, not the sub-category — that's how the
     * data model was set up in May-2026.
     *
     * @param QueryBuilder|EloquentBuilder $query
     * @param string                       $alias Alias of t_req_master (e.g. 'r').
     * @param string                       $mode  'include' or 'exclude'.
     */
    public static function applyToRequestQuery($query, string $alias = 'r', string $mode = self::MODE_INCLUDE): void
    {
        $sub = function () {
            return \DB::table('t_req_category')
                ->select('id')
                ->where('category_code', 'qurbani');
        };
        if ($mode === self::MODE_INCLUDE) {
            $query->whereIn("$alias.category_id", $sub());
        } else {
            $query->whereNotIn("$alias.category_id", $sub());
        }
    }

    /**
     * Ledger-side Qurbani filter. Two ways a ledger row counts as
     * Qurbani:
     *   1. The ledger row links back to a Qurbani request via
     *      `t_fin_ledger.request_id` → request whose category is
     *      'qurbani'. Covers `expense` posting from approval.
     *   2. The ledger row links to a Qurbani order via
     *      `t_fin_ledger.order_id`. Covers `invoice` posting on
     *      delivery and any vendor purchase that was tied to a
     *      Qurbani order (rare but possible).
     *
     * Vendor purchases tied to a Qurbani request (q1 from the user:
     * "qurbani vendors should also be separated") are picked up via
     * the request_id leg — the LedgerPostingService stamps
     * request_id on vendor_purchase rows when they originate from a
     * vendor purchase request linked to a Qurbani category.
     *
     * @param QueryBuilder|EloquentBuilder $query
     * @param string                       $alias Alias of t_fin_ledger (e.g. 'l').
     * @param string                       $mode  'include' or 'exclude'.
     */
    public static function applyToLedgerQuery($query, string $alias = 'l', string $mode = self::MODE_INCLUDE): void
    {
        $reqSub = \DB::table('t_req_master as qreq')
            ->select('qreq.id')
            ->join('t_req_category as qcat', 'qcat.id', '=', 'qreq.category_id')
            ->where('qcat.category_code', 'qurbani');

        // Order leg uses the same OR-rule as applyToOrderQuery for
        // consistency. Inlining as a subquery so this remains a single
        // WHERE clause on the outer ledger query.
        $orderSub = \DB::table('t_crm_prod_order as qord')
            ->select('qord.id')
            ->where(function ($w) {
                $w->where(function ($inner) {
                    $inner->whereNotNull('qord.qurbani_day')
                          ->orWhereNotNull('qord.qurbani_slot')
                          ->orWhereNotNull('qord.qurbani_region')
                          ->orWhereNotNull('qord.qurbani_delivery_type');
                })->orWhereExists(function ($sub) {
                    $sub->select(\DB::raw(1))
                        ->from('t_crm_prod_order_line_item as li')
                        ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                        ->whereColumn('li.order_id', 'qord.id')
                        ->whereRaw("LOWER(COALESCE(p.attribute_1, '')) = 'qurbani'");
                });
            });

        if ($mode === self::MODE_INCLUDE) {
            $query->where(function ($q) use ($alias, $reqSub, $orderSub) {
                $q->whereIn("$alias.request_id", $reqSub)
                  ->orWhereIn("$alias.order_id", $orderSub);
            });
        } else {
            $query->where(function ($q) use ($alias, $reqSub, $orderSub) {
                // Row is non-qurbani if BOTH legs miss it. NULL on
                // either id is treated as non-qurbani for that leg.
                $q->where(function ($x) use ($alias, $reqSub) {
                    $x->whereNull("$alias.request_id")
                      ->orWhereNotIn("$alias.request_id", $reqSub);
                })->where(function ($x) use ($alias, $orderSub) {
                    $x->whereNull("$alias.order_id")
                      ->orWhereNotIn("$alias.order_id", $orderSub);
                });
            });
        }
    }

    /**
     * Returns the Qurbani t_req_category id (cached per-request).
     * Useful for endpoints that need the literal id (not just to
     * filter a query). Returns null if the category row hasn't been
     * seeded yet — callers should fail closed (i.e. no filter
     * applied = no rows match) rather than skip the filter.
     */
    public static function qurbaniCategoryId(): ?int
    {
        static $id = null;
        if ($id !== null) return $id ?: null;
        $row = \DB::table('t_req_category')
            ->where('category_code', 'qurbani')
            ->value('id');
        $id = (int) ($row ?: 0);
        return $id ?: null;
    }
}
