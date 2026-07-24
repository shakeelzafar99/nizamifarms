<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_customer';
    protected $primaryKey = 'id';
    public $timestamps = true;

    /**
     * Sentinel stored in verified_location_saved_by when the CUSTOMER set the
     * pin from the customer app (not an ops/staff user). The column is a
     * signed INT, so this negative value is safe and never collides with a
     * real t_sys_user id. verifierLabel() renders it as "Customer".
     */
    public const VERIFIED_PIN_CUSTOMER_ID = -9999;

    protected $fillable = [
        'phone',
        'phone_normalized',
        'phone_original',
        'first_name',
        'last_name',
        'company',
        'customer_type',
        // Mobile-app migration tag: set once the customer places an app-origin
        // order (Shopify source_name ios_app/android_app). Drives campaign
        // exclusion + the HQ order-source split. See [[shopify source channel]].
        'is_on_mobile_app',
        'mobile_app_first_seen_at',
        'email',
        'address1',
        'address2',
        'city',
        'province',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'verified_location_url',
        'verified_location_saved_by',
        'verified_location_saved_at',
        // Verified-pin lock (Jul-2026): once a pin exists it is locked against
        // RIDER edits; a store/web user grants a time-boxed unlock window.
        'verified_pin_unlocked_until',
        'verified_pin_unlocked_by',
        // Geocoded address coordinates (auto-generated from address)
        'geocoded_latitude',
        'geocoded_longitude',
        'geocoded_at',
        'external_customer_ids',
        'first_order_date',
        'first_delivery_date',
        'last_order_date',
        'last_delivery_date',
        'last_delivered_order_id',
        'total_orders',
        'total_spent',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
        // Merge support fields
        'merged_into_customer_id',
        'merged_at',
        'merged_by'
    ];

    protected $casts = [
        'external_customer_ids' => 'json',
        'total_spent' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geocoded_latitude' => 'decimal:7',
        'geocoded_longitude' => 'decimal:7',
        'geocoded_at' => 'datetime',
        'verified_pin_unlocked_until' => 'datetime',
        'first_delivery_date' => 'datetime',
        'last_delivery_date' => 'datetime',
        'is_active' => 'boolean',
        'total_orders' => 'integer',
        'is_on_mobile_app' => 'boolean',
        'mobile_app_first_seen_at' => 'datetime'
    ];

    // Customer type constants. Shop customers settle online invoices via
    // incremental payments instead of a single full-invoice approval.
    public const TYPE_REGULAR = 'regular';
    public const TYPE_SHOP    = 'shop';

    /** True when this customer is a shop (payment-cycle billing for online orders). */
    public function isShop(): bool
    {
        return $this->customer_type === self::TYPE_SHOP;
    }

    // Verified-pin lock (Jul-2026). Once a customer HAS a verified pin, riders
    // may not overwrite it — a rider once moved a pin after delivery and every
    // delivered-orders report silently re-judged past deliveries against the new
    // spot (NF-18834). A store/web user grants a time-boxed unlock; the rider
    // then gets ONE save inside the window. The window is the backstop for
    // "unlocked but the rider never saved" — it just expires.
    public const VERIFIED_PIN_UNLOCK_MINUTES = 360; // 6h grant window

    /** True while an unlock grant is live (rider may save the pin right now). */
    public function verifiedPinUnlockActive(): bool
    {
        return $this->verified_pin_unlocked_until !== null
            && $this->verified_pin_unlocked_until->isFuture();
    }

    /**
     * True when a RIDER write of the verified pin must be refused: a pin already
     * exists AND no unlock grant is live. A customer with no pin yet (first-ever
     * drop) is never locked — that is the rider's normal daily workflow.
     */
    public function isVerifiedPinLocked(): bool
    {
        $hasPin = $this->latitude !== null && $this->longitude !== null;
        return $hasPin && !$this->verifiedPinUnlockActive();
    }

    // Relationships
    public function orders(): HasMany
    {
        return $this->hasMany(OrderModel::class, 'customer_id');
    }

    // Helper methods
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function addExternalCustomerId(string $platform, string $externalId): void
    {
        $ids = $this->external_customer_ids ?? [];
        $ids[$platform] = $externalId;
        $this->external_customer_ids = $ids;
        $this->save();
    }

    public function getExternalCustomerId(string $platform): ?string
    {
        return $this->external_customer_ids[$platform] ?? null;
    }

    // Mutators to format datetime values for MySQL
    public function setFirstOrderDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['first_order_date'] = null;
            return;
        }
        
        try {
            // Parse the date and convert to local time for MySQL storage
            $date = \Carbon\Carbon::parse($value);
            // Convert to local time (remove timezone offset) for MySQL storage
            $this->attributes['first_order_date'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['first_order_date'] = null;
        }
    }
    
    public function setLastOrderDateAttribute($value)
    {
        if (!$value) {
            $this->attributes['last_order_date'] = null;
            return;
        }
        
        try {
            // Parse the date and convert to local time for MySQL storage
            $date = \Carbon\Carbon::parse($value);
            // Convert to local time (remove timezone offset) for MySQL storage
            $this->attributes['last_order_date'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $this->attributes['last_order_date'] = null;
        }
    }

    /**
     * Get the first order date as a Carbon instance (preserves original timezone)
     */
    public function getFirstOrderDateAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        if ($value instanceof \Carbon\Carbon) {
            return $value;
        }
        
        try {
            // Create Carbon instance and explicitly set it as local timezone
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) {
            // If parsing fails, try the original method
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Exception $e2) {
                return $value;
            }
        }
    }

    /**
     * Get the last order date as a Carbon instance (preserves original timezone)
     */
    public function getLastOrderDateAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        if ($value instanceof \Carbon\Carbon) {
            return $value;
        }
        
        try {
            // Create Carbon instance and explicitly set it as local timezone
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Exception $e) {
            // If parsing fails, try the original method
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Exception $e2) {
                return $value;
            }
        }
    }

    /**
     * Map a verified_location_saved_by id to a human display label.
     * Returns "Customer" for the customer-app sentinel, the staff member's
     * fullname for a real user id, or null when unset.
     */
    public static function verifierLabel($savedById): ?string
    {
        if ($savedById === null || $savedById === '') {
            return null;
        }
        if ((int) $savedById === self::VERIFIED_PIN_CUSTOMER_ID) {
            return 'Customer';
        }
        return \DB::table('t_sys_user')->where('id', $savedById)->value('fullname');
    }

    /**
     * Normalize phone number to 10-digit format
     * Extracts last 10 digits from any phone number format
     */
    public static function normalizePhone(string $phone): array
    {
        if (empty($phone)) {
            return [
                'normalized' => '',
                'original' => ''
            ];
        }

        // Remove all non-digits
        $digits = preg_replace('/\D/', '', $phone);
        
        // Take last 10 digits (Pakistan mobile format)
        $normalized = substr($digits, -10);
        
        // Ensure we have exactly 10 digits
        if (strlen($normalized) < 10) {
            $normalized = str_pad($normalized, 10, '0', STR_PAD_LEFT);
        }
        
        return [
            'normalized' => $normalized,
            'original' => $phone
        ];
    }

    /**
     * Find or create customer by phone number
     * Update first/last order dates and statistics
     */
    public static function findOrCreateByPhone(string $phone, array $orderData, string $orderDate, float $orderTotal, bool $isUpdate = false): self
    {
        if (!$phone) {
            throw new \InvalidArgumentException('Phone number is required');
        }

        // Normalize phone number
        $phoneData = static::normalizePhone($phone);
        $normalizedPhone = $phoneData['normalized'];
        $originalPhone = $phoneData['original'];
        
        // Check if this is a Shopify order (exclude from statistics)
        $isShopifyOrder = isset($orderData['external_source']) && strtolower($orderData['external_source']) === 'shopify';

        // Find customer by normalized phone
        // ⭐ Prefer non-merged customers first; if only a merged one exists, follow the merge chain
        $customer = static::where('phone_normalized', $normalizedPhone)
            ->whereNull('merged_into_customer_id')
            ->first();
        
        if (!$customer) {
            // Check if there's a merged customer with this phone - follow the chain to the primary
            $mergedCustomer = static::where('phone_normalized', $normalizedPhone)
                ->whereNotNull('merged_into_customer_id')
                ->first();
            
            if ($mergedCustomer) {
                // Follow the merge chain to the primary customer (handle multi-level merges)
                $primaryId = $mergedCustomer->merged_into_customer_id;
                $maxDepth = 5; // Safety limit for merge chains
                $visited = [];
                while ($maxDepth-- > 0) {
                    if (in_array($primaryId, $visited)) break; // Circular reference protection
                    $visited[] = $primaryId;
                    $primary = static::find($primaryId);
                    if (!$primary) break;
                    if (!$primary->merged_into_customer_id) {
                        $customer = $primary;
                        break;
                    }
                    $primaryId = $primary->merged_into_customer_id;
                }
            }
        }
        
        if (!$customer) {
            // Create new customer
            $customerData = [
                'phone' => $normalizedPhone, // Keep for backward compatibility
                'phone_normalized' => $normalizedPhone,
                'phone_original' => $originalPhone,
                'first_name' => $orderData['address_first_name'] ?? null,
                'last_name' => $orderData['address_last_name'] ?? null,
                'company' => $orderData['address_company'] ?? null,
                'email' => $orderData['address_email'] ?? null,
                'address1' => $orderData['address_line1'] ?? null,
                'address2' => $orderData['address_line2'] ?? null,
                'city' => $orderData['address_city'] ?? null,
                'province' => $orderData['address_province'] ?? null,
                'postal_code' => $orderData['address_postal_code'] ?? null,
                'country' => $orderData['address_country'] ?? 'Pakistan',
                'created_by' => auth()->check() ? auth()->id() : null
            ];
            
            // Only add statistics if NOT a Shopify order
            if (!$isShopifyOrder) {
                $customerData['first_order_date'] = $orderDate;
                $customerData['last_order_date'] = $orderDate;
                $customerData['total_orders'] = 1;
                $customerData['total_spent'] = $orderTotal;
            } else {
                // For Shopify orders, set default values (will be calculated from non-Shopify orders)
                $customerData['first_order_date'] = null;
                $customerData['last_order_date'] = null;
                $customerData['total_orders'] = 0;
                $customerData['total_spent'] = 0.00;
            }
            
            $customer = static::create($customerData);

            // Add external customer ID if provided
            if (isset($orderData['external_customer_id']) && isset($orderData['external_source'])) {
                $customer->addExternalCustomerId($orderData['external_source'], $orderData['external_customer_id']);
            }
            } else {
            // Update existing customer
            $updates = [];
            
            // Only update statistics if NOT a Shopify order
            if (!$isShopifyOrder) {
                // Update order dates
                if (!$customer->first_order_date || $orderDate < $customer->first_order_date) {
                    $updates['first_order_date'] = $orderDate;
                }
                if (!$customer->last_order_date || $orderDate > $customer->last_order_date) {
                    $updates['last_order_date'] = $orderDate;
                }
                
                // Update statistics (only increment for new orders, not updates)
                if (!$isUpdate) {
                    $updates['total_orders'] = $customer->total_orders + 1;
                    $updates['total_spent'] = $customer->total_spent + $orderTotal;
                }
            }
            $updates['updated_by'] = auth()->check() ? auth()->id() : null;
            
            // Update contact info if this is the most recent order
            if (!$customer->last_order_date || $orderDate >= $customer->last_order_date) {
                $updates['email'] = $orderData['address_email'] ?? $customer->email;
                $updates['first_name'] = $orderData['address_first_name'] ?? $customer->first_name;
                $updates['last_name'] = $orderData['address_last_name'] ?? $customer->last_name;
                $updates['company'] = $orderData['address_company'] ?? $customer->company;
                $updates['address1'] = $orderData['address_line1'] ?? $customer->address1;
                $updates['address2'] = $orderData['address_line2'] ?? $customer->address2;
                $updates['city'] = $orderData['address_city'] ?? $customer->city;
                $updates['province'] = $orderData['address_province'] ?? $customer->province;
                $updates['postal_code'] = $orderData['address_postal_code'] ?? $customer->postal_code;
                $updates['country'] = $orderData['address_country'] ?? $customer->country;
                
                // Update phone_original if this is a different format
                if ($customer->phone_original !== $originalPhone) {
                    $updates['phone_original'] = $originalPhone;
                }
            }
            
            $customer->update($updates);

            // Update external customer ID if provided
            if (isset($orderData['external_customer_id']) && isset($orderData['external_source'])) {
                $customer->addExternalCustomerId($orderData['external_source'], $orderData['external_customer_id']);
            }
        }
        
        return $customer;
    }

    /**
     * Recalculate customer statistics based on actual orders
     * Excludes Shopify orders from statistics as per business logic
     * Useful for fixing any inconsistencies
     */
    public function recalculateStatistics(): void
    {
        // Only count non-Shopify orders for statistics
        $orders = $this->orders()
                      ->where(function($query) {
                          $query->where('external_source', '!=', 'shopify')
                                ->orWhereNull('external_source');
                      })
                      ->orderBy('order_date')
                      ->get();
        
        if ($orders->count() > 0) {
            $this->update([
                'first_order_date' => $orders->first()->order_date,
                'last_order_date' => $orders->last()->order_date,
                'total_orders' => $orders->count(),
                'total_spent' => $orders->sum('total_price'),
                'updated_by' => auth()->check() ? auth()->id() : null
            ]);
        } else {
            $this->update([
                'first_order_date' => null,
                'last_order_date' => null,
                'total_orders' => 0,
                'total_spent' => 0,
                'updated_by' => auth()->check() ? auth()->id() : null
            ]);
        }
    }
}