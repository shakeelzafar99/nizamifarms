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

    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $limit = $request->get('limit', 10);
            
            $products = \App\Models\CRM\ProductModel::with('variants')
                ->where('is_active', true)
                ->where(function($q) use ($query) {
                    $q->where('title', 'LIKE', "%{$query}%")
                      ->orWhereHas('variants', function($vq) use ($query) {
                          $vq->where('sku', 'LIKE', "%{$query}%")
                            ->orWhere('title', 'LIKE', "%{$query}%");
                      });
                })
                ->limit($limit)
                ->get();
            
            $results = [];
            foreach ($products as $product) {
                // Add main product
                $results[] = [
                    'id' => 'product_' . $product->id,
                    'type' => 'product',
                    'name' => $product->title,
                    'sku' => null,
                    'price' => $product->price_min,
                    'inventory' => $product->total_inventory,
                    'vendor' => $product->vendor
                ];
                
                // Add variants
                foreach ($product->variants as $variant) {
                    if ($variant->available) {
                        $results[] = [
                            'id' => 'variant_' . $variant->id,
                            'type' => 'variant',
                            'name' => $product->title . ($variant->title ? ' - ' . $variant->title : ''),
                            'sku' => $variant->sku,
                            'price' => $variant->price,
                            'inventory' => $variant->inventory_quantity,
                            'vendor' => $product->vendor
                        ];
                    }
                }
            }
            
            return response()->json([
                'success' => true,
                'products' => $results
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search products: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import products from Shopify (limited)
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
            $updatedCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($products as $shopifyProduct) {
                try {
                    // Check if product already exists
                    $existingProduct = ProductModel::findByShopifyId($shopifyProduct['id']);
                    $isUpdate = $existingProduct !== null;
                    
                    // Create or update product
                    ProductModel::createOrUpdateFromShopify($shopifyProduct);
                    
                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $importedCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Product ID {$shopifyProduct['id']}: " . $e->getMessage();
                    
                    Log::error('Failed to import Shopify product: ' . $e->getMessage(), [
                        'shopify_product_id' => $shopifyProduct['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $message = "Successfully processed " . ($importedCount + $updatedCount) . " products from Shopify.";
            if ($importedCount > 0) {
                $message .= " {$importedCount} new products imported.";
            }
            if ($updatedCount > 0) {
                $message .= " {$updatedCount} existing products updated.";
            }
            if ($errorCount > 0) {
                $message .= " {$errorCount} products failed to process.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
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

    /**
     * Import ALL products from Shopify
     */
    public function importAllProducts(Request $request)
    {
        try {
            \Log::info('Starting bulk import of all products from Shopify');

            // Fetch ALL products from Shopify
            $products = $this->shopify->fetchAllProducts();

            if (empty($products)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No products found in Shopify store. This could mean: 1) Your store has no products, 2) API credentials are incorrect, or 3) Products are in draft status.'
                ]);
            }

            \Log::info('Fetched ' . count($products) . ' products from Shopify, starting import process');

            // Store products in database
            $importedCount = 0;
            $updatedCount = 0;
            $errorCount = 0;
            $errors = [];
            $totalProducts = count($products);

            foreach ($products as $index => $shopifyProduct) {
                try {
                    // Check if product already exists
                    $existingProduct = ProductModel::findByShopifyId($shopifyProduct['id']);
                    $isUpdate = $existingProduct !== null;
                    
                    // Create or update product
                    ProductModel::createOrUpdateFromShopify($shopifyProduct);
                    
                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $importedCount++;
                    }

                    // Log progress every 10 products
                    if (($index + 1) % 10 === 0) {
                        \Log::info("Processed " . ($index + 1) . "/{$totalProducts} products");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Product ID {$shopifyProduct['id']}: " . $e->getMessage();
                    
                    Log::error('Failed to import Shopify product: ' . $e->getMessage(), [
                        'shopify_product_id' => $shopifyProduct['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            \Log::info('Completed bulk import', [
                'total_products' => $totalProducts,
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'error_count' => $errorCount
            ]);

            $message = "Successfully processed {$totalProducts} products from Shopify.";
            if ($importedCount > 0) {
                $message .= " {$importedCount} new products imported.";
            }
            if ($updatedCount > 0) {
                $message .= " {$updatedCount} existing products updated.";
            }
            if ($errorCount > 0) {
                $message .= " {$errorCount} products failed to process.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'total_products' => $totalProducts,
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk product import error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while importing all products: ' . $e->getMessage()
            ], 500);
        }
    }
}
