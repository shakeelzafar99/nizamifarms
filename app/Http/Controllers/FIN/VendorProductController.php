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

        return view('fin.vendor.products', compact('vendor', 'products', 'categories'));
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

        return response()->json([
            'success' => true,
            'products' => $products
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
            ]);

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

        try {
            $product = VendorProductModel::where('vendor_id', $vendorId)
                                         ->findOrFail($productId);

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
            ]);

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

