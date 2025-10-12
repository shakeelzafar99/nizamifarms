# 💰 Expense Settlement System - Implementation Plan

## 📋 Overview

This document outlines the implementation of the Expense Settlement feature, which handles scenarios where expenses are temporarily paid from non-Expense-Fund sources (like rider balance or NF Cash) and need to be reconciled later.

---

## 🎯 Problem Statement

**Scenario:**
```
Day 1: Rider has Rs. 4,400, deposits Rs. 4,000 to NF Main Till
       Rider Balance: Rs. 400
       NF Main Till: Rs. 4,000

Day 2: Rider spends Rs. 400 on petrol (approved expense)
       Rider Balance: Rs. 0 ✅
       NF Main Till: Rs. 4,000 (but SHOULD be Rs. 4,400) ❌

Issue: The Rs. 400 was company cash that should have been deposited
       Need to "settle" this by transferring from Expense Fund → NF Main Till
```

---

## ✅ Solution: Settlement as Separate Transaction

### Key Principles:
1. **DO NOT modify original expense ledger** (preserve audit trail)
2. **DO NOT touch rider balance** (already correct at Rs. 0)
3. **CREATE new settlement ledger transaction** (Expense Fund → Destination)
4. **TRACK settlement status** in request record (for filtering)
5. **UPDATE UI calculations** to respect settlement status

---

## 🗄️ Database Changes

### File: `database/migrations/add_expense_settlement_support.sql`

#### New Columns in `t_req_master`:

| Column Name | Type | Purpose | Default |
|-------------|------|---------|---------|
| `settlement_status` | ENUM('not_required', 'pending', 'settled') | Tracks if/when settlement needed | 'not_required' |
| `settled_at` | TIMESTAMP NULL | When settlement was completed | NULL |
| `settled_by` | INT NULL | User who performed settlement | NULL |
| `settlement_transaction_id` | BIGINT UNSIGNED NULL | FK to settlement ledger entry | NULL |
| `settlement_destination_account_id` | INT NULL | Where settlement money went | NULL |
| `settlement_notes` | TEXT NULL | Notes added during settlement | NULL |

#### Foreign Keys Added:
- `settled_by` → `t_sys_user(id)` ON DELETE SET NULL
- `settlement_transaction_id` → `t_fin_ledger(id)` ON DELETE SET NULL
- `settlement_destination_account_id` → `t_fin_accounts(id)` ON DELETE SET NULL

#### Safety Features:
- ✅ Checks if columns already exist before adding
- ✅ Checks if FKs already exist before adding
- ✅ Uses prepared statements for dynamic SQL
- ✅ Verifies structure before and after changes
- ✅ Provides clear error messages if prerequisites missing

---

## 💻 Code Changes Required

### 1. Update `RequestModel` ($fillable array)

**File:** `app/Models/Request/RequestModel.php`  
**Line:** ~20-46

```php
protected $fillable = [
    'request_number',
    'category_id',
    'requester_user_id',
    'title',
    'description',
    'amount',
    'expense_category',
    'payment_source_account_id',
    // ... existing fields ...
    'ledger_transaction_id',
    
    // NEW: Settlement fields
    'settlement_status',
    'settled_at',
    'settled_by',
    'settlement_transaction_id',
    'settlement_destination_account_id',
    'settlement_notes',
    
    'created_by',
    'updated_by'
];
```

### 2. Add Relationships to `RequestModel`

```php
// After paymentSourceAccount() relationship (line ~97-100)

public function settledBy(): BelongsTo
{
    return $this->belongsTo(UserModel::class, 'settled_by', 'id');
}

public function settlementTransaction(): BelongsTo
{
    return $this->belongsTo(\App\Models\FIN\LedgerModel::class, 'settlement_transaction_id', 'id');
}

public function settlementDestinationAccount(): BelongsTo
{
    return $this->belongsTo(\App\Models\FIN\AccountModel::class, 'settlement_destination_account_id', 'id');
}
```

### 3. Update `LedgerModel` Constants

**File:** `app/Models/FIN/LedgerModel.php`  
**Line:** ~49-61

```php
// Transaction type constants
const TYPE_INVOICE = 'invoice';
const TYPE_EXPENSE = 'expense';
const TYPE_VENDOR_PURCHASE = 'vendor_purchase';
const TYPE_VENDOR_PAYMENT = 'vendor_payment';
const TYPE_EMPLOYEE_DEPOSIT = 'employee_deposit';
const TYPE_REIMBURSEMENT_ACCRUAL = 'reimbursement_accrual';
const TYPE_REIMBURSEMENT_PAYMENT = 'reimbursement_payment';
const TYPE_SALARY_ADVANCE = 'salary_advance';
const TYPE_TRANSFER = 'transfer';
const TYPE_ADJUSTMENT = 'adjustment';
const TYPE_OPENING_BALANCE = 'opening_balance';
const TYPE_SETTLEMENT = 'expense_settlement'; // NEW!
```

### 4. Update `EmployeeCashController` KPI Calculation

**File:** `app/Http/Controllers/FIN/EmployeeCashController.php`  
**Line:** ~181-204

```php
// Expenses paid FROM THIS rider's own balance (affects his balance)
// EXCLUDE settled expenses from this card
$paidFromRiderBalance = $expenseRequests->whereNotNull('ledger_transaction_id')
    ->where(function($q) {
        $q->where('settlement_status', '!=', 'settled')
          ->orWhereNull('settlement_status');
    })
    ->filter(function($req) use ($account) {
        if ($req->payment_source_account_id) {
            $paymentAccount = \App\Models\FIN\AccountModel::find($req->payment_source_account_id);
            return $paymentAccount && $paymentAccount->id === $account->id;
        }
        return false;
    })->sum('amount');

// All other approved expenses (does NOT affect rider's balance)
// INCLUDE settled expenses in this card (now company-funded)
$paidFromOtherSources = $expenseRequests->whereNotNull('ledger_transaction_id')
    ->filter(function($req) use ($account) {
        // If settled, it's now company-funded
        if ($req->settlement_status === 'settled') {
            return true;
        }
        
        // If no payment source set, assume company (NOT rider)
        if (!$req->payment_source_account_id) {
            return true;
        }
        
        // Otherwise, exclude only THIS rider's balance
        $paymentAccount = \App\Models\FIN\AccountModel::find($req->payment_source_account_id);
        return $paymentAccount && $paymentAccount->id !== $account->id;
    })->sum('amount');
```

---

## 🔧 Settlement Service Logic

### File: `app/Services/FIN/ExpenseSettlementService.php` (NEW)

```php
<?php

namespace App\Services\FIN;

use App\Models\Request\RequestModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseSettlementService
{
    /**
     * Settle an expense by creating bridge transaction
     */
    public function settleExpense(
        RequestModel $expenseRequest, 
        ?int $settlementSourceAccountId = null, 
        ?string $notes = null
    ): array {
        DB::beginTransaction();
        
        try {
            // Validation
            if ($expenseRequest->settlement_status === 'settled') {
                throw new \Exception("Expense is already settled");
            }
            
            if (!$expenseRequest->ledger_transaction_id) {
                throw new \Exception("Expense has not been posted to ledger");
            }
            
            // 1. Get settlement source (usually Expense Fund)
            $expenseFund = $settlementSourceAccountId 
                ? AccountModel::findOrFail($settlementSourceAccountId)
                : ConfigModel::getExpenseFundingAccount();
            
            if (!$expenseFund) {
                throw new \Exception("Settlement source account not found");
            }
            
            // 2. Determine destination (where cash was supposed to go)
            $settlementDestination = $this->determineSettlementDestination($expenseRequest);
            
            if (!$settlementDestination) {
                throw new \Exception("Settlement destination not found");
            }
            
            // 3. Check if expense fund has sufficient balance
            if ($expenseFund->current_balance < $expenseRequest->amount) {
                throw new \Exception("Insufficient balance in {$expenseFund->account_name}. Current: Rs. " . number_format($expenseFund->current_balance, 2));
            }
            
            // 4. Create SETTLEMENT ledger transaction
            // This is a NEW transaction that "bridges the gap"
            $settlementLedger = LedgerModel::create([
                'transaction_date' => now(),
                'transaction_type' => LedgerModel::TYPE_SETTLEMENT,
                'description' => "Settlement for Expense #{$expenseRequest->request_number}" 
                                . ($expenseRequest->expense_category ? " ({$expenseRequest->expense_category})" : ''),
                'from_account_id' => $expenseFund->id,
                'to_account_id' => $settlementDestination->id,
                'amount' => $expenseRequest->amount,
                'mode' => LedgerModel::MODE_INTERNAL,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approval_date' => now(),
                'approved_by' => auth()->id(),
                'reference_type' => 'expense_request',
                'reference_id' => $expenseRequest->id,
                'comments' => "Settlement: Replenishing {$settlementDestination->account_name} for expense originally paid from {$expenseRequest->paymentSourceAccount->account_name}. " 
                             . ($notes ?? ''),
                'created_by' => auth()->id()
            ]);
            
            // 5. Update balances
            $expenseFund->current_balance -= $expenseRequest->amount;
            $expenseFund->save();
            
            $settlementDestination->current_balance += $expenseRequest->amount;
            $settlementDestination->save();
            
            // 6. Update expense request metadata
            $expenseRequest->settlement_status = 'settled';
            $expenseRequest->settled_at = now();
            $expenseRequest->settled_by = auth()->id();
            $expenseRequest->settlement_transaction_id = $settlementLedger->id;
            $expenseRequest->settlement_destination_account_id = $settlementDestination->id;
            $expenseRequest->settlement_notes = $notes;
            
            // IMPORTANT: DO NOT change payment_source_account_id!
            // Keep original for audit trail
            
            $expenseRequest->save();
            
            DB::commit();
            
            Log::info("Expense settled successfully", [
                'request_id' => $expenseRequest->id,
                'settlement_ledger_id' => $settlementLedger->id,
                'amount' => $expenseRequest->amount,
                'destination' => $settlementDestination->account_name
            ]);
            
            return [
                'success' => true,
                'message' => "Settlement completed: Rs. " . number_format($expenseRequest->amount, 2) . " transferred to {$settlementDestination->account_name}",
                'settlement_ledger_id' => $settlementLedger->id,
                'destination_account' => $settlementDestination->account_name
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Expense settlement failed", [
                'request_id' => $expenseRequest->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Settlement failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Determine where settlement money should go
     * Rule: Find most recent deposit destination for this employee
     */
    private function determineSettlementDestination(RequestModel $expenseRequest): ?AccountModel
    {
        $riderCashAccount = AccountModel::getEmployeeCashAccount($expenseRequest->requester_user_id);
        
        if (!$riderCashAccount) {
            return ConfigModel::getAccountByCode('CASH_NF_MAIN_TILL');
        }
        
        // Find most recent deposit from this rider before the expense
        $recentDeposit = LedgerModel::where('from_account_id', $riderCashAccount->id)
            ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
            ->where('transaction_date', '<=', $expenseRequest->created_at)
            ->orderBy('transaction_date', 'desc')
            ->first();
        
        if ($recentDeposit) {
            return AccountModel::find($recentDeposit->to_account_id);
        }
        
        // Default: NF Main Till
        return ConfigModel::getAccountByCode('CASH_NF_MAIN_TILL')
            ?? ConfigModel::getAccountByCode('NF_CASH');
    }
    
    /**
     * Get list of expenses needing settlement
     */
    public function getExpensesNeedingSettlement($dateFrom = null, $dateTo = null)
    {
        $query = RequestModel::whereNotNull('ledger_transaction_id')
            ->where('settlement_status', 'pending')
            ->with(['requester', 'paymentSourceAccount', 'category']);
        
        if ($dateFrom && $dateTo) {
            $query->whereBetween('created_at', [$dateFrom, $dateTo]);
        }
        
        return $query->orderBy('created_at', 'asc')->get();
    }
}
```

---

## 📊 UI Impact

### Employee Cash Page

**BEFORE Settlement:**
```
💸 Expense from Rider Balance: Rs. 400
💰 Expense Amount: Rs. 0
💰 Balance: Rs. 0
```

**AFTER Settlement:**
```
💸 Expense from Rider Balance: Rs. 0  ← Moved!
💰 Expense Amount: Rs. 400            ← Now here!
💰 Balance: Rs. 0                     ← UNCHANGED!
```

### Expense Management Page (NEW)

Shows expenses needing settlement with ability to:
- Filter by date range, category, payment source
- Bulk select and settle multiple expenses
- View settlement history
- Track who settled what and when

---

## ✅ Verification Steps

After running the SQL:

1. **Check columns added:**
   ```sql
   DESCRIBE t_req_master;
   ```

2. **Check foreign keys:**
   ```sql
   SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
   WHERE TABLE_NAME = 't_req_master' 
   AND CONSTRAINT_NAME LIKE 'fk_req_master_settle%';
   ```

3. **Test settlement_status values:**
   ```sql
   SELECT DISTINCT settlement_status FROM t_req_master;
   ```

---

## 🚨 Important Notes

### What This SQL Does:
- ✅ Adds 6 new columns to `t_req_master`
- ✅ Adds 3 foreign keys for data integrity
- ✅ Checks for existing columns/FKs before adding (safe to re-run)
- ✅ Provides verification queries at the end

### What This SQL Does NOT Do:
- ❌ Does NOT modify existing data
- ❌ Does NOT affect existing expense records
- ❌ Does NOT require downtime
- ❌ Does NOT modify `t_fin_ledger` table (transaction type added via code)

### Prerequisites:
1. ✅ `payment_source_account_id` must exist in `t_req_master`
   - Run `database/migrations/add_payment_source_to_requests.sql` first if missing
2. ✅ `ledger_transaction_id` must exist in `t_req_master`
   - Already added by finance ledger system installation

### Dependencies Check:
The SQL will verify these exist:
- `t_req_master` table
- `t_sys_user` table (for settled_by FK)
- `t_fin_ledger` table (for settlement_transaction_id FK)
- `t_fin_accounts` table (for settlement_destination_account_id FK)

---

## 📝 Next Steps After Running SQL

1. ✅ Update `RequestModel.php` $fillable array
2. ✅ Add relationships to `RequestModel.php`
3. ✅ Update `LedgerModel.php` TYPE constants
4. ✅ Update `EmployeeCashController.php` KPI calculations
5. ✅ Create `ExpenseSettlementService.php`
6. ✅ Create `ExpenseManagementController.php`
7. ✅ Build Expense Management UI page
8. ✅ Test settlement flow end-to-end

---

**Status:** ✅ SQL Ready to Run  
**Risk Level:** 🟢 Low (safe, reversible, non-destructive)  
**Estimated Time:** < 1 second to execute



