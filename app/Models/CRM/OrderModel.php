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
        'online_message_sent_by' => 'integer'
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
            // If the source is Shopify, route to ShopifyOrderModel while keeping customer flow
            $isShopify = isset($orderData['external_source']) && $orderData['external_source'] === 'shopify';

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
            $orderAttributes['customer_id'] = $customer?->id;
            $orderAttributes['created_by'] = auth()->check() ? auth()->id() : null;
            
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

            DB::commit();
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

                // Update denormalized column on order
                $orderUpdateData = [
                    'assigned_rider_user_id' => $riderUserId,
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
    public function changeStatus(string $statusCode, ?string $notes = null, ?int $changedBy = null): bool
    {
        try {
            // Validate status exists
            $newStatus = OrderStatusMaster::getByCode($statusCode);
            if (!$newStatus) {
                throw new \InvalidArgumentException("Invalid status code: {$statusCode}");
            }

            // No transition enforcement - allow any status change
            // This supports API/webhook updates and flexible status management

            // Handle everything at application level (no triggers needed)
            return \DB::transaction(function () use ($statusCode, $notes, $changedBy, $newStatus) {
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

                // 5. If status changed to 'out_for_delivery' or 'delivered', auto-prepare all
                // unprepared items and deduct their inventory. Items already marked as prepared
                // will be skipped (their inventory_deducted flag is already 1).
                // We include 'delivered' as a safety net in case an order skips out_for_delivery
                // (e.g. bulk CSV import, direct delivery marking).
                if (in_array($statusCode, ['out_for_delivery', 'delivered'])) {
                    try {
                        if (!$this->relationLoaded('lineItems')) {
                            $this->load('lineItems');
                        }

                        $autoPreparedCount = 0;
                        $autoDeductedCount = 0;
                        foreach ($this->lineItems as $lineItem) {
                            // Auto-mark as prepared if not already
                            if ($lineItem->preparation_status !== 'preparing') {
                                $lineItem->preparation_status = 'preparing';
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
                if ($statusCode === 'delivered') {
                    try {
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
                        } else {
                            \Log::warning("Failed to post invoice to ledger", [
                                'order_id' => $this->id,
                                'message' => $result['message'] ?? 'Unknown error'
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error("Exception posting invoice to ledger", [
                            'order_id' => $this->id,
                            'error' => $e->getMessage()
                        ]);
                        // Don't fail the status change if ledger posting fails
                    }
                }

                // Refresh and return
                $this->refresh();
                return true;
            });
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
        
        // Reverse balances ONLY if the transaction was approved
        if ($wasApproved) {
            $fromAccount = $ledger->fromAccount;
            $toAccount = $ledger->toAccount;
            
            if ($fromAccount) {
                // Reverse the debit (add back)
                $fromAccount->current_balance += $ledger->amount;
                $fromAccount->save();
                
                \Log::info("Reversed balance for from_account (cancellation)", [
                    'account_id' => $fromAccount->id,
                    'account_name' => $fromAccount->account_name,
                    'amount_added_back' => $ledger->amount,
                    'new_balance' => $fromAccount->current_balance
                ]);
            }
            
            if ($toAccount) {
                // Reverse the credit (subtract back)
                $toAccount->current_balance -= $ledger->amount;
                $toAccount->save();
                
                \Log::info("Reversed balance for to_account (cancellation)", [
                    'account_id' => $toAccount->id,
                    'account_name' => $toAccount->account_name,
                    'amount_subtracted' => $ledger->amount,
                    'new_balance' => $toAccount->current_balance
                ]);
            }
        }
        
        \Log::info("Ledger entry reversed for cancellation", [
            'ledger_id' => $ledger->id,
            'order_id' => $this->id,
            'was_approved' => $wasApproved,
            'balances_reversed' => $wasApproved
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