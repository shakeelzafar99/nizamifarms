<?php

namespace App\Console\Commands;

use App\Services\FIN\TipsFundService;
use Illuminate\Console\Command;

/**
 * Collect every tip the Tips Fund should already be holding.
 *
 * Thin wrapper over TipsFundService::backfill(), which is ALSO behind the
 * Taimur-only "Collect missing tips" button on the Ledger Hub Tips page —
 * production has no shell, so that button is the door that actually gets used.
 * Both doors are idempotent: a run that finds nothing to do says exactly that.
 *
 *   php artisan tips:backfill              # do it
 *   php artisan tips:backfill --dry-run    # just say what would change
 */
class TipsFundBackfill extends Command
{
    protected $signature = 'tips:backfill {--dry-run : Report what would change without writing anything}';

    protected $description = 'Collect tips on delivered invoices into the Tips Fund (idempotent).';

    public function handle(TipsFundService $tips): int
    {
        if (!$tips->ready()) {
            $this->error('The TIPS_FUND account does not exist. Run database/migrations/tips_fund_sep2026.sql first.');
            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info('Tips Fund backfill' . ($dry ? ' (dry run — nothing will be written)' : ''));

        $r = $tips->backfill($dry);

        $this->line('  Tips count from : ' . $r['cutoff']);
        $this->line('  Candidates      : ' . $r['candidates'] . ' tipped order(s)');
        foreach ($r['items'] as $it) {
            $this->line(sprintf('  %s %10s  %s', $dry ? 'would collect' : 'collected    ', number_format($it['amount'], 2), $it['order']));
        }
        $this->newLine();
        $this->info('  ' . ($dry ? 'Would move' : 'Moved') . '      : Rs ' . number_format($r['moved'], 2) . ' across ' . $r['changed'] . ' order(s)');
        $this->line('  Already correct : ' . $r['already']);
        $this->line('  Waiting on an invoice approval : ' . $r['waiting']);
        if ($r['failed']) {
            $this->error('  Failed          : ' . $r['failed'] . ' — see the log');
        }
        $this->line('  Pool holds now  : Rs ' . number_format($r['balance'], 2));
        // On a dry run nothing was collected, so every candidate still looks
        // "pending" — the figure only means something after a real run.
        if (!$dry && $r['pending'] > 0) {
            $this->warn('  Rs ' . number_format($r['pending'], 2) . ' of tips are on invoices not approved yet; they join the pool when those are approved.');
        }

        return $r['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
