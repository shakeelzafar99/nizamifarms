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
            $ids = $this->buildFilterGroupQuery($group)->pluck('id')->toArray();
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
        // ordering that matches the preview.
        $sortedIds = CustomerModel::query()
            ->whereIn('id', array_keys($unionIds))
            ->orderBy($sortBy, $sortDir)
            ->pluck('id')
            ->toArray();

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
            if ($group['activity'] === '30day') $parts[] = '30d active';
            elseif ($group['activity'] === '90day') $parts[] = '90d active';
        }
        if (!empty($group['source']) && is_string($group['source'])) {
            $src = strtolower($group['source']);
            if ($src === 'shopify')      $parts[] = 'Shopify only';
            elseif ($src === 'non_shopify') $parts[] = 'Non-Shopify only';
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
        if (!empty($group['city'])) {
            $query->where('city', $group['city']);
        }
        if (!empty($group['activity'])) {
            if ($group['activity'] === '30day') $query->where('last_order_date', '>=', now()->subDays(30));
            elseif ($group['activity'] === '90day') $query->where('last_order_date', '>=', now()->subDays(90));
        }
        if (isset($group['min_spend']) && $group['min_spend'] !== '' && $group['min_spend'] !== null) {
            $query->where('total_spent', '>=', (float) $group['min_spend']);
        }
        if (isset($group['max_spend']) && $group['max_spend'] !== '' && $group['max_spend'] !== null) {
            $query->where('total_spent', '<=', (float) $group['max_spend']);
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
     * Return [sortBy, sortDir], defaulting + guarding against unknown values.
     */
    public function normalizeSort(array $filters): array
    {
        $sortBy = $filters['sort_by'] ?? 'last_order_date';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        if (!in_array($sortBy, ['last_order_date', 'total_spent', 'created_at'])) $sortBy = 'last_order_date';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';
        return [$sortBy, $sortDir];
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
    public function customersRecentlySentTemplate(array $customerIds, string $templateName, int $windowDays): array
    {
        $templateName = trim($templateName);
        if ($templateName === '' || $windowDays <= 0 || empty($customerIds)) {
            return [];
        }
        if (!Schema::hasTable('t_wa_messages') || !Schema::hasTable('t_wa_conversations')) {
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
}
