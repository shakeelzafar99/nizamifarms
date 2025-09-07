<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CRM\ProductModel;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    protected $shopify;

    public function __construct(ShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    public function index(Request $request)
    {
        // Get products with variants
        $query = ProductModel::with('variants');
        
        // Add search functionality
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('vendor', 'LIKE', "%{$search}%")
                  ->orWhere('product_type', 'LIKE', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by vendor
        if ($request->has('vendor') && $request->vendor) {
            $query->where('vendor', $request->vendor);
        }

        $products = $query->orderBy('title')->paginate(20);

        // Get filter options
        $vendors = ProductModel::distinct()->pluck('vendor')->filter()->sort();
        $productTypes = ProductModel::distinct()->pluck('product_type')->filter()->sort();

        return view('pages.products.index', compact('products', 'vendors', 'productTypes'));
    }

    public function show($id)
    {
        try {
            $product = ProductModel::with('variants')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'product' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    /**
     * Import products from Shopify
     */
    public function importProducts(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:250'
            ]);

            $limit = $validated['limit'] ?? 50;

            // Fetch products from Shopify
            $products = $this->shopify->fetchProducts($limit);

            if (empty($products)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No products found in Shopify store. This could mean: 1) Your store has no products, 2) API credentials are incorrect, or 3) Products are in draft status.'
                ]);
            }

            // Store products in database
            $importedCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($products as $shopifyProduct) {
                try {
                    // Map Shopify product to our format
                    $productData = ProductModel::mapShopifyProduct($shopifyProduct);
                    
                    // Store product with variants
                    ProductModel::storeProductFromApi($productData);
                    $importedCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Product ID {$shopifyProduct['id']}: " . $e->getMessage();
                    
                    Log::error('Failed to import Shopify product: ' . $e->getMessage(), [
                        'shopify_product_id' => $shopifyProduct['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $message = "Successfully imported {$importedCount} products from Shopify.";
            if ($errorCount > 0) {
                $message .= " {$errorCount} products failed to import.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'imported_count' => $importedCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Shopify Products Import Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while importing products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync single product from Shopify
     */
    public function syncProduct(Request $request, $id)
    {
        try {
            $product = ProductModel::findOrFail($id);

            if (!$product->shopify_product_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not linked to Shopify'
                ], 400);
            }

            // Fetch single product from Shopify
            $shopifyProduct = $this->shopify->fetchProduct($product->shopify_product_id);

            if (!$shopifyProduct) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found in Shopify'
                ], 404);
            }

            // Map and update product
            $productData = ProductModel::mapShopifyProduct($shopifyProduct);
            ProductModel::storeProductFromApi($productData);

            return response()->json([
                'success' => true,
                'message' => 'Product synced successfully',
                'product' => $product->fresh(['variants'])
            ]);

        } catch (\Exception $e) {
            Log::error('Product sync error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync product: ' . $e->getMessage()
            ], 500);
        }
    }
}
