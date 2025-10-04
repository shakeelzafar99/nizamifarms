# hasPermission() Method - Critical Fix

## 🔴 Error Fixed

**Error**: `Call to undefined method App\Models\User::hasPermission()`

**Root Cause**: The `UserModel` didn't have a `hasPermission()` method, but the code was calling it in:
- `app/Http/Controllers/Request/RequestController.php` (line 42)
- `resources/views/pages/requests/index.blade.php` (line 45)

---

## ✅ Solution

Added the `hasPermission()` method to `app/Models/SysAdmin/UserModel.php`:

```php
/**
 * Check if user has a specific permission
 * Checks across all roles the user has
 */
public function hasPermission(string $permissionKey): bool
{
    foreach ($this->roles as $role) {
        if (RolePermissionModel::hasPermission($role->id, $permissionKey)) {
            return true;
        }
    }
    return false;
}
```

**How it works**:
1. Loops through all roles assigned to the user
2. For each role, checks if that role has the requested permission
3. Returns `true` if ANY of the user's roles has the permission
4. Returns `false` if none of the user's roles have it

---

## 📋 Testing Checklist

1. ✅ **Requests page loads** - No more "hasPermission()" error
2. ✅ **"All Requests" tab visibility** - Only shows if user has `view_all_requests` permission
3. ✅ **Riders see their requests only** - Without `view_all_requests` permission
4. ✅ **Managers/Admins see all requests** - With `view_all_requests` permission

---

## 🔍 User Data Check

Please run `check_haider_farooq.sql` to verify Haider and Farooq's:
- User ID
- Role(s)
- Permissions (especially `view_attendance`, `view_requests`, `create_requests`)
- Rider profile data (if any)

This will help us understand why Haider might have issues.

---

## 📝 Notes

- The method is now available on ANY `UserModel` instance via `$user->hasPermission('permission_key')`
- Works with multiple roles - if user has multiple roles, checks ALL of them
- Case-sensitive permission keys - make sure to use exact key names like `view_all_requests`, not `View_All_Requests`
- Requires the `roles` relationship to be loaded (eager or lazy loading)

---

## Next Steps for Haider's Attendance Issue

After running the SQL, we need to:
1. Check if Haider has the `view_attendance` permission
2. Verify if he's restricted to viewing only his own attendance
3. Check if he should be able to mark attendance for others (probably NOT - riders should only mark their own)

The attendance marking should work like this:
- **Regular users/riders**: Can only mark their OWN attendance
- **Managers/Admins**: Can mark attendance for ANYONE (using the main "Mark Attendance" button with user selection)

