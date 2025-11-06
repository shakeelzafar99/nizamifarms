<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_product';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'shopify_product_id',
        'shopify_handle',
        'title',
        'description',
        'vendor',
        'product_type',
        'attribute_1',
        'attribute_2',
        'attribute_3',
        'status',
        'published_at',
        'price_min',
        'price_max',
        'compare_at_price',
        'total_inventory',
        'track_inventory',
        'is_lean',
        'is_lean_override',
        'seo_title',
        'seo_description',
        'featured_image',
        'images',
        'tags',
        'options',
        'shopify_created_at',
        'shopify_updated_at',
        'sync_status',
        'last_synced_at',
        'sync_error',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'total_inventory' => 'integer',
        'track_inventory' => 'boolean',
        'is_active' => 'boolean',
        'images' => 'json',
        'tags' => 'json',
        'options' => 'json'
    ];

    // Mutators to format datetime values for MySQL
    public function setPublishedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['published_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['published_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['published_at'] = null;
        }
    }
    
    public function setShopifyCreatedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['shopify_created_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['shopify_created_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['shopify_created_at'] = null;
        }
    }
    
    public function setShopifyUpdatedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['shopify_updated_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['shopify_updated_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['shopify_updated_at'] = null;
        }
    }
    
    public function setLastSyncedAtAttribute($value)
    {
        if (!$value) {
            $this->attributes['last_synced_at'] = null;
            return;
        }
        
        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['last_synced_at'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['last_synced_at'] = null;
        }
    }

    // Accessors to parse datetime fields for display
    public function getPublishedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }
    
    public function getShopifyCreatedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }
    
    public function getShopifyUpdatedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }
    
    public function getLastSyncedAtAttribute($value)
    {
        if (!$value) { return null; }
        if ($value instanceof \Carbon\Carbon) { return $value; }
        try { 
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) { 
            try { return \Carbon\Carbon::parse($value); } catch (\Exception $e2) { return $value; }
        }
    }

    // Relationships
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariantModel::class, 'product_id');
    }

    /**
     * Apply attribute group mapping to this product for a given attribute key (1..3)
     */
    public function applyAttributeFromRules(int $attributeKey): void
    {
        $title = (string) ($this->title ?? '');
        $group = \DB::table('t_crm_prod_attribute_groups')
            ->where('attribute_key', $attributeKey)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get()
            ->first(function ($g) use ($title) {
                // Explicit assignment has priority; handled in controller apply flow
                if (!empty($g->match_string)) {
                    return stripos($title, $g->match_string) !== false;
                }
                return false;
            });

        if ($group) {
            $column = 'attribute_' . $attributeKey;
            $this->{$column} = $group->group_name;
            $this->save();
        }
    }

    // Helper methods
    public function getPriceRangeAttribute(): string
    {
        if ($this->price_min == $this->price_max) {
            return number_format($this->price_min, 2);
        }
        return number_format($this->price_min, 2) . ' - ' . number_format($this->price_max, 2);
    }

    public function getMainImageAttribute(): ?string
    {
        return $this->featured_image;
    }

    /**
     * Find product by Shopify ID
     */
    public static function findByShopifyId(string $shopifyId): ?self
    {
        return static::where('shopify_product_id', $shopifyId)->first();
    }

    /**
     * Create or update product from Shopify data
     */
    public static function createOrUpdateFromShopify(array $shopifyProduct): self
    {
        $existingProduct = static::findByShopifyId($shopifyProduct['id']);
        
        if ($existingProduct) {
            // Update existing product
            \Log::info("Updating existing product: {$shopifyProduct['title']} (Shopify ID: {$shopifyProduct['id']})");
            $product = $existingProduct;
        } else {
            // Create new product
            \Log::info("Creating new product: {$shopifyProduct['title']} (Shopify ID: {$shopifyProduct['id']})");
            $product = new static();
        }
        
        // Map Shopify data to our format
        $productData = static::mapShopifyProduct($shopifyProduct);
        
        // Update or create the product
        $product->fill($productData);
        $product->save();
        
        // Handle variants
        if (isset($shopifyProduct['variants']) && is_array($shopifyProduct['variants'])) {
            static::syncVariants($product, $shopifyProduct['variants']);
        }
        
        return $product;
    }

    /**
     * Sync product variants
     */
    protected static function syncVariants(self $product, array $shopifyVariants): void
    {
        // Get existing variant IDs
        $existingVariantIds = $product->variants()->pluck('shopify_variant_id')->toArray();
        $shopifyVariantIds = array_column($shopifyVariants, 'id');
        
        // Delete variants that no longer exist in Shopify
        $variantsToDelete = array_diff($existingVariantIds, $shopifyVariantIds);
        if (!empty($variantsToDelete)) {
            $product->variants()->whereIn('shopify_variant_id', $variantsToDelete)->delete();
            \Log::info("Deleted " . count($variantsToDelete) . " variants for product: {$product->title}");
        }
        
        // Create or update variants
        foreach ($shopifyVariants as $shopifyVariant) {
            $variant = $product->variants()->where('shopify_variant_id', $shopifyVariant['id'])->first();
            
            if (!$variant) {
                $variant = new \App\Models\CRM\ProductVariantModel();
                $variant->product_id = $product->id;
            }
            
            $variantData = \App\Models\CRM\ProductVariantModel::mapShopifyVariant($shopifyVariant);
            $variant->fill($variantData);
            $variant->save();
        }
    }

    /**
     * Map Shopify product data to our format
     */
    public static function mapShopifyProduct(array $shopifyProduct): array
    {
        // Calculate price range from variants
        $variants = $shopifyProduct['variants'] ?? [];
        $prices = array_column($variants, 'price');
        $priceMin = !empty($prices) ? min($prices) : 0;
        $priceMax = !empty($prices) ? max($prices) : 0;

        // Calculate total inventory
        $totalInventory = 0;
        foreach ($variants as $variant) {
            $totalInventory += $variant['inventory_quantity'] ?? 0;
        }

        // Extract images
        $images = [];
        $featuredImage = null;
        if (!empty($shopifyProduct['images'])) {
            foreach ($shopifyProduct['images'] as $image) {
                $images[] = $image['src'];
            }
            $featuredImage = $shopifyProduct['image']['src'] ?? $images[0] ?? null;
        }

        $productData = [
            'shopify_product_id' => $shopifyProduct['id'],
            'shopify_handle' => $shopifyProduct['handle'] ?? null,
            'title' => $shopifyProduct['title'],
            'description' => $shopifyProduct['body_html'] ?? null,
            'vendor' => $shopifyProduct['vendor'] ?? null,
            'product_type' => $shopifyProduct['product_type'] ?? null,
            'status' => $shopifyProduct['status'] ?? 'active',
            'published_at' => $shopifyProduct['published_at'] ?? null,
            
            // Pricing
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            
            // Inventory
            'total_inventory' => $totalInventory,
            
            // Media
            'featured_image' => $featuredImage,
            'images' => $images,
            
            // SEO
            'seo_title' => $shopifyProduct['seo_title'] ?? null,
            'seo_description' => $shopifyProduct['seo_description'] ?? null,
            
            // Organization
            'tags' => !empty($shopifyProduct['tags']) ? explode(',', $shopifyProduct['tags']) : [],
            'options' => $shopifyProduct['options'] ?? [],
            
            // Shopify metadata
            'shopify_created_at' => $shopifyProduct['created_at'] ?? null,
            'shopify_updated_at' => $shopifyProduct['updated_at'] ?? null,
            
            // Sync status
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            
            // Variants data for separate processing
            'variants' => $variants
        ];

        return $productData;
    }

    /**
     * Store product with variants from Shopify API
     */
    public static function storeProductFromApi(array $productData): self
    {
        \DB::beginTransaction();
        
        try {
            // Check for price-only update flag
            $priceOnlyUpdate = $productData['_price_only_update'] ?? false;
            unset($productData['_price_only_update']); // Remove flag from data
            
            // Check for existing product
            $existingProduct = null;
            
            // First check if we have an explicit existing product ID (for manual updates)
            if (isset($productData['existing_product_id'])) {
                $existingProduct = static::find($productData['existing_product_id']);
                unset($productData['existing_product_id']); // Remove from data to avoid DB issues
            }
            // Then check for Shopify/WooCommerce products by their platform ID
            elseif (isset($productData['shopify_product_id'])) {
                $existingProduct = static::where('shopify_product_id', $productData['shopify_product_id'])->first();
            }

            // Prepare product data
            $productAttributes = $productData;
            $productAttributes['created_by'] = auth()->check() ? auth()->id() : null;
            $productAttributes['updated_by'] = auth()->check() ? auth()->id() : null;
            
            // Extract variants
            $variants = $productAttributes['variants'] ?? [];
            unset($productAttributes['variants']);

            // Create or update product
            if ($existingProduct) {
                if ($priceOnlyUpdate) {
                    // For price-only updates, only update specific fields
                    $updateFields = [
                        'updated_by' => $productAttributes['updated_by'],
                        'updated_at' => now(),
                    ];
                    
                    // Only update sync-related fields if they exist
                    if (isset($productAttributes['sync_status'])) {
                        $updateFields['sync_status'] = $productAttributes['sync_status'];
                    }
                    if (isset($productAttributes['last_synced_at'])) {
                        $updateFields['last_synced_at'] = $productAttributes['last_synced_at'];
                    }
                    
                    $existingProduct->update($updateFields);
                    \Log::info("Price-only update for product: {$existingProduct->title}");
                } else {
                    // Full update - update all fields except categories/attributes
                    $existingProduct->update($productAttributes);
                }
                $product = $existingProduct;
            } else {
                $product = static::create($productAttributes);
            }

            // Store variants
            if (!empty($variants)) {
                if ($existingProduct && $priceOnlyUpdate) {
                    // For price-only updates, match variants by shopify_variant_id or SKU and update prices only
                    foreach ($variants as $variantData) {
                        $mappedVariant = ProductVariantModel::mapShopifyVariant($variantData);
                        $variantId = $mappedVariant['shopify_variant_id'] ?? null;
                        $sku = $mappedVariant['sku'] ?? null;
                        
                        $existingVariant = null;
                        
                        // First try to match by shopify_variant_id (WooCommerce/Shopify variant ID)
                        if ($variantId) {
                            $existingVariant = $product->variants()->where('shopify_variant_id', $variantId)->first();
                        }
                        
                        // If not found and SKU exists, try matching by SKU
                        if (!$existingVariant && $sku) {
                            $existingVariant = $product->variants()->where('sku', $sku)->first();
                        }
                        
                        if ($existingVariant) {
                            // Update only price-related fields
                            $priceUpdateFields = [
                                'price' => $mappedVariant['price'] ?? $existingVariant->price,
                                'compare_at_price' => $mappedVariant['compare_at_price'] ?? $existingVariant->compare_at_price,
                                'cost_price' => $mappedVariant['cost_price'] ?? $existingVariant->cost_price,
                                'updated_at' => now(),
                            ];
                            $existingVariant->update($priceUpdateFields);
                            \Log::info("Updated prices for variant ID: {$variantId}, SKU: {$sku}");
                        } else {
                            // New variant - add it
                            $mappedVariant['product_id'] = $product->id;
                            $mappedVariant['created_by'] = auth()->check() ? auth()->id() : null;
                            ProductVariantModel::create($mappedVariant);
                            \Log::info("Added new variant ID: {$variantId}, SKU: {$sku}");
                        }
                    }
                } else {
                    // Full update or new product - delete and recreate variants
                    if ($existingProduct) {
                        $product->variants()->delete();
                    }

                    $variantModels = [];
                    foreach ($variants as $variant) {
                        $variantData = ProductVariantModel::mapShopifyVariant($variant);
                        $variantData['product_id'] = $product->id;
                        $variantData['created_by'] = auth()->check() ? auth()->id() : null;
                        $variantModels[] = new ProductVariantModel($variantData);
                    }
                    
                    $product->variants()->saveMany($variantModels);
                }
            }

            // Auto-assign optional attribute labels by match rules stored in JSON (title contains)
            // Skip this for price-only updates to preserve existing categories
            if (!$priceOnlyUpdate || !$existingProduct) {
                try {
                    $title = (string) ($product->title ?? '');
                    $labelsPath = storage_path('app/private/attribute_auto_rules.json');
                    if (is_file($labelsPath) && $title !== '') {
                        $rules = json_decode(file_get_contents($labelsPath), true) ?: [];
                        foreach ([1,2,3] as $key) {
                            $column = 'attribute_' . $key;
                            if (!empty($rules[(string)$key]) && empty($product->{$column})) {
                                foreach ($rules[(string)$key] as $rule) { // assume already sorted by priority desc
                                    $needle = (string) ($rule['match'] ?? '');
                                    $groupName = (string) ($rule['group'] ?? '');
                                    if ($needle !== '' && $groupName !== '' && stripos($title, $needle) !== false) {
                                        $product->{$column} = $groupName;
                                        break;
                                    }
                                }
                            }
                        }
                        $product->save();
                    }
                } catch (\Throwable $e) {
                    // fail silently to avoid impacting core flows
                }
            }

            \DB::commit();
            return $product->load('variants');
            
        } catch (\Exception $e) {
            \DB::rollBack();
            
            // Mark product as sync error
            if (isset($product)) {
                $product->update([
                    'sync_status' => 'error',
                    'sync_error' => $e->getMessage()
                ]);
            }
            
            throw $e;
        }
    }
}
