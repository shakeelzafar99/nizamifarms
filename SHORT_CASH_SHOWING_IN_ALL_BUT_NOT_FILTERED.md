# Short Cash Showing in "All" but Not in Filtered View

## Current Status

✅ **REQ-202510-0027 IS visible** in "All Pending Approvals" table
✅ **Area is correctly assigned**: EXP FUND
✅ **All fields are correct**: L1 Pending, Rs. 50.00

## The Problem

When clicking:
1. L1 PENDING card → Shows 12 items
2. Then EXP FUND area card → Filters to specific items

**Expected**: REQ-202510-0027 should show
**Actual**: Might not be showing (need confirmation)

## Why This Could Happen

### Possible Issue 1: Area Comparison
The area filter checks: `$item['area'] === $area`

- Frontend sends: `'exp_fund'` (with underscore)
- Backend assigns: `'EXP_FUND'` or `'exp_fund'`?

If there's a case mismatch or format difference, the filter won't work.

### Possible Issue 2: Array Filter Bug
When the items are filtered by area, the array might lose its keys or the request might not match the exact area string.

## Quick Test

When you're on the dashboard:

1. Look at "All Pending Approvals" (shows 13 items)
2. Can you see REQ-202510-0027? **YES** ✅
3. Click "L1 PENDING" card
4. Do you see REQ-202510-0027 in the list?
   - If YES → Good, issue is with area filter
   - If NO → Issue is with level filter

5. Then click "EXP FUND" area card
6. Do you see REQ-202510-0027 in the list?
   - If YES → All working! 🎉
   - If NO → Area filter is the problem

## Next Steps

Please confirm:
- **Step 4**: Does it show when you click L1 PENDING (before clicking area)?
- **Step 6**: Does it show when you click EXP FUND area?

This will tell us exactly where the filtering is breaking!

