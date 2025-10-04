# Permission & Access Control Fixes

## Issues Fixed

### 1. ❌→✅ Riders Cannot Access `/attendance/mine`
**Problem**: The route was restricted to users with `rider` role using middleware, but riders with "NF Rider" profile were getting 403 Forbidden.

**Root Cause**: The `EnsureRole` middleware was checking for exact role match, and the route was too restrictive.

**Solution**: 
- Changed route middleware from `EnsureRole::class.':rider'` to just `auth`
- Now accessible by anyone who is logged in with `view_attendance` permission

**File Changed**: `routes/web.php`

```php
// Before:
Route::middleware(['auth', \App\Http\Middleware\EnsureRole::class.':rider'])->group(function () {
    Route::get('/attendance/mine', ...);
});

// After:
Route::middleware(['auth'])->group(function () {
    Route::get('/attendance/mine', ...);
});
```

---

### 2. ❌→✅ Riders Seeing All Requests Despite Permission Setting
**Problem**: Riders could see "All Requests" tab and view all requests even though "View All Requests (vs own)" permission was unchecked.

**Root Cause**: 
1. The `RequestController::data()` method didn't check the `view_all_requests` permission
2. The "All Requests" tab was always visible in the frontend

**Solution**: 
- **Backend**: Added permission check in `RequestController::data()` - if user tries to view 'all' but doesn't have `view_all_requests` permission, fall back to showing only their own requests
- **Frontend**: Hide "All Requests" tab if user doesn't have `view_all_requests` permission

**Files Changed**: 
- `app/Http/Controllers/Request/RequestController.php`
- `resources/views/pages/requests/index.blade.php`

**Backend Logic**:
```php
// Check permission
$canViewAllRequests = $user->hasPermission('view_all_requests');

if ($view === 'all') {
    if (!$canViewAllRequests) {
        // Restrict to own requests if no permission
        $query->where('requester_user_id', $user->id);
    }
    // Otherwise show all
}
```

**Frontend Logic**:
```blade
@if(auth()->user()->hasPermission('view_all_requests'))
<button class="kt-tab-btn" data-view="all" onclick="switchView('all')">
    All Requests
</button>
@endif
```

---

## Testing Checklist

### For NF Rider Role (ID 11):

1. ✅ **Attendance Access**:
   - Log in as a rider (e.g., Farooq)
   - Try to access `/attendance/mine`
   - Should load successfully (not 403 Forbidden)
   - Should see their own attendance history

2. ✅ **Requests Visibility**:
   - Go to `/requests`
   - Should only see "My Requests" tab (NO "All Requests" tab)
   - Click "My Requests" - should only see their own submitted requests
   - Should NOT see other employees' requests

3. ✅ **Permission Setting**:
   - Go to Roles → NF Rider → Permissions
   - Check "View All Requests (vs own)" is UNCHECKED
   - If you check it and save, the "All Requests" tab should appear
   - If you uncheck it and save, the "All Requests" tab should disappear

---

## Permission Matrix

| Role | View Attendance | View Own Attendance | View Requests | View All Requests | Create Requests |
|------|----------------|--------------------|--------------|--------------------|-----------------|
| **Rider** | ✅ | ✅ (via `/attendance/mine`) | ✅ | ❌ (by default) | ✅ |
| **Manager** | ✅ | ✅ | ✅ | ✅ (by default) | ✅ |
| **Admin** | ✅ | ✅ | ✅ | ✅ | ✅ |

---

## Notes

- The `view_all_requests` permission works as a toggle: 
  - **Checked**: User can see all requests from all employees
  - **Unchecked**: User can only see requests they created themselves
  
- The `/attendance/mine` route is now accessible by any authenticated user, but the controller still checks permissions internally

- Both backend AND frontend now enforce the `view_all_requests` permission for defense-in-depth security

