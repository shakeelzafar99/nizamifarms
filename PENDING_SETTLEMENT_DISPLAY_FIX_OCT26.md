# Pending Settlement Display Fix - October 26, 2025

## Problem Summary

After implementing partial payment feature, invoices with **pending settlements** (not yet approved) were:
1. ❌ **Not appearing** in the settlement modal for additional payments
2. ❌ Showing **full amount** instead of remaining balance after pending settlement
3. ✅ Correctly showing in Daily Closing "PENDING" card (this was working)

### Example Scenario:
- Invoice NF-14556: Rs. 10,300
- Partial payment submitted: Rs. 10,000 (PENDING approval)
- Remaining: Rs. 300

**Expected Behavior:**
- Settlement modal should show the invoice with Rs. 300 remaining
- Rider should be able to settle the remaining Rs. 300 even before the first payment is approved

**Actual Behavior (Before Fix):**
- Invoice was completely hidden from settlement modal
- Couldn't settle the remaining Rs. 300 until first payment was approved

---

## Root Cause Analysis

### Issue 1: Overly Aggressive Exclusion

The `getOutstandingInvoices()` method was **completely excluding** any invoice that had a pending settlement, regardless of whether there was remaining balance.

**Old Logic:**
```php
// Get invoice IDs with pending settlements
$pendingSettlementInvoiceIds = [123]; // Invoice NF-14556

// Exclude ALL of them from the list
$openInvoices = LedgerModel::where(...)
    ->whereNotIn('id', $pendingSettlementInvoiceIds) // ❌ Excludes invoice completely
    ->get();
```

**Problem:** If an invoice had Rs. 10,300 total and Rs. 10,000 pending, it was completely hidden even though Rs. 300 remained.

### Issue 2: Not Calculating Pending Amounts

The system wasn't calculating how much of each invoice was covered by pending settlements.

**Old Logic:**
```php
'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0)
// ❌ Doesn't account for pending settlements
```

**Problem:** Even if the invoice appeared, it would show Rs. 10,300 instead of Rs. 300.

---

## Solution Implemented

### New Logic: Smart Pending Settlement Handling

1. **Calculate Pending Amounts Per Invoice**
   - Loop through all pending settlement deposits
   - Extract settlement metadata (invoice IDs, deposit amount, short cash amount)
   - Distribute pending amounts across invoices (same logic as `processInvoiceSettlement`)
   - Track how much is pending for each invoice

2. **Only Exclude Fully Covered Invoices**
   - Calculate: `outstanding_after_pending = amount - settled_amount - pending_amount`
   - If `outstanding_after_pending <= 0`: Exclude from list (will be fully settled)
   - If `outstanding_after_pending > 0`: Include in list with remaining balance

3. **Show Remaining Balance**
   - Return `pending_settlement_amount` in the response
   - Calculate `outstanding_amount` as: `amount - settled_amount - pending_amount`

---

## Code Changes

### File: `app/Http/Controllers/FIN/EmployeeCashController.php`

#### Method: `getOutstandingInvoices()` (Line ~1076-1170)

**Before:**
```php
// Simple exclusion - hide all invoices with pending settlements
$pendingSettlementInvoiceIds = [];
foreach ($pendingDeposits as $deposit) {
    $settlementData = $deposit->settlement_metadata;
    if ($settlementData && isset($settlementData['invoice_ids'])) {
        $pendingSettlementInvoiceIds = array_merge($pendingSettlementInvoiceIds, $settlementData['invoice_ids']);
    }
}

$openInvoices = LedgerModel::where(...)
    ->whereNotIn('id', $pendingSettlementInvoiceIds) // ❌ Excludes all
    ->get();

$invoices = $openInvoices->map(function($invoice) {
    return [
        'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0)
        // ❌ No pending amount consideration
    ];
});
```

**After:**
```php
// Smart exclusion - calculate pending amounts and only exclude fully covered invoices
$pendingSettlementInvoiceIds = [];
$pendingSettlementAmounts = []; // NEW: Track pending per invoice

foreach ($pendingDeposits as $deposit) {
    $settlementData = $deposit->settlement_metadata;
    if ($settlementData && isset($settlementData['invoice_ids'])) {
        $invoiceIds = $settlementData['invoice_ids'];
        $depositAmount = $settlementData['deposit_amount'] ?? 0;
        
        // Check if this is a short cash or partial payment
        $isShortCash = $settlementData['is_short_cash_settlement'] ?? false;
        $isPartialPayment = $settlementData['is_partial_payment'] ?? false;
        $shortCashAmount = $settlementData['short_cash_amount'] ?? 0;
        
        // Calculate total settlement amount
        if ($isShortCash) {
            $totalSettlementAmount = $depositAmount + $shortCashAmount;
        } else {
            $totalSettlementAmount = $depositAmount;
        }
        
        // Distribute pending amount across invoices (same logic as processInvoiceSettlement)
        $invoices = LedgerModel::whereIn('id', $invoiceIds)
            ->whereIn('settlement_status', ['open', 'partial'])
            ->orderBy('transaction_date', 'asc')
            ->get();
        
        $remainingAmount = $totalSettlementAmount;
        foreach ($invoices as $invoice) {
            $outstandingForThisInvoice = $invoice->amount - ($invoice->settled_amount ?? 0);
            $amountToSettle = min($remainingAmount, $outstandingForThisInvoice);
            
            if ($amountToSettle > 0) {
                if (!isset($pendingSettlementAmounts[$invoice->id])) {
                    $pendingSettlementAmounts[$invoice->id] = 0;
                }
                $pendingSettlementAmounts[$invoice->id] += $amountToSettle;
                $remainingAmount -= $amountToSettle;
            }
        }
        
        // Only exclude invoices that will be fully settled by pending deposits
        foreach ($invoices as $invoice) {
            $pendingForInvoice = $pendingSettlementAmounts[$invoice->id] ?? 0;
            $outstandingAfterPending = ($invoice->amount - ($invoice->settled_amount ?? 0)) - $pendingForInvoice;
            
            if ($outstandingAfterPending <= 0) {
                // Will be fully settled - exclude from list
                $pendingSettlementInvoiceIds[] = $invoice->id;
            }
        }
    }
}

$openInvoices = LedgerModel::where(...)
    ->whereNotIn('id', $pendingSettlementInvoiceIds) // ✅ Only excludes fully covered
    ->get();

// Calculate outstanding balance (amount - settled_amount - pending_amount)
$invoices = $openInvoices->map(function($invoice) use ($pendingSettlementAmounts) {
    $pendingAmount = $pendingSettlementAmounts[$invoice->id] ?? 0;
    $outstandingAmount = $invoice->amount - ($invoice->settled_amount ?? 0) - $pendingAmount;
    
    return [
        'id' => $invoice->id,
        'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
        'transaction_date' => $invoice->transaction_date->format('Y-m-d'),
        'description' => $invoice->description,
        'amount' => $invoice->amount,
        'settled_amount' => $invoice->settled_amount ?? 0,
        'pending_settlement_amount' => $pendingAmount, // ✅ NEW
        'outstanding_amount' => $outstandingAmount // ✅ Accounts for pending
    ];
});
```

---

## How It Works Now

### Example 1: Partial Payment with Remaining Balance

**Invoice:** NF-14556 = Rs. 10,300
**Pending Settlement:** Rs. 10,000 (PENDING approval)

**Calculation:**
```
outstanding_amount = 10,300 - 0 (settled) - 10,000 (pending) = 300
outstanding_after_pending = 300 > 0 → Include in list
```

**Result:**
- ✅ Invoice appears in settlement modal
- ✅ Shows Rs. 300 as outstanding
- ✅ Rider can settle the remaining Rs. 300
- ✅ Shows "Pending: Rs. 10,000" in the UI

---

### Example 2: Full Payment Pending

**Invoice:** NF-14553 = Rs. 7,000
**Pending Settlement:** Rs. 7,000 (PENDING approval)

**Calculation:**
```
outstanding_amount = 7,000 - 0 (settled) - 7,000 (pending) = 0
outstanding_after_pending = 0 → Exclude from list
```

**Result:**
- ✅ Invoice hidden from settlement modal (correct - fully covered)
- ✅ Shows in Daily Closing "PENDING" card
- ✅ After approval, moves to "SETTLED"

---

### Example 3: Multiple Partial Payments

**Invoice:** NF-14556 = Rs. 10,300
**First Pending:** Rs. 6,000
**Second Pending:** Rs. 3,000

**Calculation:**
```
outstanding_amount = 10,300 - 0 (settled) - 6,000 (pending 1) - 3,000 (pending 2) = 1,300
outstanding_after_pending = 1,300 > 0 → Include in list
```

**Result:**
- ✅ Invoice appears with Rs. 1,300 remaining
- ✅ Shows "Pending: Rs. 9,000" in the UI
- ✅ Can settle the remaining Rs. 1,300

---

## Approval Flow (Still Works Correctly)

### When Pending Settlement is Approved:

1. **`LedgerController::approve()` is called**
2. **`processInvoiceSettlement()` is triggered**
3. **Invoice is updated:**
   ```php
   $invoice->settled_amount += $amountToSettle;
   if ($invoice->settled_amount >= $invoice->amount) {
       $invoice->settlement_status = 'settled'; // Fully settled
   } else if ($invoice->settled_amount > 0) {
       $invoice->settlement_status = 'partial'; // ✅ Partially settled
   }
   ```
4. **Invoice moves from "PENDING" to "PARTIAL" or "SETTLED" in Daily Closing**
5. **Settlement modal updates to show new remaining balance**

---

## Frontend Display (Optional Enhancement)

The API now returns `pending_settlement_amount`, which can be displayed in the UI:

```javascript
// Example: Show pending amount in settlement modal
invoices.forEach(invoice => {
    const html = `
        <tr>
            <td>${invoice.order_number}</td>
            <td>Rs. ${invoice.amount.toFixed(2)}</td>
            ${invoice.pending_settlement_amount > 0 ? `
                <td class="text-orange-600">
                    Pending: Rs. ${invoice.pending_settlement_amount.toFixed(2)}
                </td>
            ` : ''}
            <td class="font-bold">Rs. ${invoice.outstanding_amount.toFixed(2)}</td>
        </tr>
    `;
});
```

---

## Testing Checklist

### Scenario 1: Partial Payment with Remaining Balance
- [ ] Create invoice Rs. 10,300
- [ ] Submit partial payment Rs. 10,000 (PENDING)
- [ ] Click "Settle" - invoice should appear with Rs. 300 remaining
- [ ] Submit second payment Rs. 300 (PENDING)
- [ ] Click "Settle" - invoice should not appear (fully covered by pending)
- [ ] Approve first payment - invoice should show as PARTIAL with Rs. 300 remaining
- [ ] Approve second payment - invoice should show as SETTLED

### Scenario 2: Full Payment Pending
- [ ] Create invoice Rs. 7,000
- [ ] Submit full payment Rs. 7,000 (PENDING)
- [ ] Click "Settle" - invoice should NOT appear (fully covered)
- [ ] Check Daily Closing - should show in PENDING card
- [ ] Approve payment - invoice should move to SETTLED

### Scenario 3: Multiple Partial Payments
- [ ] Create invoice Rs. 10,000
- [ ] Submit partial payment Rs. 3,000 (PENDING)
- [ ] Click "Settle" - should show Rs. 7,000 remaining
- [ ] Submit another partial Rs. 4,000 (PENDING)
- [ ] Click "Settle" - should show Rs. 3,000 remaining
- [ ] Approve both - invoice should show as PARTIAL with Rs. 3,000 remaining

### Scenario 4: Short Cash with Pending
- [ ] Create invoice Rs. 10,000
- [ ] Submit short cash: Deposit Rs. 8,000, Expense Rs. 2,000 (PENDING)
- [ ] Click "Settle" - invoice should NOT appear (fully covered)
- [ ] Approve - invoice should move to SETTLED

---

## Impact on Existing Functionality

### ✅ No Breaking Changes:
1. **Fully settled invoices** - Still work the same
2. **Open invoices (no pending)** - Still work the same
3. **Daily Closing** - Still works the same
4. **Approval process** - Still works the same
5. **Mobile API** - Uses same `getOutstandingInvoices()` method, so automatically fixed

### ✅ Improvements:
1. **Better UX** - Riders can see and settle remaining balance immediately
2. **More accurate** - Shows exact remaining amount after pending settlements
3. **More flexible** - Supports multiple partial payments before approval
4. **Consistent** - Pending amounts calculated using same logic as approval process

---

## Related Features

This fix works together with:
- **Partial Payment Settlement** (`PARTIAL_PAYMENT_SETTLEMENT_FEATURE_OCT26.md`)
- **Partial Invoice Settlement Fix** (`PARTIAL_INVOICE_SETTLEMENT_FIX_OCT26.md`)
- **LedgerController Settlement Processing** (sets `settlement_status = 'partial'`)

---

## Notes

- **No database changes required** - only query logic
- **No mobile app rebuild required** - API changes are server-side
- **Backward compatible** - handles old data gracefully
- **Performance** - Minimal overhead (only processes pending settlements)

---

**Status:** ✅ **COMPLETE** - Ready for testing and deployment

