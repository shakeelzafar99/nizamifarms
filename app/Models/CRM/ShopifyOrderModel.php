<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ShopifyOrderModel extends BaseModel
{
    use HasFactory, Notifiable;

    protected $table = 't_crm_shopify_order';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'customer_id',
        'external_source',
        'external_id',
        'external_customer_id',
        'order_number',
        'order_status',
        'order_date',
        'name',
        'currency',
        'contact_email',
        'subtotal_price',
        'discount_total',
        'shipping_total',
        'total_tax',
        'tip_amount',
        'total_price',
        'total_weight',
        'address_first_name',
        'address_last_name',
        'address_company',
        'address_email',
        'address_phone',
        'address_line1',
        'address_line2',
        'address_city',
        'address_province',
        'address_postal_code',
        'address_country',
        'coupon_code',
        'payment_method',
        'note',
        'raw_products_text',
        'converted',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'subtotal_price' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'total_price' => 'decimal:2',
        'total_weight' => 'integer',
        'converted' => 'boolean'
    ];

    /**
     * The relationships that should always be loaded with the model when serializing
     */
    protected $with = [];

    /**
     * The accessors to append to the model's array form
     */
    protected $appends = ['customer_name', 'customer_phone', 'customer_address', 'items_count', 'items_summary'];

    /**
     * Override toArray to format order_date consistently
     */
    public function toArray()
    {
        $array = parent::toArray();
        
        // Ensure line_items are included if lineItems relationship is loaded
        if ($this->relationLoaded('lineItems')) {
            $array['line_items'] = $this->lineItems->toArray();
        }
        
        // Format order_date to be consistent (remove timezone conversion)
        if (isset($array['order_date']) && $this->order_date) {
            $array['order_date'] = $this->getRawOriginal('order_date') ?: $this->attributes['order_date'];
        }
        
        return $array;
    }

    /**
     * Normalize incoming order_date to MySQL format (Y-m-d H:i:s)
     * Accepts ISO 8601 strings like 2025-09-15T08:39:18+05:00
     */
    public function setOrderDateAttribute($value): void
    {
        if (!$value || trim((string) $value) === '') {
            return;
        }

        try {
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['order_date'] = $date->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            // Keep attribute unset to avoid SQL errors, but log for visibility
            \Log::warning('ShopifyOrderModel: Invalid order_date format', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerModel::class, 'customer_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(ShopifyOrderLineItemModel::class, 'order_id');
    }

    /**
     * Discounts relationship - Shopify orders typically don't have detailed discount breakdown
     * as they are stored at order level (discount_total field)
     * This relationship exists for compatibility but will usually be empty
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscountModel::class, 'order_id')->orderBy('display_order')->orderBy('id');
    }

    /**
     * Accessor methods for mobile app compatibility
     * These provide the same data fields as OrderModel for consistent API responses
     */
    public function getCustomerNameAttribute(): ?string
    {
        // PRIORITY 1: order.name field (customer name from address - same as web app logic)
        if ($this->name && trim($this->name)) {
            return trim($this->name);
        }
        
        // PRIORITY 2: customer relationship full_name
        if ($this->relationLoaded('customer') && $this->customer) {
            if ($this->customer->full_name && trim($this->customer->full_name)) {
                return trim($this->customer->full_name);
            }
            // Also try customer->name as fallback
            if ($this->customer->name && trim($this->customer->name)) {
                return trim($this->customer->name);
            }
        }
        
        // PRIORITY 3: Fallback to address fields
        $firstName = trim($this->address_first_name ?? '');
        $lastName = trim($this->address_last_name ?? '');
        
        if ($firstName && $lastName) {
            return "{$firstName} {$lastName}";
        }
        
        return $firstName ?: $lastName ?: null;
    }

    public function getCustomerPhoneAttribute(): ?string
    {
        // First try to get from relationship if loaded
        if ($this->relationLoaded('customer') && $this->customer) {
            return $this->customer->phone;
        }
        
        // Fallback: use address phone
        return $this->address_phone;
    }

    public function getCustomerAddressAttribute(): ?string
    {
        // First try to get from relationship if loaded
        if ($this->relationLoaded('customer') && $this->customer) {
            return $this->customer->address;
        }
        
        // Fallback: combine address fields
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->address_city,
            $this->address_province,
            $this->address_postal_code
        ]);
        
        return implode(', ', $parts) ?: null;
    }

    public function getItemsCountAttribute(): int
    {
        // If line items are loaded, count them
        if ($this->relationLoaded('lineItems')) {
            return $this->lineItems->count();
        }
        
        // Fallback: count from database
        return $this->lineItems()->count();
    }

    public function getItemsSummaryAttribute(): string
    {
        // If line items are loaded, build summary
        if ($this->relationLoaded('lineItems')) {
            $items = $this->lineItems->map(function($item) {
                $qty = $item->quantity ?? 1;
                $name = $item->title ?? $item->product_name ?? 'Item';
                return "{$name} × {$qty}";
            })->take(3)->implode(', ');
            
            $remaining = $this->lineItems->count() - 3;
            if ($remaining > 0) {
                $items .= " + {$remaining} more";
            }
            
            return $items;
        }
        
        // Fallback: use raw products text or count
        return $this->raw_products_text ?: ($this->items_count . ' items');
    }
}


