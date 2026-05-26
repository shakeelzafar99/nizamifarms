<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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

        // May-2026 — also write to laravel.log on every run so the
        // operator can confirm via `tail -f storage/logs/laravel.log`
        // that the cron actually fired AND see what it found/skipped.
        // Without this the operator's only signal that anything is
        // happening is the t_ops_qurbani_wa_log table — and 'skipped'
        // / 'no_phone' / 'master_off' rows don't appear there at all,
        // so there's no way to tell "is the worker even running?"
        // from a config issue. We use INFO level so prod log_level=
        // info captures it; debug/notice would be filtered out.
        Log::info('QurbaniWaProcess: tick complete', [
            'max'     => $max,
            'ran'     => (bool) ($result['ran'] ?? false),
            'sent'    => (int) ($result['sent'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed'  => (int) ($result['failed'] ?? 0),
            'reason'  => $result['reason'] ?? null,
        ]);

        // Exit 0 even if 'ran' is false — locked / master-off are
        // expected steady states, not failures.
        return self::SUCCESS;
    }
}
