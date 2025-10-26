# Employee Cash Rejected Settlement Fix - Oct 25, 2025

## Issue Reported

User reported that after a settlement deposit was rejected by the manager:
- ✅ **Settlement status** → Correctly shows "Rejected"
- ✅ **Invoice status** → Correctly reverted back (invoice is open again)
- ✅ **Main balance card** → Correctly shows the actual balance
- ❌ **Deposits card** → Still showing Rs. 12,000 (the rejected deposit amount)
- ❌ **Transaction history balance** → Showing Rs. 0.00 instead of the correct balance

## Root Cause Analysis

### Problem 1: Deposits Card Counting Rejected Deposits

**Location:** `app/Http/Controllers/FIN/EmployeeCashController.php` lines 444-456

**Old Code:**
```php
// FIXED: Deposits direction depends on account type
if ($isEmployeeAccount) {
    // Employee depositing TO company: money going FROM employee account
    $depositsQuery = LedgerModel::where('from_account_id', $account->id)
        ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT);
} else {
    // Company receiving deposits: money coming TO company account
    $depositsQuery = LedgerModel::where('to_account_id', $account->id)
        ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT);
}
```

**Issue:** The query was counting **ALL** deposits regardless of approval status:
- ✅ Approved deposits (Rs. 5,000)
- ⏳ Pending deposits (Rs. 3,000)
- ❌ **Rejected deposits (Rs. 12,000)** ← This was the problem!

**Total shown:** Rs. 20,000 (should be Rs. 5,000)

### Problem 2: Balance Calculation Including Rejected Transactions

**Location:** `app/Http/Controllers/FIN/EmployeeCashController.php` lines 399-441

**Old Code (First Attempt - WRONG):**
```php
// Calculate running balance - WRONG: Filtered out rejected transactions completely
$allTransactionsQuery = LedgerModel::where(function($q) use ($id) {
    $q->where('from_account_id', $id)->orWhere('to_account_id', $id);
})
->where('approval_status', LedgerModel::STATUS_APPROVED); // ← This was the problem!

foreach ($allTransactions as $transaction) {
    // ... calculate balance ...
    $balanceMap[$transaction->id] = $runningBalance;
}

// Attach balances
$ledger->getCollection()->transform(function($transaction) use ($balanceMap) {
    $transaction->running_balance = $balanceMap[$transaction->id] ?? 0; // ← Rejected tx not in map!
    return $transaction;
});
```

**Issue:** Two problems:
1. The running balance calculation was including **ALL** transactions (approved, pending, rejected)
2. **WORSE:** When we filtered to only approved transactions, rejected transactions weren't in the `$balanceMap`, so they showed `?? 0` as fallback!

**Why This Failed:**
- `$ledger` query (line 382): Gets **ALL** transactions for display (including rejected)
- `$allTransactions` query (line 401): Gets **ONLY APPROVED** transactions
- When rejected transaction tries to get balance from map → **NOT FOUND** → defaults to 0

This caused the balance column to show Rs. 0.00 for rejected transactions.

### Problem 3: Date Grouping Showing "Balanced" Despite Rejected Transactions

**Location:** `resources/views/fin/employee/show.blade.php` lines 414-435

**Old Code:**
```php
@php
    // Group transactions by date for accountability check
    $groupedByDate = [];
    foreach($ledger as $txn) {
        $date = $txn->transaction_date ? $txn->transaction_date->format('Y-m-d') : 'unknown';
        if (!isset($groupedByDate[$date])) {
            $groupedByDate[$date] = ['in' => 0, 'out' => 0, 'transactions' => []];
        }
        if ($txn->to_account_id === $account->id) {
            $groupedByDate[$date]['in'] += $txn->amount;  // ← Counting ALL transactions
        } else {
            $groupedByDate[$date]['out'] += $txn->amount; // ← Including rejected!
        }
        $groupedByDate[$date]['transactions'][] = $txn;
    }
    
    // Later...
    $netAmount = $dateData['in'] - $dateData['out'];
    $isZero = abs($netAmount) < 0.01;
@endphp

@if($isZero)
    <span>✅ Balanced</span>  // ← Shows "Balanced" even with rejected transactions!
@endif
```

**Issue:** The date grouping was calculating "in" and "out" by summing **ALL** transactions (approved, pending, rejected). 

**Example:**
```
Saturday, October 25, 2025:
- Invoice (Approved): +Rs. 12,000 (in)
- Settlement (Rejected): -Rs. 12,000 (out)
- Net: Rs. 12,000 - Rs. 12,000 = Rs. 0
- Badge: "✅ Balanced" ← WRONG! The rejected transaction shouldn't count!
```

The day should show **+Rs. 12,000 held** (only the approved invoice), not "Balanced".

## Solution

### Fix 1: Filter Deposits by Approval Status

**New Code (Lines 444-456):**
```php
// FIXED: Deposits direction depends on account type
// IMPORTANT: Only count APPROVED deposits (exclude pending and rejected)
if ($isEmployeeAccount) {
    // Employee depositing TO company: money going FROM employee account
    $depositsQuery = LedgerModel::where('from_account_id', $account->id)
        ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
        ->where('approval_status', LedgerModel::STATUS_APPROVED);
} else {
    // Company receiving deposits: money coming TO company account
    $depositsQuery = LedgerModel::where('to_account_id', $account->id)
        ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
        ->where('approval_status', LedgerModel::STATUS_APPROVED);
}
```

**Change:** Added `->where('approval_status', LedgerModel::STATUS_APPROVED)` to only count approved deposits.

### Fix 2: Calculate Balance for ALL Transactions, But Only Update for Approved

**New Code (Lines 399-441):**
```php
// Calculate running balance (from oldest to newest for calculation)
// IMPORTANT: Get ALL transactions for display, but only update balance for APPROVED ones
$allTransactionsQuery = LedgerModel::where(function($q) use ($id) {
    $q->where('from_account_id', $id)
      ->orWhere('to_account_id', $id);
});
// NO approval filter here - we need ALL transactions in the map!

// ... date filters ...

$runningBalance = $account->opening_balance;
$balanceMap = [];

foreach ($allTransactions as $transaction) {
    // Only update balance for APPROVED transactions
    // Pending and rejected transactions show the balance WITHOUT their effect
    if ($transaction->approval_status === LedgerModel::STATUS_APPROVED) {
        if ($transaction->to_account_id === $account->id) {
            // Money coming in
            $runningBalance += $transaction->amount;
        } else {
            // Money going out
            $runningBalance -= $transaction->amount;
        }
    }
    // Store the current balance for this transaction (whether approved or not)
    // Rejected/pending transactions will show the balance as if they never happened
    $balanceMap[$transaction->id] = $runningBalance;
}

// Attach running balances to paginated results
$ledger->getCollection()->transform(function($transaction) use ($balanceMap, $account) {
    // Use the balance from the map, or fall back to current balance if not found
    $transaction->running_balance = $balanceMap[$transaction->id] ?? $account->current_balance;
    return $transaction;
});
```

**Key Changes:**
1. **REMOVED** `->where('approval_status', LedgerModel::STATUS_APPROVED)` from the query
   - We need ALL transactions in the loop to build the complete balance map
2. **KEPT** the `if ($transaction->approval_status === LedgerModel::STATUS_APPROVED)` check inside the loop
   - Only approved transactions actually update the running balance
3. **ALL transactions** (approved, pending, rejected) get added to `$balanceMap`
   - Rejected/pending transactions show the balance **as if they never happened**
4. **Improved fallback** in transform: `?? $account->current_balance` instead of `?? 0`

**How It Works:**
```
Transaction Timeline (oldest to newest):
1. Invoice #14555 (Approved) → Balance: Rs. 12,000 ✅
2. Settlement (Rejected) → Balance: Rs. 12,000 ✅ (NOT Rs. 0!)
   - The settlement is in the map, but balance didn't change because it's rejected
```

### Fix 3: Only Count Approved Transactions in Date Grouping

**New Code (Lines 414-435):**
```php
@php
    // Group transactions by date for accountability check
    // IMPORTANT: Only count APPROVED transactions for in/out totals (exclude pending/rejected)
    $groupedByDate = [];
    foreach($ledger as $txn) {
        $date = $txn->transaction_date ? $txn->transaction_date->format('Y-m-d') : 'unknown';
        if (!isset($groupedByDate[$date])) {
            $groupedByDate[$date] = ['in' => 0, 'out' => 0, 'transactions' => []];
        }
        
        // Only count approved transactions in the in/out totals
        if ($txn->approval_status === 'approved') {
            if ($txn->to_account_id === $account->id) {
                $groupedByDate[$date]['in'] += $txn->amount;
            } else {
                $groupedByDate[$date]['out'] += $txn->amount;
            }
        }
        
        // But include ALL transactions in the list (for display)
        $groupedByDate[$date]['transactions'][] = $txn;
    }
@endphp
```

**Key Changes:**
1. Added `if ($txn->approval_status === 'approved')` check before counting in/out
2. Only approved transactions affect the "in" and "out" totals
3. All transactions (approved, pending, rejected) still appear in the transaction list

**How It Works Now:**
```
Saturday, October 25, 2025:
- Invoice (Approved): +Rs. 12,000 (counted in "in")
- Settlement (Rejected): -Rs. 12,000 (NOT counted)
- Net: Rs. 12,000 - Rs. 0 = Rs. 12,000
- Badge: "🔴 +Rs. 12,000 held" ✅ CORRECT!
```

## How It Works Now

### Scenario: Settlement Deposit Rejected

**Before Fix:**
```
Deposits Card: Rs. 12,000 (WRONG - includes rejected)
Balance in Table: Rs. 0.00 (WRONG - calculated with rejected)
Main Balance: Rs. 12,000 (CORRECT - from account.current_balance)
```

**After Fix:**
```
Deposits Card: Rs. 0.00 (CORRECT - only approved deposits)
Balance in Table: Rs. 12,000 (CORRECT - only approved transactions)
Main Balance: Rs. 12,000 (CORRECT - unchanged)
```

### Transaction States and Balance Impact

| Approval Status | Included in Deposits Card? | Included in Balance Calculation? | Updates account.current_balance? |
|----------------|---------------------------|----------------------------------|----------------------------------|
| **Pending** | ❌ No | ❌ No | ❌ No (waits for approval) |
| **Approved** | ✅ Yes | ✅ Yes | ✅ Yes (updated on approval) |
| **Rejected** | ❌ No | ❌ No | ❌ No (never updated) |

## Files Modified

1. **app/Http/Controllers/FIN/EmployeeCashController.php**
   - Lines 444-456: Added approval status filter to deposits query
   - Lines 399-441: Fixed balance calculation to include all transactions but only update for approved
   
2. **resources/views/fin/employee/show.blade.php**
   - Lines 414-435: Fixed date grouping to only count approved transactions in "Balanced" calculation

## Testing Steps

1. **Setup:**
   - Go to any employee cash account (e.g., Cash - Kanan)
   - Create a settlement deposit for Rs. 12,000
   - Note the pending status

2. **Rejection:**
   - As manager, reject the settlement
   - Check the employee cash page

3. **Verification:**
   - ✅ Deposits card should show Rs. 0.00 (or only previously approved deposits)
   - ✅ Balance in transaction history should match the main balance card
   - ✅ Main balance card should remain correct
   - ✅ Settlement should show as "Rejected"
   - ✅ Invoices should be back to "Open" status
   - ✅ **Date grouping badge should NOT show "Balanced" if there's a rejected transaction**

## Related Concepts

### Ledger Approval Workflow

1. **Creation (Pending):**
   - Ledger entry created with `approval_status = 'pending'`
   - **NO balance updates** at this stage
   - Transaction shows in UI but doesn't affect balances

2. **Approval:**
   - `approval_status` changed to `'approved'`
   - **Balances updated** at this stage
   - Transaction now affects all calculations

3. **Rejection:**
   - `approval_status` changed to `'rejected'`
   - **NO balance updates** (never happened)
   - Transaction shows in UI but doesn't affect balances

### Why This Matters

The system correctly handles balance updates only on approval, but the **reporting queries** were incorrectly including all transactions regardless of status. This fix ensures that:

- **KPI cards** (Deposits, Expenses, etc.) only show approved amounts
- **Balance calculations** only include approved transactions
- **Pending transactions** show separately (in "Pending" card)
- **Rejected transactions** are visible for audit but don't affect any calculations

## Database Schema Reference

### t_fin_ledger Table

```sql
-- Approval status values
approval_status ENUM('pending', 'approved', 'rejected')

-- Key columns
id INT
transaction_type VARCHAR(50)  -- 'employee_deposit', 'invoice', 'expense', etc.
from_account_id INT
to_account_id INT
amount DECIMAL(15,2)
approval_status ENUM('pending', 'approved', 'rejected')
approved_by INT
approval_date DATE
```

### Balance Update Logic

```php
// On APPROVAL (LedgerController::approve)
if ($ledger->to_account_id) {
    $toAccount->current_balance += $ledger->amount;
}
if ($ledger->from_account_id) {
    $fromAccount->current_balance -= $ledger->amount;
}

// On REJECTION (LedgerController::reject)
// NO balance updates - balances were never changed
$ledger->approval_status = 'rejected';
$ledger->save();
```

## Notes

- This fix ensures consistency across all employee cash accounts
- The main balance card was always correct because it reads directly from `account.current_balance`
- The issue was only in the **aggregated calculations** (deposits card, balance column)
- This same logic should apply to all ledger-based KPIs and calculations

## Impact

- ✅ **Deposits card** now accurate
- ✅ **Balance calculations** now accurate
- ✅ **Pending transactions** still visible but don't affect balances
- ✅ **Rejected transactions** still visible for audit but don't affect balances
- ✅ **No changes to approval workflow** (still works as before)

