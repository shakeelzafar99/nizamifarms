<?php

namespace App\Console\Commands;

use App\Services\QurbaniLocationRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * qurbani:loc-request-process — Phase 6 (May-2026)
 * =================================================
 *
 * Safety-net cron worker for the Qurbani Location Request feature.
 *
 * The bulk-send UI normally drives the queue inline by polling
 * POST /qurbani/api/loc-request/bulk/{batchId}/start in chunks while
 * the staff member watches the progress bar. This command exists for:
 *
 *   1. ORPHANED BATCHES — staff closes the tab mid-send. The cron
 *      picks up the remaining queued rows so the customer still gets
 *      their location request (just a bit later).
 *
 *   2. RECOVERY — "sending" rows that have been in-flight longer than
 *      5 minutes (because of a PHP crash or DB hiccup) get reset back
 *      to "queued" so this run can retry them. (The reset itself is
 *      done inside QurbaniLocationRequestService::processQueued().)
 *
 * Scoping:
 *   - No --batch flag: drains ANY queued rows (cron mode).
 *   - --batch=UUID:    drains only that batch (debugging hook).
 *   - --limit=N:       max rows per run (default = cron_max_per_run).
 *
 * Mutex:
 *   55s Cache lock so the scheduler's every-minute trigger never
 *   double-fires even if a previous run is still draining. Lock auto-
 *   releases at end of run, or after TTL on crash.
 */
class QurbaniLocationRequestProcess extends Command
{
    protected $signature = 'qurbani:loc-request-process
                            {--batch= : Process only this batch_id}
                            {--limit= : Override max rows per run}';

    protected $description = 'Drain queued Qurbani location-request WhatsApp sends (cron-fallback).';

    public function handle(QurbaniLocationRequestService $svc): int
    {
        $lockKey = 'qurbani:loc-request:lock';
        $ttl     = 55; // seconds — slightly less than the 1-min cron tick

        $lock = Cache::lock($lockKey, $ttl);
        if (!$lock->get()) {
            $this->info('Another loc-request worker is already running, skipping.');
            return self::SUCCESS;
        }

        try {
            $batchId = $this->option('batch') ?: null;
            $limit   = (int) ($this->option('limit')
                ?: config('qurbani.location_request.cron_max_per_run', 25));

            $started = microtime(true);
            $counters = $svc->processQueued($batchId, $limit);
            $elapsed = round((microtime(true) - $started) * 1000);

            $this->info(sprintf(
                'Loc-request worker run done in %dms: sent=%d failed=%d skipped=%d (batch=%s, cap=%d)',
                $elapsed,
                $counters['sent'],
                $counters['failed'],
                $counters['skipped'],
                $batchId ?: 'any',
                $limit
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Loc-request worker crashed: ' . $e->getMessage());
            \Log::error('qurbani:loc-request-process exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }
}
