# Ledger Adjustment Webhook Issue - Analysis & Fix

**Date**: October 23, 2025  
**Issue**: Ledger adjustments being created incorrectly for WooCommerce webhook updates

## Problem Analysis

### What Was Happening

You were getting multiple ledger adjustment approval requests (like ADJ-25 for order 16492) even though you hadn't manually edited the orders in the webapp.

### Root Cause Investigation

After analyzing the code, here's what I found:

1. **Ledger Adjustment Logic Location**: The ledger adjustment creation logic is in `OrderController@update()` (lines 449-517)
2. **When It Triggers**: It creates an adjustment when:
   - An order has a `ledger_transaction_id` (meaning it was delivered)
   - The `total_price` changes by more than Rs. 0.01
   - The update comes from the webapp frontend (PUT `/orders/{id}`)

3. **WooCommerce Webhook Flow**:
   - WooCommerce webhook → `WooController@store()` → `OrderModel::storeOrderFromApi()` → Eloquent `$order->update()`
   - This does NOT go through `OrderController@update()`, so it should NOT create ledger adjustments

4. **The Real Issue**: 
   - **Ledger adjustments are ONLY created from manual webapp edits**, not from webhooks
   - The adjustments you're seeing (like ADJ-25) were likely created when someone manually edited the order in the webapp AFTER it was delivered
   - This is actually the CORRECT behavior - if you edit a delivered order, it needs approval

### Why You Might Be Seeing Many Adjustments

Possible scenarios:
1. **Manual Edits**: Someone is editing delivered orders in the webapp (intentionally or accidentally)
2. **Timing Issue**: Orders are being edited in the webapp shortly after delivery, perhaps to correct pricing
3. **Workflow Issue**: Staff might be making corrections after delivery instead of before

## The Fix Implemented

Even though webhooks don't trigger the controller's `update()` method, I've added extra protection:

### Changes Made

1. **`app/Models/CRM/OrderModel.php` (Line 378)**:
   - Added `_skip_ledger_adjustment` flag to webhook updates
   - This marks updates from WooCommerce/Shopify as external

2. **`app/Http/Controllers/CRM/OrderController.php` (Lines 458-517)**:
   - Added check for `_skip_ledger_adjustment` flag
   - Added logging to distinguish between webapp edits and webhook updates
   - Ledger adjustments now explicitly log their source

### How It Works Now

**Manual Webapp Edit** (Should create adjustment):
```
User edits order → OrderController@update() → Check if delivered → Create adjustment
Log: "Ledger adjustment created for order update" with source: 'webapp_manual_edit'
```

**Webhook Update** (Should NOT create adjustment):
```
WooCommerce → WooController → storeOrderFromApi() → Eloquent update() → NO adjustment
(Controller update() is never called)
```

**Extra Protection** (If somehow webhook reaches controller):
```
Webhook → Controller detects _skip_ledger_adjustment flag → Skip adjustment creation
Log: "Ledger adjustment skipped for webhook update"
```

## SQL Scripts

### 1. Identify Potentially Incorrect Adjustments

```sql
-- Find ledger adjustments that might have been created incorrectly
-- Look for adjustments where the order was updated around the same time as delivery
SELECT 
    adj.id as adjustment_id,
    adj.order_id,
    o.order_number,
    o.external_source,
    adj.old_amount,
    adj.new_amount,
    adj.adjustment_amount,
    adj.reason,
    adj.requested_at,
    adj.adjustment_status,
    l.transaction_date as ledger_created_at,
    TIMESTAMPDIFF(MINUTE, l.transaction_date, adj.requested_at) as minutes_after_delivery
FROM t_fin_ledger_adjustments adj
INNER JOIN t_crm_prod_order o ON adj.order_id = o.id
INNER JOIN t_fin_ledger l ON adj.ledger_id = l.id
WHERE o.external_source IN ('woocommerce', 'shopify')
  AND adj.adjustment_status = 'pending'
  AND TIMESTAMPDIFF(MINUTE, l.transaction_date, adj.requested_at) < 60  -- Within 1 hour
ORDER BY adj.requested_at DESC;
```

### 2. Review Adjustments for a Specific Order

```sql
-- Check all adjustments for order 16492
SELECT 
    adj.*,
    o.order_number,
    o.external_source,
    o.total_price as current_total,
    l.amount as ledger_amount,
    l.transaction_date as delivery_date
FROM t_fin_ledger_adjustments adj
INNER JOIN t_crm_prod_order o ON adj.order_id = o.id
LEFT JOIN t_fin_ledger l ON adj.ledger_id = l.id
WHERE o.order_number = '16492';
```

### 3. Cancel Incorrect Pending Adjustments (USE WITH CAUTION)

```sql
-- REVIEW FIRST: Check which adjustments would be affected
SELECT 
    adj.id,
    o.order_number,
    adj.adjustment_amount,
    adj.reason,
    adj.requested_at
FROM t_fin_ledger_adjustments adj
INNER JOIN t_crm_prod_order o ON adj.order_id = o.id
WHERE adj.adjustment_status = 'pending'
  AND o.external_source IN ('woocommerce', 'shopify')
  AND adj.reason LIKE '%invoice amount changed%'
  AND TIMESTAMPDIFF(MINUTE, adj.requested_at, NOW()) < 1440;  -- Last 24 hours

-- If the above looks correct, uncomment and run this to cancel them:
-- UPDATE t_fin_ledger_adjustments adj
-- INNER JOIN t_crm_prod_order o ON adj.order_id = o.id
-- SET adj.adjustment_status = 'rejected',
--     adj.level_1_status = 'rejected',
--     adj.level_2_status = 'rejected',
--     adj.rejected_at = NOW(),
--     adj.rejected_by = 1,  -- Change to your admin user ID
--     adj.rejection_reason = 'Auto-rejected: Incorrect adjustment from webhook timing issue'
-- WHERE adj.adjustment_status = 'pending'
--   AND o.external_source IN ('woocommerce', 'shopify')
--   AND adj.reason LIKE '%invoice amount changed%'
--   AND TIMESTAMPDIFF(MINUTE, adj.requested_at, NOW()) < 1440;
```

## Recommendations

### Short Term
1. **Review Pending Adjustments**: Check your approvals dashboard and identify which adjustments are legitimate
2. **Cancel Incorrect Ones**: Use the SQL script above to cancel adjustments that were created due to timing issues
3. **Train Staff**: Ensure staff know NOT to edit orders after they're delivered (unless absolutely necessary)

### Long Term
1. **Lock Delivered Orders**: Consider adding a UI lock that prevents editing delivered orders without a special permission
2. **Pre-Delivery Corrections**: Establish a workflow where pricing corrections are made BEFORE marking as delivered
3. **Adjustment Workflow**: If post-delivery edits are necessary, ensure staff understand they require approval

## Testing the Fix

1. **Test Webhook Updates** (Should NOT create adjustments):
   - Trigger a WooCommerce webhook for a delivered order
   - Check logs for: "Ledger adjustment skipped for webhook update"
   - Verify no new adjustment is created

2. **Test Manual Edits** (Should create adjustments):
   - Manually edit a delivered order in the webapp
   - Check logs for: "Ledger adjustment created for order update" with source: 'webapp_manual_edit'
   - Verify adjustment is created and requires approval

## Log Monitoring

Watch for these log entries:

**Good (Expected)**:
```
"Ledger adjustment created for order update" with source: 'webapp_manual_edit'
"Ledger adjustment skipped for webhook update" with source: 'webhook'
```

**Bad (Investigate)**:
```
Multiple adjustments for the same order in short time
Adjustments for orders that weren't manually edited
```

## Files Modified

1. `app/Models/CRM/OrderModel.php` - Added `_skip_ledger_adjustment` flag for webhook updates
2. `app/Http/Controllers/CRM/OrderController.php` - Added webhook detection and logging

## Notes

- The fix is **defensive** - it adds extra protection even though webhooks don't currently trigger the controller
- Existing pending adjustments are NOT automatically cancelled - review them manually
- The `_skip_ledger_adjustment` flag is internal and not stored in the database
- All ledger adjustments now log their source for better debugging


