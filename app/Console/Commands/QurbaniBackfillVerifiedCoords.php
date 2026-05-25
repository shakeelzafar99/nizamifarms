<?php

namespace App\Console\Commands;

use App\Services\QurbaniVerifiedCoordsBackfill;
use Illuminate\Console\Command;

/**
 * One-off backfill for t_crm_prod_customer.verified_location_url + latitude
 * + longitude rows where the URL is a short link (maps.app.goo.gl /
 * goo.gl/maps / g.co) that the in-process parser cannot decode.
 *
 * May-2026
 * --------
 * The "Set Verified Location" save path used to store the user-provided
 * (often short) URL even when it had already been resolved server-side
 * to a long URL we could parse. That left customers with a Verified Pin
 * badge but NULL lat/lng — silently dropping them from the rider's map
 * and ETA calculations (Waseem Aslam scenario). The save path is now
 * fixed for future writes; this command sweeps up the historical
 * records.
 *
 * Per customer it:
 *   1. Follows the short URL with cURL (8s timeout, 5 max redirects)
 *   2. Re-parses lat/lng out of the resolved long URL
 *   3. On success: writes back verified_location_url=<long URL>,
 *      latitude / longitude, and preserves the original
 *      verified_location_saved_by / saved_at audit trail.
 *   4. On failure: leaves the row alone and logs the customer ID so
 *      ops can ping the customer to re-share their pin.
 *
 *   php artisan qurbani:backfill-verified-coords             # apply
 *   php artisan qurbani:backfill-verified-coords --dry-run   # preview
 *   php artisan qurbani:backfill-verified-coords --limit=50  # cap rows
 *
 * Safety:
 *   - Touches ONLY customers with non-null verified_location_url AND
 *     (latitude IS NULL OR longitude IS NULL) — so re-running is idempotent.
 *   - Each write is its own transaction. A single network failure
 *     never corrupts the rest of the batch.
 *   - --dry-run reports what it WOULD do, writes nothing.
 */
class QurbaniBackfillVerifiedCoords extends Command
{
    protected $signature = 'qurbani:backfill-verified-coords
                            {--dry-run : Preview without writing}
                            {--limit=0 : Cap the number of rows processed (0 = all)}';

    protected $description = 'Resolve short Google Maps URLs on customer.verified_location_url and backfill lat/lng';

    public function handle(QurbaniVerifiedCoordsBackfill $svc): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = max(0, (int) $this->option('limit'));

        $this->info('Verified-coords backfill starting' . ($dryRun ? ' (DRY RUN — no writes)' : ''));

        $result = $svc->run($limit, $dryRun);

        $this->line("  Candidate rows: {$result['candidates']}");
        if ($result['candidates'] === 0) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }
        $this->line("  Processed         : {$result['processed']}");
        $this->info('Done.');
        $this->line("  Fixed             : {$result['fixed']}");
        $this->line("  Long-already-bad  : {$result['long_unparseable']}");
        $this->line("  Resolved-no-coords: {$result['resolved_no_coords']}");
        $this->line("  Network errors    : {$result['network_errors']}");

        if (!empty($result['unresolved'])) {
            $this->newLine();
            $this->warn('Unresolved customers (re-ask for a fresh pin):');
            foreach ($result['unresolved'] as $u) {
                $this->line("    #{$u['id']}  {$u['name']}  [{$u['reason']}]");
            }
            if ($result['unresolved_count'] > count($result['unresolved'])) {
                $this->line('    ...and ' . ($result['unresolved_count'] - count($result['unresolved'])) . ' more.');
            }
        }

        return self::SUCCESS;
    }
}
