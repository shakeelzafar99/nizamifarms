<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every campaign number the web page and the mobile app display comes from
 * here. One implementation, so the two surfaces cannot drift (they did before:
 * mobile honoured product tracking, web silently ignored it).
 *
 * Everything is SET-BASED. The previous implementation ran two queries per sent
 * customer, so a 5,700-recipient campaign meant ~11,000 queries and a dead
 * modal. These are a fixed handful of queries regardless of campaign size.
 *
 * The funnel:
 *
 *   Sent      — Meta accepted the message
 *   Delivered — it reached the handset (Meta webhook)
 *   Read      — the customer opened it (Meta webhook). A FLOOR, not a truth:
 *               customers who disable read receipts never report this, so read
 *               is always <= reality. Never present it as "N people ignored us".
 *   Replied   — they sent us a WhatsApp message back inside the window
 *   Ordered   — they placed a qualifying order inside the window
 *
 * Delivery data only exists for sends made after the Jul-2026 revamp (older
 * rows have no wa_message_id), so every funnel reports `receipts_tracked` and
 * the UI says so rather than showing a misleading zero.
 */
class CampaignStatsService
{
    /** Order statuses that count as a real, money-in-the-door order. */
    private const CONVERTING_STATUSES = ['delivered', 'completed'];

    /** Shopify source_name values that mean "placed in the customer app". */
    private const APP_CHANNELS = ['ios_app', 'android_app'];

    /**
     * An active campaign with people still waiting and no send for this long is
     * treated as forgotten and surfaced on the landing view. Long enough that a
     * deliberately paced campaign (a batch a week) is never flagged.
     */
    public const STALE_DAYS = 60;

    // =====================================================================
    // Single campaign
    // =====================================================================

    public function forCampaign(int $campaignId): ?array
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return null;

        $window = (int) ($campaign->tracking_window_days ?: 30);

        $delivery   = $this->deliveryFunnel([$campaignId]);
        $conversion = $this->conversions([$campaignId], $window, $campaign);

        return [
            'campaign_id'          => $campaignId,
            'campaign_name'        => $campaign->name,
            'wa_template_name'     => $campaign->wa_template_name,
            'tracking_type'        => $campaign->tracking_type ?: 'general',
            'tracking_window_days' => $window,
            'funnel'               => $this->assembleFunnel($delivery, $conversion),
            'source_split'         => $conversion['source_split'],
            'product_breakdown'    => $this->productBreakdown([$campaignId], $window, $campaign),
            'tracking_note'        => $this->trackingNote($campaign),
        ];
    }

    /**
     * Delivery/reply funnel for one or more campaigns.
     *
     * $unique=true collapses to one row per customer (MAX over the flags =
     * "ever delivered / ever read / ever replied"), which is what the combined
     * per-template view needs so somebody messaged by three campaigns counts
     * once.
     */
    public function deliveryFunnel(array $campaignIds, bool $unique = false): array
    {
        if (empty($campaignIds)) {
            return ['sends' => 0, 'customers' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'undelivered' => 0, 'receipts_tracked' => 0];
        }

        $ph = implode(',', array_fill(0, count($campaignIds), '?'));

        // delivered counts read too: a read receipt proves delivery even when
        // the delivered webhook was missed or arrived out of order.
        $flags = "
            MAX(CASE WHEN cc.delivered_at IS NOT NULL OR cc.read_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered,
            MAX(CASE WHEN cc.read_at        IS NOT NULL THEN 1 ELSE 0 END) AS rd,
            MAX(CASE WHEN cc.replied_at     IS NOT NULL THEN 1 ELSE 0 END) AS replied,
            MAX(CASE WHEN cc.undelivered_at IS NOT NULL THEN 1 ELSE 0 END) AS undelivered,
            MAX(CASE WHEN cc.wa_message_id  IS NOT NULL THEN 1 ELSE 0 END) AS tracked";

        $rows = DB::select("
            SELECT
                COUNT(*)              AS customers,
                SUM(u.sends)          AS sends,
                SUM(u.delivered)      AS delivered,
                SUM(u.rd)             AS rd,
                SUM(u.replied)        AS replied,
                SUM(u.undelivered)    AS undelivered,
                SUM(u.tracked)        AS receipts_tracked
            FROM (
                SELECT cc.customer_id, COUNT(*) AS sends, {$flags}
                FROM t_crm_campaign_customers cc
                WHERE cc.campaign_id IN ({$ph})
                  AND cc.status = 'sent'
                GROUP BY cc.customer_id
            ) u
        ", $campaignIds);

        $r = $rows[0] ?? null;

        return [
            'sends'            => (int) ($r->sends ?? 0),
            'customers'        => (int) ($r->customers ?? 0),
            'delivered'        => (int) ($r->delivered ?? 0),
            'read'             => (int) ($r->rd ?? 0),
            'replied'          => (int) ($r->replied ?? 0),
            'undelivered'      => (int) ($r->undelivered ?? 0),
            'receipts_tracked' => (int) ($r->receipts_tracked ?? 0),
        ];
    }

    /**
     * Orders attributed to a set of campaigns.
     *
     * Attribution rule (owner ruling, Jul-2026): a customer is credited to
     * their MOST RECENT send. That is what the MAX(sent_at) derived table does,
     * and it is why the same order can never be claimed by two campaigns in a
     * combined view.
     *
     * $campaignForRules supplies tracking_type / tracked_product_ids. When
     * several campaigns are combined we pass the newest one — they share a
     * template, so in practice they share intent too.
     */
    public function conversions(array $campaignIds, int $windowDays, $campaignForRules = null): array
    {
        $empty = [
            'converters' => 0, 'orders' => 0, 'revenue' => 0.0,
            'source_split' => ['app' => 0, 'web' => 0, 'manual' => 0],
        ];
        if (empty($campaignIds)) return $empty;

        $ph = implode(',', array_fill(0, count($campaignIds), '?'));
        $trackingType = $campaignForRules->tracking_type ?? 'general';

        // Extra conditions that decide what counts as a conversion.
        $extra = '';
        if ($trackingType === 'app_orders') {
            $appList = "'" . implode("','", self::APP_CHANNELS) . "'";
            $extra = " AND o.order_source_channel IN ({$appList}) ";
        } elseif ($trackingType === 'products') {
            $productIds = $this->trackedProductIds($campaignForRules);
            if (!empty($productIds)) {
                $pph = implode(',', array_fill(0, count($productIds), '?'));
                $extra = " AND EXISTS (
                    SELECT 1 FROM t_crm_prod_order_line_item li
                    WHERE li.order_id = o.id AND li.product_id IN ({$pph})
                ) ";
            }
        }

        $statusList = "'" . implode("','", self::CONVERTING_STATUSES) . "'";
        $appList    = "'" . implode("','", self::APP_CHANNELS) . "'";

        // Bindings order: window (derived table has none), campaigns, then
        // product ids if used, then window for DATE_ADD.
        $sql = "
            SELECT
                COUNT(DISTINCT l.customer_id) AS converters,
                COUNT(DISTINCT o.id)          AS orders,
                COALESCE(SUM(o.total_price), 0) AS revenue,
                COUNT(DISTINCT CASE WHEN o.order_source_channel IN ({$appList}) THEN o.id END) AS app_orders,
                COUNT(DISTINCT CASE WHEN (o.order_source_channel IS NULL OR o.order_source_channel NOT IN ({$appList}))
                                     AND (o.order_number LIKE 'SH-%' OR o.order_source_channel = 'web') THEN o.id END) AS web_orders
            FROM (
                SELECT cc.customer_id, MAX(cc.sent_at) AS sent_at
                FROM t_crm_campaign_customers cc
                WHERE cc.campaign_id IN ({$ph})
                  AND cc.status = 'sent'
                  AND cc.sent_at IS NOT NULL
                GROUP BY cc.customer_id
            ) l
            JOIN t_crm_prod_order o
              ON o.customer_id = l.customer_id
             AND o.order_date  > l.sent_at
             AND o.order_date <= DATE_ADD(l.sent_at, INTERVAL ? DAY)
             AND o.order_status IN ({$statusList})
             {$extra}
        ";

        // Binding order must follow the order the placeholders appear in the
        // SQL above: campaign ids (derived table), then the window in the
        // JOIN's DATE_ADD, then any tracked product ids inside {$extra}.
        $bindings = $campaignIds;
        $bindings[] = $windowDays;
        if ($trackingType === 'products') {
            foreach ($this->trackedProductIds($campaignForRules) as $pid) {
                $bindings[] = $pid;
            }
        }

        $rows = DB::select($sql, $bindings);
        $r = $rows[0] ?? null;
        if (!$r) return $empty;

        $orders = (int) $r->orders;
        $app    = (int) $r->app_orders;
        $web    = (int) $r->web_orders;

        return [
            'converters'   => (int) $r->converters,
            'orders'       => $orders,
            'revenue'      => round((float) $r->revenue, 2),
            'source_split' => [
                'app'    => $app,
                'web'    => $web,
                'manual' => max(0, $orders - $app - $web),
            ],
        ];
    }

    /** Per-product totals for a 'products' campaign. Empty for other types. */
    public function productBreakdown(array $campaignIds, int $windowDays, $campaign): array
    {
        if (($campaign->tracking_type ?? 'general') !== 'products') return [];
        $productIds = $this->trackedProductIds($campaign);
        if (empty($productIds) || empty($campaignIds)) return [];

        $ph  = implode(',', array_fill(0, count($campaignIds), '?'));
        $pph = implode(',', array_fill(0, count($productIds), '?'));
        $statusList = "'" . implode("','", self::CONVERTING_STATUSES) . "'";

        $bindings = array_merge($campaignIds, [$windowDays], $productIds);

        $rows = DB::select("
            SELECT li.product_id,
                   p.title AS product_name,
                   SUM(li.quantity) AS total_qty,
                   SUM(li.price * li.quantity) AS total_value
            FROM (
                SELECT cc.customer_id, MAX(cc.sent_at) AS sent_at
                FROM t_crm_campaign_customers cc
                WHERE cc.campaign_id IN ({$ph}) AND cc.status = 'sent' AND cc.sent_at IS NOT NULL
                GROUP BY cc.customer_id
            ) l
            JOIN t_crm_prod_order o
              ON o.customer_id = l.customer_id
             AND o.order_date  > l.sent_at
             AND o.order_date <= DATE_ADD(l.sent_at, INTERVAL ? DAY)
             AND o.order_status IN ({$statusList})
            JOIN t_crm_prod_order_line_item li ON li.order_id = o.id
            JOIN t_crm_prod_product p ON p.id = li.product_id
            WHERE li.product_id IN ({$pph})
            GROUP BY li.product_id, p.title
            ORDER BY total_value DESC
        ", $bindings);

        return array_map(fn($r) => [
            'product_id'   => (int) $r->product_id,
            'product_name' => $r->product_name,
            'total_qty'    => (float) $r->total_qty,
            'total_value'  => round((float) $r->total_value, 2),
        ], $rows);
    }

    /**
     * Per-recipient rows for the campaign detail list — paginated, and with the
     * conversion + delivery facts resolved in TWO queries for the whole page
     * rather than two per person.
     */
    public function customerRows(int $campaignId, string $statusFilter = 'pending', int $page = 1, int $perPage = 100): array
    {
        $campaign = DB::table('t_crm_campaigns')->where('id', $campaignId)->first();
        if (!$campaign) return ['customers' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0];

        $filterSvc = app(\App\Services\CampaignFilterService::class);

        $q = DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
            ->where('cc.campaign_id', $campaignId);

        $this->applyStatusFilter($q, $statusFilter);

        $total = (clone $q)->count();

        $q->select(
            'cc.id as campaign_customer_id', 'cc.customer_id',
            'cc.status as campaign_status', 'cc.sent_at', 'cc.sent_by', 'cc.error_message',
            'cc.replied_at', 'cc.delivered_at', 'cc.read_at', 'cc.undelivered_at', 'cc.wa_message_id',
            'cc.match_tags',
            'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized',
            'c.city', 'c.last_order_date', 'c.is_on_mobile_app'
        );
        $q->selectRaw('(' . $filterSvc->shopifyExistsExpr('c') . ') as is_shopify');

        // MUST go through applySort(): the sort key may be 'spent' / 'orders',
        // which are computed live and need a join — `ORDER BY c.spent` would be
        // an unknown column. (This threw a 500 on any campaign saved with the
        // legacy sort_by='total_spent', which normalizeSort maps to 'spent'.)
        $filters = json_decode($campaign->filters_json ?? '{}', true) ?: [];
        [$sortBy, $sortDir] = $filterSvc->normalizeSort($filters);
        $q->orderByRaw("FIELD(cc.status, 'pending', 'sending', 'failed', 'sent', 'skipped')");
        $filterSvc->applySort($q, $sortBy, $sortDir, 'c');

        $page = max(1, $page);
        $rows = $q->forPage($page, $perPage)->get();

        // Resolve "did this person order after we messaged them" for the whole
        // page in one query.
        $sentRows = $rows->filter(fn($r) => $r->campaign_status === 'sent' && $r->sent_at);
        if ($sentRows->isNotEmpty()) {
            $orderMap = $this->ordersForCustomers(
                $sentRows->pluck('customer_id')->map(fn($v) => (int) $v)->all(),
                $campaignId,
                (int) ($campaign->tracking_window_days ?: 30),
                $campaign
            );
            foreach ($rows as $r) {
                $m = $orderMap[(int) $r->customer_id] ?? null;
                $r->order_count = $m ? (int) $m['orders'] : 0;
                $r->order_revenue = $m ? (float) $m['revenue'] : 0.0;
            }
        } else {
            foreach ($rows as $r) { $r->order_count = 0; $r->order_revenue = 0.0; }
        }

        return [
            'customers' => $rows->values(),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'pages'     => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /** Per-customer attributed order counts for one campaign (page-scoped). */
    protected function ordersForCustomers(array $customerIds, int $campaignId, int $windowDays, $campaign): array
    {
        if (empty($customerIds)) return [];

        $cph = implode(',', array_fill(0, count($customerIds), '?'));
        $statusList = "'" . implode("','", self::CONVERTING_STATUSES) . "'";

        $trackingType = $campaign->tracking_type ?? 'general';
        $extra = '';
        $productIds = [];
        if ($trackingType === 'app_orders') {
            $appList = "'" . implode("','", self::APP_CHANNELS) . "'";
            $extra = " AND o.order_source_channel IN ({$appList}) ";
        } elseif ($trackingType === 'products') {
            $productIds = $this->trackedProductIds($campaign);
            if (!empty($productIds)) {
                $pph = implode(',', array_fill(0, count($productIds), '?'));
                $extra = " AND EXISTS (SELECT 1 FROM t_crm_prod_order_line_item li WHERE li.order_id = o.id AND li.product_id IN ({$pph})) ";
            }
        }

        $rows = DB::select("
            SELECT cc.customer_id,
                   COUNT(DISTINCT o.id) AS orders,
                   COALESCE(SUM(o.total_price), 0) AS revenue
            FROM t_crm_campaign_customers cc
            JOIN t_crm_prod_order o
              ON o.customer_id = cc.customer_id
             AND o.order_date  > cc.sent_at
             AND o.order_date <= DATE_ADD(cc.sent_at, INTERVAL ? DAY)
             AND o.order_status IN ({$statusList})
             {$extra}
            WHERE cc.campaign_id = ?
              AND cc.status = 'sent'
              AND cc.customer_id IN ({$cph})
            GROUP BY cc.customer_id
        ", array_merge([$windowDays], $productIds, [$campaignId], $customerIds));

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->customer_id] = ['orders' => (int) $r->orders, 'revenue' => (float) $r->revenue];
        }
        return $out;
    }

    // =====================================================================
    // Per-template combined results
    // =====================================================================

    /**
     * Every campaign that used a template, each with its own funnel, PLUS one
     * combined funnel where each customer counts exactly once.
     *
     * This answers the two different questions the owner asked:
     *   - "how did each campaign fare?"   -> campaigns[]
     *   - "how did the TEMPLATE perform?" -> combined (deduplicated)
     *
     * The gap between combined.sends and combined.customers is the number of
     * repeat sends, surfaced as `duplicate_sends` so message fatigue is visible
     * instead of inflating the numbers.
     */
    public function forTemplate(string $templateName): array
    {
        $templateName = trim($templateName);
        if ($templateName === '') {
            return ['template_name' => '', 'campaigns' => [], 'combined' => null];
        }

        $campaigns = DB::table('t_crm_campaigns')
            ->where('wa_template_name', $templateName)
            ->orderByDesc('created_at')
            ->get();

        if ($campaigns->isEmpty()) {
            return ['template_name' => $templateName, 'campaigns' => [], 'combined' => null];
        }

        $perCampaign = [];
        foreach ($campaigns as $c) {
            $w = (int) ($c->tracking_window_days ?: 30);
            $d = $this->deliveryFunnel([(int) $c->id]);
            $v = $this->conversions([(int) $c->id], $w, $c);
            $perCampaign[] = [
                'campaign_id'   => (int) $c->id,
                'campaign_name' => $c->name,
                'status'        => $c->status,
                'created_at'    => $c->created_at,
                'ended_at'      => $c->ended_at,
                'first_sent_at' => DB::table('t_crm_campaign_customers')->where('campaign_id', $c->id)->where('status', 'sent')->min('sent_at'),
                'last_sent_at'  => DB::table('t_crm_campaign_customers')->where('campaign_id', $c->id)->where('status', 'sent')->max('sent_at'),
                'tracking_window_days' => $w,
                'tracking_type' => $c->tracking_type ?: 'general',
                'funnel'        => $this->assembleFunnel($d, $v),
                'source_split'  => $v['source_split'],
            ];
        }

        $c = $this->combinedForCampaigns($campaigns);

        return [
            'template_name'          => $templateName,
            'template_display_name'  => app(\App\Services\CampaignFilterService::class)->lookupTemplateDisplayName($templateName),
            'campaign_count'         => count($perCampaign),
            'campaigns'              => $perCampaign,
            'combined'               => $c['combined'],
            'combined_source_split'  => $c['source_split'],
            'tracking_window_days'   => $c['window'],
            'window_mixed'           => $c['window_mixed'],
        ];
    }

    /**
     * The combined (deduplicated) block for a set of campaigns.
     *
     * Extracted so the drill-down and the overview list compute it through the
     * SAME code path — otherwise the headline number on the overview row could
     * silently disagree with the number shown after clicking it, which is the
     * kind of inconsistency that destroys trust in a reporting screen.
     *
     * Rules baked in here:
     *  - Each customer counts once (deliveryFunnel with $unique = true).
     *  - Orders are credited to the customer's MOST RECENT send (owner ruling).
     *  - Where campaigns disagree on tracking window, the widest is used and
     *    `window_mixed` says so rather than hiding it.
     *  - Conversion rules (products / app_orders) come from the NEWEST campaign
     *    using the template — campaigns sharing a template share intent in
     *    practice, and the newest reflects current intent.
     */
    protected function combinedForCampaigns($campaigns): array
    {
        $ids     = $campaigns->pluck('id')->map(fn($v) => (int) $v)->all();
        $windows = $campaigns->pluck('tracking_window_days')->map(fn($v) => (int) ($v ?: 30))->unique()->values()->all();
        $window  = max($windows);
        $newest  = $campaigns->first();

        $cd = $this->deliveryFunnel($ids, true);
        $cv = $this->conversions($ids, $window, $newest);

        $combined = $this->assembleFunnel($cd, $cv);
        $combined['duplicate_sends'] = max(0, $cd['sends'] - $cd['customers']);

        return [
            'combined'     => $combined,
            'source_split' => $cv['source_split'],
            'window'       => $window,
            'window_mixed' => count($windows) > 1,
            'campaign_ids' => $ids,
        ];
    }

    /**
     * One combined summary row per template, for the campaigns landing view.
     *
     * Deliberately does NOT build the per-campaign breakdown — that is what the
     * drill-down is for. Cost is two queries per template (a handful in
     * practice), and it reuses combinedForCampaigns() so the row and the
     * drill-down headline are guaranteed identical.
     */
    public function templateSummaries(): array
    {
        $all = DB::table('t_crm_campaigns')
            ->whereNotNull('wa_template_name')
            ->where('wa_template_name', '!=', '')
            ->orderByDesc('created_at')
            ->get();

        if ($all->isEmpty()) return [];

        $filterSvc = app(\App\Services\CampaignFilterService::class);
        $out = [];

        foreach ($all->groupBy('wa_template_name') as $templateName => $campaigns) {
            $c = $this->combinedForCampaigns($campaigns);

            $sentAt = DB::table('t_crm_campaign_customers')
                ->whereIn('campaign_id', $c['campaign_ids'])
                ->where('status', 'sent')
                ->selectRaw('MIN(sent_at) as first_sent_at, MAX(sent_at) as last_sent_at')
                ->first();

            $out[] = [
                'wa_template_name'     => $templateName,
                'display_name'         => $filterSvc->lookupTemplateDisplayName($templateName),
                'campaign_count'       => $campaigns->count(),
                'active_count'         => $campaigns->where('status', 'active')->count(),
                'first_sent_at'        => $sentAt->first_sent_at ?? null,
                'last_sent_at'         => $sentAt->last_sent_at ?? null,
                'tracking_window_days' => $c['window'],
                'window_mixed'         => $c['window_mixed'],
                'tracking_type'        => $campaigns->first()->tracking_type ?: 'general',
                'funnel'               => $c['combined'],
                'source_split'         => $c['source_split'],
            ];
        }

        // Most recently used template first — that's what the operator is
        // working on today.
        usort($out, fn($a, $b) => strcmp((string) ($b['last_sent_at'] ?? ''), (string) ($a['last_sent_at'] ?? '')));

        return $out;
    }

    /**
     * The campaigns landing view: what's happening right now, how each template
     * is performing, and what needs a human.
     *
     * Answers the question the page is actually opened with ("how is my
     * messaging doing, and does anything need me?") instead of just listing
     * campaigns. Quota is added by the controller, which already owns that
     * payload shape.
     */
    public function overview(): array
    {
        $campaigns = DB::table('t_crm_campaigns')->orderByDesc('created_at')->get();

        // One aggregate for every campaign, rather than per-campaign subqueries.
        $agg = DB::table('t_crm_campaign_customers')
            ->selectRaw("
                campaign_id,
                MAX(sent_at) as last_sent_at,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status IN ('pending','sending') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='sent' AND undelivered_at IS NOT NULL THEN 1 ELSE 0 END) as undelivered
            ")
            ->groupBy('campaign_id')
            ->get()
            ->keyBy('campaign_id');

        $running   = [];
        $attention = [];
        $staleCutoff = now()->subDays(self::STALE_DAYS);

        foreach ($campaigns as $c) {
            $a = $agg->get($c->id);
            $pending     = (int) ($a->pending ?? 0);
            $failed      = (int) ($a->failed ?? 0);
            $undelivered = (int) ($a->undelivered ?? 0);
            $lastSentAt  = $a->last_sent_at ?? null;

            // --- sending right now -------------------------------------
            if (($c->send_state ?? 'idle') === 'running') {
                $run = $c->active_run_id
                    ? DB::table('t_crm_campaign_send_runs')->where('id', $c->active_run_id)->first()
                    : null;
                $running[] = [
                    'campaign_id'   => (int) $c->id,
                    'campaign_name' => $c->name,
                    'template'      => $c->wa_template_name,
                    'paused_reason' => $c->send_paused_reason,
                    'target'        => (int) ($run->target_count ?? 0),
                    'attempted'     => (int) ($run->attempted ?? 0),
                    'sent'          => (int) ($run->sent_count ?? 0),
                    'failed'        => (int) ($run->failed_count ?? 0),
                    'pending'       => $pending,
                ];
                continue; // a running campaign is not "needs attention"
            }

            if ($c->status !== 'active') continue;

            // --- needs a human -----------------------------------------
            if (($c->send_state ?? 'idle') === 'paused') {
                $attention[] = [
                    'type' => 'paused', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $c->send_paused_reason ?: 'Sending is paused.',
                    'action' => 'Open and press Resume',
                ];
            }
            if ($failed > 0) {
                $attention[] = [
                    'type' => 'failed', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $failed . ' message' . ($failed === 1 ? '' : 's') . ' failed to send',
                    'action' => 'Review and retry',
                    'count' => $failed,
                ];
            }
            // Without a template nothing can ever be sent, so this campaign is
            // stuck permanently rather than merely idle. Flag it as its own
            // problem instead of letting it read as "just needs a send".
            if ($pending > 0 && empty($c->wa_template_name)) {
                $attention[] = [
                    'type' => 'no_template', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => 'No WhatsApp template set, so its ' . $pending . ' waiting customers can never be messaged',
                    'action' => 'Re-create it with a template, or end it',
                    'count' => $pending,
                ];
            }
            if ($undelivered > 0) {
                $attention[] = [
                    'type' => 'undelivered', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $undelivered . ' could not be delivered (likely dead numbers)',
                    'action' => 'Review to clean up numbers',
                    'count' => $undelivered,
                ];
            }
            // A campaign left half-sent for months is almost always forgotten
            // rather than deliberate — surface it instead of letting it sit.
            // Skipped when there's no template: that's the 'no_template' case
            // above, and listing both would double-report one problem.
            if ($pending > 0 && !empty($c->wa_template_name) && (!$lastSentAt || $lastSentAt < $staleCutoff)) {
                $attention[] = [
                    'type' => 'stale', 'campaign_id' => (int) $c->id, 'campaign_name' => $c->name,
                    'detail' => $lastSentAt
                        ? $pending . ' still waiting, nothing sent since ' . substr((string) $lastSentAt, 0, 10)
                        : $pending . ' waiting and nothing has ever been sent',
                    'action' => 'Send the next batch, or end the campaign',
                    'count' => $pending,
                ];
            }
        }

        // Blocked first, then failures, then dead numbers, then forgotten.
        $order = ['paused' => 0, 'no_template' => 1, 'failed' => 2, 'undelivered' => 3, 'stale' => 4];
        usort($attention, fn($a, $b) => ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9));

        return [
            'running'   => $running,
            'attention' => $attention,
            'templates' => $this->templateSummaries(),
            'totals'    => [
                'active_campaigns' => $campaigns->where('status', 'active')->count(),
                'total_campaigns'  => $campaigns->count(),
                'pending_total'    => (int) $agg->sum('pending'),
                'sent_total'       => (int) $agg->sum('sent'),
            ],
        ];
    }

    /** Templates that have at least one campaign — for the template switcher. */
    public function templatesWithCampaigns(): array
    {
        $rows = DB::table('t_crm_campaigns as c')
            ->leftJoin('t_wa_templates as t', 't.name', '=', 'c.wa_template_name')
            ->whereNotNull('c.wa_template_name')
            ->where('c.wa_template_name', '!=', '')
            ->select(
                'c.wa_template_name',
                DB::raw('MAX(t.display_name) as display_name'),
                DB::raw('COUNT(*) as campaign_count'),
                DB::raw('MAX(c.created_at) as latest_campaign_at')
            )
            ->groupBy('c.wa_template_name')
            ->orderByDesc('latest_campaign_at')
            ->get();

        return $rows->map(fn($r) => [
            'wa_template_name' => $r->wa_template_name,
            'display_name'     => $r->display_name ?: $r->wa_template_name,
            'campaign_count'   => (int) $r->campaign_count,
            'latest_campaign_at' => $r->latest_campaign_at,
        ])->all();
    }

    // =====================================================================
    // Send-run history
    // =====================================================================

    public function runHistory(int $campaignId, int $limit = 20): array
    {
        if (!Schema::hasTable('t_crm_campaign_send_runs')) return [];

        return DB::table('t_crm_campaign_send_runs as r')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'r.started_by')
            ->where('r.campaign_id', $campaignId)
            ->orderByDesc('r.started_at')
            ->limit($limit)
            ->select('r.*', DB::raw("COALESCE(u.fullname, '') as started_by_name"))
            ->get()
            ->all();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    protected function assembleFunnel(array $delivery, array $conversion): array
    {
        $sent = $delivery['customers'];
        $pct = fn($n) => $sent > 0 ? round($n / $sent * 100, 1) : 0.0;

        return [
            'sends'            => $delivery['sends'],
            'sent'             => $sent,
            'delivered'        => $delivery['delivered'],
            'read'             => $delivery['read'],
            'replied'          => $delivery['replied'],
            'undelivered'      => $delivery['undelivered'],
            'ordered'          => $conversion['converters'],
            'orders'           => $conversion['orders'],
            'revenue'          => $conversion['revenue'],
            'receipts_tracked' => $delivery['receipts_tracked'],
            'rates' => [
                'delivered'  => $pct($delivery['delivered']),
                'read'       => $pct($delivery['read']),
                'replied'    => $pct($delivery['replied']),
                'ordered'    => $pct($conversion['converters']),
                'undelivered'=> $pct($delivery['undelivered']),
            ],
        ];
    }

    /**
     * A warning when a campaign's tracking setup can't do what its type claims.
     *
     * The real case: campaigns saved as tracking_type='products' before the
     * product picker existed have no tracked_product_ids, so the conversion
     * query adds no product condition and quietly counts EVERY order. The
     * number isn't wrong, but the label is — so say so instead of letting it
     * read as a product-level result.
     */
    protected function trackingNote($campaign): ?string
    {
        $type = $campaign->tracking_type ?? 'general';

        if ($type === 'products' && empty($this->trackedProductIds($campaign))) {
            return 'This campaign is set to track specific products, but no products were saved against it — so any order within the window is being counted. Re-create the campaign with products selected if you need a product-level result.';
        }

        if ($type === 'app_orders') {
            return 'Only orders placed through the customer app count here. App orders are recognised from the order source captured at checkout, so orders placed before that tracking went live will not appear.';
        }

        return null;
    }

    protected function trackedProductIds($campaign): array
    {
        $raw = $campaign->tracked_product_ids ?? null;
        if (!$raw) return [];
        $ids = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($ids)) return [];
        return array_values(array_filter(array_map('intval', $ids)));
    }

    /**
     * The Excluded/Skipped split lives in the error_message prefix, not the
     * status column — see CampaignWebController::create(). Kept here so every
     * caller applies it identically.
     */
    public function applyStatusFilter($query, string $statusFilter): void
    {
        if ($statusFilter === 'excluded') {
            $query->where('cc.status', 'skipped')->where('cc.error_message', 'LIKE', 'Excluded:%');
        } elseif ($statusFilter === 'skipped') {
            $query->where('cc.status', 'skipped')->where(function ($q) {
                $q->whereNull('cc.error_message')->orWhere('cc.error_message', 'NOT LIKE', 'Excluded:%');
            });
        } elseif ($statusFilter === 'undelivered') {
            $query->where('cc.status', 'sent')->whereNotNull('cc.undelivered_at');
        } elseif ($statusFilter === 'pending') {
            // 'sending' rows are mid-flight; showing them under Pending keeps
            // the operator's mental model simple (they are not done yet).
            $query->whereIn('cc.status', ['pending', 'sending']);
        } elseif ($statusFilter !== 'all') {
            $query->where('cc.status', $statusFilter);
        }
    }

    /** The count block every campaign endpoint returns. */
    public function counts(int $campaignId): ?object
    {
        return DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $campaignId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status IN ('pending','sending') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='sending' THEN 1 ELSE 0 END) as sending,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded,
                SUM(CASE WHEN status='sent' AND (delivered_at IS NOT NULL OR read_at IS NOT NULL) THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status='sent' AND read_at IS NOT NULL THEN 1 ELSE 0 END) as `read`,
                SUM(CASE WHEN status='sent' AND replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied,
                SUM(CASE WHEN status='sent' AND undelivered_at IS NOT NULL THEN 1 ELSE 0 END) as undelivered
            ")
            ->first();
    }
}
