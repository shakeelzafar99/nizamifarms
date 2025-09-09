<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_product_variant';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'product_id',
        'shopify_variant_id',
        'title',
        'sku',
        'barcode',
        'price',
        'compare_at_price',
        'cost_price',
        'inventory_quantity',
        'inventory_policy',
        'weight',
        'weight_unit',
        'option1',
        'option2',
        'option3',
        'available',
        'position',
        'shopify_created_at',
        'shopify_updated_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'weight' => 'decimal:2',
        'inventory_quantity' => 'integer',
        'position' => 'integer',
        'available' => 'boolean'
    ];

    // Mutators to format datetime values for MySQL
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

    // Accessors to parse datetime fields for display
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

    // Relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
    }

    // Helper methods
    public function getVariantTitleAttribute(): string
    {
        $options = array_filter([$this->option1, $this->option2, $this->option3]);
        return !empty($options) ? implode(' / ', $options) : 'Default';
    }

    public function getFormattedWeightAttribute(): string
    {
        if (!$this->weight) return '';
        return $this->weight . ' ' . $this->weight_unit;
    }

    public function isInStockAttribute(): bool
    {
        return $this->available && $this->inventory_quantity > 0;
    }

    /**
     * Map Shopify variant data to our format
     */
    public static function mapShopifyVariant(array $shopifyVariant): array
    {
        return [
            'shopify_variant_id' => $shopifyVariant['id'],
            'title' => $shopifyVariant['title'] ?? null,
            'sku' => $shopifyVariant['sku'] ?? null,
            'barcode' => $shopifyVariant['barcode'] ?? null,
            
            // Pricing
            'price' => $shopifyVariant['price'] ?? 0,
            'compare_at_price' => $shopifyVariant['compare_at_price'] ?? null,
            
            // Inventory
            'inventory_quantity' => $shopifyVariant['inventory_quantity'] ?? 0,
            'inventory_policy' => $shopifyVariant['inventory_policy'] ?? 'deny',
            
            // Physical properties
            'weight' => $shopifyVariant['weight'] ?? null,
            'weight_unit' => $shopifyVariant['weight_unit'] ?? 'g',
            
            // Options
            'option1' => $shopifyVariant['option1'] ?? null,
            'option2' => $shopifyVariant['option2'] ?? null,
            'option3' => $shopifyVariant['option3'] ?? null,
            
            // Status
            'available' => $shopifyVariant['available'] ?? true,
            'position' => $shopifyVariant['position'] ?? 1,
            
            // Shopify metadata
            'shopify_created_at' => $shopifyVariant['created_at'] ?? null,
            'shopify_updated_at' => $shopifyVariant['updated_at'] ?? null
        ];
    }
}
