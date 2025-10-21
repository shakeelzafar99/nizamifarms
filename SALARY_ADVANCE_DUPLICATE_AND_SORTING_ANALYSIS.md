# Salary Advance Duplicate & Sorting Issues - October 21, 2025

## Issues Identified

### Issue 1: Two "Salary Advance" Categories in Dropdown ❌

**Evidence from Screenshots**:
- Dropdown shows "Salary Advance" twice
- One creates REQ (request) with payment source
- One creates TXN (transaction) without payment source

**Root Cause**: Likely duplicate entries in `t_req_category` table

**SQL to Check**:
```sql
SELECT id, category_code, category_name, is_active
FROM t_req_category
WHERE category_name LIKE '%Salary%Advance%' 
   OR category_code LIKE '%salary%advance%'
ORDER BY id;
```

**Expected**: Should only have ONE entry with `category_code = 'salary_advance'`

---

### Issue 2: Arsalan Has Two Approvals (REQ + TXN) ❌

**Evidence from Screenshot**:
- REQ-202510-0007: Arsalan, Salary Advance, EXP FUND, Rs. 5,000
- TXN-20: Arsalan, Salary advance, NF CASH, Rs. 5,000

**Root Cause**: Two different approval flows triggered:
1. **Request flow** (REQ) - Goes through request system with payment source
2. **Ledger flow** (TXN) - Direct ledger transaction without payment source

**Why This Happens**:
- If there are two "Salary Advance" categories with different configurations
- One configured for request system (shows payment source)
- One configured for direct ledger posting (no payment source)

---

### Issue 3: Not Sorted by Date (Latest First) ❌

**Evidence from Screenshot**:
```
REQ-202510-0007: Oct 21, 2025, 05:37 PM  ← Latest
REQ-202510-0006: Oct 21, 2025, 05:35 PM
REQ-202510-0005: Oct 20, 2025, 10:57 AM
REQ-202510-0003: Oct 20, 2025, 09:55 AM
REQ-202510-0004: Oct 20, 2025, 09:56 AM  ← Out of order!
REQ-202510-0002: Oct 20, 2025, 07:14 AM
REQ-202510-0001: Oct 20, 2025, 07:09 AM
TXN-9: Oct 20, 2025, 07:39 AM  ← Out of order!
TXN-20: Oct 21, 2025, 05:38 PM  ← Should be near top!
```

**Root Cause**: 
- Requests and ledger transactions are fetched separately
- Each query is sorted individually
- But when merged into single array, they're not re-sorted
- Result: Requests grouped together, then transactions grouped together

**Current Code**:
```php
// app/Http/Controllers/ApprovalController.php (lines 192-220)

// Get approved requests (sorted by completed_at desc)
$approvedRequests = RequestModel::where('status', 'approved')
    ->orderBy('completed_at', 'desc')
    ->get();

foreach ($approvedRequests as $req) {
    $items[] = $this->formatRequestItem($req);  // ← Added to array
}

// Get approved ledger (sorted by approval_date desc)
$approvedLedger = LedgerModel::where('approval_status', 'approved')
    ->orderBy('approval_date', 'desc')
    ->get();

foreach ($approvedLedger as $ledger) {
    $items[] = $this->formatLedgerItem($ledger);  // ← Added to array
}

return $items;  // ← Not sorted together!
```

**Result**: 
- All requests first (sorted among themselves)
- All ledger transactions second (sorted among themselves)
- But NOT sorted together by date

---

## Solutions

### Solution 1: Remove Duplicate Salary Advance Category

**SQL to Fix**:
```sql
-- Find duplicates
SELECT id, category_code, category_name, is_active, created_at
FROM t_req_category
WHERE category_name LIKE '%Salary%Advance%' 
   OR category_code LIKE '%salary%advance%'
ORDER BY created_at;

-- Keep the one with category_code = 'salary_advance'
-- Delete any others (replace X with the duplicate ID)
DELETE FROM t_req_category 
WHERE id = X 
  AND category_code != 'salary_advance'
  AND (category_name LIKE '%Salary%Advance%' OR category_code LIKE '%salary%advance%');
```

---

### Solution 2: Sort Approved Items After Merging

**File**: `app/Http/Controllers/ApprovalController.php`

**Current Code** (lines 174-220):
```php
private function getApprovedItems($dateFrom = null, $dateTo = null)
{
    $items = [];
    
    // ... date range setup ...
    
    // Get approved requests
    $approvedRequests = RequestModel::where('status', 'approved')
        ->whereBetween(...)
        ->orderBy('completed_at', 'desc')
        ->get();
    
    foreach ($approvedRequests as $req) {
        $items[] = $this->formatRequestItem($req, ...);
    }
    
    // Get approved ledger
    $approvedLedger = LedgerModel::where('approval_status', 'approved')
        ->whereBetween(...)
        ->orderBy('approval_date', 'desc')
        ->get();
    
    foreach ($approvedLedger as $ledger) {
        $items[] = $this->formatLedgerItem($ledger, ...);
    }
    
    return $items;  // ← NOT SORTED!
}
```

**Fixed Code**:
```php
private function getApprovedItems($dateFrom = null, $dateTo = null)
{
    $items = [];
    
    // ... date range setup ...
    
    // Get approved requests
    $approvedRequests = RequestModel::where('status', 'approved')
        ->whereBetween(...)
        ->orderBy('completed_at', 'desc')
        ->get();
    
    foreach ($approvedRequests as $req) {
        $items[] = $this->formatRequestItem($req, ...);
    }
    
    // Get approved ledger
    $approvedLedger = LedgerModel::where('approval_status', 'approved')
        ->whereBetween(...)
        ->orderBy('approval_date', 'desc')
        ->get();
    
    foreach ($approvedLedger as $ledger) {
        $items[] = $this->formatLedgerItem($ledger, ...);
    }
    
    // ✅ SORT ALL ITEMS TOGETHER BY DATE (NEWEST FIRST)
    usort($items, function($a, $b) {
        $dateA = strtotime($a['date'] ?? '1970-01-01');
        $dateB = strtotime($b['date'] ?? '1970-01-01');
        return $dateB - $dateA;  // Descending (newest first)
    });
    
    return $items;
}
```

**Same Fix Needed For**:
- `getRejectedItems()` (lines 226-260)
- Potentially `getL1PendingItems()` and `getL2PendingItems()` if they mix sources

---

## Testing

### Test 1: Check for Duplicate Categories
```sql
SELECT id, category_code, category_name, is_active
FROM t_req_category
WHERE category_name LIKE '%Salary%Advance%';
```

**Expected**: Only ONE row

---

### Test 2: Create Salary Advance Request
1. Go to "Create New Request"
2. Select "Salary Advance" from dropdown
3. Should only appear ONCE
4. Should show payment source dropdown
5. Submit and approve

**Expected**: Only ONE approval created (REQ-xxx), not two

---

### Test 3: Check Sorting in Approvals Dashboard
1. Go to Approvals Dashboard
2. Click "Approved" filter
3. Check dates in DATE column

**Expected**: 
- Latest date at top
- Oldest date at bottom
- Requests and transactions mixed together by date

**Example**:
```
Oct 21, 2025, 05:38 PM  ← TXN-20
Oct 21, 2025, 05:37 PM  ← REQ-202510-0007
Oct 21, 2025, 05:35 PM  ← REQ-202510-0006
Oct 20, 2025, 10:57 AM  ← REQ-202510-0005
Oct 20, 2025, 09:56 AM  ← REQ-202510-0004
Oct 20, 2025, 09:55 AM  ← REQ-202510-0003
Oct 20, 2025, 07:39 AM  ← TXN-9
Oct 20, 2025, 07:14 AM  ← REQ-202510-0002
Oct 20, 2025, 07:09 AM  ← REQ-202510-0001
```

---

## Implementation Plan

### Step 1: Check Database for Duplicates
Run `check_duplicate_salary_advance.sql`

### Step 2: Remove Duplicate if Found
Delete the duplicate category (keep the one with `category_code = 'salary_advance'`)

### Step 3: Fix Sorting in ApprovalController
Add `usort()` after merging items in:
- `getApprovedItems()`
- `getRejectedItems()`

### Step 4: Test
- Create new salary advance request
- Check approvals dashboard sorting
- Verify no duplicates

---

## Status

⏳ **ANALYSIS COMPLETE - READY FOR FIX**

**Issues**:
1. ❌ Duplicate "Salary Advance" category
2. ❌ Two approvals created for one request
3. ❌ Sorting not working correctly (requests and ledger not mixed)

**Solutions**:
1. ✅ SQL to remove duplicate
2. ✅ Add `usort()` after merging arrays

**Next**: Implement fixes

