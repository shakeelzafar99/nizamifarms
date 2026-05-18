<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\QurbaniWaAutoSender;

/**
 * Cron-fallback runner for the Qurbani auto-WhatsApp worker.
 *
 * Phase 3 (May-2026)
 * ------------------
 * The system fires the worker primarily off `app()->terminating()`
 * callbacks attached to the manager polling endpoints (riders summary
 * + per-rider route). Those polls run while the manager has the
 * planner open — but if no manager is logged in (e.g. early-morning
 * triggers, slaughter delay messages on a slow day) we'd starve.
 *
 * This command provides a once-per-minute fallback. It does NOT add
 * any new server software — Laravel's existing scheduler runs it.
 * The worker itself uses a 55-second cache lock so the cron tick and
 * any concurrent terminating-callback can't double-fire.
 *
 *   php artisan qurbani:wa-process       # one-shot run, used by cron
 */
class QurbaniWaProcess extends Command
{
    protected $signature = 'qurbani:wa-process {--max=10 : Max sends per run}';

    protected $description = 'Process pending Qurbani auto-WhatsApp messages (slaughter + OFD triggers)';

    public function handle(QurbaniWaAutoSender $sender): int
    {
        $max = (int) $this->option('max');
        $max = $max > 0 ? $max : 10;

        $result = $sender->processNow($max);

        $this->line(json_encode($result));

        // Exit 0 even if 'ran' is false — locked / master-off are
        // expected steady states, not failures.
        return self::SUCCESS;
    }
}
