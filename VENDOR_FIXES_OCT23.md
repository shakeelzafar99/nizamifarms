# Vendor System Fixes - October 23, 2025

## 🐛 **Issues Fixed**

### 1. Running Balance Calculation Error
**Problem**: Vendor running balance was incorrect - payments were adding to balance instead of subtracting.

**Root Cause**: The balance calculation logic was checking `to_account_id` to determine if a transaction was a purchase or payment. However, both purchases AND payments have `to_account_id` set to the vendor account, causing payments to be treated as purchases.

**Fix**: Changed logic to check `transaction_type` instead of account direction.

**File**: `app/Http/Controllers/FIN/VendorController.php` (Lines 172-184)

**Before**:
```php
if ($transaction->to_account_id === $vendorAccountId) {
    // Purchase - increases liability
    $runningBalance += $transaction->amount;
} else {
    // Payment - decreases liability
    $runningBalance -= $transaction->amount;
}
```

**After**:
```php
if ($transaction->transaction_type === 'vendor_purchase') {
    // Purchase - increases liability
    $runningBalance += $transaction->amount;
} elseif ($transaction->transaction_type === 'vendor_payment') {
    // Payment - decreases liability
    $runningBalance -= $transaction->amount;
}
```

---

### 2. "Add Line Item" Button Visibility
**Problem**: The "+" symbol in the "Purchase by Weight" modal was not visible enough.

**Fix**: Changed button to blue color with text "+ Add Line Item" for better visibility.

**File**: `resources/views/fin/vendor/show.blade.php` (Lines 312-314)

**Before**:
```html
<button class="bg-orange-500 text-white">
    ➕ Add Product Line
</button>
```

**After**:
```html
<button class="bg-blue-600 text-white hover:bg-blue-700">
    + Add Line Item
</button>
```

---

### 3. Vendor Report - Show/Hide Payments Filter
**Problem**: User wanted ability to exclude payments from vendor reports while keeping purchases, balance, and other fields. When unchecked, the report was still showing "Payments: Rs. 0.00" which could confuse vendors.

**Fix**: 
- Added a checkbox filter "Show Payments" in the report modal
- When unchecked, payments are completely hidden from the report (no "Payments: Rs. 0.00" text)
- The "Payments" column is removed from vendor totals and grand totals
- Excel export also respects this setting

**Files Modified**:
1. `resources/views/fin/vendor/index.blade.php` (Lines 597, 615-618, 647, 655-656, 816, 834-844, 872, 914-925)
2. `app/Http/Controllers/FIN/VendorController.php` (Lines 677, 705-708, 715)

**Frontend Changes**:
```html
<!-- Added checkbox in filter section -->
<div class="flex items-end">
    <label class="inline-flex items-center px-3 py-1.5 bg-white border rounded">
        <input type="checkbox" id="show_payments" checked>
        <span class="ml-2 text-sm font-medium">Show Payments</span>
    </label>
</div>
```

```javascript
// Updated generateReport() function
const showPayments = document.getElementById('show_payments').checked;
const params = new URLSearchParams({
    date_from: dateFrom,
    date_to: dateTo,
    show_payments: showPayments ? '1' : '0'
});
```

**Backend Changes**:
```php
// In getReport() method
$showPayments = $request->input('show_payments', '1') === '1';

// Filter transaction types based on checkbox
$transactionTypes = [LedgerModel::TYPE_VENDOR_PURCHASE];
if ($showPayments) {
    $transactionTypes[] = LedgerModel::TYPE_VENDOR_PAYMENT;
}

$transactions = LedgerModel::whereIn('transaction_type', $transactionTypes)
    // ... rest of query
```

---

## 📊 **How It Works**

### Running Balance Fix:
```
BEFORE (WRONG):
Purchase Rs. 250,000 → Balance: Rs. 250,000 ✓
Payment Rs. 10,000  → Balance: Rs. 260,000 ❌ (should be Rs. 240,000)

AFTER (CORRECT):
Purchase Rs. 250,000 → Balance: Rs. 250,000 ✓
Payment Rs. 10,000   → Balance: Rs. 240,000 ✓
```

### Show Payments Filter:
```
Checkbox CHECKED (default):
- Shows purchases AND payments
- Shows payment rows in daily summary
- Shows "Payments: Rs. X" in vendor totals
- Shows "Total Payments" in grand total (3 columns)
- Excel export includes payment data

Checkbox UNCHECKED:
- Shows purchases ONLY
- Hides payment rows completely
- NO "Payments: Rs. 0.00" text (completely removed)
- Vendor total shows only "Purchases: Rs. X"
- Grand total shows only 2 columns (Purchases + Net Change)
- Balance still shows correctly
- Purchase totals still accurate
- Excel export excludes payment data
```

---

## 🎯 **Benefits**

1. ✅ **Accurate Balance**: Running balance now correctly reflects purchases (+) and payments (-)
2. ✅ **Better UX**: Blue "Add Line Item" button is more visible and clear
3. ✅ **Flexible Reporting**: Users can choose to include or exclude payments from reports
4. ✅ **Cleaner Reports**: When payments are hidden, focus is on purchases only

---

## 📋 **Testing Checklist**

### Running Balance:
- [ ] Create vendor purchase - balance increases
- [ ] Record vendor payment - balance decreases
- [ ] Check running balance column in transaction table
- [ ] Verify balance matches: Opening + Purchases - Payments

### Add Line Item Button:
- [ ] Open "Purchase by Weight" modal
- [ ] Verify button is blue and says "+ Add Line Item"
- [ ] Click button - new line item should appear
- [ ] Button should be clearly visible

### Show Payments Filter:
- [ ] Open vendor report modal
- [ ] Checkbox should be checked by default
- [ ] Generate report WITH checkbox checked:
  - Should show purchases and payments
  - Should show payment totals
- [ ] Uncheck "Show Payments" checkbox
- [ ] Generate report WITHOUT checkbox:
  - Should show purchases only
  - Should NOT show payment rows
  - Balance should still be correct
- [ ] Check/uncheck and regenerate multiple times

---

## 🔍 **Technical Details**

### Why Transaction Type Check?

**Ledger Entry Structure**:
```
Vendor Purchase:
- from_account_id: VENDOR_PURCHASE account
- to_account_id: Vendor account
- transaction_type: 'vendor_purchase'
- Effect: Increases vendor balance (we owe them)

Vendor Payment:
- from_account_id: Payment source (NF_CASH, ONLINE, etc.)
- to_account_id: Vendor account
- transaction_type: 'vendor_payment'
- Effect: Decreases vendor balance (we pay them)
```

**Problem with Old Logic**:
Both have `to_account_id = vendor_account_id`, so we can't distinguish them by account direction alone.

**Solution**:
Check `transaction_type` field which explicitly identifies the transaction purpose.

---

## 📁 **Files Modified**

| File | Lines | Changes |
|------|-------|---------|
| `app/Http/Controllers/FIN/VendorController.php` | 172-184 | Fixed running balance calculation |
| `app/Http/Controllers/FIN/VendorController.php` | 677, 705-718 | Added show_payments filter |
| `resources/views/fin/vendor/show.blade.php` | 312-314 | Changed button to blue "+ Add Line Item" |
| `resources/views/fin/vendor/index.blade.php` | 975-980 | Added "Show Payments" checkbox |
| `resources/views/fin/vendor/index.blade.php` | 597, 615-618 | Updated JS to send show_payments param |

**Total**: 3 files, ~30 lines modified

---

## ✅ **Sign-Off**

**Issues Fixed**: 3/3  
**Status**: ✅ COMPLETE  
**Testing**: ⏳ Pending UAT  
**Deployment**: ✅ Ready  

**Critical Fix**: Running balance calculation (was causing incorrect balances)  
**UX Improvements**: Better button visibility, flexible reporting  
**Risk Level**: Low (bug fixes only)  

---

**Last Updated**: October 23, 2025  
**Implemented By**: AI Assistant  
**Priority**: High (balance calculation was critical)

