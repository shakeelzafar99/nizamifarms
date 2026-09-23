<?php

namespace App\Services\Khaas;

use App\Models\CRM\WarehouseInventoryLogModel;
use App\Models\Khaas\BatchConsumptionModel;
use App\Models\Khaas\RecipeModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns "packs arrived in the warehouse" into "this is what they should have used".
 *
 * ⭐⭐ ONE TRIGGER, BOTH DOORS. Every stock_in is answered — the ones a production plan
 * created (reference_type = 'batch') and the ones somebody typed straight into the
 * warehouse. That is not a convenience; it is the only way the costing can agree with
 * the Inventory Report, which has always counted Made as stock_in. In Aug-2026 three
 * batches were closed at 0 and 111 of 449 packs were entered by hand. A costing engine
 * listening only to batches would have quietly lost a quarter of the month.
 *
 * `source_kind` remembers which door each lot came through, so Month Review can show
 * the split rather than blending them into one number the owner cannot question.
 *
 * ⚠⚠ THIS MUST NEVER BLOCK PRODUCTION. Every entry point swallows its own errors and
 *    logs them. A missing recipe, a bad ingredient, a database hiccup — none of it may
 *    stop Qasim from recording that he made 50 packs. Costing is a reader of the truth,
 *    never a gate on it.
 */
class ConsumptionService
{
    /**
     * Answer one warehouse log row. Safe to call twice — the unique key
     * (warehouse_log_id, ingredient_id) makes a repeat a no-op.
     *
     * @return int number of consumption rows written
     */
    public function recordForLog(WarehouseInventoryLogModel $log, bool $rethrow = false): int
    {
        try {
            if ($log->change_type !== 'stock_in') {
                return 0;
            }

            $packets = (int) $log->quantity_change;
            if ($packets <= 0) {
                // A stock_in is always positive by construction; if one ever is not,
                // it is a correction wearing the wrong hat and we do not guess at it.
                return 0;
            }

            $madeOn = $log->created_at
                ? Carbon::parse($log->created_at)->toDateString()
                : now()->toDateString();

            $recipe = RecipeModel::currentFor((int) $log->product_id, $madeOn);
            if (!$recipe) {
                $this->noteMissingRecipe((int) $log->product_id, $madeOn);
                return 0;
            }

            $lines = $recipe->lines()->get();
            if ($lines->isEmpty()) {
                return 0;
            }

            $fromPlan = $log->reference_type === 'batch';

            $rows = [];
            foreach ($lines as $line) {
                $perPacket = $recipe->perPacket($line);
                if ($perPacket <= 0) {
                    continue;
                }

                $rows[] = [
                    'warehouse_log_id' => (int) $log->id,
                    'business_unit_id' => (int) $log->business_unit_id,
                    'product_id'       => (int) $log->product_id,
                    'batch_id'         => $fromPlan ? (int) $log->reference_id : null,
                    'source_kind'      => $fromPlan
                        ? BatchConsumptionModel::SOURCE_PLAN
                        : BatchConsumptionModel::SOURCE_DIRECT,
                    'recipe_id'        => (int) $recipe->id,
                    'ingredient_id'    => (int) $line->ingredient_id,
                    'packets'          => $packets,
                    'qty_base'         => round($perPacket * $packets, 3),
                    'made_on'          => $madeOn,
                    'created_by'       => $log->created_by ?: auth()->id(),
                    'created_at'       => now(),
                ];
            }

            if (!$rows) {
                return 0;
            }

            // insertOrIgnore, not insert: replaying a log that was already answered
            // must be silent, so "Re-apply" and a retried request are both harmless.
            //
            // ⚠ Return what was ACTUALLY written, not how many rows we offered. The
            //   difference matters: reapplyMonth counts a 0 as "this stock_in had no
            //   recipe" and reports it to the owner, so a replay that silently ignored
            //   its rows must not be able to masquerade as work done.
            return (int) DB::table('t_crm_khaas_batch_consumption')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            Log::warning('Frozen consumption: could not record for warehouse log', [
                'log_id' => $log->id ?? null,
                'error'  => $e->getMessage(),
            ]);

            // The replay path asks for the truth: it is rebuilding a whole month inside
            // one transaction and must be allowed to abort rather than commit a hole.
            if ($rethrow) {
                throw $e;
            }

            // ⚠⚠ A DEADLOCK OR LOCK-WAIT TIMEOUT MUST NOT BE SWALLOWED EITHER.
            //    Those roll the CALLER's transaction back server-side. Staying quiet
            //    would let the stock-in's own commit "succeed" while the quantity change
            //    and its log row were already gone — the app would say "stock updated"
            //    and nothing would have been. Costing may fail silently; it may not take
            //    a warehouse movement down with it without saying so.
            if ($e instanceof \Illuminate\Database\QueryException
                && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw $e;
            }

            return 0;
        }
    }

    /**
     * Restate a whole month: drop what is there and rebuild it from the recipe version
     * that governs each stock_in's own date.
     *
     * This is the ONLY way history is rewritten. It exists because the first recipe a
     * product ever gets is usually typed after some packs were already made, and
     * because a recipe can simply be wrong the first time. It is deliberate, it is
     * gated on the manage permission, and it says what it changed.
     *
     * @return array{logs:int, rows:int, skipped:int, products:array<int,string>}
     */
    public function reapplyMonth(int $businessUnitId, string $month, ?int $productId = null): array
    {
        [$start, $end] = (new FrozenMonthService())->window($month);

        $logs = WarehouseInventoryLogModel::where('business_unit_id', $businessUnitId)
            ->where('change_type', 'stock_in')
            ->whereBetween('created_at', [$start, $end])
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('id')
            ->get();

        $logIds = $logs->pluck('id')->all();

        $rows = 0;
        $skipped = 0;
        $missing = [];

        // ⚠ The clear AND the rebuild are ONE transaction. Splitting them means a failure
        //   halfway leaves the month with its old rows deleted and only some rewritten —
        //   a month that silently reads low. Either the whole month is restated or none
        //   of it is.
        DB::transaction(function () use ($logs, $logIds, $businessUnitId, &$rows, &$skipped, &$missing) {
            if ($logIds) {
                BatchConsumptionModel::where('business_unit_id', $businessUnitId)
                    ->whereIn('warehouse_log_id', $logIds)
                    ->delete();
            }

            foreach ($logs as $log) {
                // ⚠⚠ rethrow: TRUE here. recordForLog normally swallows its own errors,
                //    which is right when it is riding along behind a stock-in — but
                //    inside this transaction it would turn a database failure into
                //    "0 rows written", the loop would carry on, the delete above would
                //    commit, and the month would be left emptied while the report said
                //    "N had no recipe on their date and were left alone". A failure must
                //    abort the whole restatement instead.
                $written = $this->recordForLog($log, true);
                if ($written === 0) {
                    $skipped++;
                    $missing[(int) $log->product_id] = true;
                }
                $rows += $written;
            }
        });

        return [
            'logs'     => $logs->count(),
            'rows'     => $rows,
            'skipped'  => $skipped,
            'products' => array_keys($missing),
        ];
    }

    /**
     * Consumption lines for one stock_in or one batch, for the drill-downs.
     *
     * @return array<int,array>
     */
    public function linesFor(?int $warehouseLogId = null, ?int $batchId = null): array
    {
        $q = BatchConsumptionModel::with('ingredient')
            ->when($warehouseLogId, fn ($x) => $x->where('warehouse_log_id', $warehouseLogId))
            ->when($batchId, fn ($x) => $x->where('batch_id', $batchId));

        if (!$warehouseLogId && !$batchId) {
            return [];
        }

        return $q->get()->map(function (BatchConsumptionModel $c) {
            $ing = $c->ingredient;
            return [
                'ingredient_id'   => (int) $c->ingredient_id,
                'ingredient_name' => $ing->name ?? 'Unknown',
                'kind'            => $ing->kind ?? 'other',
                'qty_base'        => (float) $c->qty_base,
                'qty_text'        => $ing ? $ing->phrase((float) $c->qty_base) : (string) $c->qty_base,
                'packets'         => (int) $c->packets,
                'source_kind'     => $c->source_kind,
                'made_on'         => optional($c->made_on)->toDateString(),
            ];
        })->values()->all();
    }

    /**
     * A product whose packs are arriving with no recipe behind them is worth saying
     * out loud — but once a day, not once a pack. The log is the only signal; nothing
     * is written to the database and nothing is shown to the person stocking in.
     */
    private function noteMissingRecipe(int $productId, string $madeOn): void
    {
        $key = 'frozen_recipe_missing_' . $productId . '_' . $madeOn;

        try {
            if (\Illuminate\Support\Facades\Cache::has($key)) {
                return;
            }
            \Illuminate\Support\Facades\Cache::put($key, 1, now()->addDay());
        } catch (\Throwable $e) {
            // A cache that is not there must not stop the note, nor repeat it loudly.
        }

        Log::info('Frozen consumption: no recipe for product, stock_in left uncosted', [
            'product_id' => $productId,
            'made_on'    => $madeOn,
        ]);
    }
}
