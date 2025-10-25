# Product Import from WooCommerce - Implementation Summary

## Overview
The product import functionality has been moved from the Products page to the Operations section and enhanced with **price-only update** mode to preserve your manually configured categories and attributes.

## Location
**Administration → Operations → Import Products**

## How It Works

### Import Modes

#### 1. **Price-Only Update Mode** (Default from Operations page)
When importing from the Operations page, the system uses "price-only update" mode:

**For Existing Products (matched by SKU):**
- ✅ **Updates:** Prices (price, compare_at_price, cost_price)
- ❌ **Preserves:** Product title, description, categories (attribute_1, attribute_2, attribute_3), vendor, product type, tags, images
- ✅ **Adds:** New variants if they don't exist

**For New Products:**
- ✅ Imports everything normally
- ✅ Auto-assigns categories based on your attribute rules (if configured)

#### 2. **Full Update Mode** (Products page)
When importing from the Products page, it performs a full update:
- Updates all product fields including title, description, categories, etc.
- Deletes and recreates all variants

## Database Matching Logic

### Product Matching (in order of priority):
1. **WooCommerce Products:** Matched by `external_id` + `external_source = 'woocommerce'`
2. **Shopify Products:** Matched by `shopify_product_id`

### Variant Matching (for price-only updates):
- Variants are matched by **SKU**
- If SKU matches → Update prices only
- If SKU doesn't exist → Add as new variant
- Existing variants not in import → Remain unchanged

## Database Structure Verification

### Products Table (`t_crm_products`)
```sql
-- Key columns for matching:
external_id         -- WooCommerce product ID
external_source     -- 'woocommerce'
shopify_product_id  -- Shopify product ID (if applicable)

-- Preserved during price-only update:
title
description
vendor
product_type
attribute_1         -- Category Level 1
attribute_2         -- Category Level 2
attribute_3         -- Category Level 3
tags
status
images

-- Updated during price-only update:
updated_by
updated_at
sync_status
last_synced_at
```

### Product Variants Table (`t_crm_product_variants`)
```sql
-- Key column for matching:
sku                 -- Product SKU (unique identifier)

-- Updated during price-only update:
price
compare_at_price
cost_price
updated_at

-- Preserved during price-only update:
title
inventory_quantity
weight
weight_unit
barcode
```

## Technical Implementation

### Frontend (Operations Page)
**File:** `resources/views/admin/operations.blade.php`

```javascript
function executeSelectedImport() {
    // Sends price_only_update: true flag to backend
    fetch('/products/import-all', {
        body: JSON.stringify({
            price_only_update: true
        })
    })
}
```

### Backend Controller
**File:** `app/Http/Controllers/CRM/ProductController.php`

```php
public function importAllProducts(Request $request)
{
    $priceOnlyUpdate = $request->get('price_only_update', false);
    
    foreach ($products as $productPayload) {
        if ($priceOnlyUpdate) {
            $productPayload['_price_only_update'] = true;
        }
        ProductModel::storeProductFromApi($productPayload);
    }
}
```

### Model Logic
**File:** `app/Models/CRM/ProductModel.php`

```php
public static function storeProductFromApi(array $productData): self
{
    $priceOnlyUpdate = $productData['_price_only_update'] ?? false;
    
    if ($existingProduct && $priceOnlyUpdate) {
        // Update only sync-related fields, not content
        $existingProduct->update([
            'updated_by' => ...,
            'updated_at' => now(),
        ]);
        
        // For variants: match by SKU and update prices only
        foreach ($variants as $variantData) {
            $sku = $variantData['sku'];
            $existingVariant = $product->variants()->where('sku', $sku)->first();
            
            if ($existingVariant) {
                $existingVariant->update([
                    'price' => $newPrice,
                    'compare_at_price' => $newComparePrice,
                    'cost_price' => $newCostPrice,
                ]);
            }
        }
    }
}
```

## Usage Instructions

### Step 1: Navigate to Operations
1. Go to **Administration** in the sidebar
2. Click on **Operations**
3. Find the **Import Products** card

### Step 2: Configure Import
1. Click **"Open Product Import"**
2. Select **Import Source:** WooCommerce Store
3. Select **Import Type:** All Products
4. Click **"Import Products"**

### Step 3: Confirm
A confirmation dialog will appear:
```
This will import products from your WooCommerce store.

For existing products (matched by SKU), only prices will be updated.
Your categories and attributes will remain unchanged.

Continue?
```

### Step 4: Wait for Completion
- A loading overlay will appear
- The import may take several minutes depending on product count
- Do not close the window during import

### Step 5: Review Results
After completion, you'll see a summary:
```
Import completed!

Successfully processed 302 products from WooCommerce.
150 new products imported.
152 existing products updated (prices only, categories/attributes preserved).
0 products failed to process.

Total Products: 302
New: 150
Updated (prices only): 152
Errors: 0
```

## Safety Features

### 1. **Transaction Safety**
- All database operations are wrapped in transactions
- If any error occurs, all changes are rolled back
- Database integrity is maintained

### 2. **Logging**
All import operations are logged:
```
[2025-10-24 10:30:00] Starting bulk import of all products
[2025-10-24 10:30:05] Import settings: source=WooCommerce, price_only_update=true
[2025-10-24 10:30:10] Price-only update for product: Boneless Chicken
[2025-10-24 10:30:10] Updated prices for variant SKU: BC-1KG
[2025-10-24 10:35:00] Completed bulk import: total=302, new=150, updated=152, errors=0
```

### 3. **Error Handling**
- Products that fail to import are counted and logged
- Other products continue to import
- Error messages are displayed in the final summary

## What Gets Preserved vs Updated

### ✅ **PRESERVED** (Price-Only Mode)
- Product title
- Product description
- Vendor name
- Product type
- Category Level 1 (attribute_1)
- Category Level 2 (attribute_2)
- Category Level 3 (attribute_3)
- Tags
- Status (active/draft/archived)
- Images
- Variant titles
- Variant inventory quantities
- Variant weights
- Variant barcodes

### ✅ **UPDATED** (Price-Only Mode)
- Variant prices (price)
- Variant compare at prices (compare_at_price)
- Variant cost prices (cost_price)
- Last sync timestamp
- Updated by/at timestamps

### ➕ **ADDED** (Price-Only Mode)
- New products (if they don't exist)
- New variants (if SKU doesn't exist)

## Troubleshooting

### Issue: Products not updating
**Solution:** Check that products have matching SKUs in both WooCommerce and your database.

### Issue: New products not getting categories
**Solution:** Ensure your attribute auto-rules are configured in `storage/app/private/attribute_auto_rules.json`.

### Issue: Import fails completely
**Solution:** Check Laravel logs at `storage/logs/laravel.log` for detailed error messages.

### Issue: Some products show errors
**Solution:** Review the error count in the final summary and check logs for specific product issues.

## Comparison with Products Page Import

| Feature | Operations Page | Products Page |
|---------|----------------|---------------|
| **Product Title** | Preserved | Updated |
| **Description** | Preserved | Updated |
| **Categories** | Preserved | Updated |
| **Attributes** | Preserved | Updated |
| **Prices** | Updated | Updated |
| **Variants** | Match by SKU | Delete & Recreate |
| **Images** | Preserved | Updated |
| **Use Case** | Daily price sync | Initial import or full sync |

## Best Practices

1. **Daily Price Updates:** Use Operations page import
2. **Initial Product Setup:** Use Products page import
3. **Category Management:** Set categories manually or via attribute rules, then use Operations page for updates
4. **Regular Backups:** Always backup your database before large imports
5. **Test First:** Try importing on a test/staging environment first

## Related Files

- `resources/views/admin/operations.blade.php` - Operations page UI
- `app/Http/Controllers/CRM/ProductController.php` - Import controller
- `app/Models/CRM/ProductModel.php` - Product model with import logic
- `app/Models/CRM/ProductVariantModel.php` - Variant model
- `app/Services/WooCommerceService.php` - WooCommerce API integration
- `storage/app/private/attribute_auto_rules.json` - Category auto-assignment rules

## Support

If you encounter any issues:
1. Check the Laravel logs: `storage/logs/laravel.log`
2. Review the import summary for error counts
3. Verify your WooCommerce API credentials in `.env`
4. Ensure your database structure matches the schema above


