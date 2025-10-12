# Expense Fund Settlement System - Analysis & Design

## 📊 **Current System Overview:**

### **Data Flow for Expense Requests:**

```
1. Employee creates Expense Request
   ↓
2. Manager/Admin approves
   ↓
3. LedgerPostingService.postExpenseFromRequest() called
   ↓
4. Ledger Entry Created:
   - FROM: payment_source_account_id (Expense Fund, NF Cash, Employee Cash, etc.)
   - TO: Expense Account (EXP_PETROL, EXP_RENT, etc.)
   - Links: request_id → ledger_transaction_id
```

### **Database Columns Used:**

#### **t_req_master:**
- `id` - Request ID
- `request_number` - REQ-202510-0007
- `requester_user_id` - Employee who made request
- `amount` - Rs. 350.00
- `expense_category` - "Petrol", "Rent", etc.
- **`payment_source_account_id`** - FK to `t_fin_accounts.id` (which account paid)
- **`ledger_transaction_id`** - FK to `t_fin_ledger.id` (the payment record)
- `status` - pending/approved/rejected

#### **t_fin_ledger:**
- `id` - Ledger ID
- `from_account_id` - Payment source (Expense Fund, NF Cash, etc.)
- `to_account_id` - Expense account (EXP_PETROL, etc.)
- `amount` - Rs. 350.00
- **`request_id`** - Links back to t_req_master

#### **t_fin_accounts:**
- `id` - Account ID
- `account_code` - 'EXP_FUND', 'NF_CASH', 'CASH_EMP_WASEEM', etc.
- `account_name` - "Expense Fund", "NF Cash (Main Till)", etc.
- `current_balance` - Current balance

---

## 🎯 **Proposed Expense Fund Settlement System:**

### **Business Logic:**

1. **Expense Fund is a "float"** - temporary cash given to employees
2. **When employees spend from Expense Fund:**
   - Expense Fund balance decreases
   - NF Cash balance remains same (hasn't been replenished yet)
3. **Settlement Process:**
   - Manager reviews all Expense Fund transactions
   - Selects which ones to settle
   - System transfers: NF Cash → Expense Fund
   - This "refills" the Expense Fund

### **Card Display Logic:**

```
🏢 Company Cash (Rs. 350)
- Shows: Expenses paid FROM company accounts (NF Cash, Online, etc.)
- Excludes: Expense Fund (tracked separately)
- Query: payment_source_account_id IN ('NF_CASH', 'ONLINE', 'CASH_NF_MAIN_TILL')

💰 Expenses (Rs. 1,500)
- Shows: Total paid expenses EXCLUDING company cash
- Includes: Expense Fund, Employee Cash
- Query: ledger_transaction_id IS NOT NULL 
         AND payment_source_account_id NOT IN ('NF_CASH', 'ONLINE', 'CASH_NF_MAIN_TILL')
```

---

## 🛠️ **Implementation Plan:**

### **Phase 1: Card Layout (Current Task)**

Update cards to show:
```
LEFT SIDE:                        RIGHT SIDE:
┌──────────┐ ┌──────────┐        ┌──────────┐ ┌──────────┐ ┌──────────┐
│ Invoices │ │ Deposits │        │ Company  │ │ Pending  │ │ Expenses │
└──────────┘ └──────────┘        │   Cash   │ └──────────┘ └──────────┘
            ┌──────────┐          └──────────┘               (Rs. 1,500)
            │ Balance  │          (Rs. 350)                  Excludes
            └──────────┘          From NF/Online             Company Cash
```

**Controller Changes:**
```php
// Current implementation in EmployeeCashController.php:
$expenseSummary = [
    'pending' => $expenseRequests->where('status', 'pending')->sum('amount'),
    'total_approved' => $expenseRequests->where('status', 'approved')->sum('amount'),
    'paid' => $expenseRequests->whereNotNull('ledger_transaction_id')->sum('amount'),
    'paid_from_company' => $expenseRequests->whereNotNull('ledger_transaction_id')
        ->filter(function($req) {
            if ($req->payment_source_account_id) {
                $account = AccountModel::find($req->payment_source_account_id);
                return $account && in_array($account->account_code, ['EXP_FUND', 'NF_CASH', 'ONLINE', 'CASH_NF_MAIN_TILL']);
            }
            return false;
        })->sum('amount'),
    'paid_from_employee' => $expenseRequests->whereNotNull('ledger_transaction_id')
        ->filter(function($req) {
            if ($req->payment_source_account_id) {
                $account = AccountModel::find($req->payment_source_account_id);
                return $account && $account->account_category === 'employee_cash';
            }
            return false;
        })->sum('amount')
];
```

**Change to:**
```php
// NEW: Split company cash into NF/Online vs Expense Fund
$paidFromDirectCompany = $expenseRequests->whereNotNull('ledger_transaction_id')
    ->filter(function($req) {
        if ($req->payment_source_account_id) {
            $account = AccountModel::find($req->payment_source_account_id);
            // Only NF Cash and Online (NOT Expense Fund)
            return $account && in_array($account->account_code, ['NF_CASH', 'ONLINE', 'CASH_NF_MAIN_TILL']);
        }
        return false;
    })->sum('amount');

$paidFromExpenseFundOrEmployee = $expenseRequests->whereNotNull('ledger_transaction_id')
    ->filter(function($req) {
        if ($req->payment_source_account_id) {
            $account = AccountModel::find($req->payment_source_account_id);
            // Expense Fund OR Employee Cash
            return $account && ($account->account_code === 'EXP_FUND' || $account->account_category === 'employee_cash');
        }
        return false;
    })->sum('amount');

$expenseSummary = [
    'pending' => $expenseRequests->where('status', 'pending')->sum('amount'),
    'paid_from_direct_company' => $paidFromDirectCompany, // Company Cash card
    'paid_from_expense_fund_or_employee' => $paidFromExpenseFundOrEmployee, // Expenses card
];
```

---

### **Phase 2: Expense Fund Settlement Screen (Future)**

**New Controller:** `app/Http/Controllers/FIN/ExpenseFundSettlementController.php`

**Route:** `/finance/expense-fund-settlement`

**Features:**
1. List all expenses paid from Expense Fund (unsettled)
2. Checkbox for each transaction
3. "Bulk Select" option
4. "Settle Selected" button

**Logic:**
```php
public function index()
{
    // Get all expense requests paid from Expense Fund that haven't been settled
    $unsettledExpenses = RequestModel::with(['paymentSourceAccount', 'requester'])
        ->whereNotNull('ledger_transaction_id')
        ->whereHas('paymentSourceAccount', function($q) {
            $q->where('account_code', 'EXP_FUND');
        })
        ->whereDoesntHave('settlementTransaction') // New relationship
        ->get();
    
    return view('fin.expense-fund-settlement.index', compact('unsettledExpenses'));
}

public function settle(Request $request)
{
    $requestIds = $request->input('request_ids'); // Array of selected requests
    
    DB::beginTransaction();
    
    foreach ($requestIds as $requestId) {
        $expenseRequest = RequestModel::with('paymentSourceAccount')->findOrFail($requestId);
        
        // Create settlement ledger entry: NF Cash → Expense Fund
        $settlementLedger = LedgerModel::create([
            'transaction_date' => now(),
            'transaction_type' => 'expense_fund_settlement',
            'description' => "Settlement for Expense Request #{$expenseRequest->request_number}",
            'from_account_id' => ConfigModel::getNFCashAccount()->id, // NF Cash
            'to_account_id' => $expenseRequest->payment_source_account_id, // Expense Fund
            'amount' => $expenseRequest->amount,
            'mode' => LedgerModel::MODE_CASH,
            'approval_status' => LedgerModel::STATUS_APPROVED,
            'created_by' => auth()->id()
        ]);
        
        // Update balances
        $nfCash = ConfigModel::getNFCashAccount();
        $nfCash->current_balance -= $expenseRequest->amount;
        $nfCash->save();
        
        $expenseFund = AccountModel::find($expenseRequest->payment_source_account_id);
        $expenseFund->current_balance += $expenseRequest->amount;
        $expenseFund->save();
        
        // Mark as settled (new column needed)
        $expenseRequest->settlement_ledger_id = $settlementLedger->id;
        $expenseRequest->save();
    }
    
    DB::commit();
    
    return redirect()->route('fin.expense-fund-settlement.index')
                   ->with('success', 'Settlement completed successfully!');
}
```

**Database Changes Needed:**
```sql
-- Add settlement tracking to requests
ALTER TABLE t_req_master 
ADD COLUMN settlement_ledger_id INT NULL COMMENT 'FK to t_fin_ledger for settlement transaction',
ADD INDEX idx_settlement_ledger_id (settlement_ledger_id);

-- Add new transaction type to ledger
-- (Already flexible as VARCHAR(50), no schema change needed)
```

**New Relationship in RequestModel:**
```php
public function settlementTransaction(): BelongsTo
{
    return $this->belongsTo(LedgerModel::class, 'settlement_ledger_id', 'id');
}
```

---

## 📋 **Implementation Checklist:**

### **Phase 1: Card Layout** ✅ (Current)
- [ ] Update card order (Invoices, Deposits, Balance on left)
- [ ] Rename "Reimbursed" to "Expenses"
- [ ] Split company cash: NF/Online vs Expense Fund
- [ ] Update controller to calculate new KPIs
- [ ] Update view to display new cards

### **Phase 2: Settlement System** 🔜 (Future)
- [ ] Add `settlement_ledger_id` column to `t_req_master`
- [ ] Create `ExpenseFundSettlementController`
- [ ] Create view `/finance/expense-fund-settlement/index.blade.php`
- [ ] Add route to `routes/web.php`
- [ ] Add menu item to Finance sidebar
- [ ] Add relationship to `RequestModel`
- [ ] Add scope for unsettled expenses
- [ ] Test settlement flow

---

## ✅ **Benefits:**

1. **Clear Separation:** Company Cash (direct) vs Expenses (via Expense Fund/Employee)
2. **Trackable Settlement:** See which Expense Fund transactions need settling
3. **No Duplication:** Uses existing `payment_source_account_id` and `ledger_transaction_id`
4. **Audit Trail:** Settlement creates its own ledger entry linking back to original request
5. **Seamless Display:** Once settled, employee page shows "paid from Expense Fund" (no change)

---

**Ready to implement Phase 1 now!** 🚀

