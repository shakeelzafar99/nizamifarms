# Shopify Tip Handling Analysis - October 20, 2025

## Problem

**Current Issue**: Tip appears as a line item (product) in Shopify orders, causing conversion to fail with error: "Line item 'Tip' has no SKU"

**Expected Behavior**: Tip should be handled as an order-level field (like shipping/delivery fee), not as a product line item.

---

## Current Implementation Analysis

### 1. **Webhook Reception** ✅ Working Correctly
**File**: `app/Http/Controllers/Webhook/ShopifyController.php` (line 90-154)

- Webhook receives Shopify order data
- HMAC verification working
- Calls `OrderModel::mapShopifyOrder($payload)`
- Calls `OrderModel::storeOrderFromApi($orderData)`

**Status**: ✅ No changes needed - webhook is stable and working

---

### 2. **Order Mapping** ✅ Tip Already Captured
**File**: `app/Models/CRM/OrderModel.php` (line 525-590)

**Current Logic**:
```php
// Extract tip amount from Shopify webhook
$tipAmount = 0;
if (isset($shopifyOrder['current_total_tip_set']['shop_money']['amount'])) {
    $tipAmount = $shopifyOrder['current_total_tip_set']['shop_money']['amount'];
} elseif (isset($shopifyOrder['total_tip_received'])) {
    $tipAmount = $shopifyOrder['total_tip_received'];
}

$orderData = [
    // ...
    'tip_amount' => $tipAmount,  // ✅ Already captured at order level
    // ...
    'line_items' => array_map([static::class, 'mapShopifyLineItem'], $shopifyOrder['line_items'] ?? [])
];
```

**Issue**: `$shopifyOrder['line_items']` includes the tip as a line item!

**Shopify sends tip in TWO places**:
1. ✅ Order-level field: `current_total_tip_set.shop_money.amount` (already captured)
2. ❌ Line item: A product called "Tip" with no SKU (causing the error)

---

### 3. **Line Item Mapping** ⚠️ Problem Here
**File**: `app/Models/CRM/OrderModel.php` (line 646-662)

```php
private static function mapShopifyLineItem(array $item): array
{
    return [
        'external_line_item_id' => (string)$item['id'],
        'product_id' => $item['product_id'] ?? null,
        'variant_id' => $item['variant_id'] ?? null,
        'sku' => $item['sku'] ?? null,  // ❌ Tip has no SKU!
        'name' => $item['name'] ?? null,  // "Tip"
        'vendor' => $item['vendor'] ?? null,
        'quantity' => $item['quantity'] ?? 1,
        'unit_price' => $item['price'] ?? 0,
        // ...
    ];
}
```

**Problem**: This method blindly maps ALL line items, including the tip.

---

### 4. **Order Conversion** ❌ Fails Here
**File**: `app/Http/Controllers/CRM/OrderController.php` (line 909-1004)

**Validation Logic** (line 1029-1033):
```php
foreach ($shopifyOrder->lineItems as $lineItem) {
    if (!$lineItem->sku) {
        $validationErrors[] = "Line item '{$lineItem->name}' has no SKU";  // ❌ Fails for "Tip"
        continue;
    }
    // ...
}
```

**This is where the error occurs!**

---

## Root Cause

**Shopify's Tip Behavior**:
- When a customer adds a tip, Shopify includes it as BOTH:
  1. An order-level field (`current_total_tip_set`)
  2. A line item with name "Tip" and no SKU

**Our Current Logic**:
- ✅ Captures tip at order level
- ❌ Also tries to process tip as a line item
- ❌ Conversion fails because tip line item has no SKU

---

## Solution Strategy

### Option 1: Filter Out Tip Line Items (Recommended) ✅

**Approach**: Exclude tip line items during mapping, since we already capture tip at order level.

**Pros**:
- ✅ Clean solution
- ✅ No database changes
- ✅ Webhook stays stable
- ✅ Conversion works
- ✅ Tip still captured and displayed

**Cons**:
- None

**Implementation**:
```php
// In mapShopifyOrder(), filter out tip line items
'line_items' => array_map(
    [static::class, 'mapShopifyLineItem'], 
    array_filter($shopifyOrder['line_items'] ?? [], function($item) {
        // Exclude tip line items (they're handled at order level)
        $name = strtolower($item['name'] ?? '');
        return $name !== 'tip' && !empty($item['sku']);
    })
)
```

---

### Option 2: Handle Tip Line Items Specially

**Approach**: Detect tip line items and skip SKU validation for them.

**Pros**:
- Preserves tip in line items

**Cons**:
- ❌ Redundant (tip already in order-level field)
- ❌ More complex
- ❌ Tip shouldn't be a product

**Not recommended** - tip is not a product and shouldn't be in line items.

---

## Recommended Implementation

### Change 1: Filter Tip Line Items During Mapping

**File**: `app/Models/CRM/OrderModel.php`

**Location**: Line 586 in `mapShopifyOrder()` method

**Before**:
```php
'line_items' => array_map([static::class, 'mapShopifyLineItem'], $shopifyOrder['line_items'] ?? [])
```

**After**:
```php
'line_items' => array_map(
    [static::class, 'mapShopifyLineItem'], 
    array_filter($shopifyOrder['line_items'] ?? [], function($item) {
        // Exclude tip line items - they're captured at order level in tip_amount field
        // Shopify sends tips as both an order field AND a line item, we only need the order field
        $name = strtolower(trim($item['name'] ?? ''));
        $isTip = $name === 'tip' || $name === 'tips';
        
        // Also exclude items without SKU (invalid products)
        $hasSku = !empty($item['sku']);
        
        return !$isTip && $hasSku;
    })
)
```

---

### Change 2: Add Safety Check in Line Item Mapping (Belt & Suspenders)

**File**: `app/Models/CRM/OrderModel.php`

**Location**: Line 646 in `mapShopifyLineItem()` method

**Add at the beginning**:
```php
private static function mapShopifyLineItem(array $item): array
{
    // Safety check: Skip tip line items (should already be filtered, but just in case)
    $name = strtolower(trim($item['name'] ?? ''));
    if ($name === 'tip' || $name === 'tips') {
        return null;  // Will be filtered out by array_filter
    }
    
    return [
        // ... existing code
    ];
}
```

---

## Impact Analysis

### ✅ **Webhook Safety**
- **No changes to webhook controller** - stays stable
- **No changes to HMAC verification** - stays secure
- **No changes to database structure** - no migration needed
- **Tip still captured** - in `tip_amount` field at order level

### ✅ **Data Integrity**
- **Tip amount preserved** - stored in `t_crm_shopify_order.tip_amount`
- **Tip displayed correctly** - shown in order details and invoices
- **No duplicate tip** - not counted twice (once as line item, once as order field)

### ✅ **Conversion Flow**
- **SKU validation passes** - tip not in line items anymore
- **Price calculation correct** - tip added to total, not as product
- **Invoice generation works** - tip shown in delivery/shipping section

### ✅ **Backward Compatibility**
- **Existing orders unaffected** - only affects new webhooks
- **No data migration needed** - tip_amount field already exists
- **Existing invoices unchanged** - no retroactive changes

---

## Testing Plan

### Test Case 1: New Shopify Order with Tip
1. Customer places order on Shopify with tip
2. Webhook received and processed
3. Order stored with tip in `tip_amount` field
4. Tip NOT in line items
5. Conversion succeeds
6. Invoice shows tip in delivery/shipping section

### Test Case 2: Shopify Order without Tip
1. Customer places order without tip
2. Webhook processed normally
3. `tip_amount` = 0
4. All line items processed
5. Conversion succeeds

### Test Case 3: Existing Orders
1. Orders already in system unaffected
2. Can still be converted
3. Tip (if any) shown correctly

---

## Display Logic

### Where Tip Should Appear

**Order Details View**:
```
Subtotal:     PKR 5,733.00
Discount:     -PKR 0.00
Shipping:     PKR 250.00
Tip:          PKR 273.00  ← Show here (not as line item)
Total:        PKR 6,256.00
```

**Invoice/Receipt**:
```
Line Items:
- Product 1: PKR 2,730.00
- Product 2: PKR 2,730.00

Subtotal:     PKR 5,733.00
Shipping:     PKR 250.00
Tip:          PKR 273.00  ← Show here
Total:        PKR 6,256.00
```

---

## Database Fields

### Already Exists ✅
- `t_crm_shopify_order.tip_amount` DECIMAL(10,2)
- `t_crm_prod_order.tip_amount` DECIMAL(10,2)

**No migration needed!**

---

## Files to Modify

1. **`app/Models/CRM/OrderModel.php`**
   - Line 586: Filter tip line items in `mapShopifyOrder()`
   - Line 646: Add safety check in `mapShopifyLineItem()`

2. **No other files need changes!**

---

## Risk Assessment

### Risk Level: **LOW** ✅

**Why Low Risk**:
- ✅ No webhook controller changes
- ✅ No database changes
- ✅ Only filtering logic added
- ✅ Tip still captured (order-level field)
- ✅ Backward compatible
- ✅ Easy to rollback (just remove filter)

**Potential Issues**:
- ⚠️ If Shopify changes tip line item name (unlikely)
  - **Mitigation**: Check for both "tip" and "tips", case-insensitive

**Rollback Plan**:
```php
// If issues occur, simply revert to:
'line_items' => array_map([static::class, 'mapShopifyLineItem'], $shopifyOrder['line_items'] ?? [])
```

---

## Status

⏳ **ANALYSIS COMPLETE - READY FOR IMPLEMENTATION**

**Next Steps**:
1. Implement filtering logic in `mapShopifyOrder()`
2. Add safety check in `mapShopifyLineItem()`
3. Test with sample Shopify webhook
4. Verify conversion works
5. Check invoice display

**Estimated Time**: 15 minutes  
**Risk**: Low  
**Impact**: High (fixes conversion error)

