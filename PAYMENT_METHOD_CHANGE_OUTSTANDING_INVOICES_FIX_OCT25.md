# Payment Method Change - Outstanding Invoices Fix (Oct 25, 2025)

## Issue Summary

After implementing the payment method change feature (Cash ↔ Online after delivery), reversed ledger entries were still appearing in:
1. **Outstanding Invoices** page (`/finance/employee/outstanding-invoices`)
2. **Settle Invoices** modal on employee cash pages
3. **Daily Closing** calculations
4. **KPI cards** on the finance dashboard

This caused confusion as invoices that had been reversed (due to payment method changes) were still showing as "open" and available for settlement.

## Root Cause

When a payment method is changed after delivery:
- The old ledger entry is marked as `approval_status = 'reversed'`
- A new ledger entry is created with the correct payment method
- However, queries fetching outstanding invoices were only checking `settlement_status = 'open'`
- They were NOT excluding `reversed` transactions

This meant reversed invoices were still appearing in settlement lists and calculations.

## Solution

Added `approval_status != 'reversed'` filter to all queries that fetch invoices for:
- Outstanding invoices lists
- Settlement operations
- KPI calculations
- Daily closing reports

## Files Modified

### 1. `app/Http/Controllers/FIN/EmployeeCashController.php`

#### A. `getOutstandingInvoices()` Method (Line ~1090)
**Purpose:** Fetches outstanding invoices for the "Settle Invoices" modal

```php
// Get all open invoices for this rider (exclude those with pending settlements)
// IMPORTANT: Exclude reversed transactions (e.g., from payment method changes)
$openInvoices = LedgerModel::where('to_account_id', $employeeAccount->id)
    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('settlement_status', 'open')
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
    ->whereNotIn('id', $pendingSettlementInvoiceIds)
    ->orderBy('transaction_date', 'asc')
    ->get();
```

#### B. `allOutstandingInvoices()` Method (Line ~1415)
**Purpose:** Fetches all outstanding invoices across all riders for the manager view

```php
// Base query for ALL invoices (not just open)
// IMPORTANT: Exclude reversed transactions (e.g., from payment method changes)
$invoicesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
    ->with(['toAccount', 'order'])
    ->whereHas('toAccount', function($q) {
        $q->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH);
    });
```

#### C. `recordSettlementDeposit()` Method (Line ~1163)
**Purpose:** Validates selected invoices before creating settlement deposit

```php
// Verify selected invoices belong to this rider and are open
// Exclude reversed transactions (e.g., from payment method changes)
$selectedInvoices = LedgerModel::whereIn('id', $request->invoice_ids)
    ->where('to_account_id', $employeeAccount->id)
    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('settlement_status', 'open')
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
    ->orderBy('transaction_date', 'asc')
    ->get();
```

#### D. `recordShortCashSettlement()` Method (Line ~1266)
**Purpose:** Validates selected invoices for short cash settlement

```php
// Same filter as recordSettlementDeposit (applied via replace_all)
```

#### E. `index()` Method - KPI Calculations (Line ~202)
**Purpose:** Dashboard KPI for online invoices

```php
$onlineQuery = LedgerModel::where('to_account_id', $onlineAccount->id)
    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED); // Exclude reversed transactions
```

#### F. `show()` Method - Summary Queries (Line ~448)
**Purpose:** Employee/Company account detail page summaries

```php
$invoicesQuery = LedgerModel::where('to_account_id', $account->id)
    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED); // Exclude reversed transactions

$expensesQuery = LedgerModel::where('from_account_id', $account->id)
    ->where('transaction_type', LedgerModel::TYPE_EXPENSE)
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED); // Exclude reversed transactions
```

#### G. `show()` Method - Cash Invoices for Daily Closing (Line ~797)
**Purpose:** Calculate total cash invoices for daily closing reports

```php
// Cash Invoices: ALL cash/COD invoices delivered (from employee accounts)
// These go to rider accounts, not NF Cash directly
// Exclude reversed transactions (e.g., from payment method changes)
$cashInvoicesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('mode', LedgerModel::MODE_CASH)
    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
    ->whereHas('toAccount', function($q) {
        $q->where('account_category', 'employee_cash');
    });
```

### 2. `app/Http/Controllers/CRM/OrderController.php`

#### Bug Fix: Clear `ledger_transaction_id` Before Reposting (Line ~1879)

**Issue:** When changing payment method, the order still had the old `ledger_transaction_id`. The `LedgerPostingService` saw this and returned `['success' => true, 'message' => 'Already posted']` WITHOUT a `ledger_id` key, causing "Undefined array key 'ledger_id'" error.

**Fix:**
```php
// 1. Reverse the old ledger entry
$this->reverseLedgerEntry($oldLedger, "Payment method changed from '{$oldPaymentMethod}' to '{$newPaymentMethod}'");

// 2. Clear the old ledger_transaction_id so new one can be created
$order->ledger_transaction_id = null;
$order->save();

// 3. Temporarily update order payment method for posting
$order->payment_method = $newPaymentMethod;

// 4. Create new ledger entry with correct payment method
$ledgerService = new \App\Services\FIN\LedgerPostingService();
$result = $ledgerService->postInvoiceFromOrder($order);
```

## Testing Checklist

### Test 1: Outstanding Invoices Page
1. ✅ Navigate to `/finance/employee/outstanding-invoices`
2. ✅ Verify reversed invoices (from payment method changes) do NOT appear
3. ✅ Verify only legitimate open invoices are shown

### Test 2: Settle Invoices Modal
1. ✅ Go to employee cash page (e.g., Cash_EMP_KANAN)
2. ✅ Click "Settle" button
3. ✅ Verify reversed invoices are NOT in the outstanding invoices list
4. ✅ Select valid invoices and submit settlement
5. ✅ Verify settlement completes successfully

### Test 3: Payment Method Change
1. ✅ Create a cash order and mark it delivered
2. ✅ Change payment method from Cash to Online
3. ✅ Verify old ledger is marked "Reversed"
4. ✅ Verify new ledger is created with Online payment method
5. ✅ Verify reversed invoice does NOT appear in outstanding invoices
6. ✅ Verify new online invoice appears in Online Bank pending approvals

### Test 4: Daily Closing
1. ✅ Go to NF Cash account page
2. ✅ Check "Cash Invoices" KPI
3. ✅ Verify reversed invoices are NOT counted
4. ✅ Verify only active cash invoices are included

### Test 5: KPI Cards
1. ✅ Go to Finance Dashboard (`/finance/employee`)
2. ✅ Check "Invoices Delivered" card → Online sub-values
3. ✅ Verify reversed invoices are NOT counted in approved/pending totals

## Impact Assessment

### ✅ Fixed Issues
1. Reversed invoices no longer appear in outstanding invoices lists
2. Settlement operations can't accidentally include reversed invoices
3. KPI calculations are accurate (exclude reversed transactions)
4. Daily closing reports show correct cash invoice totals
5. Payment method change now works end-to-end without errors

### ⚠️ Potential Impacts
1. **Existing reversed transactions:** If there are any existing reversed transactions from testing, they will now be automatically excluded from all queries
2. **Reporting:** Historical reports that previously included reversed transactions will now exclude them (this is the correct behavior)

### 🔍 Areas to Monitor
1. **Settlement approvals:** Ensure managers can still approve/reject settlements normally
2. **Daily closing:** Verify daily closing calculations remain accurate
3. **Online payment approvals:** Ensure online invoices (from payment method changes) go through approval correctly

## Related Documentation

- `PAYMENT_METHOD_CHANGE_IMPLEMENTATION.md` - Original payment method change feature
- `PAYMENT_METHOD_CHANGE_ANALYSIS.md` - Initial analysis and design
- `add_reversed_status_to_ledger.sql` - Database migration for 'reversed' status
- `EMPLOYEE_CASH_REJECTED_SETTLEMENT_FIX_OCT25.md` - Related fix for rejected settlements

## Database Schema

### `t_fin_ledger.approval_status` ENUM Values
- `pending` - Awaiting approval
- `approved` - Approved and balances updated
- `rejected` - Rejected, balances NOT updated
- `reversed` - Reversed due to payment method change (balances reverted if was approved)

## Notes for Future Development

1. **Consistency:** Always exclude `reversed` transactions when querying for active/open invoices
2. **Pattern:** Use `->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)` in invoice queries
3. **Exception:** Only include reversed transactions in audit trails and transaction history (for transparency)
4. **Balance calculations:** Reversed transactions should NOT affect running balances (already handled in `show()` method)

---

**Fixed by:** AI Assistant  
**Date:** October 25, 2025  
**Tested by:** User (Taimur)  
**Status:** ✅ Deployed to Production

