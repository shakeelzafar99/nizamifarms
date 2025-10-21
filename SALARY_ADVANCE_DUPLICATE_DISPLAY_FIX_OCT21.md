# Salary Advance Duplicate Display Fix - October 21, 2025

## The REAL Problem (You Were Right!)

The issue was **NOT** duplicate categories creating two separate transactions.

The issue was that **ONE transaction was being displayed TWICE** in the approvals dashboard:
1. Once as the **REQUEST** (REQ-202510-0008)
2. Once as the **LEDGER TRANSACTION** (TXN-21) created by that request

## Root Cause

### How Salary Advance Works:
```
User creates salary advance request
    ↓
REQ-202510-0008 created (status: pending)
    ↓
L1 Approver approves
    ↓
L2 Approver approves
    ↓
System automatically creates ledger entry (TXN-21)
    ↓
TXN-21 is linked to REQ-202510-0008 via request_id
```

### The Display Bug:

**File**: `app/Http/Controllers/ApprovalController.php`

The `getApprovedItems()` method was fetching:
1. **All approved requests** (including salary advances)
2. **All approved ledger transactions** (including the ones created by requests)

**Result**: Same transaction shown twice!

```php
// OLD CODE (BUGGY)
$approvedRequests = RequestModel::where('status', 'approved')->get();
foreach ($approvedRequests as $req) {
    $items[] = $this->formatRequestItem($req);  // ← Shows REQ-202510-0008
}

$approvedLedger = LedgerModel::where('approval_status', 'approved')->get();
foreach ($approvedLedger as $ledger) {
    $items[] = $this->formatLedgerItem($ledger);  // ← Shows TXN-21 (same transaction!)
}
```

## The Fix

Added `->whereNull('request_id')` to exclude ledger entries that are already represented by their parent request.

### Change 1: L1 Pending Items (Lines 110-116)
```php
// Get pending ledger transactions (no L1/L2 - just pending)
// IMPORTANT: Exclude ledger entries that are linked to requests (to avoid duplicates)
$pendingLedger = LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING)
    ->whereNull('request_id')  // ← NEW: Only show standalone ledger entries
    ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order.customer'])
    ->orderBy('transaction_date', 'desc')
    ->get();
```

### Change 2: Approved Items (Lines 209-217)
```php
// Approved ledger transactions
// IMPORTANT: Exclude ledger entries that are linked to requests (to avoid duplicates)
// Those will already show up via the request itself
$approvedLedger = LedgerModel::where('approval_status', LedgerModel::STATUS_APPROVED)
    ->whereBetween('approval_date', [$dateFrom, $dateTo])
    ->whereNull('request_id')  // ← NEW: Only show standalone ledger entries
    ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order.customer'])
    ->orderBy('approval_date', 'desc')
    ->get();
```

### Change 3: Rejected Items (Lines 262-269)
```php
// Rejected ledger transactions
// IMPORTANT: Exclude ledger entries that are linked to requests (to avoid duplicates)
$rejectedLedger = LedgerModel::where('approval_status', LedgerModel::STATUS_REJECTED)
    ->whereBetween('updated_at', [$dateFrom, $dateTo])
    ->whereNull('request_id')  // ← NEW: Only show standalone ledger entries
    ->with(['fromAccount', 'toAccount', 'createdBy', 'request', 'order.customer'])
    ->orderBy('updated_at', 'desc')
    ->get();
```

## What This Means

### Ledger Entries WITH request_id (Hidden from standalone display)
- Salary advances created from requests
- Expenses created from requests
- Any transaction that originated from the request system
- **These show up via their parent request, not as standalone ledger entries**

### Ledger Entries WITHOUT request_id (Still shown)
- Direct deposits (employee cash deposits)
- Direct transfers (account transfers)
- Invoice settlements
- Vendor payments
- **These show up as standalone ledger entries**

## Impact

### Before Fix ❌
```
Approvals Dashboard (Approved):
- REQ-202510-0008: Arslan Aslam, Salary Advance, EXP FUND, Rs. 5,000
- TXN-21: Arslan Aslam, Salary advance, NF CASH, Rs. 5,000
  ↑ DUPLICATE! Same transaction, shown twice
```

### After Fix ✅
```
Approvals Dashboard (Approved):
- REQ-202510-0008: Arslan Aslam, Salary Advance, EXP FUND, Rs. 5,000
  ↑ Only shown once!
```

## Testing

### Test 1: Create New Salary Advance
1. Create salary advance for any employee
2. Approve L1
3. Approve L2
4. Check Approvals Dashboard (Approved filter)

**Expected**: Only ONE entry (REQ-xxx), not two

### Test 2: Check Existing Approvals
1. Go to Approvals Dashboard
2. Click "Approved" filter
3. Look for salary advances

**Expected**: Each salary advance shows only once

### Test 3: Verify Other Transactions Still Show
1. Create a direct deposit (not from request)
2. Approve it
3. Check Approvals Dashboard

**Expected**: Shows as TXN-xxx (because it has no request_id)

## Files Changed

1. **`app/Http/Controllers/ApprovalController.php`**
   - `getL1PendingItems()` - Added `whereNull('request_id')` (line 113)
   - `getApprovedItems()` - Added `whereNull('request_id')` (line 214)
   - `getRejectedItems()` - Added `whereNull('request_id')` (line 266)

## Status

✅ **FIXED - READY FOR TESTING**

**What was fixed**:
- Duplicate display of salary advances in approvals dashboard
- Duplicate display of any request-based transactions

**What still works**:
- Standalone ledger transactions (deposits, transfers, invoices) still show
- Request-based transactions show via their parent request
- All approval flows unchanged

**Risk**: Low (only filtering display, not changing approval logic)

## User's Insight Was Correct!

> "the 2 entries are not because of the 2 categories, the flow is calling 2 functions that create these separate req one with txn and the other with req"

**You were absolutely right!** The flow wasn't creating two separate transactions - it was creating ONE transaction (request + ledger), but the dashboard was displaying BOTH the request AND its linked ledger entry, making it look like duplicates.

The fix ensures that when a ledger entry is linked to a request, only the request shows up in the dashboard, not both.

## Next Steps

1. Refresh your approvals dashboard
2. The duplicate salary advances should now show only once
3. Create a new test salary advance to verify
4. Confirm only ONE entry appears

---

**Note**: The duplicate category cleanup is still good to do (for consistency), but it wasn't causing the double display issue. The double display was purely a UI/query issue in the ApprovalController.

