<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentSignalAutoRunner;
use Illuminate\Console\Command;

/**
 * Cron-fallback tick for the online-payment auto-matching workers (Jul-2026).
 *
 * Delegates to PaymentSignalAutoRunner::processNow(), which owns the global
 * 55s Cache lock shared with the request-time pokes (WhatsApp webhook, the
 * store unread-count poll). Scheduling THIS command — instead of running
 * payments:process-signals / payments:poll-bank-emails directly — means the
 * cron and an in-flight request drain can never read the same pending
 * screenshots twice (duplicate paid Gemini calls / double IMAP connects).
 *
 * Runs as its own background process (runInBackground in routes/console.php)
 * so a slow Gemini batch never delays the other every-minute scheduled tasks.
 */
class PaymentsAutoTick extends Command
{
    protected $signature = 'payments:auto-tick';
    protected $description = 'Run the payment-signal workers once, under the shared auto-runner lock';

    public function handle(PaymentSignalAutoRunner $runner): int
    {
        $result = $runner->processNow();
        $this->info($result['ran'] ? 'ran' : ('skipped: ' . ($result['reason'] ?? 'unknown')));

        return self::SUCCESS;
    }
}
