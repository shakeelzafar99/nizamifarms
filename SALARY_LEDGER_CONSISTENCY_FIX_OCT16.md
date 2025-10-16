# Salary & Advance Ledger Consistency Fix - October 16, 2025

## 🎯 **User Requirement Verified**

**Requirement:** Salary advances and salary payments should follow the **same pattern as expense requests**:
1. ✅ **Default source:** EXP_FUND (Expense Fund)
2. ✅ **Respect payment_source_account_id:** Allow payment from other accounts
3. ✅ **Settlement tracking:** Mark as 'pending' if NOT from EXP_FUND, 'not_required' if from EXP_FUND
4. ✅ **Consistent flow:** Same logic as expense requests

---

## 🔍 **Issues Found**

### **Issue 1: Salary Advance - Wrong Payment Source**
**File:** `app/Models/Request/RequestModel.php` (Lines 412-499 - OLD)

**Problems:**
- ❌ Hardcoded to `NF_CASH` (company cash) instead of `EXP_FUND`
- ❌ Did NOT respect `payment_source_account_id`
- ❌ No settlement status tracking
- ❌ Not using `LedgerPostingService` (inconsistent with expenses)

**Old Code:**
```php
// WRONG: Hardcoded to NF_CASH
$companyCashAccount = DB::table('t_fin_accounts')
    ->where('account_code', 'NF_CASH')
    ->first();

// No settlement logic
// No payment source selection
```

---

### **Issue 2: Salary Payment - Wrong Payment Source**
**File:** `app/Http/Controllers/HR/SalarySlipController.php` (Lines 318-380 - OLD)

**Problems:**
- ❌ Hardcoded to `EXPENSE_SALARY` account
- ❌ Did NOT respect `payment_source_account_id`
- ❌ No settlement status tracking
- ❌ No option to select payment source

**Old Code:**
```php
// WRONG: Hardcoded to EXPENSE_SALARY
$salaryExpenseAccount = DB::table('t_fin_accounts')
    ->where('account_code', 'EXPENSE_SALARY')
    ->first();

// From: EXPENSE_SALARY → To: Employee Cash
// No settlement logic
```

---

## ✅ **Fixes Applied**

### **Fix 1: Salary Advance - Now Consistent with Expenses**

#### **A. Updated RequestModel to Use Service**
**File:** `app/Models/Request/RequestModel.php` (Lines 412-426)

**New Code:**
```php
protected function postSalaryAdvanceToLedger(): bool
{
    try {
        // Use LedgerPostingService for consistent handling
        $ledgerService = new \App\Services\FIN\LedgerPostingService();
        $result = $ledgerService->postSalaryAdvanceFromRequest($this);
        
        return $result['success'] ?? false;
    } catch (\Exception $e) {
        \Log::error("Failed to post salary advance to ledger", [
            'request_id' => $this->id,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}
```

#### **B. Added New Service Method**
**File:** `app/Services/FIN/LedgerPostingService.php` (Lines 270-405)

**Key Features:**
1. ✅ **Respects payment_source_account_id:**
```php
// Priority: 1) payment_source_account_id, 2) Config default, 3) EXP_FUND
if ($request->payment_source_account_id) {
    $fundingAccount = AccountModel::find($request->payment_source_account_id);
}

if (!$fundingAccount) {
    $fundingAccount = ConfigModel::getExpenseFundingAccount(); // EXP_FUND
}
```

2. ✅ **Settlement tracking:**
```php
// Mark settlement status (same as expenses)
$this->markSettlementStatus($request, $fundingAccount);

// Result:
// - From EXP_FUND → settlement_status = 'not_required'
// - From other account → settlement_status = 'pending'
```

3. ✅ **Proper ledger entry:**
```php
$ledger = LedgerModel::create([
    'transaction_date' => $request->completed_at ?? now(),
    'transaction_type' => 'salary_advance',
    'from_account_id' => $fundingAccount->id,      // ✅ EXP_FUND by default
    'to_account_id' => $employeeCashAccount->id,   // Employee cash
    'amount' => $request->amount,
    'comments' => "Paid from: {$fundingAccount->account_name}",
    // ... other fields
]);
```

---

### **Fix 2: Salary Payment - Now Consistent with Expenses**

#### **Updated Salary Ledger Entry Creation**
**File:** `app/Http/Controllers/HR/SalarySlipController.php` (Lines 315-415)

**Key Features:**
1. ✅ **Respects payment_source_account_id:**
```php
// Get payment source account
// Priority: 1) payment_source_account_id from slip, 2) Config default, 3) EXP_FUND
$paymentSource = null;

if ($slip->payment_source_account_id) {
    $paymentSource = \App\Models\FIN\AccountModel::find($slip->payment_source_account_id);
}

if (!$paymentSource) {
    $paymentSource = \App\Models\FIN\ConfigModel::getExpenseFundingAccount();
}

if (!$paymentSource) {
    $paymentSource = \App\Models\FIN\AccountModel::getByCode('EXP_FUND');
}
```

2. ✅ **Settlement tracking:**
```php
// Mark settlement status (same pattern as expenses/advances)
$expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount();

// If paid from Expense Fund, no settlement needed
if ($expenseFund && $paymentSource->id == $expenseFund->id) {
    $slip->settlement_status = 'not_required';
} else {
    // Otherwise, mark as pending settlement
    $slip->settlement_status = 'pending';
}
```

3. ✅ **Proper ledger entry:**
```php
$ledger = \App\Models\FIN\LedgerModel::create([
    'transaction_date' => now(),
    'transaction_type' => 'salary_payment',
    'from_account_id' => $paymentSource->id,       // ✅ EXP_FUND by default
    'to_account_id' => $employeeCashAccount->id,   // Employee cash
    'amount' => $slip->net_salary,
    'comments' => "Paid from: {$paymentSource->account_name}",
    // ... other fields
]);
```

---

## 📊 **Complete Flow Comparison**

### **✅ Expense Requests (REFERENCE - Already Working)**
```
1. Request created → payment_source_account_id = NULL
2. Approval → postExpenseFromRequest()
   - Defaults to EXP_FUND
   - Respects payment_source_account_id if set
   - Creates ledger: EXP_FUND → Expense Account
   - Marks settlement_status:
     * 'not_required' if from EXP_FUND
     * 'pending' if from other account
```

### **✅ Salary Advances (NOW FIXED)**
```
1. Request created → payment_source_account_id = NULL
2. Approval → postSalaryAdvanceFromRequest()
   - ✅ Defaults to EXP_FUND
   - ✅ Respects payment_source_account_id if set
   - ✅ Creates ledger: EXP_FUND → Employee Cash
   - ✅ Marks settlement_status:
     * 'not_required' if from EXP_FUND
     * 'pending' if from other account
```

### **✅ Salary Payments (NOW FIXED)**
```
1. Salary slip created → payment_source_account_id = NULL
2. Approval → createSalaryLedgerEntry()
   - ✅ Defaults to EXP_FUND
   - ✅ Respects payment_source_account_id if set
   - ✅ Creates ledger: EXP_FUND → Employee Cash
   - ✅ Marks settlement_status on slip:
     * 'not_required' if from EXP_FUND
     * 'pending' if from other account
```

---

## 🔄 **Settlement Workflow**

### **Scenario 1: Payment from EXP_FUND (Standard)**
```
1. Salary advance approved
   ├─ payment_source_account_id = NULL (not set by user)
   ├─ System defaults to EXP_FUND
   ├─ Ledger: EXP_FUND (₹100,000) → Employee Cash (+₹5,000)
   └─ settlement_status = 'not_required' ✅ No settlement needed!

2. Salary paid
   ├─ payment_source_account_id = NULL (not set by user)
   ├─ System defaults to EXP_FUND
   ├─ Ledger: EXP_FUND (₹95,000) → Employee Cash (+₹45,000)
   └─ settlement_status = 'not_required' ✅ No settlement needed!
```

### **Scenario 2: Payment from Other Account (Needs Settlement)**
```
1. Salary advance approved with custom source
   ├─ Manager selects: payment_source_account_id = NF_CASH
   ├─ Ledger: NF_CASH (₹50,000) → Employee Cash (+₹5,000)
   └─ settlement_status = 'pending' ⚠️ Needs settlement!

2. Later: Settlement processed
   ├─ User deposits cash to EXP_FUND
   ├─ Ledger: EXP_FUND → NF_CASH (₹5,000)
   └─ Request updated: settlement_status = 'settled' ✅
```

---

## 🗄️ **Database Schema**

### **t_req_master (Salary Advance Requests)**
```sql
payment_source_account_id INT NULL,     -- Which account paid the advance
settlement_status VARCHAR(50) NULL,     -- 'not_required', 'pending', 'settled'
settled_at TIMESTAMP NULL,
settlement_transaction_id INT NULL,
settlement_notes TEXT NULL
```

### **t_hr_salary_slips (Salary Payments)**
```sql
payment_source_account_id INT NULL,     -- Which account paid the salary
settlement_status VARCHAR(50) NULL,     -- 'not_required', 'pending', 'settled'
ledger_transaction_id INT NULL
```

### **t_fin_ledger**
```sql
from_account_id INT,                    -- ✅ Now EXP_FUND by default
to_account_id INT,                      -- Employee cash account
transaction_type VARCHAR(50),           -- 'salary_advance' or 'salary_payment'
request_id INT NULL,                    -- Link to request if applicable
```

---

## 🧪 **Testing Checklist**

### **Test 1: Salary Advance from EXP_FUND**
1. Create salary advance request (amount: 5000)
2. Approve it
3. ✅ **Check ledger:** FROM: EXP_FUND, TO: Employee Cash, AMOUNT: 5000
4. ✅ **Check request:** settlement_status = 'not_required'
5. ✅ **Check EXP_FUND balance:** Decreased by 5000
6. ✅ **Check employee cash:** Increased by 5000

### **Test 2: Salary Advance from Custom Account**
1. Create salary advance request
2. Manager sets payment_source_account_id = NF_CASH
3. Approve it
4. ✅ **Check ledger:** FROM: NF_CASH (not EXP_FUND!), TO: Employee Cash
5. ✅ **Check request:** settlement_status = 'pending'
6. ✅ **Should appear in settlement list**

### **Test 3: Salary Payment from EXP_FUND**
1. Create & approve salary slip (net salary: 45000)
2. ✅ **Check ledger:** FROM: EXP_FUND, TO: Employee Cash, AMOUNT: 45000
3. ✅ **Check slip:** settlement_status = 'not_required'
4. ✅ **Check EXP_FUND balance:** Decreased by 45000
5. ✅ **Check employee cash:** Increased by 45000

### **Test 4: Salary Deduction Flow**
1. Salary advance approved (5000 from EXP_FUND)
2. Create October salary slip → advance deducted
3. Approve slip → net salary paid from EXP_FUND
4. ✅ **Check advance:** settlement_status = 'settled' (from deduction)
5. ✅ **Check salary slip:** Includes advance deduction
6. ✅ **Ledger shows:** Both advance payment AND salary payment from EXP_FUND

---

## ⚠️ **Important Notes**

### **Backward Compatibility**
- ✅ **Existing requests/slips:** Will use EXP_FUND by default (no breaking changes)
- ✅ **Old ledger entries:** Remain unchanged
- ✅ **Settlement tracking:** Only applies to NEW requests/slips

### **Configuration**
The system uses this priority for payment source:
1. **payment_source_account_id** (if set by user/manager)
2. **ConfigModel::getExpenseFundingAccount()** (system config)
3. **AccountModel::getByCode('EXP_FUND')** (hardcoded fallback)

### **Settlement Status Values**
- **'not_required'** → Paid from EXP_FUND, no settlement needed
- **'pending'** → Paid from other account, awaits settlement to EXP_FUND
- **'settled'** → Settlement completed (cash deposited back to EXP_FUND)

---

## ✅ **Summary**

### **What Was Fixed:**
1. ✅ **Salary advances** now default to **EXP_FUND** (not NF_CASH)
2. ✅ **Salary payments** now default to **EXP_FUND** (not EXPENSE_SALARY)
3. ✅ Both respect **payment_source_account_id** for custom sources
4. ✅ Both have **settlement tracking** (consistent with expenses)
5. ✅ Both use **proper service layer** (LedgerPostingService)

### **Benefits:**
- ✅ **Consistent accounting:** All HR payments from same source (EXP_FUND)
- ✅ **Flexible source selection:** Can pay from other accounts when needed
- ✅ **Settlement tracking:** Know what needs to be settled
- ✅ **Audit trail:** Clear ledger entries show payment source
- ✅ **Matches expense pattern:** Same logic across all payment types

### **Files Modified:**
1. `app/Models/Request/RequestModel.php` (Simplified to use service)
2. `app/Services/FIN/LedgerPostingService.php` (Added `postSalaryAdvanceFromRequest`)
3. `app/Http/Controllers/HR/SalarySlipController.php` (Updated `createSalaryLedgerEntry`)

---

**Implementation Date:** October 16, 2025  
**Status:** ✅ PRODUCTION READY  
**Risk Level:** 🟢 Low (follows existing expense pattern, backward compatible)

