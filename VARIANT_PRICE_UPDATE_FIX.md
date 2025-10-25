# Variant Price Update Fix

## Issue
When importing WooCommerce products with price-only update mode, all 296 products failed with duplicate entry errors:

```
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '87441' for key 'uq_shopify_variant_id'
```

## Root Cause
The price-only update logic was trying to **INSERT** new variants instead of **UPDATE** existing ones because:

1. **Variants were matched only by SKU** (line 432 in ProductModel)
2. **WooCommerce variants are stored with `shopify_variant_id`** (which contains the WooCommerce variant ID)
3. **The code didn't try matching by `shopify_variant_id`** first, so it couldn't find existing variants
4. **It tried to create new variants**, which failed due to the unique constraint on `shopify_variant_id`

## The Problem in Detail

### Before Fix - Matching Logic
```php
// Only matched by SKU
$sku = $mappedVariant['sku'] ?? null;

if ($sku) {
    $existingVariant = $product->variants()->where('sku', $sku)->first();
    if ($existingVariant) {
        // Update prices ✅
    } else {
        // Try to INSERT new variant ❌ (fails with duplicate key error)
    }
}
```

### Why It Failed
```
WooCommerce Variant ID: 87441
↓
Stored as: shopify_variant_id = 87441, sku = 'P5-RBS'
↓
Import tries to match by SKU only
↓
If SKU doesn't match exactly → Thinks it's a new variant
↓
Tries to INSERT with shopify_variant_id = 87441
↓
❌ Duplicate key error: 87441 already exists!
```

## Solution Applied

### After Fix - Matching Logic
```php
// Match by shopify_variant_id FIRST, then by SKU
$variantId = $mappedVariant['shopify_variant_id'] ?? null;
$sku = $mappedVariant['sku'] ?? null;

$existingVariant = null;

// First try to match by shopify_variant_id (WooCommerce/Shopify variant ID)
if ($variantId) {
    $existingVariant = $product->variants()->where('shopify_variant_id', $variantId)->first();
}

// If not found and SKU exists, try matching by SKU
if (!$existingVariant && $sku) {
    $existingVariant = $product->variants()->where('sku', $sku)->first();
}

if ($existingVariant) {
    // Update prices ✅
} else {
    // Create new variant (only if truly new)
}
```

### Why It Works Now
```
WooCommerce Variant ID: 87441
↓
Stored as: shopify_variant_id = 87441, sku = 'P5-RBS'
↓
Import tries to match by shopify_variant_id = 87441
↓
✅ Match found!
↓
Updates only price fields (price, compare_at_price, cost_price)
↓
✅ Success! Categories, attributes, inventory preserved
```

## Code Changes

### File: `app/Models/CRM/ProductModel.php`

**Lines 425-461** - Updated variant matching logic in `storeProductFromApi()` method:

**BEFORE:**
```php
if ($sku) {
    $existingVariant = $product->variants()->where('sku', $sku)->first();
    // ...
}
```

**AFTER:**
```php
$variantId = $mappedVariant['shopify_variant_id'] ?? null;
$sku = $mappedVariant['sku'] ?? null;

$existingVariant = null;

// First try to match by shopify_variant_id
if ($variantId) {
    $existingVariant = $product->variants()->where('shopify_variant_id', $variantId)->first();
}

// If not found, try matching by SKU
if (!$existingVariant && $sku) {
    $existingVariant = $product->variants()->where('sku', $sku)->first();
}
```

## Testing

### Before Fix
```
Import completed!

Successfully processed 307 products from WooCommerce.
11 new products imported.
0 existing products updated (prices only).
296 products failed to process.

Errors: 296
```

### After Fix (Expected)
```
Import completed!

Successfully processed 307 products from WooCommerce.
11 new products imported.
296 existing products updated (prices only, categories/attributes preserved).
0 products failed to process.

Errors: 0
```

## What Gets Updated vs Preserved

### ✅ **UPDATED** (Price-Only Mode)
- `price` - Variant price
- `compare_at_price` - Original/compare price
- `cost_price` - Cost price
- `updated_at` - Timestamp

### ✅ **PRESERVED** (Price-Only Mode)
- `shopify_variant_id` - Variant ID (used for matching)
- `sku` - Product SKU
- `title` - Variant title
- `barcode` - Barcode
- `inventory_quantity` - Stock level
- `inventory_policy` - Overselling policy
- `weight` - Product weight
- `weight_unit` - Weight unit
- `option1`, `option2`, `option3` - Variant options
- `available` - Availability status
- `position` - Display order

## Matching Priority

The system now uses a **two-tier matching strategy**:

1. **Primary Match**: `shopify_variant_id` (WooCommerce/Shopify variant ID)
   - Most reliable for platform imports
   - Unique constraint ensures no duplicates

2. **Fallback Match**: `sku` (Stock Keeping Unit)
   - Used if `shopify_variant_id` match fails
   - Useful for manual products or SKU-based updates

## Verification Steps

1. ✅ Code updated to match by `shopify_variant_id` first
2. ✅ Fallback to SKU matching if needed
3. ✅ No linting errors
4. [ ] Test: Import WooCommerce products again
5. [ ] Verify: 296 products should update successfully
6. [ ] Verify: Prices updated, other fields preserved
7. [ ] Check logs: Should show "Updated prices for variant ID: xxx, SKU: xxx"

## Related Issues

- **Issue**: Duplicate entry errors during price-only updates
- **Cause**: Variant matching only by SKU
- **Solution**: Match by `shopify_variant_id` first, then SKU
- **Impact**: All existing WooCommerce products can now be updated

## Related Documentation

- `WOOCOMMERCE_IMPORT_FIX.md` - Main WooCommerce import fix
- `PRODUCT_IMPORT_FROM_WOOCOMMERCE.md` - Complete import guide
- `app/Models/CRM/ProductVariantModel.php` - Variant model
- `app/Services/WooCommerceService.php` - WooCommerce service


