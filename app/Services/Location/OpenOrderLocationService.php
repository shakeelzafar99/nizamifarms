<?php

namespace App\Services\Location;

use App\Models\FIN\ConfigModel;
use App\Models\WhatsApp\ConversationModel;
use App\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Open Orders → "Get Customer Locations".
 *
 * Deliberately TABLE-FREE: there is no tracking table. Every status is derived
 * live so the manager never looks at stale customers.
 *
 *   needs / eligible  →  open orders whose customer has no verified pin (live query)
 *   sent / awaiting    →  most recent outbound location-request template in t_wa_messages
 *   delivery failed    →  that message's status = 'failed'
 *   resolved (saved)   →  the customer now has a verified pin on t_crm_prod_customer
 *
 * Pressing the button re-runs everything against the current open list.
 */
class OpenOrderLocationService
{
    /** Shared global drain lock — the cron command and the request-time
     *  terminating hook both acquire this so they can't double-fire. */
    public const LOCK_KEY = 'location:auto-process:lock';

    public function __construct(protected WhatsAppService $wa, protected LocationUrlResolver $resolver)
    {
    }

    protected function cfg(string $key, $default = null)
    {
        return config("open_order_location.$key", $default);
    }

    /** Template names that count as the open-orders location request. */
    protected function requestTemplates(): array
    {
        $t = $this->cfg('request_templates', ['location', 'location_request']);
        return is_array($t) ? array_values(array_filter($t)) : [];
    }

    // ------------------------------------------------------------------
    // ELIGIBLE / RESULTS  (one live snapshot)
    // ------------------------------------------------------------------

    /**
     * Build the live snapshot for the modal:
     *   - needs: customers on the open list without a verified pin (+ last-send status)
     *   - resolved_recent: customers who shared a pin recently (the wins)
     *   - counts
     */
    public function snapshot(): array
    {
        $orders = $this->openUnverifiedOrders();

        // Collapse to one row per customer (a customer may have several open orders).
        $byCustomer = [];
        foreach ($orders as $o) {
            $cid = (int) $o->customer_id;
            if (!isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id'   => $cid,
                    'customer_name' => trim((string) ($o->cust_name ?? '')) ?: 'Customer',
                    'phone'         => $o->phone ?: $o->phone_normalized,
                    'region_name'   => $o->region_name,
                    'order_ids'     => [],
                    'order_numbers' => [],
                    'order_count'   => 0,
                ];
            }
            $byCustomer[$cid]['order_ids'][] = (int) $o->order_id;
            if ($o->order_number) {
                $byCustomer[$cid]['order_numbers'][] = $o->order_number;
            }
            $byCustomer[$cid]['order_count']++;
        }

        $customerIds = array_keys($byCustomer);
        $sendMap = $this->lastLocationSendMap($customerIds);

        $recentCutoff = now()->subHours((int) $this->cfg('recently_messaged_hours', 24));

        $needs = [];
        $counts = ['never' => 0, 'awaiting' => 0, 'failed' => 0];
        foreach ($byCustomer as $cid => $row) {
            $send = $sendMap[$cid] ?? null;
            if (!$send) {
                $status = 'never';
            } elseif (($send->status ?? null) === 'failed') {
                $status = 'failed';
            } else {
                $status = 'awaiting';
            }
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            $sentAt = $send && $send->created_at ? Carbon::parse($send->created_at) : null;
            $row['status'] = $status;
            $row['last_sent_at'] = $sentAt ? $sentAt->toIso8601String() : null;
            $row['last_sent_human'] = $sentAt ? $sentAt->diffForHumans() : null;
            $row['last_error'] = $send->error_message ?? null;
            // Pre-select everyone except those messaged within the recent window.
            $row['preselect'] = !($sentAt && $sentAt->greaterThan($recentCutoff));
            $row['phone'] = $row['phone'];
            $needs[] = $row;
        }

        // Sort: never-sent first, then failed, then awaiting; name as tiebreak.
        $order = ['never' => 0, 'failed' => 1, 'awaiting' => 2];
        usort($needs, function ($a, $b) use ($order) {
            $d = ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
            return $d !== 0 ? $d : strcasecmp($a['customer_name'], $b['customer_name']);
        });

        return [
            'needs'           => $needs,
            'resolved_recent' => $this->recentlyResolved(),
            'counts'          => [
                'needs'    => count($needs),
                'never'    => $counts['never'] ?? 0,
                'awaiting' => $counts['awaiting'] ?? 0,
                'failed'   => $counts['failed'] ?? 0,
            ],
        ];
    }

    /** Statuses that are NOT eligible for a location request. */
    protected function excludedStatuses(): array
    {
        // 'pending' is excluded (Jun-2026): we don't ask for a location until the
        // order is actually confirmed/accepted. Newly accepted Shopify + new NF
        // orders land as 'new', so they remain eligible.
        return ['pending', 'delivered', 'completed', 'cancelled', 'refunded'];
    }

    /**
     * Base query for open, non-shopify, non-qurbani orders whose customer has no
     * verified pin. Shared by the snapshot list and the per-customer eligibility
     * check (so the manual button and the automation use the exact same rule).
     */
    protected function baseEligibleQuery()
    {
        return DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('t_ops_delivery_region as r', 'r.id', '=', 'c.delivery_region_id')
            ->whereNotIn('o.order_status', $this->excludedStatuses())
            ->where(function ($w) {
                $w->whereNull('o.external_source')->orWhere('o.external_source', '!=', 'shopify');
            })
            // Exclude qurbani orders (order-level flag or qurbani line items).
            ->whereNull('o.qurbani_day')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('t_crm_prod_order_line_item as li')
                    ->join('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                    ->whereColumn('li.order_id', 'o.id')
                    ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
            })
            // Unverified location: no URL and not a complete lat/lng pin.
            ->whereNull('c.verified_location_url')
            ->where(function ($w) {
                $w->whereNull('c.latitude')
                  ->orWhereNull('c.longitude')
                  ->orWhere('c.latitude', 0)
                  ->orWhere('c.longitude', 0);
            })
            ->where(function ($w) {
                $w->whereNotNull('c.phone')->orWhereNotNull('c.phone_normalized');
            });
    }

    /** Open, non-shopify, non-qurbani orders whose customer has no verified pin. */
    protected function openUnverifiedOrders()
    {
        return $this->baseEligibleQuery()
            ->orderByDesc('o.id')
            ->get([
                'o.id as order_id',
                'o.order_number',
                'o.customer_id',
                'c.first_name',
                'c.last_name',
                DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) as cust_name"),
                'c.phone',
                'c.phone_normalized',
                'c.delivery_region_id',
                'r.name as region_name',
            ]);
    }

    /**
     * Does this customer currently qualify for a location request? (Same rule as
     * the manual button.) Used by the automation to gate enqueue + send.
     */
    public function customerIsEligible(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        return $this->baseEligibleQuery()
            ->where('o.customer_id', $customerId)
            ->exists();
    }

    /**
     * For each customer id, the most recent OUTBOUND location-request template
     * message (status + timestamp), derived purely from the WhatsApp log.
     *
     * @param  array<int>  $customerIds
     * @return array<int,object>  keyed by customer_id
     */
    public function lastLocationSendMap(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_filter($customerIds)));
        if (empty($customerIds) || empty($this->requestTemplates())) {
            return [];
        }

        $rows = DB::table('t_wa_messages as m')
            ->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('c.customer_id', $customerIds)
            ->where('m.direction', 'outbound')
            ->where('m.type', 'template')
            ->whereIn('m.template_name', $this->requestTemplates())
            ->orderByDesc('m.id')
            ->get(['c.customer_id', 'm.status', 'm.error_code', 'm.error_message', 'm.created_at']);

        $map = [];
        foreach ($rows as $r) {
            $cid = (int) $r->customer_id;
            if (!isset($map[$cid])) {
                $map[$cid] = $r; // first seen = most recent (ordered by id desc)
            }
        }
        return $map;
    }

    /**
     * Customers who recently gained a verified pin AND were sent a location
     * request — i.e. the "auto-saved" wins to show the manager.
     */
    public function recentlyResolved(): array
    {
        if (empty($this->requestTemplates())) {
            return [];
        }
        $days = (int) $this->cfg('resolved_recent_days', 7);
        $cutoff = now()->subDays($days);

        // Customers we sent a location request within the broader window.
        $sentCutoff = now()->subDays((int) $this->cfg('autosave_window_days', 14));
        $requestedIds = DB::table('t_wa_messages as m')
            ->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.direction', 'outbound')
            ->where('m.type', 'template')
            ->whereIn('m.template_name', $this->requestTemplates())
            ->where('m.created_at', '>=', $sentCutoff)
            ->whereNotNull('c.customer_id')
            ->distinct()
            ->pluck('c.customer_id')
            ->all();

        if (empty($requestedIds)) {
            return [];
        }

        $rows = DB::table('t_crm_prod_customer as c')
            ->leftJoin('t_ops_delivery_region as r', 'r.id', '=', 'c.delivery_region_id')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'c.verified_location_saved_by')
            ->whereIn('c.id', $requestedIds)
            ->whereNotNull('c.latitude')
            ->whereNotNull('c.longitude')
            ->where('c.latitude', '!=', 0)
            ->where('c.longitude', '!=', 0)
            ->whereNotNull('c.verified_location_saved_at')
            ->where('c.verified_location_saved_at', '>=', $cutoff)
            ->orderByDesc('c.verified_location_saved_at')
            ->limit(100)
            ->get([
                'c.id as customer_id',
                DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) as customer_name"),
                'c.latitude',
                'c.longitude',
                'c.verified_location_saved_at',
                'c.verified_location_saved_by',
                'r.name as region_name',
                DB::raw("COALESCE(u.fullname, '') as saved_by_name"),
            ]);

        return $rows->map(function ($r) {
            $savedAt = $r->verified_location_saved_at ? Carbon::parse($r->verified_location_saved_at) : null;
            return [
                'customer_id'   => (int) $r->customer_id,
                'customer_name' => trim((string) $r->customer_name) ?: 'Customer',
                'latitude'      => (float) $r->latitude,
                'longitude'     => (float) $r->longitude,
                'region_name'   => $r->region_name,
                // No saver = saved automatically from the WhatsApp reply.
                'auto'          => empty($r->verified_location_saved_by),
                'saved_by_name' => ((int) $r->verified_location_saved_by === \App\Models\CRM\CustomerModel::VERIFIED_PIN_CUSTOMER_ID)
                    ? 'Customer'
                    : ($r->saved_by_name ?: null),
                'saved_at'      => $savedAt ? $savedAt->toIso8601String() : null,
                'saved_human'   => $savedAt ? $savedAt->diffForHumans() : null,
                'maps_url'      => 'https://www.google.com/maps/search/?api=1&query=' . $r->latitude . ',' . $r->longitude,
            ];
        })->all();
    }

    // ------------------------------------------------------------------
    // BULK SEND  (one chunk of selected customers)
    // ------------------------------------------------------------------

    /**
     * Send the location-request template to a set of customers (one chunk).
     * Synchronous with pacing; returns a per-customer result so the UI can
     * show live progress and a final breakdown.
     *
     * @param  array<int>  $customerIds
     * @return array{results: array<int,array>, sent:int, failed:int, skipped:int}
     */
    public function sendBulk(array $customerIds, string $templateName, ?string $language, ?int $userId): array
    {
        $customerIds = array_values(array_unique(array_filter($customerIds)));
        $language = $language ?: $this->cfg('default_language', 'en');
        $pacing = (int) $this->cfg('send_pacing_us', 200000);

        $varCount = (int) (DB::table('t_wa_templates')
            ->where('name', $templateName)
            ->value('variable_count') ?? 0);

        $customers = DB::table('t_crm_prod_customer')
            ->whereIn('id', $customerIds)
            ->get([
                'id', 'first_name', 'last_name', 'phone', 'phone_normalized',
                'latitude', 'longitude', 'verified_location_url',
            ])
            ->keyBy('id');

        $results = [];
        $sent = $failed = $skipped = 0;
        $first = true;

        foreach ($customerIds as $cid) {
            $cust = $customers[$cid] ?? null;
            if (!$cust) {
                $results[] = ['customer_id' => $cid, 'ok' => false, 'skipped' => true, 'reason' => 'Customer not found'];
                $skipped++;
                continue;
            }

            // Skip if the customer already got a verified pin in the meantime.
            $hasPin = $cust->latitude && $cust->longitude
                && (float) $cust->latitude !== 0.0 && (float) $cust->longitude !== 0.0;
            if ($hasPin || !empty($cust->verified_location_url)) {
                $results[] = ['customer_id' => $cid, 'ok' => false, 'skipped' => true, 'reason' => 'Already has a location'];
                $skipped++;
                continue;
            }

            $rawPhone = $cust->phone ?: $cust->phone_normalized;
            if (empty($rawPhone)) {
                $results[] = ['customer_id' => $cid, 'ok' => false, 'skipped' => true, 'reason' => 'No phone number'];
                $skipped++;
                continue;
            }
            $phone = $this->wa->formatPhone($rawPhone);
            $name = trim(((string) $cust->first_name) . ' ' . ((string) $cust->last_name));
            $name = $name !== '' ? $name : 'Customer';

            if (!$first && $pacing > 0) {
                usleep($pacing);
            }
            $first = false;

            try {
                $bodyParams = $varCount >= 1 ? [$name] : [];
                $resp = $this->wa->sendTemplateMessage($phone, $templateName, $language, $bodyParams);

                if (!empty($resp['success'])) {
                    // Mirror Qurbani: persist the outbound to the conversation timeline
                    // so the messages inbox + our status query both see it.
                    try {
                        $conv = $this->wa->findOrCreateConversation($phone);
                        $this->wa->saveOutboundMessage(
                            $conv->id,
                            $resp,
                            'template',
                            $templateName,
                            $userId,
                            $templateName,
                            $varCount >= 1 ? ['1' => $name] : null
                        );
                    } catch (\Throwable $e) {
                        Log::debug('OpenOrderLocation: saveOutboundMessage failed (non-fatal)', [
                            'customer_id' => $cid, 'error' => $e->getMessage(),
                        ]);
                    }
                    $results[] = ['customer_id' => $cid, 'ok' => true];
                    $sent++;
                } else {
                    $results[] = [
                        'customer_id' => $cid,
                        'ok' => false,
                        'reason' => $resp['error'] ?? 'Send failed',
                    ];
                    $failed++;
                }
            } catch (\Throwable $e) {
                $results[] = ['customer_id' => $cid, 'ok' => false, 'reason' => $e->getMessage()];
                $failed++;
            }
        }

        return ['results' => $results, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------
    // DEFAULT TEMPLATE  (DB-backed, with config-file fallback)
    // ------------------------------------------------------------------

    /** The global default template, used by both the manual button and the automation. */
    public function defaultTemplate(): string
    {
        $db = trim((string) ConfigModel::get('open_order_location_default_template', ''));
        if ($db !== '') {
            return $db;
        }
        return (string) $this->cfg('default_template', 'location');
    }

    /** The global default language for the location-request template. */
    public function defaultLanguage(): string
    {
        $db = trim((string) ConfigModel::get('open_order_location_default_lang', ''));
        if ($db !== '') {
            return $db;
        }
        return (string) $this->cfg('default_language', 'en');
    }

    /** True only when an admin/auto-adopt has explicitly set a default (not the file fallback). */
    public function defaultTemplateIsSet(): bool
    {
        return trim((string) ConfigModel::get('open_order_location_default_template', '')) !== '';
    }

    /**
     * Persist the global default template if one has not been set yet. Called after
     * the first manual send so a rider's choice becomes the default without an admin
     * having to configure it ("marked as default if not set explicitly").
     */
    public function adoptDefaultTemplateIfUnset(string $templateName, ?string $language = null): void
    {
        if ($templateName === '' || $this->defaultTemplateIsSet()) {
            return;
        }
        ConfigModel::set(
            'open_order_location_default_template',
            $templateName,
            'Global default WhatsApp template for Open Orders location requests (auto-adopted from first send).'
        );
        if (!empty($language)) {
            ConfigModel::set('open_order_location_default_lang', $language);
        }
    }

    // ------------------------------------------------------------------
    // AUTOMATION  (enqueue on order accept/create + scheduled drain)
    // ------------------------------------------------------------------

    /** Master switch for the auto-send automation (OFF until the owner turns it on). */
    public function isAutoEnabled(): bool
    {
        return (string) ConfigModel::get('location_auto_send_enabled', '0') === '1';
    }

    /** Timezone the daily send window is evaluated in. */
    protected function windowTimezone(): string
    {
        return 'Asia/Karachi';
    }

    /** Is "now" inside the configured daily send window? */
    public function withinSendWindow(?Carbon $now = null): bool
    {
        $tz = $this->windowTimezone();
        $now = $now ? $now->copy()->setTimezone($tz) : Carbon::now($tz);

        [$sh, $sm] = $this->parseHm((string) ConfigModel::get('location_auto_window_start', '09:00'), 9, 0);
        [$eh, $em] = $this->parseHm((string) ConfigModel::get('location_auto_window_end', '18:00'), 18, 0);

        $start = $now->copy()->setTime($sh, $sm, 0);
        $end   = $now->copy()->setTime($eh, $em, 0);

        if ($start->lessThanOrEqualTo($end)) {
            return $now->betweenIncluded($start, $end);
        }
        // Overnight window (e.g. 20:00–06:00): inside if after start OR before end.
        return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
    }

    /** Parse an "HH:MM" string into [hour, minute] with safe fallbacks. */
    protected function parseHm(string $hm, int $defH, int $defM): array
    {
        $parts = explode(':', trim($hm));
        $h = isset($parts[0]) && is_numeric($parts[0]) ? max(0, min(23, (int) $parts[0])) : $defH;
        $m = isset($parts[1]) && is_numeric($parts[1]) ? max(0, min(59, (int) $parts[1])) : $defM;
        return [$h, $m];
    }

    /** Was this customer sent a location request within the recent-messaged window? */
    public function recentlyMessaged(int $customerId): bool
    {
        $hours = (int) $this->cfg('recently_messaged_hours', 24);
        if ($hours <= 0) {
            return false;
        }
        $send = $this->lastLocationSendMap([$customerId])[$customerId] ?? null;
        if (!$send || empty($send->created_at)) {
            return false;
        }
        return Carbon::parse($send->created_at)->greaterThan(now()->subHours($hours));
    }

    /**
     * Queue a customer for an automatic location request (called from the order
     * accept / create hooks). Only queues when the master switch is ON, the
     * customer currently qualifies (same rule as the manual button), they were
     * not messaged recently, and they are not already queued.
     *
     * @return int|null  queue row id (existing or new), or null when not queued
     */
    public function enqueue(int $customerId, ?int $orderId, string $source): ?int
    {
        try {
            if (!$this->isAutoEnabled() || $customerId <= 0) {
                return null;
            }
            if (!$this->customerIsEligible($customerId) || $this->recentlyMessaged($customerId)) {
                return null;
            }

            // One pending row per customer.
            $existing = DB::table('t_crm_location_auto_queue')
                ->where('customer_id', $customerId)
                ->where('status', 'queued')
                ->value('id');
            if ($existing) {
                return (int) $existing;
            }

            $now = now();
            return (int) DB::table('t_crm_location_auto_queue')->insertGetId([
                'customer_id'    => $customerId,
                'order_id'       => $orderId,
                'trigger_source' => $source,
                'status'         => 'queued',
                'attempts'       => 0,
                'enqueued_at'    => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } catch (\Throwable $e) {
            // Never let the automation break order creation/conversion.
            Log::warning('OpenOrderLocation: enqueue failed (non-fatal)', [
                'customer_id' => $customerId, 'order_id' => $orderId, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Drain queued auto-send rows. No-op when the switch is off or we're outside
     * the daily window (rows simply wait for the window to open). Each row is
     * re-validated live before sending so nothing stale goes out.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function processQueue(int $limit = 25): array
    {
        $counters = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if (!$this->isAutoEnabled() || !$this->withinSendWindow()) {
            return $counters;
        }

        $rows = DB::table('t_crm_location_auto_queue')
            ->where(function ($q) {
                $q->where('status', 'queued')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'failed')->where('attempts', '<', 3);
                  });
            })
            ->orderBy('enqueued_at', 'asc')
            ->limit(max(1, $limit))
            ->get();

        if ($rows->isEmpty()) {
            return $counters;
        }

        $template = $this->defaultTemplate();
        $language = $this->defaultLanguage();

        foreach ($rows as $row) {
            $cid = (int) $row->customer_id;

            if (!$this->customerIsEligible($cid) || $this->recentlyMessaged($cid)) {
                DB::table('t_crm_location_auto_queue')->where('id', $row->id)->update([
                    'status'     => 'skipped',
                    'last_error' => 'No longer eligible / recently messaged',
                    'attempts'   => (int) $row->attempts + 1,
                    'updated_at' => now(),
                ]);
                $counters['skipped']++;
                continue;
            }

            try {
                $out = $this->sendBulk([$cid], $template, $language, null);
                $reason = $out['results'][0]['reason'] ?? null;

                if (!empty($out['sent'])) {
                    DB::table('t_crm_location_auto_queue')->where('id', $row->id)->update([
                        'status'        => 'sent',
                        'template_name' => $template,
                        'sent_at'       => now(),
                        'attempts'      => (int) $row->attempts + 1,
                        'updated_at'    => now(),
                    ]);
                    $counters['sent']++;
                } elseif (!empty($out['skipped'])) {
                    DB::table('t_crm_location_auto_queue')->where('id', $row->id)->update([
                        'status'     => 'skipped',
                        'last_error' => $reason ?: 'Skipped at send',
                        'attempts'   => (int) $row->attempts + 1,
                        'updated_at' => now(),
                    ]);
                    $counters['skipped']++;
                } else {
                    DB::table('t_crm_location_auto_queue')->where('id', $row->id)->update([
                        'status'     => 'failed',
                        'last_error' => $reason ?: 'Send failed',
                        'attempts'   => (int) $row->attempts + 1,
                        'updated_at' => now(),
                    ]);
                    $counters['failed']++;
                }
            } catch (\Throwable $e) {
                DB::table('t_crm_location_auto_queue')->where('id', $row->id)->update([
                    'status'     => 'failed',
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'attempts'   => (int) $row->attempts + 1,
                    'updated_at' => now(),
                ]);
                $counters['failed']++;
            }
        }

        return $counters;
    }

    /**
     * Drain the queue under a short global lock so the request-time terminating
     * hook (mobile/web open-orders poll) and the every-minute cron can't
     * double-fire on the same rows. Shares ONE lock key with the cron command,
     * so whichever fires first wins the ~55s window. Cheap no-op when the
     * automation is off.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function drainWithLock(int $limit = 25): array
    {
        $noop = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if (!$this->isAutoEnabled()) {
            return $noop;
        }

        try {
            $lock = Cache::lock(self::LOCK_KEY, 55);
            if (!$lock->get()) {
                return $noop; // another drain is already running this window
            }
        } catch (\Throwable $e) {
            // Cache lock store unavailable — fall back to draining without the
            // lock. processQueue() re-validates + flips each row to 'sent'
            // immediately, and recentlyMessaged() guards repeats, so the worst
            // case is a rare benign retry, never a silent crash.
            Log::warning('OpenOrderLocation: lock unavailable, draining unlocked', ['error' => $e->getMessage()]);
            return $this->processQueue($limit);
        }

        try {
            return $this->processQueue($limit);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Register an app()->terminating() callback that drains the auto
     * location-request queue AFTER the response is flushed — so a manager poll
     * (mobile store open-orders, web "Get Locations") flushes queued requests
     * without depending on the cron. This is what makes an order queued at 11pm
     * go out the next morning the moment the store app starts polling inside the
     * send window. Fire-and-forget; never throws; registers nothing when the
     * automation is off so it costs one config read on a normal poll.
     */
    public function fireFromRequest(): void
    {
        try {
            if (!$this->isAutoEnabled()) {
                return;
            }
            app()->terminating(function () {
                try {
                    app(self::class)->drainWithLock();
                } catch (\Throwable $e) {
                    Log::warning('OpenOrderLocation: terminating drain failed', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
            // Some environments (e.g. console/testing) can't register terminating
            // callbacks — the every-minute cron is the fallback there.
            Log::debug('OpenOrderLocation: fireFromRequest skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Daily stats for the auto-send automation, for the Get Locations modal:
     *   - sent      : customers auto-messaged today
     *   - responded : of those, how many now have a verified pin (saved)
     *   - pending   : of those, how many still have no pin (didn't respond)
     *   - pending_list : the non-responders, so the manager knows who to chase
     *
     * "Today" is the app's operational day (matches how sent_at is stored).
     */
    public function autoSendStats(): array
    {
        $startOfDay = now()->startOfDay();

        $rows = DB::table('t_crm_location_auto_queue as q')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'q.customer_id')
            ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'q.order_id')
            ->where('q.status', 'sent')
            ->whereNotNull('q.sent_at')
            ->where('q.sent_at', '>=', $startOfDay)
            ->orderByDesc('q.sent_at')
            ->get([
                'q.customer_id',
                'q.sent_at',
                'o.order_number',
                DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) as customer_name"),
                'c.phone',
                'c.phone_normalized',
                'c.latitude',
                'c.longitude',
                'c.verified_location_url',
            ]);

        $seen = [];
        $sent = 0;
        $responded = 0;
        $pendingList = [];

        foreach ($rows as $r) {
            $cid = (int) $r->customer_id;
            if (isset($seen[$cid])) {
                continue; // one row per customer per day
            }
            $seen[$cid] = true;
            $sent++;

            $hasPin = $r->latitude && $r->longitude
                && (float) $r->latitude !== 0.0 && (float) $r->longitude !== 0.0;
            $verified = $hasPin || !empty($r->verified_location_url);

            if ($verified) {
                $responded++;
            } else {
                $sentAt = $r->sent_at ? Carbon::parse($r->sent_at) : null;
                $pendingList[] = [
                    'customer_id'   => $cid,
                    'customer_name' => trim((string) $r->customer_name) ?: 'Customer',
                    'phone'         => $r->phone ?: $r->phone_normalized,
                    'order_number'  => $r->order_number,
                    'sent_at'       => $sentAt ? $sentAt->toIso8601String() : null,
                    'sent_human'    => $sentAt ? $sentAt->diffForHumans() : null,
                ];
            }
        }

        return [
            'enabled'      => $this->isAutoEnabled(),
            'sent'         => $sent,
            'responded'    => $responded,
            'pending'      => count($pendingList),
            'pending_list' => $pendingList,
        ];
    }

    // ------------------------------------------------------------------
    // INBOUND AUTO-SAVE  (called from the WhatsApp webhook)
    // ------------------------------------------------------------------

    /**
     * Auto-save a location reply onto the customer record, but only when:
     *   - the feature + auto-save are on,
     *   - the conversation maps to a customer,
     *   - the customer currently has NO verified pin (never overwrite), and
     *   - the customer's most recent location-request send used an open-orders
     *     template (NOT 'qurbani_location') within the auto-save window.
     *
     * @return bool  true if a pin was saved
     */
    public function autoSaveInbound(ConversationModel $conversation, ?float $lat, ?float $lng, ?string $sourceUrl = null): bool
    {
        if (!$this->cfg('enabled', true) || !$this->cfg('auto_save_on_reply', true)) {
            return false;
        }
        $customerId = $conversation->customer_id ? (int) $conversation->customer_id : null;
        if (!$customerId) {
            return false;
        }

        $valid = $this->resolver->validateCoords($lat, $lng);
        if (!$valid) {
            return false;
        }

        $cust = DB::table('t_crm_prod_customer')
            ->where('id', $customerId)
            ->first(['id', 'latitude', 'longitude', 'verified_location_url']);
        if (!$cust) {
            return false;
        }
        $hasPin = $cust->latitude && $cust->longitude
            && (float) $cust->latitude !== 0.0 && (float) $cust->longitude !== 0.0;
        if ($hasPin || !empty($cust->verified_location_url)) {
            return false; // already verified — leave it
        }

        if (!$this->lastRequestIsOpenOrder($customerId)) {
            return false; // not our flow (no request, or the qurbani flow owns it)
        }

        $update = [
            'latitude'                   => $valid['latitude'],
            'longitude'                  => $valid['longitude'],
            'verified_location_saved_at' => now(),
            'verified_location_saved_by' => null, // null = auto-saved from WhatsApp reply
            'updated_at'                 => now(),
        ];
        if (!empty($sourceUrl)) {
            $update['verified_location_url'] = mb_substr($sourceUrl, 0, 500);
        }

        DB::table('t_crm_prod_customer')->where('id', $customerId)->update($update);

        Log::info('OpenOrderLocation: auto-saved customer location from WhatsApp reply', [
            'customer_id' => $customerId,
            'lat' => $valid['latitude'],
            'lng' => $valid['longitude'],
            'via_url' => $sourceUrl,
        ]);

        return true;
    }

    /**
     * Resolve a free-text reply then auto-save it (used for Maps URLs / plain
     * coordinates sent as text rather than a native pin).
     */
    public function autoSaveInboundText(ConversationModel $conversation, ?string $text): bool
    {
        if (!$this->cfg('enabled', true) || !$this->cfg('auto_save_on_reply', true)) {
            return false;
        }
        if (!$conversation->customer_id) {
            return false;
        }
        $resolved = $this->resolver->resolveFromText($text);
        if (!$resolved || empty($resolved['latitude']) || empty($resolved['longitude'])) {
            return false; // nothing usable (or a place-name URL needing human review)
        }
        return $this->autoSaveInbound(
            $conversation,
            (float) $resolved['latitude'],
            (float) $resolved['longitude'],
            $resolved['source_url'] ?? null
        );
    }

    /**
     * True when the customer's MOST RECENT location-family request was an
     * open-orders template (so the qurbani flow doesn't get hijacked).
     */
    protected function lastRequestIsOpenOrder(int $customerId): bool
    {
        $ours = $this->requestTemplates();
        if (empty($ours)) {
            return false;
        }
        $family = array_values(array_unique(array_merge($ours, ['qurbani_location'])));
        $windowDays = (int) $this->cfg('autosave_window_days', 14);

        $last = DB::table('t_wa_messages as m')
            ->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.customer_id', $customerId)
            ->where('m.direction', 'outbound')
            ->where('m.type', 'template')
            ->whereIn('m.template_name', $family)
            ->where('m.created_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('m.id')
            ->value('m.template_name');

        return $last !== null && in_array($last, $ours, true);
    }
}
