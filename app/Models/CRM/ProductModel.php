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
        'status',
        'published_at',
        'price_min',
        'price_max',
        'compare_at_price',
        'total_inventory',
        'track_inventory',
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
        // Removed datetime casting for Shopify dates to preserve original timezone
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

    // Relationships
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariantModel::class, 'product_id');
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
            // Check for existing product
            $existingProduct = null;
            if (isset($productData['shopify_product_id'])) {
                $existingProduct = static::where('shopify_product_id', $productData['shopify_product_id'])->first();
            }

            // Prepare product data
            $productAttributes = $productData;
            $productAttributes['created_by'] = auth()->id();
            $productAttributes['updated_by'] = auth()->id();
            
            // Extract variants
            $variants = $productAttributes['variants'] ?? [];
            unset($productAttributes['variants']);

            // Create or update product
            if ($existingProduct) {
                $existingProduct->update($productAttributes);
                $product = $existingProduct;
            } else {
                $product = static::create($productAttributes);
            }

            // Store variants
            if (!empty($variants)) {
                // Delete existing variants if updating
                if ($existingProduct) {
                    $product->variants()->delete();
                }

                $variantModels = [];
                foreach ($variants as $variant) {
                    $variantData = ProductVariantModel::mapShopifyVariant($variant);
                    $variantData['product_id'] = $product->id;
                    $variantData['created_by'] = auth()->id();
                    $variantModels[] = new ProductVariantModel($variantData);
                }
                
                $product->variants()->saveMany($variantModels);
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
