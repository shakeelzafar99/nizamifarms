# Employee Cash Dashboard Cards Enhancement Plan

## 📋 Current State Analysis

### Existing Cards (5 cards):
1. **Invoices** - Total invoices delivered (Cash/Online split from orders)
2. **Deposits** - Deposits to NF Cash
3. **All Expenses** - Approved expenses (Settlement/In Fund split)
4. **Online** - Online payments (Approved/Pending split)
5. **With Riders** - Riders balance (real-time) + Open invoices count

### Current Data Sources:
- **Invoices**: From `t_crm_order_status_history` + `t_crm_prod_order` (by delivery date)
- **Deposits**: From `t_fin_ledger` (TYPE_EMPLOYEE_DEPOSIT to NF_CASH)
- **Expenses**: From `t_req_master` (approved expense requests)
- **Online**: From `t_fin_ledger` (TYPE_INVOICE to ONLINE account)
- **Riders Balance**: From `t_fin_accounts` (employee_cash category, real-time)

---

## 🎯 New Requirements

### New Card Structure (5 cards):

#### **Card 1: INVOICES** ✅ (Enhanced)
**Main Value**: Total invoices delivered (by delivery date)
**Sub-values**:
- **Cash** split into:
  - Deposits (cash deposited to NF Cash)
  - Short Cash (settled short cash - already counted as deposits)
- **Online** split into:
  - Approved (online payments approved)
  - Pending (online payments pending approval)

#### **Card 2: EXPENSES** ⚠️ (Modified)
**Main Value**: ALL expenses from ANY account (not just cash)
**Sub-values**:
- Expenses from EXP_FUND
- Expenses needing settlement
**Exclusion**: Vendor payments NOT included

#### **Card 3: VENDOR PAYMENTS** 🆕 (New)
**Main Value**: Total vendor-related transactions
**Sub-values**:
- Vendor Purchases (TYPE_VENDOR_PURCHASE)
- Vendor Payments (TYPE_VENDOR_PAYMENT)

#### **Card 4: APPROVALS & RIDERS** 🆕 (New)
**Main Value**: Riders Balance (real-time)
**Sub-values**:
- Pending Deposits (cash in - pending approval)
- Pending Expenses (cash out - pending approval)

#### **Card 5: NF BALANCE (PROFIT)** 🆕 (New)
**Main Value**: Profit = Invoices - Expenses - Vendor Purchases
**Sub-values**:
- Total Invoices Delivered
- Total Expenses
- Total Vendor Purchases

---

## 🔍 Detailed Implementation Plan

### Card 1: INVOICES (Enhanced)

**Current Logic**: ✅ Already correct
- Uses delivery date from `t_crm_order_status_history`
- Splits by payment method from orders

**New Logic**:
```php
// Main Value: Total Invoices (same as current)
$totalInvoices = delivered orders sum(total_price)

// Cash Sub-values:
$cashInvoices = delivered orders where payment_method IN ('cash', 'COD') sum(total_price)

// Deposits = Cash deposited to NF Cash (from ledger)
$depositsToNFCash = LedgerModel::where('to_account_id', NF_CASH)
    ->where('transaction_type', TYPE_EMPLOYEE_DEPOSIT)
    ->where('approval_status', APPROVED)
    ->sum('amount')

// Short Cash (already settled) = Included in deposits above
// (When short cash is settled, it creates a deposit transaction)

// Online Sub-values:
$onlineApproved = LedgerModel::where('to_account_id', ONLINE)
    ->where('transaction_type', TYPE_INVOICE)
    ->where('approval_status', APPROVED)
    ->sum('amount')

$onlinePending = LedgerModel::where('to_account_id', ONLINE)
    ->where('transaction_type', TYPE_INVOICE)
    ->where('approval_status', PENDING)
    ->sum('amount')
```

**Filters Applied**: ✅ Date filters on delivery date and transaction_date

---

### Card 2: EXPENSES (Modified)

**Current Logic**: Shows approved expense requests
**Issue**: Only shows expense requests, not actual ledger expenses

**New Logic**:
```php
// Main Value: ALL expenses from ledger (any account)
$totalExpenses = LedgerModel::where('transaction_type', TYPE_EXPENSE)
    ->where('approval_status', APPROVED)
    ->sum('amount')

// Sub-value 1: Expenses from EXP_FUND
$expensesFromFund = LedgerModel::where('transaction_type', TYPE_EXPENSE)
    ->where('from_account_id', EXP_FUND_ID)
    ->where('approval_status', APPROVED)
    ->sum('amount')

// Sub-value 2: Expenses needing settlement
// These are approved expense requests with settlement_status = 'pending'
$expensesNeedingSettlement = RequestModel::where('status', 'approved')
    ->where('settlement_status', 'pending')
    ->whereHas('category', fn($q) => $q->where('category_code', 'expense'))
    ->sum('amount')

// EXCLUDE: Vendor payments (TYPE_VENDOR_PAYMENT) - not counted as expenses
```

**Filters Applied**: ✅ Date filters on transaction_date and created_at

---

### Card 3: VENDOR PAYMENTS (New)

**Data Source**: `t_fin_ledger`

**Logic**:
```php
// Main Value: Total vendor transactions
$totalVendorTransactions = $vendorPurchases + $vendorPayments

// Sub-value 1: Vendor Purchases
$vendorPurchases = LedgerModel::where('transaction_type', TYPE_VENDOR_PURCHASE)
    ->where('approval_status', APPROVED)
    ->sum('amount')

// Sub-value 2: Vendor Payments
$vendorPayments = LedgerModel::where('transaction_type', TYPE_VENDOR_PAYMENT)
    ->where('approval_status', APPROVED)
    ->sum('amount')
```

**Filters Applied**: ✅ Date filters on transaction_date

---

### Card 4: APPROVALS & RIDERS (New)

**Data Sources**: 
- `t_fin_accounts` (riders balance - real-time)
- `t_fin_ledger` (pending deposits)
- `t_req_master` (pending expense requests)

**Logic**:
```php
// Main Value: Riders Balance (Real-time, NO FILTER)
$ridersBalance = AccountModel::where('account_category', 'employee_cash')
    ->where('is_active', 1)
    ->sum('current_balance')

// Sub-value 1: Pending Deposits (Cash IN)
$pendingDeposits = LedgerModel::where('transaction_type', TYPE_EMPLOYEE_DEPOSIT)
    ->where('approval_status', PENDING)
    ->sum('amount')

// Sub-value 2: Pending Expenses (Cash OUT)
$pendingExpenses = RequestModel::where('status', 'pending')
    ->whereHas('category', fn($q) => $q->where('category_code', 'expense'))
    ->sum('amount')
```

**Filters Applied**: 
- ❌ Riders Balance: NO FILTER (real-time)
- ✅ Pending Deposits: Date filter on transaction_date
- ✅ Pending Expenses: Date filter on created_at

---

### Card 5: NF BALANCE / PROFIT (New)

**Calculation**: Profit = Revenue - Costs

**Logic**:
```php
// Get delivered invoices (revenue)
$totalInvoicesDelivered = [same as Card 1]

// Get all expenses
$totalExpenses = LedgerModel::where('transaction_type', TYPE_EXPENSE)
    ->where('approval_status', APPROVED)
    ->sum('amount')

// Get vendor purchases
$totalVendorPurchases = LedgerModel::where('transaction_type', TYPE_VENDOR_PURCHASE)
    ->where('approval_status', APPROVED)
    ->sum('amount')

// Main Value: Profit
$profit = $totalInvoicesDelivered - $totalExpenses - $totalVendorPurchases

// Sub-values (for breakdown):
// 1. Total Invoices Delivered
// 2. Total Expenses
// 3. Total Vendor Purchases
```

**Filters Applied**: ✅ Date filters on all components

---

## 🔧 Implementation Steps

### Step 1: Update Controller (`EmployeeCashController::index`)

**File**: `app/Http/Controllers/FIN/EmployeeCashController.php`

**Changes**:
1. Modify Card 1 (Invoices) calculation:
   - Add deposits breakdown under cash
   - Keep online approved/pending split

2. Modify Card 2 (Expenses) calculation:
   - Change from request-based to ledger-based
   - Add EXP_FUND split
   - Add settlement needed split
   - Exclude vendor payments

3. Add Card 3 (Vendor Payments) calculation:
   - Query vendor purchases
   - Query vendor payments
   - Calculate total

4. Add Card 4 (Approvals & Riders) calculation:
   - Get riders balance (real-time)
   - Get pending deposits
   - Get pending expenses

5. Add Card 5 (NF Balance/Profit) calculation:
   - Calculate profit
   - Provide breakdown values

### Step 2: Update View (`resources/views/fin/employee/index.blade.php`)

**File**: `resources/views/fin/employee/index.blade.php`

**Changes**:
1. Update Card 1 HTML:
   - Modify cash sub-values to show deposits + short cash note
   - Keep online approved/pending

2. Update Card 2 HTML:
   - Change title to "All Expenses"
   - Show EXP_FUND amount
   - Show settlement needed amount

3. Add Card 3 HTML:
   - New card for vendor payments
   - Show purchases and payments

4. Update Card 5 HTML (was "With Riders"):
   - Change to show approvals + riders balance
   - Show pending deposits
   - Show pending expenses

5. Add new Card 5 HTML:
   - NF Balance/Profit card
   - Show profit as main value
   - Show breakdown

---

## ⚠️ Important Considerations

### 1. Filter Consistency
- All date filters must respect the selected period (Month/Day/Custom)
- Real-time values (Riders Balance) should NOT be filtered
- Use `transaction_date` for ledger queries
- Use `created_at` for request queries
- Use `changed_at` for order status history

### 2. Vendor Payment Direction
- After recent fix: `from_account_id` = Payment Source, `to_account_id` = Vendor
- Vendor payments reduce cash (FROM payment account)
- Vendor purchases increase liability (TO vendor account)

### 3. Short Cash Logic
- Short cash that's settled creates a deposit transaction
- Already included in deposits count
- No separate tracking needed

### 4. Expense vs Vendor Payment
- Expenses: `transaction_type = 'expense'`
- Vendor Payments: `transaction_type = 'vendor_payment'`
- These are SEPARATE and should NOT overlap

### 5. Existing Functionality
- ✅ Keep all existing filters working
- ✅ Keep account list below cards unchanged
- ✅ Keep pagination working
- ✅ Keep search functionality
- ✅ Maintain all existing routes

---

## 📊 Expected Output

### Card Layout:
```
┌─────────────┬─────────────┬─────────────┬─────────────┬─────────────┐
│  INVOICES   │  EXPENSES   │   VENDOR    │  APPROVALS  │ NF BALANCE  │
│             │             │  PAYMENTS   │  & RIDERS   │  (PROFIT)   │
├─────────────┼─────────────┼─────────────┼─────────────┼─────────────┤
│ Rs. 88,613  │ Rs. 5,100   │ Rs. 9,000   │ Rs. -5,295  │ Rs. 74,513  │
├─────────────┼─────────────┼─────────────┼─────────────┼─────────────┤
│ 💵 Cash:    │ 💰 EXP Fund:│ 📦 Purchases│ 👥 Riders:  │ 📊 Revenue: │
│   Deposits: │   Rs. 4,950 │   Rs. 8,000 │   Rs. -5,295│   Rs. 88,613│
│   Rs. 21,912│ ⏳ Settling: │ 💸 Payments:│ ⬇️ Pending  │ 🧾 Expenses:│
│ 💳 Online:  │   Rs. 150   │   Rs. 1,000 │   Deposits: │   Rs. 5,100 │
│   Approved: │             │             │   Rs. 0     │ 🏪 Vendor:  │
│   Rs. 35,190│             │             │ ⬆️ Pending  │   Rs. 9,000 │
│   Pending:  │             │             │   Expenses: │             │
│   Rs. 4,875 │             │             │   Rs. 745   │             │
└─────────────┴─────────────┴─────────────┴─────────────┴─────────────┘
```

---

## ✅ Testing Checklist

After implementation:

1. ☐ Apply Month filter → All cards update correctly
2. ☐ Apply Day filter → All cards update correctly
3. ☐ Apply Custom range → All cards update correctly
4. ☐ Riders Balance stays same regardless of filter (real-time)
5. ☐ Invoices card shows correct cash/online split
6. ☐ Expenses card excludes vendor payments
7. ☐ Vendor Payments card shows purchases + payments
8. ☐ Approvals card shows pending amounts
9. ☐ Profit card calculates correctly
10. ☐ All existing functionality still works (account list, search, pagination)

---

## 🚀 Ready to Implement

This plan ensures:
- ✅ All 5 cards properly defined
- ✅ Filters respected where appropriate
- ✅ Real-time values not filtered
- ✅ Vendor payments separate from expenses
- ✅ No existing functionality broken
- ✅ Clear data sources identified
- ✅ Proper accounting logic applied

Proceed with implementation?

