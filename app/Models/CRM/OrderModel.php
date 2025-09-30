<?php

namespace App\Models\CRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use App\Models\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'total_price' => 'decimal:2',
        'total_weight' => 'integer',
        'converted' => 'boolean'
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
                    $existingOrder->update($orderAttributes);
                    $order = $existingOrder;

                    // New rule: For WooCommerce updates, only accept the FIRST status from Woo.
                    // For subsequent edits, ignore status changes unless the new status is 'cancelled'.
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
                                $order->changeStatus($incomingNormalized, 'WooCommerce sync');
                            } else {
                                // Keep main order status as-is; ensure we don't overwrite it via update()
                                // by resetting it back to previous value
                                $order->order_status = $previousStatus;
                                $order->save();
                                \Log::info('WooCommerce status update ignored (non-cancelled subsequent edit)', [
                                    'order_id' => $order->id,
                                    'previous' => $previousStatus,
                                    'incoming' => $incomingNormalized,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            \Log::warning('storeOrderFromApi: failed to apply Woo status rule', [
                                'order_id' => $order->id,
                                'from' => $previousStatus,
                                'to' => $orderAttributes['order_status'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } else {
                    $order = static::create($orderAttributes);
                    // Create initial status history for new non-Shopify orders
                    $order->createInitialStatusHistory();
                }
            }

            // Store line items
            if (!empty($lineItems)) {
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
                } else {
                    $lineItemModels = [];
                    foreach ($lineItems as $lineItem) {
                        $lineItem['order_id'] = $order->id;
                        $lineItem['created_by'] = auth()->check() ? auth()->id() : null;
                        $lineItemModels[] = new OrderLineItemModel($lineItem);
                    }
                    $order->lineItems()->saveMany($lineItemModels);
                }
            }

            DB::commit();
            return $order->load(['customer', 'lineItems']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
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
            'note' => $shopifyOrder['note'] ?? null,
            
            // Line items
            'line_items' => array_map([static::class, 'mapShopifyLineItem'], $shopifyOrder['line_items'] ?? [])
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

    private static function mapShopifyLineItem(array $item): array
    {
        return [
            'external_line_item_id' => (string)$item['id'],
            'product_id' => $item['product_id'] ?? null,
            'variant_id' => $item['variant_id'] ?? null,
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
            'pending' => 'new',
            'on_hold' => 'on_hold',
            'on-hold' => 'on_hold',
            'on hold' => 'on_hold',
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