# Invoice Settlement System - Testing Guide

## 🧪 Quick Start Testing

### Prerequisites
- ✅ SQL migration has been run (`add_invoice_settlement_tracking.sql`)
- ✅ Code deployed to server
- ✅ Cache cleared (`php artisan route:clear && php artisan config:clear && php artisan view:clear`)

---

## Test Scenario 1: Basic Settlement Flow

### Step 1: Create Test Data
1. Create a test order for a rider (e.g., Waseem, Arslan, Kanan)
2. Mark it as delivered
3. Verify invoice appears in ledger with `settlement_status = 'open'`

**SQL Check:**
```sql
SELECT id, order_id, transaction_type, settlement_status, amount
FROM t_fin_ledger
WHERE transaction_type = 'invoice'
AND order_id = [YOUR_ORDER_ID];
```

### Step 2: Open Settlement Modal
1. Navigate to: **Finance > Employee Cash**
2. Click on rider's account
3. Click **"📋 Settle & Deposit"** button
4. Modal should show loading spinner, then invoice table

**Expected:**
- ✅ Invoice appears in table
- ✅ Checkbox is checked by default
- ✅ Amount shows correct total
- ✅ Summary shows "1 invoice, Rs. [amount]"

### Step 3: Submit Settlement
1. Verify date is correct
2. Amount should match invoice total
3. Add optional notes (e.g., "Test settlement")
4. Click **"💾 Submit for Approval"**

**Expected:**
- ✅ Redirects to rider's account page
- ✅ Success message: "Settlement deposit recorded and pending approval!"
- ✅ New pending transaction appears in table

**SQL Check:**
```sql
SELECT id, transaction_type, description, approval_status, amount
FROM t_fin_ledger
WHERE transaction_type = 'employee_deposit'
AND approval_status = 'pending'
ORDER BY id DESC
LIMIT 1;
```

### Step 4: Manager Approval
1. Navigate to: **Approvals** (top menu)
2. Find the settlement deposit
3. Description should show invoice number(s)
4. Click **"Approve"**

**Expected:**
- ✅ Success message
- ✅ Balances updated
- ✅ Invoice marked as 'settled'

**SQL Check:**
```sql
-- Check invoice is settled
SELECT id, settlement_status, settled_amount, settled_at, settled_via_ledger_id
FROM t_fin_ledger
WHERE order_id = [YOUR_ORDER_ID];

-- Check audit trail
SELECT * FROM t_fin_invoice_settlements
WHERE invoice_ledger_id = [INVOICE_LEDGER_ID];
```

---

## Test Scenario 2: Partial Settlement

### Setup:
1. Create 3 orders for same rider:
   - Order A: Rs. 2000
   - Order B: Rs. 1500
   - Order C: Rs. 1000
2. Deliver all 3 orders
3. Total outstanding: Rs. 4500

### Test:
1. Open "Settle & Deposit" modal
2. All 3 invoices should be selected
3. **Change amount to Rs. 3000** (partial)
4. Submit for approval
5. Manager approves

**Expected Results:**
- ✅ Order A: Fully settled (Rs. 2000)
- ✅ Order B: Partially settled (Rs. 1000 of Rs. 1500)
- ✅ Order C: Still open (Rs. 0 settled)
- ✅ Settlement modal shows Order B and C next time

**SQL Check:**
```sql
SELECT 
    o.order_number,
    l.amount,
    l.settled_amount,
    l.settlement_status,
    (l.amount - COALESCE(l.settled_amount, 0)) as remaining
FROM t_fin_ledger l
LEFT JOIN t_crm_prod_order o ON o.id = l.order_id
WHERE l.to_account_id = [RIDER_ACCOUNT_ID]
AND l.transaction_type = 'invoice'
ORDER BY l.transaction_date;
```

### Second Settlement:
1. Open modal again
2. Should show only Order B (Rs. 500 remaining) and Order C (Rs. 1000)
3. Submit Rs. 1500
4. Manager approves

**Expected:**
- ✅ All invoices now fully settled
- ✅ Modal shows "All invoices settled!" next time

---

## Test Scenario 3: Selective Settlement

### Test:
1. Rider has 3 outstanding invoices
2. Open "Settle & Deposit"
3. **Uncheck middle invoice** (select only 2)
4. Submit
5. Manager approves

**Expected:**
- ✅ Only selected 2 invoices marked as settled
- ✅ Unchecked invoice remains open
- ✅ Audit trail shows only 2 settlements

---

## Test Scenario 4: No Outstanding Invoices

### Test:
1. Settle all invoices for a rider
2. Open "Settle & Deposit" again

**Expected:**
- ✅ Shows "All invoices settled!" message
- ✅ Form is hidden
- ✅ Only "Close" button visible

---

## Test Scenario 5: Regular Deposit (Non-Settlement)

### Test:
1. Click **"💵 Record Deposit to NF Cash"** (regular button)
2. Enter amount Rs. 1000
3. Submit
4. Manager approves

**Expected:**
- ✅ Regular deposit created
- ✅ No invoice settlement processing
- ✅ Balances update normally

**This ensures old functionality still works!**

---

## Test Scenario 6: Manager Rejection

### Test:
1. Submit settlement for 2 invoices
2. Manager **rejects** the deposit

**Expected:**
- ✅ Deposit marked as rejected
- ✅ Invoices remain 'open'
- ✅ No settlement records created
- ✅ Rider can try again

---

## Test Scenario 7: Legacy Data Compatibility

### Test:
1. Find an old order (before settlement system)
2. Check its invoice in database

**SQL Check:**
```sql
SELECT id, settlement_status, settled_amount
FROM t_fin_ledger
WHERE order_id = [OLD_ORDER_ID]
AND transaction_type = 'invoice';
```

**Expected:**
- ✅ `settlement_status = 'settled'` (migrated)
- ✅ `settled_amount = amount` (fully paid)
- ✅ No errors when viewing

---

## 🐛 Troubleshooting

### Problem: Modal doesn't open
**Fix:**
1. Check browser console for JavaScript errors
2. Verify route exists: `php artisan route:list | grep outstanding-invoices`
3. Clear cache: `php artisan view:clear`

### Problem: No invoices showing
**Check:**
1. Are there delivered orders for this rider?
2. SQL: `SELECT * FROM t_fin_ledger WHERE to_account_id = [ID] AND transaction_type = 'invoice' AND settlement_status = 'open'`
3. Check browser Network tab for API response

### Problem: Submit button disabled
**Reason:**
- No invoices selected, OR
- Amount is 0 or empty

**Fix:** Select at least one invoice and enter valid amount

### Problem: Approval doesn't settle invoices
**Check:**
1. Laravel logs for errors
2. Verify session has settlement data:
   ```php
   // In controller, add debugging:
   \Log::info('Settlement data:', [\Session::get("settlement_pending_{$ledger->id}")]);
   ```
3. Check `t_fin_invoice_settlements` table for records

---

## 📊 Useful SQL Queries for Testing

### All Outstanding Invoices:
```sql
SELECT 
    a.account_name,
    o.order_number,
    l.transaction_date,
    l.amount,
    COALESCE(l.settled_amount, 0) as settled,
    (l.amount - COALESCE(l.settled_amount, 0)) as outstanding
FROM t_fin_ledger l
JOIN t_fin_accounts a ON a.id = l.to_account_id
LEFT JOIN t_crm_prod_order o ON o.id = l.order_id
WHERE l.transaction_type = 'invoice'
AND l.settlement_status = 'open'
ORDER BY a.account_name, l.transaction_date;
```

### Settlement Audit Trail:
```sql
SELECT 
    d.id as deposit_id,
    d.description as deposit_desc,
    d.amount as deposit_amount,
    o.order_number,
    s.settled_amount as amount_allocated,
    s.created_at as settled_at
FROM t_fin_invoice_settlements s
JOIN t_fin_ledger d ON d.id = s.settlement_deposit_id
JOIN t_fin_ledger i ON i.id = s.invoice_ledger_id
LEFT JOIN t_crm_prod_order o ON o.id = i.order_id
ORDER BY d.id DESC, s.id;
```

### Deposit with Settlement Intent:
```sql
SELECT 
    l.id,
    l.description,
    l.amount,
    l.approval_status,
    l.comments
FROM t_fin_ledger l
WHERE l.transaction_type = 'employee_deposit'
AND l.description LIKE '%Settlement%'
ORDER BY l.id DESC;
```

---

## ✅ Sign-off Checklist

After testing all scenarios:

- [ ] Basic settlement works (single invoice)
- [ ] Partial settlement allocates correctly
- [ ] Selective settlement respects checkboxes
- [ ] "No invoices" state displays correctly
- [ ] Regular deposits still work (non-settlement)
- [ ] Manager rejection doesn't affect invoices
- [ ] Legacy invoices show as settled
- [ ] Audit trail records created
- [ ] Balance calculations correct
- [ ] No JavaScript errors in console
- [ ] No Laravel errors in logs

**Once all checked, system is ready for production use! 🎉**
