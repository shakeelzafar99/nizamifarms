# WooCommerce Product Import Fix

## Issue
WooCommerce product import was failing with the error:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'external_id' in 'where clause'
```

## Root Cause
The `mapWooProduct` function in `WooCommerceService.php` was returning `external_id` and `external_source` fields, but:

1. **These columns don't exist** in the `t_crm_prod_product` table
2. **Your existing system** stores both Shopify AND WooCommerce products using the `shopify_product_id` field
3. **The code was updated** in September 2025 to use `external_id`, but this broke your existing working setup

## How It Works Now (Fixed)

### Product Matching Strategy
**Both Shopify AND WooCommerce products use the same `shopify_product_id` field:**

| Platform | ID Field | Notes |
|----------|----------|-------|
| **Shopify** | `shopify_product_id` | Stores Shopify product ID |
| **WooCommerce** | `shopify_product_id` | Stores WooCommerce product ID |

This is simpler and works with your existing database structure!

### Matching Logic (from `ProductModel::storeProductFromApi`)
```php
// Check for Shopify/WooCommerce products by their platform ID
if (isset($productData['shopify_product_id'])) {
    $existingProduct = static::where('shopify_product_id', $productData['shopify_product_id'])->first();
}
```

### WooCommerce Mapping (from `WooCommerceService::mapWooProduct`) - FIXED
```php
return [
    'shopify_product_id' => $wooProduct['id'],  // ← Store WooCommerce ID in shopify_product_id field
    'title' => $title,
    'vendor' => config('app.name'),
    // ... other fields
];
```

## Solution Applied

### 1. WooCommerce Service Fixed
**File:** `app/Services/WooCommerceService.php`

Changed the `mapWooProduct` function to return `shopify_product_id` instead of `external_id`:

**BEFORE (Broken):**
```php
return [
    'external_source' => 'woocommerce',  // ❌ Column doesn't exist
    'external_id' => $wooProduct['id'],  // ❌ Column doesn't exist
    // ...
];
```

**AFTER (Fixed):**
```php
return [
    'shopify_product_id' => $wooProduct['id'],  // ✅ Uses existing column
    // ...
];
```

### 2. Product Model Fixed
**File:** `app/Models/CRM/ProductModel.php`

Removed the `external_id` matching logic since we're using `shopify_product_id` for both platforms:

**BEFORE (Broken):**
```php
// Check for external_id (WooCommerce, etc.)
elseif (isset($productData['external_id']) && isset($productData['external_source'])) {
    $existingProduct = static::where('external_id', $productData['external_id'])
        ->where('external_source', $productData['external_source'])
        ->first();
}
```

**AFTER (Fixed):**
```php
// Check for Shopify/WooCommerce products by their platform ID
elseif (isset($productData['shopify_product_id'])) {
    $existingProduct = static::where('shopify_product_id', $productData['shopify_product_id'])->first();
}
```

### 3. No Database Changes Needed! ✅
Your existing database structure is perfect. No migrations required.

## Testing After Fix

### Test WooCommerce Import
1. Go to **Administration → Operations**
2. Click **"Open Product Import"**
3. Select **WooCommerce Store**
4. Click **"Import Products"**

Expected result:
```
Import completed!

Successfully processed 307 products from WooCommerce.
150 new products imported.
157 existing products updated (prices only, categories/attributes preserved).
0 products failed to process.
```

## How Price-Only Update Works with WooCommerce

### First Import (New Products)
```
WooCommerce Product ID: 88711
↓
Stored as: external_id = 88711, external_source = 'woocommerce'
↓
Full product data imported (title, description, price, categories, etc.)
```

### Subsequent Imports (Existing Products)
```
WooCommerce Product ID: 88711
↓
Match found: WHERE external_id = 88711 AND external_source = 'woocommerce'
↓
Price-only update: Only variant prices updated
↓
Categories, attributes, title, description → PRESERVED
```

## Files Modified

1. **`app/Services/WooCommerceService.php`**
   - Changed to use `shopify_product_id` instead of `external_id`

2. **`app/Models/CRM/ProductModel.php`**
   - Removed `external_id` matching logic
   - **Fixed variant matching**: Now matches by `shopify_variant_id` first, then by `sku`

3. **`PRODUCT_IMPORT_FROM_WOOCOMMERCE.md`**
   - Complete import guide

4. **`WOOCOMMERCE_IMPORT_FIX.md`**
   - This documentation file

## Verification Checklist

- [x] WooCommerceService fixed to use `shopify_product_id`
- [x] ProductModel updated to remove `external_id` logic
- [x] No database changes needed
- [ ] Test: WooCommerce import completes without errors
- [ ] Test: Existing products are matched correctly by WooCommerce ID
- [ ] Test: Price-only updates preserve categories and attributes
- [ ] Test: New products are imported with all data

## Troubleshooting

### Issue: "Column not found: external_id"
**Solution:** ✅ Fixed! The code now uses `shopify_product_id` which exists in your database.

### Issue: Products are being duplicated instead of updated
**Solution:** Products are now matched by `shopify_product_id` (which stores WooCommerce IDs for WooCommerce products).

## Related Documentation

- `PRODUCT_IMPORT_FROM_WOOCOMMERCE.md` - Complete import guide
- `database/migrations/create_products_table.sql` - Original table schema
- `app/Services/WooCommerceService.php` - WooCommerce API integration
- `app/Models/CRM/ProductModel.php` - Product model

