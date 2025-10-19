# Online Bank Ledger Fixes - October 19, 2025

## Summary
Fixed critical bugs in the Online Bank ledger page related to pending approvals display, cash flow calculations, and transaction status visibility.

---

## Bugs Fixed

### 1. **Pending Approvals Amount Showing 0**
**Problem**: The pending approvals card was showing Rs. 0.00 even though there were pending transactions.

**Root Cause**: 
1. The query for pending approvals was applying date filters, which excluded pending transactions outside the selected date range.
2. The `total_pending` calculation was using the date-filtered query instead of summing the actual pending approvals.

**Fix**: 
1. Removed date filtering from the pending approvals query so ALL pending transactions are shown regardless of the selected date filter.
2. Added a separate `$pendingApprovalsTotal` variable that sums the amounts from the `$pendingApprovals` collection.
3. Updated the `total_pending` in the summary to use `$pendingApprovalsTotal` for Online Bank accounts.

**File**: `app/Http/Controllers/FIN/EmployeeCashController.php`
```php
// Get pending approvals details for Online Bank account (NO DATE FILTER - show all pending)
$pendingApprovals = [];
$pendingApprovalsTotal = 0;
if ($account->account_code === 'ONLINE') {
    $pendingApprovals = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy'])
        ->where('to_account_id', $account->id)
        ->where('transaction_type', LedgerModel::TYPE_INVOICE)
        ->where('approval_status', LedgerModel::STATUS_PENDING)
        ->orderBy('transaction_date', 'desc')
        ->get();
    
    // Calculate total pending amount for Online Bank
    $pendingApprovalsTotal = $pendingApprovals->sum('amount');
}

$summary = [
    // ...
    'total_pending' => $account->account_code === 'ONLINE' ? $pendingApprovalsTotal : ($pendingQuery ? $pendingQuery->sum('amount') : ($pendingAmount->sum('amount') ?? 0)),
    // ...
];
```

---

### 2. **Total Cash IN Including Pending Transactions**
**Problem**: The "Total Cash IN" card was including pending (unapproved) invoice amounts, causing a mismatch with the actual balance.

**Root Cause**: The invoices query for Cash IN breakdown was not filtering by approval status.

**Fix**: Added `approval_status = 'approved'` filter to only include approved invoices in the Cash IN calculation.

**File**: `app/Http/Controllers/FIN/EmployeeCashController.php`
```php
// Invoices IN (for Online Bank account specifically) - ONLY APPROVED
$invoicesInQuery = LedgerModel::where('to_account_id', $account->id)
    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
    ->where('approval_status', LedgerModel::STATUS_APPROVED);
if ($dateFrom && $dateTo) {
    $invoicesInQuery->whereBetween('transaction_date', [$dateFrom, $dateTo]);
}
$cashInBreakdown['invoices'] = $invoicesInQuery->sum('amount') ?? 0;
```

**Impact**: 
- Current Balance now correctly reflects only approved transactions
- Total Cash IN now matches the sum of approved transactions
- Pending approvals are shown separately and don't affect the balance

---

### 3. **Missing Status Column in Transaction Table**
**Problem**: Users couldn't tell which transactions were pending vs approved in the transaction history table.

**Root Cause**: The transaction table didn't have a status column to display approval status.

**Fix**: Added a new "Status" column between "Type" and "Description" columns that shows:
- ⏳ Pending (yellow badge)
- ✅ Approved (green badge)
- ❌ Rejected (red badge)

**File**: `resources/views/fin/employee/show.blade.php`

**Table Header**:
```blade
<th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
```

**Table Cell**:
```blade
<td class="px-6 py-3 whitespace-nowrap">
    @php
        $status = $transaction->approval_status ?? 'approved';
        $statusColors = [
            'pending' => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            'approved' => 'bg-green-100 text-green-800 border-green-300',
            'rejected' => 'bg-red-100 text-red-800 border-red-300',
        ];
        $statusColor = $statusColors[$status] ?? 'bg-gray-100 text-gray-800 border-gray-300';
    @endphp
    <span class="px-2 py-1 text-xs font-semibold rounded-full border {{ $statusColor }}">
        @if($status === 'pending')
            ⏳ Pending
        @elseif($status === 'approved')
            ✅ Approved
        @elseif($status === 'rejected')
            ❌ Rejected
        @else
            {{ ucfirst($status) }}
        @endif
    </span>
</td>
```

---

## Testing Checklist

### Pending Approvals Card
- [ ] Shows correct count of pending invoices (not filtered by date)
- [ ] Shows correct total amount of pending invoices
- [ ] Opens modal with all pending invoices when clicked
- [ ] Modal shows correct invoice details

### Cash IN Calculation
- [ ] Total Cash IN only includes approved transactions
- [ ] Current Balance matches approved transactions
- [ ] Pending transactions don't affect the balance
- [ ] Invoices breakdown shows only approved amounts

### Transaction Table
- [ ] Status column is visible
- [ ] Pending transactions show "⏳ Pending" badge
- [ ] Approved transactions show "✅ Approved" badge
- [ ] Status badges have correct colors (yellow for pending, green for approved)
- [ ] Table layout is not broken by the new column

### Overall Flow
- [ ] Date filters work correctly for transaction history
- [ ] Date filters don't affect pending approvals count
- [ ] Approving a pending invoice updates the balance correctly
- [ ] Rejecting a pending invoice doesn't affect the balance

---

## Files Modified
1. `app/Http/Controllers/FIN/EmployeeCashController.php`
   - Fixed pending approvals query (removed date filter)
   - Fixed invoices Cash IN query (added approval status filter)

2. `resources/views/fin/employee/show.blade.php`
   - Added Status column to transaction table header
   - Added Status cell with color-coded badges

---

## Business Logic Summary

### Online Bank Ledger Flow
1. **Current Balance**: Sum of all APPROVED transactions
2. **Pending Approvals**: All pending invoices (regardless of date)
3. **Total Cash IN**: Sum of approved deposits, settlements, transfers, and invoices
4. **Transaction History**: Shows all transactions with their approval status

### Key Rules
- Pending transactions are NOT included in balance calculations
- Pending approvals are shown separately and prominently
- Date filters apply to transaction history but NOT to pending approvals
- Users can see at a glance which transactions are pending vs approved

---

## Notes
- All existing functionality preserved
- No database changes required
- Backward compatible with existing data
- Visual improvements make status immediately clear to users

