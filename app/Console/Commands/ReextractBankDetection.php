<?php

namespace App\Console\Commands;

use App\Services\Payments\BankTagBackfillService;
use Illuminate\Console\Command;

/**
 * CLI wrapper for the receiving-bank HISTORY backfill. The actual logic lives
 * in BankTagBackfillService (shared with the Operations "catch-up" button).
 *
 * Deliberately NOT scheduled / not part of the auto-runner tick — re-scanning
 * history is an owner-triggered action (see the service docblock for why).
 */
class ReextractBankDetection extends Command
{
    protected $signature = 'payments:reextract-bank {--limit=}';
    protected $description = 'Re-read pending-approval WhatsApp proofs through Gemini to backfill the receiving-bank tag (button/CLI only)';

    public function handle(BankTagBackfillService $backfill): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $result = $backfill->run($limit);

        $this->info(sprintf(
            'Eligible: %d | Re-read: %d | Tagged: %d | Remaining: %d%s',
            $result['eligible'],
            $result['reread'],
            $result['tagged'],
            $result['remaining'],
            isset($result['skipped_reason']) ? " (skipped: {$result['skipped_reason']})" : ''
        ));

        return self::SUCCESS;
    }
}
