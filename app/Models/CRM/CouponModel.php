<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;

class CouponModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_shopify_coupon';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'shopify_discount_id',
        'title',
        'code',
        'discount_type',
        'value_type',
        'value',
        'minimum_amount',
        'usage_limit',
        'usage_count',
        'customer_selection',
        'target_type',
        'target_selection',
        'allocation_method',
        'allocation_limit',
        'once_per_customer',
        'starts_at',
        'ends_at',
        'status',
        'prerequisite_product_ids',
        'prerequisite_variant_ids',
        'prerequisite_collection_ids',
        'prerequisite_customer_ids',
        'entitled_product_ids',
        'entitled_variant_ids',
        'entitled_collection_ids',
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
        'value' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
        'allocation_limit' => 'integer',
        'once_per_customer' => 'boolean',
        'is_active' => 'boolean',
        'prerequisite_product_ids' => 'json',
        'prerequisite_variant_ids' => 'json',
        'prerequisite_collection_ids' => 'json',
        'prerequisite_customer_ids' => 'json',
        'entitled_product_ids' => 'json',
        'entitled_variant_ids' => 'json',
        'entitled_collection_ids' => 'json',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'shopify_created_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'last_synced_at' => 'datetime'
    ];

    // Mutators to format datetime values for MySQL
    public function setStartsAtAttribute($value)
    {
        $this->attributes['starts_at'] = $this->formatDateForMySQL($value);
    }

    public function setEndsAtAttribute($value)
    {
        $this->attributes['ends_at'] = $this->formatDateForMySQL($value);
    }

    public function setShopifyCreatedAtAttribute($value)
    {
        $this->attributes['shopify_created_at'] = $this->formatDateForMySQL($value);
    }

    public function setShopifyUpdatedAtAttribute($value)
    {
        $this->attributes['shopify_updated_at'] = $this->formatDateForMySQL($value);
    }

    public function setLastSyncedAtAttribute($value)
    {
        $this->attributes['last_synced_at'] = $this->formatDateForMySQL($value);
    }

    /**
     * Format date for MySQL storage
     */
    private function formatDateForMySQL($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            if ($value instanceof \DateTime) {
                return $value->format('Y-m-d H:i:s');
            }

            $date = new \DateTime($value);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            \Log::warning('Invalid date format for coupon model: ' . $value);
            return null;
        }
    }

    /**
     * Find coupon by Shopify discount ID
     */
    public static function findByShopifyId($shopifyDiscountId): ?self
    {
        if (empty($shopifyDiscountId)) {
            return null;
        }

        return static::where('shopify_discount_id', $shopifyDiscountId)->first();
    }

    /**
     * Get active coupons
     */
    public static function getActiveCoupons()
    {
        return static::where('is_active', true)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->orderBy('title')
            ->get();
    }

    /**
     * Get coupon by code
     */
    public static function findByCode($code): ?self
    {
        if (empty($code)) {
            return null;
        }

        return static::where('code', $code)
            ->where('is_active', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Check if coupon is currently valid
     */
    public function isValid(): bool
    {
        if (!$this->is_active || $this->status !== 'active') {
            return false;
        }

        $now = now();

        // Check start date
        if ($this->starts_at && $this->starts_at > $now) {
            return false;
        }

        // Check end date
        if ($this->ends_at && $this->ends_at < $now) {
            return false;
        }

        // Check usage limit
        if ($this->usage_limit && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    /**
     * Map Shopify price rule data to our format
     */
    public static function mapShopifyPriceRule(array $priceRule): array
    {
        // Determine discount type and value
        $discountType = 'percentage';
        $valueType = 'percentage';
        $value = 0;

        if ($priceRule['value_type'] === 'percentage') {
            $discountType = 'percentage';
            $valueType = 'percentage';
            $value = abs($priceRule['value']); // Shopify sends negative values
        } elseif ($priceRule['value_type'] === 'fixed_amount') {
            $discountType = 'fixed_amount';
            $valueType = 'fixed_amount';
            $value = abs($priceRule['value']);
        }

        // Determine status
        $status = 'active';
        $now = now();
        
        if (isset($priceRule['starts_at']) && $priceRule['starts_at'] && $now < new \DateTime($priceRule['starts_at'])) {
            $status = 'scheduled';
        } elseif (isset($priceRule['ends_at']) && $priceRule['ends_at'] && $now > new \DateTime($priceRule['ends_at'])) {
            $status = 'expired';
        }

        return [
            'shopify_discount_id' => $priceRule['id'],
            'title' => $priceRule['title'] ?? 'Untitled Discount',
            'discount_type' => $discountType,
            'value_type' => $valueType,
            'value' => $value,
            'minimum_amount' => $priceRule['prerequisite_subtotal_range']['greater_than_or_equal_to'] ?? null,
            'usage_limit' => $priceRule['usage_limit'] ?? null,
            'customer_selection' => $priceRule['customer_selection'] ?? 'all',
            'target_type' => $priceRule['target_type'] ?? 'line_item',
            'target_selection' => $priceRule['target_selection'] ?? 'all',
            'allocation_method' => $priceRule['allocation_method'] ?? 'across',
            'allocation_limit' => $priceRule['allocation_limit'] ?? null,
            'once_per_customer' => $priceRule['once_per_customer'] ?? false,
            'starts_at' => $priceRule['starts_at'] ?? null,
            'ends_at' => $priceRule['ends_at'] ?? null,
            'status' => $status,
            'prerequisite_product_ids' => $priceRule['prerequisite_product_ids'] ?? null,
            'prerequisite_variant_ids' => $priceRule['prerequisite_variant_ids'] ?? null,
            'prerequisite_collection_ids' => $priceRule['prerequisite_collection_ids'] ?? null,
            'prerequisite_customer_ids' => $priceRule['prerequisite_customer_ids'] ?? null,
            'entitled_product_ids' => $priceRule['entitled_product_ids'] ?? null,
            'entitled_variant_ids' => $priceRule['entitled_variant_ids'] ?? null,
            'entitled_collection_ids' => $priceRule['entitled_collection_ids'] ?? null,
            'shopify_created_at' => $priceRule['created_at'] ?? null,
            'shopify_updated_at' => $priceRule['updated_at'] ?? null,
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'is_active' => true
        ];
    }

    /**
     * Create or update coupon from Shopify data
     */
    public static function createOrUpdateFromShopify(array $priceRule, ?string $discountCode = null): self
    {
        $existingCoupon = static::findByShopifyId($priceRule['id']);
        
        if ($existingCoupon) {
            // Update existing coupon
            \Log::info("Updating existing coupon: {$priceRule['title']} (Shopify ID: {$priceRule['id']})");
            $coupon = $existingCoupon;
        } else {
            // Create new coupon
            \Log::info("Creating new coupon: {$priceRule['title']} (Shopify ID: {$priceRule['id']})");
            $coupon = new static();
        }
        
        // Map Shopify data to our format
        $couponData = static::mapShopifyPriceRule($priceRule);
        
        // Add discount code if provided
        if ($discountCode) {
            $couponData['code'] = $discountCode;
        }
        
        // Update or create the coupon
        $coupon->fill($couponData);
        $coupon->save();
        
        return $coupon;
    }

    /**
     * Store coupon from Shopify API
     */
    public static function storeCouponFromApi(array $priceRule, ?string $discountCode = null): self
    {
        \DB::beginTransaction();
        
        try {
            // Check for existing coupon
            $existingCoupon = static::findByShopifyId($priceRule['id']);

            // Prepare coupon data
            $couponData = static::mapShopifyPriceRule($priceRule);
            $couponData['created_by'] = auth()->check() ? auth()->id() : null;
            $couponData['updated_by'] = auth()->check() ? auth()->id() : null;
            
            // Add discount code if provided
            if ($discountCode) {
                $couponData['code'] = $discountCode;
            }

            // Create or update coupon
            if ($existingCoupon) {
                $existingCoupon->update($couponData);
                $coupon = $existingCoupon;
            } else {
                $coupon = static::create($couponData);
            }

            \DB::commit();
            
            \Log::info('Successfully stored coupon from Shopify API', [
                'coupon_id' => $coupon->id,
                'shopify_discount_id' => $priceRule['id'],
                'title' => $priceRule['title'] ?? 'Untitled',
                'code' => $discountCode
            ]);
            
            return $coupon;
            
        } catch (\Exception $e) {
            \DB::rollback();
            
            \Log::error('Failed to store coupon from Shopify API: ' . $e->getMessage(), [
                'shopify_discount_id' => $priceRule['id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}
