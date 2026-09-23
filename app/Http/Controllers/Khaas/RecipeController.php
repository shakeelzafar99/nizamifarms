<?php

namespace App\Http\Controllers\Khaas;

use App\Http\Controllers\Controller;
use App\Models\Khaas\IngredientModel;
use App\Models\Khaas\IngredientOpeningModel;
use App\Services\Khaas\ConsumptionService;
use App\Services\Khaas\FrozenCostingService;
use App\Services\Khaas\FrozenMonthService;
use App\Services\Khaas\RecipeService;
use Illuminate\Http\Request;

/**
 * Ingredients, recipes and what they cost — one controller, both surfaces.
 *
 * Web routes live under /khaas/*, the phone's under /api/warehouse/*, and both land
 * here, so the two can never answer differently.
 *
 * ⚠ /api/warehouse/* sits in one auth:sanctum group with no permission middleware — a
 *   known, owner-accepted gap. Rather than wait for a group-level gate, every WRITE
 *   below checks its own permission in the method, the same way approveTransfer does.
 */
class RecipeController extends Controller
{
    private RecipeService $recipes;
    private FrozenCostingService $costing;
    private ConsumptionService $consumption;

    public function __construct()
    {
        $this->recipes     = new RecipeService();
        $this->costing     = new FrozenCostingService();
        $this->consumption = new ConsumptionService();
    }

    // =================================================================
    //  GATES
    // =================================================================

    private function canAccess(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('access_khaas_mode') : false;
    }

    private function canManage(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('manage_khaas_recipes') : false;
    }

    private function canSeeCost(): bool
    {
        $user = auth()->user();
        return $user ? $user->hasMobilePermission('view_khaas_costing') : false;
    }

    /** A refusal that names the way out, never a bare "access denied". */
    private function deny(string $what)
    {
        return response()->json([
            'success' => false,
            'message' => $what,
        ], 403);
    }

    /**
     * ⚠⚠ THE BUSINESS UNIT IS NOT THE CLIENT'S TO CHOOSE.
     *
     * This used to take whatever `business_unit_id` arrived. That is harmless on a read
     * and very much not on `reapply`, which DELETES a month of consumption for the unit
     * it is handed — anyone with `manage_khaas_recipes` could have wiped another unit's
     * rows by posting `business_unit_id=1`. Every caller of this feature is Frozen, so
     * the value is pinned and a mismatched request is refused rather than quietly
     * answered about the wrong unit.
     */
    private function businessUnitId(Request $request): int
    {
        $asked = (int) $request->input('business_unit_id', 0);

        if ($asked && $asked !== RecipeService::FROZEN_BU) {
            abort(response()->json([
                'success' => false,
                'message' => 'Recipes and ingredient costing are a Frozen feature; that is not a Frozen business unit.',
            ], 422));
        }

        return RecipeService::FROZEN_BU;
    }

    // =================================================================
    //  INGREDIENTS
    // =================================================================

    public function ingredients(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed to see ingredients.');
        }

        $bu = $this->businessUnitId($request);

        return response()->json([
            'success'     => true,
            'ingredients' => $this->recipes->ingredients($bu, (bool) $request->boolean('include_inactive')),
            // Where a recipe names something purchasing has never seen — the list both
            // surfaces show so Qasim knows what to add, and under what exact name.
            'gaps'        => $this->recipes->gaps($bu),
            'kinds'       => IngredientModel::KIND_LABELS,
            'units'       => IngredientModel::DISPLAY_UNITS,
            'can_manage'  => $this->canManage(),
        ]);
    }

    public function saveIngredient(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change the ingredient list. Ask one of them to add it.');
        }

        try {
            $ingredient = $this->recipes->saveIngredient(
                $request->all(),
                auth()->id(),
                $this->businessUnitId($request)
            );

            return response()->json([
                'success'    => true,
                'message'    => 'Saved.',
                'ingredient' => $ingredient->shape(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('Frozen recipes: ingredient save failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that ingredient.'], 500);
        }
    }

    public function deactivateIngredient(Request $request, $id)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change the ingredient list.');
        }

        $ingredient = IngredientModel::find($id);
        if (!$ingredient) {
            return response()->json(['success' => false, 'message' => 'That ingredient no longer exists.'], 404);
        }

        // Deactivating is always allowed — the history it is attached to is unaffected,
        // and a wrong ingredient nobody can hide is worse than one that stops appearing.
        $ingredient->is_active = 0;
        $ingredient->save();

        $inUse = $this->recipes->ingredientInUse($ingredient);

        return response()->json([
            'success' => true,
            'message' => $inUse
                ? "\"{$ingredient->name}\" is hidden from new recipes. Past purchases and batches keep it."
                : "\"{$ingredient->name}\" is hidden.",
        ]);
    }

    // =================================================================
    //  RECIPES
    // =================================================================

    public function show(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed to see a recipe.');
        }

        $productId = (int) $request->input('product_id');
        if (!$productId) {
            return response()->json(['success' => false, 'message' => 'Which product?'], 400);
        }

        return response()->json([
            'success'     => true,
            'recipe'      => $this->recipes->recipeFor($productId, $request->input('on_date')),
            'ingredients' => $this->recipes->ingredients($this->businessUnitId($request)),
            'history'     => $this->recipes->history($productId),
            'can_manage'  => $this->canManage(),
        ]);
    }

    public function save(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can change a recipe. Ask one of them.');
        }

        $lines = $request->input('lines', []);
        if (!is_array($lines)) {
            $lines = [];
        }

        try {
            $recipe = $this->recipes->saveRecipe(
                (int) $request->input('product_id'),
                (int) $request->input('basis_packets'),
                $lines,
                $request->input('effective_from'),
                $request->input('note'),
                auth()->id()
            );

            $shaped = $this->recipes->recipeFor((int) $recipe->product_id);

            return response()->json([
                'success' => true,
                'message' => "Saved as version {$recipe->version}.",
                'recipe'  => $shaped,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Log::error('Frozen recipes: save failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save that recipe.'], 500);
        }
    }

    /** Which products have a recipe — drives the "no recipe" chips on both surfaces. */
    public function coverage(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        return response()->json([
            'success'  => true,
            'products' => $this->recipes->coverage($this->businessUnitId($request)),
            'can_manage' => $this->canManage(),
        ]);
    }

    // =================================================================
    //  CONSUMPTION AND COST
    // =================================================================

    /** The ingredient lines behind one stock-in or one batch. */
    public function consumption(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $logId   = (int) $request->input('log_id') ?: null;
        $batchId = (int) $request->input('batch_id') ?: null;

        if (!$logId && !$batchId) {
            return response()->json(['success' => false, 'message' => 'Which stock-in or batch?'], 400);
        }

        return response()->json([
            'success' => true,
            'lines'   => $this->consumption->linesFor($logId, $batchId),
        ]);
    }

    /**
     * One product's estimated cost per pack, for the card chip.
     * Quantities are open to anyone in Frozen mode; the rupees are not.
     */
    public function productCost(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $productId = (int) $request->input('product_id');
        $month     = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            $month = now()->format('Y-m');
        }

        $bu   = $this->businessUnitId($request);
        $cost = $this->costing->costPerPackFor($bu, $productId, $month);

        if ($cost && !$this->canSeeCost()) {
            $cost['cost_per_pack'] = null;
        }

        return response()->json([
            'success'       => true,
            'cost'          => $cost,
            'can_see_cost'  => $this->canSeeCost(),
            'recipe'        => $this->recipes->recipeFor($productId),
        ]);
    }

    /**
     * Cost chips for a whole screen in one call, so the Products list does not fire one
     * request per card. Returns a map keyed by product id.
     */
    public function productCosts(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $month = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            $month = now()->format('Y-m');
        }

        $bu      = $this->businessUnitId($request);
        $data    = $this->costing->productMonth($bu, $month);
        $canCost = $this->canSeeCost();

        $map = [];
        foreach ($data['rows'] as $row) {
            $map[(string) $row['product_id']] = [
                'made'          => $row['made'],
                'made_plan'     => $row['made_plan'],
                'made_direct'   => $row['made_direct'],
                'cost_per_pack' => $canCost ? $row['cost_per_pack'] : null,
            ];
        }

        return response()->json([
            'success'      => true,
            'month'        => $month,
            'costs'        => (object) $map,
            'coverage'     => $this->recipes->coverage($bu),
            'can_see_cost' => $canCost,
        ]);
    }

    /**
     * Restate a month from the recipes as they stand now.
     *
     * The one deliberate way history is rewritten — needed because a product's first
     * recipe is usually typed after some packs were already made.
     */
    public function reapply(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can re-apply a month.');
        }

        $month = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            return response()->json(['success' => false, 'message' => 'Which month?'], 400);
        }

        $result = $this->consumption->reapplyMonth(
            $this->businessUnitId($request),
            $month,
            (int) $request->input('product_id') ?: null
        );

        $message = sprintf(
            'Re-applied %s: %d stock-ins read, %d ingredient lines written.',
            $month, $result['logs'], $result['rows']
        );

        if ($result['skipped'] > 0) {
            $message .= sprintf(' %d had no recipe on their date and were left alone.', $result['skipped']);
        }

        return response()->json(['success' => true, 'message' => $message, 'result' => $result]);
    }

    // =================================================================
    //  OPENING STOCK AND COUNTS
    // =================================================================

    public function saveOpening(Request $request)
    {
        if (!$this->canManage()) {
            return $this->deny('Only Taimur, Shabib or Qasim can set opening stock.');
        }

        $ingredientId = (int) $request->input('ingredient_id');
        $ingredient   = IngredientModel::where('id', $ingredientId)
            ->where('business_unit_id', $this->businessUnitId($request))
            ->first();
        if (!$ingredient) {
            return response()->json(['success' => false, 'message' => 'Which ingredient?'], 404);
        }

        $qty  = (float) $request->input('qty', 0);
        $unit = (string) $request->input('unit', $ingredient->base_unit);
        $base = IngredientModel::toBase($qty, $unit);

        if ($base < 0) {
            return response()->json(['success' => false, 'message' => 'Stock cannot be negative.'], 422);
        }

        $kind = $request->input('kind') === IngredientOpeningModel::KIND_COUNT
            ? IngredientOpeningModel::KIND_COUNT
            : IngredientOpeningModel::KIND_OPENING;

        IngredientOpeningModel::create([
            'ingredient_id' => $ingredientId,
            'kind'          => $kind,
            'counted_on'    => $request->input('counted_on') ?: now()->toDateString(),
            'qty_base'      => $base,
            'rupees'        => $request->input('rupees') !== null ? (float) $request->input('rupees') : null,
            'note'          => $request->input('note') ? mb_substr($request->input('note'), 0, 255) : null,
            'created_by'    => auth()->id(),
            'created_at'    => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $kind === IngredientOpeningModel::KIND_COUNT
                ? "Counted {$ingredient->phrase($base)} of {$ingredient->name}."
                : "Opening stock set to {$ingredient->phrase($base)} of {$ingredient->name}.",
        ]);
    }

    /** The whole ingredient panel for a month, for the phone and the page alike. */
    public function ingredientMonth(Request $request)
    {
        if (!$this->canAccess()) {
            return $this->deny('Frozen mode access is needed.');
        }

        $month = $request->input('month');
        if (!FrozenMonthService::isValidMonth($month)) {
            $month = now()->format('Y-m');
        }

        $bu   = $this->businessUnitId($request);
        $data = $this->costing->ingredientMonth($bu, $month);

        if (!$this->canSeeCost()) {
            foreach ($data['rows'] as $i => $row) {
                foreach (['bought_cost', 'used_value', 'rate_per_base', 'rate_text'] as $k) {
                    $data['rows'][$i][$k] = null;
                }
            }
            foreach (['bought_cost', 'used_value', 'meat_used_value', 'other_used_value'] as $k) {
                $data['totals'][$k] = null;
            }
        }

        return response()->json([
            'success'      => true,
            'month'        => $month,
            'ingredients'  => $data,
            'can_see_cost' => $this->canSeeCost(),
            'can_manage'   => $this->canManage(),
        ]);
    }
}
