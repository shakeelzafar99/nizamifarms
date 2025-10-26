# Payment Method Change After Delivery - Implementation Complete

## Overview

This feature allows changing the payment method of a delivered order (Cash ↔ Online) and automatically updates all ledger entries and account balances.

## How It Works

### Scenario 1: Cash → Online

**Before:**
```
Order #2610 - Rs. 1,000 (Cash on Delivery)
├─ Ledger: Revenue → Rider Cash (approved)
├─ Rider Balance: +Rs. 1,000
└─ Daily Closing: Expects Rs. 1,000 from rider
```

**User changes to "Online Payment":**
```
1. System checks: Is invoice settled? NO ✅
2. Reverse old ledger:
   ├─ Mark as 'reversed'
   ├─ Rider Balance: -Rs. 1,000 (reversed)
   └─ Revenue: +Rs. 1,000 (reversed)
3. Create new ledger:
   ├─ Revenue → Online Bank (pending approval)
   └─ Online Bank: +Rs. 1,000 (after approval)
```

**After:**
```
Order #2610 - Rs. 1,000 (Online Payment)
├─ Old Ledger: REVERSED (audit trail)
├─ New Ledger: Revenue → Online Bank (pending)
├─ Rider Balance: Rs. 0 ✅
└─ Daily Closing: Expects Rs. 0 from rider ✅
```

### Scenario 2: Online → Cash

**Before:**
```
Order #2608 - Rs. 500 (Online Payment)
├─ Ledger: Revenue → Online Bank (pending approval)
├─ Online Bank: +Rs. 500 (pending)
└─ Rider Balance: Rs. 0
```

**User changes to "Cash on Delivery":**
```
1. System checks: Is invoice settled? NO ✅
2. Reverse old ledger:
   ├─ Mark as 'reversed'
   └─ No balance changes (was pending, not approved)
3. Create new ledger:
   ├─ Revenue → Rider Cash (auto-approved)
   ├─ Revenue: -Rs. 500
   └─ Rider Balance: +Rs. 500
```

**After:**
```
Order #2608 - Rs. 500 (Cash on Delivery)
├─ Old Ledger: REVERSED (audit trail)
├─ New Ledger: Revenue → Rider Cash (approved)
├─ Rider Balance: +Rs. 500 ✅
└─ Daily Closing: Expects Rs. 500 from rider ✅
```

## Validation Rules

### ✅ Allowed

- Payment method change for delivered orders
- Cash → Online
- Online → Cash
- Multiple changes (each creates audit trail)

### ❌ Not Allowed

1. **Invoice Already Settled**
   ```
   Error: "Cannot change payment method: Invoice has already been settled."
   ```

2. **Partial Settlement**
   ```
   Error: "Cannot change payment method: Invoice has partial settlement."
   ```

3. **Webhook Updates**
   - Automatic updates from WooCommerce/Shopify skip this logic

## Files Modified

### 1. `app/Models/FIN/LedgerModel.php`

**Added:**
```php
const STATUS_REVERSED = 'reversed'; // For payment method changes after delivery
```

### 2. `app/Http/Controllers/CRM/OrderController.php`

**Added (Lines 519-580):**
- Payment method change detection
- Settlement status validation
- Call to `handlePaymentMethodChange()`

**Added (Lines 1860-1970):**
- `handlePaymentMethodChange()` method
- `reverseLedgerEntry()` method

### 3. Database Migration

**File:** `add_reversed_status_to_ledger.sql`

```sql
ALTER TABLE t_fin_ledger 
MODIFY COLUMN approval_status ENUM('pending', 'approved', 'rejected', 'reversed') 
DEFAULT 'pending';
```

## Technical Details

### Reverse & Repost Flow

```php
// 1. Detect payment method change
if ($oldPaymentMethod !== $newPaymentMethod) {
    // 2. Check settlement status
    if ($ledger->settlement_status === 'settled') {
        return error('Cannot change: Already settled');
    }
    
    // 3. Reverse old ledger entry
    $this->reverseLedgerEntry($oldLedger, $reason);
    
    // 4. Create new ledger entry
    $ledgerService->postInvoiceFromOrder($order);
    
    // 5. Link new ledger to order
    $order->ledger_transaction_id = $newLedger->id;
}
```

### Balance Reversal Logic

```php
// Only reverse balances if transaction was APPROVED
if ($ledger->approval_status === 'approved') {
    // Reverse debit (add back to from_account)
    $fromAccount->current_balance += $ledger->amount;
    
    // Reverse credit (subtract from to_account)
    $toAccount->current_balance -= $ledger->amount;
}
```

**Why this matters:**
- ✅ **Approved transactions:** Balances were updated → Must reverse
- ✅ **Pending transactions:** Balances NOT updated → No reversal needed
- ✅ **Rejected transactions:** Balances never updated → No reversal needed

## Response Messages

### Success (Payment Method Changed Only)
```json
{
    "success": true,
    "message": "Payment method changed from 'Cash on Delivery' to 'Online Payment'. Ledger entry updated.",
    "payment_method_changed": true,
    "order": { ... }
}
```

### Success (With Ledger Adjustment)
```json
{
    "success": true,
    "message": "Order updated successfully. Ledger adjustment created and pending L1→L2 approval. Payment method changed from 'Cash on Delivery' to 'Online Payment'. Ledger entry updated.",
    "requires_approval": true,
    "adjustment_id": 123,
    "payment_method_changed": true,
    "order": { ... }
}
```

### Error (Already Settled)
```json
{
    "success": false,
    "message": "Cannot change payment method: Invoice has already been settled.",
    "error_type": "already_settled"
}
```

### Error (Partial Settlement)
```json
{
    "success": false,
    "message": "Cannot change payment method: Invoice has partial settlement.",
    "error_type": "partial_settlement"
}
```

## Impact on System Components

### ✅ Fixed/Updated

1. **Employee Cash Balance**
   - Cash → Online: Balance decreases correctly
   - Online → Cash: Balance increases correctly

2. **Daily Closing**
   - Shows accurate cash expected from riders
   - Reversed entries excluded from calculations

3. **Online Bank**
   - Cash → Online: Shows pending invoice (requires approval)
   - Online → Cash: Pending invoice removed

4. **Settlement Flow**
   - Works correctly after payment method change
   - Prevents changes if already settled

5. **Audit Trail**
   - Old ledger marked as 'reversed' with reason
   - New ledger has comment linking to old entry
   - Complete history preserved

### ❌ Not Affected

- Order history
- Customer records
- Product inventory
- Shipping/delivery status
- Existing ledger adjustments (amount-based)

## Testing Checklist

### Cash → Online

- [ ] Order delivered as Cash
- [ ] Change to Online Payment
- [ ] Rider balance decreases
- [ ] Online bank shows pending invoice
- [ ] Daily closing updated
- [ ] Old ledger shows 'reversed' status
- [ ] New ledger created with correct account
- [ ] Audit trail complete

### Online → Cash

- [ ] Order delivered as Online
- [ ] Change to Cash on Delivery
- [ ] Online bank pending invoice removed
- [ ] Rider balance increases
- [ ] Daily closing updated
- [ ] Old ledger shows 'reversed' status
- [ ] New ledger created with correct account
- [ ] Audit trail complete

### Edge Cases

- [ ] Try changing settled invoice → Error message
- [ ] Try changing partially settled invoice → Error message
- [ ] Multiple payment method changes → Each creates audit trail
- [ ] Webhook update → Skips payment method change logic
- [ ] Change payment method + amount → Both handled correctly

## Database Query Examples

### Find Reversed Ledger Entries

```sql
SELECT 
    l.id,
    l.transaction_date,
    l.description,
    l.amount,
    l.approval_status,
    l.comments,
    o.order_number
FROM t_fin_ledger l
LEFT JOIN t_crm_prod_order o ON l.order_id = o.id
WHERE l.approval_status = 'reversed'
ORDER BY l.created_at DESC;
```

### Find Orders with Payment Method Changes

```sql
SELECT 
    o.order_number,
    o.payment_method,
    COUNT(l.id) as ledger_count,
    GROUP_CONCAT(l.approval_status) as ledger_statuses
FROM t_crm_prod_order o
INNER JOIN t_fin_ledger l ON l.order_id = o.id
WHERE l.transaction_type = 'invoice'
GROUP BY o.id
HAVING ledger_count > 1;
```

### Verify Balance Consistency

```sql
-- Check if any reversed ledgers still affecting balances
SELECT 
    l.id,
    l.description,
    l.amount,
    l.approval_status,
    fa.account_name as from_account,
    ta.account_name as to_account
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts fa ON l.from_account_id = fa.id
LEFT JOIN t_fin_accounts ta ON l.to_account_id = ta.id
WHERE l.approval_status = 'reversed'
  AND l.transaction_type = 'invoice';
```

## Logging

All payment method changes are logged with:
- Order ID and number
- Old and new payment methods
- Old and new ledger IDs
- Balance changes
- Timestamps

**Log Example:**
```
[2025-10-25 20:30:15] local.INFO: Payment method changed successfully
{
    "order_id": 2610,
    "old_method": "Cash on Delivery",
    "new_method": "Online Payment",
    "old_ledger_id": 1234,
    "new_ledger_id": 1235
}
```

## Troubleshooting

### Issue: Rider balance not updating

**Check:**
1. Is old ledger marked as 'reversed'?
2. Were balances actually reversed?
3. Is new ledger created and approved?

**SQL:**
```sql
SELECT * FROM t_fin_ledger WHERE order_id = [ORDER_ID] ORDER BY created_at DESC;
```

### Issue: Daily closing showing wrong amount

**Check:**
1. Are reversed ledgers excluded from calculations?
2. Is the query filtering by `approval_status != 'reversed'`?

**Fix:**
```php
$ledgerQuery->where('approval_status', '!=', 'reversed');
```

### Issue: Online bank not showing invoice

**Check:**
1. Is new ledger created with correct `to_account_id`?
2. Is `mode` set to 'online'?
3. Is `approval_status` set to 'pending'?

## Notes

- No approval required for payment method change (online invoices still require approval)
- Works in both directions (Cash ↔ Online)
- Complete audit trail maintained
- Balances always accurate
- Settlement flow protected
- Webhook updates skip this logic

