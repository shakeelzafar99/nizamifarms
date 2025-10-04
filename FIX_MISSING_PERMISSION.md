# 🔴 CRITICAL: Missing Permission in Database!

## Problem Found

The `view_all_requests` permission **DOES NOT EXIST** in the `t_sys_role_permissions` table!

We added it to the code (`RolePermissionController.php`) but the database doesn't have it yet.

**This is why**:
- Haider sees an error on `/requests` (fixed by adding `hasPermission()` method)
- The "All Requests" tab is hidden for EVERYONE (even admins/managers who should see it)
- Riders can only see "My Requests" tab

---

## ✅ Solution: Create the Missing Permission

### Option 1: Through UI (Recommended)

For EACH role that needs this permission:

1. Go to **Roles** → Select role (e.g., "Manager", "Admin")  
2. Click **"Manage Permissions"**
3. Click **"Set Defaults for [Role Type]"** button
4. This will add ALL missing permissions including `view_all_requests`

**Do this for**:
- Manager roles (should have `view_all_requests` = ALLOWED)
- Admin roles (should have `view_all_requests` = ALLOWED)  
- Rider roles (should have `view_all_requests` = DENIED - already done by default)

---

### Option 2: Via SQL (Faster)

Run this SQL to add the permission for existing roles:

```sql
-- Add view_all_requests permission for all roles
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    id as role_id,
    'view_all_requests' as permission_key,
    'View All Requests (vs own)' as permission_name,
    CASE 
        WHEN type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = t_sys_role.id 
    AND permission_key = 'view_all_requests'
);
```

---

## After Fixing

Once the permission is added:

### ✅ For Riders (Haider, Farooq):
- `/requests` page will load ✓
- Will see "My Requests" tab only (no "All Requests" tab)
- Can create and view ONLY their own requests

### ✅ For Managers/Admins:
- `/requests` page will load ✓
- Will see BOTH "My Requests" AND "All Requests" tabs
- Can view everyone's requests

---

## About Attendance

**Current setup is CORRECT**:
- `/attendance` - Main page for managers/admins to mark anyone's attendance
- `/attendance/mine` - Page for riders to view/mark ONLY their own attendance

Haider and Farooq have `view_attendance` permission, so they can access `/attendance/mine` to see their own attendance history.

If they want to **mark** their own attendance, they should use the "+" button on `/attendance/mine` page.

---

## Summary

1. **Run the SQL above** OR **click "Set Defaults" for each role through UI**
2. **Refresh** the requests page
3. **Test**:
   - Haider logs in → sees "My Requests" tab, can create leave requests
   - Manager logs in → sees "My Requests" AND "All Requests" tabs

