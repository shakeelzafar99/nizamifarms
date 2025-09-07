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
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        // Removed 'order_date' => 'datetime' to preserve original timezone
        'subtotal_price' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total_price' => 'decimal:2',
        'total_weight' => 'integer'
    ];

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerModel::class, 'customer_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(OrderLineItemModel::class, 'order_id');
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
        
        // Parse the date string and preserve timezone
        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Exception $e) {
            // If parsing fails, return the original value
            return $value;
        }
    }

    /**
     * Store order with line items and customer management
     */
    public static function storeOrderFromApi(array $orderData): self
    {
        DB::beginTransaction();
        
        try {
            // Check for existing order
            $existingOrder = null;
            if (isset($orderData['external_source']) && isset($orderData['external_id'])) {
                $existingOrder = static::where('external_source', $orderData['external_source'])
                    ->where('external_id', $orderData['external_id'])
                    ->first();
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
            $orderAttributes['created_by'] = auth()->id();
            
            // Extract line items
            $lineItems = $orderAttributes['line_items'] ?? [];
            unset($orderAttributes['line_items']);

            // Create or update order
            if ($existingOrder) {
                $existingOrder->update($orderAttributes);
                $order = $existingOrder;
            } else {
                $order = static::create($orderAttributes);
            }

            // Store line items
            if (!empty($lineItems)) {
                // Delete existing line items if updating
                if ($existingOrder) {
                    $order->lineItems()->delete();
                }

                $lineItemModels = [];
                foreach ($lineItems as $lineItem) {
                    $lineItem['order_id'] = $order->id;
                    $lineItem['created_by'] = auth()->id();
                    $lineItemModels[] = new OrderLineItemModel($lineItem);
                }
                
                $order->lineItems()->saveMany($lineItemModels);
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
            'payment_method' => $shopifyOrder['gateway'] ?? $shopifyOrder['payment_gateway_names'][0] ?? 
                               (isset($shopifyOrder['transactions'][0]) ? $shopifyOrder['transactions'][0]['gateway'] : null),
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
            'order_status' => $wooOrder['status'] ?? 'pending',
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
            'payment_method' => $wooOrder['payment_method_title'] ?? $wooOrder['payment_method'] ?? null,
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
}