<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\QurbaniSlotParser;

/**
 * One-off backfill for qurbani_slot_start_minute /
 * qurbani_slot_end_minute on t_crm_prod_order_line_item.
 *
 * Phase 4 (May-2026)
 * ------------------
 * The columns were added by add_qurbani_slot_minutes_may2026.sql.
 * This command parses every line item's qurbani_slot string via
 * QurbaniSlotParser and writes the derived integers back. After
 * a successful first run the columns stay in sync via
 * OrderLineItemModel's saving() hook — but you should re-run
 * this command after any bulk DB::table()->update() that
 * mutates qurbani_slot directly (i.e. anything that bypasses
 * Eloquent), or any time you add a new slot variant to the
 * lookup tables.
 *
 *   php artisan qurbani:backfill-slot-minutes              # apply changes
 *   php artisan qurbani:backfill-slot-minutes --dry-run    # preview only
 *   php artisan qurbani:backfill-slot-minutes --only-null  # skip already-populated rows
 *
 * Output:
 *   - Per-distinct-slot summary table (slot → minutes → count)
 *   - List of unparseable slot strings (so you can clean them up)
 *   - Total updated / skipped / unparseable counts
 *
 * Safety:
 *   - Runs in chunks of 500 to avoid loading the whole table.
 *   - Wrapped in a transaction per chunk so a mid-run failure
 *     leaves the DB in a consistent state.
 *   - --dry-run reports what it WOULD do, writes nothing.
 */
class QurbaniBackfillSlotMinutes extends Command
{
    protected $signature = 'qurbani:backfill-slot-minutes
                            {--dry-run : Preview the changes without writing}
                            {--only-null : Skip rows that already have a slot_end_minute}
                            {--chunk=500 : Rows per chunk}';

    protected $description = 'Parse qurbani_slot strings and populate qurbani_slot_start_minute / qurbani_slot_end_minute columns';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $onlyNull = (bool) $this->option('only-null');
        $chunk    = max(50, (int) $this->option('chunk') ?: 500);

        $this->info('Qurbani slot-minute backfill starting'
            . ($dryRun ? ' (DRY RUN — no writes)' : ''));

        // Phase 4 (May-2026): pre-fetch settings overrides so we
        // can resolve a slot string in O(1) per row. Same priority
        // the model boot hook uses: explicit override beats parser.
        $overrideMap = []; // [slot_text => ['start' => int, 'end' => int]]
        $overrides = DB::table('t_crm_qurbani_field_options')
            ->where('field_name', 'qurbani_slot')
            ->where('is_active', 1)
            ->whereNotNull('slot_end_minute')
            ->get(['option_value', 'slot_start_minute', 'slot_end_minute']);
        foreach ($overrides as $o) {
            $overrideMap[$o->option_value] = [
                'start' => (int) $o->slot_start_minute,
                'end'   => (int) $o->slot_end_minute,
            ];
        }
        if (!empty($overrideMap)) {
            $this->line('  Loaded ' . count($overrideMap) . ' slot override(s) from settings.');
        }

        $query = DB::table('t_crm_prod_order_line_item')
            ->whereNotNull('qurbani_slot')
            ->where('qurbani_slot', '!=', '');
        if ($onlyNull) {
            $query->whereNull('qurbani_slot_end_minute');
        }

        $total = (int) $query->count();
        $this->line("  Candidate rows: {$total}");
        if ($total === 0) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        $updated      = 0;
        $unchanged    = 0;
        $unparseable  = 0;
        $fromOverride = 0;
        $perSlotStats = []; // [slot => ['start' => int|null, 'end' => int|null, 'rows' => int, 'src' => 'override'|'parser'|'none']]
        $bad          = []; // [slot => count]   — unparseable strings

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat('verbose');
        $bar->start();

        $query->orderBy('id')->chunk($chunk, function ($rows) use (
            &$updated, &$unchanged, &$unparseable, &$fromOverride, &$perSlotStats, &$bad,
            $dryRun, $bar, $overrideMap
        ) {
            DB::beginTransaction();
            try {
                foreach ($rows as $r) {
                    $slotKey = (string) $r->qurbani_slot;

                    // Resolve via override map first; fall back to parser.
                    if (isset($overrideMap[$slotKey])) {
                        $start = $overrideMap[$slotKey]['start'];
                        $end   = $overrideMap[$slotKey]['end'];
                        $src   = 'override';
                    } else {
                        [$start, $end] = QurbaniSlotParser::parse($slotKey);
                        $src = $end === null ? 'none' : 'parser';
                    }

                    if (!isset($perSlotStats[$slotKey])) {
                        $perSlotStats[$slotKey] = [
                            'start' => $start, 'end' => $end, 'rows' => 0, 'src' => $src,
                        ];
                    }
                    $perSlotStats[$slotKey]['rows']++;
                    if ($src === 'override') $fromOverride++;

                    if ($end === null) {
                        $unparseable++;
                        $bad[$slotKey] = ($bad[$slotKey] ?? 0) + 1;
                        $bar->advance();
                        continue;
                    }

                    // Skip writes when nothing would change — keeps
                    // updated_at unchanged for already-correct rows.
                    if ((int) ($r->qurbani_slot_start_minute ?? -1) === (int) $start
                        && (int) ($r->qurbani_slot_end_minute   ?? -1) === (int) $end) {
                        $unchanged++;
                        $bar->advance();
                        continue;
                    }

                    if (!$dryRun) {
                        DB::table('t_crm_prod_order_line_item')
                            ->where('id', $r->id)
                            ->update([
                                'qurbani_slot_start_minute' => $start,
                                'qurbani_slot_end_minute'   => $end,
                            ]);
                    }
                    $updated++;
                    $bar->advance();
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("\nChunk failed: " . $e->getMessage());
                throw $e;
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Per-slot summary so the user can eyeball that the parser
        // got the right minutes for every variant. The Source column
        // tells you whether the timing came from the settings UI
        // (override) or the regex parser (auto).
        $this->info('Per-slot summary (' . count($perSlotStats) . ' distinct values):');
        $rows = [];
        ksort($perSlotStats);
        foreach ($perSlotStats as $slot => $s) {
            $rows[] = [
                $slot,
                $s['rows'],
                $s['start'] !== null ? QurbaniSlotParser::formatMinutes($s['start']) . " ({$s['start']})" : '—',
                $s['end']   !== null ? QurbaniSlotParser::formatMinutes($s['end'])   . " ({$s['end']})"   : '—',
                $s['src'],
                $s['end'] === null ? '✗ UNPARSEABLE' : 'ok',
            ];
        }
        $this->table(['Slot', 'Rows', 'Start (min)', 'End (min)', 'Source', 'Result'], $rows);

        if (!empty($bad)) {
            $this->warn('Unparseable slot strings — clean these up and re-run:');
            foreach ($bad as $slot => $cnt) {
                $this->line("  - [{$slot}] · {$cnt} row(s)");
            }
        }

        $this->newLine();
        $this->info('Totals:');
        $this->line("  updated         : {$updated}" . ($dryRun ? '  (dry-run, not written)' : ''));
        $this->line("  unchanged       : {$unchanged}");
        $this->line("  unparseable     : {$unparseable}");
        $this->line("  via override    : {$fromOverride}  (from settings UI)");
        $this->line("  total seen      : " . ($updated + $unchanged + $unparseable));

        return self::SUCCESS;
    }
}
