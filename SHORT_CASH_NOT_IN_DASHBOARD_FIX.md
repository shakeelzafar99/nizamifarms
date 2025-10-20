# Short Cash Not Showing in Approvals Dashboard - Fix

## Issue
Short cash expense request (REQ-202510-0027) is:
- ✅ Showing in Expense Management (Pending tab)
- ❌ NOT showing in Approvals Dashboard (L1 Pending)

## Root Cause
This request was created BEFORE we added the fix for approval levels. It's missing:
- `requires_level_1` field
- `level_1_status` field
- Possibly wrong `settlement_status`
- Possibly missing `submitted_at`

## Solution
Run the comprehensive fix SQL script:

### File: `fix_all_short_cash_approval_issues.sql`

This script will:
1. ✅ Fix approval levels (`requires_level_1`, `level_1_status`)
2. ✅ Fix settlement status (change `not_required` to `pending`)
3. ✅ Fix missing `submitted_at` dates
4. ✅ Verify all fixes
5. ✅ Show summary

## After Running the Script

### The expense request will:
1. ✅ Show in Approvals Dashboard → L1 Pending → EXP FUND area
2. ✅ Show in Expense Management → Pending tab
3. ✅ Be approvable from both locations
4. ✅ Require settlement after approval

### When you approve it:
1. ✅ Will show in Approvals Dashboard → Approved section
2. ✅ Will show in Expense Management → Needs Settlement tab
3. ✅ Can be settled from Expense Management

## Why This Happened
The expense requests were created with the OLD code that didn't set approval levels. We've now:
- ✅ Fixed the code (for new submissions)
- ⚠️ Need to fix existing requests with SQL (one-time fix)

## Run This Command
```sql
source fix_all_short_cash_approval_issues.sql;
```

Or copy/paste the SQL into your database tool.

## After the Fix
Refresh the Approvals Dashboard and you should see:
- REQ-202510-0027 (Rs. 50) in L1 Pending → EXP FUND
- REQ-202510-0026 (Rs. 300) in L1 Pending → EXP FUND

Both will be there and approvable!

