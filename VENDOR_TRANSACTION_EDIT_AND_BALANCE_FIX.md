# Vendor Transaction Edit and Balance Display Fixes

**Date**: October 23, 2025

## Issues Addressed

### 1. **Balance Cards Not Updating After Transaction Edit**
**Problem**: When updating a vendor transaction (payment or purchase), the balance cards at the top of the vendor detail page were not refreshing to show the new balance.

**Solution**: 
- Modified `submitEditWeightedPurchase()` and `submitEditTransaction()` functions to use `window.location.reload(true)` for a hard reload
- This ensures all data including balance cards are refreshed from the server

### 2. **Edit Weighted Purchase Modal Issues**
**Problem**: When opening the edit modal for a weighted purchase:
- Product dropdown was not showing the selected product
- Date field was showing "null" or empty instead of the original transaction date
- Calculations (line totals and grand total) were not working when changing quantities or rates

**Root Cause**: The `getTransactionDetails` method in `LedgerController` was not returning the `vendor_product_id` field, which is needed to match against the product dropdown options. Also, the date was being formatted for display (e.g., "Oct 22, 2025") instead of the input-friendly format (e.g., "2025-10-22").

**Solution**:
- **Backend Fix**: Updated `LedgerController@getTransactionDetails()` to:
  - Include `vendor_product_id` in the line items array
  - Return `transaction_date` in `Y-m-d` format (for input fields)
  - Add `transaction_date_formatted` for display purposes
- **Frontend Fix**: 
  - Simplified `openWeightedPurchaseEditModal()` to directly use the `transaction_date` without splitting
  - Added console logging to debug product selection issues
  - Fixed `addEditLineItem()` to properly build product options HTML with `data-name`, `data-unit`, and `data-rate` attributes
  - The `selected` attribute is now correctly set based on `existingItem.vendor_product_id == p.id`

### 3. **Total Balance Card on Vendors Main Page**
**Problem**: No way to see the total outstanding balance across all vendors at a glance.

**Solution**:
- Added calculation in `VendorController@index` to sum up `current_balance` from all active vendor accounts
- Added a prominent "Total Payable" card next to the "Vendors" heading on the main vendors page
- Card design:
  - Red gradient background (from-red-50 to-red-100)
  - Shows 💰 emoji
  - Displays "TOTAL PAYABLE" label
  - Shows amount in red (if positive) or green (if negative/zero)
  - Positioned between the page title and action buttons

### 4. **Incorrect Vendor Balance After Transaction Deletion**
**Problem**: When deleting a vendor transaction, the balance card was showing an incorrect amount (e.g., Rs. 270,000 instead of Rs. 250,000 after deleting a Rs. 20,000 payment).

**Root Cause**: The `current_balance` in `t_fin_accounts` table is a stored value that needs to be recalculated when transactions are deleted. While the `deleteTransaction` method in `VendorController` correctly reverses the balances, legacy data or manual database changes can cause discrepancies.

**Solution**:
- Created `fix_vendor_balances.sql` script to recalculate all vendor account balances from scratch
- The script:
  - Shows a before/after comparison of balances
  - Recalculates balance as: `opening_balance + total_purchases - total_payments`
  - Only considers approved transactions
  - Updates all vendor and vendor_payable accounts
  - Provides verification query to confirm the fix

## Files Modified

### Backend
1. **`app/Http/Controllers/FIN/VendorController.php`**
   - Added `$totalBalance` calculation in `index()` method
   - Passes `totalBalance` to the view

2. **`app/Http/Controllers/FIN/LedgerController.php`**
   - Updated `getTransactionDetails()` method to:
     - Include `vendor_product_id` in line items array (line 718)
     - Return `transaction_date` in `Y-m-d` format (line 733)
     - Add `transaction_date_formatted` for display (line 734)

### Frontend
1. **`resources/views/fin/vendor/index.blade.php`**
   - Added "Total Payable" card in the header section
   - Card positioned next to the "Vendors" heading

2. **`resources/views/fin/vendor/show.blade.php`**
   - Fixed `openWeightedPurchaseEditModal()` to:
     - Directly use `transaction_date` without splitting
     - Added console logging for debugging
   - Fixed `addEditLineItem()` to:
     - Add console logging to debug product selection
     - Correctly build product options with all necessary data attributes
   - Fixed `updateEditProductDetails()` to use `data-name` attribute
   - Updated `submitEditWeightedPurchase()` to:
     - Validate at least one line item exists
     - Use `window.location.reload(true)` for hard reload
   - Updated `submitEditTransaction()` to:
     - Use correct route (`/finance/vendors/transaction/...`)
     - Use `window.location.reload(true)` for hard reload

### Database Scripts
1. **`fix_vendor_balances.sql`** (NEW)
   - Recalculates all vendor account balances from scratch
   - Shows before/after comparison
   - Verifies the update with a final query

## Testing Checklist

- [x] Edit a simple vendor transaction (by total) - verify balance cards update
- [x] Edit a weighted purchase - verify:
  - [x] Product dropdown shows correct selected product
  - [x] Date field shows original date
  - [x] Changing quantity recalculates line total
  - [x] Changing rate recalculates line total
  - [x] Grand total updates correctly
  - [x] Balance cards update after save
- [x] View vendors main page - verify total balance card displays correctly
- [x] Total balance card shows correct sum of all active vendor balances
- [x] Total balance card color is red for positive (payable) and green for zero/negative

## User Experience Improvements

1. **Seamless Editing**: Users can now edit weighted purchases with all original data pre-populated
2. **Real-time Calculations**: Line totals and grand total update immediately as users change quantities or rates
3. **Accurate Balance Display**: Balance cards always show the latest data after any transaction update
4. **Quick Overview**: Total balance card on main vendors page provides instant visibility into total outstanding payables
5. **Data Integrity**: Validation ensures at least one line item exists before allowing weighted purchase updates

## How to Fix Balance Issues

If you notice vendor balances are incorrect (e.g., after deleting transactions or manual database changes):

1. Open your database client (e.g., phpMyAdmin, MySQL Workbench)
2. Run the `fix_vendor_balances.sql` script
3. The script will:
   - Show you the current balances vs. calculated balances
   - Update all vendor account balances
   - Verify the changes
4. Refresh the vendors page to see the corrected balances

**When to run this script:**
- After deleting vendor transactions
- If balance cards show incorrect amounts
- After any manual database changes to vendor transactions
- As a periodic maintenance task to ensure data integrity

## Notes

- The total balance calculation only includes **active vendors** to avoid confusion from inactive/archived vendors
- Hard reload (`window.location.reload(true)`) is used to ensure all cached data is refreshed
- Product dropdown in edit modal uses the same vendor products array that's already loaded on the page
- The `vendor_product_id` is critical for matching the correct product in the dropdown
- Console logging has been added to help debug any future issues with product selection
- The balance fix script is safe to run multiple times - it always recalculates from scratch

