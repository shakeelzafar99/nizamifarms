# Preparation Status Scope Update

**Date:** November 2, 2025  
**Update:** Restricted preparation status to open non-Shopify orders only

## Changes Made

### Scope Restrictions

The preparation status feature is now **ONLY** available for:
- ✅ Regular orders (from `t_crm_prod_order` table)
- ✅ Open orders (NOT delivered, completed, cancelled, or refunded)

The feature is **NOT** available for:
- ❌ Shopify orders
- ❌ Delivered orders
- ❌ Completed orders
- ❌ Cancelled orders
- ❌ Refunded orders

---

## Files Modified

### 1. Web View (`resources/views/pages/orders/index.blade.php`)

**Changes:**
- Added checks for `order_status` and `external_source`
- Conditionally show/hide:
  - "Select All" checkbox
  - "Mark as Preparing" button
  - "Clear Status" button
  - Select column in table
  - Status column in table
  - Checkboxes for each line item
  - Status badges for each line item

**Logic:**
```javascript
var isOpenOrder = !['delivered', 'completed', 'cancelled', 'refunded'].includes(order.order_status);
var isShopifyOrder = order.external_source === 'shopify';
var showPreparationControls = isOpenOrder && !isShopifyOrder;
```

**Result:**
- For **Shopify orders**: Line items table shows normally WITHOUT checkboxes, status column, or action buttons
- For **closed orders**: Line items table shows normally WITHOUT checkboxes, status column, or action buttons
- For **open regular orders**: Full preparation status UI is shown

---

### 2. API Controller (`app/Http/Controllers/API/RiderController.php`)

**Method:** `bulkUpdateLineItemStatus()`

**Changes:**
- Removed Shopify order lookup
- Added order status validation
- Returns error if order is closed or not found

**Validation:**
```php
// Only allow updates for regular orders (not Shopify)
$order = \App\Models\CRM\OrderModel::with('lineItems')->find($orderId);

if (!$order) {
    return response()->json([
        'success' => false,
        'message' => 'Order not found or not eligible for preparation status updates'
    ], 404);
}

// Check if order is open (not delivered/completed/cancelled)
$closedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
if (in_array($order->order_status, $closedStatuses)) {
    return response()->json([
        'success' => false,
        'message' => 'Cannot update preparation status for closed orders'
    ], 400);
}
```

**Error Responses:**
- `404`: Order not found or is a Shopify order
- `400`: Order is closed (delivered/completed/cancelled/refunded)

---

### 3. Mobile App - Order Details (`src/screens/OrderDetailsScreen.js`)

**Changes:**
- Added checks for order status and external source
- Conditionally show/hide:
  - "Select All" button
  - Checkboxes for each line item
  - Status badges
  - Action buttons ("Mark as Preparing", "Clear Status")

**Logic:**
```javascript
const isClosedOrder = ['delivered', 'completed', 'cancelled', 'refunded'].includes(order.order_status);
const isShopifyOrder = order.external_source === 'shopify';
const showPreparationControls = !isClosedOrder && !isShopifyOrder;
```

**Result:**
- For **Shopify orders**: Line items display normally WITHOUT preparation controls
- For **closed orders**: Line items display normally WITHOUT preparation controls
- For **open regular orders**: Full preparation status UI is shown

---

### 4. Mobile App - Store Open Orders (`src/screens/StoreOpenOrdersScreen.js`)

**No changes needed** - The preparation summary will naturally only show for orders that have the data (i.e., open regular orders).

---

## Database

### Migration Applied

**File:** `database/migrations/add_preparation_status_to_line_items_nov02_2025.sql`

**Status:** ✅ Applied to `t_crm_prod_order_line_item` table

**NOT Applied:** `t_crm_shopify_order_line_item` (not needed since Shopify orders are excluded)

---

## Testing Checklist

### Web View
- [ ] Open a **regular open order** → Should see checkboxes, status badges, and action buttons
- [ ] Open a **delivered regular order** → Should NOT see checkboxes or status badges
- [ ] Open a **Shopify order** → Should NOT see checkboxes or status badges
- [ ] Try to update a closed order via API → Should return error

### Mobile App
- [ ] Open a **regular open order** → Should see checkboxes, status badges, and action buttons
- [ ] Open a **delivered regular order** → Should NOT see checkboxes or status badges
- [ ] Open a **Shopify order** → Should NOT see checkboxes or status badges
- [ ] Store open orders list → Should show preparation summary only for regular orders

### API
- [ ] Try to update Shopify order line items → Should return 404
- [ ] Try to update closed order line items → Should return 400
- [ ] Update open regular order line items → Should succeed

---

## Summary

The preparation status feature is now properly scoped to:
1. **Only regular orders** (not Shopify)
2. **Only open orders** (not delivered/completed/cancelled/refunded)

This ensures:
- ✅ No confusion with Shopify orders
- ✅ No accidental updates to closed orders
- ✅ Cleaner UI for orders that don't need preparation tracking
- ✅ Better performance (no unnecessary data loading)

---

## Files Changed in This Update

1. `resources/views/pages/orders/index.blade.php` - Added conditional logic
2. `app/Http/Controllers/API/RiderController.php` - Added validation
3. `src/screens/OrderDetailsScreen.js` - Added conditional rendering
4. `PREPARATION_STATUS_SCOPE_UPDATE.md` - This documentation

---

## Ready to Test

You can now test the feature with confidence that:
- It will **only** appear for open regular orders
- It will **not** interfere with Shopify orders
- It will **not** appear for closed orders

