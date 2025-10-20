# Short Cash - Submitted At Field Fix
## Date: October 19, 2025

## Issue Reported
User encountered error when viewing short cash expense request details:
```
Call to a member function format() on null
resources/views/pages/requests/show.blade.php :67
```

## Root Cause
The `submitted_at` field was not being set when creating the short cash expense request, causing it to be `null`. The view template was trying to call `format()` on this null value without checking if it exists first.

## Fixes Applied

### Fix 1: Add Null Check in View
**File**: `resources/views/pages/requests/show.blade.php`  
**Line**: 65-70

Added conditional check before displaying submitted date:

**Before**:
```blade
<div>
    <label class="text-sm font-semibold text-gray-700">Submitted</label>
    <p class="text-base mt-1">{{ $request->submitted_at->format('Y-m-d H:i') }}</p>
</div>
```

**After**:
```blade
@if($request->submitted_at)
<div>
    <label class="text-sm font-semibold text-gray-700">Submitted</label>
    <p class="text-base mt-1">{{ $request->submitted_at->format('Y-m-d H:i') }}</p>
</div>
@endif
```

### Fix 2: Set submitted_at When Creating Request
**File**: `app/Http/Controllers/FIN/EmployeeCashController.php`  
**Line**: 1297

Added `submitted_at` field to expense request creation:

**Before**:
```php
$expenseRequest = \App\Models\Request\RequestModel::create([
    // ... other fields ...
    'level_2_status' => $category->requiresLevel2() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
    'created_by' => auth()->id(),
]);
```

**After**:
```php
$expenseRequest = \App\Models\Request\RequestModel::create([
    // ... other fields ...
    'level_2_status' => $category->requiresLevel2() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
    'submitted_at' => now(),  // ← ADDED
    'created_by' => auth()->id(),
]);
```

## Impact
- ✅ View details page no longer crashes
- ✅ Submitted date is properly recorded
- ✅ Consistent with other request creation flows
- ✅ Approval timeline is accurate

## Testing
- [x] View short cash expense request details
- [x] Verify submitted date displays correctly
- [x] Verify no errors in view
- [x] Check approval timeline

## Related
This is part of the short cash feature implementation fixes.

