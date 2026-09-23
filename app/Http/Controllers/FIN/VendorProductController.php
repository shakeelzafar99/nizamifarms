<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\VendorModel;
use App\Models\FIN\VendorProductModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VendorProductController extends Controller
{
    /**
     * ❄ The Frozen business unit. Ingredients belong to it and to nothing else.
     */
    private const FROZEN_BU = 2;

    /**
     * ⚠⚠ DOES THIS VENDOR DEAL IN FROZEN INGREDIENTS AT ALL?
     *
     * The first version asked the wrong question — it only asked whether the ingredient
     * COLUMNS existed, so every by-weight vendor in the company got the Frozen ingredient
     * list. On this database that is **11 meat suppliers** on BU 1: open Jilani Meat or
     * Ghousia Beef, start typing a product name, and the box suggests "Cheese" and
     * "Cooking oil". Ingredients are a Frozen concept and must not appear anywhere else.
     * Caught by the owner, 22-Sep.
     *
     * This gates the SUGGESTIONS (below) and the TAG ITSELF (`ingredientFields()`), so a
     * hand-made API call cannot tag a meat vendor's product either — which would have
     * fed that vendor's purchases into Frozen consumption maths.
     */
    private function dealsInIngredients(?VendorModel $vendor): bool
    {
        if (!$vendor || (int) ($vendor->business_unit_id ?? 0) !== self::FROZEN_BU) {
            return false;
        }

        // Manual deploy: the PHP can land before the SQL. Writing or reading a column
        // that is not there yet would break catalogue management for every vendor.
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('t_fin_vendor_products', 'ingredient_id');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Show vendor products management page
     */
    public function index($vendorId)
    {
        $vendor = VendorModel::findOrFail($vendorId);
        $products = VendorProductModel::forVendor($vendorId)
                                      ->orderBy('product_name')
                                      ->get();

        // Level-1 categories, read from the SALES catalogue so a purchase
        // can never be filed under a category that sales doesn't use.
        $categories = app(\App\Services\CategorySalesPurchaseService::class)->categoryVocabulary();

        // ❄ The Frozen ingredient list, for the "what is this, for Frozen?" field and for
        // the product-name suggestions. ⭐ The names here are the STANDARD: a product typed
        // with the same name as an ingredient is what makes purchasing and the recipe meet.
        // Fails soft to an empty list on a database the migration has not reached yet.
        // ⚠⚠ FROZEN VENDORS ONLY. An empty list here switches off the datalist, the tag
        //    field on both forms, the Ingredient column and the INGREDIENTS payload —
        //    every one of them already renders behind `@if(!empty($ingredients))`.
        $ingredients = [];
        if ($this->dealsInIngredients($vendor)) {
            try {
                $ingredients = \App\Models\Khaas\IngredientModel::where('business_unit_id', self::FROZEN_BU)
                    ->where('is_active', 1)->whereNull('storage_product_id')
                    ->orderBy('name')->get()
                    ->map(fn ($i) => $i->shape())->values()->all();
            } catch (\Throwable $e) {
                $ingredients = [];
            }
        }

        return view('fin.vendor.products', compact('vendor', 'products', 'categories', 'ingredients'));
    }

    /**
     * Get products list as JSON (for AJAX)
     */
    public function list($vendorId)
    {
        $products = VendorProductModel::forVendor($vendorId)
                                      ->active()
                                      ->orderBy('product_name')
                                      ->get();

        // ❄ Carry the ingredient's NAME beside its id so a picker can show "Onions
        // (Piyaaz)" next to the product instead of a number. Additive: every key the
        // phone already reads is untouched, and a product with no tag gets null.
        try {
            $ids = $products->pluck('ingredient_id')->filter()->unique()->all();
            $names = $ids
                ? \App\Models\Khaas\IngredientModel::whereIn('id', $ids)->pluck('name', 'id')->all()
                : [];
            $products->each(function ($p) use ($names) {
                $p->setAttribute('ingredient_name', $p->ingredient_id ? ($names[$p->ingredient_id] ?? null) : null);
            });
        } catch (\Throwable $e) {
            // Migration not run yet: the attribute simply stays absent.
        }

        return response()->json([
            'success' => true,
            'products' => $products,
            // ❄ Does this vendor deal in Frozen ingredients? The phone asks here rather
            //   than guessing from the vendor payload, so the rule lives in ONE place.
            //   False for every BU 1 vendor, which is what keeps "Cheese" and "Cooking
            //   oil" out of the meat suppliers' product forms.
            'supports_ingredients' => $this->dealsInIngredients(VendorModel::find($vendorId)),
        ]);
    }

    /**
     * Store a new vendor product
     */
    public function store(Request $request, $vendorId)
    {
        $request->validate([
            'product_name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'rate_per_unit' => 'required|numeric|min:0.01',
            'is_default' => 'nullable|boolean',
            'category_level_1' => 'nullable|string|max:50'
        ]);

        // ⚠ Resolved OUTSIDE the try below on purpose. The helper refuses an unsizeable tag
        //   with a 422 by throwing; inside the try that catch-all turns it into a bare
        //   500 "Error adding product: " and the person never sees the question.
        $ingredientFields = $this->ingredientFields($request, null, $vendorId);

        try {
            // If this is being set as default, unset any existing defaults
            if ($request->is_default) {
                VendorProductModel::where('vendor_id', $vendorId)
                                  ->where('is_default', 1)
                                  ->update(['is_default' => 0]);
            }

            $product = VendorProductModel::create([
                'vendor_id' => $vendorId,
                'product_name' => $request->product_name,
                'category_level_1' => $this->cleanCategory($request->category_level_1),
                'unit' => $request->unit,
                'rate_per_unit' => $request->rate_per_unit,
                'is_active' => 1,
                'is_default' => $request->is_default ? 1 : 0
            ] + $ingredientFields);

            return response()->json([
                'success' => true,
                'message' => 'Product added successfully!',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            Log::error("Error adding vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error adding product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a vendor product
     */
    public function update(Request $request, $vendorId, $productId)
    {
        $request->validate([
            'product_name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'rate_per_unit' => 'required|numeric|min:0.01',
            'is_default' => 'nullable|boolean',
            'category_level_1' => 'nullable|string|max:50'
        ]);

        // ⚠ Both resolved OUTSIDE the try: a missing product becomes Laravel's own 404,
        //   and an unsizeable tag reaches the person as the 422 question it is, instead
        //   of being swallowed into "Error updating product: ".
        $product = VendorProductModel::where('vendor_id', $vendorId)->findOrFail($productId);
        $ingredientFields = $this->ingredientFields($request, $product, $vendorId);

        try {
            // If this is being set as default, unset any existing defaults
            if ($request->is_default && !$product->is_default) {
                VendorProductModel::where('vendor_id', $vendorId)
                                  ->where('id', '!=', $productId)
                                  ->where('is_default', 1)
                                  ->update(['is_default' => 0]);
            }

            $product->update([
                'product_name' => $request->product_name,
                'category_level_1' => $this->cleanCategory($request->category_level_1),
                'unit' => $request->unit,
                'rate_per_unit' => $request->rate_per_unit,
                'is_default' => $request->is_default ? 1 : 0
            ] + $ingredientFields);

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully!',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            Log::error("Error updating vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ❄ Sep-2026 — the ingredient tag on a catalogue product.
     *
     * "One unit of this product is how many base units of that ingredient?" A kg of
     * onions is 1000 g; a 1 L canola pack is 1000 ml; one egg is 1 pc. When the caller
     * does not say, the unit answers for the obvious cases so nobody has to type 1000
     * for every vegetable — a bare "kg" against a grams ingredient can only mean 1000.
     *
     * An untagged product returns nulls and behaves exactly as it always has.
     * On an edit, an absent ingredient_id LEAVES the existing tag alone rather than
     * wiping it: ConvertEmptyStringsToNull makes a blank field present-but-null, and
     * the old mobile form does not send these keys at all.
     */
    private function ingredientFields(Request $request, ?VendorProductModel $existing = null, $vendorId = null): array
    {
        // ⚠⚠ A NON-FROZEN VENDOR HAS NO INGREDIENTS, whatever the request says.
        //    `dealsInIngredients()` also covers the manual-deploy case where this PHP
        //    arrives before the SQL: writing a column that is not there yet would kill
        //    catalogue creation for every vendor, not just Frozen ones.
        $vendor = null;
        try {
            $vendor = $vendorId !== null ? VendorModel::find($vendorId) : null;
        } catch (\Throwable $e) {
            $vendor = null;
        }

        if (!$this->dealsInIngredients($vendor)) {
            return [];
        }

        if (!$request->has('ingredient_id')) {
            return $existing
                ? []                                        // edit: keep what is there
                : ['ingredient_id' => null, 'pack_qty_base' => null];
        }

        $ingredientId = (int) $request->input('ingredient_id');

        if (!$ingredientId) {
            // Explicitly cleared — "this is not an ingredient".
            return ['ingredient_id' => null, 'pack_qty_base' => null];
        }

        $ingredient = \App\Models\Khaas\IngredientModel::find($ingredientId);
        if (!$ingredient) {
            return ['ingredient_id' => null, 'pack_qty_base' => null];
        }

        $packQty = (float) $request->input('pack_qty_base', 0);

        if ($packQty <= 0) {
            $packQty = $this->impliedPackQty((string) $request->input('unit'), $ingredient->base_unit);
        }

        if ($packQty <= 0) {
            // ⚠⚠ We know what it is but not how much of it. The first version dropped the
            //    tag silently here — Qasim would tag "Tazo cheese 400 g pack" as Cheese,
            //    press Save, and the product would come back untagged with no word why.
            //    A tag the person chose must either stick or be refused OUT LOUD.
            $unitWord = strtolower(trim((string) $request->input('unit'))) ?: 'unit';
            abort(response()->json([
                'success' => false,
                'message' => "How much {$ingredient->name} is in one {$unitWord}? "
                    . "A {$unitWord} could be any size, so type the amount (in "
                    . ($ingredient->base_unit === 'pcs' ? 'pieces' : ($ingredient->base_unit === 'ml' ? 'ml' : 'grams'))
                    . ") before saving.",
            ], 422));
        }

        return ['ingredient_id' => $ingredientId, 'pack_qty_base' => round($packQty, 3)];
    }

    /**
     * What one purchase unit obviously means in base units, or 0 when it is not
     * obvious (a "pack", a "box" — only the person buying knows how big it is).
     */
    private function impliedPackQty(string $purchaseUnit, string $baseUnit): float
    {
        $u = strtolower(trim($purchaseUnit));

        $map = [
            'kg'    => ['g' => 1000.0],
            'gram'  => ['g' => 1.0],
            'grams' => ['g' => 1.0],
            'g'     => ['g' => 1.0],
            'ton'   => ['g' => 1000000.0],
            'liter' => ['ml' => 1000.0],
            'litre' => ['ml' => 1000.0],
            'l'     => ['ml' => 1000.0],
            'ml'    => ['ml' => 1.0],
            'piece' => ['pcs' => 1.0],
            'pcs'   => ['pcs' => 1.0],
            'dozen' => ['pcs' => 12.0],
        ];

        return (float) ($map[$u][$baseUnit] ?? 0.0);
    }

    /**
     * Toggle product active status
     */
    public function toggleStatus($vendorId, $productId)
    {
        try {
            $product = VendorProductModel::where('vendor_id', $vendorId)
                                         ->findOrFail($productId);

            $product->is_active = !$product->is_active;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Product status updated!',
                'is_active' => $product->is_active
            ]);

        } catch (\Exception $e) {
            Log::error("Error toggling vendor product status: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set a product as default for the vendor
     */
    public function setAsDefault($vendorId, $productId)
    {
        try {
            // First, unset any existing default for this vendor
            VendorProductModel::where('vendor_id', $vendorId)
                              ->where('is_default', 1)
                              ->update(['is_default' => 0]);

            // Set the selected product as default
            $product = VendorProductModel::where('vendor_id', $vendorId)
                                         ->findOrFail($productId);
            
            $product->is_default = 1;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => 'Default product updated successfully!',
                'product' => $product
            ]);

        } catch (\Exception $e) {
            Log::error("Error setting default vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error setting default product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Normalise a submitted Level-1 category: blank becomes NULL, and a
     * value outside the sales vocabulary is rejected to NULL rather than
     * stored, so the purchase side can never drift from the sales side by
     * a typo. (Free text here is what would break the whole comparison —
     * purchases are typed as "Veal" but sold as "Beef".)
     */
    private function cleanCategory($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $vocab = app(\App\Services\CategorySalesPurchaseService::class)->categoryVocabulary();

        return in_array($value, $vocab, true) ? $value : null;
    }

    /**
     * Delete a vendor product
     */
    public function destroy($vendorId, $productId)
    {
        try {
            $product = VendorProductModel::where('vendor_id', $vendorId)
                                         ->findOrFail($productId);

            // Check if product has been used in any purchases
            if ($product->purchaseItems()->count() > 0) {
                // Soft delete by deactivating instead
                $product->is_active = 0;
                $product->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Product deactivated (has purchase history)',
                    'deactivated' => true
                ]);
            }

            // Safe to delete if no purchase history
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully!'
            ]);

        } catch (\Exception $e) {
            Log::error("Error deleting vendor product: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting product: ' . $e->getMessage()
            ], 500);
        }
    }
}

