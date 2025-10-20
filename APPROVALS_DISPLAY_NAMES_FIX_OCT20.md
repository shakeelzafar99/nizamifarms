# Approvals Display Names Fix - October 20, 2025

## Issue
In the Approvals Dashboard, the "REQUESTER" column was showing:
- **Employee Deposits**: Creator name (e.g., "Taimur") instead of Employee name (e.g., "Waseem")
- **Online Invoices**: Creator name (e.g., "Taimur") instead of Customer name

This made it difficult to quickly identify:
- Which employee is depositing money
- Which customer the invoice belongs to

## Solution

Updated `app/Http/Controllers/ApprovalController.php` in the `formatLedgerItem()` method (lines 289-300).

### Before
```php
'requester' => $ledger->createdBy ? $ledger->createdBy->fullname : 'System',
```

### After
```php
// Determine requester name based on transaction type
$requester = 'System';
if ($ledger->transaction_type === \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT) {
    // For employee deposits, show the employee name (from_account)
    $requester = $ledger->fromAccount ? $ledger->fromAccount->account_name : 'Unknown';
} elseif ($ledger->transaction_type === \App\Models\FIN\LedgerModel::TYPE_INVOICE && $ledger->order) {
    // For invoices, show the customer name
    $requester = $ledger->order->customer ? $ledger->order->customer->customer_name : 'Unknown';
} else {
    // For other types, show the person who created it
    $requester = $ledger->createdBy ? $ledger->createdBy->fullname : 'System';
}
```

## Result

### Employee Deposits
**Before:**
```
TXN-5 | Employee deposit | Taimur | Rs. 40,000
```

**After:**
```
TXN-5 | Employee deposit | Waseem | Rs. 40,000
```
✅ Instantly shows which employee is depositing

### Online Invoices
**Before:**
```
TXN-4 | Invoice | Taimur | Rs. 14,505
```

**After:**
```
TXN-4 | Invoice | CustomerName | Rs. 14,505
```
✅ Instantly shows which customer the invoice belongs to

### Other Transactions
For expense requests, transfers, etc., still shows the person who created it (no change).

---

## Files Modified
- `app/Http/Controllers/ApprovalController.php` (lines 289-300)

## Status
✅ Complete and deployed

---

## Business Impact
- **Faster decision-making**: Managers can quickly see which employee or customer the transaction relates to
- **Better context**: No need to click into each transaction to see details
- **Consistent with business logic**: Display names match the actual parties involved in the transaction


