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
     * OFD trigger (May-2026 rev2). Drives off `qurbani_dispatched_at`
     * — when the manager presses Dispatch in qurbani mode (or the
     * rider presses Start in rider mode — same backend event) the
     * line item enters this worker's candidate set. From there the
     * eligibility per line item depends on its lane:
     *
     *   • Self-collection (delivery_type contains self/collect/pickup):
     *       Fire IMMEDIATELY on dispatch using the self-collection
     *       template — only 2 params (name, order#). No time window,
     *       no ETA needed. Customer is coming to us; we just announce
     *       readiness.
     *
     *   • Delivery + ETA exists:
     *       Hold the send until (eta - now) <= eta_window_minutes
     *       (default 120). At that point fire the delivery template
     *       with 3 params: name, order#, and a rounded ETA range
     *       built as floor(eta, 10min) → start, start+30min → end
     *       (e.g. 7:32 → "7:30 PM - 8:00 PM"). This is the "don't
     *       spam customers whose stop is 4 hours away" rule.
     *
     *   • Delivery + no ETA (missing coords case — Waseem-style):
     *       Fire IMMEDIATELY on dispatch with the slot string
     *       (e.g. "Afternoon 11 AM to 3 PM") as the {{3}} param.
     *       Customer still gets a window, just a wider one — the
     *       slot they originally booked.
     *
     * Stored in wa-log.delivery_time_used: the raw ETA timestamp we
     * based the message on (NULL for self-collect / no-ETA paths).
     * The manual delay-update flow compares this against current
     * `qurbani_estimated_delivery_at` to find stops that need a
     * second message.
     *
     * Worker runs every minute, so a stop whose ETA was 4 h away at
     * dispatch silently rolls into the 2 h window 2 h later — no
     * delivery-event hook needed.
     */
    private function processOfdTrigger(int $cap): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $deliveryTpl = trim((string) $this->cfg['ofd_template']);
        $selfTpl     = trim((string) $this->cfg['ofd_self_collection_template']);
        if ($deliveryTpl === '' && $selfTpl === '') {
            return $out;
        }

        $windowMin  = max(0, (int) $this->cfg['ofd_eta_window_minutes']);
        $candidates = $this->fetchOfdCandidates($cap);

        foreach ($candidates as $li) {
            try {
                if ($this->isAlreadySent($li->line_item_id, 'ofd')) {
                    $out['skipped']++;
                    continue;
                }

                $isSelfCollection = $this->isSelfCollection($li->qurbani_delivery_type);
                $template = $isSelfCollection ? $selfTpl : $deliveryTpl;
                if ($template === '') {
                    $this->logRow($li, 'ofd', '(missing)', null, 'skipped',
                        $isSelfCollection ? 'self_collection_template_missing' : 'delivery_template_missing', null);
                    $out['skipped']++;
                    continue;
                }

                // Build params + decide if eligible right now.
                $etaUsed = null;
                $params = null;

                if ($isSelfCollection) {
                    // Self-collection — fire immediately, 2 vars only.
                    // The template body is configured in Meta to only
                    // reference {{1}} (name) + {{2}} (order#); no time
                    // variable. WhatsAppService::sendTemplateMessage
                    // pads bodyParams to whatever the template expects,
                    // so passing 2 is correct.
                    $params = [
                        $this->customerFirstName($li),
                        $this->orderNumber($li),
                    ];
                } else {
                    // Delivery — needs 3 params. ETA gates the send.
                    $eta = $li->qurbani_estimated_delivery_at ?: null;
                    if ($eta) {
                        $etaCarbon = \Carbon\Carbon::parse($eta);
                        // diffInMinutes(now(), false) returns negative
                        // when the eta is in the future. We want
                        // "minutes from now until eta" → flip the sign.
                        $minutesUntil = -1 * $etaCarbon->diffInMinutes(now(), false);
                        if ($minutesUntil > $windowMin) {
                            // Too far out — silently skip; we'll re-evaluate
                            // every minute and fire once it rolls in.
                            continue;
                        }
                        $timeText = $this->formatTenMinRange($etaCarbon);
                        $etaUsed = $etaCarbon->toDateTimeString();
                    } else {
                        // Missing coords / no ETA → slot fallback, fire now.
                        $timeText = trim((string) ($li->qurbani_slot ?? ''));
                    }
                    $params = [
                        $this->customerFirstName($li),
                        $this->orderNumber($li),
                        $timeText,
                    ];
                }

                $phone = $this->resolveCustomerPhone($li);
                if ($phone === null) {
                    $this->logRow($li, 'ofd', $template, null, 'skipped', 'no_phone', $etaUsed);
                    $out['skipped']++;
                    continue;
                }
                [$sendTo, $loggedPhone] = $this->resolveSendTarget($phone);

                $logCtx = "Qurbani " . ($isSelfCollection ? 'collect' : 'OFD') . ": " . $this->orderNumber($li);
                $sent = $this->sendTemplate($sendTo, $template, $params, $logCtx);
                if ($sent['ok']) {
                    $this->logRow($li, 'ofd', $template, $loggedPhone, 'sent', null, $etaUsed,
                        $sent['wa_message_id'] ?? null, $sent['conversation_id'] ?? null);
                    $out['sent']++;
                } else {
                    $this->logRow($li, 'ofd', $template, $loggedPhone, 'failed', $sent['error'] ?? 'send_failed', $etaUsed);
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
        // May-2026 rev2 — candidate set is now anchored to
        // qurbani_dispatched_at (dispatch button), NOT the old
        // qurbani_out_for_delivery_at (manual status flip). Dispatch
        // = the manager pressing Dispatch in qurbani mode OR the
        // rider pressing Start in rider mode — both call
        // dispatchQurbaniRoute(target='pending') which stamps the
        // same column. So the lane is unified regardless of who
        // pressed.
        //
        // 24h lookback covers overnight-dispatch scenarios (manager
        // sets up day-2 routes the previous evening). Older rows are
        // ignored to prevent retroactive spam when the feature is
        // toggled on after the fact.
        return DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotNull('li.qurbani_dispatched_at')
            ->whereNull('li.qurbani_delivered_at')
            ->where('li.qurbani_dispatched_at', '>=', now()->subHours(24)->toDateTimeString())
            ->whereNotExists(function ($q2) {
                $q2->select(DB::raw(1))
                   ->from('t_ops_qurbani_wa_log as l')
                   ->whereColumn('l.line_item_id', 'li.id')
                   ->where('l.trigger_event', 'ofd')
                   ->where('l.status', 'sent');
            })
            ->select(
                'li.id as line_item_id',
                'li.order_id',
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
            // Earliest-ETA first so the 2h-window rule processes the
            // nearest stops before the cap is exhausted. Rows with NULL
            // ETA sort last in MySQL ASC, which is what we want too —
            // those fire immediately regardless of position in the list.
            ->orderByRaw('li.qurbani_estimated_delivery_at IS NULL, li.qurbani_estimated_delivery_at ASC')
            ->limit($cap * 3) // over-fetch; window-gated rows will skip
            ->get();
    }

    /**
     * Format a Carbon ETA as a rounded 10-min / +30-min range, e.g.
     *   7:32 PM → "7:30 PM - 8:00 PM"
     *   5:45 PM → "5:40 PM - 6:10 PM"
     *   11:55 PM → "11:50 PM - 12:20 AM" (day rollover)
     *
     * Step 1 — round DOWN to the nearest 10-minute mark for the
     *           start (so a stop estimated at 7:32 doesn't suggest
     *           a 7:30-8:00 window to a customer expecting it now).
     * Step 2 — start + 30 min for the end. Carbon's addMinutes
     *           handles day/year rollover correctly.
     * Format — `g:i A` (12h with AM/PM marker, leading zero
     *           stripped). Picked over compact "7:30pm" because
     *           customers replied to past tests asking what "7:30pm"
     *           meant — the explicit "7:30 PM - 8:00 PM" with the
     *           hyphen + spaces is unambiguous on Android + iPhone
     *           WhatsApp clients.
     */
    private function formatTenMinRange(\Carbon\Carbon $eta): string
    {
        try {
            $start = $eta->copy()->second(0)->minute(intdiv($eta->minute, 10) * 10);
            $end   = $start->copy()->addMinutes(30);
            return $start->format('g:i A') . ' - ' . $end->format('g:i A');
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ─── Manual delay-update flow (May-2026 — manager-only) ──────

    /**
     * Find dispatched + un-delivered stops where the rider's CURRENT
     * Google ETA differs from the timestamp we last messaged the
     * customer about by more than `delay_threshold_minutes` (default
     * 30). One row per line item (grouped by customer when returned
     * to the UI).
     *
     * Used by:
     *   - GET /api/qurbani/riders/{riderId}/delay-impacted — feeds
     *     the manager banner in the mobile Qurbani Riders → Route
     *     screen.
     *   - sendDelayUpdate() below — server-side cross-check before
     *     firing, so a stale UI payload can't trick the worker into
     *     resending stops that aren't actually impacted any more.
     *
     * "Last messaged" is the most recent SUCCESSFUL wa-log row for
     * this line item across BOTH trigger types ('ofd' and
     * 'ofd_delay_update') — that way a previous delay-update resets
     * the comparison baseline (you don't keep getting "30 min
     * slipped" alerts for the same slip).
     *
     * Returns an array of stdClass rows:
     *   { line_item_id, order_id, order_number, customer_id,
     *     customer_name, qurbani_delivery_type,
     *     messaged_eta_at, current_eta_at, delta_minutes,
     *     last_trigger_event, in_cooldown (bool) }
     */
    public function findDelayImpactedStops(int $riderId): array
    {
        $threshold = max(1, (int) $this->cfg['ofd_delay_threshold_minutes']);
        $cooldown  = max(0, (int) $this->cfg['ofd_delay_resend_cooldown_minutes']);
        $cooldownCutoff = $cooldown > 0
            ? now()->subMinutes($cooldown)->toDateTimeString()
            : null;

        // Pull every stop on this rider's route that's dispatched +
        // not delivered + has both a current ETA AND a previously-
        // messaged ETA. Without either side of the comparison there's
        // nothing to flag.
        $rows = DB::select(
            "SELECT
                li.id              AS line_item_id,
                li.order_id,
                li.qurbani_delivery_type,
                li.qurbani_estimated_delivery_at AS current_eta_at,
                o.order_number,
                o.customer_id,
                o.address_first_name,
                o.address_last_name,
                c.first_name       AS customer_first_name,
                c.last_name        AS customer_last_name,
                (SELECT l.delivery_time_used FROM t_ops_qurbani_wa_log l
                  WHERE l.line_item_id = li.id
                    AND l.trigger_event IN ('ofd','ofd_delay_update')
                    AND l.status = 'sent'
                    AND l.delivery_time_used IS NOT NULL
                  ORDER BY l.created_at DESC LIMIT 1) AS messaged_eta_at,
                (SELECT l.trigger_event FROM t_ops_qurbani_wa_log l
                  WHERE l.line_item_id = li.id
                    AND l.trigger_event IN ('ofd','ofd_delay_update')
                    AND l.status = 'sent'
                  ORDER BY l.created_at DESC LIMIT 1) AS last_trigger_event,
                (SELECT MAX(l.created_at) FROM t_ops_qurbani_wa_log l
                  WHERE l.line_item_id = li.id
                    AND l.trigger_event = 'ofd_delay_update'
                    AND l.status = 'sent') AS last_delay_update_at
             FROM t_crm_prod_order_line_item li
             INNER JOIN t_crm_prod_order o ON o.id = li.order_id
             LEFT JOIN t_crm_customer c ON c.id = o.customer_id
             WHERE li.qurbani_assigned_rider_user_id = ?
               AND li.qurbani_dispatched_at IS NOT NULL
               AND li.qurbani_delivered_at IS NULL
               AND li.qurbani_estimated_delivery_at IS NOT NULL",
            [$riderId]
        );

        $impacted = [];
        foreach ($rows as $r) {
            if (empty($r->messaged_eta_at)) continue; // never messaged → no delay-update applicable
            try {
                $messaged = \Carbon\Carbon::parse($r->messaged_eta_at);
                $current  = \Carbon\Carbon::parse($r->current_eta_at);
                // Positive delta = current ETA is LATER than what we
                // messaged. Negative would mean the rider's ahead of
                // schedule, which the customer doesn't need to know about.
                $delta = (int) $messaged->diffInMinutes($current, false);
                if ($delta <= $threshold) continue;

                $inCooldown = $cooldownCutoff !== null
                    && !empty($r->last_delay_update_at)
                    && strtotime((string) $r->last_delay_update_at) > strtotime($cooldownCutoff);

                $name = trim(
                    ($r->address_first_name ?? '') . ' ' .
                    ($r->address_last_name ?? '')
                );
                if ($name === '') {
                    $name = trim(($r->customer_first_name ?? '') . ' ' . ($r->customer_last_name ?? ''));
                }
                if ($name === '') $name = 'Customer';

                $impacted[] = (object) [
                    'line_item_id'          => (int) $r->line_item_id,
                    'order_id'              => (int) $r->order_id,
                    'order_number'          => (string) $r->order_number,
                    'customer_id'           => $r->customer_id ? (int) $r->customer_id : null,
                    'customer_name'         => $name,
                    'qurbani_delivery_type' => (string) ($r->qurbani_delivery_type ?? ''),
                    'messaged_eta_at'       => (string) $r->messaged_eta_at,
                    'current_eta_at'        => (string) $r->current_eta_at,
                    'delta_minutes'         => $delta,
                    'last_trigger_event'    => (string) ($r->last_trigger_event ?? 'ofd'),
                    'in_cooldown'           => (bool) $inCooldown,
                ];
            } catch (\Throwable $e) {
                // Bad timestamp — skip this row, don't kill the whole list.
                Log::debug('QurbaniWaAutoSender: delay impact parse failed', ['li' => $r->line_item_id, 'err' => $e->getMessage()]);
            }
        }

        // Sort by largest delay first so the manager sees the worst
        // slippage at the top of the list.
        usort($impacted, function ($a, $b) {
            return $b->delta_minutes <=> $a->delta_minutes;
        });

        return $impacted;
    }

    /**
     * Manager-triggered re-send of the OFD delivery template with the
     * NEW rounded-range time, for stops whose ETA has slipped.
     *
     * Caller (RiderController) is responsible for the permission gate.
     * Idempotency/cooldown is enforced here so a runaway client can't
     * spam customers — each line item gets at most one
     * 'ofd_delay_update' send per `cooldown_minutes` window.
     *
     * @param int        $riderId
     * @param int[]|null $lineItemIds  Optional whitelist. NULL = send
     *                                 to every currently-impacted stop.
     * @return array  ['sent'=>int, 'skipped'=>int, 'failed'=>int,
     *                 'reasons'=>[ [li_id, reason], ... ] ]
     */
    public function sendDelayUpdate(int $riderId, ?array $lineItemIds = null): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'reasons' => []];

        // Master switch — same gate as the auto worker.
        if (!$this->cfg['master_enabled']) {
            $out['reasons'][] = ['reason' => 'master_off'];
            return $out;
        }
        $deliveryTpl = trim((string) $this->cfg['ofd_template']);
        if ($deliveryTpl === '') {
            $out['reasons'][] = ['reason' => 'delivery_template_missing'];
            return $out;
        }

        $impacted = $this->findDelayImpactedStops($riderId);
        if (empty($impacted)) return $out;

        // Build a quick lookup so we can also fetch the customer
        // contact details for each impacted line item without an
        // N+1. We need: address phone, customer phone, slot (for
        // self-collect fallback never reached here — defensive).
        $impactedIds = array_map(fn($r) => $r->line_item_id, $impacted);
        if ($lineItemIds !== null) {
            $whitelist = array_map('intval', $lineItemIds);
            $impactedIds = array_values(array_intersect($impactedIds, $whitelist));
            $impacted = array_values(array_filter($impacted, fn($r) => in_array($r->line_item_id, $impactedIds, true)));
        }
        if (empty($impacted)) return $out;

        $details = DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('li.id', $impactedIds)
            ->select(
                'li.id as line_item_id',
                'li.order_id',
                'li.qurbani_slot',
                'li.qurbani_delivery_type',
                'li.qurbani_estimated_delivery_at',
                'o.order_number',
                'o.customer_id',
                'o.address_first_name',
                'o.address_last_name',
                'o.address_phone',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.phone as customer_phone'
            )
            ->get()
            ->keyBy('line_item_id');

        foreach ($impacted as $imp) {
            $li = $details[$imp->line_item_id] ?? null;
            if (!$li) {
                $out['skipped']++;
                $out['reasons'][] = ['li_id' => $imp->line_item_id, 'reason' => 'detail_missing'];
                continue;
            }
            // Self-collect should never appear in the impacted list
            // (no ETA-based message went out) — but defensively skip
            // if it does.
            if ($this->isSelfCollection($li->qurbani_delivery_type)) {
                $out['skipped']++;
                $out['reasons'][] = ['li_id' => $imp->line_item_id, 'reason' => 'self_collection_skipped'];
                continue;
            }
            if ($imp->in_cooldown) {
                $out['skipped']++;
                $out['reasons'][] = ['li_id' => $imp->line_item_id, 'reason' => 'cooldown'];
                continue;
            }
            try {
                $eta = $li->qurbani_estimated_delivery_at;
                if (empty($eta)) {
                    $out['skipped']++;
                    $out['reasons'][] = ['li_id' => $imp->line_item_id, 'reason' => 'no_current_eta'];
                    continue;
                }
                $etaCarbon = \Carbon\Carbon::parse($eta);
                $timeText  = $this->formatTenMinRange($etaCarbon);

                $phone = $this->resolveCustomerPhone($li);
                if ($phone === null) {
                    $this->logRow($li, 'ofd_delay_update', $deliveryTpl, null, 'skipped', 'no_phone', $etaCarbon->toDateTimeString());
                    $out['skipped']++;
                    $out['reasons'][] = ['li_id' => $imp->line_item_id, 'reason' => 'no_phone'];
                    continue;
                }
                [$sendTo, $loggedPhone] = $this->resolveSendTarget($phone);

                $params = [
                    $this->customerFirstName($li),
                    $this->orderNumber($li),
                    $timeText,
                ];
                $logCtx = "Qurbani OFD (delay update): " . $this->orderNumber($li);
                $sent = $this->sendTemplate($sendTo, $deliveryTpl, $params, $logCtx);
                if ($sent['ok']) {
                    $this->logRow($li, 'ofd_delay_update', $deliveryTpl, $loggedPhone, 'sent', null, $etaCarbon->toDateTimeString(),
                        $sent['wa_message_id'] ?? null, $sent['conversation_id'] ?? null);
                    $out['sent']++;
                } else {
                    $this->logRow($li, 'ofd_delay_update', $deliveryTpl, $loggedPhone, 'failed', $sent['error'] ?? 'send_failed', $etaCarbon->toDateTimeString());
                    $out['failed']++;
                    $out['reasons'][] = ['li_id' => $imp->line_item_id, 'reason' => 'send_failed'];
                }
            } catch (\Throwable $e) {
                Log::error('QurbaniWaAutoSender: delay-update row failed', ['li' => $imp->line_item_id, 'error' => $e->getMessage()]);
                $out['failed']++;
                $out['reasons'][] = ['li_id' => $imp->line_item_id, 'reason' => 'exception'];
            }
        }

        return $out;
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

            // May-2026 rev2 — new ETA-window rule + delay-update knobs.
            // Old config keys (ofd_timing_mode, ofd_minutes_after_status,
            // ofd_minutes_after_dispatch, ofd_eta_buffer_minutes,
            // ofd_minutes_before, ofd_require_dispatched) are no longer
            // consulted but left in t_fin_config so a future revert is
            // a one-line revert here.
            'ofd_eta_window_minutes'                => $int('qurbani_wa_ofd_eta_window_minutes', 120),
            'ofd_delay_threshold_minutes'           => $int('qurbani_wa_ofd_delay_threshold_minutes', 30),
            'ofd_delay_resend_cooldown_minutes'     => $int('qurbani_wa_ofd_delay_resend_cooldown_minutes', 15),
        ];
    }
}
