<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\Campaigns\CampaignSendService;

/**
 * Drains campaigns the operator started in BACKGROUND mode.
 *
 * Why this exists: a large campaign takes a long time to send safely (pacing +
 * the daily tier cap), and the browser-driven send only progresses while the tab
 * stays open. A 2,000-person campaign meant babysitting a laptop for the better
 * part of an hour, and closing the lid stopped it. Now the operator presses
 * "Send in background", walks away, and this worker finishes the session.
 *
 * Runs every minute (see routes/console.php). Each tick does a short, bounded
 * slice of work — never a long-running process:
 *   - one campaign per tick, oldest-started first, so several running campaigns
 *     take turns instead of one starving the rest
 *   - a ~25s time budget per tick, well inside the every-minute cadence
 *   - the same guards as a manual send, because it calls the same service:
 *     dedup, tier cap, per-row claim, error classification, circuit breaker
 *
 * Follows the QurbaniWaProcess pattern already proven in production: a cache
 * lock so overlapping ticks can't double-send, and a master switch in
 * t_fin_config (wa_campaign_auto_send) so all background sending can be stopped
 * without touching individual campaigns.
 */
class CampaignSendProcess extends Command
{
    protected $signature = 'campaigns:send-process
                            {--campaign= : Only work this campaign id}
                            {--budget=25 : Seconds of sending to do this tick}';

    protected $description = 'Send the next slice of any campaign running in background mode';

    /** Guards against two ticks overlapping. Shorter than the cron interval. */
    private const LOCK_SECONDS = 55;

    public function handle(): int
    {
        $service = app(CampaignSendService::class);

        if (!$service->backgroundEnabled()) {
            $this->info('Background campaign sending is switched off (wa_campaign_auto_send = 0).');
            return self::SUCCESS;
        }

        $lock = Cache::lock('campaigns_send_process', self::LOCK_SECONDS);
        if (!$lock->get()) {
            $this->info('Another tick is still running — skipping.');
            return self::SUCCESS;
        }

        try {
            // Housekeeping: release rows a crashed sender left claimed.
            $service->recoverStuckClaims();

            $campaign = $this->nextCampaign();
            if (!$campaign) {
                $this->info('No campaigns are running in background mode.');
                return self::SUCCESS;
            }

            // Respect the tier cap before doing anything else, and say so
            // clearly on the campaign so the UI can explain the pause.
            $quota = $service->quota();
            if (!$quota['unlimited'] && $quota['remaining'] <= 0) {
                $this->pause($campaign->id, 'Daily WhatsApp limit reached — will resume automatically once it resets.');
                $this->warn('Daily cap reached; paused campaign ' . $campaign->id);
                return self::SUCCESS;
            }

            $run = $campaign->active_run_id
                ? DB::table('t_crm_campaign_send_runs')->where('id', $campaign->active_run_id)->first()
                : null;

            if (!$run || $run->finished_at) {
                // Nothing left to work on — the session finished.
                $this->markIdle($campaign->id, 'Session complete.');
                $this->info('Campaign ' . $campaign->id . ' had no open run; marked idle.');
                return self::SUCCESS;
            }

            $sessionLeft = max(0, (int) $run->target_count - (int) $run->attempted);
            if ($sessionLeft <= 0) {
                $service->finishRun((int) $run->id, 'target_reached');
                $this->markIdle($campaign->id, 'Session batch finished.');
                $this->info('Campaign ' . $campaign->id . ' session target met.');
                return self::SUCCESS;
            }

            $budgetMs = max(5, (int) $this->option('budget')) * 1000;

            $result = $service->sendBatch((int) $campaign->id, [
                'limit'          => $sessionLeft,
                'include_failed' => false,
                'mode'           => 'background',
                'user_id'        => $run->started_by,
                'run_id'         => (int) $run->id,
                'time_budget_ms' => $budgetMs,
            ]);

            $this->info(sprintf(
                'Campaign %d: sent %d, failed %d, excluded %d — %s',
                $campaign->id,
                $result['sent'] ?? 0,
                $result['failed'] ?? 0,
                $result['excluded'] ?? 0,
                $result['stop_reason'] ?? 'unknown'
            ));

            $this->applyOutcome($service, (int) $campaign->id, (int) $run->id, $result);

            return self::SUCCESS;

        } catch (\Throwable $e) {
            Log::error('campaigns:send-process failed', ['error' => $e->getMessage()]);
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Oldest-started running campaign first. Round-robin by last_send_at means a
     * campaign that just got a slice goes to the back of the queue.
     */
    protected function nextCampaign()
    {
        $q = DB::table('t_crm_campaigns')
            ->where('status', 'active')
            ->where('send_state', 'running')
            ->where('send_mode', 'background');

        if ($this->option('campaign')) {
            $q->where('id', (int) $this->option('campaign'));
        }

        return $q->orderByRaw('last_send_at IS NULL DESC')
            ->orderBy('last_send_at', 'asc')
            ->first();
    }

    /**
     * Translate the batch outcome into campaign send_state.
     *
     * The distinction that matters: 'time_budget' means "still working, come
     * back next minute" and must NOT close the run. A cap or rate limit pauses
     * the campaign with a human-readable reason so the operator understands why
     * it stalled instead of assuming it silently died.
     */
    protected function applyOutcome(CampaignSendService $service, int $campaignId, int $runId, array $result): void
    {
        $reason = $result['stop_reason'] ?? null;

        if ($reason === 'time_budget') {
            DB::table('t_crm_campaigns')->where('id', $campaignId)
                ->update(['send_paused_reason' => null, 'updated_at' => now()]);
            return;
        }

        switch ($reason) {
            case 'daily_cap':
                $this->pause($campaignId, 'Daily WhatsApp limit reached — will resume automatically once it resets.');
                break;

            case 'rate_limited':
                $this->pause($campaignId, 'WhatsApp asked us to slow down — will retry shortly.');
                break;

            // These two need a human. Hard-pause so the worker stops picking the
            // campaign up — otherwise it would retry every minute forever and
            // bury the real problem in log noise.
            case 'auth_error':
                $service->finishRun($runId, 'auth_error');
                $this->pause($campaignId, 'WhatsApp account/token problem — sending stopped until it is fixed.', true);
                break;

            case 'too_many_failures':
                $service->finishRun($runId, 'too_many_failures');
                $this->pause($campaignId, 'Stopped after repeated failures — check the Failed list, then press Resume.', true);
                break;

            case 'media_missing':
                $service->finishRun($runId, 'media_missing');
                $this->pause($campaignId, "The template's header image is missing on the server — upload it, then press Resume. Nobody was messaged.", true);
                break;

            case 'completed':
            case 'no_eligible':
                $service->finishRun($runId, $reason);
                $this->markIdle($campaignId, 'Everyone in this campaign has been messaged.');
                break;

            case 'target_reached':
            case 'all_excluded':
            default:
                $service->finishRun($runId, $reason ?: 'target_reached');
                $this->markIdle($campaignId, "This session's batch is done.");
                break;
        }
    }

    /**
     * Soft pause (default) leaves send_state='running' on purpose: the next tick
     * re-checks the condition and picks straight back up, so a daily-cap or
     * throttle stall needs no operator action. Hard pause flips send_state to
     * 'paused' so the worker stops considering this campaign until someone
     * presses Resume — used only for problems a human has to look at.
     */
    protected function pause(int $campaignId, string $reason, bool $hard = false): void
    {
        $payload = [
            'send_paused_reason' => mb_substr($reason, 0, 255),
            'updated_at'         => now(),
        ];
        if ($hard) {
            $payload['send_state'] = 'paused';
        }

        DB::table('t_crm_campaigns')->where('id', $campaignId)->update($payload);
    }

    protected function markIdle(int $campaignId, string $reason): void
    {
        DB::table('t_crm_campaigns')->where('id', $campaignId)->update([
            'send_state'         => 'idle',
            'active_run_id'      => null,
            'send_paused_reason' => mb_substr($reason, 0, 255),
            'updated_at'         => now(),
        ]);
    }
}
