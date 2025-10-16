# Bulk Price Adjustments: Cascading Filters & Preview - Oct 16, 2025

## Summary
Enhanced the Bulk Adjust Prices feature with two major improvements:
1. **Cascading/Dependent Filters** - Filter dropdowns dynamically update based on selections
2. **Preview Functionality** - View price changes before applying them, with option to apply from preview

---

## Problem Statement

### Issue 1: Non-Cascading Filters
**Before**: When selecting "Chicken" in Category Level 1, all other filter dropdowns (Category, Vendor, Level 2) still showed ALL options including "Mutton", "Beef", vendors that don't sell chicken, etc.

**Confusion**: Users could select filter combinations that don't make sense or result in zero products.

### Issue 2: No Preview
**Before**: Users had to click "Apply" and wait for changes to be committed before seeing what would happen.

**Risk**: No way to verify changes before they're applied to the database.

---

## Solution Implemented

### 1. Cascading Filters in Bulk Modal

**How It Works**:
- When ANY filter dropdown changes, an AJAX request fetches updated filter options
- Backend calculates which values are actually available for the current filter combination
- All other dropdowns update to show only relevant options
- Current selections are preserved if still valid

**Example Flow**:
```
1. User selects Category Level 1 = "Chicken"
   → AJAX request: GET /products?attribute_1=Chicken
   
2. Backend calculates products matching "Chicken"
   
3. Backend returns available filter_options:
   - product_types: ["Meat", "Poultry"]  // Only categories with Chicken
   - vendors: ["nizamifarms"]            // Only vendors selling Chicken
   - attribute_2s: ["Boneless", "LEAN"] // Only Level 2 for Chicken
   
4. Frontend updates all dropdowns:
   - Category dropdown: Now shows only "Meat", "Poultry"
   - Vendor dropdown: Now shows only "nizamifarms"
   - Level 2 dropdown: Now shows only "Boneless", "LEAN"
   - No more "Mutton" options visible!
```

### 2. Preview Button & Modal

**Two-Stage Process**:
1. **Preview** - Calculate changes WITHOUT saving to database
2. **Apply** - Commit changes after user confirms

**Preview Modal Features**:
- 👁️ "Preview Price Changes" heading
- "X products will be updated (Y variants will change)" subtitle
- Same detailed table as final summary (Product, Variant, SKU, Old, New, Change)
- ⚠️ "Changes not yet applied" warning at bottom
- Two buttons:
  - **Cancel** - Close preview, return to adjust modal
  - **Apply These Changes** - Proceed with actual price changes

---

## Implementation Details

### A. Frontend Changes

#### File: `resources/views/pages/products/index.blade.php`

**1. Added CSS class to filter dropdowns** (Lines 359, 368, 379, 388, 397)
```html
<select id="bulkCategory" class="select select-sm bulk-filter-cascade">
```
- `bulk-filter-cascade` class identifies filters that participate in cascading

**2. Added Preview button** (Line 428)
```html
<button onclick="previewBulkAdjustPrices()" 
        class="kt-btn kt-btn-secondary" 
        style="background-color: #6366f1; color: white;">
    Preview Changes
</button>
```

**3. Added Preview Modal HTML** (Lines 452-474)
```html
<div id="priceChangePreviewModal" style="display: none; ...">
    <!-- Modal with preview table and Apply/Cancel buttons -->
</div>
```

**4. JavaScript Functions Added/Modified**:

**setupBulkModalCascadingFilters()** (Lines 1139-1144)
- Attaches `change` event listeners to all `.bulk-filter-cascade` dropdowns
- Triggers `updateBulkModalFilters()` on any filter change

**updateBulkModalFilters()** (Lines 1146-1180)
- Builds query parameters from current filter selections
- Fetches `/products?...` with AJAX
- Updates all filter dropdowns with new options from `data.filter_options`

**updateBulkFilterDropdown()** (Lines 1182-1195)
- Helper to update a single dropdown with new options
- Preserves current selection if it exists in new options
- Maintains "Any" option at top

**previewBulkAdjustPrices()** (Lines 1247-1293)
- Validates input amount
- Stores payload in `window.bulkPricePayload` for later use
- Calls `/products/bulk-adjust-prices/preview` endpoint
- Shows preview modal with results

**showPriceChangePreview()** (Lines 1295-1306)
- Renders preview modal with calculated changes
- Uses `generatePriceChangeTable()` for consistency

**applyFromPreview()** (Lines 1308-1350)
- Retrieves stored payload from `window.bulkPricePayload`
- Calls actual `/products/bulk-adjust-prices` endpoint
- Closes preview modal
- Shows success summary modal
- Refreshes product table

**generatePriceChangeTable()** (Lines 1365-1411)
- Extracted common table generation logic
- Used by both preview and summary modals
- Ensures consistent display

---

### B. Backend Changes

#### File: `app/Http/Controllers/CRM/ProductController.php`

**1. Added Preview Endpoint** (Lines 835-906)

```php
public function previewBulkAdjustPrices(Request $request)
{
    // Same validation as actual adjustment
    $validated = $request->validate([...]);
    
    // Query products with filters
    $query = ProductModel::query();
    foreach (['product_type','vendor','attribute_1','attribute_2','attribute_3'] as $f) {
        if ($request->$f) $query->where($f, $request->$f);
    }
    
    $products = $query->with('variants')->get();
    $changes = []; // Calculate changes WITHOUT saving
    
    foreach ($products as $product) {
        foreach ($product->variants as $variant) {
            $old = (float) $variant->price;
            // ... calculate new price ...
            
            if ($new !== $old) {
                // Record change but DON'T save to database
                $changes[] = [
                    'product_title' => $product->title,
                    'variant_title' => $variant->title,
                    'sku' => $variant->sku,
                    'old_price' => $old,
                    'new_price' => $new,
                    'difference' => $new - $old,
                    'difference_percent' => ...
                ];
            }
        }
    }
    
    return response()->json([
        'success' => true,
        'preview' => true,  // ← Indicates this is preview mode
        'affected_variants' => count($changes),
        'affected_products' => ...,
        'message' => "Will update X products...",
        'changes' => $changes
    ]);
}
```

**Key Difference from Actual Adjustment**:
- ❌ **NO** `$variant->save()` 
- ❌ **NO** `$product->save()`
- ✅ Only calculates and returns what WOULD change

**2. Enhanced filter_options in API** (Lines 814-821)
- Already implemented in previous enhancement
- Returns available filter values based on current selections
- Used by cascading filter logic

#### File: `routes/web.php`

**Added Preview Route** (Line 179)
```php
Route::post('/products/bulk-adjust-prices/preview', 
    [\App\Http\Controllers\CRM\ProductController::class, 'previewBulkAdjustPrices'])
    ->name('products.bulk_adjust_prices.preview');
```

---

## User Flow Examples

### Example 1: Cascading Filters

```
User opens Bulk Adjust Prices modal:
├─ All dropdowns show full lists
│
User selects Category Level 1 = "Chicken":
├─ AJAX request fires
├─ Backend calculates products with Chicken
├─ Returns filter_options:
│  ├─ Categories: ["Meat", "Poultry"]
│  ├─ Vendors: ["nizamifarms"]
│  ├─ Level 2: ["Boneless", "LEAN", "With Skin"]
│  └─ Level 3: ["Breast", "Cubes", "Steak Fillet"]
│
Dropdowns update:
├─ Category: Now shows only "Meat", "Poultry" (no "Mutton"!)
├─ Vendor: Now shows only "nizamifarms"
├─ Level 2: Now shows only Chicken-related values
└─ User cannot select invalid combinations!

User selects Level 2 = "Boneless":
├─ Another AJAX request
├─ Level 3 updates to show only Boneless options
└─ ("Breast", "Cubes", "Steak Fillet" only)
```

### Example 2: Preview Before Applying

```
User configures price adjustment:
├─ Category Level 1: Chicken
├─ Level 2: Boneless
├─ Operation: Increase
├─ Mode: Percentage (%)
├─ Amount: 10
│
User clicks "Preview Changes":
├─ AJAX POST to /preview endpoint
├─ Backend calculates changes (doesn't save)
├─ Returns 15 products, 23 variants
│
Preview modal shows:
┌──────────────────────────────────────────────────────┐
│ 👁️ Preview Price Changes                            │
│ 15 products will be updated (23 variants will change)│
├──────────────────────────────────────────────────────┤
│ Product              │ Old    │ New    │ Change      │
│ Chicken Boneless     │ 1290.00│ 1419.00│ ↑ +10%     │
│ Chicken LEAN Boneless│ 2651.00│ 2916.10│ ↑ +10%     │
│ ...                  │ ...    │ ...    │ ...         │
├──────────────────────────────────────────────────────┤
│ ⚠️ Changes not yet applied                           │
│                      [Cancel] [Apply These Changes]  │
└──────────────────────────────────────────────────────┘

User reviews changes:
├─ Sees exactly what will happen
├─ Verifies calculations are correct
│
Option 1 - User clicks "Cancel":
├─ Preview closes
└─ Returns to Adjust Prices modal (can modify filters)

Option 2 - User clicks "Apply These Changes":
├─ Actual bulk adjust endpoint called
├─ Prices saved to database
├─ Both modals close
├─ Success summary modal appears (✅ "Price Changes Applied")
└─ Product table refreshes with new prices
```

### Example 3: Direct Apply (Skip Preview)

```
User can still click "Apply Now" directly:
├─ Skips preview step
├─ Prices immediately applied
└─ Success summary appears
```

---

## Benefits

### 1. Cascading Filters

**User Experience**:
- ✅ No confusion - only see relevant options
- ✅ Impossible to select invalid combinations
- ✅ Faster to find right filters
- ✅ Clear visual feedback on what's available

**Example**:
- Select "Chicken" → Can't accidentally select "Mutton" vendor
- Select "nizamifarms" → Only see categories they sell

### 2. Preview Functionality

**Safety**:
- ✅ See changes BEFORE they're permanent
- ✅ Catch mistakes (wrong percentage, wrong filters)
- ✅ Verify calculations are correct
- ✅ No "undo" needed because preview comes first

**Confidence**:
- ✅ Know exactly how many products affected
- ✅ See specific price changes per variant
- ✅ Review old vs new prices side-by-side
- ✅ Make informed decision before applying

**Flexibility**:
- ✅ Can still use "Apply Now" for quick changes
- ✅ Preview for large/important changes
- ✅ Cancel and adjust filters if preview looks wrong

---

## Technical Architecture

### Data Flow: Cascading Filters

```
┌──────────────┐
│ User changes │
│ filter       │
└──────┬───────┘
       │
       ▼
┌────────────────────────────────┐
│ updateBulkModalFilters()       │
│ - Build query params           │
│ - Fetch /products?filters      │
└──────┬─────────────────────────┘
       │
       ▼
┌────────────────────────────────┐
│ Backend: index() method        │
│ - Apply filters to products    │
│ - Calculate available options  │
│ - Return filter_options JSON   │
└──────┬─────────────────────────┘
       │
       ▼
┌────────────────────────────────┐
│ updateBulkFilterDropdown()     │
│ - Replace dropdown options     │
│ - Preserve current selection   │
└────────────────────────────────┘
```

### Data Flow: Preview & Apply

```
┌──────────────────┐
│ User clicks      │
│ "Preview Changes"│
└────────┬─────────┘
         │
         ▼
┌───────────────────────────────────┐
│ previewBulkAdjustPrices()         │
│ - Validate form                   │
│ - Store payload                   │
│ - POST /preview endpoint          │
└────────┬──────────────────────────┘
         │
         ▼
┌───────────────────────────────────┐
│ Backend: previewBulkAdjustPrices()│
│ - Query filtered products         │
│ - Calculate new prices            │
│ - DON'T save to database          │
│ - Return changes[] array          │
└────────┬──────────────────────────┘
         │
         ▼
┌───────────────────────────────────┐
│ showPriceChangePreview()          │
│ - Display preview modal           │
│ - Show calculated changes         │
│ - Wait for user decision          │
└────────┬──────────────────────────┘
         │
         ├─ User clicks "Cancel" → Close modal
         │
         └─ User clicks "Apply" ──┐
                                  │
                                  ▼
                    ┌──────────────────────────────┐
                    │ applyFromPreview()           │
                    │ - Retrieve stored payload    │
                    │ - POST /bulk-adjust-prices   │
                    └────────┬─────────────────────┘
                             │
                             ▼
                    ┌──────────────────────────────┐
                    │ Backend: bulkAdjustPrices()  │
                    │ - Query filtered products    │
                    │ - Calculate new prices       │
                    │ - SAVE to database           │
                    │ - Return changes[] array     │
                    └────────┬─────────────────────┘
                             │
                             ▼
                    ┌──────────────────────────────┐
                    │ showPriceChangeSummary()     │
                    │ - Display success modal      │
                    │ - Show applied changes       │
                    │ - Refresh product table      │
                    └──────────────────────────────┘
```

---

## Comparison: Before vs After

| Aspect | Before | After |
|--------|--------|-------|
| **Filter Options** | All values always shown | Only relevant values shown |
| **Invalid Selections** | Possible (e.g., Chicken + Mutton vendor) | Impossible - dropdowns update |
| **User Confusion** | High - too many irrelevant options | Low - only see what's available |
| **Preview** | ❌ None - apply blindly | ✅ Full preview before applying |
| **Safety** | Low - can't undo easily | High - see before commit |
| **User Confidence** | Low - unsure what will happen | High - know exactly what changes |
| **Workflow** | One-step (Apply) | Two-step (Preview → Apply) OR one-step (Apply Now) |

---

## API Endpoints

### 1. Get Filter Options (existing, enhanced)
**GET** `/products?product_type=X&vendor=Y&...`

**Headers**:
```
X-Requested-With: XMLHttpRequest
Accept: application/json
```

**Response**:
```json
{
    "success": true,
    "products": [...],
    "pagination": {...},
    "filter_options": {
        "product_types": ["Meat", "Poultry"],
        "vendors": ["nizamifarms"],
        "attribute_1s": ["Chicken", "Beef"],
        "attribute_2s": ["Boneless", "LEAN"],
        "attribute_3s": ["Breast", "Cubes"],
        "sync_statuses": ["shopify", "manual"]
    }
}
```

### 2. Preview Price Changes (NEW)
**POST** `/products/bulk-adjust-prices/preview`

**Request**:
```json
{
    "mode": "percent",
    "operation": "increase",
    "amount": 10,
    "product_type": "",
    "vendor": "",
    "attribute_1": "Chicken",
    "attribute_2": "Boneless",
    "attribute_3": ""
}
```

**Response**:
```json
{
    "success": true,
    "preview": true,
    "affected_products": 15,
    "affected_variants": 23,
    "message": "Will update 15 products (23 variants)",
    "changes": [
        {
            "product_title": "Chicken (B2) Boneless Cubes per kg",
            "variant_title": "Default",
            "sku": "CH-CBS",
            "old_price": 1290.00,
            "new_price": 1419.00,
            "difference": 129.00,
            "difference_percent": 10.00
        },
        // ... more changes
    ]
}
```

### 3. Apply Price Changes (existing, unchanged)
**POST** `/products/bulk-adjust-prices`

**Request**: Same as preview

**Response**: Same structure as preview, but `preview: false` and changes are actually saved

---

## Testing Checklist

### Cascading Filters
- [ ] Select Category Level 1 → Other dropdowns update
- [ ] Select Vendor → Other dropdowns update
- [ ] Select Category → Level 1/2 update to match
- [ ] Clear a filter → Dropdowns expand to show more options
- [ ] Select multiple filters → Each selection narrows options further
- [ ] Invalid combination impossible → Can't select Chicken + Mutton vendor

### Preview Functionality
- [ ] Click "Preview Changes" → Modal appears with table
- [ ] Preview shows correct number of products/variants
- [ ] Preview calculations match expected values
- [ ] Old price vs New price correct
- [ ] Percentage change calculated correctly
- [ ] Click "Cancel" in preview → Returns to adjust modal
- [ ] Click "Apply These Changes" → Prices actually update
- [ ] After apply → Success modal shows same changes
- [ ] Product table refreshes with new prices

### Combined Workflow
- [ ] Select filter → Preview → See only filtered products
- [ ] Modify filter → Preview again → See updated list
- [ ] Preview → Cancel → Modify → Preview → Apply
- [ ] Skip preview (use "Apply Now") → Still works

---

## Files Modified

1. **`routes/web.php`**
   - Added preview route (line 179)

2. **`app/Http/Controllers/CRM/ProductController.php`**
   - Added `previewBulkAdjustPrices()` method (lines 835-906)
   - Enhanced `filter_options` in API response (already done)

3. **`resources/views/pages/products/index.blade.php`**
   - Added `.bulk-filter-cascade` class to filter dropdowns
   - Added "Preview Changes" button (line 428)
   - Added preview modal HTML (lines 452-474)
   - Added `setupBulkModalCascadingFilters()` (lines 1139-1144)
   - Added `updateBulkModalFilters()` (lines 1146-1180)
   - Added `updateBulkFilterDropdown()` (lines 1182-1195)
   - Added `previewBulkAdjustPrices()` (lines 1247-1293)
   - Added `showPriceChangePreview()` (lines 1295-1306)
   - Added `applyFromPreview()` (lines 1308-1350)
   - Extracted `generatePriceChangeTable()` (lines 1365-1411)

---

## Summary

✅ **Cascading Filters**: Dropdowns now intelligently update based on selections  
✅ **Preview Modal**: See exactly what will change before applying  
✅ **Apply from Preview**: Confirm changes after reviewing  
✅ **Flexible Workflow**: Can preview OR apply directly  
✅ **No Breaking Changes**: All existing functionality preserved  
✅ **Enhanced UX**: Clearer, safer, more confident price adjustments

