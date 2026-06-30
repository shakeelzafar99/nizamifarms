<?php

namespace App\Console\Commands;

use App\Services\Location\OpenOrderLocationService;
use Illuminate\Console\Command;

/**
 * location:auto-process — Jun-2026
 * ================================
 *
 * Drains the auto location-request queue (t_crm_location_auto_queue) written by
 * the order accept / create hooks.
 *
 * Behaviour:
 *   - Master switch OFF  → no-op (one cheap config lookup).
 *   - Outside the daily window (default 09:00–18:00 Asia/Karachi) → rows wait;
 *     the next run inside the window flushes the backlog. So an order accepted at
 *     8pm waits until 9am, then goes out automatically.
 *   - Inside the window → sends the global default template to each queued
 *     customer, re-validating eligibility live before each send.
 *
 * Mutex: 55s Cache lock so the every-minute scheduler never double-fires.
 */
class LocationAutoProcess extends Command
{
    protected $signature = 'location:auto-process {--limit= : Max rows per run}';

    protected $description = 'Drain queued auto location-request WhatsApp sends (window + switch aware).';

    public function handle(OpenOrderLocationService $svc): int
    {
        try {
            $limit = (int) ($this->option('limit') ?: 25);

            $started = microtime(true);
            // drainWithLock() owns the 55s mutex now — shared with the
            // request-time terminating hook (mobile/web open-orders poll) so
            // the two trigger paths can never double-fire on the same rows.
            $counters = $svc->drainWithLock($limit);
            $elapsed = round((microtime(true) - $started) * 1000);

            $this->info(sprintf(
                'Auto location worker done in %dms: sent=%d failed=%d skipped=%d (cap=%d)',
                $elapsed,
                $counters['sent'],
                $counters['failed'],
                $counters['skipped'],
                $limit
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Auto location worker crashed: ' . $e->getMessage());
            \Log::error('location:auto-process exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}
