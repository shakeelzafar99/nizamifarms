# Line Item Preparation Status Feature Implementation

**Date:** November 2, 2025  
**Feature:** Line Item Preparation Status Tracking

## Overview

This feature adds the ability to track preparation status for individual line items in orders. Users can mark items as "Preparing" from both web and mobile interfaces, with a summary showing progress (e.g., "Preparing 5/8 items").

**Key Points:**
- ✅ Does NOT affect overall order status
- ✅ Backward compatible (NULL = not started)
- ✅ Works for both regular and Shopify orders
- ✅ Available in web view and mobile app (Store & Rider modes)

---

## Database Changes

### Tables Modified

1. **`t_crm_prod_order_line_item`**
   - Added column: `preparation_status` VARCHAR(20) NULL
   - Added index: `idx_preparation_status`

2. **`t_crm_shopify_order_line_item`**
   - Added column: `preparation_status` VARCHAR(20) NULL
   - Added index: `idx_preparation_status`

### Migration File

**Location:** `database/migrations/add_preparation_status_to_line_items_nov02_2025.sql`

**To Apply:**
```sql
-- Run the migration
SOURCE database/migrations/add_preparation_status_to_line_items_nov02_2025.sql;

-- Or execute directly in MySQL
mysql -u your_user -p your_database < database/migrations/add_preparation_status_to_line_items_nov02_2025.sql
```

**To Rollback:**
```sql
ALTER TABLE `t_crm_prod_order_line_item`
DROP INDEX `idx_preparation_status`,
DROP COLUMN `preparation_status`;

ALTER TABLE `t_crm_shopify_order_line_item`
DROP INDEX `idx_preparation_status`,
DROP COLUMN `preparation_status`;
```

---

## Backend Changes

### 1. Models Updated

#### `app/Models/CRM/OrderLineItemModel.php`
- Added `preparation_status` to `$fillable` array

#### `app/Models/CRM/ShopifyOrderLineItemModel.php`
- Added `preparation_status` to `$fillable` array

### 2. API Controller

#### `app/Http/Controllers/API/RiderController.php`

**New Endpoint:**
```php
POST /api/rider/orders/{orderId}/line-items/bulk-update-status
```

**Request Body:**
```json
{
  "line_item_ids": [1, 2, 3],
  "preparation_status": "preparing"  // or "null" to clear
}
```

**Response:**
```json
{
  "success": true,
  "message": "Updated 3 line item(s)",
  "updated_count": 3,
  "preparing_count": 5,
  "total_items": 8
}
```

**Modified Methods:**
- `getOrderDetails()` - Now includes `preparation_status` for each line item and `preparation_summary`
- `getStoreOpenOrders()` - Now includes `preparation_summary` for each order

### 3. Routes

#### `routes/api.php`
Added route:
```php
Route::post('/orders/{orderId}/line-items/bulk-update-status', 
    [\App\Http\Controllers\API\RiderController::class, 'bulkUpdateLineItemStatus']);
```

---

## Web View Changes

### `resources/views/pages/orders/index.blade.php`

**Changes in Order Details Modal:**

1. **Line Items Table Header:**
   - Added "Select" column with checkboxes
   - Added "Status" column showing badge (Preparing/Not Started)
   - Added "Select All" checkbox
   - Added "Mark as Preparing" button
   - Added "Clear Status" button

2. **New JavaScript Functions:**
   - `toggleSelectAllLineItems()` - Toggle all checkboxes
   - `getSelectedLineItemIds()` - Get IDs of selected items
   - `markSelectedAsPreparing()` - Bulk update to "preparing"
   - `clearSelectedPreparingStatus()` - Bulk clear status

**Visual Changes:**
- Green badge for "Preparing" items
- Gray badge for "Not Started" items
- Action buttons appear above the line items table

---

## Mobile App Changes

### 1. Store Open Orders Screen

#### `src/screens/StoreOpenOrdersScreen.js`

**Added:**
- Preparation summary in order card: "Preparing: X/Y items"
- Color-coded: Green if all items preparing, Orange if partial

**Display:**
```
Preparation: 5/8 items  (Orange if partial, Green if all)
```

### 2. Order Details Screen

#### `src/screens/OrderDetailsScreen.js`

**New Features:**

1. **Line Item Selection:**
   - Checkbox next to each line item
   - "Select All" / "Deselect All" button in header
   - Status badge for each item (Preparing/Not Started)

2. **Bulk Actions:**
   - "Mark as Preparing (X)" button - appears when items selected
   - "Clear Status (X)" button - appears when items selected
   - Loading indicators during API calls

3. **New State Variables:**
   - `selectedLineItems` - Array of selected item IDs
   - `updatingLineItemStatus` - Loading state for bulk updates

4. **New Functions:**
   - `toggleLineItemSelection(itemId)` - Toggle single item
   - `toggleSelectAllLineItems()` - Toggle all items
   - `updateLineItemStatus(status)` - Call API to update status

**New Styles:**
- Checkbox styles (checked/unchecked)
- Status badge styles (preparing/not started)
- Action button styles (green for preparing, gray for clear)

---

## API Response Changes

### Order Details API Response

**Before:**
```json
{
  "line_items": [
    {
      "id": 1,
      "product_name": "Chicken Breast",
      "quantity": 2,
      ...
    }
  ]
}
```

**After:**
```json
{
  "line_items": [
    {
      "id": 1,
      "product_name": "Chicken Breast",
      "quantity": 2,
      "preparation_status": "preparing",  // NEW
      ...
    }
  ],
  "preparation_summary": {  // NEW
    "preparing_count": 5,
    "total_items": 8
  }
}
```

### Store Open Orders API Response

**Before:**
```json
{
  "orders": [
    {
      "id": 123,
      "items_count": 8,
      ...
    }
  ]
}
```

**After:**
```json
{
  "orders": [
    {
      "id": 123,
      "items_count": 8,
      "preparation_summary": {  // NEW
        "preparing_count": 5,
        "total_items": 8
      },
      ...
    }
  ]
}
```

---

## Testing Checklist

### Database
- [ ] Run migration SQL successfully
- [ ] Verify columns exist in both tables
- [ ] Verify indexes are created
- [ ] Test rollback script (optional)

### Backend API
- [ ] Test bulk update endpoint with valid data
- [ ] Test with invalid order ID (should return 404)
- [ ] Test with invalid line item IDs (should skip)
- [ ] Test with empty selection (should return validation error)
- [ ] Test clearing status (preparation_status = 'null')
- [ ] Verify permissions are checked

### Web View
- [ ] Open order details modal
- [ ] Verify line items show with status badges
- [ ] Test "Select All" checkbox
- [ ] Test individual item checkboxes
- [ ] Test "Mark as Preparing" button
- [ ] Test "Clear Status" button
- [ ] Verify status updates reflect immediately after API call
- [ ] Test with orders that have no line items
- [ ] Test with Shopify orders

### Mobile App (Store Mode)
- [ ] View open orders list
- [ ] Verify preparation summary shows in order cards
- [ ] Verify color coding (green/orange)
- [ ] Test with orders having all items preparing
- [ ] Test with orders having partial items preparing
- [ ] Test with orders having no items preparing

### Mobile App (Order Details)
- [ ] Open order details
- [ ] Verify line items show with checkboxes
- [ ] Verify status badges display correctly
- [ ] Test "Select All" button
- [ ] Test individual item selection
- [ ] Test "Mark as Preparing" button
- [ ] Test "Clear Status" button
- [ ] Verify loading indicators work
- [ ] Verify success/error alerts display
- [ ] Test with no items selected (should show alert)
- [ ] Verify order refreshes after status update

### Backward Compatibility
- [ ] Existing orders (NULL status) display as "Not Started"
- [ ] Existing order list/filter functionality unchanged
- [ ] Existing order status workflow unchanged
- [ ] Invoice generation unaffected
- [ ] Order creation/editing unaffected

---

## Permissions

**Required Mobile Permissions:**
- `view_open_orders` OR `mark_order_delivered`

Users with either of these permissions can update line item preparation status.

---

## Future Enhancements (Optional)

1. **Additional Statuses:**
   - Add "ready" status for items that are prepared
   - Add "out_of_stock" status for unavailable items

2. **Notifications:**
   - Notify rider when all items are preparing
   - Notify customer when order preparation starts

3. **Analytics:**
   - Track average preparation time per item
   - Report on preparation efficiency

4. **Automatic Status:**
   - Auto-mark items as preparing when order status changes to "processing"

---

## Troubleshooting

### Issue: Status not updating in web view
**Solution:** Check browser console for API errors. Verify CSRF token is present.

### Issue: Mobile app not showing preparation summary
**Solution:** Ensure API response includes `preparation_summary`. Check API version.

### Issue: Permission denied error
**Solution:** Verify user has `view_open_orders` or `mark_order_delivered` permission.

### Issue: SQL migration fails
**Solution:** Check if columns already exist. Review error message for conflicts.

---

## Files Modified

### Backend
- `app/Models/CRM/OrderLineItemModel.php`
- `app/Models/CRM/ShopifyOrderLineItemModel.php`
- `app/Http/Controllers/API/RiderController.php`
- `routes/api.php`

### Frontend (Web)
- `resources/views/pages/orders/index.blade.php`

### Mobile App
- `src/screens/StoreOpenOrdersScreen.js`
- `src/screens/OrderDetailsScreen.js`

### Database
- `database/migrations/add_preparation_status_to_line_items_nov02_2025.sql`

### Documentation
- `LINE_ITEM_PREPARATION_STATUS_IMPLEMENTATION.md` (this file)

---

## Summary

This feature provides a simple yet effective way to track line item preparation without disrupting existing order workflows. The implementation is backward compatible, performant (with indexes), and provides a consistent experience across web and mobile platforms.

**Total Changes:**
- 2 database tables modified
- 4 backend files modified
- 1 frontend file modified
- 2 mobile app files modified
- 1 new API endpoint
- 1 SQL migration file
- 1 documentation file

**Estimated Implementation Time:** 2-3 hours  
**Testing Time:** 1-2 hours  
**Total:** 3-5 hours

