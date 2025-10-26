# Troubleshooting Partial Payment Approval - October 26, 2025

## Issue: After Approval, Invoice Still Shows as "Deposit Pending"

### Symptoms:
1. Approved the partial payment deposit (Rs. 10,000)
2. Approval shows in Approvals Dashboard ✅
3. But Daily Closing still shows:
   - Invoice with "Deposit Pending" status
   - Deposit still showing with Approve/Reject buttons
   - Invoice in "PENDING" section instead of "PARTIAL"

---

## Root Cause Analysis

### Possible Causes:

1. **Page Not Refreshed** (Most Likely)
   - Daily Closing page loaded before approval
   - Showing cached/stale data
   - **Solution:** Refresh the page (F5 or Ctrl+R)

2. **Settlement Processing Failed**
   - Approval recorded but `processInvoiceSettlement()` failed
   - Invoice not updated in database
   - **Solution:** Check logs and run SQL diagnostic

3. **Metadata Missing**
   - Deposit doesn't have `settlement_metadata`
   - Settlement processing skipped
   - **Solution:** Check deposit record

---

## Diagnostic Steps

### Step 1: Refresh the Page
**Action:** Press F5 or Ctrl+R to refresh the Daily Closing page

**Expected Result:**
- ✅ Deposit disappears from pending list
- ✅ Invoice shows in "PARTIAL" card
- ✅ Invoice status shows "🟡 Partial"
- ✅ Settled amount shows Rs. 10,000
- ✅ Outstanding shows Rs. 300

**If this fixes it:** No further action needed - it was just stale data.

**If problem persists:** Continue to Step 2.

---

### Step 2: Check Invoice Status in Database

Run the SQL query: `check_invoice_status.sql`

```sql
-- Check invoice status
SELECT 
    l.id,
    o.order_number,
    l.amount,
    l.settled_amount,
    l.settlement_status,
    l.approval_status,
    l.updated_at
FROM t_fin_ledger l
LEFT JOIN t_crm_orders o ON l.order_id = o.id
WHERE l.transaction_type = 'invoice'
  AND o.order_number = 'NF-14556';
```

**Expected Result:**
```
id: [invoice_id]
order_number: NF-14556
amount: 10300.00
settled_amount: 10000.00  ← Should be 10000
settlement_status: partial  ← Should be 'partial'
approval_status: approved
updated_at: [recent timestamp]
```

**If `settled_amount` is still 0 or `settlement_status` is still 'open':**
→ Settlement processing failed. Continue to Step 3.

---

### Step 3: Check Deposit Record

```sql
-- Check deposit status and metadata
SELECT 
    l.id,
    l.description,
    l.amount,
    l.approval_status,
    l.settlement_metadata,
    l.approval_date,
    l.approved_by
FROM t_fin_ledger l
WHERE l.transaction_type = 'employee_deposit'
  AND l.description LIKE '%NF-14556%'
ORDER BY l.created_at DESC
LIMIT 1;
```

**Check:**
1. `approval_status` should be 'approved'
2. `settlement_metadata` should contain:
   ```json
   {
       "invoice_ids": [invoice_id],
       "deposit_amount": 10000,
       "total_outstanding": 10300,
       "is_partial_payment": true,
       "is_short_cash_settlement": false,
       "short_cash_amount": 300,
       "expense_category": "PENDING"
   }
   ```

**If `settlement_metadata` is NULL:**
→ Metadata wasn't saved. This is a critical issue.

---

### Step 4: Check Application Logs

Look for these log entries around the approval time:

```
[timestamp] local.INFO: Checking for settlement data
[timestamp] local.INFO: Processing invoice settlement
[timestamp] local.INFO: Invoice settlement completed
```

**If you see:**
```
local.WARNING: No settlement data found for deposit - invoices will not be auto-settled
```
→ Metadata is missing. Settlement was skipped.

**If you see:**
```
local.ERROR: Error processing invoice settlement
```
→ Settlement processing failed. Check the error message.

---

## Manual Fix (If Settlement Failed)

If the settlement processing failed and the invoice wasn't updated, you can manually fix it:

### Option 1: Reject and Resubmit
1. Reject the approved deposit
2. Have the rider resubmit the partial payment
3. Approve again (should work this time)

### Option 2: Manual Database Update (Advanced)

```sql
-- ONLY RUN THIS IF SETTLEMENT PROCESSING FAILED
-- AND YOU'VE VERIFIED THE DEPOSIT WAS APPROVED

-- Update the invoice
UPDATE t_fin_ledger
SET 
    settled_amount = 10000.00,
    settlement_status = 'partial',
    updated_at = NOW()
WHERE id = [invoice_id]
  AND transaction_type = 'invoice';

-- Create settlement audit record
INSERT INTO t_fin_invoice_settlement (
    settlement_deposit_id,
    invoice_ledger_id,
    settled_amount,
    created_at,
    updated_at
) VALUES (
    [deposit_id],
    [invoice_id],
    10000.00,
    NOW(),
    NOW()
);
```

**⚠️ Warning:** Only use this if you understand SQL and have verified the issue.

---

## Prevention

To prevent this issue in the future:

### 1. Add Better Error Handling

Update `LedgerController::approve()` to show clear error messages if settlement processing fails.

### 2. Add Transaction Rollback

If settlement processing fails, the approval should be rolled back.

### 3. Add UI Feedback

Show a success message after approval that confirms:
- "Deposit approved successfully"
- "Invoice NF-14556 updated: Rs. 10,000 settled, Rs. 300 remaining"

---

## Expected Behavior After Fix

### Daily Closing Page:

**PARTIAL Card:**
```
🟡 PARTIAL
0 invoices
Rs. 0.00

Wait... this should show 1 invoice with Rs. 300 remaining!
```

Actually, I just realized another issue! The "PARTIAL" card summary is showing Rs. 0.00 because it's calculating the total outstanding for partial invoices, but the individual invoice should still appear in the list.

Let me check the card summary calculation...

Actually, looking at your screenshot, the "PARTIAL" card shows:
- 0 invoices
- Rs. 0.00

This means the invoice is NOT in the `$partialInvoices` collection. This confirms that the invoice database record hasn't been updated yet.

---

## Immediate Action Required

1. **Refresh the Daily Closing page** (F5)
2. **Check if the issue persists**
3. **If it persists, run the diagnostic SQL queries**
4. **Share the results** so we can determine if:
   - Settlement processing failed
   - Metadata is missing
   - Database update failed

---

## Quick Test

To verify the system is working correctly, try this:

1. Go to Approvals Dashboard
2. Find another pending deposit (if any)
3. Approve it
4. **Immediately refresh Daily Closing**
5. Check if the invoice updates correctly

If new approvals work but the old one doesn't, it might be a one-time glitch.

---

**Next Steps:**
1. Refresh the page
2. If problem persists, run the SQL diagnostics
3. Share the results for further investigation

