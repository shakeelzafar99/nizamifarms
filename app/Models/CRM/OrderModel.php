<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class OrderModel extends BaseModel
{
    use HasFactory, Notifiable;
    
    protected $table = 't_crm_prod_order';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'customer_id',
        'external_source',
        'order_source_channel',
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
        'expected_packets',  // Number of packets expected (entered by manager/admin)
        'actual_packets',    // Number of packets delivered (entered by rider)
        'raw_products_text',
        'converted',
        'ledger_transaction_id',
        'delivery_priority', // ⭐ Delivery sequence priority (1=first, 2=second, etc)
        'online_message_sent_at', // WhatsApp payment reminder sent timestamp
        'online_message_sent_by', // User ID who sent the WhatsApp message
        'qurbani_day',
        'qurbani_slot',
        'qurbani_region',
        'qurbani_delivery_type',
        'payment_status',
        'total_paid',
        // Invoice PAID-stamp metadata — display-only, edited without
        // mutating payment history rows. See getPaidStampData().
        'paid_stamp_sending_bank',
        'paid_stamp_date',
        'paid_stamp_ref_mode',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'subtotal_price' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'delivery_priority' => 'integer',
        'total_price' => 'decimal:2',
        'total_weight' => 'integer',
        'converted' => 'boolean',
        'expected_packets' => 'integer',
        'actual_packets' => 'integer',
        'online_message_sent_at' => 'datetime',
        'online_message_sent_by' => 'integer',
        'total_paid' => 'decimal:2',
        'paid_stamp_date' => 'date'
    ];

    // Ensure relationships are included when converting to array/JSON
    protected $with = [];

    // Append computed attributes to JSON automatically (for frontend table)
    protected $appends = [
        'rider_name',
        'delivery_date' // Actual delivery date from status history
    ];

    // Mutator to format order_date for MySQL
    public function setOrderDateAttribute($value)
    {
        if (!$value || trim($value) === '') {
            // Don't set to null if value is empty - let validation handle it
            // For updates, preserve existing value if no new value provided
            return;
        }
        
        try {
            // Parse the date and format it for MySQL (without timezone info)
            $date = \Carbon\Carbon::parse($value);
            $this->attributes['order_date'] = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::warning('Invalid order_date format: ' . $value . ' - Error: ' . $e->getMessage());
            // Don't set to null, let validation handle the error
            return;
        }
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerModel::class, 'customer_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(OrderLineItemModel::class, 'order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('changed_at', 'desc');
    }

    public function currentStatusHistory(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OrderStatusHistory::class, 'order_id')->where('is_current', true);
    }

    public function assignedRider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\SysAdmin\UserModel::class, 'assigned_rider_user_id', 'id');
    }

    /**
     * Get discount detail breakdown for this order
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscountModel::class, 'order_id')->orderBy('display_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        // Tiebreak by id DESC so when two payments share the same
        // payment_date the most recently-created row is considered "the
        // latest" — matters for the PAID-stamp fallback (bank, reference,
        // date) which uses the top row here.
        return $this->hasMany(OrderPaymentModel::class, 'order_id')
            ->where('status', 'active')
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Build the dataset used by the PAID stamp on every invoice view
     * (web, print, image, PDF, and mobile's local share-as-image). Centralised
     * here so all five templates stay in sync and fallback rules live in one
     * place.
     *
     * Returns an associative array suitable for use directly in Blade:
     *   - show            (bool)   → whether to render the stamp at all
     *   - bank            (string) → sending bank label ("HBL", "CASH", "—")
     *                                (kept for back-compat; mirrors sending_bank)
     *   - sending_bank    (string) → customer's sending bank ("HBL", "CASH", "—")
     *   - receiving_bank  (string) → Nizami Farms receiving bank ("HBL", "", "—")
     *                                Empty for cash orders.
     *   - date            (string) → formatted payment date ("17 Apr 2026")
     *   - ref             (?string)→ third line (reference/customer name/null-hides)
     *   - ref_label       (string) → "ref" | "to" depending on ref_mode
     *   - logo_url        (string) → fully-qualified logo URL used as a
     *                                faint watermark inside the stamp.
     */
    public function getPaidStampData(): array
    {
        // Fully paid only — per product decision (partial stamps not shown).
        if (($this->payment_status ?? '') !== 'paid') {
            return [
                'show' => false, 'bank' => '', 'sending_bank' => '',
                'receiving_bank' => '', 'date' => '', 'ref' => null,
                'ref_label' => 'ref', 'logo_url' => '',
            ];
        }

        // Latest active payment gives us fallbacks for anything the user
        // didn't explicitly set on the stamp override fields.
        $latest = $this->payments()->first(); // payments() already filters active + DESC by date

        // Sending bank (customer's bank) — order override wins;
        // else infer from payment method ('CASH' for cash, '—' otherwise).
        $sendingBank = $this->paid_stamp_sending_bank;
        if (!$sendingBank) {
            $sendingBank = ($latest && $latest->payment_method === 'cash') ? 'CASH' : '—';
        }

        // Receiving bank (Nizami Farms' bank) — derived from the latest
        // payment's receiving_account_id → t_fin_online_receiving_accounts.
        // Only shown for non-cash payments; cash receipts leave it blank so
        // the stamp doesn't look crowded.
        $receivingBank = '';
        if ($latest && $latest->payment_method !== 'cash' && $latest->receiving_account_id) {
            $receivingBank = \DB::table('t_fin_online_receiving_accounts')
                ->where('id', $latest->receiving_account_id)
                ->value('short_code')
                ?: \DB::table('t_fin_online_receiving_accounts')
                    ->where('id', $latest->receiving_account_id)
                    ->value('name')
                ?: '';
        }

        // Stamp date — order override wins; else latest payment date; else
        // today as a last resort (shouldn't happen for a paid order).
        $stampDate = $this->paid_stamp_date ?? ($latest?->payment_date);
        $stampDateFormatted = $stampDate
            ? \Carbon\Carbon::parse($stampDate)->format('d M Y')
            : now()->format('d M Y');

        // Third-line ref — respects user's explicit choice at payment time.
        $refMode = $this->paid_stamp_ref_mode ?: 'reference';
        $ref = null;
        $refLabel = 'ref';
        if ($refMode === 'reference') {
            $ref = $latest?->reference ?: null;
            $refLabel = 'ref';
        } elseif ($refMode === 'customer_name') {
            // Pull name from customer relation, or fall back to address name
            // on the order itself (same rules the rest of the app uses).
            $ref = ($this->customer?->full_name)
                ?: trim(($this->address_first_name ?? '') . ' ' . ($this->address_last_name ?? ''))
                ?: ($this->name ?? null);
            $refLabel = 'to';
        } // 'blank' → $ref stays null so the line isn't rendered

        // Absolute URL to the company logo, used as a faint watermark
        // inside the stamp. Prod stores the file as "nizami-farms-logo.png.jpg"
        // (not .png), so we probe the same fallback chain the other invoice
        // templates use and return the first URL whose file actually exists.
        // Works for the on-screen invoice, html2canvas image render, and
        // dompdf (remote-enabled). Mobile ignores this and uses its own
        // bundled asset.
        $logoCandidates = [
            'assets/media/logos/nizami-farms-logo.png',
            'assets/media/logos/nizami-farms-logo.jpg',
            'assets/media/logos/nizami-farms-logo.png.jpg',
        ];
        $logoUrl = asset($logoCandidates[0]); // last-resort URL
        foreach ($logoCandidates as $candidate) {
            if (is_file(public_path($candidate))) {
                $logoUrl = asset($candidate);
                break;
            }
        }

        return [
            'show'           => true,
            'bank'           => $sendingBank,           // back-compat alias
            'sending_bank'   => $sendingBank,
            'receiving_bank' => $receivingBank,
            'date'           => $stampDateFormatted,
            'ref'            => $ref ?: null,
            'ref_label'      => $refLabel,
            'logo_url'       => $logoUrl,
        ];
    }

    public function hasQurbaniItems(): bool
    {
        if (!$this->relationLoaded('lineItems')) {
            $this->load('lineItems');
        }
        $productIds = $this->lineItems->pluck('product_id')->filter()->unique();
        if ($productIds->isEmpty()) {
            return false;
        }
        return \DB::table('t_crm_prod_product')
            ->whereIn('id', $productIds)
            ->whereRaw("LOWER(attribute_1) = 'qurbani'")
            ->exists();
    }

    public function hasPreReceivedPayments(): bool
    {
        return \DB::table('t_crm_order_payments')
            ->where('order_id', $this->id)
            ->where('status', 'active')
            ->exists();
    }

    public function recalculatePaymentStatus(): void
    {
        $totalPaid = (float) \DB::table('t_crm_order_payments')
            ->where('order_id', $this->id)
            ->where('status', 'active')
            ->sum('amount');

        $totalPrice = (float) $this->total_price;

        if ($totalPaid <= 0) {
            $status = 'unpaid';
        } elseif ($totalPaid >= $totalPrice) {
            $status = 'paid';
        } else {
            $status = 'partial';
        }

        $this->total_paid = $totalPaid;
        $this->payment_status = $status;
        $this->save();
    }

    /**
     * Computed rider_name for frontend consumption
     */
    public function getRiderNameAttribute(): ?string
    {
        if ($this->relationLoaded('assignedRider') && $this->assignedRider) {
            return $this->assignedRider->fullname ?? null;
        }
        // Lazy fetch minimal if not loaded and id present
        if (!empty($this->assigned_rider_user_id)) {
            $name = \DB::table('t_sys_user')->where('id', $this->assigned_rider_user_id)->value('fullname');
            return $name ?: null;
        }
        return null;
    }

    /**
     * Get actual delivery date from status history (for mobile app grouping)
     * Returns the date when the order was actually marked as delivered
     */
    public function getDeliveryDateAttribute(): ?string
    {
        // Only for delivered/completed orders
        if (!in_array($this->order_status, ['delivered', 'completed'])) {
            return null;
        }

        // Try to get from loaded relationship first (if current status is delivered)
        if ($this->relationLoaded('currentStatusHistory') && $this->currentStatusHistory) {
            if ($this->currentStatusHistory->status_code === 'delivered' && $this->currentStatusHistory->changed_at) {
                return date('Y-m-d', strtotime($this->currentStatusHistory->changed_at));
            }
        }

        // Query the database for delivery date
        // NOTE: Don't filter by is_current=1 because if order went delivered→completed,
        // the 'delivered' entry would have is_current=0 but we still want that date
        $deliveryHistory = \DB::table('t_crm_order_status_history')
            ->where('order_id', $this->id)
            ->where('status_code', 'delivered')
            ->orderBy('changed_at', 'desc') // Get most recent delivered status change
            ->value('changed_at');

        if ($deliveryHistory) {
            return date('Y-m-d', strtotime($deliveryHistory));
        }

        // Last fallback: use order_date
        return $this->order_date ? date('Y-m-d', strtotime($this->order_date)) : null;
    }

    /**
     * Get discount breakdown for display
     * Returns detail records if they exist, otherwise returns single discount from discount_total
     * This ensures backward compatibility with webhook orders and old manual orders
     */
    public function getDiscountBreakdown()
    {
        // Load discount details if not already loaded
        if (!$this->relationLoaded('discounts')) {
            $this->load('discounts');
        }
        
        $discounts = $this->discounts;
        
        // If we have detail records, return them
        if ($discounts->isNotEmpty()) {
            return $discounts;
        }
        
        // Fallback: If no detail records but discount_total exists, 
        // return a single discount object for display
        if ($this->discount_total > 0) {
            $title = 'Discount';
            
            // If there's a coupon code, include it in the title
            if ($this->coupon_code) {
                $title = 'Discount';
                if (strlen($this->coupon_code) > 0) {
                    $title .= " ({$this->coupon_code})";
                }
            }
            
            return collect([
                (object)[
                    'discount_title' => $title,
                    'discount_amount' => $this->discount_total,
                    'discount_type' => 'fixed',
                    'coupon_code' => $this->coupon_code
                ]
            ]);
        }
        
        // No discounts at all
        return collect([]);
    }

    // Helper methods
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->address_city,
            $this->address_province,
            $this->address_postal_code,
            $this->address_country
        ]);
        
        return implode(', ', $parts);
    }

    public function getCustomerNameAttribute(): string
    {
        // Priority: order.name field -> customer relationship -> address fields
        if ($this->name && trim($this->name)) {
            return trim($this->name);
        }
        
        if ($this->customer && $this->customer->full_name && trim($this->customer->full_name)) {
            return trim($this->customer->full_name);
        }
        
        // Fallback to address fields
        return trim(($this->address_first_name ?? '') . ' ' . ($this->address_last_name ?? ''));
    }

    /**
     * Get the order date as a Carbon instance (for display purposes)
     * This preserves the original timezone from the source
     */
    public function getOrderDateAttribute($value)
    {
        if (!$value) {
            return null;
        }
        
        // If it's already a Carbon instance, return as-is
        if ($value instanceof \Carbon\Carbon) {
            return $value;
        }
        
        // Parse the date string and return as-is (no timezone conversion)
        try {
            // Create Carbon instance without timezone conversion
            $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
            return $carbon;
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
     * Override how order_date is serialized to JSON to prevent timezone conversion
     */
    public function toArray()
    {
        $array = parent::toArray();
        
        // Override order_date serialization to return raw database value
        if (isset($array['order_date']) && $this->order_date) {
            // Get the raw database value without timezone conversion
            $array['order_date'] = $this->getRawOriginal('order_date') ?: $this->attributes['order_date'];
        }
        
        return $array;
    }

    /**
     * Store order with line items and customer management
     */
    /**
     * Store order (Shopify routes to ShopifyOrderModel).
     * Returns the concrete Eloquent model instance that was created/updated.
     */
    public static function storeOrderFromApi(array $orderData): \Illuminate\Database\Eloquent\Model
    {
        DB::beginTransaction();
        
        try {
            // Route Shopify and khaas_storage orders to the approval queue (ShopifyOrderModel)
            // The _force_prod_order flag bypasses this routing (used during conversion)
            $forceProdOrder = !empty($orderData['_force_prod_order']);
            unset($orderData['_force_prod_order']);
            $isShopify = !$forceProdOrder
                && isset($orderData['external_source'])
                && in_array($orderData['external_source'], ['shopify', 'khaas_storage']);

            // Check for existing order
            $existingOrder = null;
            if (isset($orderData['external_source']) && isset($orderData['external_id'])) {
                if ($isShopify) {
                    $existingOrder = \App\Models\CRM\ShopifyOrderModel::where('external_source', 'shopify')
                        ->where('external_id', $orderData['external_id'])
                        ->first();
                } else {
                    $existingOrder = static::where('external_source', $orderData['external_source'])
                        ->where('external_id', $orderData['external_id'])
                        ->first();
                }
            }

            // Find or create customer by phone
            $customer = null;
            if (isset($orderData['address_phone']) && $orderData['address_phone']) {
                $customer = CustomerModel::findOrCreateByPhone(
                    $orderData['address_phone'],
                    $orderData,
                    $orderData['order_date'] ?? now(),
                    $orderData['total_price'] ?? 0,
                    $existingOrder !== null  // Pass true if this is an update
                );
            }

            // Prepare order data
            $orderAttributes = $orderData;
            if ($customer) {
                $orderAttributes['customer_id'] = $customer->id;
            }
            // Keep the original customer_id from $orderData if phone lookup didn't find/create one
            $orderAttributes['created_by'] = auth()->check() ? auth()->id() : null;

            // Jul-2026: flag the customer as a mobile-app user the first time an
            // app-origin order (Shopify source_name ios_app/android_app) is seen
            // for them. One-way flag + first-seen stamp; only writes when it
            // actually flips, so it's a no-op on every later order. Non-fatal —
            // a flag hiccup must never break order ingest.
            try {
                $channel = $orderData['order_source_channel'] ?? null;
                if ($customer && in_array($channel, ['ios_app', 'android_app'], true) && !$customer->is_on_mobile_app) {
                    $customer->is_on_mobile_app = true;
                    $customer->mobile_app_first_seen_at = $customer->mobile_app_first_seen_at ?? now();
                    $customer->save();
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to set customer mobile-app flag (non-fatal)', [
                    'customer_id' => $customer->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Extract line items
            $lineItems = $orderAttributes['line_items'] ?? [];
            unset($orderAttributes['line_items']);

            // Create or update order (route by source)
            if ($isShopify) {
                if ($existingOrder) {
                    $existingOrder->update($orderAttributes);
                    $order = $existingOrder;
                } else {
                    $order = \App\Models\CRM\ShopifyOrderModel::create($orderAttributes);
                }
            } else {
                if ($existingOrder) {
                    // Capture the previous status to detect changes from WooCommerce or other non-Shopify sources
                    $previousStatus = $existingOrder->order_status;
                    
                    // Check if we should block status change BEFORE updating database
                    $incomingNormalized = null;
                    $shouldUseChangeStatus = false;
                    
                    if (array_key_exists('order_status', $orderAttributes)
                        && $orderAttributes['order_status'] !== null
                        && $orderAttributes['order_status'] !== $previousStatus) {
                        try {
                            $incomingNormalized = static::normalizeStatusCode($orderAttributes['order_status']);

                            // If existing order already has a non-empty status set, block Woo updates
                            // unless the incoming status is 'cancelled'.
                            $allowStatusChange = ($incomingNormalized === 'cancelled')
                                || empty($previousStatus);

                            if ($allowStatusChange) {
                                // Remove status from orderAttributes - we'll use changeStatus() instead
                                unset($orderAttributes['order_status']);
                                $shouldUseChangeStatus = true;
                            } else {
                                // Block the status change by removing it from attributes BEFORE update
                                unset($orderAttributes['order_status']);
                                \Log::info('WooCommerce status update blocked (non-cancelled subsequent edit)', [
                                    'order_id' => $existingOrder->id,
                                    'previous' => $previousStatus,
                                    'incoming' => $incomingNormalized,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            \Log::warning('storeOrderFromApi: failed to apply Woo status rule', [
                                'order_id' => $existingOrder->id,
                                'from' => $previousStatus,
                                'to' => $orderAttributes['order_status'] ?? 'unknown',
                                'error' => $e->getMessage(),
                            ]);
                            // On error, remove status to be safe
                            unset($orderAttributes['order_status']);
                        }
                    }
                    
                    // Mark this update as coming from an external source (webhook)
                    // This prevents ledger adjustments from being triggered
                    $orderAttributes['_skip_ledger_adjustment'] = true;
                    
                    // Now update order - status already removed if needed
                    $existingOrder->update($orderAttributes);
                    $order = $existingOrder;
                    
                    // If status change was allowed, apply it using changeStatus method
                    if ($shouldUseChangeStatus && $incomingNormalized) {
                        $order->changeStatus($incomingNormalized, 'WooCommerce sync');
                    }
                } else {
                    $order = static::create($orderAttributes);
                    // Create initial status history for new non-Shopify orders
                    $order->createInitialStatusHistory();
                }
            }

            // Store line items
            if (!empty($lineItems)) {
                \Log::info('storeOrderFromApi: Preparing to save line items', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number ?? null,
                    'is_shopify' => $isShopify,
                    'line_items_count' => count($lineItems),
                    'is_update' => $existingOrder !== null,
                    'skus' => array_map(fn($li) => $li['sku'] ?? 'N/A', $lineItems),
                ]);

                // Delete existing line items if updating
                if ($existingOrder) {
                    if ($isShopify) {
                        $order->lineItems()->delete();
                    } else {
                        $order->lineItems()->delete();
                    }
                }

                if ($isShopify) {
                    $lineItemModels = [];
                    foreach ($lineItems as $lineItem) {
                        $lineItem['order_id'] = $order->id;
                        $lineItem['created_by'] = auth()->check() ? auth()->id() : null;
                        $lineItemModels[] = new \App\Models\CRM\ShopifyOrderLineItemModel($lineItem);
                    }
                    $order->lineItems()->saveMany($lineItemModels);
                    
                    // ⚠️ POST-SAVE VERIFICATION: Confirm line items were actually inserted
                    // This catches silent INSERT failures (e.g., column type overflow, PDO silent mode)
                    $savedCount = $order->lineItems()->count();
                    if ($savedCount !== count($lineItems)) {
                        \Log::error('storeOrderFromApi: LINE ITEMS MISMATCH after save!', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number ?? null,
                            'expected_count' => count($lineItems),
                            'actual_saved_count' => $savedCount,
                            'line_items_data' => array_map(fn($li) => [
                                'sku' => $li['sku'] ?? null,
                                'name' => $li['name'] ?? null,
                                'product_id' => $li['product_id'] ?? null,
                                'variant_id' => $li['variant_id'] ?? null,
                            ], $lineItems),
                        ]);
                        
                        // Retry: Try inserting items one at a time using direct DB insert
                        // This bypasses Eloquent model and may give clearer error messages
                        if ($savedCount === 0) {
                            \Log::warning('storeOrderFromApi: Attempting direct DB insert fallback for line items');
                            $order->lineItems()->delete(); // Clean slate
                            foreach ($lineItems as $idx => $lineItem) {
                                try {
                                    $lineItem['order_id'] = $order->id;
                                    $lineItem['created_by'] = auth()->check() ? auth()->id() : null;
                                    $lineItem['created_at'] = now();
                                    $lineItem['updated_at'] = now();
                                    // Remove non-column fields
                                    unset($lineItem['external_line_item_id'], $lineItem['vendor']);
                                    DB::table('t_crm_shopify_order_line_item')->insert($lineItem);
                                } catch (\Exception $itemEx) {
                                    \Log::error('storeOrderFromApi: Direct insert failed for line item', [
                                        'order_id' => $order->id,
                                        'item_index' => $idx,
                                        'sku' => $lineItem['sku'] ?? null,
                                        'product_id' => $lineItem['product_id'] ?? null,
                                        'variant_id' => $lineItem['variant_id'] ?? null,
                                        'error' => $itemEx->getMessage(),
                                    ]);
                                }
                            }
                            $finalCount = $order->lineItems()->count();
                            \Log::info('storeOrderFromApi: Direct insert fallback result', [
                                'order_id' => $order->id,
                                'final_saved_count' => $finalCount,
                            ]);
                        }
                    } else {
                        \Log::info('storeOrderFromApi: Line items saved successfully', [
                            'order_id' => $order->id,
                            'saved_count' => $savedCount,
                        ]);
                    }
                } else {
                    $lineItemModels = [];
                    foreach ($lineItems as $lineItem) {
                        $lineItem['order_id'] = $order->id;
                        $lineItem['created_by'] = auth()->check() ? auth()->id() : null;
                        $lineItemModels[] = new OrderLineItemModel($lineItem);
                    }
                    $order->lineItems()->saveMany($lineItemModels);
                }
            } else if ($isShopify) {
                \Log::warning('storeOrderFromApi: No line items to save for Shopify order', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number ?? null,
                ]);
            }

            // ⭐ INVENTORY NOTE: Inventory is NOT deducted on order creation.
            // It is deducted later when line items are marked as "preparing"
            // (via bulkUpdateLineItemStatus or bulkMarkOrdersAsPrepared),
            // or auto-deducted when the order moves to "out_for_delivery".
            // This applies to all order sources (webapp, Shopify conversions, etc.).

            // Rename Shopify qurbani orders to QUR format (use SKU-based lookup since
            // Shopify line items store external product IDs, not local CRM IDs)
            if ($isShopify && !$existingOrder) {
                $liTable = 't_crm_shopify_order_line_item';
                $hasQurbani = DB::table($liTable . ' as li')
                    ->join('t_crm_prod_product_variant as v', 'v.sku', '=', 'li.sku')
                    ->join('t_crm_prod_product as p', 'v.product_id', '=', 'p.id')
                    ->where('li.order_id', $order->id)
                    ->whereNotNull('li.sku')
                    ->whereRaw("LOWER(p.attribute_1) = 'qurbani'")
                    ->exists();
                if ($hasQurbani && !str_starts_with($order->order_number ?? '', 'QUR')) {
                    $yearSuffix = date('y');
                    $prefix = 'QUR' . $yearSuffix . '-';
                    $prefixLen = strlen($prefix) + 1;
                    $maxSeq = DB::table('t_crm_prod_order')
                        ->where('order_number', 'LIKE', $prefix . '%')
                        ->lockForUpdate()
                        ->max(DB::raw("CAST(SUBSTRING(order_number, {$prefixLen}) AS UNSIGNED)"));
                    $maxSeqShopify = DB::table('t_crm_shopify_order')
                        ->where('order_number', 'LIKE', $prefix . '%')
                        ->lockForUpdate()
                        ->max(DB::raw("CAST(SUBSTRING(order_number, {$prefixLen}) AS UNSIGNED)"));
                    $nextSeq = max(($maxSeq ?? 0), ($maxSeqShopify ?? 0)) + 1;
                    $order->order_number = $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
                    $order->save();
                }
            }

            DB::commit();

            // WhatsApp "order received" automation (Jun-2026). Fire ONLY for a
            // brand-new order arrival — never on an update, and never on the
            // Shopify->prod conversion ($forceProdOrder), which has its own
            // accept/location automation. SCOPE (owner, Jun-2026): SHOPIFY-
            // received orders only — orders created in our own open-orders
            // system are intentionally excluded (the `$isShopify` guard). To
            // also cover those later, drop the `$isShopify` guard (and add a
            // per-rule source option if both are wanted). Deferred to app
            // terminating so the WhatsApp send never blocks order creation, and
            // fully isolated so it can never affect the committed order. Dormant
            // unless the operator has enabled an order-received rule + master switch.
            if ($existingOrder === null && empty($forceProdOrder) && $isShopify) {
                try {
                    $createdOrder = $order->load(['customer', 'lineItems']);
                    $createdIsShopify = $isShopify;
                    app()->terminating(function () use ($createdOrder, $createdIsShopify) {
                        try {
                            app(\App\Services\WhatsApp\Automation\WhatsAppAutomationService::class)
                                ->dispatch('order.created', [
                                    'order'      => $createdOrder,
                                    'order_id'   => $createdOrder->id,
                                    'is_shopify' => $createdIsShopify,
                                ]);
                        } catch (\Throwable $e) {
                            \Log::warning('WA order.created automation failed', ['error' => $e->getMessage()]);
                        }
                    });
                } catch (\Throwable $e) {
                    \Log::warning('WA order.created scheduling failed', ['error' => $e->getMessage()]);
                }
            }

            return $order->load(['customer', 'lineItems']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Assign a rider to this order and record history.
     * - Demotes previous current assignment (if any)
     * - Inserts a new current assignment
     * - Updates denormalized assigned_rider_user_id on order
     */
    public function assignRider(int $riderUserId, ?string $notes = null, ?int $assignedByUserId = null, ?\DateTimeInterface $assignedAt = null): bool
    {
        try {
            $orderId = $this->getAttribute($this->primaryKey);
            if (!$orderId) {
                return false;
            }

            $assignedBy = $assignedByUserId ?? (auth()->check() ? (int)auth()->id() : null);
            $assignedAtTs = $assignedAt ? Carbon::instance($assignedAt)->format('Y-m-d H:i:s') : Carbon::now()->format('Y-m-d H:i:s');

            return DB::transaction(function () use ($orderId, $riderUserId, $notes, $assignedBy, $assignedAtTs) {
                // Check if assigning the same rider (no-op)
                $currentAssignment = DB::table('t_ops_order_rider_history')
                    ->where('order_id', $orderId)
                    ->where('is_current', 1)
                    ->first();
                
                if ($currentAssignment && $currentAssignment->rider_user_id == $riderUserId) {
                    \Log::info('Rider already assigned, skipping', [
                        'order_id' => $orderId,
                        'rider_user_id' => $riderUserId
                    ]);
                    return true; // Already assigned, treat as success
                }
                
                // Demote previous current (set unassigned_at timestamp)
                if ($currentAssignment) {
                    $updateData = [
                        'is_current' => 0,
                        'unassigned_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    ];
                    
                    // Add is_current_order_id only if column exists
                    if (Schema::hasColumn('t_ops_order_rider_history', 'is_current_order_id')) {
                        $updateData['is_current_order_id'] = null;
                    }
                    
                    DB::table('t_ops_order_rider_history')
                        ->where('id', $currentAssignment->id)
                        ->update($updateData);
                }

                // Insert new current assignment
                $insertData = [
                    'order_id'      => $orderId,
                    'rider_user_id' => $riderUserId,
                    'is_current'    => 1,
                    'assigned_at'   => $assignedAtTs,
                    'assigned_by'   => $assignedBy,
                    'source'        => 'api',
                    'notes'         => $notes,
                    'created_at'    => Carbon::now()->format('Y-m-d H:i:s'),
                ];
                
                // Add is_current_order_id only if column exists
                if (Schema::hasColumn('t_ops_order_rider_history', 'is_current_order_id')) {
                    $insertData['is_current_order_id'] = $orderId;
                }
                
                DB::table('t_ops_order_rider_history')->insert($insertData);

                // Update denormalized column on order.
                // Reached only on a REAL (re)assignment — the same-rider case
                // returns early above. Clearing delivery_priority drops the
                // order out of the OLD rider's planned route and lets it join
                // the NEW rider's list at the bottom (COALESCE(...,999) sorts
                // nulls last), instead of injecting the old rider's stale stop
                // number into the middle of the new rider's sequence.
                //
                // The dispatch ETA goes with it, for the same reason: it was
                // computed from the OLD rider's position, his stop order and his
                // legs, so it describes a run this order is no longer on. Left
                // behind it followed the order onto the new rider and:
                //   - showed him (and the customer) a time nobody calculated
                //     for him;
                //   - made him look mid-run to riderIsMidRun(), so his next
                //     dispatch skipped the office-anchored origin and the 5-min
                //     grace;
                //   - hid the order from "Dispatch Next", which only times stops
                //     with no ETA — so the wrong time could never be corrected
                //     except by a full re-dispatch;
                //   - suppressed the left-without-dispatch alert (he looked like
                //     he was already carrying a dispatched wave);
                //   - got quoted to the customer by the WhatsApp invoice, which
                //     reads whatever ETA an out_for_delivery order carries.
                // Clearing it puts the order in the new rider's next dispatch,
                // which is the only thing that can time it correctly.
                $orderUpdateData = [
                    'assigned_rider_user_id' => $riderUserId,
                    'delivery_priority' => null,
                    'estimated_delivery_at' => null,
                    'eta_calculated_at' => null,
                    'eta_calculated_by' => null,
                    'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
                ];
                
                // Add sync tracking columns if they exist (smart sync feature)
                if (Schema::hasColumn('t_crm_prod_order', 'rider_sync_required')) {
                    $orderUpdateData['rider_sync_required'] = true;
                }
                
                DB::table('t_crm_prod_order')
                    ->where('id', $orderId)
                    ->update($orderUpdateData);

                // Refresh current model instance
                $this->setAttribute('assigned_rider_user_id', $riderUserId);
                $this->refresh();

                return true;
            });
        } catch (\Throwable $e) {
            \Log::error('Failed to assign rider', [
                'order_id' => $this->id ?? null,
                'rider_user_id' => $riderUserId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Map Shopify order data to our format
     */
    public static function mapShopifyOrder(array $shopifyOrder): array
    {
        $billingAddress = $shopifyOrder['billing_address'] ?? [];
        $shippingAddress = $shopifyOrder['shipping_address'] ?? $billingAddress;
        
        // Use shipping if available, otherwise billing
        $primaryAddress = !empty($shippingAddress['address1']) ? $shippingAddress : $billingAddress;

        // Extract tip amount from Shopify webhook
        // Shopify sends tip in multiple possible formats:
        // 1. current_total_tip_set.shop_money.amount (preferred - most accurate)
        // 2. total_tip_received (fallback - legacy field)
        $tipAmount = 0;
        if (isset($shopifyOrder['current_total_tip_set']['shop_money']['amount'])) {
            $tipAmount = $shopifyOrder['current_total_tip_set']['shop_money']['amount'];
        } elseif (isset($shopifyOrder['total_tip_received'])) {
            $tipAmount = $shopifyOrder['total_tip_received'];
        }

        $orderData = [
            'external_source' => 'shopify',
            // Order origin channel — Shopify's source_name, set by the customer
            // app / storefront (e.g. "ios_app", "android_app", "web"). Lets NF
            // tell app orders from website orders. Captured raw; rendered as a
            // badge on the orders page. NULL for sources that don't set it.
            'order_source_channel' => $shopifyOrder['source_name'] ?? null,
            'external_id' => (string)$shopifyOrder['id'],
            'external_customer_id' => isset($shopifyOrder['customer']['id']) ? (string)$shopifyOrder['customer']['id'] : null,
            
            'order_number' => $shopifyOrder['order_number'] ?? $shopifyOrder['name'],
            'order_status' => static::mapShopifyStatus($shopifyOrder['financial_status'] ?? 'pending'),
            'order_date' => $shopifyOrder['created_at'],
            'name' => trim(($primaryAddress['first_name'] ?? '') . ' ' . ($primaryAddress['last_name'] ?? '')),
            'currency' => $shopifyOrder['currency'] ?? 'PKR',
            'contact_email' => $shopifyOrder['email'] ?? null,
            
            // Money fields
            'subtotal_price' => $shopifyOrder['subtotal_price'] ?? 0,
            'discount_total' => $shopifyOrder['total_discounts'] ?? 0,
            'shipping_total' => $shopifyOrder['total_shipping_price_set']['shop_money']['amount'] ?? 0,
            'total_tax' => $shopifyOrder['total_tax'] ?? 0,
            'tip_amount' => $tipAmount,
            'total_price' => $shopifyOrder['total_price'] ?? 0,
            'total_weight' => $shopifyOrder['total_weight'] ?? 0,
            
            // Address (single set)
            'address_first_name' => $primaryAddress['first_name'] ?? null,
            'address_last_name' => $primaryAddress['last_name'] ?? null,
            'address_company' => $primaryAddress['company'] ?? null,
            'address_email' => $shopifyOrder['email'] ?? null,
            'address_phone' => $primaryAddress['phone'] ?? null,
            'address_line1' => $primaryAddress['address1'] ?? null,
            'address_line2' => $primaryAddress['address2'] ?? null,
            'address_city' => $primaryAddress['city'] ?? null,
            'address_province' => $primaryAddress['province'] ?? null,
            'address_postal_code' => $primaryAddress['zip'] ?? null,
            'address_country' => $primaryAddress['country'] ?? 'Pakistan',
            
            // Extras
            'payment_method' => static::normalizePaymentMethod(
                $shopifyOrder['gateway'] ?? $shopifyOrder['payment_gateway_names'][0] ?? 
                (isset($shopifyOrder['transactions'][0]) ? $shopifyOrder['transactions'][0]['gateway'] : null)
            ),
            'note' => $shopifyOrder['note'] ?? null,  // Order notes already captured here
            
            // Line items - Filter out tip line items and items without SKU
            // Shopify sends tips as BOTH an order-level field (tip_amount) AND a line item
            // We only need the order-level field, so exclude tip line items here
            'line_items' => array_values(array_filter(array_map(
                [static::class, 'mapShopifyLineItem'], 
                array_filter($shopifyOrder['line_items'] ?? [], function($item) {
                    // Exclude tip line items - they're captured at order level in tip_amount field
                    $name = strtolower(trim($item['name'] ?? ''));
                    $isTip = in_array($name, ['tip', 'tips', 'gratuity']);
                    
                    // Also exclude items without SKU (invalid products)
                    $hasSku = !empty($item['sku']);
                    
                    return !$isTip && $hasSku;
                })
            )))
        ];

        return $orderData;
    }

    /**
     * Map WooCommerce order data to our format
     */
    public static function mapWooCommerceOrder(array $wooOrder): array
    {
        $billing = $wooOrder['billing'] ?? [];
        $shipping = $wooOrder['shipping'] ?? $billing;
        
        // Use shipping if available, otherwise billing
        $primaryAddress = !empty($shipping['address_1']) ? $shipping : $billing;

        $orderData = [
            'external_source' => 'woocommerce',
            'external_id' => (string)$wooOrder['id'],
            'external_customer_id' => isset($wooOrder['customer_id']) ? (string)$wooOrder['customer_id'] : null,
            
            'order_number' => $wooOrder['number'] ?? $wooOrder['id'],
            'order_status' => static::normalizeStatusCode($wooOrder['status'] ?? 'pending'),
            'order_date' => $wooOrder['date_created'] ?? now(),
            'name' => trim(($primaryAddress['first_name'] ?? '') . ' ' . ($primaryAddress['last_name'] ?? '')),
            'currency' => $wooOrder['currency'] ?? 'PKR',
            'contact_email' => $billing['email'] ?? null,
            
            // Money fields
            'subtotal_price' => ($wooOrder['total'] ?? 0) - ($wooOrder['total_tax'] ?? 0),
            'discount_total' => $wooOrder['discount_total'] ?? 0,
            'shipping_total' => $wooOrder['shipping_total'] ?? 0,
            'total_tax' => $wooOrder['total_tax'] ?? 0,
            'total_price' => $wooOrder['total'] ?? 0,
            
            // Address (single set)
            'address_first_name' => $primaryAddress['first_name'] ?? null,
            'address_last_name' => $primaryAddress['last_name'] ?? null,
            'address_company' => $primaryAddress['company'] ?? null,
            'address_email' => $billing['email'] ?? null,
            'address_phone' => $primaryAddress['phone'] ?? null,
            'address_line1' => $primaryAddress['address_1'] ?? null,
            'address_line2' => $primaryAddress['address_2'] ?? null,
            'address_city' => $primaryAddress['city'] ?? null,
            'address_province' => $primaryAddress['state'] ?? null,
            'address_postal_code' => $primaryAddress['postcode'] ?? null,
            'address_country' => $primaryAddress['country'] ?? 'Pakistan',
            
            // Extras
            'payment_method' => static::normalizePaymentMethod($wooOrder['payment_method_title'] ?? $wooOrder['payment_method'] ?? null),
            'note' => $wooOrder['customer_note'] ?? null,
            
            // Line items
            'line_items' => array_map([static::class, 'mapWooCommerceLineItem'], $wooOrder['line_items'] ?? [])
        ];

        return $orderData;
    }

    private static function mapShopifyLineItem(array $item): ?array
    {
        // Safety check: Skip tip line items (should already be filtered, but just in case)
        // This prevents tip line items from breaking the conversion process
        $name = strtolower(trim($item['name'] ?? ''));
        if (in_array($name, ['tip', 'tips', 'gratuity'])) {
            return null;  // Will be filtered out by array_filter
        }
        
        // Safety check: Skip items without SKU
        if (empty($item['sku'])) {
            \Log::warning('Shopify line item without SKU skipped', [
                'item_name' => $item['name'] ?? 'Unknown',
                'item_id' => $item['id'] ?? null
            ]);
            return null;  // Will be filtered out by array_filter
        }
        
        // Cast Shopify IDs to string to prevent INT overflow in database
        // Shopify product/variant IDs can exceed MySQL INT max (4,294,967,295)
        // e.g., product_id: 8913415962913, variant_id: 49731300819233
        return [
            'external_line_item_id' => (string)$item['id'],
            'product_id' => isset($item['product_id']) ? (string)$item['product_id'] : null,
            'variant_id' => isset($item['variant_id']) ? (string)$item['variant_id'] : null,
            'sku' => $item['sku'] ?? null,
            'name' => $item['name'] ?? null,
            'vendor' => $item['vendor'] ?? null,
            'quantity' => $item['quantity'] ?? 1,
            'unit_price' => $item['price'] ?? 0,
            'line_subtotal' => ($item['price'] ?? 0) * ($item['quantity'] ?? 1),
            'discount_amount' => 0, // Shopify handles discounts at order level
            'tax_amount' => 0, // Shopify handles tax at order level
            'line_total' => ($item['price'] ?? 0) * ($item['quantity'] ?? 1),
        ];
    }

    private static function mapWooCommerceLineItem(array $item): array
    {
        return [
            'external_line_item_id' => (string)$item['id'],
            'product_id' => $item['product_id'] ?? null,
            'sku' => $item['sku'] ?? null,
            'name' => $item['name'] ?? null,
            'quantity' => $item['quantity'] ?? 1,
            'unit_price' => $item['price'] ?? 0,
            'line_subtotal' => $item['subtotal'] ?? 0,
            'discount_amount' => 0,
            'tax_amount' => $item['total_tax'] ?? 0,
            'line_total' => $item['total'] ?? 0,
        ];
    }

    private static function mapShopifyStatus(string $status): string
    {
        $statusMap = [
            'pending' => 'pending',
            'authorized' => 'processing',
            'partially_paid' => 'processing',
            'paid' => 'completed',
            'partially_refunded' => 'completed',
            'refunded' => 'refunded',
            'voided' => 'cancelled'
        ];

        return $statusMap[$status] ?? 'pending';
    }

    /**
     * Normalize external status codes into our canonical codes used in the app
     */
    private static function normalizeStatusCode(?string $status): ?string
    {
        if ($status === null) return null;
        $s = strtolower(trim($status));
        // unify separators first
        $s = str_replace([' ', '-'], ['_', '_'], $s);

        // Map common aliases to canonical codes
        $map = [
            'on_hold' => 'on_hold',
            'on-hold' => 'on_hold',
            'on hold' => 'on_hold',
            // Treat Woo 'pending' / 'pending payment' as pending
            'pending' => 'pending',
            'pending_payment' => 'pending',
            'pending-payment' => 'pending',
            'pending payment' => 'pending',
            'processing' => 'processing',
            'out_for_delivery' => 'out_for_delivery',
            'out-for-delivery' => 'out_for_delivery',
            'delivered' => 'delivered',
            'completed' => 'delivered',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
        ];
        return $map[$s] ?? $s; // fallback to normalized underscores
    }

    /**
     * Normalize payment method from external sources to our standard values
     */
    private static function normalizePaymentMethod(?string $paymentMethod): string
    {
        if (!$paymentMethod) {
            return 'cash'; // Default to cash
        }

        $method = strtolower(trim($paymentMethod));

        // Log the original payment method for debugging
        \Log::info('Normalizing payment method', [
            'original' => $paymentMethod,
            'lowercase' => $method
        ]);

        // Mapping from external payment methods to our standard values
        $methodMap = [
            // Cash variants
            'cash' => 'cash',
            'cash_on_delivery' => 'cash_on_delivery',
            'cod' => 'cash_on_delivery',
            
            // Bank transfer variants
            'bank_transfer' => 'bank_transfer',
            'direct_bank_transfer' => 'bank_transfer',
            'bacs' => 'bank_transfer',
            'wire_transfer' => 'bank_transfer',
            'manual' => 'bank_transfer',
            
            // Card variants
            'card' => 'card',
            'credit_card' => 'card',
            'debit_card' => 'card',
            'visa' => 'card',
            'mastercard' => 'card',
            'amex' => 'card',
            
            // Online payment variants
            'online' => 'online',
            'online_payment' => 'online',
            'paypal' => 'online',
            'stripe' => 'online',
            'razorpay' => 'online',
            'square' => 'online',
            'authorize.net' => 'online',
            'shopify_payments' => 'online',
            'bogus' => 'online', // Shopify test gateway
        ];

        // Check for partial matches if exact match not found
        if (!isset($methodMap[$method])) {
            // Check if it contains keywords
            if (strpos($method, 'bank') !== false || strpos($method, 'transfer') !== false) {
                $normalized = 'bank_transfer';
            } elseif (strpos($method, 'cash') !== false || strpos($method, 'cod') !== false) {
                $normalized = 'cash';
            } elseif (strpos($method, 'card') !== false || strpos($method, 'visa') !== false || strpos($method, 'master') !== false) {
                $normalized = 'card';
            } elseif (strpos($method, 'online') !== false || strpos($method, 'paypal') !== false || strpos($method, 'stripe') !== false) {
                $normalized = 'online';
            } else {
                $normalized = 'cash'; // Default fallback
            }
        } else {
            $normalized = $methodMap[$method];
        }

        \Log::info('Payment method normalized', [
            'original' => $paymentMethod,
            'normalized' => $normalized
        ]);

        return $normalized;
    }

    /**
     * Coarse payment channel for UI badges: 'online' vs 'cash'.
     *
     * Unlike normalizePaymentMethod() (5 buckets + a Log::info on every call),
     * this is a side-effect-free binary split for display chips. The stored
     * column holds RAW gateway strings (e.g. 'online_payment', 'bank_transfer',
     * 'cod') — NOT the normalized value — so match loosely: anything cash/COD-like
     * (or blank, the system default) is cash; everything else (transfer, card,
     * online, wallets) is online.
     */
    public static function paymentChannel(?string $paymentMethod): string
    {
        $m = strtolower(trim((string) $paymentMethod));
        if ($m === '' || str_contains($m, 'cash') || str_contains($m, 'cod')) {
            return 'cash';
        }
        return 'online';
    }

    /**
     * Reconcile current status flag and main order table using latest history by changed_at
     * Defensive utility for cases where external updates created inconsistent flags
     */
    public static function reconcileCurrentStatus(int $orderId): void
    {
        \DB::transaction(function () use ($orderId) {
            $latest = \DB::table('t_crm_order_status_history')
                ->where('order_id', $orderId)
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first();

            if (!$latest) {
                return; // nothing to reconcile
            }

            // Demote all, promote the latest
            \DB::table('t_crm_order_status_history')
                ->where('order_id', $orderId)
                ->update(['is_current' => 0]);

            \DB::table('t_crm_order_status_history')
                ->where('id', $latest->id)
                ->update(['is_current' => 1]);

            // Update main order table to reflect latest status
            \DB::table('t_crm_prod_order')
                ->where('id', $orderId)
                ->update([
                    'order_status' => $latest->status_code,
                    'updated_at' => now(),
                ]);
        });
    }
    // Status Management Methods
    /**
     * Transient flag (Jul-2026) — NOT persisted, NOT an Eloquent attribute.
     * Set by changeStatus() when a transition to 'delivered' ATTEMPTED to post
     * the invoice to the ledger and the posting failed (e.g. "No rider assigned
     * to order"). Callers read it to surface the failure to the user instead of
     * letting it die quietly in the log — a delivered order with no ledger
     * entry has no retry mechanism, so the person delivering must know NOW.
     * Declared as a real class property so it can never leak into $attributes,
     * save() or toArray(). Stays null when posting succeeded or was legitimately
     * skipped (pre-received payments / shop-online rules).
     */
    public ?string $lastInvoicePostError = null;

    /**
     * Transient companion to $lastInvoicePostError (Jul-2026) for a NON-fatal
     * heads-up: the invoice posted OK but not to the usual place — currently
     * "no rider assigned, so the cash invoice went to NF Cash (Main Till)".
     * Same non-persisted/non-serialized guarantees as $lastInvoicePostError.
     */
    public ?string $lastInvoiceNote = null;

    public function changeStatus(string $statusCode, ?string $notes = null, ?int $changedBy = null): bool
    {
        $this->lastInvoicePostError = null;
        $this->lastInvoiceNote = null;
        // Phase 1 (May-2026) — capture the prior NF status BEFORE the
        // transaction mutates $this->order_status, so the customer-app
        // webhook emitter (called post-commit below) can include it as
        // payload.data.previous_status. Using getOriginal() reads the
        // Eloquent-cached value, which is the status as it was loaded
        // from the DB regardless of any intermediate in-memory edits.
        $previousNfStatus = $this->getOriginal('order_status');

        try {
            // Validate status exists
            $newStatus = OrderStatusMaster::getByCode($statusCode);
            if (!$newStatus) {
                throw new \InvalidArgumentException("Invalid status code: {$statusCode}");
            }

            // No transition enforcement - allow any status change
            // This supports API/webhook updates and flexible status management

            // Handle everything at application level (no triggers needed)
            $result = \DB::transaction(function () use ($statusCode, $notes, $changedBy, $newStatus) {
                \Log::info("OrderModel::changeStatus - Starting transaction", [
                    'order_id' => $this->id,
                    'status_code' => $statusCode,
                    'status_id' => $newStatus->id
                ]);

                // 1. Mark all previous status history as not current
                $updatedRows = \DB::table('t_crm_order_status_history')
                    ->where('order_id', $this->id)
                    ->where('is_current', 1)
                    ->update(['is_current' => 0]);

                \Log::info("OrderModel::changeStatus - Marked previous history as not current", [
                    'order_id' => $this->id,
                    'updated_rows' => $updatedRows
                ]);

                // 2. Create new status history record
                $historyData = [
                    'order_id' => $this->id,
                    'status_id' => $newStatus->id,
                    'status_code' => $statusCode,
                    'is_current' => 1,
                    'changed_by' => $changedBy ?? (auth()->check() ? auth()->id() : 1),
                    'notes' => $notes ?? "Status changed to {$statusCode}",
                    'changed_at' => now(),
                    'created_at' => now()
                ];

                \Log::info("OrderModel::changeStatus - Inserting new history record", [
                    'order_id' => $this->id,
                    'history_data' => $historyData
                ]);

                $historyId = \DB::table('t_crm_order_status_history')->insertGetId($historyData);

                \Log::info("OrderModel::changeStatus - History record created", [
                    'order_id' => $this->id,
                    'history_id' => $historyId
                ]);

                // 3. Update the main order table
                $this->order_status = $statusCode;
                // Route hygiene: an order pulled OUT of the active run must not
                // keep its planned delivery slot. Clear it so the remaining
                // van/up-next stops close up (clients derive labels from
                // position, so no gap shows). We intentionally do NOT clear on
                // 'out_for_delivery' or 'processing' (that IS the carry-forward:
                // a promoted or cancelled-back order keeps its number so the
                // route is preserved), nor on 'delivered'/'completed' (the
                // dispatch tracker compares planned delivery_priority vs the
                // actual delivered sequence).
                if (in_array($statusCode, ['on_hold', 'cancelled', 'refunded'], true)) {
                    $this->delivery_priority = null;
                }
                // Same hygiene for the dispatch ETA, which is a SNAPSHOT of one
                // run: it was computed from a rider position and a stop order
                // that stop no longer has. Leaving it on an order pulled off the
                // van meant that when the order went back out it still carried
                // the old time — invisible while it sat in processing (the ETA
                // is only ever read for out_for_delivery), then quoted again the
                // moment it returned: hidden from "Dispatch Next" (which only
                // times stops with NO ETA, so it never got re-timed), and
                // embedded in the WhatsApp invoice by EtaWindowService::forOrder.
                //
                // Deliberately an explicit list, not "anything except OFD":
                // this method takes any code present in t_crm_order_status_master
                // (which still holds a legacy hyphenated 'on-hold', and the Hub
                // can add more), so an inverse test would clear ETAs for statuses
                // nobody has thought about yet. Unknown status ⇒ keep, as today.
                //
                // NOT cleared for 'delivered'/'completed': the dispatch tracker
                // rebuilds each batch's planned sequence from estimated_delivery_at
                // (planned_sequence), so wiping it there would blind the report.
                // NOT cleared for 'out_for_delivery' — that's the live run itself.
                if (in_array($statusCode, ['on_hold', 'cancelled', 'refunded', 'processing', 'new', 'pending'], true)) {
                    $this->estimated_delivery_at = null;
                    $this->eta_calculated_at = null;
                    $this->eta_calculated_by = null;
                }
                if (method_exists($this, 'setAttribute')) {
                    $this->setAttribute('updated_by', $changedBy ?? (auth()->check() ? auth()->id() : 1));
                }
                $this->save();

                \Log::info("OrderModel::changeStatus - Main order table updated", [
                    'order_id' => $this->id,
                    'new_status' => $this->order_status
                ]);

                // 4. If status changed to 'cancelled', reverse ledger if exists
                if ($statusCode === 'cancelled' && $this->ledger_transaction_id) {
                    try {
                        $ledger = \App\Models\FIN\LedgerModel::find($this->ledger_transaction_id);
                        
                        if ($ledger && $ledger->approval_status !== \App\Models\FIN\LedgerModel::STATUS_REVERSED) {
                            // Check if ledger is settled
                            if ($ledger->settlement_status === 'settled') {
                                throw new \Exception('Cannot cancel order: Invoice has already been settled. Please reverse the settlement first.');
                            }
                            
                            // Check for partial settlement
                            if ($ledger->settlement_status === 'partial' || ($ledger->settled_amount > 0)) {
                                throw new \Exception('Cannot cancel order: Invoice has partial settlement. Please reverse the settlement first.');
                            }
                            
                            // Reverse the ledger entry
                            $this->reverseLedgerForCancellation($ledger);
                            
                            \Log::info("Ledger reversed for cancelled order", [
                                'order_id' => $this->id,
                                'ledger_id' => $ledger->id
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error("Failed to reverse ledger for cancelled order", [
                            'order_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Re-throw to prevent status change if ledger reversal fails
                        throw $e;
                    }
                }

                // 4b. If cancelled and has pre-received payments, reverse their ledger entries too
                if ($statusCode === 'cancelled') {
                    try {
                        $paymentLedgerIds = \DB::table('t_crm_order_payments')
                            ->where('order_id', $this->id)
                            ->where('status', 'active')
                            ->whereNotNull('ledger_transaction_id')
                            ->pluck('ledger_transaction_id');

                        $reversedCount = 0;
                        foreach ($paymentLedgerIds as $ledgerId) {
                            $ledger = \App\Models\FIN\LedgerModel::find($ledgerId);
                            if ($ledger && $ledger->approval_status !== \App\Models\FIN\LedgerModel::STATUS_REVERSED) {
                                if ($ledger->settlement_status === 'settled' || ($ledger->settled_amount > 0)) {
                                    \Log::warning("Cannot reverse settled payment ledger entry on cancellation", [
                                        'order_id' => $this->id,
                                        'ledger_id' => $ledgerId,
                                    ]);
                                    continue;
                                }
                                $this->reverseLedgerForCancellation($ledger);
                                $reversedCount++;
                            }
                        }

                        // Mark all payments as voided
                        \DB::table('t_crm_order_payments')
                            ->where('order_id', $this->id)
                            ->where('status', 'active')
                            ->update(['status' => 'voided', 'updated_at' => now()]);

                        $this->payment_status = 'unpaid';
                        $this->total_paid = 0;
                        $this->save();

                        if ($reversedCount > 0) {
                            \Log::info("Reversed payment ledger entries for cancelled order", [
                                'order_id' => $this->id,
                                'reversed_count' => $reversedCount,
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error("Failed to reverse payment ledger entries for cancelled order", [
                            'order_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't block cancellation for payment reversal failures
                    }
                }

                // ⭐ INVENTORY RESTORATION: Restore store inventory on order cancellation
                // Only restores inventory for line items that actually had inventory deducted
                // (inventory_deducted = 1). This works for both old orders (backfilled by migration)
                // and new orders (flagged when marked as prepared).
                if ($statusCode === 'cancelled') {
                    try {
                        if (!$this->relationLoaded('lineItems')) {
                            $this->load('lineItems');
                        }
                        
                        $restoredCount = 0;
                        foreach ($this->lineItems as $lineItem) {
                            if ($lineItem->inventory_deducted) {
                                if ($lineItem->restoreInventory()) {
                                    $restoredCount++;
                                }
                            }
                        }
                        
                        if ($restoredCount > 0) {
                            \Log::info('Inventory restored on order cancellation', [
                                'order_id' => $this->id,
                                'order_number' => $this->order_number,
                                'items_restored' => $restoredCount,
                                'total_items' => $this->lineItems->count(),
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error("Failed to restore inventory for cancelled order", [
                            'order_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't block cancellation if inventory restore fails
                    }
                }

                // 5. If this status is an "out the door" status (auto_prepares in the Status Hub),
                // auto-prepare all unprepared items and deduct their inventory. Items already marked
                // as prepared will be skipped (their inventory_deducted flag is already 1).
                // Historically this was hardcoded to out_for_delivery/delivered; it now reads the
                // Status Hub so a new post-dispatch status the owner adds behaves the same way.
                // (The rule service falls back to that exact literal if the Hub columns are absent.)
                if (app(\App\Services\CRM\OrderStatusRuleService::class)->autoPreparesOn($statusCode)) {
                    try {
                        if (!$this->relationLoaded('lineItems')) {
                            $this->load('lineItems');
                        }

                        // Stamp provenance only if the column exists (deploy-order safe: if the
                        // Status Hub migration hasn't run yet, we still auto-prepare + deduct).
                        static $hasPreparedSource = null;
                        if ($hasPreparedSource === null) {
                            $hasPreparedSource = \Schema::hasColumn('t_crm_prod_order_line_item', 'prepared_source');
                        }

                        $autoPreparedCount = 0;
                        $autoDeductedCount = 0;
                        foreach ($this->lineItems as $lineItem) {
                            // Auto-mark as prepared if not already
                            if ($lineItem->preparation_status !== 'preparing') {
                                $lineItem->preparation_status = 'preparing';
                                if ($hasPreparedSource) {
                                    $lineItem->prepared_source = 'auto';
                                }
                                $lineItem->save();
                                $autoPreparedCount++;
                            }

                            // Deduct inventory if not already deducted
                            // (covers both newly auto-prepared and any edge case where
                            //  an item was marked prepared but deduction was missed)
                            if (!$lineItem->inventory_deducted) {
                                if ($lineItem->deductInventory()) {
                                    $autoDeductedCount++;
                                }
                            }
                        }

                        if ($autoPreparedCount > 0 || $autoDeductedCount > 0) {
                            \Log::info("Auto-prepared items on {$statusCode}", [
                                'order_id' => $this->id,
                                'order_number' => $this->order_number,
                                'status_trigger' => $statusCode,
                                'items_auto_prepared' => $autoPreparedCount,
                                'items_auto_deducted' => $autoDeductedCount,
                                'total_items' => $this->lineItems->count(),
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error("Failed to auto-prepare items for {$statusCode}", [
                            'order_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't block status change if auto-prepare fails
                    }
                }

                // 6. If status changed to 'delivered', post invoice to ledger
                //    GUARD: Skip if order has pre-received payments (qurbani flow).
                //    Those payments already have their own ledger entries.
                if ($statusCode === 'delivered') {
                    try {
                        if ($this->hasPreReceivedPayments()) {
                            \Log::info("Skipping invoice posting - order has pre-received payments (qurbani flow)", [
                                'order_id' => $this->id,
                                'order_number' => $this->order_number,
                                'total_paid' => $this->total_paid,
                            ]);
                        } else {
                            // Ensure customer relationship is loaded for ledger description
                            if (!$this->relationLoaded('customer')) {
                                $this->load('customer');
                            }
                            
                            $ledgerService = new \App\Services\FIN\LedgerPostingService();
                            $result = $ledgerService->postInvoiceFromOrder($this);
                            
                            if ($result['success']) {
                                \Log::info("Invoice posted to ledger", [
                                    'order_id' => $this->id,
                                    'ledger_id' => $result['ledger_id'] ?? null
                                ]);
                                // Non-fatal heads-up (e.g. routed to Main Till
                                // because no rider was assigned) — surface it.
                                if (!empty($result['posted_to_main_till'])) {
                                    $this->lastInvoiceNote = $result['message'] ?? null;
                                }
                            } else {
                                \Log::warning("Failed to post invoice to ledger", [
                                    'order_id' => $this->id,
                                    'message' => $result['message'] ?? 'Unknown error'
                                ]);
                                // Surface to the caller (see property docblock above)
                                $this->lastInvoicePostError = $result['message'] ?? 'Unknown error';
                            }
                        }
                    } catch (\Exception $e) {
                        \Log::error("Exception posting invoice to ledger", [
                            'order_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't fail the status change if ledger posting fails —
                        // but surface it to the caller (see property docblock above)
                        $this->lastInvoicePostError = $e->getMessage();
                    }
                }

                // Refresh and return
                $this->refresh();
                return true;
            });

            // Phase 1 (May-2026) — Customer-app outbound webhook.
            // Runs AFTER the transaction has committed, so a successful
            // status change is persisted before we queue the event. The
            // emitter is wrapped in its own try/catch and only writes to
            // an outbox table (no HTTP), so it cannot block the caller
            // or break this method's contract. Filtered to SH- orders
            // inside the emitter — non-SH orders are silent no-ops.
            if ($result === true) {
                try {
                    app(\App\Services\CustomerAppWebhookEmitter::class)
                        ->emitStatusChange($this, $previousNfStatus);
                } catch (\Throwable $emitException) {
                    \Log::error('CustomerAppWebhookEmitter call failed (non-fatal)', [
                        'order_id'   => $this->id,
                        'status_code' => $statusCode,
                        'error'      => $emitException->getMessage(),
                    ]);
                }
            }

            // Jul-2026 — Auto location-request on ACTIVATION (Option B). A future-day
            // order accepted via the delivery buttons is parked in 'pending'; when the
            // team activates it (pending -> new) on its delivery day, ask the customer
            // for their delivery location now. The location enqueue otherwise fires only
            // on order create / Shopify convert, so this transition would never trigger
            // it. enqueue() is internally a no-op when the automation is off and
            // re-validates eligibility at drain time, so this is safe + non-fatal.
            if ($result === true && $previousNfStatus === 'pending' && $statusCode === 'new' && $this->customer_id) {
                try {
                    $locSvc = app(\App\Services\Location\OpenOrderLocationService::class);
                    $locSvc->enqueue((int) $this->customer_id, (int) $this->id, 'activate');
                    $locSvc->fireFromRequest();
                } catch (\Throwable $locException) {
                    \Log::warning('Auto location-request enqueue on activation failed (non-fatal)', [
                        'order_id' => $this->id,
                        'error' => $locException->getMessage(),
                    ]);
                }
            }

            // Jul-2026 — WhatsApp "delivered" automations (e.g. the seasonal
            // meat-storage guidelines). changeStatus() is the SINGLE choke point
            // for a delivery — the rider app, the web edit form, bulk CSV status
            // imports and OrderStatusService all route through here — so this one
            // seam covers every path. Deferred to app terminating so the WhatsApp
            // call never holds up the rider's tap, and fully isolated so it can
            // never affect the committed status change. Dormant unless the
            // operator has enabled the rule AND the master switch is on; the
            // handler re-reads the order fresh and applies its own skips
            // (send-once per order, per-customer cooldown, shop customers,
            // back-fill guard).
            if ($result === true && $statusCode === 'delivered') {
                try {
                    $deliveredOrder = $this->load(['customer']);
                    app()->terminating(function () use ($deliveredOrder) {
                        try {
                            app(\App\Services\WhatsApp\Automation\WhatsAppAutomationService::class)
                                ->dispatch('order.delivered', [
                                    'order'    => $deliveredOrder,
                                    'order_id' => $deliveredOrder->id,
                                ]);
                        } catch (\Throwable $e) {
                            \Log::warning('WA order.delivered automation failed', [
                                'order_id' => $deliveredOrder->id ?? null,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    });
                } catch (\Throwable $e) {
                    \Log::warning('WA order.delivered scheduling failed', ['error' => $e->getMessage()]);
                }
            }

            // Jul-2026 — Shopify fulfillment sync. When an SH- (Shopify-origin)
            // order is DELIVERED, mark the origin order Fulfilled + Paid on
            // Shopify. Runs in app()->terminating() so the caller's response
            // (rider app tap, web edit, bulk CSV) is never held up by Shopify
            // HTTP; the sync itself is fully non-fatal (logs only). SH- number
            // → Shopify order id resolution lives in ShopifyFulfillmentSync
            // (external_id is nulled on conversion by design, so it goes via
            // the t_crm_shopify_order staging row).
            if ($result === true && $statusCode === 'delivered'
                && str_starts_with((string) $this->order_number, 'SH-')) {
                $syncOrderId = $this->id;
                $syncOrderNumber = (string) $this->order_number;
                app()->terminating(function () use ($syncOrderId, $syncOrderNumber) {
                    try {
                        app(\App\Services\ShopifyFulfillmentSync::class)
                            ->syncDelivered($syncOrderId, $syncOrderNumber);
                    } catch (\Throwable $syncException) {
                        \Log::error('ShopifySync hook failed (non-fatal)', [
                            'order_id' => $syncOrderId,
                            'order_number' => $syncOrderNumber,
                            'error' => $syncException->getMessage(),
                        ]);
                    }
                });
            }

            return $result;
        } catch (\Exception $e) {
            \Log::error("Failed to change order status: " . $e->getMessage(), [
                'order_id' => $this->id,
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function getCurrentStatus(): ?OrderStatusMaster
    {
        return OrderStatusMaster::getByCode($this->order_status);
    }

    /**
     * Relationship: Get invoice approval request for online invoices
     */
    public function invoiceRequest()
    {
        return $this->hasOne(\App\Models\Request\RequestModel::class, 'order_id')
                    ->whereHas('category', function($q) {
                        $q->where('category_code', 'invoice_approval');
                    });
    }

    /**
     * Reverse a ledger entry when order is cancelled
     * 
     * @param \App\Models\FIN\LedgerModel $ledger
     * @return void
     * @throws \Exception
     */
    private function reverseLedgerForCancellation($ledger)
    {
        $wasApproved = $ledger->approval_status === \App\Models\FIN\LedgerModel::STATUS_APPROVED;
        
        // Mark ledger as reversed
        $ledger->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED;
        $ledger->comments = ($ledger->comments ? $ledger->comments . "\n\n" : '') . 
                           "REVERSED: Order #{$this->order_number} was cancelled";
        $ledger->save();
        
        // Reverse the balance move via the canonical engine. It self-guards on balance_updated, so
        // it correctly reverses a row that was applied at L1 (pending_l2) as well as a fully approved
        // one — fixing the old `$wasApproved` guard that LEAKED money when an order was cancelled
        // during the L1→L2 window (it only reversed status===approved rows). Engine reverse is the
        // exact inverse of the invoice/order_payment posting (income +, holder −).
        $balancesReversed = (bool) $ledger->balance_updated;
        (new \App\Services\FIN\BalancePostingService())->reverse($ledger);

        \Log::info("Ledger entry reversed for cancellation", [
            'ledger_id' => $ledger->id,
            'order_id' => $this->id,
            'was_approved' => $wasApproved,
            'balances_reversed' => $balancesReversed
        ]);
    }

    /**
     * Create initial status history for new orders
     * This replaces the trigger functionality
     */
    public function createInitialStatusHistory(): bool
    {
        try {
            // Check if history already exists
            $existingHistory = \DB::table('t_crm_order_status_history')
                ->where('order_id', $this->id)
                ->exists();

            if ($existingHistory) {
                return true; // Already has history
            }

            $status = OrderStatusMaster::getByCode($this->order_status);
            if (!$status) {
                \Log::warning("Cannot create initial status history: status '{$this->order_status}' not found in master table for order {$this->id}");
                return false;
            }

            \DB::table('t_crm_order_status_history')->insert([
                'order_id' => $this->id,
                'status_id' => $status->id,
                'status_code' => $this->order_status,
                'is_current' => 1,
                'changed_by' => $this->created_by ?? 1,
                'notes' => 'Initial order status',
                'changed_at' => $this->created_at ?? now(),
                'created_at' => $this->created_at ?? now()
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to create initial status history: " . $e->getMessage(), [
                'order_id' => $this->id,
                'order_status' => $this->order_status
            ]);
            return false;
        }
    }

    public function getStatusHistory()
    {
        return $this->statusHistory()->with(['status', 'changedBy'])->get();
    }

    public function getAvailableStatusTransitions()
    {
        $currentStatus = $this->getCurrentStatus();
        return $currentStatus ? $currentStatus->getAvailableTransitions() : collect();
    }

    public function canChangeToStatus(string $statusCode): bool
    {
        $currentStatus = $this->getCurrentStatus();
        $newStatus = OrderStatusMaster::getByCode($statusCode);
        
        if (!$currentStatus || !$newStatus) {
            return false;
        }

        return $currentStatus->canTransitionTo($newStatus);
    }

    public function getStatusDisplayInfo(): array
    {
        $status = $this->getCurrentStatus();
        
        if (!$status) {
            return [
                'code' => $this->order_status,
                'name' => ucfirst(str_replace('_', ' ', $this->order_status ?? 'unknown')),
                'color_class' => 'gray',
                'icon' => '?',
                'is_final' => false
            ];
        }

        return [
            'code' => $status->status_code,
            'name' => $status->status_name,
            'color_class' => $status->color_class,
            'icon' => $status->icon,
            'is_final' => $status->is_final
        ];
    }

    // Bulk status operations
    public static function bulkChangeStatus(array $orderIds, string $statusCode, ?string $notes = null, ?int $changedBy = null): array
    {
        $results = ['success' => [], 'failed' => []];
        
        foreach ($orderIds as $orderId) {
            try {
                $order = static::find($orderId);
                if (!$order) {
                    $results['failed'][] = ['id' => $orderId, 'error' => 'Order not found'];
                    continue;
                }

                if ($order->changeStatus($statusCode, $notes, $changedBy)) {
                    $results['success'][] = $orderId;
                } else {
                    $results['failed'][] = ['id' => $orderId, 'error' => 'Status change failed'];
                }
            } catch (\Exception $e) {
                $results['failed'][] = ['id' => $orderId, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }
}