<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CRM\ProductModel;
use App\Services\ShopifyService;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    protected $shopify;
    protected $wooCommerce;

    public function __construct(ShopifyService $shopify, WooCommerceService $wooCommerce)
    {
        $this->shopify = $shopify;
        $this->wooCommerce = $wooCommerce;
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
                  ->orWhere('product_type', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%")
                        ->orWhere('title', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by sync_status
        if ($request->has('sync_status') && $request->sync_status) {
            $query->where('sync_status', $request->sync_status);
        }

        $products = $query->orderBy('title')->paginate(20);

        // Get filter options
        $syncStatuses = ProductModel::distinct()->pluck('sync_status')->filter()->sort();
        $productTypes = ProductModel::distinct()->pluck('product_type')->filter()->sort();

        // If this is an AJAX request, return JSON
        if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => true,
                'products' => $products->items(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'from' => $products->firstItem(),
                    'to' => $products->lastItem(),
                    'total' => $products->total(),
                    'per_page' => $products->perPage(),
                    'last_page' => $products->lastPage()
                ]
            ]);
        }

        return view('pages.products.index', compact('products', 'syncStatuses', 'productTypes'));
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
                    // Create hyphen-less version for flexible SKU search
                    $queryNoHyphens = str_replace(['-', ' '], '', $query);
                    
                    $q->where('title', 'LIKE', "%{$query}%")
                      ->orWhereHas('variants', function($vq) use ($query, $queryNoHyphens) {
                          $vq->where('sku', 'LIKE', "%{$query}%")
                            ->orWhere('title', 'LIKE', "%{$query}%")
                            // Allow searching SKU without hyphens (e.g., "P12 DM" finds "P12-DM")
                            ->orWhereRaw('REPLACE(REPLACE(sku, "-", ""), " ", "") LIKE ?', ["%{$queryNoHyphens}%"]);
                      });
                })
                ->limit($limit)
                ->get();
            
            $results = [];
            foreach ($products as $product) {
                // Only add variants, not the parent product (to avoid duplicates)
                foreach ($product->variants as $variant) {
                    if ($variant->available) {
                        // For single variants, use just the product title
                        // For multiple variants, append variant title if it's different
                        $displayName = $product->title;
                        if (count($product->variants) > 1 && $variant->title && $variant->title !== $product->title) {
                            $displayName .= ' - ' . $variant->title;
                        }
                        
                        $results[] = [
                            'id' => 'variant_' . $variant->id,
                            'type' => 'variant',
                            'name' => $displayName,
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

            $source = $request->get('source', 'Shopify');
            if (strcasecmp($source, 'WooCommerce') === 0) {
                \Log::info('Bulk import source selected: WooCommerce');
                $wooProductsRaw = $this->wooCommerce->fetchAllProducts();
                $products = [];
                foreach ($wooProductsRaw as $wooProduct) {
                    $variations = [];
                    if (($wooProduct['type'] ?? '') === 'variable') {
                        $variations = $this->wooCommerce->fetchProductVariations((int)$wooProduct['id']);
                    }
                    $products[] = $this->wooCommerce->mapWooProduct($wooProduct, $variations);
                }
            } else {
                // Fetch ALL products from Shopify
                $products = $this->shopify->fetchAllProducts();
            }

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

            foreach ($products as $index => $productPayload) {
                try {
                    // Reuse store method that expects canonical payload
                    $stored = ProductModel::storeProductFromApi($productPayload);
                    $isUpdate = $stored->wasRecentlyCreated === false;
                    
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
                    $errors[] = $e->getMessage();
                    Log::error('Failed to import product: ' . $e->getMessage());
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

    /**
     * Show the form for creating a new product
     */
    public function create()
    {
        return view('pages.products.create');
    }

    /**
     * Store a manually created product
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'vendor' => 'nullable|string|max:100',
                'product_type' => 'nullable|string|max:100',
                'status' => 'required|in:active,draft,archived',
                'tags' => 'nullable|string',
                'seo_title' => 'nullable|string|max:255',
                'seo_description' => 'nullable|string|max:500',
                'track_inventory' => 'boolean',
                'is_active' => 'boolean',
                
                // Variants
                'variants' => 'required|array|min:1',
                'variants.*.title' => 'nullable|string|max:255',
                'variants.*.sku' => 'nullable|string|max:100',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.compare_at_price' => 'nullable|numeric|min:0',
                'variants.*.cost_price' => 'nullable|numeric|min:0',
                'variants.*.inventory_quantity' => 'required|integer|min:0',
                'variants.*.weight' => 'nullable|numeric|min:0',
                'variants.*.weight_unit' => 'nullable|string|in:g,kg,oz,lb',
                'variants.*.barcode' => 'nullable|string|max:100',
            ]);

            // Format data to match API structure
            $productData = $this->formatManualProductData($validated);
            
            // For single-variant products, default variant title to product title if empty
            if (isset($productData['variants'])) {
                foreach ($productData['variants'] as $index => $variantData) {
                    if (count($productData['variants']) == 1 && empty($productData['variants'][$index]['title'])) {
                        $productData['variants'][$index]['title'] = $productData['title'];
                    }
                }
            }
            
            // Use the same function as API to maintain consistency
            $product = ProductModel::storeProductFromApi($productData);

            return redirect()->route('products.index')
                ->with('success', 'Product created successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::error('Error creating manual product: ' . $e->getMessage());
            return back()->withInput()
                ->with('error', 'Failed to create product. Please try again.');
        }
    }

    /**
     * Show the form for editing the specified product
     */
    public function edit($id)
    {
        try {
            $product = ProductModel::with('variants')->findOrFail($id);
            return view('pages.products.edit', compact('product'));
        } catch (\Exception $e) {
            Log::error('Error fetching product for edit: ' . $e->getMessage());
            return redirect()->route('products.index')
                ->with('error', 'Product not found.');
        }
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, $id)
    {
        try {
            $product = ProductModel::findOrFail($id);

            // Allow editing for all products (manual or imported). No restrictions.

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'vendor' => 'nullable|string|max:100',
                'product_type' => 'nullable|string|max:100',
                'status' => 'required|in:active,draft,archived',
                'tags' => 'nullable|string',
                'seo_title' => 'nullable|string|max:255',
                'seo_description' => 'nullable|string|max:500',
                'track_inventory' => 'boolean',
                'is_active' => 'boolean',
                
                // Variants
                'variants' => 'required|array|min:1',
                'variants.*.id' => 'nullable|integer|exists:t_crm_prod_product_variant,id',
                'variants.*.title' => 'nullable|string|max:255',
                'variants.*.sku' => 'nullable|string|max:100',
                'variants.*.price' => 'required|numeric|min:0',
                'variants.*.compare_at_price' => 'nullable|numeric|min:0',
                'variants.*.cost_price' => 'nullable|numeric|min:0',
                'variants.*.inventory_quantity' => 'required|integer|min:0',
                'variants.*.weight' => 'nullable|numeric|min:0',
                'variants.*.weight_unit' => 'nullable|string|in:g,kg,oz,lb',
                'variants.*.barcode' => 'nullable|string|max:100',
            ]);

            // Format data to match API structure
            $productData = $this->formatManualProductData($validated);
            
            // For updates, we need to include the existing product ID
            $productData['existing_product_id'] = $product->id;
            
            // For updates, we need to include the existing product ID in variants
            if (isset($productData['variants'])) {
                foreach ($productData['variants'] as $index => $variantData) {
                    // If this is an existing variant, preserve its ID
                    if (isset($validated['variants'][$index]['id'])) {
                        $productData['variants'][$index]['id'] = $validated['variants'][$index]['id'];
                    }
                    
                    // For single-variant products, default variant title to product title if empty
                    if (count($productData['variants']) == 1 && empty($productData['variants'][$index]['title'])) {
                        $productData['variants'][$index]['title'] = $productData['title'];
                    }
                }
            }
            
            // Use the same function as API to maintain consistency
            $updatedProduct = ProductModel::storeProductFromApi($productData);

            return redirect()->route('products.index')
                ->with('success', 'Product updated successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::error('Error updating manual product: ' . $e->getMessage());
            return back()->withInput()
                ->with('error', 'Failed to update product. Please try again.');
        }
    }

    /**
     * Format manual product data to match API structure
     */
    private function formatManualProductData(array $validated): array
    {
        // Calculate price range from variants
        $prices = array_column($validated['variants'], 'price');
        $priceMin = min($prices);
        $priceMax = max($prices);
        
        // Calculate total inventory
        $totalInventory = array_sum(array_column($validated['variants'], 'inventory_quantity'));
        
        // Format variants to match API structure
        $variants = [];
        foreach ($validated['variants'] as $index => $variantData) {
            $variants[] = [
                'id' => $variantData['id'] ?? null, // For updates
                'title' => $variantData['title'],
                'sku' => $variantData['sku'] ?? null,
                'barcode' => $variantData['barcode'] ?? null,
                'price' => $variantData['price'],
                'compare_at_price' => $variantData['compare_at_price'] ?? null,
                'cost_price' => $variantData['cost_price'] ?? null,
                'inventory_quantity' => $variantData['inventory_quantity'],
                'inventory_policy' => 'deny', // Default for manual products
                'weight' => $variantData['weight'] ?? null,
                'weight_unit' => $variantData['weight_unit'] ?? 'g',
                'position' => $index + 1,
                'available' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Parse tags
        $tags = [];
        if (!empty($validated['tags'])) {
            $tags = array_map('trim', explode(',', $validated['tags']));
        }

        // Format data to match API structure
        return [
            // No Shopify IDs for manual products
            'shopify_product_id' => null,
            'shopify_handle' => null,
            
            // Basic info
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'vendor' => $validated['vendor'] ?? null,
            'product_type' => $validated['product_type'] ?? null,
            'status' => $validated['status'],
            'published_at' => $validated['status'] === 'active' ? now() : null,
            
            // Pricing
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            
            // Inventory
            'total_inventory' => $totalInventory,
            'track_inventory' => $validated['track_inventory'] ?? true,
            
            // SEO
            'seo_title' => $validated['seo_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
            
            // Media (manual products start without images)
            'featured_image' => null,
            'images' => [],
            
            // Organization
            'tags' => $tags,
            'options' => [], // Manual products can have simple options later
            
            // Sync status (manual products are not synced)
            'sync_status' => 'manual',
            'last_synced_at' => null,
            'shopify_created_at' => null,
            'shopify_updated_at' => null,
            
            // Activity
            'is_active' => $validated['is_active'] ?? true,
            
            // Variants
            'variants' => $variants
        ];
    }
}
