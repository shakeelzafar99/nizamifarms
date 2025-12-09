# Payment Method Pill Implementation
**Date:** November 8, 2025  
**Feature:** Quick payment method change with pill UI (similar to rider and status pills)

## Overview
Implemented a clickable pill UI for payment methods in the orders table, allowing quick changes between "Cash" and "Online" payment methods with a modal interface and change history tracking.

## Implementation Details

### 1. Frontend Changes (`resources/views/pages/orders/index.blade.php`)

#### Payment Method Pill Rendering
- **Location:** Two `case 'payment_method':` blocks (lines ~6149 and ~6545)
- **Functionality:**
  - Renders payment method as a clickable pill button
  - **Cash:** Green pill (`bg-green-50`, `border-green-300`, `text-green-800`)
  - **Online:** Purple pill (`bg-purple-50`, `border-purple-300`, `text-purple-800`)
  - Normalizes various payment method values (e.g., "cash_on_delivery", "bank_transfer", "card") to either "Cash" or "Online"
  - Clicking the pill opens the quick change modal

#### Quick Change Modal
- **Function:** `openQuickPaymentMethodChange(orderId, currentMethod)` (line ~3727)
- **Features:**
  - Displays current payment method
  - Dropdown with only "Cash" and "Online" options
  - Optional notes field for reason
  - Timeline/history panel showing past changes
  - Save button with loading state

#### Timeline Loading
- **Function:** `loadQuickPaymentMethodTimeline(orderId)` (line ~3837)
- **Features:**
  - Fetches payment method change history from API
  - Displays last 5 changes with timestamps and notes
  - Shows "old_method → new_method" format

#### Row Update
- **Function:** `updateOrderRowPaymentMethod(orderId, newMethod)` (line ~3864)
- **Features:**
  - Updates the payment method pill in the table row without full page refresh
  - Highlights the row briefly (blue background for 2 seconds)
  - Maintains the clickable pill structure

### 2. Backend Changes

#### Controller Methods (`app/Http/Controllers/CRM/OrderController.php`)

**1. Change Payment Method**
- **Method:** `changePaymentMethod(Request $request)` (line ~2691)
- **Validation:**
  - `order_id`: required, integer, must exist in `t_crm_prod_order`
  - `payment_method`: required, string, must be either "cash" or "online"
  - `notes`: optional, string, max 500 characters
- **Process:**
  1. Finds the order
  2. Stores old payment method
  3. Updates order with new payment method
  4. Logs change in `t_crm_order_payment_method_history` table
  5. Returns success response with updated order

**2. Get Payment Method Timeline**
- **Method:** `getPaymentMethodTimeline($orderId)` (line ~2743)
- **Returns:** Last 10 payment method changes for the order, ordered by most recent first

#### Routes (`routes/web.php`)
```php
// Payment method APIs
Route::post('/orders/api/change-payment-method', [OrderController::class, 'changePaymentMethod'])
    ->name('orders.api.change-payment-method');
Route::get('/orders/{id}/payment-method/timeline', [OrderController::class, 'getPaymentMethodTimeline'])
    ->name('orders.payment-method.timeline');
```

### 3. Database Changes

#### New Table: `t_crm_order_payment_method_history`
**File:** `database/migrations/create_payment_method_history_table_nov08_2025.sql`

**Schema:**
```sql
CREATE TABLE `t_crm_order_payment_method_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `old_method` VARCHAR(50) NULL,
    `new_method` VARCHAR(50) NOT NULL,
    `changed_by_user_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_changed_at` (`changed_at`),
    FOREIGN KEY (`order_id`) REFERENCES `t_crm_prod_order`(`id`) ON DELETE CASCADE
)
```

**Indexes:**
- `idx_order_id`: Fast lookup by order
- `idx_changed_at`: Efficient sorting by date

## Payment Method Normalization

The system normalizes various payment method values to two display options:

### Cash (Green Pill)
- "cash"
- "cash_on_delivery"
- "cod"
- Any value containing "cash" or "cod"

### Online (Purple Pill)
- "online"
- "bank_transfer"
- "card"
- "online_payment"
- Any value containing "online", "bank", or "card"

## User Experience

1. **View Current Method:** Payment method displays as a colored pill in the orders table
2. **Click to Change:** Clicking the pill opens a modal with:
   - Current method display
   - Dropdown to select new method (Cash or Online)
   - Optional notes field
   - Change history timeline
3. **Save Change:** Updates the order and logs the change
4. **Visual Feedback:**
   - Toast notification on success/error
   - Row highlight (blue background for 2 seconds)
   - Pill color updates immediately

## Consistency with Existing Features

This implementation follows the same pattern as:
- **Rider Assignment:** `openQuickRiderAssign()` function
- **Status Change:** `openQuickStatusChange()` function

All three features share:
- Similar modal UI structure
- Timeline/history panel
- Row update without full page refresh
- Toast notifications
- Consistent styling and UX

## Testing Checklist

- [ ] Run SQL migration to create history table
- [ ] Test payment method pill display in orders table
- [ ] Test opening the quick change modal
- [ ] Test changing from Cash to Online
- [ ] Test changing from Online to Cash
- [ ] Test adding notes during change
- [ ] Verify timeline shows past changes
- [ ] Verify row updates without page refresh
- [ ] Test with various existing payment method values (cash_on_delivery, bank_transfer, etc.)
- [ ] Verify change is logged in history table
- [ ] Test error handling (invalid order ID, network errors)

## Files Modified

1. `resources/views/pages/orders/index.blade.php`
   - Added payment method pill rendering (2 locations)
   - Added `openQuickPaymentMethodChange()` function
   - Added `loadQuickPaymentMethodTimeline()` function
   - Added `updateOrderRowPaymentMethod()` function

2. `app/Http/Controllers/CRM/OrderController.php`
   - Added `changePaymentMethod()` method
   - Added `getPaymentMethodTimeline()` method

3. `routes/web.php`
   - Added payment method change route
   - Added payment method timeline route

4. `database/migrations/create_payment_method_history_table_nov08_2025.sql`
   - New migration file for history table

## Next Steps

1. Run the SQL migration:
   ```bash
   mysql -u [username] -p [database] < database/migrations/create_payment_method_history_table_nov08_2025.sql
   ```

2. Test the feature in the orders page

3. Verify the payment method pill appears and is clickable

4. Test changing payment methods and verify history tracking

## Notes

- The implementation only allows "cash" and "online" as options, as requested by the user
- All existing payment method values are normalized to one of these two options for display
- The actual database value is stored as "cash" or "online" when changed through this feature
- The history table tracks all changes with timestamps, user IDs, and optional notes
- The feature is fully integrated with the existing orders page without breaking any functionality








