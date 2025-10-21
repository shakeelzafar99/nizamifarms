# Salary Advance Fixes - October 21, 2025

## Issues Fixed

### Issue 1: Sorting Not Working (Latest First) ✅ FIXED

**Problem**: Approved items weren't sorted by date correctly. Requests and ledger transactions were grouped separately instead of mixed by date.

**Example of Problem**:
```
REQ-202510-0007: Oct 21, 05:37 PM
REQ-202510-0006: Oct 21, 05:35 PM
REQ-202510-0005: Oct 20, 10:57 AM
REQ-202510-0004: Oct 20, 09:56 AM  ← Out of order
TXN-20: Oct 21, 05:38 PM  ← Should be at top!
```

**Root Cause**: 
- Requests fetched and sorted separately
- Ledger transactions fetched and sorted separately
- Merged into one array but NOT re-sorted together

**Solution**: Added `usort()` after merging to sort all items by date

**File**: `app/Http/Controllers/ApprovalController.php`

**Changes Made**:

#### Change 1: Sort Approved Items (Lines 220-226)
```php
// Sort all items together by date (newest first)
// This ensures requests and ledger transactions are mixed and sorted by actual date
usort($items, function($a, $b) {
    $dateA = strtotime($a['date'] ?? '1970-01-01 00:00:00');
    $dateB = strtotime($b['date'] ?? '1970-01-01 00:00:00');
    return $dateB - $dateA;  // Descending order (newest first)
});
```

#### Change 2: Sort Rejected Items (Lines 268-273)
```php
// Sort all items together by date (newest first)
usort($items, function($a, $b) {
    $dateA = strtotime($a['date'] ?? '1970-01-01 00:00:00');
    $dateB = strtotime($b['date'] ?? '1970-01-01 00:00:00');
    return $dateB - $dateA;  // Descending order (newest first)
});
```

**Expected Result**:
```
TXN-20: Oct 21, 05:38 PM  ← Latest
REQ-202510-0007: Oct 21, 05:37 PM
REQ-202510-0006: Oct 21, 05:35 PM
REQ-202510-0005: Oct 20, 10:57 AM
REQ-202510-0004: Oct 20, 09:56 AM
REQ-202510-0003: Oct 20, 09:55 AM
TXN-9: Oct 20, 07:39 AM
REQ-202510-0002: Oct 20, 07:14 AM
REQ-202510-0001: Oct 20, 07:09 AM  ← Oldest
```

---

### Issue 2: Duplicate "Salary Advance" in Dropdown ⚠️ NEEDS DATABASE FIX

**Problem**: Two "Salary Advance" entries appear in the request category dropdown.

**Evidence**:
- One creates REQ (request) with payment source
- One creates TXN (transaction) without payment source
- Arsalan got TWO approvals for one request

**Root Cause**: Duplicate entries in `t_req_category` table

**Solution**: SQL script to identify and remove duplicate

**File**: `fix_duplicate_salary_advance_category.sql`

**Steps**:

1. **Run Analysis Script**:
   ```bash
   mysql -u root -p nizamifarms_db < fix_duplicate_salary_advance_category.sql
   ```

2. **Review Output**: Script will show:
   - All "Salary Advance" categories found
   - Their configurations
   - How many requests use each
   - Which one to keep

3. **Manual Fix** (if duplicate found):
   ```sql
   -- Replace X with the ID of the duplicate to delete
   
   -- Update any existing requests to use the correct category
   UPDATE t_req_master
   SET category_id = (SELECT id FROM t_req_category WHERE category_code = 'salary_advance' LIMIT 1)
   WHERE category_id = X;
   
   -- Delete the duplicate category
   DELETE FROM t_req_category_approval_config WHERE category_id = X;
   DELETE FROM t_req_category WHERE id = X;
   ```

4. **Verify**:
   ```sql
   SELECT * FROM t_req_category WHERE category_name LIKE '%Salary%Advance%';
   ```
   Should return only ONE row.

**Correct Configuration**:
- `category_code`: `salary_advance`
- `category_name`: `Salary Advance`
- `requires_level_1`: `1` (Yes)
- `requires_level_2`: `1` (Yes)
- `is_active`: `1` (Yes)

---

## Testing

### Test 1: Sorting ✅
**Steps**:
1. Go to Approvals Dashboard
2. Click "Approved" filter
3. Check dates in DATE column

**Expected**: 
- Latest date at top
- Oldest date at bottom
- Requests (REQ) and transactions (TXN) mixed together by date

---

### Test 2: Duplicate Category ⚠️
**Steps**:
1. Run `fix_duplicate_salary_advance_category.sql`
2. Review output
3. If duplicate found, run manual fix SQL
4. Go to "Create New Request"
5. Check "Request Category" dropdown

**Expected**: 
- Only ONE "Salary Advance" option
- Shows payment source dropdown when selected

---

### Test 3: No Double Approvals ⚠️
**Steps**:
1. After fixing duplicate category
2. Create new salary advance request
3. Submit for approval
4. Check Approvals Dashboard

**Expected**: 
- Only ONE approval created (REQ-xxx)
- NOT two approvals (REQ + TXN)

---

## Impact

### What's Fixed Automatically ✅
- Sorting in Approvals Dashboard (Approved tab)
- Sorting in Approvals Dashboard (Rejected tab)

### What Needs Database Fix ⚠️
- Duplicate "Salary Advance" category
- Double approvals for single request

---

## Files Changed

1. **`app/Http/Controllers/ApprovalController.php`**
   - Added `usort()` in `getApprovedItems()` (lines 220-226)
   - Added `usort()` in `getRejectedItems()` (lines 268-273)

2. **`fix_duplicate_salary_advance_category.sql`** (new file)
   - Analysis script to identify duplicates
   - Instructions for manual fix

3. **`check_duplicate_salary_advance.sql`** (new file)
   - Quick check script

---

## Status

### Sorting: ✅ **FIXED - READY FOR TESTING**

**Changes Applied**: Added sorting after merging items  
**Impact**: Approved and Rejected items now sorted by date (latest first)  
**Risk**: Low (only sorting logic)

### Duplicate Category: ⏳ **NEEDS DATABASE FIX**

**Analysis Complete**: SQL script ready  
**Next Step**: Run analysis script and follow instructions  
**Impact**: Will fix duplicate dropdown entries and double approvals  
**Risk**: Low (script checks before deleting)

---

## User Instructions

### For Sorting Fix:
1. Refresh Approvals Dashboard
2. Click "Approved" tab
3. Verify items are sorted by date (latest first)

### For Duplicate Category Fix:
1. Run: `mysql -u root -p nizamifarms_db < fix_duplicate_salary_advance_category.sql`
2. Review output to see if duplicate exists
3. If duplicate found, follow the manual fix instructions in the output
4. Test by creating a new salary advance request
5. Verify only one approval is created

---

## Notes

- **Sorting fix** is automatic (code change only)
- **Duplicate fix** requires database action (SQL script provided)
- Both fixes are safe and have low risk
- Sorting fix will work immediately after deployment
- Duplicate fix will prevent future double approvals

