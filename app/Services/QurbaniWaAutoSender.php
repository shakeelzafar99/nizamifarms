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

        // May-2026 — heartbeat. Stamp EVERY entry into processNow()
        // including master-off and locked paths. The diagnose page
        // surfaces this as "last attempt" so the operator can tell
        // at a glance whether the scheduler/cron is alive in prod.
        // Without this heartbeat the only signal that the worker is
        // running is t_ops_qurbani_wa_log rows — but those only
        // appear when a real candidate is found, so a quiet day
        // looks identical to "cron is dead".
        try {
            Cache::put('qurbani_wa_last_entry_at', now()->toDateTimeString(), now()->addDays(7));
        } catch (\Throwable $e) {
            // Cache failures must NEVER break the worker.
        }

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

        // Stamp "actually got the lock" so we can distinguish
        // "scheduler is firing but always losing the lock race" from
        // "scheduler not running at all".
        try {
            Cache::put('qurbani_wa_last_tick_at', now()->toDateTimeString(), now()->addDays(7));
        } catch (\Throwable $e) {
            // ignore
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
                // Defence-in-depth — see hasReachedHardCap docstring.
                if ($this->hasReachedHardCap($li->line_item_id, 'slaughtered')) {
                    $this->logRow($li, 'slaughtered', $template, null, 'skipped', 'hard_cap_reached', null);
                    Log::warning('QurbaniWaAutoSender: hard send cap reached', [
                        'line_item_id' => $li->line_item_id,
                        'trigger'      => 'slaughtered',
                        'cap'          => self::HARD_SEND_CAP,
                        'note'         => 'No further attempts will be made. DELETE log rows for this line item to reset.',
                    ]);
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

        // May-2026 — DEDUPE IS ALL-TIME, NO EXCEPTIONS.
        // Previously this method had a "test mode" relaxation that
        // restricted the dedupe whereNotExists to the last 2 minutes
        // when qurbani_wa_test_phone was set. The intent was to make
        // re-testing the same line item easy in dev, but the side
        // effect in production (where the operator uses test_phone
        // as a "redirect to my number" safety mechanism rather than
        // a re-test toggle) was that EVERY slaughtered line item
        // got re-messaged every 2-15 minutes for 6 hours after the
        // slaughter timestamp. The operator received the same
        // 'qurbani_start' template repeatedly, traced in prod logs
        // around 2026-05-25 21:58 → 22:42.
        //
        // The fix: test_phone now ONLY redirects the phone, never
        // relaxes dedupe. For intentional re-tests, use:
        //   1. Performance screen → row action → 🔪 Slaughter button
        //      (which has a force=true flag to bypass dedupe ONCE
        //       after a confirmation dialog), OR
        //   2. Manual SQL: DELETE FROM t_ops_qurbani_wa_log
        //      WHERE line_item_id = ? AND trigger_event = 'slaughtered'
        //
        // After this fix is deployed, in-flight spam stops
        // immediately because each spammed line item has a
        // 'sent' log row that now blocks all subsequent ticks.
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
                if ($this->hasReachedHardCap($li->line_item_id, 'ofd')) {
                    $this->logRow($li, 'ofd', '(capped)', null, 'skipped', 'hard_cap_reached', null);
                    Log::warning('QurbaniWaAutoSender: hard send cap reached', [
                        'line_item_id' => $li->line_item_id,
                        'trigger'      => 'ofd',
                        'cap'          => self::HARD_SEND_CAP,
                    ]);
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
                    // Self-collection — 2 vars only (name + order). The
                    // template body is configured in Meta to only use
                    // {{1}} and {{2}}; no time / rider variables.
                    $params = $this->buildOfdParams($li, null, true);
                } else {
                    // Delivery — 3 vars by default, or 5 with the
                    // qurbani_ofd_rider variant (adds rider name +
                    // contact). ETA still gates the send identically.
                    $eta = $li->qurbani_estimated_delivery_at ?: null;
                    $timeText = '';
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
                    $params = $this->buildOfdParams($li, $timeText, false);
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
        //
        // May-2026 — DEDUPE IS ALL-TIME, NO EXCEPTIONS. Same fix as
        // fetchSlaughterCandidates (see the long comment block there
        // for the root-cause analysis of the 21:58–22:42 prod spam
        // and why test_phone must NOT relax dedupe). For intentional
        // re-tests, use the Performance screen's row action 🛵 OFD
        // button (which has a force flag), or DELETE rows from
        // t_ops_qurbani_wa_log manually.
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
                // May-2026 — needed by buildOfdParams() for the
                // with_rider variant. See QurbaniWaAutoSender::resolveRiderInfo.
                'li.qurbani_assigned_rider_user_id',
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

                $params = $this->buildOfdParams($li, $timeText, false);
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

    // ─── One-shot send (May-2026, CS Manager actions) ────────────

    /**
     * Send the SLAUGHTER template for a single line item NOW.
     *
     * Used by the Qurbani Performance screen's "🔪 Send" quick-action
     * button so the CS manager can manually trigger the message
     * without waiting for the worker tick. Useful when:
     *   - The auto worker is paused (master_off was on at slaughter
     *     time, then turned on later — the candidate is now outside
     *     the lookback window so processNow() won't catch it).
     *   - A previous attempt failed (Meta API blip, missing phone
     *     fixed since) and the manager wants to retry without
     *     waiting for the next dispatch.
     *
     * Gates enforced (same as the auto worker — explicit, no surprises):
     *   - Master switch must be ON (master_off → returns error)
     *   - Slaughter trigger must be enabled
     *   - Slaughter template must be configured
     *   - Line item must have qurbani_slaughtered_at set
     *   - If `force=false` (default): respects the same already-sent
     *     dedupe as the auto worker. Force=true bypasses ONLY the
     *     dedupe — every other gate still applies. Phone resolution
     *     still falls back to test_phone in test mode.
     *
     * Returns the same shape as processNow() entries with one extra
     * field: `wa_log_id` — the t_ops_qurbani_wa_log row id we wrote,
     * so the UI can correlate the result back to a specific log entry.
     */
    public function sendSlaughterForLineItem(int $lineItemId, bool $force = false): array
    {
        if (!$this->cfg['master_enabled']) {
            return ['ok' => false, 'reason' => 'master_off', 'message' => 'Auto WhatsApp master switch is OFF.'];
        }
        if (!$this->cfg['slaughter_enabled']) {
            return ['ok' => false, 'reason' => 'slaughter_disabled', 'message' => 'Slaughter trigger is disabled in settings.'];
        }
        $template = trim((string) $this->cfg['slaughter_template']);
        if ($template === '') {
            return ['ok' => false, 'reason' => 'template_missing', 'message' => 'No slaughter template configured.'];
        }

        $li = $this->fetchLineItemForSend($lineItemId);
        if (!$li) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => 'Line item not found.'];
        }
        if (empty($li->qurbani_slaughtered_at)) {
            return ['ok' => false, 'reason' => 'not_slaughtered', 'message' => 'Line item has no qurbani_slaughtered_at — mark it slaughtered first.'];
        }

        if (!$force && $this->isAlreadySent($lineItemId, 'slaughtered')) {
            return ['ok' => false, 'reason' => 'already_sent', 'message' => 'Slaughter message already sent. Use Force to override.'];
        }
        // Hard cap protects even force=true. If the operator hammered
        // force 10 times the only recourse is DELETE from the log table.
        if ($this->hasReachedHardCap($lineItemId, 'slaughtered')) {
            return [
                'ok' => false,
                'reason' => 'hard_cap_reached',
                'message' => 'This line item has hit the lifetime send cap of ' . self::HARD_SEND_CAP . ' attempts for the slaughter trigger. To re-test, manually DELETE rows from t_ops_qurbani_wa_log WHERE line_item_id = ' . $lineItemId . " AND trigger_event = 'slaughtered'.",
            ];
        }

        $phone = $this->resolveCustomerPhone($li);
        if ($phone === null) {
            $this->logRow($li, 'slaughtered', $template, null, 'skipped', 'no_phone', null);
            return ['ok' => false, 'reason' => 'no_phone', 'message' => 'No customer phone on file (and no test_phone configured).'];
        }
        [$sendTo, $loggedPhone] = $this->resolveSendTarget($phone);

        $params = [$this->customerFirstName($li), $this->orderNumber($li)];
        $logCtx = "Qurbani slaughtered (manual): {$this->orderNumber($li)}";
        $sent = $this->sendTemplate($sendTo, $template, $params, $logCtx);

        if ($sent['ok']) {
            $this->logRow($li, 'slaughtered', $template, $loggedPhone, 'sent', null, null,
                $sent['wa_message_id'] ?? null, $sent['conversation_id'] ?? null);
            return ['ok' => true, 'reason' => 'sent', 'message' => 'Slaughter message sent.', 'phone' => $sendTo];
        }
        $this->logRow($li, 'slaughtered', $template, $loggedPhone, 'failed', $sent['error'] ?? 'send_failed', null);
        return ['ok' => false, 'reason' => 'send_failed', 'message' => 'Send failed: ' . ($sent['error'] ?? 'unknown'), 'phone' => $sendTo];
    }

    /**
     * Send the OFD template for a single line item NOW. Mirrors
     * sendSlaughterForLineItem; differs only in which template lane
     * runs (delivery vs self-collection) and the parameter shape.
     *
     * Lane selection (same as auto worker):
     *   - delivery_type contains self/collect/pickup → self-collection
     *     template, 2 params (name, order#).
     *   - otherwise → delivery template, 3 params with ETA range
     *     formatted via formatTenMinRange(), or the booking slot
     *     string when no Google ETA is available (missing-coords case).
     *
     * Force bypasses dedupe ONLY. master_off / template_missing /
     * no_phone still block the send. ETA-window gate is NOT enforced
     * here — the whole point of this method is to override the gate
     * when the manager has explicit reason to fire early.
     */
    public function sendOfdForLineItem(int $lineItemId, bool $force = false): array
    {
        if (!$this->cfg['master_enabled']) {
            return ['ok' => false, 'reason' => 'master_off', 'message' => 'Auto WhatsApp master switch is OFF.'];
        }
        if (!$this->cfg['ofd_enabled']) {
            return ['ok' => false, 'reason' => 'ofd_disabled', 'message' => 'OFD trigger is disabled in settings.'];
        }
        $li = $this->fetchLineItemForSend($lineItemId);
        if (!$li) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => 'Line item not found.'];
        }

        $isSelfCollection = $this->isSelfCollection($li->qurbani_delivery_type);
        $template = $isSelfCollection
            ? trim((string) $this->cfg['ofd_self_collection_template'])
            : trim((string) $this->cfg['ofd_template']);
        if ($template === '') {
            return ['ok' => false, 'reason' => 'template_missing',
                'message' => $isSelfCollection ? 'Self-collection template not configured.' : 'Delivery template not configured.'];
        }

        if (!$force && $this->isAlreadySent($lineItemId, 'ofd')) {
            return ['ok' => false, 'reason' => 'already_sent', 'message' => 'OFD message already sent. Use Force to override.'];
        }
        // Hard cap protects even force=true.
        if ($this->hasReachedHardCap($lineItemId, 'ofd')) {
            return [
                'ok' => false,
                'reason' => 'hard_cap_reached',
                'message' => 'This line item has hit the lifetime send cap of ' . self::HARD_SEND_CAP . ' attempts for the OFD trigger. To re-test, manually DELETE rows from t_ops_qurbani_wa_log WHERE line_item_id = ' . $lineItemId . " AND trigger_event = 'ofd'.",
            ];
        }

        $etaUsed = null;
        $params  = null;
        if ($isSelfCollection) {
            $params = $this->buildOfdParams($li, null, true);
        } else {
            $eta = $li->qurbani_estimated_delivery_at ?: null;
            $timeText = '';
            if ($eta) {
                $etaCarbon = \Carbon\Carbon::parse($eta);
                $timeText  = $this->formatTenMinRange($etaCarbon);
                $etaUsed   = $etaCarbon->toDateTimeString();
            } else {
                $timeText = trim((string) ($li->qurbani_slot ?? ''));
            }
            $params = $this->buildOfdParams($li, $timeText, false);
        }

        $phone = $this->resolveCustomerPhone($li);
        if ($phone === null) {
            $this->logRow($li, 'ofd', $template, null, 'skipped', 'no_phone', $etaUsed);
            return ['ok' => false, 'reason' => 'no_phone', 'message' => 'No customer phone on file (and no test_phone configured).'];
        }
        [$sendTo, $loggedPhone] = $this->resolveSendTarget($phone);

        $logCtx = "Qurbani " . ($isSelfCollection ? 'collect' : 'OFD') . " (manual): " . $this->orderNumber($li);
        $sent = $this->sendTemplate($sendTo, $template, $params, $logCtx);

        if ($sent['ok']) {
            $this->logRow($li, 'ofd', $template, $loggedPhone, 'sent', null, $etaUsed,
                $sent['wa_message_id'] ?? null, $sent['conversation_id'] ?? null);
            return ['ok' => true, 'reason' => 'sent', 'message' => 'OFD message sent.', 'phone' => $sendTo];
        }
        $this->logRow($li, 'ofd', $template, $loggedPhone, 'failed', $sent['error'] ?? 'send_failed', $etaUsed);
        return ['ok' => false, 'reason' => 'send_failed', 'message' => 'Send failed: ' . ($sent['error'] ?? 'unknown'), 'phone' => $sendTo];
    }

    /**
     * Pull a single line item with the same shape the batch fetch
     * uses (joined order + customer columns). Keeps the manual-send
     * methods drop-in compatible with the existing helper functions
     * (customerFirstName, resolveCustomerPhone, orderNumber, etc.)
     * which all expect that joined shape.
     */
    private function fetchLineItemForSend(int $lineItemId)
    {
        return DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->leftJoin('t_crm_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('li.id', $lineItemId)
            ->select(
                'li.id as line_item_id',
                'li.order_id',
                'li.qurbani_slaughtered_at',
                'li.qurbani_estimated_delivery_at',
                'li.qurbani_slot',
                'li.qurbani_delivery_type',
                'li.qurbani_dispatched_at',
                // May-2026 — needed by buildOfdParams() / resolveRiderInfo()
                // when the operator selects the with_rider OFD variant.
                // Cheap to always select.
                'li.qurbani_assigned_rider_user_id',
                'o.order_number',
                'o.customer_id',
                'o.address_first_name',
                'o.address_last_name',
                'o.address_phone',
                'c.first_name as customer_first_name',
                'c.last_name as customer_last_name',
                'c.phone as customer_phone'
            )
            ->first();
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Hard lifetime cap: regardless of status (sent/failed/skipped) or
     * mode (test/production), no single (line_item_id, trigger_event)
     * combination may have more than HARD_SEND_CAP attempts written
     * to t_ops_qurbani_wa_log. This is defence-in-depth — it ONLY
     * kicks in if every other dedupe layer fails (a bug, race
     * condition, operator hammering the force button, log rows
     * deleted out from under us, etc).
     *
     * Why 10? Normal flow writes 1 row (sent). Even a pathological
     * "send fails, retry, succeeds, force-resend twice" flow tops out
     * around 5. 10 leaves headroom for legitimate manual recovery
     * while making spam mathematically impossible.
     *
     * If a future legitimate use-case ever needs >10 attempts, the
     * operator deletes log rows for that line item — same escape
     * hatch as a force re-test.
     */
    private const HARD_SEND_CAP = 10;

    private function hasReachedHardCap(int $lineItemId, string $trigger): bool
    {
        return DB::table('t_ops_qurbani_wa_log')
            ->where('line_item_id', $lineItemId)
            ->where('trigger_event', $trigger)
            ->count() >= self::HARD_SEND_CAP;
    }

    private function isAlreadySent(int $lineItemId, string $trigger): bool
    {
        // ALL-TIME dedupe — once a customer has been messaged for a
        // given trigger on a given line item, that's it. No retries,
        // no relaxation, regardless of whether test_phone is set.
        //
        // May-2026 root cause: prior implementation had a 2-min
        // "test mode" relaxation here (and in the two SQL fetch
        // gates). It was meant to enable easy re-testing of a
        // line item in dev, but in production with test_phone set
        // as a redirect-safety mechanism, it caused the same
        // 'qurbani_start' template to fire every ~2 min for 6 hours
        // after slaughter. See the long comment in
        // fetchSlaughterCandidates() for the full timeline.
        //
        // For intentional re-tests:
        //   1. Performance screen → row action 🔪/🛵 buttons with
        //      force=true (already supported via sendSlaughterForLineItem
        //      and sendOfdForLineItem below), OR
        //   2. DELETE FROM t_ops_qurbani_wa_log
        //      WHERE line_item_id = ? AND trigger_event = ?
        return DB::table('t_ops_qurbani_wa_log')
            ->where('line_item_id', $lineItemId)
            ->where('trigger_event', $trigger)
            ->where('status', 'sent')
            ->exists();
    }

    /** True when a developer/operator has set a test phone — the worker
     *  treats this as "redirect-to-me-for-safety" mode. The ONLY effect
     *  is that outbound messages are sent to the test phone instead of
     *  the customer's phone (resolveCustomerPhone + resolveSendTarget).
     *  Dedupe, rate limits, and time gates are NOT affected — the worker
     *  is otherwise identical to production behaviour, so what you see
     *  on the test phone is a faithful preview of what a customer would
     *  see.
     *
     *  History: prior to May-2026 this flag also relaxed dedupe to a
     *  2-minute rolling window. That feature was removed because in
     *  production it caused every slaughtered line item to be
     *  re-messaged every ~2 minutes for 6 hours. See isAlreadySent. */
    private function isTestMode(): bool
    {
        return trim((string) ($this->cfg['test_phone'] ?? '')) !== '';
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
        // May-2026 — TEST MODE fallback: when the test phone is
        // configured and the order/customer record has no phone on
        // file, return the test phone itself so the operator can
        // still verify the flow on half-populated dev orders.
        // Audit log records 'no_customer_phone' as the resolved
        // identity so we know it wasn't a real customer number.
        if ($this->isTestMode()) {
            $test = $this->whatsapp->formatPhone(trim((string) $this->cfg['test_phone']));
            if ($test !== '') {
                return 'TEST:' . $test;
            }
        }
        return null;
    }

    private function resolveSendTarget(string $customerPhone): array
    {
        // Special marker emitted by resolveCustomerPhone() when the
        // customer record had no phone but test mode is on — strip
        // the marker, send to the test phone, and log the surrogate
        // so the audit trail makes the situation obvious.
        if (str_starts_with($customerPhone, 'TEST:')) {
            $sendTo = substr($customerPhone, 5);
            return [$sendTo, 'no_customer_phone'];
        }
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

    /**
     * May-2026 — Resolve the rider's display name + contact for the
     * 'with_rider' (5-variable) OFD template. Returns ['name', 'contact']
     * with soft fallbacks so the send NEVER fails just because a rider
     * row is incomplete (a failed delivery message is worse than one
     * that says "your rider" / "(contact pending)").
     *
     *   • name    — t_sys_user.fullname for $li->qurbani_assigned_rider_user_id;
     *               falls back to "your rider" when unassigned / not found
     *   • contact — qurbani_rider_meta config map keyed by user_id;
     *               falls back to "(contact pending)" when not set
     *
     * The contact map is cached on the instance because processOfdTrigger
     * loops through many candidates and each one calls this helper. One
     * cheap JSON decode on first hit, zero on subsequent. The rider
     * name lookup is a single per-call query — acceptable for the
     * worker's <100 rows/min cap and avoids forcing every fetch
     * helper to add a join.
     */
    private array $_riderMetaCache = [];
    private bool  $_riderMetaCacheLoaded = false;

    private function loadRiderMetaCache(): array
    {
        if ($this->_riderMetaCacheLoaded) return $this->_riderMetaCache;
        $raw = (string) ConfigModel::get('qurbani_rider_meta', '{}');
        $map = json_decode($raw, true);
        $this->_riderMetaCache = is_array($map) ? $map : [];
        $this->_riderMetaCacheLoaded = true;
        return $this->_riderMetaCache;
    }

    private function resolveRiderInfo($li): array
    {
        $riderId = (int) ($li->qurbani_assigned_rider_user_id ?? 0);
        $name    = 'your rider';
        $contact = '(contact pending)';
        if ($riderId > 0) {
            $row = DB::table('t_sys_user')->where('id', $riderId)->first(['fullname']);
            if ($row && trim((string) $row->fullname) !== '') {
                $name = trim((string) $row->fullname);
            }
            $meta = $this->loadRiderMetaCache();
            $entry = $meta[(string) $riderId] ?? null;
            if (is_array($entry)) {
                $c = trim((string) ($entry['contact'] ?? ''));
                if ($c !== '') {
                    $contact = $c;
                }
            }
        }
        return ['name' => $name, 'contact' => $contact];
    }

    /**
     * Build the template body params array for an OFD send, branching
     * on (a) whether the item is self-collection (2 vars), and (b) the
     * configured template variant for the delivery path (standard 3
     * vars vs with_rider 5 vars). Centralised so processOfdTrigger,
     * sendOfdForLineItem, and sendDelayUpdate all stay in sync — a
     * mismatch between them would either truncate the rider info or
     * cause WhatsApp to reject the send with a param-count error.
     */
    private function buildOfdParams($li, ?string $timeText, bool $isSelfCollection): array
    {
        if ($isSelfCollection) {
            return [
                $this->customerFirstName($li),
                $this->orderNumber($li),
            ];
        }
        $base = [
            $this->customerFirstName($li),
            $this->orderNumber($li),
            (string) ($timeText ?? ''),
        ];
        $variant = (string) ($this->cfg['ofd_template_variant'] ?? 'standard');
        if ($variant === 'with_rider') {
            $rider = $this->resolveRiderInfo($li);
            $base[] = $rider['name'];
            $base[] = $rider['contact'];
        }
        return $base;
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
            // May-2026 — try EVERY approved language for this template
            // (in priority order), then fall back to ['en', 'en_US',
            // 'ur', 'en_GB'] if t_wa_templates doesn't have the
            // template at all. This survives the common case where
            // Meta approves a template under 'en_US' but t_wa_templates
            // isn't synced — without this we'd fail forever with
            // "template name (X) does not exist in en". Cache the
            // first language that works for 1 hour so we don't retry
            // dead langs on every cron tick.
            $cacheKey = 'qurbani_wa_lang:' . $templateName;
            $cachedLang = \Cache::get($cacheKey);

            $approvedLangs = DB::table('t_wa_templates')
                ->where('name', $templateName)
                ->where('status', 'approved')
                ->pluck('language')
                ->map(fn($l) => (string) $l)
                ->filter()
                ->values()
                ->all();
            $fallbackLangs = ['en', 'en_US', 'ur', 'en_GB'];
            $langs = array_values(array_unique(array_filter(array_merge(
                $cachedLang ? [$cachedLang] : [],
                $approvedLangs,
                $fallbackLangs
            ))));
            if (empty($langs)) {
                $langs = ['en'];
            }

            if ($this->isTestMode()) {
                Log::info('QurbaniWaAutoSender: 🧪 test-mode send', [
                    'template' => $templateName,
                    'lang_try' => $langs,
                    'to'       => $to,
                    'context'  => $logContent,
                ]);
            }

            $resp = ['success' => false, 'error' => 'no_attempt'];
            $lastDoesNotExist = null;
            foreach ($langs as $lang) {
                $resp = $this->whatsapp->sendTemplateMessage($to, $templateName, $lang, $bodyParams);
                if ($resp['success'] ?? false) {
                    // Remember the language that worked so the next
                    // 100 cron ticks skip the lookup + failing langs.
                    \Cache::put($cacheKey, $lang, now()->addHour());
                    break;
                }
                $errMsg = (string) ($resp['error'] ?? '');
                $isLangErr = stripos($errMsg, 'does not exist') !== false || stripos($errMsg, '132001') !== false;
                if ($isLangErr) {
                    $lastDoesNotExist = $errMsg;
                    continue; // try the next language
                }
                // A non-language error (auth, recipient block, rate
                // limit, etc.) — no point in trying other langs.
                break;
            }

            if (!($resp['success'] ?? false)) {
                $errOut = $lastDoesNotExist
                    ? ('template_not_found_in_any_lang: ' . $lastDoesNotExist . ' · tried=' . implode(',', $langs))
                    : ((string) ($resp['error'] ?? 'send_failed'));
                return ['ok' => false, 'error' => substr($errOut, 0, 240)];
            }
            $lang = \Cache::get($cacheKey, $langs[0]);

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
            // May-2026 — Which delivery-template variant to render:
            //   • 'standard'   → 3 vars: {{1}} name, {{2}} order, {{3}} time
            //   • 'with_rider' → 5 vars: {{1}} name, {{2}} order, {{3}} time,
            //                             {{4}} rider name, {{5}} rider contact
            // The Meta-approved template `qurbani_ofd_rider` ships with
            // the 5-var body. Picking 'with_rider' here switches the
            // sender to pass those two extra params; rider name comes
            // from t_sys_user.fullname of qurbani_assigned_rider_user_id
            // and contact from the new qurbani_rider_meta config (saved
            // per-rider in Qurbani Settings → 🏍️ Qurbani Riders).
            // Defaults to 'standard' so existing installs unaffected.
            'ofd_template_variant'        => (string) $get('qurbani_wa_ofd_template_variant', 'standard'),

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
