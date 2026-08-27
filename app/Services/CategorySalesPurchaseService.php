<?php

namespace App\Services;

use App\Services\QurbaniFinanceFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Category Level-1 SALES vs PURCHASES, bucketed by period.
 *
 * Sales are already categorised: t_crm_prod_product.attribute_1 IS
 * "Category Level 1" (there is no category table in this system).
 * Purchases are categorised by two nullable columns added Aug-2026 -
 * see database/migrations/category_sales_purchase_report_aug26_2026.sql.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  THE THREE RULES THIS FILE EXISTS TO KEEP IN ONE PLACE
 * ─────────────────────────────────────────────────────────────────────
 *
 *  1. A SALE'S DATE IS ITS DELIVERY DATE, and that date lives in the
 *     status history, never on the order row (OrderModel::delivery_date
 *     is an accessor). is_current is deliberately NOT filtered: when an
 *     order goes delivered -> completed the 'delivered' row flips to 0
 *     but is still the delivery event. MIN() picks one row per order so
 *     an order marked delivered twice cannot be counted twice.
 *
 *  2. THE PRODUCT JOIN IS EXCLUSIVE. SKU wins when present; the
 *     variant/product-id fallbacks apply ONLY when there is no SKU.
 *     Do not "simplify" it into plain ORs - without the exclusivity one
 *     line item matches several variant rows and every SUM inflates.
 *     It is worth the complexity: over a year the plain
 *     `p.id = li.product_id` join leaves 42% of line items (Rs 42.8m)
 *     unresolved, this one leaves 1.5%.
 *
 *  3. PURCHASES ARRIVE IN TWO SHAPES and must never be summed twice.
 *     by_weight vendors post a ledger row PLUS line items; by_total
 *     vendors post a bare ledger amount with no items. They are read by
 *     two separate queries partitioned on EXISTS(items), so a purchase
 *     belongs to exactly one of them.
 *
 * ─────────────────────────────────────────────────────────────────────
 *
 * Quantities are reported as kg and pieces SEPARATELY and never added
 * together - the two units are not commensurable. Purchases from
 * lump-sum vendors carry no quantity at all, which is why every qty
 * figure travels with a `qty_known` flag rather than a misleading 0.
 */
class CategorySalesPurchaseService
{
    public const G_DAY   = 'day';
    public const G_WEEK  = 'week';
    public const G_MONTH = 'month';
    public const G_TOTAL = 'total';

    /**
     * The business week starts WEDNESDAY (owner: "week starts Wednesday
     * till Monday night"). MySQL WEEKDAY() is 0=Mon..6=Sun, so 2 = Wed.
     *
     * The bucket is a full Wed->Tue span, not Wed->Mon. Tuesday is the
     * quiet day, but it is NOT empty (12 delivered orders and 25
     * purchases in the last 90 days), so a Wed->Mon bucket would
     * silently drop real money. Tuesday lands at the tail of the week
     * that began the prior Wednesday.
     */
    private const WEEK_START_WEEKDAY = 2;

    public const UNTAGGED      = 'Untagged';
    public const UNCATEGORIZED = 'Uncategorized';

    /** Statuses that mean "this order was actually delivered". */
    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    /**
     * Build the whole report.
     *
     * @return array{
     *   periods: array, categories: array, cells: array, totals: array,
     *   coverage: array, granularity: string
     * }
     */
    public function report(
        Carbon $start,
        Carbon $end,
        string $granularity = self::G_DAY,
        bool $excludeQurbani = true,
        array $hiddenCategories = []
    ): array {
        $granularity = $this->normalizeGranularity($granularity);
        $start = $start->copy()->startOfDay();
        $end   = $end->copy()->endOfDay();

        $sales     = $this->sales($start, $end, $granularity, $excludeQurbani);
        $purchases = $this->purchases($start, $end, $granularity, $excludeQurbani);

        // Merge the two sides into one period x category grid.
        $cells = [];
        foreach ($sales as $r) {
            $key = $r->period . '|' . $r->category;
            $cells[$key] = $this->blankCell($r->period, $r->category) + [];
            $cells[$key]['sold_rs']   = (float) $r->revenue;
            $cells[$key]['sold_kg']   = (float) $r->qty_kg;
            $cells[$key]['sold_pcs']  = (float) $r->qty_pcs;
            $cells[$key]['orders']    = (int) $r->orders;
        }
        foreach ($purchases as $r) {
            $key = $r->period . '|' . $r->category;
            if (!isset($cells[$key])) {
                $cells[$key] = $this->blankCell($r->period, $r->category);
            }
            $cells[$key]['bought_rs']      = (float) $r->amount;
            $cells[$key]['bought_kg']      = (float) $r->qty_kg;
            $cells[$key]['bought_pcs']     = (float) $r->qty_pcs;
            // Rupees that arrived as a bare ledger amount, i.e. with no
            // quantity attached. Drives the "kg not comparable" hint.
            $cells[$key]['bought_rs_noqty'] = (float) $r->amount_without_qty;
        }

        foreach ($cells as $k => $c) {
            $cells[$k]['margin_rs'] = $c['sold_rs'] - $c['bought_rs'];
        }

        // Personal display filter. Applied AFTER the money is computed, so
        // hiding is purely subtractive and the removed amounts are still
        // known exactly - the UI discloses them rather than losing them.
        // Everything on screen then adds up, which a money report must.
        $hidden = $this->splitHidden($cells, $hiddenCategories);

        $periods    = $this->collectPeriods($cells, $granularity);
        $categories = $this->collectCategories($cells);

        // ❄️ The current period is where "still in the freezer" is shown, but
        // periods are collected from SALES and PURCHASES — so on a day with no
        // deliveries yet (early morning, a closed day) today has no row at all
        // and the live stock would have nowhere to appear. Add the row when
        // there is stock to report; it carries no money, only the freezer
        // figure, and the money columns render as zero exactly as they should.
        $freezerStock  = $this->freezerStockByCategory();
        $currentPeriod = $this->currentPeriodKey($start, $end, $granularity);
        if ($currentPeriod !== null
            && $granularity !== self::G_TOTAL
            && array_sum(array_column($freezerStock, 'kg')) > 0
            && !in_array($currentPeriod, array_column($periods, 'key'), true)) {
            array_unshift($periods, [
                'key'   => $currentPeriod,
                'label' => $this->periodLabel($currentPeriod, $granularity),
            ]);
        }

        return [
            'granularity' => $granularity,
            'periods'     => $periods,
            'categories'  => $categories,
            'cells'       => $cells,
            'totals'      => $this->totals($cells, $categories),
            'coverage'    => $this->coverage($start, $end, $excludeQurbani),
            'hidden'      => $hidden,
            // DISTINCT orders across the window. The per-category counts
            // legitimately overlap (one order can hold mutton AND chicken),
            // so summing them overstates the total - this is the honest one.
            'orders_total' => $this->distinctOrders($start, $end, $excludeQurbani),

            // ❄️ FREEZER — deliberately alongside the money grid, never inside
            // it. `stock` is a live level (only meaningful for the period that
            // holds today, named by `current_period`); `flow` is real per-period
            // in/out history since `history_start`. `quiet_days` are days the
            // tracker recorded nothing, so an empty cell can be read correctly.
            'freezer' => [
                'stock'          => $freezerStock,
                'flow'           => $this->freezerFlowByPeriod($start, $end, $granularity),
                'current_period' => $currentPeriod,
                'history_start'  => $this->freezerHistoryStart(),
                'quiet_days'     => $this->freezerQuietDays($start, $end),
            ],
        ];
    }

    // =================================================================
    //  SALES
    // =================================================================

    private function sales(Carbon $start, Carbon $end, string $granularity, bool $excludeQurbani)
    {
        $period = $this->periodExpr($granularity, 'd.delivered_at');
        $piece  = $this->pieceCaseSql();
        $cat    = "COALESCE(NULLIF(TRIM(p.attribute_1), ''), '" . self::UNCATEGORIZED . "')";

        // One 'delivered' event per order, bounded to the window so the
        // scan never walks the whole history table.
        $delivered = DB::table('t_crm_order_status_history')
            ->select('order_id', DB::raw('MIN(changed_at) as delivered_at'))
            ->where('status_code', 'delivered')
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('order_id');

        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($delivered, 'd', fn ($j) => $j->on('d.order_id', '=', 'o.id'))
            ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')
                  ->orWhereNull('o.external_source');
            });

        $this->joinProduct($q);

        if ($excludeQurbani) {
            QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_EXCLUDE);
        }

        return $q->selectRaw("
                {$period} as period,
                {$cat} as category,
                COALESCE(SUM(li.line_total), 0)                              as revenue,
                COALESCE(SUM(CASE WHEN {$piece} THEN 0 ELSE li.quantity END), 0) as qty_kg,
                COALESCE(SUM(CASE WHEN {$piece} THEN li.quantity ELSE 0 END), 0) as qty_pcs,
                COUNT(DISTINCT o.id)                                          as orders
            ")
            // Group by the SELECT aliases, not by repeating the expressions:
            // under ONLY_FULL_GROUP_BY (on by default) MariaDB refuses to match
            // a repeated COALESCE(...) back to the selected one and throws 1055.
            ->groupByRaw('period, category')
            ->get();
    }

    /**
     * Removes the user's hidden categories from $cells (by reference) and
     * returns what was taken out, so the page can say exactly how much
     * money is being kept off screen. Matching is case-insensitive because
     * the values are owner-editable data, not constants.
     *
     * @return array{categories: array, sold_rs: float, bought_rs: float, count: int}
     */
    private function splitHidden(array &$cells, array $hiddenCategories): array
    {
        $out = ['categories' => [], 'sold_rs' => 0.0, 'bought_rs' => 0.0, 'count' => 0];
        if (empty($hiddenCategories)) {
            return $out;
        }

        $needles = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $hiddenCategories);

        foreach ($cells as $key => $c) {
            if (!in_array(mb_strtolower($c['category']), $needles, true)) {
                continue;
            }
            $name = $c['category'];
            if (!isset($out['categories'][$name])) {
                $out['categories'][$name] = ['category' => $name, 'sold_rs' => 0.0, 'bought_rs' => 0.0];
            }
            $out['categories'][$name]['sold_rs']   += $c['sold_rs'];
            $out['categories'][$name]['bought_rs'] += $c['bought_rs'];
            $out['sold_rs']   += $c['sold_rs'];
            $out['bought_rs'] += $c['bought_rs'];
            unset($cells[$key]);
        }

        uasort($out['categories'], fn ($a, $b) =>
            ($b['sold_rs'] + $b['bought_rs']) <=> ($a['sold_rs'] + $a['bought_rs']));
        $out['categories'] = array_values($out['categories']);
        $out['count'] = count($out['categories']);

        return $out;
    }

    /** Distinct delivered orders in the window (no category split). */
    private function distinctOrders(Carbon $start, Carbon $end, bool $excludeQurbani): int
    {
        $delivered = DB::table('t_crm_order_status_history')
            ->select('order_id', DB::raw('MIN(changed_at) as delivered_at'))
            ->where('status_code', 'delivered')
            ->whereBetween('changed_at', [$start, $end])
            ->groupBy('order_id');

        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($delivered, 'd', fn ($j) => $j->on('d.order_id', '=', 'o.id'))
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')
                  ->orWhereNull('o.external_source');
            });

        if ($excludeQurbani) {
            QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_EXCLUDE);
        }
        return (int) $q->count();
    }

    /**
     * The EXCLUSIVE product join (see rule 2 in the class docblock).
     * Copied from OrderController::openQuantitiesData via LaCarneController
     * so all four screens resolve a line item to a product identically.
     */
    private function joinProduct($query): void
    {
        $query->leftJoin('t_crm_prod_product_variant as pv', function ($join) {
            $join->where(function ($q) {
                $q->where(function ($skuMatch) {
                    $skuMatch->whereNotNull('li.sku')
                             ->where('li.sku', '!=', '')
                             ->whereColumn('li.sku', 'pv.sku');
                })
                ->orWhere(function ($fallback) {
                    $fallback->where(function ($noSku) {
                        $noSku->whereNull('li.sku')->orWhere('li.sku', '');
                    })
                    ->where(function ($idMatch) {
                        $idMatch->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                                ->orWhereColumn('li.variant_id', 'pv.id')
                                ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                                ->orWhereColumn('li.product_id', 'pv.id');
                    });
                });
            });
        })
        ->leftJoin('t_crm_prod_product as p', function ($join) {
            $join->where(function ($q) {
                $q->whereColumn('pv.product_id', 'p.id')
                  ->orWhere(function ($nameFallback) {
                      $nameFallback->whereNull('li.sku')
                                   ->where(function ($noIds) {
                                       $noIds->whereNull('li.variant_id')->orWhere('li.variant_id', '');
                                   })
                                   ->where(function ($noProdId) {
                                       $noProdId->whereNull('li.product_id')->orWhere('li.product_id', '');
                                   })
                                   ->whereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                  });
            });
        });
    }

    /**
     * TRUE when a line item is sold per PIECE rather than by weight.
     * Same heuristic as ExecutiveClosingService::pieceCaseSql so the two
     * screens split kg/pcs the same way.
     */
    private function pieceCaseSql(): string
    {
        $khaas = (int) ($this->khaasBusinessUnitId() ?: -1);

        return "(p.business_unit_id = {$khaas}"
            . " OR LOWER(li.name) LIKE '%per piece%'"
            . " OR LOWER(li.name) LIKE '%pcs%'"
            . " OR LOWER(li.name) LIKE '%pieces%'"
            . " OR LOWER(li.name) LIKE '%dozen%')";
    }

    private function khaasBusinessUnitId(): ?int
    {
        static $id = false;
        if ($id === false) {
            $id = (int) (DB::table('t_fin_business_units')->where('code', 'khaas')->value('id') ?: 0);
        }
        return $id ?: null;
    }

    // =================================================================
    //  PURCHASES
    // =================================================================

    /**
     * Purchases for the window, already categorised.
     *
     * Read in three passes that partition the money with no overlap:
     *   A. itemised  - ledger rows that HAVE line items (category + qty
     *                  per item)
     *   B. lump sum  - ledger rows that have NO line items (vendor
     *                  default category, no quantity)
     *   C. adjustments on itemised rows - amount = SUM(line_total) +
     *      adjustment_amount, so without this the report would not
     *      reconcile to the ledger. Tiny (Rs 30k across all history) but
     *      a money report that does not tie out invites doubt.
     */
    private function purchases(Carbon $start, Carbon $end, string $granularity, bool $excludeQurbani)
    {
        $period = $this->periodExpr($granularity, 'l.transaction_date');

        $itemCat = "COALESCE("
            . "NULLIF(TRIM(vp.category_level_1), ''), "
            . "NULLIF(TRIM(v.default_category_level_1), ''), "
            . "'" . self::UNTAGGED . "')";
        $vendorCat = "COALESCE(NULLIF(TRIM(v.default_category_level_1), ''), '" . self::UNTAGGED . "')";

        // --- A. itemised -------------------------------------------------
        $itemised = $this->purchaseBase($start, $end, $excludeQurbani)
            ->join('t_fin_vendor_purchase_items as i', 'i.ledger_id', '=', 'l.id')
            ->leftJoin('t_fin_vendor_products as vp', 'vp.id', '=', 'i.vendor_product_id')
            ->selectRaw("
                {$period} as period,
                {$itemCat} as category,
                COALESCE(SUM(i.line_total), 0) as amount,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(i.unit)) = 'kg' THEN i.quantity ELSE 0 END), 0) as qty_kg,
                COALESCE(SUM(CASE WHEN LOWER(TRIM(i.unit)) <> 'kg' THEN i.quantity ELSE 0 END), 0) as qty_pcs,
                0 as amount_without_qty
            ")
            ->groupByRaw('period, category')
            ->get();

        // --- B. lump sum -------------------------------------------------
        $lumpSum = $this->purchaseBase($start, $end, $excludeQurbani)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('t_fin_vendor_purchase_items as i2')
                    ->whereColumn('i2.ledger_id', 'l.id');
            })
            ->selectRaw("
                {$period} as period,
                {$vendorCat} as category,
                COALESCE(SUM(l.amount), 0) as amount,
                0 as qty_kg,
                0 as qty_pcs,
                COALESCE(SUM(l.amount), 0) as amount_without_qty
            ")
            ->groupByRaw('period, category')
            ->get();

        // --- C. adjustments on itemised rows -----------------------------
        $adjustments = $this->purchaseBase($start, $end, $excludeQurbani)
            ->whereRaw('COALESCE(l.adjustment_amount, 0) <> 0')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('t_fin_vendor_purchase_items as i3')
                    ->whereColumn('i3.ledger_id', 'l.id');
            })
            ->selectRaw("
                {$period} as period,
                {$vendorCat} as category,
                COALESCE(SUM(l.adjustment_amount), 0) as amount,
                0 as qty_kg,
                0 as qty_pcs,
                COALESCE(SUM(l.adjustment_amount), 0) as amount_without_qty
            ")
            ->groupByRaw('period, category')
            ->get();

        return $this->mergePurchaseRows($itemised, $lumpSum, $adjustments);
    }

    /** Shared FROM/WHERE for every purchase pass. */
    private function purchaseBase(Carbon $start, Carbon $end, bool $excludeQurbani)
    {
        $q = DB::table('t_fin_ledger as l')
            // 1:1 on production - a vendor owns exactly one payable account.
            ->join('t_fin_vendors as v', 'v.account_id', '=', 'l.to_account_id')
            ->where('l.transaction_type', 'vendor_purchase')
            ->where('l.approval_status', 'approved')
            ->whereBetween('l.transaction_date', [$start->toDateString(), $end->toDateString()]);

        if ($excludeQurbani) {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
        }

        return $q;
    }

    /** Fold the three purchase passes onto one period+category key. */
    private function mergePurchaseRows(...$sets)
    {
        $merged = [];
        foreach ($sets as $set) {
            foreach ($set as $r) {
                $key = $r->period . '|' . $r->category;
                if (!isset($merged[$key])) {
                    $merged[$key] = (object) [
                        'period'            => $r->period,
                        'category'          => $r->category,
                        'amount'            => 0.0,
                        'qty_kg'            => 0.0,
                        'qty_pcs'           => 0.0,
                        'amount_without_qty' => 0.0,
                    ];
                }
                $merged[$key]->amount             += (float) $r->amount;
                $merged[$key]->qty_kg             += (float) $r->qty_kg;
                $merged[$key]->qty_pcs            += (float) $r->qty_pcs;
                $merged[$key]->amount_without_qty += (float) $r->amount_without_qty;
            }
        }
        return collect(array_values($merged));
    }

    /**
     * Vendor-level detail behind ONE purchase cell (a category within a
     * date window): which vendors, which products, what quantity, what
     * money.
     *
     * Mirrors the three passes of purchases() exactly - itemised lines,
     * lump-sum rows, adjustments - so the drill's total always equals the
     * cell it explains. If these ever disagree, the bug is that this
     * method and purchases() drifted apart.
     */
    public function purchaseDrill(Carbon $start, Carbon $end, string $category, bool $excludeQurbani): array
    {
        $itemCat = "COALESCE("
            . "NULLIF(TRIM(vp.category_level_1), ''), "
            . "NULLIF(TRIM(v.default_category_level_1), ''), "
            . "'" . self::UNTAGGED . "')";
        $vendorCat = "COALESCE(NULLIF(TRIM(v.default_category_level_1), ''), '" . self::UNTAGGED . "')";

        // Pass A: itemised lines whose resolved category matches.
        $items = $this->purchaseBase($start, $end, $excludeQurbani)
            ->join('t_fin_vendor_purchase_items as i', 'i.ledger_id', '=', 'l.id')
            ->leftJoin('t_fin_vendor_products as vp', 'vp.id', '=', 'i.vendor_product_id')
            ->whereRaw("{$itemCat} = ?", [$category])
            ->selectRaw("v.id as vendor_id, v.vendor_name, v.default_purchase_method,
                         v.default_category_level_1 as vendor_category, l.transaction_date,
                         i.product_name, i.quantity, i.unit, i.line_total as amount,
                         vp.id as vendor_product_id, vp.category_level_1 as product_category,
                         'item' as kind")
            ->orderBy('l.transaction_date')
            ->get();

        // Pass B: lump-sum rows filed under the vendor default.
        $lumps = $this->purchaseBase($start, $end, $excludeQurbani)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('t_fin_vendor_purchase_items as i2')
                    ->whereColumn('i2.ledger_id', 'l.id');
            })
            ->whereRaw("{$vendorCat} = ?", [$category])
            ->selectRaw("v.id as vendor_id, v.vendor_name, v.default_purchase_method,
                         v.default_category_level_1 as vendor_category, l.transaction_date,
                         COALESCE(NULLIF(TRIM(l.description), ''), 'Lump-sum purchase') as product_name,
                         NULL as quantity, NULL as unit, l.amount as amount,
                         NULL as vendor_product_id, NULL as product_category,
                         'lump' as kind")
            ->orderBy('l.transaction_date')
            ->get();

        // Pass C: adjustments riding on itemised rows.
        $adjs = $this->purchaseBase($start, $end, $excludeQurbani)
            ->whereRaw('COALESCE(l.adjustment_amount, 0) <> 0')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('t_fin_vendor_purchase_items as i3')
                    ->whereColumn('i3.ledger_id', 'l.id');
            })
            ->whereRaw("{$vendorCat} = ?", [$category])
            ->selectRaw("v.id as vendor_id, v.vendor_name, v.default_purchase_method,
                         v.default_category_level_1 as vendor_category, l.transaction_date,
                         'Adjustment' as product_name,
                         NULL as quantity, NULL as unit, l.adjustment_amount as amount,
                         NULL as vendor_product_id, NULL as product_category,
                         'adjustment' as kind")
            ->orderBy('l.transaction_date')
            ->get();

        // Group vendor -> product. Aggregating by product (rather than listing
        // every weighing) is what makes the popup readable: Ghousia is 47 raw
        // lines but only one product. Each product row carries the tag handle
        // it would be retagged by, so the popup can fix categories in place.
        $vendors = [];
        foreach ([$items, $lumps, $adjs] as $set) {
            foreach ($set as $r) {
                $vid = (int) $r->vendor_id;
                if (!isset($vendors[$vid])) {
                    $vendors[$vid] = [
                        'vendor_id'        => $vid,
                        'vendor_name'      => $r->vendor_name,
                        'purchase_method'  => $r->default_purchase_method,
                        'vendor_category'  => $r->vendor_category,
                        'total'            => 0.0,
                        'qty_kg'           => 0.0,
                        'qty_pcs'          => 0.0,
                        'products'         => [],
                    ];
                }
                $v = &$vendors[$vid];
                $v['total'] += (float) $r->amount;

                $isItem = $r->kind === 'item';
                $isKg   = $isItem && strtolower(trim((string) $r->unit)) === 'kg';
                if ($isKg)                      { $v['qty_kg']  += (float) $r->quantity; }
                elseif ($isItem)                { $v['qty_pcs'] += (float) $r->quantity; }

                // Lump sums and adjustments have no product of their own, so
                // they collapse onto the vendor and are retagged via its default.
                $key = $isItem
                    ? 'p' . (int) $r->vendor_product_id
                    : ($r->kind === 'adjustment' ? 'adj' : 'lump');

                if (!isset($v['products'][$key])) {
                    $v['products'][$key] = [
                        'name'      => $isItem ? $r->product_name
                                     : ($r->kind === 'adjustment' ? 'Adjustments' : 'Lump-sum purchases'),
                        'kind'      => $r->kind,
                        'tag_scope' => ($isItem && $r->vendor_product_id) ? 'product' : 'vendor',
                        'tag_id'    => ($isItem && $r->vendor_product_id) ? (int) $r->vendor_product_id : $vid,
                        'tag_value' => ($isItem && $r->vendor_product_id)
                                        ? $r->product_category
                                        : $r->vendor_category,
                        'amount'    => 0.0,
                        'qty_kg'    => 0.0,
                        'qty_pcs'   => 0.0,
                        'count'     => 0,
                        'examples'  => [],
                    ];
                }
                $p = &$v['products'][$key];
                $p['amount'] += (float) $r->amount;
                $p['count']++;
                if ($isKg)       { $p['qty_kg']  += (float) $r->quantity; }
                elseif ($isItem) { $p['qty_pcs'] += (float) $r->quantity; }
                // Keep a few raw rows so a lump sum still shows what it was for.
                if (count($p['examples']) < 6) {
                    $p['examples'][] = [
                        'date'   => $r->transaction_date,
                        'label'  => $isItem ? null : $r->product_name,
                        'qty'    => $r->quantity !== null ? (float) $r->quantity : null,
                        'unit'   => $r->unit,
                        'amount' => (float) $r->amount,
                    ];
                }
                unset($p, $v);
            }
        }

        foreach ($vendors as &$v) {
            usort($v['products'], fn ($a, $b) => $b['amount'] <=> $a['amount']);
        }
        unset($v);
        usort($vendors, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'category' => $category,
            'from'     => $start->toDateString(),
            'to'       => $end->toDateString(),
            'total'    => array_sum(array_column($vendors, 'total')),
            'vendors'  => array_values($vendors),
        ];
    }

    // =================================================================
    //  PERIOD BUCKETING
    // =================================================================

    /**
     * SQL expression yielding the START DATE of the bucket a row falls in.
     * For 'total' every row collapses to one literal bucket.
     */
    private function periodExpr(string $granularity, string $dateExpr): string
    {
        switch ($granularity) {
            case self::G_WEEK:
                $w = self::WEEK_START_WEEKDAY;
                return "DATE(DATE_SUB({$dateExpr}, INTERVAL ((WEEKDAY({$dateExpr}) - {$w} + 7) % 7) DAY))";

            case self::G_MONTH:
                return "DATE(DATE_FORMAT({$dateExpr}, '%Y-%m-01'))";

            case self::G_TOTAL:
                return "'total'";

            case self::G_DAY:
            default:
                return "DATE({$dateExpr})";
        }
    }

    /**
     * Concrete [from, to] dates for one bucket key, clamped to the report
     * range. Clamping matters: a week bucket can begin before the range
     * started, but the cell only counted rows inside the range - so the
     * drill must too, or it would show more than the cell it explains.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bucketBounds(string $periodKey, string $granularity, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        if ($granularity === self::G_TOTAL || $periodKey === 'total' || $periodKey === '__range') {
            return [$rangeStart->copy(), $rangeEnd->copy()];
        }

        $from = Carbon::parse($periodKey)->startOfDay();
        switch ($granularity) {
            case self::G_WEEK:  $to = $from->copy()->addDays(6);  break;
            case self::G_MONTH: $to = $from->copy()->endOfMonth(); break;
            default:            $to = $from->copy();               break;
        }

        return [$from->max($rangeStart), $to->min($rangeEnd)];
    }

    public function normalizeGranularity(?string $g): string
    {
        $g = strtolower(trim((string) $g));
        return in_array($g, [self::G_DAY, self::G_WEEK, self::G_MONTH, self::G_TOTAL], true)
            ? $g
            : self::G_DAY;
    }

    /** Human label for a bucket, e.g. "Wed 20 Aug – Tue 26 Aug". */
    public function periodLabel(string $period, string $granularity): string
    {
        if ($granularity === self::G_TOTAL || $period === 'total') {
            return 'Whole range';
        }
        try {
            $d = Carbon::parse($period);
        } catch (\Throwable $e) {
            return $period;
        }

        switch ($granularity) {
            case self::G_WEEK:
                return $d->format('D j M') . ' – ' . $d->copy()->addDays(6)->format('D j M');
            case self::G_MONTH:
                return $d->format('F Y');
            default:
                return $d->format('D, j M Y');
        }
    }

    private function collectPeriods(array $cells, string $granularity): array
    {
        $periods = array_values(array_unique(array_map(fn ($c) => $c['period'], $cells)));
        rsort($periods); // newest first
        return array_map(fn ($p) => [
            'key'   => $p,
            'label' => $this->periodLabel($p, $granularity),
        ], $periods);
    }

    /**
     * Categories present anywhere in the grid, ordered by how much money
     * moved through them (sold + bought) so the big ones lead.
     */
    private function collectCategories(array $cells): array
    {
        $weight = [];
        foreach ($cells as $c) {
            $weight[$c['category']] = ($weight[$c['category']] ?? 0)
                + abs($c['sold_rs']) + abs($c['bought_rs']);
        }
        arsort($weight);
        return array_keys($weight);
    }

    private function blankCell(string $period, string $category): array
    {
        return [
            'period'          => $period,
            'category'        => $category,
            'sold_rs'         => 0.0,
            'sold_kg'         => 0.0,
            'sold_pcs'        => 0.0,
            'orders'          => 0,
            'bought_rs'       => 0.0,
            'bought_kg'       => 0.0,
            'bought_pcs'      => 0.0,
            'bought_rs_noqty' => 0.0,
            'margin_rs'       => 0.0,
        ];
    }

    /** Column totals per category, plus a grand total. */
    private function totals(array $cells, array $categories): array
    {
        $out = [];
        foreach ($categories as $cat) {
            $out[$cat] = $this->blankCell('total', $cat);
        }
        $grand = $this->blankCell('total', 'ALL');

        foreach ($cells as $c) {
            foreach (['sold_rs','sold_kg','sold_pcs','orders','bought_rs','bought_kg','bought_pcs','bought_rs_noqty','margin_rs'] as $f) {
                $out[$c['category']][$f] += $c[$f];
                $grand[$f]               += $c[$f];
            }
        }
        $out['__grand'] = $grand;
        return $out;
    }

    // =================================================================
    //  FREEZER  (overnight storage — the FREEZER section only)
    // =================================================================
    //
    // Owner rule (Aug-27-2026): this report shows the FREEZER only, never the
    // chiller. Two different questions, deliberately answered differently:
    //
    //   · "what is still in there"  — a stock LEVEL. Only ever true for NOW,
    //     so it is shown on the whole-range table and on the period row that
    //     CONTAINS TODAY. Painting it on a past row would be a lie: no
    //     historical freezer balance exists (see the plan doc).
    //   · "what came out"           — a FLOW. Real history, per day, since the
    //     overnight tracker went live on 2026-08-01.
    //
    // Both are keyed by the SAME category vocabulary as sales (product
    // attribute_1), so a row lines up with the money on its left.
    //
    // ⚠ These never touch blankCell()'s money fields. They ride alongside in
    // their own arrays, so the column sums and the margin identity are
    // untouched by anything here.

    /** First day the overnight tracker holds data — nothing exists before it. */
    public function freezerHistoryStart(): ?string
    {
        static $d = false;
        if ($d === false) {
            $d = $this->overnightReady()
                ? DB::table('t_crm_overnight_log')->min('created_at')
                : null;
            $d = $d ? Carbon::parse($d)->toDateString() : null;
        }
        return $d;
    }

    /**
     * Is the overnight tracker present? Keeps the report working on any
     * database where its tables have not been created.
     */
    private function overnightReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = \Schema::hasTable('t_crm_overnight_item')
                    && \Schema::hasTable('t_crm_overnight_log');
            } catch (\Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * What is sitting in the FREEZER right now, per category.
     *
     * @return array<string, array{kg: float, packets: int}>
     */
    public function freezerStockByCategory(): array
    {
        if (!$this->overnightReady()) {
            return [];
        }

        $rows = DB::table('t_crm_overnight_item as oi')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'oi.product_id')
            ->where('oi.status', 'stored')
            ->where('oi.section', 'freezer')
            ->selectRaw("
                COALESCE(NULLIF(TRIM(p.attribute_1), ''), '" . self::UNCATEGORIZED . "') AS category,
                COALESCE(SUM(CASE WHEN oi.unit = 'kg' THEN oi.quantity ELSE 0 END), 0) AS kg,
                COUNT(*) AS packets
            ")
            ->groupBy('category')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->category] = ['kg' => (float) $r->kg, 'packets' => (int) $r->packets];
        }
        return $out;
    }

    /**
     * Everything that went INTO and OUT OF the freezer, per period x category.
     *
     * Owner rules (Aug-27-2026):
     *  · a packet moved freezer -> chiller counts as taken OUT, exactly like one
     *    taken out for use — it is no longer in the freezer;
     *  · by the same logic a packet moved chiller -> freezer counts as going IN;
     *  · showing both directions is what makes the day readable ("we put 105 kg
     *    in and took 14 kg out"), and it surfaces extra buying on the day.
     *
     * So the test is simply which side of the row names the freezer:
     *   IN  = anything whose to_section   is the freezer (stored, or moved in)
     *   OUT = anything whose from_section is the freezer (taken out, or moved out)
     * The two ways each direction can happen stay separately readable for the
     * tooltip, so a number can always be explained.
     *
     * @return array<string, array{in_kg: float, out_kg: float, in_packets: int,
     *                             out_packets: int, taken_kg: float, moved_out_kg: float,
     *                             stored_kg: float, moved_in_kg: float}>
     *         keyed "period|category"
     */
    public function freezerFlowByPeriod(Carbon $start, Carbon $end, string $granularity): array
    {
        if (!$this->overnightReady()) {
            return [];
        }

        $granularity = $this->normalizeGranularity($granularity);
        $periodExpr  = $this->periodExpr($granularity, 'l.created_at');

        $rows = DB::table('t_crm_overnight_log as l')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.action', ['in', 'out', 'move'])
            ->where(function ($w) {
                $w->where('l.from_section', 'freezer')->orWhere('l.to_section', 'freezer');
            })
            ->whereBetween('l.created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->selectRaw("
                {$periodExpr} AS period,
                COALESCE(NULLIF(TRIM(p.attribute_1), ''), '" . self::UNCATEGORIZED . "') AS category,
                COALESCE(SUM(CASE WHEN l.to_section   = 'freezer' AND l.unit = 'kg' THEN l.quantity ELSE 0 END), 0) AS in_kg,
                COALESCE(SUM(CASE WHEN l.from_section = 'freezer' AND l.unit = 'kg' THEN l.quantity ELSE 0 END), 0) AS out_kg,
                COALESCE(SUM(CASE WHEN l.to_section   = 'freezer' THEN 1 ELSE 0 END), 0) AS in_packets,
                COALESCE(SUM(CASE WHEN l.from_section = 'freezer' THEN 1 ELSE 0 END), 0) AS out_packets,
                COALESCE(SUM(CASE WHEN l.action = 'out'  AND l.unit = 'kg' THEN l.quantity ELSE 0 END), 0) AS taken_kg,
                COALESCE(SUM(CASE WHEN l.action = 'in'   AND l.unit = 'kg' THEN l.quantity ELSE 0 END), 0) AS stored_kg,
                COALESCE(SUM(CASE WHEN l.action = 'move' AND l.from_section = 'freezer' AND l.unit = 'kg' THEN l.quantity ELSE 0 END), 0) AS moved_out_kg,
                COALESCE(SUM(CASE WHEN l.action = 'move' AND l.to_section   = 'freezer' AND l.unit = 'kg' THEN l.quantity ELSE 0 END), 0) AS moved_in_kg
            ")
            // GROUP BY the SELECT ALIASES — repeating the COALESCE expression
            // trips MariaDB ONLY_FULL_GROUP_BY (error 1055).
            ->groupBy('period', 'category')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->period . '|' . $r->category] = [
                'in_kg'        => (float) $r->in_kg,
                'out_kg'       => (float) $r->out_kg,
                'in_packets'   => (int) $r->in_packets,
                'out_packets'  => (int) $r->out_packets,
                'taken_kg'     => (float) $r->taken_kg,
                'moved_out_kg' => (float) $r->moved_out_kg,
                'stored_kg'    => (float) $r->stored_kg,
                'moved_in_kg'  => (float) $r->moved_in_kg,
            ];
        }
        return $out;
    }

    /**
     * Every freezer movement behind one cell — the packets themselves, so a
     * number on the report can always be taken apart into the events that
     * made it, with who did each one.
     *
     * @return array{from:string,to:string,category:string,in_kg:float,out_kg:float,events:array}
     */
    public function freezerDrill(Carbon $from, Carbon $to, string $category): array
    {
        $empty = ['from' => $from->format('j M Y'), 'to' => $to->format('j M Y'),
                  'category' => $category, 'in_kg' => 0.0, 'out_kg' => 0.0, 'events' => []];

        if (!$this->overnightReady()) {
            return $empty;
        }

        $cat = "COALESCE(NULLIF(TRIM(p.attribute_1), ''), '" . self::UNCATEGORIZED . "')";

        $rows = DB::table('t_crm_overnight_log as l')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.created_by')
            ->whereIn('l.action', ['in', 'out', 'move'])
            ->where(function ($w) {
                $w->where('l.from_section', 'freezer')->orWhere('l.to_section', 'freezer');
            })
            ->whereBetween('l.created_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->whereRaw("{$cat} = ?", [$category])
            ->orderBy('l.created_at')
            ->orderBy('l.id')
            ->limit(400)
            ->get([
                'l.id', 'l.action', 'l.from_section', 'l.to_section', 'l.product_name',
                'l.quantity', 'l.unit', 'l.source', 'l.created_at', 'u.fullname as by_name',
            ]);

        $events = [];
        $inKg = 0.0;
        $outKg = 0.0;
        foreach ($rows as $r) {
            $isIn  = $r->to_section === 'freezer';
            $qty   = (float) $r->quantity;
            if ($r->unit === 'kg') {
                $isIn ? $inKg += $qty : $outKg += $qty;
            }

            // Say what actually happened to the packet, in the freezer's terms.
            if ($r->action === 'move') {
                $label = $isIn ? 'Moved in from the chiller' : 'Moved out to the chiller';
            } elseif ($r->action === 'in') {
                $label = 'Put in the freezer';
            } else {
                $label = 'Taken out of the freezer';
            }

            $events[] = [
                'id'           => $r->id,
                'direction'    => $isIn ? 'in' : 'out',
                'label'        => $label,
                'product_name' => $r->product_name,
                'quantity'     => round($qty, 3),
                'unit'         => $r->unit,
                'source'       => $r->source,
                'by_name'      => $r->by_name ?: 'System',
                'at'           => $r->created_at ? Carbon::parse($r->created_at)->format('j M, g:i A') : '',
            ];
        }

        return [
            'from'     => $from->format('j M Y'),
            'to'       => $to->format('j M Y'),
            'category' => $category,
            'in_kg'    => round($inKg, 3),
            'out_kg'   => round($outKg, 3),
            'events'   => $events,
        ];
    }

    /**
     * What was actually sold inside one cell, broken down to the product —
     * grouped under its Level-2 attribute so the detail reads as a hierarchy
     * rather than a flat list.
     *
     * Uses the SAME delivered-event source and exclusive product join as
     * sales(), so the drill always adds up to the cell it explains.
     *
     * @return array{from:string,to:string,category:string,total:float,orders:int,groups:array}
     */
    public function salesDrill(Carbon $from, Carbon $to, string $category, bool $excludeQurbani): array
    {
        $piece = $this->pieceCaseSql();
        $cat   = "COALESCE(NULLIF(TRIM(p.attribute_1), ''), '" . self::UNCATEGORIZED . "')";
        $lvl2  = "COALESCE(NULLIF(TRIM(p.attribute_2), ''), '—')";

        $delivered = DB::table('t_crm_order_status_history')
            ->select('order_id', DB::raw('MIN(changed_at) as delivered_at'))
            ->where('status_code', 'delivered')
            ->whereBetween('changed_at', [$from, $to])
            ->groupBy('order_id');

        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($delivered, 'd', fn ($j) => $j->on('d.order_id', '=', 'o.id'))
            ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')
                  ->orWhereNull('o.external_source');
            });

        $this->joinProduct($q);

        if ($excludeQurbani) {
            QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_EXCLUDE);
        }

        $rows = $q->whereRaw("{$cat} = ?", [$category])
            ->selectRaw("
                {$lvl2} AS level2,
                COALESCE(NULLIF(TRIM(p.title), ''), li.name) AS product_name,
                COALESCE(SUM(li.line_total), 0) AS revenue,
                COALESCE(SUM(CASE WHEN {$piece} THEN 0 ELSE li.quantity END), 0) AS qty_kg,
                COALESCE(SUM(CASE WHEN {$piece} THEN li.quantity ELSE 0 END), 0) AS qty_pcs,
                COUNT(DISTINCT o.id) AS orders
            ")
            ->groupByRaw('level2, product_name')
            ->orderByRaw('revenue DESC')
            ->limit(300)
            ->get();

        // Fold the flat rows into Level-2 groups, keeping both orders sane:
        // groups by money, products by money inside each group.
        $groups = [];
        $total  = 0.0;
        foreach ($rows as $r) {
            $total += (float) $r->revenue;
            if (!isset($groups[$r->level2])) {
                $groups[$r->level2] = ['level2' => $r->level2, 'revenue' => 0.0,
                                       'qty_kg' => 0.0, 'qty_pcs' => 0.0, 'products' => []];
            }
            $groups[$r->level2]['revenue'] += (float) $r->revenue;
            $groups[$r->level2]['qty_kg']  += (float) $r->qty_kg;
            $groups[$r->level2]['qty_pcs'] += (float) $r->qty_pcs;
            $groups[$r->level2]['products'][] = [
                'product_name' => $r->product_name,
                'revenue'      => (float) $r->revenue,
                'qty_kg'       => (float) $r->qty_kg,
                'qty_pcs'      => (float) $r->qty_pcs,
                'orders'       => (int) $r->orders,
            ];
        }
        usort($groups, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'from'     => $from->format('j M Y'),
            'to'       => $to->format('j M Y'),
            'category' => $category,
            'total'    => round($total, 2),
            'orders'   => 0,
            'groups'   => array_values($groups),
        ];
    }

    /**
     * Which period bucket contains TODAY, so the UI knows the single row that
     * may show "still in the freezer" instead of "taken out". Returns null
     * when the report range ends in the past — then every row is history and
     * no live stock is shown anywhere in the period table.
     */
    public function currentPeriodKey(Carbon $start, Carbon $end, string $granularity): ?string
    {
        $today = Carbon::today();
        if ($today->lt($start->copy()->startOfDay()) || $today->gt($end->copy()->endOfDay())) {
            return null;
        }

        switch ($this->normalizeGranularity($granularity)) {
            case self::G_TOTAL:
                return 'total';
            case self::G_MONTH:
                return $today->copy()->startOfMonth()->toDateString();
            case self::G_WEEK:
                $delta = ($today->dayOfWeekIso - 1 - self::WEEK_START_WEEKDAY + 7) % 7;
                return $today->copy()->subDays($delta)->toDateString();
            case self::G_DAY:
            default:
                return $today->toDateString();
        }
    }

    /**
     * Days inside the window that the tracker recorded NO movement for.
     * Shown so a quiet day is visibly "nothing was recorded" rather than
     * silently reading as "nothing happened" — the two are indistinguishable
     * in the data, and only the store team can tell them apart.
     *
     * @return array<int, string> Y-m-d, ascending. Days before the tracker
     *         went live are excluded (there is nothing to expect there).
     */
    public function freezerQuietDays(Carbon $start, Carbon $end): array
    {
        if (!$this->overnightReady()) {
            return [];
        }

        $liveFrom = $this->freezerHistoryStart();
        if (!$liveFrom) {
            return [];
        }

        $from = Carbon::parse($liveFrom)->startOfDay();
        if ($from->lt($start->copy()->startOfDay())) {
            $from = $start->copy()->startOfDay();
        }
        $to = $end->copy()->endOfDay();
        if ($to->gt(Carbon::today()->endOfDay())) {
            $to = Carbon::today()->endOfDay();
        }
        if ($from->gt($to)) {
            return [];
        }

        // Any movement at all counts as "recorded", in either section: the
        // question is whether the team was using the tracker that day.
        $active = DB::table('t_crm_overnight_log')
            ->whereIn('action', ['in', 'out', 'move', 'verify'])
            ->whereBetween('created_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw('DATE(created_at) AS d')
            ->distinct()
            ->pluck('d')
            ->all();
        $active = array_flip(array_map(fn ($d) => (string) $d, $active));

        $quiet = [];
        for ($c = $from->copy(); $c->lte($to); $c->addDay()) {
            $key = $c->toDateString();
            if (!isset($active[$key])) {
                $quiet[] = $key;
            }
        }
        return $quiet;
    }

    // =================================================================
    //  COVERAGE  (how trustworthy is this window?)
    // =================================================================

    /**
     * What the numbers cannot tell you by themselves: how much of the
     * purchase side is uncategorised or has no quantity. Surfaced in the
     * UI so a partly-tagged catalogue never reads as a real shortfall.
     */
    private function coverage(Carbon $start, Carbon $end, bool $excludeQurbani): array
    {
        $rows = $this->purchaseBase($start, $end, $excludeQurbani)
            ->selectRaw("
                COUNT(*) as purchases,
                COALESCE(SUM(l.amount), 0) as total_rs,
                COALESCE(SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM t_fin_vendor_purchase_items i WHERE i.ledger_id = l.id
                ) THEN l.amount ELSE 0 END), 0) as itemised_rs
            ")
            ->first();

        $untaggedRs = 0.0;
        foreach ($this->purchases($start, $end, self::G_TOTAL, $excludeQurbani) as $r) {
            if ($r->category === self::UNTAGGED) {
                $untaggedRs += (float) $r->amount;
            }
        }

        $total = (float) ($rows->total_rs ?? 0);

        return [
            'purchases'       => (int) ($rows->purchases ?? 0),
            'total_rs'        => $total,
            'itemised_rs'     => (float) ($rows->itemised_rs ?? 0),
            'itemised_pct'    => $total > 0 ? round(((float) $rows->itemised_rs) / $total * 100) : 0,
            'untagged_rs'     => $untaggedRs,
            'untagged_pct'    => $total > 0 ? round($untaggedRs / $total * 100) : 0,
        ];
    }

    // =================================================================
    //  TAGGING SUPPORT
    // =================================================================

    /**
     * The category vocabulary offered in the tagging dropdowns: whatever
     * Level 1 values actually exist on products, plus 'Other' for the
     * non-meat vendors (grocery, gas, packaging) that must be visible
     * but never counted inside a meat category.
     *
     * Read from the products table on purpose - a free-text purchase
     * category would never line up with the sales side. ("Veal" on the
     * purchase side is sold as "Beef".)
     */
    public function categoryVocabulary(): array
    {
        $fromProducts = DB::table('t_crm_prod_product')
            ->select('attribute_1')
            ->whereNotNull('attribute_1')
            ->where('attribute_1', '!=', '')
            ->where('is_active', 1)
            ->groupBy('attribute_1')
            ->orderBy('attribute_1')
            ->pluck('attribute_1')
            ->map(fn ($v) => trim($v))
            ->filter()
            ->values()
            ->all();

        if (!in_array('Other', $fromProducts, true)) {
            $fromProducts[] = 'Other';
        }
        return $fromProducts;
    }

    /**
     * Every category the report can ever show, for the "categories shown"
     * picker. Deliberately NOT limited to what appears in the current
     * range: a category with no data this week must still be toggleable,
     * or the checkbox would vanish exactly when it is quiet.
     *
     * Includes the two synthetic buckets the report itself can produce.
     */
    public function togglableCategories(): array
    {
        $all = $this->categoryVocabulary();
        foreach ([self::UNTAGGED, self::UNCATEGORIZED] as $synthetic) {
            if (!in_array($synthetic, $all, true)) {
                $all[] = $synthetic;
            }
        }
        return $all;
    }

    /**
     * Everything the tagging screen needs: each vendor, its default
     * category, and its catalogue - so untagged rows can be found and
     * fixed in one place instead of vendor by vendor.
     */
    public function taggingRows(): array
    {
        $vendors = DB::table('t_fin_vendors')
            ->select('id', 'vendor_name', 'default_category_level_1', 'default_purchase_method', 'is_active')
            ->orderBy('vendor_name')
            ->get();

        $products = DB::table('t_fin_vendor_products as vp')
            ->select('vp.id', 'vp.vendor_id', 'vp.product_name', 'vp.unit', 'vp.category_level_1', 'vp.is_active')
            ->orderBy('vp.product_name')
            ->get()
            ->groupBy('vendor_id');

        return $vendors->map(function ($v) use ($products) {
            $items = $products->get($v->id, collect());
            return [
                'vendor'   => $v,
                'products' => $items->all(),
                'untagged' => $items->filter(fn ($p) => trim((string) $p->category_level_1) === '')->count()
                              + (trim((string) $v->default_category_level_1) === '' ? 1 : 0),
            ];
        })->all();
    }
}
