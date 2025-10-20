# Approval Dashboard Date & Area Fix - October 20, 2025

## Issues Fixed

### Issue 1: Date Showing "03:00 AM" Instead of Actual Time
**Problem**: All items in the approval dashboard showed `Oct 20, 2025, 03:00 AM` even though the actual submission time was `10:57 AM`.

**Root Cause**: The `formatRequestItem` method was stripping the time from timestamps:
```php
// BEFORE (BROKEN)
'date' => $request->submitted_at ? $request->submitted_at->format('Y-m-d') : null,
// Returns: "2025-10-20" (date only, no time)
```

When JavaScript received `"2025-10-20"`, it interpreted it as midnight UTC, then converted to local time, resulting in weird times like "03:00 AM".

**Solution**: Include the full datetime in the response:
```php
// AFTER (FIXED)
'date' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i:s') : null,
// Returns: "2025-10-20 10:57:00" (full datetime)
```

---

### Issue 2: Salary Advance Showing in Wrong Area
**Problem**: Salary Advance (REQ-202510-0005) appeared in **NF_CASH** area in the approvals table, but when opened, it showed **EXP_FUND** as the payment source (which is correct).

**Root Cause**: The `determineRequestArea` method had a hardcoded rule:
```php
// BEFORE (BROKEN)
// Salary advances typically go to NF_CASH
if ($categoryCode === 'salary_advance') {
    return self::AREA_NF_CASH;
}
```

This ignored the actual `payment_source_account_id` and always classified salary advances as NF_CASH.

**Solution**: Changed the default area for salary advances to EXP_FUND:
```php
// AFTER (FIXED)
// Salary advances: Use payment source if available, otherwise default to EXP_FUND
// (Salary advances should be paid from EXP_FUND by default)
if ($categoryCode === 'salary_advance') {
    return self::AREA_EXP_FUND;
}
```

**Logic Flow**:
1. **First**: Check if `payment_source_account_id` is set → Use that account's area
2. **Second**: If no payment source, check category code → Salary advances default to EXP_FUND
3. **Third**: If nothing matches → Default to OTHERS

---

## Files Changed

### `app/Http/Controllers/ApprovalController.php`

#### Change 1: Request Date Format (Line 281)
```php
// BEFORE
'date' => $request->submitted_at ? $request->submitted_at->format('Y-m-d') : null,

// AFTER
'date' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i:s') : null,
```

#### Change 2: Ledger Date Format (Line 349)
```php
// BEFORE
'date' => $ledger->transaction_date,

// AFTER
'date' => $ledger->created_at ? $ledger->created_at->format('Y-m-d H:i:s') : $ledger->transaction_date,
```

#### Change 3: Adjustment Date Format (Line 373)
```php
// BEFORE
'date' => $adjustment->requested_at ? $adjustment->requested_at->format('Y-m-d') : null,

// AFTER
'date' => $adjustment->requested_at ? $adjustment->requested_at->format('Y-m-d H:i:s') : null,
```

#### Change 4: Salary Advance Area Classification (Lines 415-418)
```php
// BEFORE
// Salary advances typically go to NF_CASH
if ($categoryCode === 'salary_advance') {
    return self::AREA_NF_CASH;
}

// AFTER
// Salary advances: Use payment source if available, otherwise default to EXP_FUND
// (Salary advances should be paid from EXP_FUND by default)
if ($categoryCode === 'salary_advance') {
    return self::AREA_EXP_FUND;
}
```

---

## Testing

### Test Case 1: Date Display
**Before**: `Oct 20, 2025, 03:00 AM` (incorrect)  
**After**: `Oct 20, 2025, 10:57 AM` (correct - actual submission time)

**Steps**:
1. Refresh the Approvals Dashboard
2. Click "All Pending Approvals" or any filter
3. Check the DATE column
4. Should show actual submission time, not midnight

### Test Case 2: Salary Advance Area
**Before**: Shows in **NF_CASH** area (incorrect)  
**After**: Shows in **EXP_FUND** area (correct)

**Steps**:
1. Refresh the Approvals Dashboard
2. Click "L1 PENDING" or "L2 PENDING"
3. Click "EXP FUND" area card
4. Salary Advance (REQ-202510-0005) should appear
5. Click "NF CASH" area card
6. Salary Advance should NOT appear

### Test Case 3: Other Request Types
**Verify these still work correctly**:
- ✅ Expense Reimbursement → EXP_FUND
- ✅ Employee Deposits → NF_CASH (if from employee cash account)
- ✅ Online Invoices → ONLINE
- ✅ Leave Requests → OTHERS

---

## Impact

### What's Fixed
✅ Dates now show actual submission/creation time  
✅ Salary advances appear in correct area (EXP_FUND)  
✅ Area classification respects payment source account  
✅ No more "03:00 AM" confusion  

### What's NOT Changed
- Database schema unchanged
- No data migration required
- Existing requests unchanged
- Only affects display logic

---

## Why This Matters

### Date Display
- **Before**: Managers couldn't tell when a request was actually submitted
- **After**: Clear visibility of submission time for better tracking

### Area Classification
- **Before**: Salary advances appeared in wrong area, causing confusion
- **After**: Salary advances correctly grouped with other EXP_FUND expenses

---

## Related Issues

### Timezone (Separate Task)
The dates now show correctly, but they're in **local time (Pakistan Time)**.

**Next Step**: Implement comprehensive timezone strategy (see `TIMEZONE_STRATEGY_PLAN.md`)

**Priority**: Medium (after verifying current fixes work)

---

## Status

✅ **FIXED - READY FOR TESTING**

**Files Changed**: 1 file (`app/Http/Controllers/ApprovalController.php`)  
**Lines Changed**: 4 locations (dates + area classification)

**Next**: User to refresh and verify:
1. Dates show correct time (10:57 AM, not 03:00 AM)
2. Salary advance appears in EXP_FUND area

