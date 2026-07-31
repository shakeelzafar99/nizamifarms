<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\CRM\CustomerModel;

/**
 * Shared campaign-filtering logic used by both the web (CampaignWebController)
 * and the mobile API (RiderController). Centralising it here keeps the two
 * entry points in lockstep so a campaign created / refreshed on either
 * surface behaves identically.
 *
 * Supported filter payload shapes (accepted by every public method that takes
 * $filters):
 *
 *   Legacy (pre-multi-group):
 *       { activity: '90day', city: '...', min_spend: .., sort_by, sort_dir }
 *
 *   New (multi-group + exclusions):
 *       {
 *         groups: [
 *           { activity: '90day', label: 'Active 90d' },
 *           { qurbani_year: 2025 },
 *           ...
 *         ],
 *         exclude_campaign_ids: [1, 2],            // drop anyone 'sent' in these
 *         exclude_campaign_customer_ids: [3, 4],   // drop anyone at ANY status in these
 *         sort_by: 'last_order_date',
 *         sort_dir: 'desc'
 *       }
 */
class CampaignFilterService
{
    /**
     * How far back we look when classifying a customer as "Shopify".
     * 300 days keeps the window wide enough to not churn a customer out of
     * the segment between two Qurbani seasons, while still reflecting
     * roughly "this customer is currently using Shopify".
     *
     * If you ever need to tune this, it's the ONLY place it lives — both
     * the campaigns preview filter and the per-customer badge query read
     * this constant via `shopifyExistsExpr()`, so web and mobile stay in
     * lockstep automatically.
     */
    public const SHOPIFY_RECENT_WINDOW_DAYS = 300;

    /**
     * A raw-SQL boolean expression that is TRUE when the given customer row
     * should be classified as a Shopify customer.
     *
     * Uses a **combo** (user-requested):
     *   • Primary signal: the customer has at least one approved production
     *     order whose `order_number` starts with 'SH' within the recent
     *     window (SH-prefix is how `OrderController@convertShopifyOrder`
     *     tags Shopify orders once they're promoted into `t_crm_prod_order`).
     *   • Guardrail: the customer's `external_customer_ids` JSON contains a
     *     `shopify` key (set by `CustomerModel::findOrCreateByPhone` the
     *     first time a Shopify order for that phone is seen, including ones
     *     that are still pending approval in `t_crm_shopify_order`).
     *
     * Why both? The primary signal is the truthful ledger-based answer; the
     * guardrail catches rows where the Shopify order is still awaiting
     * approval (so it hasn't promoted to `t_crm_prod_order` yet) OR where
     * the customer's last Shopify order is older than 300 days but we still
     * want to preserve their Shopify identity.
     *
     * Performance: the EXISTS uses the existing `customer_id` index on
     * `t_crm_prod_order`, short-circuits on first match, and is the same
     * access pattern already used for `qurbani_year` (see
     * `buildFilterGroupQuery`). The JSON check is evaluated on the already-
     * loaded customer row and costs microseconds. No new index needed.
     *
     * @param string $customerAlias Alias or table name the customer row
     *                              is referenced by in the outer query.
     *                              Defaults to the unaliased table name,
     *                              matching how `buildFilterGroupQuery`
     *                              references it today.
     */
    /**
     * Live per-customer order count + lifetime spend, as a derived table.
     *
     * WHY THIS EXISTS: `t_crm_prod_customer.total_orders` / `total_spent` are
     * stale. 2,986 customers on the live replica have real delivered orders but
     * a stored counter of 0 — nothing keeps those columns current, and the
     * Customers page quietly recomputes them at render time instead. The
     * campaign spend filter used to read the stored column, so "customers who
     * spent >= X" silently skipped roughly half of everyone who qualified.
     * Every spend / order-count filter now goes through this instead.
     *
     * The definition deliberately mirrors CustomerController::getCombinedCustomerStats()
     * so campaign counts agree with what the owner sees on the Customers page:
     *   - production orders: delivered|completed, excluding external_source='shopify'
     *     (SH-numbered orders are tagged 'webapp' and DO count)
     *   - history orders: delivered
     *
     * Static SQL, no bindings — safe to interpolate into a join.
     */
    public function orderStatsSubquerySql(): string
    {
        $hasHistory = Schema::hasTable('t_crm_history_order');

        $prod = "SELECT customer_id, COUNT(*) AS cnt, COALESCE(SUM(total_price),0) AS spent
                 FROM t_crm_prod_order
                 WHERE customer_id IS NOT NULL
                   AND (external_source <> 'shopify' OR external_source IS NULL)
                   AND order_status IN ('delivered','completed')
                 GROUP BY customer_id";

        if (!$hasHistory) {
            return "SELECT customer_id, SUM(cnt) AS orders_count, SUM(spent) AS spent_total
                    FROM ({$prod}) p GROUP BY customer_id";
        }

        $hist = "SELECT customer_id, COUNT(*) AS cnt, COALESCE(SUM(total_price),0) AS spent
                 FROM t_crm_history_order
                 WHERE customer_id IS NOT NULL
                   AND order_status = 'delivered'
                 GROUP BY customer_id";

        return "SELECT customer_id, SUM(cnt) AS orders_count, SUM(spent) AS spent_total
                FROM ({$prod} UNION ALL {$hist}) x
                GROUP BY customer_id";
    }

    /** Does this filter group need the (relatively costly) live order stats join? */
    public function needsOrderStats(array $group): bool
    {
        foreach (['min_spend', 'max_spend', 'min_orders', 'max_orders'] as $k) {
            if (isset($group[$k]) && $group[$k] !== '' && $group[$k] !== null) return true;
        }
        return false;
    }

    /**
     * LEFT JOIN the live stats so customers with no orders survive as 0 rather
     * than vanishing (important for "spent under X" and churn targeting).
     */
    protected function joinOrderStats($query): void
    {
        $query->leftJoin(
            DB::raw('(' . $this->orderStatsSubquerySql() . ') as ostats'),
            'ostats.customer_id', '=', 't_crm_prod_customer.id'
        );
    }

    public function shopifyExistsExpr(string $customerAlias = 't_crm_prod_customer'): string
    {
        $days = (int) self::SHOPIFY_RECENT_WINDOW_DAYS;
        // NOTE: using a single-quoted SQL literal for the JSON path so MySQL
        // sees the `$` as part of `$.shopify`, and escaping the `$` in the
        // PHP double-quoted string so PHP does not try to interpolate it.
        return '(EXISTS (SELECT 1 FROM t_crm_prod_order o_sh '
             .        'WHERE o_sh.customer_id = ' . $customerAlias . '.id '
             .          "AND o_sh.order_number LIKE 'SH%' "
             .          "AND o_sh.order_date >= DATE_SUB(NOW(), INTERVAL {$days} DAY)) "
             .  'OR JSON_EXTRACT(' . $customerAlias . ".external_customer_ids, '\$.shopify') IS NOT NULL)";
    }

    /**
     * Build the deduplicated customer-id set for a filter payload.
     *
     * Returns:
     *   [
     *     'customer_ids'    => [1,2,3,...],   // deduped, in sort order, post-excludes
     *     'group_counts'    => [5,12,0,...],  // index-aligned match count per input group (pre-exclude)
     *     'excluded_count'  => int,           // how many customers were removed by excludes
     *     'tags_by_id'      => [id => [labels, ...]], // which groups each surviving customer matched
     *   ]
     */
    public function buildCustomerIdSet(array $filters): array
    {
        $groups = $this->extractGroups($filters);
        [$sortBy, $sortDir] = $this->normalizeSort($filters);

        $unionIds = [];
        $groupCounts = [];
        $tagsById = []; // customer_id => [label, label, ...]

        foreach ($groups as $idx => $group) {
            // Qualify the column — a group using spend/order-count filters joins
            // a derived table, and an unqualified `id` would be ambiguous the
            // moment that subquery ever exposes one.
            $ids = $this->buildFilterGroupQuery($group)->pluck('t_crm_prod_customer.id')->toArray();
            $groupCounts[] = count($ids);

            $label = $this->buildGroupLabel($group, $idx);
            foreach ($ids as $id) {
                $unionIds[$id] = true;
                if (!isset($tagsById[$id])) $tagsById[$id] = [];
                if (!in_array($label, $tagsById[$id], true)) {
                    $tagsById[$id][] = $label;
                }
            }
        }

        $excludedCount = 0;
        if (!empty($unionIds)) {
            $excludeIds = $this->collectExclusionIds($filters);
            foreach ($excludeIds as $eid) {
                if (isset($unionIds[$eid])) {
                    unset($unionIds[$eid]);
                    unset($tagsById[$eid]);
                    $excludedCount++;
                }
            }
        }

        if (empty($unionIds)) {
            return [
                'customer_ids' => [],
                'group_counts' => $groupCounts,
                'excluded_count' => $excludedCount,
                'tags_by_id' => [],
            ];
        }

        // Apply the global sort so the campaign_customers rows have a stable
        // ordering that matches the preview — and, crucially, the order the
        // sender will work through them in.
        $sortQuery = CustomerModel::query()->whereIn('t_crm_prod_customer.id', array_keys($unionIds));
        $this->applySort($sortQuery, $sortBy, $sortDir);
        $sortedIds = $sortQuery->pluck('t_crm_prod_customer.id')->toArray();

        return [
            'customer_ids' => array_map('intval', $sortedIds),
            'group_counts' => $groupCounts,
            'excluded_count' => $excludedCount,
            'tags_by_id' => $tagsById,
        ];
    }

    /**
     * Normalise any supported filter payload into an array of filter groups.
     * A legacy single-object payload becomes a 1-element array. An empty
     * payload becomes a single empty group (= all customers).
     */
    public function extractGroups(array $filters): array
    {
        if (isset($filters['groups']) && is_array($filters['groups']) && !empty($filters['groups'])) {
            return array_values(array_filter($filters['groups'], 'is_array'));
        }

        $legacy = $filters;
        unset(
            $legacy['groups'],
            $legacy['sort_by'], $legacy['sort_dir'],
            $legacy['exclude_campaign_ids'], $legacy['exclude_campaign_customer_ids']
        );
        return [$legacy];
    }

    /**
     * Build a short, human-readable label for a single filter group. Used for
     * the per-customer match_tags pills so users can see which slice of the
     * campaign a customer came from.
     */
    public function buildGroupLabel(array $group, int $idx = 0): string
    {
        if (!empty($group['label']) && is_string($group['label'])) {
            return mb_substr(trim($group['label']), 0, 60);
        }

        $parts = [];
        if (!empty($group['qurbani_year'])) $parts[] = 'Qurbani ' . (int) $group['qurbani_year'];
        if (!empty($group['activity'])) {
            $labels = ['30day' => '30d active', '90day' => '90d active', '180day' => '180d active', '365day' => '1y active'];
            if (isset($labels[$group['activity']])) $parts[] = $labels[$group['activity']];
        }
        if (!empty($group['inactive_days'])) {
            $parts[] = 'quiet ' . (int) $group['inactive_days'] . 'd+';
        }
        if (!empty($group['last_order_from']) || !empty($group['last_order_to'])) {
            $from = !empty($group['last_order_from']) ? substr((string) $group['last_order_from'], 0, 10) : '…';
            $to   = !empty($group['last_order_to'])   ? substr((string) $group['last_order_to'], 0, 10)   : '…';
            $parts[] = 'last order ' . $from . '→' . $to;
        }
        if (!empty($group['never_ordered'])) $parts[] = 'never ordered';
        if (!empty($group['first_order_from']) || !empty($group['first_order_to'])) {
            $from = !empty($group['first_order_from']) ? substr((string) $group['first_order_from'], 0, 10) : '…';
            $to   = !empty($group['first_order_to'])   ? substr((string) $group['first_order_to'], 0, 10)   : '…';
            $parts[] = 'joined ' . $from . '→' . $to;
        }
        if (!empty($group['customer_type'])) $parts[] = strtolower($group['customer_type']) === 'shop' ? 'shops' : 'regular';
        if (isset($group['min_orders']) && $group['min_orders'] !== '' && $group['min_orders'] !== null) {
            $parts[] = (int) $group['min_orders'] . '+ orders';
        }
        if (isset($group['max_orders']) && $group['max_orders'] !== '' && $group['max_orders'] !== null) {
            $parts[] = '≤' . (int) $group['max_orders'] . ' orders';
        }
        if (!empty($group['source']) && is_string($group['source'])) {
            $src = strtolower($group['source']);
            if ($src === 'shopify')      $parts[] = 'Shopify only';
            elseif ($src === 'non_shopify') $parts[] = 'Non-Shopify only';
        }
        if (!empty($group['mobile_app']) && is_string($group['mobile_app'])) {
            $ma = strtolower($group['mobile_app']);
            if ($ma === 'on_app')       $parts[] = 'On app';
            elseif ($ma === 'not_on_app') $parts[] = 'Not on app';
        }
        if (!empty($group['city'])) $parts[] = (string) $group['city'];
        if (isset($group['min_spend']) && $group['min_spend'] !== '' && $group['min_spend'] !== null) {
            $parts[] = '≥ ' . number_format((float) $group['min_spend']);
        }
        if (isset($group['max_spend']) && $group['max_spend'] !== '' && $group['max_spend'] !== null) {
            $parts[] = '≤ ' . number_format((float) $group['max_spend']);
        }
        if (!empty($group['search'])) $parts[] = '“' . mb_substr($group['search'], 0, 20) . '”';

        if (empty($parts)) return 'Group ' . ($idx + 1);
        return implode(' · ', $parts);
    }

    /**
     * Build the raw query for a single filter group. Supported keys:
     *   search, city, activity (30day|90day), min_spend, max_spend, qurbani_year (int)
     *
     * Returns a Customer query (unsorted — global sort applied later).
     */
    public function buildFilterGroupQuery(array $group)
    {
        $query = CustomerModel::query()->whereNull('merged_into_customer_id');

        if (!empty($group['search'])) {
            $s = $group['search'];
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'LIKE', "%{$s}%")
                  ->orWhere('last_name', 'LIKE', "%{$s}%")
                  ->orWhere('phone', 'LIKE', "%{$s}%")
                  ->orWhere('phone_normalized', 'LIKE', "%{$s}%")
                  ->orWhere('company', 'LIKE', "%{$s}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$s}%"]);
            });
        }
        // City — accepts a single value (legacy) or an array (multi-select).
        if (!empty($group['city'])) {
            $cities = is_array($group['city'])
                ? array_values(array_filter(array_map('strval', $group['city'])))
                : [(string) $group['city']];
            if (count($cities) === 1) {
                $query->where('city', $cities[0]);
            } elseif (count($cities) > 1) {
                $query->whereIn('city', $cities);
            }
        }

        // ------------------------------------------------------------------
        // WHEN did they last order? This is the heart of churn targeting.
        //
        //   activity        = ordered WITHIN the last N days  (still warm)
        //   inactive_days   = has NOT ordered in the last N days (gone quiet)
        //   last_order_from / last_order_to = an explicit window, which is what
        //                     you want for "customers whose last order was
        //                     between 6 and 12 months ago" — the classic
        //                     win-back slice. Combining the two bounds is how
        //                     you avoid lumping a 2-year-dead customer in with
        //                     someone who lapsed last month.
        //   never_ordered   = on file but never bought
        //
        // All of these read `last_order_date`, which IS maintained live
        // (verified: max value tracks the newest order). Do NOT switch them to
        // last_delivery_date — that column has been frozen since Jan-2026.
        // ------------------------------------------------------------------
        if (!empty($group['activity'])) {
            $map = ['30day' => 30, '90day' => 90, '180day' => 180, '365day' => 365];
            $days = $map[$group['activity']] ?? null;
            if ($days) {
                $query->where('last_order_date', '>=', now()->subDays($days));
            }
        }

        if (isset($group['inactive_days']) && $group['inactive_days'] !== '' && $group['inactive_days'] !== null) {
            $days = max(0, (int) $group['inactive_days']);
            if ($days > 0) {
                // "Quiet for N days" must include people who never ordered at
                // all — otherwise the biggest dormant group is invisible.
                $query->where(function ($q) use ($days) {
                    $q->where('last_order_date', '<', now()->subDays($days))
                      ->orWhereNull('last_order_date');
                });
            }
        }

        if (!empty($group['last_order_from'])) {
            $query->whereDate('last_order_date', '>=', $group['last_order_from']);
        }
        if (!empty($group['last_order_to'])) {
            $query->whereDate('last_order_date', '<=', $group['last_order_to']);
        }

        if (!empty($group['never_ordered'])) {
            $query->whereNull('last_order_date');
        }

        // Acquisition cohort — when did this customer FIRST buy? Lets you talk
        // to "people who joined during last Qurbani" as a group.
        if (!empty($group['first_order_from'])) {
            $query->whereDate('first_order_date', '>=', $group['first_order_from']);
        }
        if (!empty($group['first_order_to'])) {
            $query->whereDate('first_order_date', '<=', $group['first_order_to']);
        }

        // When the customer record itself was created (covers people with no
        // orders, who have no first_order_date to filter on).
        if (!empty($group['created_from'])) {
            $query->whereDate('t_crm_prod_customer.created_at', '>=', $group['created_from']);
        }
        if (!empty($group['created_to'])) {
            $query->whereDate('t_crm_prod_customer.created_at', '<=', $group['created_to']);
        }

        // Shop vs regular customer.
        if (!empty($group['customer_type']) && is_string($group['customer_type'])) {
            $ct = strtolower($group['customer_type']);
            if (in_array($ct, [CustomerModel::TYPE_REGULAR, CustomerModel::TYPE_SHOP], true)) {
                $query->where('customer_type', $ct);
            }
        }

        // A campaign is a WhatsApp send, so a customer with no number can never
        // receive it — they would just pile up as "No phone number" failures.
        // Default-on in the UI; absent here means no filter, so old stored
        // campaigns behave exactly as before.
        if (!empty($group['require_phone'])) {
            $query->where(function ($q) {
                $q->where(function ($w) {
                    $w->whereNotNull('phone_normalized')->where('phone_normalized', '!=', '');
                })->orWhere(function ($w) {
                    $w->whereNotNull('phone')->where('phone', '!=', '');
                });
            });
        }

        // ------------------------------------------------------------------
        // Spend + order count — computed LIVE (see orderStatsSubquerySql).
        // These used to read the stored total_spent/total_orders columns, which
        // are stale for thousands of customers, so the old filter under-targeted
        // badly. COALESCE keeps no-order customers at 0 instead of NULL.
        // ------------------------------------------------------------------
        if ($this->needsOrderStats($group)) {
            $this->joinOrderStats($query);

            if (isset($group['min_spend']) && $group['min_spend'] !== '' && $group['min_spend'] !== null) {
                $query->whereRaw('COALESCE(ostats.spent_total, 0) >= ?', [(float) $group['min_spend']]);
            }
            if (isset($group['max_spend']) && $group['max_spend'] !== '' && $group['max_spend'] !== null) {
                $query->whereRaw('COALESCE(ostats.spent_total, 0) <= ?', [(float) $group['max_spend']]);
            }
            if (isset($group['min_orders']) && $group['min_orders'] !== '' && $group['min_orders'] !== null) {
                $query->whereRaw('COALESCE(ostats.orders_count, 0) >= ?', [(int) $group['min_orders']]);
            }
            if (isset($group['max_orders']) && $group['max_orders'] !== '' && $group['max_orders'] !== null) {
                $query->whereRaw('COALESCE(ostats.orders_count, 0) <= ?', [(int) $group['max_orders']]);
            }
        }

        // Shopify source filter. Three-state: 'shopify' | 'non_shopify' | 'any'
        // (or absent → same as 'any'). Uses the same access pattern as the
        // qurbani_year EXISTS below, so there is no new burden — MySQL hits
        // the `customer_id` index on `t_crm_prod_order` and short-circuits
        // on first match.
        if (!empty($group['source']) && is_string($group['source'])) {
            $src = strtolower($group['source']);
            if ($src === 'shopify') {
                $query->whereRaw($this->shopifyExistsExpr('t_crm_prod_customer'));
            } elseif ($src === 'non_shopify') {
                $query->whereRaw('NOT ' . $this->shopifyExistsExpr('t_crm_prod_customer'));
            }
            // 'any' or anything else → no filter applied (explicit no-op).
        }

        // Mobile-app filter. Three-state: 'on_app' | 'not_on_app' | 'any' (or
        // absent → 'any'). Reads the stored, indexed is_on_mobile_app flag —
        // set when a customer places an app-origin order — so a campaign can
        // exclude people already on the app (no message to existing app users).
        if (!empty($group['mobile_app']) && is_string($group['mobile_app'])) {
            $ma = strtolower($group['mobile_app']);
            if ($ma === 'on_app') {
                $query->where('is_on_mobile_app', 1);
            } elseif ($ma === 'not_on_app') {
                $query->where(function ($q) {
                    $q->where('is_on_mobile_app', 0)->orWhereNull('is_on_mobile_app');
                });
            }
            // 'any' or anything else → no filter applied (explicit no-op).
        }

        // Qurbani-year: customers who have at least one Qurbani order in the
        // given year. Definition mirrors the Qurbani dashboard (getYearOrders)
        // so preview counts line up exactly with what the user sees there.
        if (!empty($group['qurbani_year'])) {
            $year = (int) $group['qurbani_year'];
            $query->where(function ($outer) use ($year) {
                $outer->whereExists(function ($sub) use ($year) {
                    $sub->select(DB::raw(1))
                        ->from('t_crm_prod_order as o')
                        ->whereColumn('o.customer_id', 't_crm_prod_customer.id')
                        ->whereYear('o.order_date', $year)
                        ->where(function ($qq) {
                            $qq->whereNotNull('o.qurbani_day')
                               ->orWhereExists(function ($inner) {
                                   $inner->select(DB::raw(1))
                                       ->from('t_crm_prod_order_line_item as li')
                                       ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                                       ->whereColumn('li.order_id', 'o.id')
                                       ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                               });
                        });
                });

                if (Schema::hasTable('t_crm_history_order') && Schema::hasTable('t_crm_history_order_line_item')) {
                    $outer->orWhereExists(function ($sub) use ($year) {
                        $sub->select(DB::raw(1))
                            ->from('t_crm_history_order as ho')
                            ->whereColumn('ho.customer_id', 't_crm_prod_customer.id')
                            ->whereYear('ho.order_date', $year)
                            ->whereExists(function ($inner) {
                                $inner->select(DB::raw(1))
                                    ->from('t_crm_history_order_line_item as hli')
                                    ->whereColumn('hli.order_id', 'ho.id')
                                    ->where(function ($q) {
                                        $q->whereRaw("LOWER(hli.name) LIKE '%qurbani%'")
                                          ->orWhereRaw("LOWER(hli.name) LIKE '%hissa%'")
                                          ->orWhereRaw("LOWER(COALESCE(hli.sku,'')) LIKE 'qur%'");
                                    });
                            });
                    });
                }
            });
        }

        return $query;
    }

    /**
     * Collect customer_ids to exclude from a filter payload.
     *   exclude_campaign_ids          → customers where status='sent' in any of these
     *   exclude_campaign_customer_ids → customers at ANY status in any of these
     */
    public function collectExclusionIds(array $filters): array
    {
        $ids = [];

        $excludeSent = $filters['exclude_campaign_ids'] ?? [];
        if (is_array($excludeSent) && !empty($excludeSent)) {
            $excludeSent = array_values(array_filter(array_map('intval', $excludeSent)));
            if (!empty($excludeSent)) {
                $rows = DB::table('t_crm_campaign_customers')
                    ->whereIn('campaign_id', $excludeSent)
                    ->where('status', 'sent')
                    ->pluck('customer_id');
                foreach ($rows as $id) $ids[(int)$id] = true;
            }
        }

        $excludeAny = $filters['exclude_campaign_customer_ids'] ?? [];
        if (is_array($excludeAny) && !empty($excludeAny)) {
            $excludeAny = array_values(array_filter(array_map('intval', $excludeAny)));
            if (!empty($excludeAny)) {
                $rows = DB::table('t_crm_campaign_customers')
                    ->whereIn('campaign_id', $excludeAny)
                    ->pluck('customer_id');
                foreach ($rows as $id) $ids[(int)$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Sort keys the campaign UI may ask for. 'spent' and 'orders' are computed
     * live; the legacy 'total_spent' key maps onto 'spent' so campaigns saved
     * before Jul-2026 stop sorting by the stale stored column.
     */
    public const SORT_KEYS = ['last_order_date', 'first_order_date', 'created_at', 'spent', 'orders'];

    /**
     * Return [sortBy, sortDir], defaulting + guarding against unknown values.
     */
    public function normalizeSort(array $filters): array
    {
        $sortBy = $filters['sort_by'] ?? 'last_order_date';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        if ($sortBy === 'total_spent') $sortBy = 'spent';   // legacy payloads
        if ($sortBy === 'total_orders') $sortBy = 'orders';

        if (!in_array($sortBy, self::SORT_KEYS, true)) $sortBy = 'last_order_date';
        if (!in_array($sortDir, ['asc', 'desc'], true)) $sortDir = 'desc';
        return [$sortBy, $sortDir];
    }

    /** Does this sort key need the live order-stats join? */
    public function sortNeedsOrderStats(string $sortBy): bool
    {
        return in_array($sortBy, ['spent', 'orders'], true);
    }

    /**
     * Apply the campaign sort to any customer query, joining live order stats
     * when the chosen key needs them.
     *
     * Every place that orders campaign customers goes through here — the
     * preview/insert order, the detail list, and the SEND order — so "send to
     * the first 100" means the same 100 the operator saw on screen. $alias is
     * whatever the customer table is called in the caller's query.
     */
    public function applySort($query, string $sortBy, string $sortDir, string $alias = 't_crm_prod_customer'): void
    {
        if ($this->sortNeedsOrderStats($sortBy)) {
            $query->leftJoin(
                DB::raw('(' . $this->orderStatsSubquerySql() . ') as sort_stats'),
                'sort_stats.customer_id', '=', $alias . '.id'
            );
            $col = $sortBy === 'spent'
                ? 'COALESCE(sort_stats.spent_total, 0)'
                : 'COALESCE(sort_stats.orders_count, 0)';
            $query->orderByRaw($col . ' ' . ($sortDir === 'asc' ? 'asc' : 'desc'));
            return;
        }

        $query->orderBy($alias . '.' . $sortBy, $sortDir);
    }

    /**
     * Build the match_tags JSON value for a customer at insert time.
     *   $tagsById comes from buildCustomerIdSet()['tags_by_id'].
     * Returns null when no tags exist (keeps the column null-friendly).
     */
    public function matchTagsJson(array $tagsById, int $customerId): ?string
    {
        if (empty($tagsById[$customerId])) return null;
        return json_encode(array_values($tagsById[$customerId]), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Return the subset of $customerIds that already received a WhatsApp
     * message for $templateName in the last $windowDays days. Sources
     * from t_wa_messages (outbound only, not failed) so a customer who
     * was sent the same template via ANY earlier campaign — or a one-off
     * manual send — still counts as "already got it". Null/empty template
     * or non-positive window short-circuits to an empty set so the caller
     * can disable the check by just omitting those inputs.
     *
     * Both campaign entry points (CampaignWebController + RiderController)
     * call this so web + mobile stay in lockstep and can't drift on what
     * "already received" actually means.
     */
    public function customersRecentlySentTemplate(array $customerIds, string $templateName, int $windowDays, bool $marketingOnly = false): array
    {
        $templateName = trim($templateName);
        if ($templateName === '' || $windowDays <= 0 || empty($customerIds)) {
            return [];
        }
        if (!Schema::hasTable('t_wa_messages') || !Schema::hasTable('t_wa_conversations')) {
            return [];
        }

        // marketingOnly short-circuits if the named template isn't actually
        // a marketing-category one — utility/auth templates (invoices,
        // OTPs, transactional confirmations) must NEVER be deduped.
        if ($marketingOnly && !$this->isMarketingTemplate($templateName)) {
            return [];
        }

        $cutoff = \Carbon\Carbon::now()->subDays($windowDays);

        return DB::table('t_wa_messages as m')
            ->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereNotNull('c.customer_id')
            ->whereIn('c.customer_id', $customerIds)
            ->where('m.direction', 'outbound')
            ->where('m.template_name', $templateName)
            ->where('m.created_at', '>=', $cutoff)
            // "failed" here = send API call failed outright. Webhook
            // delivery failures (status=delivered-then-undelivered)
            // still count as "the customer saw it in WhatsApp".
            ->where(function ($q) {
                $q->whereNull('m.status')->orWhere('m.status', '!=', 'failed');
            })
            ->pluck('c.customer_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Lightweight lookup: is the given template name flagged as a
     * marketing-category template in `t_wa_templates`? Cached per request
     * via a static map so repeated checks during a campaign send don't
     * re-hit the DB. Returns false if the templates table is missing or
     * the template isn't found (default to "not marketing" so we never
     * accidentally dedup utility/auth sends).
     */
    public function isMarketingTemplate(string $templateName): bool
    {
        $templateName = trim($templateName);
        if ($templateName === '') return false;

        static $cache = [];
        if (array_key_exists($templateName, $cache)) {
            return $cache[$templateName];
        }
        if (!Schema::hasTable('t_wa_templates') || !Schema::hasColumn('t_wa_templates', 'category')) {
            return $cache[$templateName] = false;
        }
        $row = DB::table('t_wa_templates')
            ->select('category')
            ->where('name', $templateName)
            ->first();
        return $cache[$templateName] = ($row && strtolower((string) $row->category) === 'marketing');
    }

    /**
     * Same as customersRecentlySentTemplate() but operating on a single
     * conversation (id) instead of a customer-id set. Returns the most
     * recent matching outbound row (or null) along with the resolved
     * template display name. Used by the chat-window pre-flight check
     * and the pinned-indicator endpoint.
     *
     * The conversation→customer fallback chain:
     *   1. If the conversation has a customer_id, match other outbound
     *      messages to that same customer (handles the case where the
     *      customer has multiple conversations on different numbers).
     *   2. Otherwise fall back to matching by conversation_id directly,
     *      so the rule still works for unknown/new chats that haven't
     *      been linked to a customer yet.
     */
    public function recentMarketingTemplateSendForConversation(int $conversationId, string $templateName, int $windowDays): ?array
    {
        $templateName = trim($templateName);
        if ($conversationId <= 0 || $templateName === '' || $windowDays <= 0) {
            return null;
        }
        if (!Schema::hasTable('t_wa_messages') || !Schema::hasTable('t_wa_conversations')) {
            return null;
        }
        if (!$this->isMarketingTemplate($templateName)) {
            // The whole feature is marketing-scoped. If this isn't a
            // marketing template, there is nothing to warn about.
            return null;
        }

        $conv = DB::table('t_wa_conversations')
            ->select('id', 'customer_id')
            ->where('id', $conversationId)
            ->first();
        if (!$conv) return null;

        $cutoff = \Carbon\Carbon::now()->subDays($windowDays);

        $q = DB::table('t_wa_messages as m')
            ->where('m.direction', 'outbound')
            ->where('m.template_name', $templateName)
            ->where('m.created_at', '>=', $cutoff)
            ->where(function ($w) {
                $w->whereNull('m.status')->orWhere('m.status', '!=', 'failed');
            });

        if ($conv->customer_id) {
            $q->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
              ->where('c.customer_id', $conv->customer_id);
        } else {
            $q->where('m.conversation_id', $conversationId);
        }

        $row = $q->orderBy('m.created_at', 'desc')
            ->select('m.created_at', 'm.template_name')
            ->first();
        if (!$row) return null;

        $sentAt = \Carbon\Carbon::parse($row->created_at);
        return [
            'template_name'         => $row->template_name,
            'template_display_name' => $this->lookupTemplateDisplayName($row->template_name),
            'sent_at'               => $sentAt->toDateTimeString(),
            'sent_at_human'         => $sentAt->diffForHumans(),
            'window_days'           => $windowDays,
        ];
    }

    /**
     * Return ALL distinct marketing templates sent to this conversation's
     * customer in the configured window, newest-first. Powers the pinned
     * "marketing template sent recently" strip in the chat header (web
     * + mobile). Returns an empty array when the feature is disabled
     * (window=0) or when the conversation hasn't received any marketing
     * templates lately.
     */
    public function recentMarketingTemplatesForConversation(int $conversationId, int $windowDays): array
    {
        if ($conversationId <= 0 || $windowDays <= 0) {
            return [];
        }
        if (!Schema::hasTable('t_wa_messages')
            || !Schema::hasTable('t_wa_conversations')
            || !Schema::hasTable('t_wa_templates')) {
            return [];
        }

        $conv = DB::table('t_wa_conversations')
            ->select('id', 'customer_id')
            ->where('id', $conversationId)
            ->first();
        if (!$conv) return [];

        $cutoff = \Carbon\Carbon::now()->subDays($windowDays);

        $q = DB::table('t_wa_messages as m')
            ->join('t_wa_templates as t', 't.name', '=', 'm.template_name')
            ->where('m.direction', 'outbound')
            ->whereNotNull('m.template_name')
            ->where('m.template_name', '!=', '')
            ->where('m.created_at', '>=', $cutoff)
            ->where(function ($w) {
                $w->whereNull('m.status')->orWhere('m.status', '!=', 'failed');
            })
            ->whereRaw("LOWER(t.category) = 'marketing'");

        if ($conv->customer_id) {
            $q->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
              ->where('c.customer_id', $conv->customer_id);
        } else {
            $q->where('m.conversation_id', $conversationId);
        }

        // We want one row per template_name with its latest send time.
        $rows = $q->select(
                'm.template_name',
                't.display_name',
                DB::raw('MAX(m.created_at) as last_sent_at')
            )
            ->groupBy('m.template_name', 't.display_name')
            ->orderByDesc('last_sent_at')
            ->limit(5) // hard cap so the strip never balloons
            ->get();

        return $rows->map(function ($r) use ($windowDays) {
            $sentAt = \Carbon\Carbon::parse($r->last_sent_at);
            return [
                'template_name'         => $r->template_name,
                'template_display_name' => $r->display_name ?: $r->template_name,
                'sent_at'               => $sentAt->toDateTimeString(),
                'sent_at_human'         => $sentAt->diffForHumans(),
                'window_days'           => $windowDays,
            ];
        })->all();
    }

    /**
     * Look up a template's `display_name` by its `name` (Cloud API key).
     * Returns the template name itself as a sensible fallback so the UI
     * never shows a blank label.
     */
    public function lookupTemplateDisplayName(string $templateName): string
    {
        $templateName = trim($templateName);
        if ($templateName === '') return '';
        if (!Schema::hasTable('t_wa_templates')) return $templateName;

        static $cache = [];
        if (array_key_exists($templateName, $cache)) return $cache[$templateName];

        $row = DB::table('t_wa_templates')
            ->select('display_name')
            ->where('name', $templateName)
            ->first();
        $display = ($row && !empty($row->display_name)) ? (string) $row->display_name : $templateName;
        return $cache[$templateName] = $display;
    }
}
