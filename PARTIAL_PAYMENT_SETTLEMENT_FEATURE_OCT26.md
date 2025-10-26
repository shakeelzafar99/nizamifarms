# Partial Payment Settlement Feature - October 26, 2025

## Overview
Enhanced the rider settlement system to support **partial payments** where riders can deposit less than the full invoice amount without creating an expense. The remaining balance stays open for future settlement.

---

## What Changed

### 1. **New "PENDING" Category**
- Added a special "PENDING" option in the expense category dropdown during settlement
- When selected, the system treats it as a partial payment instead of an expense
- The remaining amount is NOT deducted from the rider's balance
- Invoices remain **partially open** for future settlement

### 2. **Backend Changes**

#### **Web Application** (`EmployeeCashController.php`)
- Modified `recordShortCashSettlement()` method to detect "PENDING" category
- If "PENDING":
  - Sets `$isPartialPayment = true`
  - Skips expense request creation
  - Updates settlement metadata with `is_partial_payment: true`
  - Adjusts description and success message

#### **Mobile API** (`RiderController.php`)
- Updated `settleShortCash()` method with same logic as web
- Updated `getExpenseCategories()` to include "PENDING" at the top of the list
- Mobile app will automatically show "PENDING" option without rebuild

#### **Ledger Processing** (`LedgerController.php`)
- Modified `processInvoiceSettlement()` to handle partial payments
- For partial payments: `totalSettlementAmount = depositAmount only`
- For short cash: `totalSettlementAmount = depositAmount + expenseAmount`
- This ensures invoices stay partially open when using "PENDING"

### 3. **Frontend Changes**

#### **Web UI** (`resources/views/fin/employee/show.blade.php`)
- Added "PENDING" option to expense category dropdown
- Styled with blue background to distinguish from expense categories
- Added help text: "💡 Select 'Pending' to make a partial payment and keep the invoice(s) open for future settlement."

#### **Mobile App** (No Changes Required!)
- Mobile app fetches categories from API
- Will automatically show "PENDING" option on next API call
- **No rebuild needed** - works immediately after backend deployment

---

## How It Works

### Example Scenario:
1. **Rider has invoices totaling Rs. 10,000**
2. **Rider deposits Rs. 6,000** (short by Rs. 4,000)
3. **Rider selects "PENDING" as category**

**Result:**
- ✅ Deposit of Rs. 6,000 recorded (pending approval)
- ✅ No expense request created
- ✅ Invoices are partially settled (Rs. 6,000)
- ✅ Remaining Rs. 4,000 stays on rider's account
- ✅ Invoices remain "open" with Rs. 4,000 balance
- ✅ Can be settled again later with another deposit

### Comparison with Short Cash:
| Feature | Short Cash (Expense) | Partial Payment (PENDING) |
|---------|---------------------|---------------------------|
| Deposit Amount | Recorded | Recorded |
| Shortage Amount | Creates expense request | No expense created |
| Invoice Status | Fully settled | Partially settled (open) |
| Rider Balance | Fully cleared | Partially cleared |
| Future Settlement | Not possible | Can settle remaining later |

---

## User Flow

### **Web Application:**
1. Go to Employee Cash page
2. Click "Settle" on a rider account
3. Select invoices to settle
4. Enter deposit amount (less than total)
5. System shows shortage amount
6. Select **"⏳ Pending (Partial Payment - Keep Invoice Open)"** from dropdown
7. Submit settlement
8. Success message: "Partial payment recorded and pending approval! Paid: Rs. X, Remaining: Rs. Y. Invoice(s) will remain open with remaining balance after approval."

### **Mobile App:**
1. Open Ledger screen
2. Tap "Settle" button
3. Select invoices
4. Enter deposit amount (less than total)
5. System shows shortage
6. Select **"PENDING"** from Expense Category dropdown
7. Confirm settlement
8. Success message: "Partial payment recorded! Paid: Rs. X. Remaining Rs. Y will stay open for future settlement."

---

## Technical Details

### Settlement Metadata Structure:
```php
// Partial Payment
[
    'invoice_ids' => [123, 456],
    'deposit_amount' => 6000,
    'total_outstanding' => 10000,
    'short_cash_amount' => 4000,
    'expense_category' => 'PENDING',
    'is_partial_payment' => true,
    'is_short_cash_settlement' => false
]

// Short Cash (Expense)
[
    'invoice_ids' => [123, 456],
    'deposit_amount' => 6000,
    'total_outstanding' => 10000,
    'short_cash_amount' => 4000,
    'expense_category' => 'Petrol',
    'expense_request_id' => 789,
    'is_short_cash_settlement' => true,
    'is_partial_payment' => false
]
```

### Ledger Entry Description Examples:
- **Partial Payment:** "Partial Payment: INV-001, INV-002 - Paid Rs. 6,000, Remaining Rs. 4,000"
- **Short Cash:** "Short Cash Settlement: INV-001, INV-002 - Deposit Rs. 6,000, Expense Rs. 4,000 (Petrol)"

---

## Daily Closing Impact

### **Partial Invoices Card:**
- Partial payments will show in the "Partial Invoices" card on daily closing
- Shows invoices with `settlement_status = 'partial'`
- Displays settled amount and remaining balance

### **Cash Deposits:**
- Partial payment deposits count toward total cash deposits (after approval)
- No expense is created, so expense totals are unaffected

---

## Mobile App - No Rebuild Required! ✅

### Why No Rebuild?
1. **Dynamic API:** Mobile app fetches categories from `/rider/ledger/expense-categories` API
2. **Backend Update:** We added "PENDING" to the API response
3. **Automatic Pickup:** Next time the app calls the API, "PENDING" will appear in the dropdown
4. **No Code Changes:** Mobile app code already handles any category from the API

### When Will Users See It?
- **Immediately** after backend deployment
- Next time they open the settlement modal (triggers API call)
- If they have the modal already open, they need to close and reopen it

---

## Testing Checklist

### Web Application:
- [ ] Create partial settlement with "PENDING" category
- [ ] Verify no expense request is created
- [ ] Verify deposit transaction is created (pending approval)
- [ ] Approve deposit and verify invoice is partially settled
- [ ] Verify remaining balance stays on rider account
- [ ] Verify can settle same invoices again with another deposit
- [ ] Verify daily closing shows partial invoices correctly

### Mobile App:
- [ ] Open Ledger screen and tap "Settle"
- [ ] Verify "PENDING" appears in expense category dropdown
- [ ] Create partial settlement with "PENDING"
- [ ] Verify success message mentions "partial payment"
- [ ] Verify response includes `is_partial_payment: true`
- [ ] Verify invoices remain open after approval

---

## Files Modified

### Backend:
1. `app/Http/Controllers/FIN/EmployeeCashController.php`
   - `recordShortCashSettlement()` - Added partial payment logic

2. `app/Http/Controllers/FIN/LedgerController.php`
   - `processInvoiceSettlement()` - Handle partial payment settlement amount

3. `app/Http/Controllers/API/RiderController.php`
   - `settleShortCash()` - Added partial payment logic for mobile
   - `getExpenseCategories()` - Added "PENDING" to category list

### Frontend:
1. `resources/views/fin/employee/show.blade.php`
   - Added "PENDING" option to expense category dropdown
   - Added help text for partial payments

---

## Benefits

1. **Flexibility:** Riders can deposit what they have without forcing an expense
2. **Accuracy:** No need to create fake expenses for partial payments
3. **Tracking:** Clear audit trail of partial payments vs actual expenses
4. **Reconciliation:** Easier to track which invoices are partially settled
5. **Mobile Support:** Works seamlessly on mobile without app rebuild

---

## Notes

- "PENDING" is a special flag, not an actual expense category
- Partial payments still require manager approval (like all deposits)
- Multiple partial payments can be made for the same invoices
- Once fully settled, invoices move to "settled" status
- Daily closing "Partial Invoices" card shows all partially settled invoices

---

**Status:** ✅ **COMPLETE** - Ready for testing on both web and mobile
