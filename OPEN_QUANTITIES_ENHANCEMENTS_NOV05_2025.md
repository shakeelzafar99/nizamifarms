# Open Order Quantities Enhancements - November 5, 2025

## Overview
Enhanced the Open Order Quantities page with new analytical columns to provide better insights into order composition and status.

## Changes Made

### 1. Backend Changes (`app/Http/Controllers/CRM/OrderController.php`)

#### Added New Calculated Columns in `openQuantitiesData()` method:

**For All Hierarchy Levels (Category, Product, Orders):**

1. **Lean/Non-Lean Split**
   - `lean_quantity`: Sum of quantities where product name contains "lean" (case-insensitive)
   - `non_lean_quantity`: Sum of quantities where product name doesn't contain "lean"
   - SQL: `SUM(CASE WHEN LOWER(li.name) LIKE "%lean%" THEN li.quantity ELSE 0 END)`

2. **Processing Status**
   - `processing_quantity`: Sum of quantities in orders with `order_status = 'processing'`
   - SQL: `SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END)`

3. **Preparation Status**
   - `preparing_quantity`: Sum of quantities where line item has `preparation_status = 'preparing'`
   - SQL: `SUM(CASE WHEN li.preparation_status = "preparing" THEN li.quantity ELSE 0 END)`

#### Removed:
- Percentage calculation logic (no longer needed)

### 2. Frontend Changes (`resources/views/pages/orders/open-quantities.blade.php`)

#### Updated Table Headers:

**For Orders Level:**
- Order Number
- Customer Name
- Status
- Quantity
- **NEW:** Lean / Non-Lean
- **NEW:** Processing
- **NEW:** Preparing
- Date
- Action

**For Category/Product Levels:**
- Category
- Quantity
- **NEW:** Lean / Non-Lean (replaced "% of Total")
- **NEW:** Processing
- **NEW:** Preparing
- Orders
- Action

#### Updated Table Row Rendering:

**Orders Level Display:**
- Total quantity shown in bold
- Lean/Non-Lean split: Green (lean) / Red (non-lean) with separator
- Processing: Blue badge with quantity
- Preparing: Green badge with quantity

**Category/Product Level Display:**
- Total quantity shown prominently
- Lean/Non-Lean split: Green (lean) / Red (non-lean) inline format
- Processing: Blue badge with quantity
- Preparing: Green badge with quantity

### 3. Database Schema
Uses existing columns:
- `t_crm_prod_order_line_item.preparation_status` (VARCHAR 20, values: NULL or 'preparing')
- `t_crm_prod_order.order_status` (VARCHAR, values: 'new', 'pending', 'processing', etc.)
- `t_crm_prod_order_line_item.name` (VARCHAR, product name for lean detection)

## Color Coding

### Lean/Non-Lean Split:
- **Lean**: Green (#059669) - indicates lean products
- **Non-Lean**: Red (#dc2626) - indicates non-lean products

### Processing Status:
- **Blue Badge** (#dbeafe background, #1e40af text) - items in processing orders

### Preparing Status:
- **Green Badge** (#d1fae5 background, #065f46 text) - items marked as preparing

## Logic Details

### Lean Detection:
- Case-insensitive check: `LOWER(product_name) LIKE '%lean%'`
- Any product with "lean" anywhere in the name is counted as lean

### Processing Detection:
- Checks if the order's status is exactly 'processing'
- Only counts quantities from orders in processing state

### Preparing Detection:
- Checks if line item's `preparation_status = 'preparing'`
- Only counts quantities of line items marked as preparing

## Benefits

1. **Better Inventory Visibility**: Immediate view of lean vs non-lean product distribution
2. **Status Tracking**: See how many items are in processing vs preparing stages
3. **Drill-Down Consistency**: All new columns work at every hierarchy level (Category → Product → Orders)
4. **Real-Time Insights**: Data refreshes with existing refresh button

## Testing Recommendations

1. Navigate to **Orders → Open Order Quantities**
2. Verify all three new columns appear
3. Test drill-down from Category → Product → Orders
4. Verify lean/non-lean detection works correctly
5. Check that processing status reflects actual order statuses
6. Confirm preparing status matches line item preparation_status field
7. Test with orders that have mixed lean/non-lean products
8. Test with orders in different statuses (processing, pending, etc.)

## Files Modified
1. `app/Http/Controllers/CRM/OrderController.php` - Added new SQL calculations
2. `resources/views/pages/orders/open-quantities.blade.php` - Updated UI to display new columns

## Notes
- No database migrations required (uses existing fields)
- Backward compatible with existing functionality
- Performance impact minimal (uses existing indexes)
- All calculations done at query level for efficiency

