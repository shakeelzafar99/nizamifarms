<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\WhatsAppService;
use App\Services\CampaignFilterService;

/**
 * The one place a campaign WhatsApp message is ever sent from.
 *
 * Both entry points — the web page (CampaignWebController) and the mobile app
 * (API\RiderController) — and the background worker (campaigns:send-process)
 * all call sendBatch(). That is deliberate: send safety rules are worthless if
 * one surface can bypass them.
 *
 * What this guarantees:
 *
 *  1. ONE sender per campaign at a time. A cache lock plus a per-row atomic
 *     claim (pending -> sending) means two operators, two browser tabs, or the
 *     browser racing the background worker can never message the same person
 *     twice. Whoever's UPDATE reports 1 affected row owns that recipient.
 *
 *  2. A SESSION CAP. Nothing ever "sends to everyone" by accident — the caller
 *     always passes an explicit limit, which the operator confirms in the send
 *     dialog. Batches beyond the cap simply stay Pending for the next session.
 *
 *  3. A DAILY CAP tied to the WhatsApp messaging tier (t_fin_config
 *     wa_daily_send_cap, currently 2000). When the remaining quota runs out the
 *     run STOPS CLEANLY and the untouched recipients stay Pending — they are
 *     never burned as "failed", which is what would happen without this guard.
 *
 *  4. ERROR CLASSIFICATION. Meta's error codes mean very different things and
 *     must not be treated alike:
 *       - throttling      -> back off, retry once, then stop the run (stay Pending)
 *       - tier/cap hit    -> stop the run (stay Pending)
 *       - auth/token      -> stop everything (nothing is wrong with the recipients)
 *       - bad recipient   -> mark that ONE row failed, keep going
 *     Plus a circuit breaker: N consecutive failures aborts the run rather than
 *     grinding through thousands of doomed sends.
 *
 *  5. A TIME BUDGET. sendBatch() returns before PHP's execution limit; the
 *     caller (browser or cron) simply calls again. No half-finished requests.
 */
class CampaignSendService
{
    /** Rows claimed but never finished (crash / deploy mid-send) are recovered after this long. */
    public const STUCK_CLAIM_MINUTES = 15;

    /** Consecutive per-recipient failures that abort a run. */
    public const CIRCUIT_BREAKER = 8;

    /** Default wall-clock budget for one sendBatch() call. */
    public const DEFAULT_TIME_BUDGET_MS = 20000;

    /** Lock lifetime — longer than any single batch. */
    private const LOCK_SECONDS = 180;

    /**
     * Meta throttling. The message was NOT sent; trying again later works.
     * 130429 rate limit hit · 131056 pair rate limit · 80007 business rate limit
     * · 4 application request limit.
     */
    private const ERR_THROTTLE = [4, 80007, 130429, 131056];

    /**
     * Messaging-tier / quality caps. The number itself is limited today; the
     * recipients are fine. Stop and resume tomorrow.
     * 131048 spam rate limit · 131045 (cert) · 368 temporarily blocked policy.
     */
    private const ERR_CAP = [368, 131045, 131048];

    /**
     * Credentials / configuration. Every subsequent send would fail the same
     * way, so abort immediately and surface it loudly.
     * 190 expired token · 0/2 unknown auth · 10 & 200 permission · 133010 not registered.
     */
    private const ERR_AUTH = [0, 2, 10, 190, 200, 133010];

    /**
     * This recipient specifically cannot receive this message. Marking the row
     * failed is correct and retrying is pointless until something changes.
     * 131026 undeliverable (no WhatsApp / can't receive) · 131047 re-engagement
     * · 132xxx template problems · 33 invalid parameter.
     */
    private const ERR_PERMANENT = [33, 131026, 131047, 132000, 132001, 132005, 132007, 132012, 132015, 132016, 132068, 132069];

    /**
     * Media problems (upload/download/expired id). Not fatal to the run, but the
     * cached header media id must be dropped so the next send re-uploads rather
     * than replaying a dead id.
     * 131052 download failed · 131053 upload failed · 130472 (media) · 131051 unsupported type.
     */
    private const ERR_MEDIA = [130472, 131051, 131052, 131053];

    protected CampaignFilterService $filters;

    public function __construct(CampaignFilterService $filters)
    {
        $this->filters = $filters;
    }

    // =====================================================================
    // Settings
    // =====================================================================

    public function config(string $key, $default = null)
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $v = DB::table('t_fin_config')->where('config_key', $key)->value('config_value');
            return $cache[$key] = ($v === null ? $default : $v);
        } catch (\Throwable $e) {
            return $cache[$key] = $default;
        }
    }

    public function dailyCap(): int
    {
        return max(0, (int) $this->config('wa_daily_send_cap', 2000));
    }

    public function defaultSessionLimit(): int
    {
        return max(1, (int) $this->config('wa_campaign_session_default', 100));
    }

    public function paceMicros(): int
    {
        return max(0, (int) $this->config('wa_campaign_pace_ms', 250)) * 1000;
    }

    public function backgroundEnabled(): bool
    {
        return (int) $this->config('wa_campaign_auto_send', 1) === 1;
    }

    /**
     * How much of the WhatsApp messaging tier is left in the rolling 24h.
     *
     * Counts DISTINCT conversations that received an outbound *template* in the
     * last 24 hours — the closest available proxy for Meta's own metric
     * ("unique customers you started a conversation with"). Deliberately counts
     * ALL template traffic, not just campaigns: a day of heavy invoice sending
     * eats the same tier, and pretending otherwise would let a campaign run
     * straight into the wall.
     */
    public function quota(): array
    {
        $cap = $this->dailyCap();
        $used = 0;
        try {
            $used = (int) DB::table('t_wa_messages')
                ->where('direction', 'outbound')
                ->whereNotNull('template_name')
                ->where('template_name', '!=', '')
                ->where('created_at', '>=', now()->subDay())
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'failed');
                })
                ->distinct()
                ->count('conversation_id');
        } catch (\Throwable $e) {
            Log::warning('Campaign quota lookup failed', ['error' => $e->getMessage()]);
        }

        return [
            'cap'       => $cap,
            'used'      => $used,
            'remaining' => $cap > 0 ? max(0, $cap - $used) : PHP_INT_MAX,
            'unlimited' => $cap === 0,
            'window'    => 'rolling 24h',
        ];
    }

    // =====================================================================
    // Eligibility
    // =====================================================================

    /**
     * Recipients this campaign could still message right now.
     * 'sending' rows are counted as pending — they're either in flight or about
     * to be recovered, and either way they are not done.
     */
    public function eligibleCount(int $campaignId, bool $includeFailed = false): int
    {
        $statuses = $includeFailed ? ['pending', 'sending', 'failed'] : ['pending', 'sending'];
        return (int) DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $campaignId)
            ->whereIn('status', $statuses)
            ->count();
    }

    /**
     * Release rows a previous sender claimed but never resolved (crash, deploy,
     * killed request). Without this a stuck claim would silently shrink the
     * campaign forever.
     */
    public function recoverStuckClaims(?int $campaignId = null): int
    {
        $q = DB::table('t_crm_campaign_customers')
            ->where('status', 'sending')
            ->where(function ($w) {
                $w->whereNull('claimed_at')
                  ->orWhere('claimed_at', '<', now()->subMinutes(self::STUCK_CLAIM_MINUTES));
            });
        if ($campaignId) $q->where('campaign_id', $campaignId);

        $n = $q->update(['status' => 'pending', 'claimed_at' => null]);
        if ($n > 0) {
            Log::info('Campaign send: recovered stuck claims', ['campaign_id' => $campaignId, 'rows' => $n]);
        }
        return $n;
    }

    // =====================================================================
    // Run bookkeeping
    // =====================================================================

    public function startRun(int $campaignId, int $target, string $mode, ?int $userId): int
    {
        return (int) DB::table('t_crm_campaign_send_runs')->insertGetId([
            'campaign_id' => $campaignId,
            'mode'        => $mode,
            'target_count'=> $target,
            'started_by'  => $userId,
            'started_at'  => now(),
        ]);
    }

    public function finishRun(int $runId, string $reason): void
    {
        DB::table('t_crm_campaign_send_runs')
            ->where('id', $runId)
            ->whereNull('finished_at')
            ->update(['finished_at' => now(), 'stop_reason' => $reason]);
    }

    /** Progress of a run so far (what the operator sees as "sent 45 of 100"). */
    public function runProgress(int $runId): ?object
    {
        return DB::table('t_crm_campaign_send_runs')->where('id', $runId)->first();
    }

    // =====================================================================
    // The send
    // =====================================================================

    /**
     * Send one batch. Safe to call repeatedly — it always picks up where the
     * last call stopped.
     *
     * @param array $opts
     *   limit           int    max recipients THIS call may message (required, > 0)
     *   customer_ids    ?array restrict to this explicit selection (web "send selected")
     *   include_failed  bool   also retry rows currently in 'failed'
     *   mode            string 'manual' | 'background'
     *   user_id         ?int
     *   run_id          ?int   continue an existing session run
     *   time_budget_ms  int
     *
     * @return array sent/failed/excluded/attempted/stop_reason/remaining/quota/run_id
     */
    public function sendBatch(int $campaignId, array $opts = []): array
    {
        $limit         = max(0, (int) ($opts['limit'] ?? 0));
        $customerIds   = $opts['customer_ids'] ?? null;
        $includeFailed = (bool) ($opts['include_failed'] ?? false);
        $mode          = ($opts['mode'] ?? 'manual') === 'background' ? 'background' : 'manual';
        $userId        = $opts['user_id'] ?? null;
        $runId         = $opts['run_id'] ?? null;
        $budgetMs      = (int) ($opts['time_budget_ms'] ?? self::DEFAULT_TIME_BUDGET_MS);

        $result = [
            'sent' => 0, 'failed' => 0, 'excluded' => 0, 'attempted' => 0,
            'errors' => [], 'stop_reason' => null, 'run_id' => $runId,
        ];

        $campaign = DB::table('t_crm_campaigns')->where('id', $campaignId)->first();
        if (!$campaign) {
            return $result + ['ok' => false, 'message' => 'Campaign not found'];
        }
        if ($campaign->status !== 'active') {
            return $result + ['ok' => false, 'message' => 'Campaign has ended'];
        }
        $templateName = (string) ($campaign->wa_template_name ?? '');
        if ($templateName === '') {
            return $result + ['ok' => false, 'message' => 'No WhatsApp template configured'];
        }
        if ($limit <= 0) {
            return $result + ['ok' => false, 'message' => 'No send limit given'];
        }

        // ---- One sender per campaign -------------------------------------
        $lock = Cache::lock('campaign_send_' . $campaignId, self::LOCK_SECONDS);
        if (!$lock->get()) {
            return $result + [
                'ok' => false,
                'busy' => true,
                'message' => 'This campaign is already being sent right now (another user, tab, or the background sender). Nothing was sent twice.',
            ];
        }

        try {
            $this->recoverStuckClaims($campaignId);

            // ---- Daily tier cap ------------------------------------------
            $quota = $this->quota();
            if (!$quota['unlimited'] && $quota['remaining'] <= 0) {
                $result['stop_reason'] = 'daily_cap';
                $result['quota'] = $quota;
                return $result + [
                    'ok' => true,
                    'remaining' => $this->eligibleCount($campaignId, $includeFailed),
                    'message' => 'Daily WhatsApp limit reached — nobody was messaged. Everyone still shows as Pending; resume when the limit resets.',
                ];
            }
            $limit = $quota['unlimited'] ? $limit : min($limit, $quota['remaining']);

            // Continuing an existing session: the operator asked for N in TOTAL,
            // not N per HTTP call. Without this cap, a session of 100 that took
            // four ticks to finish would send 400. The run row is the single
            // source of truth for how much of the session is left.
            if ($runId) {
                $run = $this->runProgress((int) $runId);
                if ($run) {
                    $sessionLeft = max(0, (int) $run->target_count - (int) $run->attempted);
                    if ($sessionLeft <= 0) {
                        $result['stop_reason'] = 'target_reached';
                        $result['quota'] = $quota;
                        return $result + [
                            'ok' => true,
                            'remaining' => $this->eligibleCount($campaignId, $includeFailed),
                            'message' => "This session's batch is already complete.",
                        ];
                    }
                    $limit = min($limit, $sessionLeft);
                }
            }

            $eligibleStatuses = $includeFailed ? ['pending', 'failed'] : ['pending'];

            // ---- Candidate rows, in the campaign's own sort order ---------
            $candidates = $this->pickCandidates($campaign, $limit, $eligibleStatuses, $customerIds);
            if ($candidates->isEmpty()) {
                $result['stop_reason'] = 'no_eligible';
                $result['quota'] = $quota;
                return $result + ['ok' => true, 'remaining' => 0, 'message' => 'No one left to send to.'];
            }

            // ---- Send-time dedup guard (unchanged behaviour) --------------
            $dedupWindow = (int) ($campaign->dedup_window_days ?? 0);
            if ($dedupWindow > 0) {
                $ids = $candidates->pluck('customer_id')->map(fn($v) => (int) $v)->all();
                $excludedIds = $this->filters->customersRecentlySentTemplate($ids, $templateName, $dedupWindow);
                if (!empty($excludedIds)) {
                    $reason = 'Excluded: already received template "' . $templateName . '" in last '
                        . $dedupWindow . ' day' . ($dedupWindow === 1 ? '' : 's');
                    DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $campaignId)
                        ->whereIn('customer_id', $excludedIds)
                        ->whereIn('status', $eligibleStatuses)
                        ->update(['status' => 'skipped', 'error_message' => $reason, 'sent_at' => null]);

                    $set = array_flip($excludedIds);
                    $candidates = $candidates->reject(fn($c) => isset($set[(int) $c->customer_id]))->values();
                    $result['excluded'] = count($excludedIds);
                }
            }

            if ($candidates->isEmpty()) {
                $result['stop_reason'] = 'all_excluded';
                $result['quota'] = $quota;
                return $result + [
                    'ok' => true,
                    'remaining' => $this->eligibleCount($campaignId, $includeFailed),
                    'message' => 'Everyone in this batch had already received the template recently — moved to Excluded, nothing sent.',
                ];
            }

            // ---- Session run ---------------------------------------------
            if (!$runId) {
                $runId = $this->startRun($campaignId, $limit, $mode, $userId);
                $result['run_id'] = $runId;
            }
            DB::table('t_crm_campaigns')->where('id', $campaignId)->update([
                'active_run_id' => $runId,
                'last_send_at'  => now(),
                'updated_at'    => now(),
            ]);

            $whatsapp = app(WhatsAppService::class);
            $expectedVarCount = $this->templateVariableCount($templateName);
            $language = $campaign->wa_template_language ?: 'en';
            $pace = $this->paceMicros();
            $startedAt = microtime(true);
            $consecutiveFailures = 0;
            $stopReason = null;

            Log::info('Campaign send: batch start', [
                'campaign_id' => $campaignId, 'run_id' => $runId, 'mode' => $mode,
                'template' => $templateName, 'candidates' => $candidates->count(), 'limit' => $limit,
            ]);

            foreach ($candidates as $i => $customer) {
                // Time budget — hand control back before PHP kills us.
                if ((microtime(true) - $startedAt) * 1000 > $budgetMs) {
                    $stopReason = 'time_budget';
                    break;
                }

                // Atomic claim. If someone else already took this row, skip it.
                $claimed = DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $campaignId)
                    ->where('customer_id', $customer->customer_id)
                    ->whereIn('status', $eligibleStatuses)
                    ->update(['status' => 'sending', 'claimed_at' => now()]);
                if ($claimed !== 1) {
                    continue;
                }

                if ($i > 0 && $pace > 0) {
                    usleep($pace);
                }

                $outcome = $this->sendOne($whatsapp, $campaign, $customer, $templateName, $language, $expectedVarCount, $opts['body_params'] ?? null, $userId);
                $result['attempted']++;

                if ($outcome['ok']) {
                    $result['sent']++;
                    $consecutiveFailures = 0;
                    continue;
                }

                // --- error handling -------------------------------------
                $class = $outcome['class'];

                if ($class === 'throttle') {
                    // Not the recipient's fault — put them back and slow down.
                    $this->releaseRow($campaignId, $customer->customer_id, $outcome['prev_status']);
                    usleep(2_000_000);
                    $retry = $this->claimAndSend($whatsapp, $campaign, $customer, $templateName, $language, $expectedVarCount, $opts['body_params'] ?? null, $userId, $eligibleStatuses);
                    if ($retry === true) {
                        $result['sent']++;
                        $result['attempted']++;
                        $consecutiveFailures = 0;
                        continue;
                    }
                    $stopReason = 'rate_limited';
                    $result['errors'][] = ['error' => 'WhatsApp is rate-limiting us: ' . $outcome['error']];
                    break;
                }

                if ($class === 'cap') {
                    $this->releaseRow($campaignId, $customer->customer_id, $outcome['prev_status']);
                    $stopReason = 'daily_cap';
                    $result['errors'][] = ['error' => 'WhatsApp messaging limit reached: ' . $outcome['error']];
                    break;
                }

                if ($class === 'auth') {
                    $this->releaseRow($campaignId, $customer->customer_id, $outcome['prev_status']);
                    $stopReason = 'auth_error';
                    $result['errors'][] = ['error' => 'WhatsApp account/token problem: ' . $outcome['error']];
                    Log::error('Campaign send aborted — WhatsApp auth error', [
                        'campaign_id' => $campaignId, 'error' => $outcome['error'],
                    ]);
                    break;
                }

                // Permanent or unknown -> this recipient failed, keep going.
                $result['failed']++;
                $result['errors'][] = [
                    'customer_id' => $customer->customer_id,
                    'name' => $outcome['name'] ?? '',
                    'error' => $outcome['error'],
                ];
                $consecutiveFailures = $class === 'permanent' ? 0 : $consecutiveFailures + 1;

                if ($consecutiveFailures >= self::CIRCUIT_BREAKER) {
                    $stopReason = 'too_many_failures';
                    break;
                }
            }

            // ---- Wrap up --------------------------------------------------
            $remaining = $this->eligibleCount($campaignId, $includeFailed);
            if ($stopReason === null) {
                $stopReason = $remaining > 0 ? 'target_reached' : 'completed';
            }
            $result['stop_reason'] = $stopReason;
            $result['quota'] = $this->quota();
            $result['remaining'] = $remaining;

            DB::table('t_crm_campaign_send_runs')->where('id', $runId)->update([
                'attempted'      => DB::raw('attempted + ' . (int) $result['attempted']),
                'sent_count'     => DB::raw('sent_count + ' . (int) $result['sent']),
                'failed_count'   => DB::raw('failed_count + ' . (int) $result['failed']),
                'excluded_count' => DB::raw('excluded_count + ' . (int) $result['excluded']),
                'stop_reason'    => $stopReason,
            ]);

            // Keep the campaign's denormalised counter honest.
            if ($result['sent'] > 0) {
                DB::table('t_crm_campaigns')->where('id', $campaignId)->update([
                    'sent_count' => DB::raw('(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = ' . (int) $campaignId . " AND status = 'sent')"),
                    'updated_at' => now(),
                ]);
            }

            Log::info('Campaign send: batch end', [
                'campaign_id' => $campaignId, 'run_id' => $runId,
                'sent' => $result['sent'], 'failed' => $result['failed'],
                'excluded' => $result['excluded'], 'stop_reason' => $stopReason,
                'remaining' => $remaining,
            ]);

            $result['ok'] = true;
            $result['message'] = $this->explain($stopReason, $result);
            return $result;

        } finally {
            optional($lock)->release();
        }
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /**
     * Next N recipients, ordered the way the campaign was built (so "send the
     * first 100" means the first 100 the operator saw in the list, not a
     * random slice).
     */
    protected function pickCandidates($campaign, int $limit, array $statuses, ?array $customerIds)
    {
        $q = DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
            ->where('cc.campaign_id', $campaign->id)
            ->whereIn('cc.status', $statuses)
            ->select('cc.customer_id', 'cc.status as prev_status', 'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized');

        if (is_array($customerIds) && !empty($customerIds)) {
            $q->whereIn('cc.customer_id', $customerIds);
        }

        // Send in the SAME order the operator previewed the list in, so
        // "send the first 100" means the first 100 they actually saw.
        $filters = json_decode($campaign->filters_json ?? '{}', true) ?: [];
        [$sortBy, $sortDir] = $this->filters->normalizeSort($filters);
        $this->filters->applySort($q, $sortBy, $sortDir, 'c');
        $q->orderBy('cc.id', 'asc');

        return $q->limit($limit)->get();
    }

    protected function templateVariableCount(string $templateName): ?int
    {
        $row = DB::table('t_wa_templates')->where('name', $templateName)->first();
        return $row ? (int) $row->variable_count : null;
    }

    /** Put a claimed row back where it came from (send never happened). */
    protected function releaseRow(int $campaignId, $customerId, ?string $prevStatus): void
    {
        DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $campaignId)
            ->where('customer_id', $customerId)
            ->where('status', 'sending')
            ->update(['status' => $prevStatus ?: 'pending', 'claimed_at' => null]);
    }

    /** Re-claim + single retry used by the throttle path. */
    protected function claimAndSend($whatsapp, $campaign, $customer, $templateName, $language, $expectedVarCount, $bodyParams, $userId, array $statuses)
    {
        $claimed = DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $campaign->id)
            ->where('customer_id', $customer->customer_id)
            ->whereIn('status', $statuses)
            ->update(['status' => 'sending', 'claimed_at' => now()]);
        if ($claimed !== 1) return false;

        $out = $this->sendOne($whatsapp, $campaign, $customer, $templateName, $language, $expectedVarCount, $bodyParams, $userId);
        if ($out['ok']) return true;

        $this->releaseRow($campaign->id, $customer->customer_id, $out['prev_status']);
        return false;
    }

    /**
     * Send to exactly one recipient and record the outcome on their row.
     * Returns ['ok'=>bool, 'class'=>'throttle|cap|auth|permanent|unknown', 'error'=>string].
     */
    protected function sendOne($whatsapp, $campaign, $customer, string $templateName, string $language, ?int $expectedVarCount, $bodyParams, ?int $userId): array
    {
        $campaignId = $campaign->id;
        $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        $prevStatus = $customer->prev_status ?? 'pending';

        $phone = $customer->phone_normalized ?: $customer->phone;
        if (!$phone) {
            $this->markFailed($campaignId, $customer->customer_id, 'No phone number');
            return ['ok' => false, 'class' => 'permanent', 'error' => 'No phone number', 'name' => $name, 'prev_status' => $prevStatus];
        }

        try {
            $formattedPhone = $whatsapp->resolveDialPhone((string) $phone);

            $params = is_array($bodyParams) ? $bodyParams : ['{{customer_name}}'];
            $resolved = array_map(fn($p) => $p === '{{customer_name}}' ? $name : $p, $params);
            if ($expectedVarCount !== null) {
                if (count($resolved) > $expectedVarCount) {
                    $resolved = array_slice($resolved, 0, $expectedVarCount);
                }
                while (count($resolved) < $expectedVarCount) {
                    $resolved[] = $name;
                }
            }

            // A template with a media header must carry its image on EVERY send
            // (Meta's example.header_handle is only the approval sample), or the
            // message fails with 132012 (verified live). Resolved once per batch and
            // this is a cache hit for all but the first recipient. Returns []
            // for ordinary templates, so this is a no-op for everything else.
            $headerParams = $whatsapp->headerParamsForTemplate($templateName);

            $response = $whatsapp->sendTemplateMessage($formattedPhone, $templateName, $language, $resolved, $headerParams);

            if ($response['success'] ?? false) {
                $waMessageId = $response['messages'][0]['id'] ?? null;

                DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $campaignId)
                    ->where('customer_id', $customer->customer_id)
                    ->update([
                        'status'        => 'sent',
                        'sent_at'       => now(),
                        'sent_by'       => $userId,
                        'error_message' => null,
                        'claimed_at'    => null,
                        'wa_message_id' => $waMessageId,
                        // A retry of a previously-undelivered send starts a
                        // fresh delivery story.
                        'delivered_at'   => null,
                        'read_at'        => null,
                        'undelivered_at' => null,
                    ]);

                $conversation = $whatsapp->findOrCreateConversation($formattedPhone);
                if (!$conversation->customer_id) {
                    $conversation->update(['customer_id' => $customer->customer_id]);
                }
                $whatsapp->saveOutboundMessage(
                    $conversation->id, $response, 'template',
                    "Campaign: {$campaign->name}", $userId, $templateName, $resolved
                );

                return ['ok' => true, 'class' => null, 'error' => null, 'name' => $name, 'prev_status' => $prevStatus];
            }

            $error = (string) ($response['error'] ?? 'API send failed');
            $code  = $response['error_code'] ?? null;
            $class = $this->classify($code, $error);

            // A cached header media id can expire or be revoked before our TTL
            // runs out. Drop it so the next recipient re-uploads, instead of
            // replaying a dead id for the rest of the batch.
            if (in_array((int) $code, self::ERR_MEDIA, true) || str_contains(strtolower($error), 'media')) {
                $whatsapp->forgetTemplateHeaderMedia($templateName);
            }

            if (in_array($class, ['permanent', 'unknown'], true)) {
                $this->markFailed($campaignId, $customer->customer_id, $error);
            }

            return ['ok' => false, 'class' => $class, 'error' => $error, 'name' => $name, 'prev_status' => $prevStatus];

        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->markFailed($campaignId, $customer->customer_id, $error);
            return ['ok' => false, 'class' => 'unknown', 'error' => $error, 'name' => $name, 'prev_status' => $prevStatus];
        }
    }

    protected function markFailed(int $campaignId, $customerId, string $error): void
    {
        DB::table('t_crm_campaign_customers')
            ->where('campaign_id', $campaignId)
            ->where('customer_id', $customerId)
            ->update([
                'status'        => 'failed',
                'error_message' => mb_substr($error, 0, 500),
                'claimed_at'    => null,
            ]);
    }

    /**
     * Map a Meta error onto how we should react. Codes are authoritative; the
     * text match is only a fallback for responses that arrive without one.
     */
    public function classify($code, string $message): string
    {
        $code = is_numeric($code) ? (int) $code : null;

        if ($code !== null) {
            if (in_array($code, self::ERR_THROTTLE, true))  return 'throttle';
            if (in_array($code, self::ERR_CAP, true))       return 'cap';
            if (in_array($code, self::ERR_AUTH, true))      return 'auth';
            if (in_array($code, self::ERR_PERMANENT, true)) return 'permanent';
        }

        $m = strtolower($message);
        if (str_contains($m, 'rate limit') || str_contains($m, 'too many requests') || str_contains($m, 'throttl')) return 'throttle';
        if (str_contains($m, 'messaging limit') || str_contains($m, 'spam rate') || str_contains($m, 'business account limit')) return 'cap';
        if (str_contains($m, 'access token') || str_contains($m, 'oauth') || str_contains($m, 'permission')) return 'auth';

        return 'unknown';
    }

    /** Plain-English outcome for the operator — no error codes, no jargon. */
    protected function explain(string $reason, array $r): string
    {
        $sent = $r['sent'];
        $failed = $r['failed'];
        $base = "Sent {$sent}" . ($failed > 0 ? ", {$failed} failed" : '');

        return match ($reason) {
            'daily_cap'         => "{$base}. Stopped: the daily WhatsApp limit is used up. Everyone not yet messaged is still Pending — continue tomorrow.",
            'rate_limited'      => "{$base}. Stopped: WhatsApp is asking us to slow down. Nobody was lost — try again in a few minutes.",
            'auth_error'        => "{$base}. Stopped: there's a problem with the WhatsApp account or token. Nothing else will send until that's fixed.",
            'too_many_failures' => "{$base}. Stopped after " . self::CIRCUIT_BREAKER . " failures in a row — something looks wrong, so the rest were left untouched.",
            'time_budget'       => "{$base} so far — still working through the batch.",
            'target_reached'    => "{$base}. This session's batch is done.",
            'completed'         => "{$base}. Everyone in this campaign has now been messaged.",
            'no_eligible'       => 'No one left to send to.',
            'all_excluded'      => 'Everyone in this batch had already received the template recently — moved to Excluded, nothing sent.',
            default             => $base . '.',
        };
    }
}
