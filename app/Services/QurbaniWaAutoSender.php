<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\FIN\ConfigModel;
use App\Services\WhatsAppService;

/**
 * Qurbani Auto WhatsApp Sender (Phase 3, May-2026)
 * ==================================================
 *
 * Goal
 * ----
 * Send a small set of *operational* WhatsApp template messages to
 * Qurbani customers as their item moves through the day:
 *
 *   1. Slaughter   — N minutes after qurbani_slaughtered_at.
 *   2. OFD/Collect — based on a timing rule (after status / after
 *      dispatch / "X min before delivery time"). Different template
 *      for delivery vs self-collection.
 *
 * Design choices
 * --------------
 * • Stateless — every run pulls candidate line items from the live
 *   tables. No queues, no pending tables.
 * • Idempotent — every successful send writes a row in
 *   t_ops_qurbani_wa_log (status='sent'). The "is this candidate
 *   eligible?" check looks for a prior 'sent' row with matching
 *   (line_item_id, trigger_event) and skips if found.
 * • Rate-limited — Cache::add('qurbani_wa_lock', ...) holds a 55s
 *   lock so two parallel callers (terminating + scheduler) can't
 *   double-fire. A per-run send cap (qurbani_wa_send_max_per_minute,
 *   default 10) prevents a one-time backlog from flooding the API.
 * • Test mode — when qurbani_wa_test_phone is set, ALL outbound
 *   sends redirect to that phone. The customer's real phone is still
 *   logged in t_ops_qurbani_wa_log.wa_phone for the audit trail.
 * • Fail-safe — when the master switch is OFF, processNow() is a
 *   no-op and writes no log rows.
 *
 * How it gets called
 * ------------------
 *   • app()->terminating() in 2 manager polling endpoints (after
 *     each successful response).
 *   • Scheduler — Schedule::command('qurbani:wa-process')->everyMinute()
 *     in routes/console.php as the cron-fallback.
 *
 * Both pathways acquire the same Cache lock, so the effective fire
 * rate is ≤ once per ~55 seconds regardless of how often we're poked.
 */
class QurbaniWaAutoSender
{
    /** Cache lock TTL — short enough that a missed cron tick still fires within ~1m, long enough to absorb 30s of poll noise. */
    private const LOCK_TTL_SECONDS = 55;

    /** Cache key prefix for the global throttle. */
    private const LOCK_KEY = 'qurbani_wa_auto_lock';

    /** GPS reading is "fresh" for ETA-based scheduling if captured within this many minutes. */
    private const GPS_FRESH_MAX_MIN = 5;

    /** Slip threshold for "previous order in this rider's route was delivered late". */
    private const PRIOR_DELAY_THRESHOLD_MIN_FALLBACK = 10;

    private WhatsAppService $whatsapp;
    private array $cfg;

    public function __construct(WhatsAppService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
        $this->cfg = $this->loadConfig();
    }

    /**
     * Public entry point. Returns:
     *   ['ran' => bool, 'sent' => int, 'skipped' => int, 'failed' => int, 'reason' => ?string]
     *
     * Returns 'ran' => false WITHOUT touching the DB when:
     *   - Master switch is OFF
     *   - Lock was held by a recent run
     *
     * Caller should never block on this method's output — it's
     * called from terminating() callbacks for fire-and-forget.
     */
    public function processNow(?int $maxSends = null): array
    {
        $result = ['ran' => false, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'reason' => null];

        if (!$this->cfg['master_enabled']) {
            $result['reason'] = 'master_off';
            return $result;
        }

        // Cache::add is atomic — only the FIRST caller in this window
        // gets true. Everyone else returns immediately.
        $gotLock = Cache::add(self::LOCK_KEY, now()->toIso8601String(), self::LOCK_TTL_SECONDS);
        if (!$gotLock) {
            $result['reason'] = 'locked';
            return $result;
        }

        $cap = $maxSends ?: max(1, (int) $this->cfg['send_max_per_minute']);
        $result['ran'] = true;

        try {
            // Slaughter trigger first (it's cheaper — single timestamp check).
            if ($this->cfg['slaughter_enabled'] && $cap > 0) {
                $batch = $this->processSlaughterTrigger($cap);
                $result['sent']    += $batch['sent'];
                $result['skipped'] += $batch['skipped'];
                $result['failed']  += $batch['failed'];
                $cap              -= $batch['sent'];
            }

            if ($this->cfg['ofd_enabled'] && $cap > 0) {
                $batch = $this->processOfdTrigger($cap);
                $result['sent']    += $batch['sent'];
                $result['skipped'] += $batch['skipped'];
                $result['failed']  += $batch['failed'];
            }
        } catch (\Throwable $e) {
            // Catch-all so a broken row doesn't poison subsequent runs.
            // The Cache lock will expire on its own.
            Log::error('QurbaniWaAutoSender: unhandled error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $result['reason'] = 'error: ' . $e->getMessage();
        }

        return $result;
    }

    // ─── Trigger 1: Slaughter ─────────────────────────────────────

    /**
     * Find slaughtered items where:
     *   • qurbani_slaughtered_at IS NOT NULL
     *   • now() - qurbani_slaughtered_at >= delay_minutes
     *   • no prior 'sent' log row for (line_item_id, 'slaughtered')
     *
     * Window is bounded to today + delay+60min lookback — we don't
     * want to fire historic re-sends if the master switch is turned
     * on for the first time after older items were already slaughtered.
     */
    private function processSlaughterTrigger(int $cap): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $template = trim((string) $this->cfg['slaughter_template']);
        if ($template === '') {
            return $out; // not configured yet — skip silently
        }
        $delayMin = max(0, (int) $this->cfg['slaughter_delay_minutes']);

        $candidates = $this->fetchSlaughterCandidates($delayMin, $cap);

        foreach ($candidates as $li) {
            $batchKey = "li:{$li->line_item_id}:slaughtered";
            try {
                if ($this->isAlreadySent($li->line_item_id, 'slaughtered')) {
                    $out['skipped']++;
                    continue;
                }
                $phone = $this->resolveCustomerPhone($li);
                if ($phone === null) {
                    $this->logRow($li, 'slaughtered', $template, null, 'skipped', 'no_phone', null);
                    $out['skipped']++;
                    continue;
                }
                [$sendTo, $loggedPhone] = $this->resolveSendTarget($phone);

                $params = [
                    $this->customerFirstName($li),
                    $this->orderNumber($li),
                ];
                $sent = $this->sendTemplate($sendTo, $template, $params, "Qurbani slaughtered: {$this->orderNumber($li)}");
                if ($sent['ok']) {
                    $this->logRow($li, 'slaughtered', $template, $loggedPhone, 'sent', null, null,
                        $sent['wa_message_id'] ?? null, $sent['conversation_id'] ?? null);
                    $out['sent']++;
                } else {
                    $this->logRow($li, 'slaughtered', $template, $loggedPhone, 'failed', $sent['error'] ?? 'send_failed', null);
                    $out['failed']++;
                }
            } catch (\Throwable $e) {
                Log::error('QurbaniWaAutoSender: slaughter row failed', ['key' => $batchKey, 'error' => $e->getMessage()]);
                $out['failed']++;
            }
        }

        return $out;
    }

    private function fetchSlaughterCandidates(int $delayMin, int $cap)
    {
        // Lookback floor: today's start. If admin enables the trigger
        // mid-day we don't retroactively message everyone slaughtered
        // earlier — we only catch items where (slaughtered_at + delay)
        // is within the last 6 hours, so we never spam old data.
        $startCutoff = now()->subHours(6)->toDateTimeString();
        $eligibleBefore = now()->subMinutes($delayMin)->toDateTimeString();

        return DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotNull('li.qurbani_slaughtered_at')
            ->where('li.qurbani_slaughtered_at', '>=', $startCutoff)
            ->where('li.qurbani_slaughtered_at', '<=', $eligibleBefore)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('t_ops_qurbani_wa_log as l')
                  ->whereColumn('l.line_item_id', 'li.id')
                  ->where('l.trigger_event', 'slaughtered')
                  ->where('l.status', 'sent');
            })
            ->select(
                'li.id as line_item_id',
                'li.order_id',
                'li.qurbani_slaughtered_at',
                'li.qurbani_slot',
                'li.qurbani_delivery_type',
                'o.order_number',
                'o.customer_id',
                'o.address_first_name',
                'o.address_last_name',
                'o.address_phone',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.phone as customer_phone'
            )
            ->orderBy('li.qurbani_slaughtered_at')
            ->limit($cap)
            ->get();
    }

    // ─── Trigger 2: Out-for-Delivery / Self-collection ────────────

    /**
     * OFD/collect trigger has 3 timing modes:
     *
     *   after_status:           fire X min after qurbani_out_for_delivery_at
     *   after_dispatch:         fire X min after qurbani_dispatched_at
     *   before_eta_with_buffer: fire X min before (eta + buffer)
     *
     * For after_status / after_dispatch the candidate query is a
     * straight timestamp comparison. For before_eta_with_buffer we
     * compute (eta + buffer) for each row and fire when (now() >=
     * (eta + buffer) - X). All 3 modes additionally require:
     *   • OFD status set
     *   • require_dispatched (if on) → qurbani_dispatched_at set
     *   • not previously logged 'sent' for trigger='ofd'
     */
    private function processOfdTrigger(int $cap): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $deliveryTpl   = trim((string) $this->cfg['ofd_template']);
        $selfTpl       = trim((string) $this->cfg['ofd_self_collection_template']);
        if ($deliveryTpl === '' && $selfTpl === '') {
            return $out;
        }

        $candidates = $this->fetchOfdCandidates($cap);

        foreach ($candidates as $li) {
            try {
                if ($this->isAlreadySent($li->line_item_id, 'ofd')) {
                    $out['skipped']++;
                    continue;
                }

                // Pick the right template based on delivery type.
                $isSelfCollection = $this->isSelfCollection($li->qurbani_delivery_type);
                $template = $isSelfCollection ? $selfTpl : $deliveryTpl;
                if ($template === '') {
                    $this->logRow($li, 'ofd', '(missing)', null, 'skipped',
                        $isSelfCollection ? 'self_collection_template_missing' : 'delivery_template_missing', null);
                    $out['skipped']++;
                    continue;
                }

                // Timing gate — does this candidate meet the timing rule right now?
                $gate = $this->evaluateOfdTiming($li);
                if (!$gate['ready']) {
                    // Not eligible yet — DON'T log a skip row (would
                    // never re-evaluate). Just move on; we'll see this
                    // row again next run.
                    continue;
                }

                $phone = $this->resolveCustomerPhone($li);
                if ($phone === null) {
                    $this->logRow($li, 'ofd', $template, null, 'skipped', 'no_phone', $gate['delivery_time_used']);
                    $out['skipped']++;
                    continue;
                }
                [$sendTo, $loggedPhone] = $this->resolveSendTarget($phone);

                $deliveryTimeText = $this->buildDeliveryTimeText($li);

                $params = [
                    $this->customerFirstName($li),
                    $this->orderNumber($li),
                    $deliveryTimeText,
                ];
                $logCtx = "Qurbani " . ($isSelfCollection ? 'collect' : 'OFD') . ": " . $this->orderNumber($li);
                $sent = $this->sendTemplate($sendTo, $template, $params, $logCtx);
                if ($sent['ok']) {
                    $this->logRow($li, 'ofd', $template, $loggedPhone, 'sent', null, $gate['delivery_time_used'],
                        $sent['wa_message_id'] ?? null, $sent['conversation_id'] ?? null);
                    $out['sent']++;
                } else {
                    $this->logRow($li, 'ofd', $template, $loggedPhone, 'failed', $sent['error'] ?? 'send_failed', $gate['delivery_time_used']);
                    $out['failed']++;
                }
            } catch (\Throwable $e) {
                Log::error('QurbaniWaAutoSender: ofd row failed', ['li' => $li->line_item_id, 'error' => $e->getMessage()]);
                $out['failed']++;
            }
        }

        return $out;
    }

    private function fetchOfdCandidates(int $cap)
    {
        $requireDispatched = (bool) $this->cfg['ofd_require_dispatched'];

        $q = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotNull('li.qurbani_out_for_delivery_at')
            // Don't message after delivery completed.
            ->whereNull('li.qurbani_delivered_at')
            // Lookback to today so old OFDs don't get retro-messaged.
            ->where('li.qurbani_out_for_delivery_at', '>=', now()->subHours(12)->toDateTimeString())
            ->whereNotExists(function ($q2) {
                $q2->select(DB::raw(1))
                   ->from('t_ops_qurbani_wa_log as l')
                   ->whereColumn('l.line_item_id', 'li.id')
                   ->where('l.trigger_event', 'ofd')
                   ->where('l.status', 'sent');
            });

        if ($requireDispatched) {
            $q->whereNotNull('li.qurbani_dispatched_at');
        }

        return $q->select(
                'li.id as line_item_id',
                'li.order_id',
                'li.qurbani_out_for_delivery_at',
                'li.qurbani_dispatched_at',
                'li.qurbani_estimated_delivery_at',
                'li.qurbani_delivery_priority',
                'li.qurbani_assigned_rider_user_id',
                'li.qurbani_slot',
                'li.qurbani_delivery_type',
                'o.order_number',
                'o.customer_id',
                'o.address_first_name',
                'o.address_last_name',
                'o.address_phone',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.phone as customer_phone'
            )
            ->orderBy('li.qurbani_out_for_delivery_at')
            ->limit($cap * 3) // over-fetch — many will fail the timing gate, that's expected
            ->get();
    }

    /**
     * Returns ['ready' => bool, 'delivery_time_used' => ?string].
     * delivery_time_used is the (eta + buffer) timestamp when the
     * timing mode is before_eta_with_buffer — useful for the wa-log.
     */
    private function evaluateOfdTiming($li): array
    {
        $mode = $this->cfg['ofd_timing_mode'];
        $now  = now();

        if ($mode === 'after_status') {
            $delay = max(0, (int) $this->cfg['ofd_minutes_after_status']);
            if (!$li->qurbani_out_for_delivery_at) return ['ready' => false, 'delivery_time_used' => null];
            $fireAt = \Carbon\Carbon::parse($li->qurbani_out_for_delivery_at)->addMinutes($delay);
            return ['ready' => $now->greaterThanOrEqualTo($fireAt), 'delivery_time_used' => null];
        }

        if ($mode === 'after_dispatch') {
            $delay = max(0, (int) $this->cfg['ofd_minutes_after_dispatch']);
            if (!$li->qurbani_dispatched_at) return ['ready' => false, 'delivery_time_used' => null];
            $fireAt = \Carbon\Carbon::parse($li->qurbani_dispatched_at)->addMinutes($delay);
            return ['ready' => $now->greaterThanOrEqualTo($fireAt), 'delivery_time_used' => null];
        }

        // before_eta_with_buffer
        if (!$li->qurbani_estimated_delivery_at) {
            return ['ready' => false, 'delivery_time_used' => null];
        }
        $buffer  = max(0, (int) $this->cfg['ofd_eta_buffer_minutes']);
        $before  = max(0, (int) $this->cfg['ofd_minutes_before']);
        $deliveryTime = \Carbon\Carbon::parse($li->qurbani_estimated_delivery_at)->addMinutes($buffer);
        $fireAt = $deliveryTime->copy()->subMinutes($before);
        return [
            'ready'              => $now->greaterThanOrEqualTo($fireAt),
            'delivery_time_used' => $deliveryTime->toDateTimeString(),
        ];
    }

    // ─── Smart delivery-time text (ETA window vs slot fallback) ───

    /**
     * Build the {{3}} parameter for the OFD/collect message. Per
     * user spec:
     *
     *   IF rider GPS is fresh AND no earlier stop in this rider's
     *   route slipped late, return a 1-hour window built from
     *   (eta + buffer) — formatted "7pm-8pm".
     *
     *   ELSE return the slot string (e.g. "Afternoon 11 AM to 3 PM")
     *   so the customer always gets *something*.
     */
    private function buildDeliveryTimeText($li): string
    {
        $slot = trim((string) ($li->qurbani_slot ?? ''));

        // Quick exit if no ETA at all — slot is the only option.
        if (empty($li->qurbani_estimated_delivery_at)) {
            return $slot !== '' ? $slot : '';
        }

        $useEta = $this->canUseEtaForDeliveryText($li);
        if (!$useEta) {
            return $slot !== '' ? $slot : $this->formatHourWindow($li->qurbani_estimated_delivery_at, (int) $this->cfg['ofd_eta_buffer_minutes']);
        }

        return $this->formatHourWindow($li->qurbani_estimated_delivery_at, (int) $this->cfg['ofd_eta_buffer_minutes']);
    }

    /**
     * Fresh GPS + no prior late delivery in this rider's sequence
     * = ETA is trustworthy enough to message a window time.
     */
    private function canUseEtaForDeliveryText($li): bool
    {
        if (empty($li->qurbani_assigned_rider_user_id)) return false;

        $gps = DB::table('t_ops_rider_location')
            ->where('user_id', $li->qurbani_assigned_rider_user_id)
            ->orderBy('captured_at', 'desc')
            ->select('captured_at')
            ->first();
        if (!$gps || !$gps->captured_at) return false;
        try {
            $ageMin = (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($gps->captured_at)));
            if ($ageMin > self::GPS_FRESH_MAX_MIN) return false;
        } catch (\Exception $e) {
            return false;
        }

        // Any earlier stop in this rider's route delivered > threshold late?
        // We don't have a dispatch_sequence column, so we use the
        // earlier estimated_delivery_at (or, failing that, dispatched_at)
        // as a proxy for "earlier in the route". A stop that has actually
        // been delivered is definitionally already past — comparing its
        // delivered_at to its stored ETA tells us if it ran long.
        $threshold = (int) ConfigModel::get('qurbani_eta_delay_threshold_minutes', '10');
        if ($threshold < 1) $threshold = self::PRIOR_DELAY_THRESHOLD_MIN_FALLBACK;

        $myEta = $li->qurbani_estimated_delivery_at;
        $priorQ = DB::table('t_crm_prod_order_line_item')
            ->where('qurbani_assigned_rider_user_id', $li->qurbani_assigned_rider_user_id)
            ->whereNotNull('qurbani_delivered_at')
            ->whereNotNull('qurbani_estimated_delivery_at')
            ->whereRaw('TIMESTAMPDIFF(MINUTE, qurbani_estimated_delivery_at, qurbani_delivered_at) > ?', [$threshold]);
        if ($myEta) {
            // A stop is "before this one" if its planned ETA was earlier.
            $priorQ->where('qurbani_estimated_delivery_at', '<', $myEta);
        }
        $priorDelayed = $priorQ->exists();

        return !$priorDelayed;
    }

    /**
     * Format a timestamp + buffer as a 1-hour window like "7pm-8pm".
     * The window is anchored to the START of the (eta+buffer) hour
     * so 19:30 → "7pm-8pm" and 19:55 → "7pm-8pm" but 20:05 → "8pm-9pm".
     */
    private function formatHourWindow($ts, int $bufferMinutes): string
    {
        try {
            $t = \Carbon\Carbon::parse($ts)->addMinutes(max(0, $bufferMinutes));
            $start = $t->copy()->minute(0)->second(0);
            $end   = $start->copy()->addHour();
            return $this->formatHour($start) . '-' . $this->formatHour($end);
        } catch (\Exception $e) {
            return '';
        }
    }

    private function formatHour(\Carbon\Carbon $t): string
    {
        // 7pm, 12am, 12pm, etc.
        return strtolower($t->format('ga'));
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function isAlreadySent(int $lineItemId, string $trigger): bool
    {
        return DB::table('t_ops_qurbani_wa_log')
            ->where('line_item_id', $lineItemId)
            ->where('trigger_event', $trigger)
            ->where('status', 'sent')
            ->exists();
    }

    private function resolveCustomerPhone($li): ?string
    {
        // Order's shipping address phone wins — it's what the rider
        // calls when knocking the door. Falls back to the customer
        // record's phone if the order's address phone is blank.
        $candidates = [$li->address_phone ?? null, $li->customer_phone ?? null];
        foreach ($candidates as $c) {
            $clean = trim((string) ($c ?? ''));
            if ($clean === '') continue;
            $formatted = $this->whatsapp->formatPhone($clean);
            if ($formatted !== '') return $formatted;
        }
        return null;
    }

    private function resolveSendTarget(string $customerPhone): array
    {
        $test = trim((string) $this->cfg['test_phone']);
        if ($test !== '') {
            return [$this->whatsapp->formatPhone($test), $customerPhone];
        }
        return [$customerPhone, $customerPhone];
    }

    private function customerFirstName($li): string
    {
        // Prefer the order's shipping address first name (matches the
        // door label) — fall back to the master customer record.
        $candidates = [
            $li->address_first_name ?? null,
            $li->customer_first_name ?? null,
        ];
        foreach ($candidates as $c) {
            $clean = trim((string) ($c ?? ''));
            if ($clean !== '') {
                $parts = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
                return $parts[0] ?? 'Customer';
            }
        }
        return 'Customer';
    }

    private function orderNumber($li): string
    {
        return (string) ($li->order_number ?? ('#' . ($li->order_id ?? '')));
    }

    private function isSelfCollection(?string $deliveryType): bool
    {
        if ($deliveryType === null) return false;
        $needle = strtolower($deliveryType);
        return str_contains($needle, 'self') || str_contains($needle, 'collect') || str_contains($needle, 'pickup') || str_contains($needle, 'pick-up') || str_contains($needle, 'pick up');
    }

    /**
     * Send the template + persist the outbound row in t_wa_messages.
     * Returns ['ok' => bool, 'wa_message_id' => ?, 'conversation_id' => ?, 'error' => ?].
     *
     * We deliberately mirror what WhatsAppController::sendInvoice does
     * so the message shows up in the existing customer thread on the
     * /messages page, which is also where the new "Timeline" button
     * will surface them.
     */
    private function sendTemplate(string $to, string $templateName, array $bodyParams, string $logContent): array
    {
        try {
            // Look up template language; default 'en'.
            $tplRow = DB::table('t_wa_templates')->where('name', $templateName)->where('status', 'approved')->first(['language']);
            $lang = $tplRow ? (string) ($tplRow->language ?? 'en') : 'en';

            $resp = $this->whatsapp->sendTemplateMessage($to, $templateName, $lang ?: 'en', $bodyParams);
            if (!($resp['success'] ?? false)) {
                return ['ok' => false, 'error' => substr((string) ($resp['error'] ?? 'send_failed'), 0, 240)];
            }

            $conv = $this->whatsapp->findOrCreateConversation($to);
            $msg  = $this->whatsapp->saveOutboundMessage(
                $conv->id,
                $resp,
                'template',
                $logContent,
                null, // sent_by — system, no user
                $templateName,
                $bodyParams
            );

            // Meta message id can live in different keys depending on
            // the SDK shape; sniff the common ones.
            $waMsgId = null;
            if (isset($resp['messages'][0]['id'])) {
                $waMsgId = $resp['messages'][0]['id'];
            } elseif (isset($resp['data']['messages'][0]['id'])) {
                $waMsgId = $resp['data']['messages'][0]['id'];
            }

            return ['ok' => true, 'wa_message_id' => $waMsgId, 'conversation_id' => $conv->id];
        } catch (\Throwable $e) {
            Log::warning('QurbaniWaAutoSender: send threw', ['template' => $templateName, 'to' => $to, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => substr($e->getMessage(), 0, 240)];
        }
    }

    private function logRow($li, string $trigger, string $template, ?string $phone, string $status, ?string $skipReason, ?string $deliveryTimeUsed, ?string $waMessageId = null, ?int $conversationId = null): void
    {
        try {
            DB::table('t_ops_qurbani_wa_log')->insert([
                'line_item_id'       => $li->line_item_id,
                'order_id'           => $li->order_id,
                'customer_id'        => $li->customer_id ?? null,
                'trigger_event'      => $trigger,
                'template_name'      => $template ?: '(unset)',
                'wa_phone'           => $phone,
                'wa_message_id'      => $waMessageId,
                'conversation_id'    => $conversationId,
                'status'             => $status,
                'skip_reason'        => $skipReason,
                'delivery_time_used' => $deliveryTimeUsed,
                'created_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            // Logging failures must NEVER break the worker.
            Log::error('QurbaniWaAutoSender: log insert failed', ['error' => $e->getMessage()]);
        }
    }

    private function loadConfig(): array
    {
        $get  = fn(string $k, $d = null) => ConfigModel::get($k, $d);
        $bool = fn(string $k, bool $d = false) => ConfigModel::get($k, $d ? '1' : '0') === '1';
        $int  = fn(string $k, int $d) => (int) ConfigModel::get($k, (string) $d);

        return [
            'master_enabled'              => $bool('qurbani_wa_auto_enabled', false),
            'test_phone'                  => (string) $get('qurbani_wa_test_phone', ''),
            'send_max_per_minute'         => $int('qurbani_wa_send_max_per_minute', 10),

            'slaughter_enabled'           => $bool('qurbani_wa_slaughter_enabled', false),
            'slaughter_template'          => (string) $get('qurbani_wa_slaughter_template', ''),
            'slaughter_delay_minutes'     => $int('qurbani_wa_slaughter_delay_minutes', 30),

            'ofd_enabled'                 => $bool('qurbani_wa_ofd_enabled', false),
            'ofd_template'                => (string) $get('qurbani_wa_ofd_template', ''),
            'ofd_self_collection_template' => (string) $get('qurbani_wa_ofd_self_collection_template', ''),
            'ofd_require_dispatched'      => $bool('qurbani_wa_ofd_require_dispatched', true),
            'ofd_timing_mode'             => (string) $get('qurbani_wa_ofd_timing_mode', 'before_eta_with_buffer'),
            'ofd_minutes_after_status'    => $int('qurbani_wa_ofd_minutes_after_status', 0),
            'ofd_minutes_after_dispatch'  => $int('qurbani_wa_ofd_minutes_after_dispatch', 30),
            'ofd_eta_buffer_minutes'      => $int('qurbani_wa_ofd_eta_buffer_minutes', 15),
            'ofd_minutes_before'          => $int('qurbani_wa_ofd_minutes_before', 15),
        ];
    }
}
