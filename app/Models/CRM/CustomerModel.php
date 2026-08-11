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
        // Aug-2026: remembered payment choice ('cash'|'online'|null) applied as
        // the pre-selection on both order forms. See normalizePaymentMethod().
        'default_payment_method',
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
        // Aug-2026: the rider's "please unlock this" request behind the store /
        // web banners. Cleared by every action that resolves the lock.
        'verified_pin_unlock_requested_at',
        'verified_pin_unlock_requested_by',
        'verified_pin_unlock_request_order_id',
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
        'verified_pin_unlock_requested_at' => 'datetime',
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

    // The two payment choices the pickers offer, and the only values
    // default_payment_method ever stores. Everything else in the system
    // (cash_on_delivery, bank_transfer, card, free text from Shopify) folds
    // into one of these two — see normalizePaymentMethod().
    public const PAYMENT_CASH   = 'cash';
    public const PAYMENT_ONLINE = 'online';

    /**
     * Aug-2026 — fold any payment_method string down to 'cash' | 'online',
     * or null when there is nothing to fold (no default set).
     *
     * ⭐ This is deliberately NOT a new rule. The cash-vs-online question is
     * already answered in exactly one place — PaymentChangeInvoiceHandler::
     * isOnline() — which is what the WhatsApp invoice templates and the
     * payment-change automation judge by, and what the orders page mirrors in
     * JS as waClassifyPayment(). Delegating here keeps a customer's remembered
     * default and the order's own method agreeing forever; a second copy of
     * "does this string mean cash?" is how they would drift apart.
     *
     * Note the asymmetry that makes this safe: an ORDER stores whichever
     * historical value its form has always written ('cash_on_delivery' on the
     * regular NF path, 'cash' on the qurbani path), while this column stores
     * the canonical choice. Reading is lossless in the direction that matters
     * — the forms expand 'cash'/'online' back to their own values.
     */
    public static function normalizePaymentMethod($value): ?string
    {
        $pm = strtolower(trim((string) $value));
        if ($pm === '') {
            return null;
        }

        return \App\Services\WhatsApp\Automation\Handlers\PaymentChangeInvoiceHandler::isOnline($pm)
            ? self::PAYMENT_ONLINE
            : self::PAYMENT_CASH;
    }

    // Verified-pin lock (Jul-2026). Once a customer HAS a verified pin, riders
    // may not overwrite it — a rider once moved a pin after delivery and every
    // delivered-orders report silently re-judged past deliveries against the new
    // spot (NF-18834). A store/web user grants a time-boxed unlock; the rider
    // then gets ONE save inside the window. The window is the backstop for
    // "unlocked but the rider never saved" — it just expires.
    public const VERIFIED_PIN_UNLOCK_MINUTES = 360; // 6h grant window

    /**
     * Aug-2026 — the GRACE window a staff pin-save leaves open behind it.
     *
     * The lock's first version re-locked the instant anyone saved, including
     * the manager. That broke the commonest real workflow: a manager drops a
     * TEMPORARY pin so dispatch timings and ETAs keep working, and the rider
     * who then reaches the door — the only person who actually knows where the
     * customer lives — found it locked and had to phone for an unlock.
     *
     * So a save BY STAFF (or by the customer from the customer app) now leaves
     * the pin open for 24h: whoever arrives next may correct it once. That
     * rider's own save consumes the grant (see pinGrantConsumed) and the pin
     * re-locks immediately, so the NF-18834 hole — a rider silently re-pinning
     * a customer days after delivery, which re-judges every past delivery
     * report against the new spot — stays closed.
     */
    public const VERIFIED_PIN_STAFF_GRACE_MINUTES = 1440; // 24h

    /**
     * Aug-2026 — how long a rider's unlock REQUEST stays on the banners.
     *
     * The request is normally cleared the moment someone acts on it, so this is
     * only the backstop for one nobody ever answered: it must not still be
     * shouting at the store tomorrow morning. One shift is the natural life of
     * "a rider is standing at a door right now".
     */
    public const VERIFIED_PIN_UNLOCK_REQUEST_TTL_MINUTES = 720; // 12h

    /** True while an unlock grant is live (rider may save the pin right now). */
    public function verifiedPinUnlockActive(): bool
    {
        return $this->verified_pin_unlocked_until !== null
            && $this->verified_pin_unlocked_until->isFuture();
    }

    /**
     * True while a rider's unlock request is still worth showing: raised, inside
     * the TTL, and the pin is STILL locked. The last clause is the safety net —
     * even if some future writer forgets to clear the request, granting the
     * unlock makes the banner disappear anyway.
     */
    public function verifiedPinUnlockRequestActive(): bool
    {
        return $this->verified_pin_unlock_requested_at !== null
            && $this->verified_pin_unlock_requested_at->isAfter(
                now()->subMinutes(self::VERIFIED_PIN_UNLOCK_REQUEST_TTL_MINUTES)
            )
            && $this->isVerifiedPinLocked();
    }

    /**
     * Columns that CLEAR a pending rider unlock request.
     *
     * ⭐ Every action that resolves the lock one way or the other merges this
     * in — unlock granted, pin saved, relocked, dismissed. That single habit is
     * what stops the store and web banners outliving the thing they ask for:
     * there is no separate "dismiss the alert" step that someone can forget.
     *
     * It matters most on the RIDER's own save: that consumes the grant and
     * re-locks the pin, so a request left standing would make the banner pop
     * straight back up for a job that is already done.
     */
    public static function pinUnlockRequestCleared(): array
    {
        return [
            'verified_pin_unlock_requested_at'     => null,
            'verified_pin_unlock_requested_by'     => null,
            'verified_pin_unlock_request_order_id' => null,
        ];
    }

    /**
     * Grant columns to write when a RIDER saves the pin: consume the grant, so
     * he gets exactly the one edit it was opened for.
     */
    public static function pinGrantConsumed(): array
    {
        return array_merge([
            'verified_pin_unlocked_until' => null,
            'verified_pin_unlocked_by'    => null,
        ], self::pinUnlockRequestCleared());
    }

    /**
     * Grant columns to write when STAFF (or the customer) saves the pin: open
     * the 24h grace window described on VERIFIED_PIN_STAFF_GRACE_MINUTES.
     *
     * Takes the CURRENT stored values rather than a model instance so the raw
     * DB::table() writers (the customer app) can use the identical rule. Never
     * SHORTENS a grant that already runs longer than the new window — otherwise
     * a staff save moments after a manual unlock could cut that unlock short.
     * Parsing is defensive: an unreadable stored value simply falls through to
     * a fresh window rather than throwing inside a save.
     *
     * @param  mixed     $currentUntil  stored verified_pin_unlocked_until (Carbon|string|null)
     * @param  mixed     $currentBy     stored verified_pin_unlocked_by
     * @param  int|null  $grantedBy     user id to credit for the new window
     */
    public static function pinGrantGrace($currentUntil, $currentBy, ?int $grantedBy): array
    {
        $until = now()->addMinutes(self::VERIFIED_PIN_STAFF_GRACE_MINUTES);

        try {
            if (!empty($currentUntil)) {
                $existing = \Illuminate\Support\Carbon::parse($currentUntil);
                if ($existing->greaterThan($until)) {
                    return array_merge([
                        'verified_pin_unlocked_until' => $existing,
                        'verified_pin_unlocked_by'    => $currentBy,
                    ], self::pinUnlockRequestCleared());
                }
            }
        } catch (\Throwable $e) {
            // Unparseable stored value — fall through to a fresh window.
        }

        return array_merge([
            'verified_pin_unlocked_until' => $until,
            'verified_pin_unlocked_by'    => $grantedBy,
        ], self::pinUnlockRequestCleared());
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