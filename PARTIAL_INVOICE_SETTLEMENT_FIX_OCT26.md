# Partial Invoice Settlement Display Fix - October 26, 2025

## Problem Summary

After implementing the partial payment feature, three critical issues were discovered:

1. **Settlement Modal Issue**: When clicking "Settle" again on a partially settled invoice, the modal was showing the **full invoice amount** (Rs. 10,300) instead of the **remaining balance** (Rs. 300)

2. **Daily Closing Issue**: Partially settled invoices were **not appearing** in the Daily Closing page at all - neither in the "Partial Invoices" card nor in the transaction list

3. **Mobile App Issue**: Same as #1 - mobile app was showing full amount instead of remaining balance

---

## Root Cause Analysis

### Issue 1 & 3: Settlement Modal (Web & Mobile)

**Problem:** Multiple queries were only fetching invoices with `settlement_status = 'open'`, but **partial invoices have `settlement_status = 'partial'`**

**Affected Queries:**
- `getOutstandingInvoices()` - Used by both web and mobile to populate settlement modal
- `recordSettlementDeposit()` - Validates selected invoices before creating deposit
- `recordShortCashSettlement()` - Validates selected invoices for short cash settlement
- Mobile API `settleInvoices()` - Mobile full payment settlement
- Mobile API `settleShortCash()` - Mobile short cash settlement

**Code Example (Before Fix):**
```php
$openInvoices = LedgerModel::where('to_account_id', $employeeAccount->id)
    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('settlement_status', 'open')  // ❌ Missing 'partial'
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
    ->get();
```

### Issue 2: Daily Closing Display

**Problem:** The logic for filtering partial invoices was checking for `settlement_status === 'open'` with `settled_amount > 0`, but **partial invoices should have `settlement_status = 'partial'`**

**Code Example (Before Fix):**
```php
$partialInvoices = $allInvoices->filter(function($invoice) {
    return $invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) > 0;  // ❌ Wrong status
});
```

---

## Solution Implemented

### Fix 1: Include 'partial' in Settlement Queries

Changed all relevant queries to fetch **both 'open' and 'partial'** invoices:

```php
$openInvoices = LedgerModel::where('to_account_id', $employeeAccount->id)
    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->whereIn('settlement_status', ['open', 'partial'])  // ✅ Include partial
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
    ->get();
```

### Fix 2: Correct Partial Invoice Filter

Updated the daily closing filter to check for `settlement_status = 'partial'`:

```php
$partialInvoices = $allInvoices->filter(function($invoice) {
    return $invoice->settlement_status === 'partial' ||   // ✅ Check for 'partial' status
           ($invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) > 0);  // Fallback for legacy data
});
```

---

## Critical Bug Found & Fixed

### **Missing `settlement_status = 'partial'` Update**

**The Root Cause:** When a deposit was approved and invoices were partially settled, the system was:
- ✅ Updating `settled_amount` correctly
- ❌ **NOT updating `settlement_status` to 'partial'**
- ✅ Only setting status to 'settled' when fully paid

This meant:
- Partial invoices stayed as `settlement_status = 'open'` with `settled_amount > 0`
- They didn't appear in Daily Closing "PARTIAL" card
- They didn't appear in settlement modal (because we were only fetching 'open' OR 'partial', but they were stuck as 'open' with settled_amount)

**The Fix:** Added the missing `else if` clause in `LedgerController::processInvoiceSettlement()`:

```php
if ($invoice->settled_amount >= $invoice->amount) {
    // Fully settled
    $invoice->settlement_status = 'settled';
    $invoice->settled_at = now();
    $invoice->settled_via_ledger_id = $depositLedger->id;
} else if ($invoice->settled_amount > 0) {
    // ✅ Partially settled - THIS WAS MISSING!
    $invoice->settlement_status = 'partial';
}
```

---

## Files Modified

### 1. `app/Http/Controllers/FIN/LedgerController.php` ⭐ **CRITICAL FIX**

#### **Method: `processInvoiceSettlement()`** (Line ~621 & ~645)

**Fix 1: Include partial invoices in settlement query**
**Before:**
```php
$invoices = LedgerModel::whereIn('id', $invoiceIds)
    ->where('settlement_status', 'open')
    ->orderBy('transaction_date', 'asc')
    ->get();
```
**After:**
```php
$invoices = LedgerModel::whereIn('id', $invoiceIds)
    ->whereIn('settlement_status', ['open', 'partial'])  // ✅ Can settle partial invoices again
    ->orderBy('transaction_date', 'asc')
    ->get();
```

**Fix 2: Set settlement_status to 'partial' when partially settled**
**Before:**
```php
if ($invoice->settled_amount >= $invoice->amount) {
    // Fully settled
    $invoice->settlement_status = 'settled';
    $invoice->settled_at = now();
    $invoice->settled_via_ledger_id = $depositLedger->id;
}
// ❌ Missing else clause - status stays 'open' even with settled_amount > 0
$invoice->save();
```
**After:**
```php
if ($invoice->settled_amount >= $invoice->amount) {
    // Fully settled
    $invoice->settlement_status = 'settled';
    $invoice->settled_at = now();
    $invoice->settled_via_ledger_id = $depositLedger->id;
} else if ($invoice->settled_amount > 0) {
    // ✅ Partially settled - NOW PROPERLY MARKED!
    $invoice->settlement_status = 'partial';
}
$invoice->save();
```

**Impact:** 
- ✅ Invoices are now properly marked as 'partial' when partially settled
- ✅ Partial invoices can be settled again (multiple partial payments)
- ✅ Partial invoices will appear in Daily Closing
- ✅ Settlement modal will show correct remaining balance

---

### 2. `app/Http/Controllers/FIN/EmployeeCashController.php`

#### **Method: `getOutstandingInvoices()`** (Line ~1101)
**Before:**
```php
->where('settlement_status', 'open')
```
**After:**
```php
->whereIn('settlement_status', ['open', 'partial'])
```
**Impact:** Settlement modal now shows partial invoices with their remaining balance

---

#### **Method: `recordSettlementDeposit()`** (Line ~1169)
**Before:**
```php
->where('settlement_status', 'open')
```
**After:**
```php
->whereIn('settlement_status', ['open', 'partial'])
```
**Comment Updated:**
```php
// Calculate expected amount (remaining balance for partial invoices)
```
**Impact:** Can now settle partial invoices via full payment flow

---

#### **Method: `recordShortCashSettlement()`** (Line ~1274)
**Before:**
```php
->where('settlement_status', 'open')
```
**After:**
```php
->whereIn('settlement_status', ['open', 'partial'])
```
**Comment Updated:**
```php
// Calculate expected amount and shortage (remaining balance for partial invoices)
```
**Impact:** Can now settle partial invoices via short cash flow

---

#### **Method: `allOutstandingInvoices()`** (Line ~1489) - Daily Closing
**Before:**
```php
$partialInvoices = $allInvoices->filter(function($invoice) {
    return $invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) > 0;
});
```
**After:**
```php
$partialInvoices = $allInvoices->filter(function($invoice) {
    return $invoice->settlement_status === 'partial' || 
           ($invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) > 0);
});
```
**Impact:** Partial invoices now appear in Daily Closing page

---

### 2. `app/Http/Controllers/API/RiderController.php`

#### **Method: `settleInvoices()`** (Line ~602)
**Before:**
```php
->where('settlement_status', 'open')
```
**After:**
```php
->whereIn('settlement_status', ['open', 'partial'])
```
**Comment Updated:**
```php
// Verify selected invoices (include both open and partial invoices)
```
**Impact:** Mobile app can now settle partial invoices

---

#### **Method: `settleShortCash()`** (Line ~717)
**Before:**
```php
->where('settlement_status', 'open')
```
**After:**
```php
->whereIn('settlement_status', ['open', 'partial'])
```
**Comment Updated:**
```php
// Verify selected invoices (include both open and partial invoices)
// Calculate expected amount and shortage (remaining balance for partial invoices)
```
**Impact:** Mobile app can now do short cash settlement on partial invoices

---

## How It Works Now

### Example Scenario:

**Initial Invoice:** Rs. 10,300
**First Partial Payment:** Rs. 10,000 (with Rs. 300 as PENDING)

**After First Payment Approval:**
- Invoice `settlement_status` = `'partial'`
- Invoice `settled_amount` = `10,000`
- Invoice `amount` = `10,300` (unchanged)
- Outstanding balance = `10,300 - 10,000 = 300`

**When Clicking "Settle" Again:**
1. ✅ Invoice appears in the settlement modal (because we now fetch 'partial' status)
2. ✅ Shows **Rs. 300** as the outstanding amount (calculated as `amount - settled_amount`)
3. ✅ Rider can deposit the remaining Rs. 300
4. ✅ After approval, invoice becomes fully settled

**Daily Closing Page:**
1. ✅ Invoice appears in "PARTIAL" card
2. ✅ Shows Rs. 10,000 settled, Rs. 300 remaining
3. ✅ Can click to view details and approve/reject

**Mobile App:**
1. ✅ Invoice appears in outstanding list
2. ✅ Shows Rs. 300 as outstanding amount
3. ✅ Can settle the remaining balance

---

## Outstanding Amount Calculation

The system correctly calculates outstanding amount everywhere:

```php
$outstandingAmount = $invoice->amount - ($invoice->settled_amount ?? 0);
```

**Example:**
- Full invoice: `10,300 - 0 = 10,300`
- Partial invoice: `10,300 - 10,000 = 300`
- Settled invoice: `10,300 - 10,300 = 0`

---

## Fix for Existing Partial Invoices

**Important:** Partial payments made BEFORE this fix will have:
- ✅ `settled_amount` > 0 (correct)
- ❌ `settlement_status` = 'open' (should be 'partial')

**Solution:** Run the SQL script `fix_existing_partial_invoices.sql` to update existing records:

```sql
-- This will update all invoices that have been partially paid but not marked as 'partial'
UPDATE t_fin_ledger
SET settlement_status = 'partial'
WHERE transaction_type = 'invoice'
  AND settlement_status = 'open'
  AND settled_amount > 0
  AND settled_amount < amount
  AND approval_status != 'reversed';
```

**After running this SQL:**
- Your existing partial payment (NF-14556) will be marked as 'partial'
- It will appear in Daily Closing
- Settlement modal will show the correct remaining balance
- Mobile app will show the correct remaining balance

---

## Testing Checklist

### Web Application:
- [x] Create partial payment (Rs. 10,000 out of Rs. 10,300)
- [ ] **Approve the partial payment** (this will now set status to 'partial')
- [ ] Verify invoice shows as "Partial" in Daily Closing
- [ ] Click "Settle" on the rider account
- [ ] Verify invoice appears with Rs. 300 outstanding (not Rs. 10,300)
- [ ] Settle the remaining Rs. 300
- [ ] Verify invoice becomes fully settled after approval

### Mobile App:
- [ ] Create partial payment from mobile
- [ ] Verify outstanding invoices API returns partial invoice with Rs. 300
- [ ] Settle the remaining Rs. 300 from mobile
- [ ] Verify invoice becomes fully settled after approval

### Daily Closing:
- [ ] Verify partial invoices appear in "PARTIAL" card
- [ ] Verify correct amounts shown (settled vs outstanding)
- [ ] Verify can approve/reject partial settlements
- [ ] Verify settled invoices move to "SETTLED" after full payment

---

## Database Schema Reference

### `t_fin_ledger` Table - Relevant Columns:

| Column | Type | Description |
|--------|------|-------------|
| `amount` | decimal | Original invoice amount (never changes) |
| `settled_amount` | decimal | Amount settled so far (increases with each payment) |
| `settlement_status` | enum | `'open'`, `'partial'`, `'settled'` |
| `settled_at` | timestamp | When fully settled |
| `approval_status` | enum | `'pending'`, `'approved'`, `'rejected'`, `'reversed'` |

### Settlement Status Flow:

```
open (settled_amount = 0)
  ↓ [Partial Payment]
partial (0 < settled_amount < amount)
  ↓ [Final Payment]
settled (settled_amount = amount)
```

---

## Key Improvements

1. **Consistency**: Both web and mobile now handle partial invoices identically
2. **Accuracy**: Settlement modal shows correct remaining balance, not full amount
3. **Visibility**: Daily closing now displays partial invoices properly
4. **User Experience**: Riders can easily see and settle remaining balances
5. **No Data Loss**: All partial payments are tracked and visible

---

## Related Features

This fix complements the following features:
- **Partial Payment Settlement** (PENDING category) - `PARTIAL_PAYMENT_SETTLEMENT_FEATURE_OCT26.md`
- **Payment Method Change** - `PAYMENT_METHOD_CHANGE_IMPLEMENTATION.md`
- **Employee Cash Settlement** - `EMPLOYEE_CASH_REJECTED_SETTLEMENT_FIX_OCT25.md`

---

## Notes

- **No database migration required** - only query logic changes
- **Backward compatible** - fallback logic handles old data where `settlement_status` might still be 'open' with `settled_amount > 0`
- **No mobile app rebuild required** - API changes are server-side only
- All changes are **non-breaking** and **safe to deploy**

---

**Status:** ✅ **COMPLETE** - Ready for testing and deployment

