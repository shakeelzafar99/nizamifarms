<?php

namespace App\Services\WhatsApp\Automation\Handlers;

use App\Models\WhatsApp\AutomationModel;
use App\Services\WhatsApp\Automation\AutomationHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared logic for the two "order received" automations (new-customer and
 * existing-customer lanes). Fires on order.created.
 *
 * The operator-editable part is a SCHEDULE stored in the rule's config_json:
 *   {
 *     "rows": [
 *       {"days":[3,4,5,6,0,1],"start":"10:00","end":"17:00","composition":"any","template":"order_received_workinghours"},
 *       {"days":[1],"start":"20:00","end":"23:59","composition":"chicken_only","template":"order_received_mon_chicken"},
 *       {"days":[1],"start":"20:00","end":"23:59","composition":"mixed","template":"order_received_mon_mixed"}
 *     ],
 *     "default_template": "order_received_offhours_new"
 *   }
 * First matching row wins (day + time window + order composition); otherwise
 * the default_template. Days are 0=Sun..6=Sat; an empty/missing time window =
 * all day; start>end crosses midnight. composition is any|chicken_only|mixed|
 * red_meat_only.
 *
 * Everything else (who to message, the {{1}}=name {{2}}=order-number mapping,
 * the new/existing test, the composition classification) is FIXED in code —
 * exactly the parts the UI does not expose.
 */
abstract class BaseOrderReceivedHandler implements AutomationHandler
{
    /**
     * Only acknowledge orders that ARRIVED within this many hours. A live
     * Shopify webhook fires seconds after the customer places the order, so
     * its order_date is fresh. A bulk back-fill / re-sync import inserts rows
     * NOW but with order_date days old — this guard skips those so an import
     * can't blast welcome messages to old customers (review fix H1). It also
     * fixes the schedule routing for back-fills (review M3) by never reaching
     * pickTemplate for stale orders.
     */
    const MAX_ARRIVAL_AGE_HOURS = 6;

    /** true = only fire for first-ever customers; false = only for returning. */
    abstract protected function isNewLane(): bool;

    /** Stable per-order key so each order is acknowledged at most once. */
    public function dedupKey(array $context): string
    {
        $order = $context['order'] ?? null;
        $ref = $order?->order_number ?: ('id:' . ($context['is_shopify'] ? 'sh' : 'pr') . ':' . ($order?->id ?? '0'));
        return 'order:' . $ref;
    }

    public function eligibility(array $context): ?string
    {
        $order = $context['order'] ?? null;
        if (!$order || empty($order->customer_id)) {
            return 'no customer on order';
        }

        // Back-fill / import guard (H1): skip orders that were PLACED more than
        // MAX_ARRIVAL_AGE_HOURS ago. Uses order_date (the real Shopify placement
        // time); a 30-day import inserts rows now but with old order_dates.
        $placedAt = $order->order_date ?: ($order->created_at ?? null);
        if ($placedAt) {
            try {
                if (\Carbon\Carbon::parse($placedAt)->lt(now()->subHours(self::MAX_ARRIVAL_AGE_HOURS))) {
                    return 'order too old (likely a back-fill / import)';
                }
            } catch (\Throwable $e) {
                // Unparseable date — don't block on it.
            }
        }

        $isNew = !$this->customerHasOtherOrders($order, (bool) ($context['is_shopify'] ?? false));
        if ($this->isNewLane() && !$isNew) {
            return 'not a new customer';
        }
        if (!$this->isNewLane() && $isNew) {
            return 'first-time customer (handled by the new-customer rule)';
        }

        if (!$this->recipientPhone($context)) {
            return 'no phone number';
        }
        return null;
    }

    public function pickTemplate(array $context, AutomationModel $rule): ?string
    {
        $order = $context['order'] ?? null;
        $config = $rule->config();
        $rows = is_array($config['rows'] ?? null) ? $config['rows'] : [];

        $when = $order?->created_at ?: ($order?->order_date ? \Carbon\Carbon::parse($order->order_date) : now());
        $dow = (int) $when->dayOfWeek;            // 0=Sun..6=Sat
        $cur = $when->format('H:i');
        $composition = $this->classifyComposition($order, (bool) ($context['is_shopify'] ?? false));

        foreach ($rows as $row) {
            $days = is_array($row['days'] ?? null) ? array_map('intval', $row['days']) : [];
            if (!empty($days) && !in_array($dow, $days, true)) {
                continue;
            }
            $start = $row['start'] ?? null;
            $end   = $row['end'] ?? null;
            if ($start && $end) {
                // Treat an end of 23:59 as "to midnight" so the final minute
                // (23:59:00-23:59:59) is included rather than falling through to
                // the default (review L1). $cur is "H:i", so "23:59" < "23:59:59"
                // is true and includes the whole 23:59 minute.
                $endCmp = ($end === '23:59' || $end === '23:59:00') ? '23:59:59' : $end;
                $in = ($start <= $endCmp)
                    ? ($cur >= $start && $cur < $endCmp)     // same-day window
                    : ($cur >= $start || $cur < $endCmp);    // crosses midnight
                if (!$in) {
                    continue;
                }
            }
            $comp = $row['composition'] ?? 'any';
            if ($comp !== 'any' && $comp !== $composition) {
                continue;
            }
            if (!empty($row['template'])) {
                return $row['template'];
            }
        }

        // Fall through to the default, then to the rule's own template. Written
        // without `?? ... ?:` on one line: that parses as (a ?? b) ?: null, so a
        // BLANK default_template ("") would wrongly skip $rule->template_name
        // (review fix M1). `?:` treats "" as empty and falls through correctly.
        $default = $config['default_template'] ?? '';
        if (!empty($default)) {
            return $default;
        }
        return $rule->template_name ?: null;
    }

    public function bodyParams(array $context, AutomationModel $rule): array
    {
        $order = $context['order'] ?? null;
        $name = $order?->customer?->first_name
            ?: ($order?->address_first_name ?: 'there');
        $number = $order?->order_number ?: ('#' . ($order?->id ?? ''));
        return [(string) $name, (string) $number];
    }

    public function headerParams(array $context, AutomationModel $rule): array
    {
        return []; // Order-received templates have no media header.
    }

    public function recipientPhone(array $context): ?string
    {
        $order = $context['order'] ?? null;
        if (!$order) {
            return null;
        }
        $phone = $order->customer?->phone_normalized
            ?: ($order->customer?->phone_original ?: $order->address_phone);
        return $phone ? (string) $phone : null;
    }

    // ---------------------------------------------------------------------

    /**
     * "First order ever with us" test: does this customer have ANY order other
     * than the triggering one? Counts live + Shopify-staging + history so a
     * customer's first arrival (incl. a Shopify approval-queue order) reads as
     * new, while a conversion (which does NOT re-fire order.created) cannot
     * flip them. Never throws.
     */
    protected function customerHasOtherOrders($order, bool $isShopify): bool
    {
        $cid = $order->customer_id ?? null;
        if (!$cid) {
            return false;
        }
        try {
            $currentId = (int) ($order->id ?? 0);

            // Include any duplicate customer ids merged INTO this one (review
            // fix H3). The merge routine repoints prod + history orders but NOT
            // t_crm_shopify_order, so a returning customer whose old orders sit
            // under a merged id (in Shopify staging especially) would otherwise
            // be misread as brand-new and get the welcome message.
            $custIds = [(int) $cid];
            $merged = DB::table('t_crm_prod_customer')->where('merged_into_customer_id', $cid)->pluck('id')->all();
            foreach ($merged as $m) { $custIds[] = (int) $m; }
            $custIds = array_values(array_unique($custIds));

            $prodQ = DB::table('t_crm_prod_order')->whereIn('customer_id', $custIds);
            $shopQ = DB::table('t_crm_shopify_order')->whereIn('customer_id', $custIds);
            // Exclude the triggering order from its own table.
            if ($isShopify) {
                $shopQ->where('id', '!=', $currentId);
            } else {
                $prodQ->where('id', '!=', $currentId);
            }

            $count = $prodQ->count() + $shopQ->count();
            if ($count === 0 && Schema::hasTable('t_crm_history_order')) {
                $count += DB::table('t_crm_history_order')->whereIn('customer_id', $custIds)->count();
            }
            return $count > 0;
        } catch (\Throwable $e) {
            // On any error, fail toward "existing" so we never spam a welcome to
            // someone who isn't actually new.
            return true;
        }
    }

    /**
     * Classify the order's meat composition for schedule routing:
     *   chicken_only | mixed | red_meat_only | unknown
     * Resolves each line item to its product via SKU (the reliable key — see
     * the qurbani-rename block in OrderModel), reads product.attribute_1.
     * 'unknown' only matches composition='any' rows.
     */
    protected function classifyComposition($order, bool $isShopify): string
    {
        if (!$order || empty($order->id)) {
            return 'unknown';
        }
        try {
            $liTable = $isShopify ? 't_crm_shopify_order_line_item' : 't_crm_prod_order_line_item';
            $cats = DB::table($liTable . ' as li')
                ->join('t_crm_prod_product_variant as v', 'v.sku', '=', 'li.sku')
                ->join('t_crm_prod_product as p', 'v.product_id', '=', 'p.id')
                ->where('li.order_id', $order->id)
                ->whereNotNull('li.sku')
                ->selectRaw('LOWER(p.attribute_1) as cat')
                ->distinct()
                ->pluck('cat')
                ->filter()
                ->all();

            if (empty($cats)) {
                return 'unknown';
            }
            // Owner's rule (Jun-2026): only 'chicken' is chicken — EVERYTHING
            // else counts as red meat for delivery routing (raheel, aqeeqa,
            // qurbani, table talk, khaas, df, sadqa, mutton, beef, ...), since
            // they all follow the non-chicken / Thursday delivery schedule.
            $hasChicken = in_array('chicken', $cats, true);
            $hasRedMeat = count(array_diff($cats, ['chicken'])) > 0; // any non-chicken category

            if ($hasChicken && $hasRedMeat) return 'mixed';
            if ($hasChicken)                return 'chicken_only';
            if ($hasRedMeat)                return 'red_meat_only';
            return 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
