<?php

namespace App\Console\Commands;

use App\Services\Riders\FleetSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * ⏰ `fleet:sweep` — the bikes / maintenance / workshop layer's clock.
 *
 * Runs every time-driven fleet sweep in one bounded tick:
 *   • service-due pushes (due-soon 150 km / 3 days, and overdue) to the rider holding the
 *     machine TODAY and to the managers — deduped per alert-and-keeper in
 *     `t_ops_service_alert_push`;
 *   • the day-before workshop reminder to the rider, once per visit;
 *   • the 17:00 nudge to the planners about a proposal still waiting for tomorrow, and the
 *     auto-decline of a proposal whose day arrived with nobody having looked;
 *   • the overnight-meter escalation to management, stamped once per journey.
 *
 * WHY. Every one of these used to fire only when somebody opened a screen that polled the
 * right endpoint — correct while prod could not run a command on a clock, and the reason
 * a time-based job due in three days could sit un-announced until a manager happened to
 * open Bikes. StackCP schedules individual artisan commands (`campaigns:send-process` has
 * run that way since Aug-7 2026), so this one goes in the same list:
 *
 *   /usr/bin/php82 /home/sites/29a/8/8556230fc3/public_html/app/artisan fleet:sweep
 *
 * every 15 minutes is plenty — nothing here is more urgent than that, and every push is
 * idempotent, so a tighter cadence buys nothing but load.
 *
 * ⭐ The request piggybacks are KEPT (same service, same ledgers). They cover a day the
 *   cron is off, and cannot double-send.
 *
 * Follows the CampaignSendProcess pattern: a cache lock shorter than the cron interval,
 * one bounded tick, counts on stdout so the StackCP "Test Command" button shows what ran.
 */
class FleetSweep extends Command
{
    protected $signature   = 'fleet:sweep';
    protected $description = 'Service-due pushes, workshop reminders/escalation, overnight-meter escalation';

    /** Shorter than any sane cron interval. */
    private const LOCK_SECONDS = 55;

    public function handle(): int
    {
        $lock = Cache::lock('fleet_sweep', self::LOCK_SECONDS);
        if (!$lock->get()) {
            $this->info('Another fleet sweep is still running — skipping.');
            return self::SUCCESS;
        }
        try {
            $r = app(FleetSweepService::class)->run();
            $this->info(sprintf(
                'fleet:sweep — service pushes %d · workshop reminders %d · approval nudges %d · auto-declined %d · home-meter escalations %d',
                $r['service_pushes'], $r['workshop_reminders'], $r['approval_nudges'],
                $r['auto_declined'], $r['home_meter_escalations']
            ));
            foreach ($r['errors'] as $e) $this->error('  ⚠ ' . $e);
            return $r['errors'] ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
