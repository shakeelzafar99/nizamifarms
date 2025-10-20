# Approved Items Visibility Fix - October 20, 2025

## Issue
Approved expense requests (including auto-approved short cash expenses) were not showing in the Approvals Dashboard's "Approved" filter, even though they appeared correctly in Expense Management.

## Root Cause
The query for approved requests was filtering by `completed_at` date:

```php
$approvedRequests = RequestModel::where('status', 'approved')
    ->whereBetween('completed_at', [$dateFrom, $dateTo])
    ->get();
```

**Problem**: Some approved requests might have `completed_at = NULL`, especially:
1. Auto-approved short cash expenses
2. Requests approved through certain flows
3. Legacy data

When `completed_at` is NULL, the `whereBetween` filter excludes them, even if they were approved today.

## Fix Applied

Updated the query to handle NULL `completed_at` values by falling back to `updated_at`:

```php
// Approved requests
// Use COALESCE to handle cases where completed_at might be NULL
$approvedRequests = RequestModel::where('status', 'approved')
    ->where(function($query) use ($dateFrom, $dateTo) {
        $query->whereBetween('completed_at', [$dateFrom, $dateTo])
              ->orWhere(function($q) use ($dateFrom, $dateTo) {
                  $q->whereNull('completed_at')
                    ->whereBetween('updated_at', [$dateFrom, $dateTo]);
              });
    })
    ->with(['category', 'requester', 'paymentSourceAccount', 'createdBy'])
    ->orderBy('completed_at', 'desc')
    ->orderBy('updated_at', 'desc')
    ->get();
```

**Logic**:
1. **Primary**: If `completed_at` exists and is within date range → Include
2. **Fallback**: If `completed_at` is NULL but `updated_at` is within date range → Include
3. **Result**: All approved requests from the date range are now visible

## File Modified
- `app/Http/Controllers/ApprovalController.php` (lines 186-199)

## Testing

### Before Fix
```
Approved Filter:
- REQ-202510-0001: ✅ Visible (has completed_at)
- REQ-202510-0004: ❌ Missing (completed_at = NULL)
- REQ-202510-0003: ❌ Missing (completed_at = NULL)
```

### After Fix
```
Approved Filter:
- REQ-202510-0001: ✅ Visible (completed_at)
- REQ-202510-0004: ✅ Visible (updated_at fallback)
- REQ-202510-0003: ✅ Visible (updated_at fallback)
```

## Verification Steps

1. Go to Approvals Dashboard
2. Click "Approved" filter
3. **Verify**: All approved requests from last 30 days are visible
4. **Verify**: Auto-approved short cash expenses appear
5. **Verify**: Regular approved expenses appear

## Related Issues

This fix ensures consistency between:
- ✅ Approvals Dashboard (Approved filter)
- ✅ Expense Management (All Expenses tab)
- ✅ Auto-approved short cash expenses
- ✅ Manually approved expenses

## Status
✅ **COMPLETE**

All approved items now visible in Approvals Dashboard regardless of whether `completed_at` is set or NULL.

