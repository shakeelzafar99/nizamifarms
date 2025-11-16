# Approvals Mobile Permission Setup

## ✅ Issue Resolved

### Problem
1. Approvals menu item not showing in mobile app side menu
2. No mobile permission existed for viewing approvals
3. Permission check was looking for wrong permissions

### Solution
1. Created new mobile permission: `view_approvals`
2. Updated SideMenu to check for this permission
3. Added to web admin panel for role management

---

## 🔧 Changes Made

### 1. Database Migration
**File**: `database/migrations/add_approvals_mobile_permissions.sql`

**Added Permission**:
```sql
INSERT INTO t_sys_mobile_permission 
(permission_code, permission_name, permission_group, description, is_active)
VALUES 
('view_approvals', 'View Approvals', 'store_mode_approvals', 
 'View pending approval requests in mobile app', 1);
```

**Result**: New permission with ID 15 created

---

### 2. Mobile App Update
**File**: `src/components/SideMenu.js`

**Changed From**:
```javascript
const canViewApprovals = hasPermission('level_1_approval') || hasPermission('level_2_approval');
```

**Changed To**:
```javascript
const canViewApprovals = hasPermission('view_approvals');
```

**Why**: 
- `level_1_approval` and `level_2_approval` are **role permissions** (not mobile permissions)
- Mobile app only checks **mobile permissions** from `t_sys_mobile_permission`
- Now correctly checks for the mobile permission

---

## 📱 How to Enable for Users

### Step 1: Assign Mobile Permission
1. Log in to web app as admin
2. Go to **System Admin → Roles**
3. Click on the role (e.g., "Manager", "Admin")
4. Click **"Mobile App Permissions"** tab
5. Find **"Store Mode Approvals"** section
6. Check ✅ **"View Approvals"**
7. Click **"Save Mobile Permissions"**

### Step 2: Ensure User Has Approval Rights
Users also need actual approval rights to see items:
1. Go to **System Admin → Role Approval Levels**
2. Assign users to Level 1 or Level 2 approval
3. This determines what they can approve

### Step 3: Test on Mobile
1. User logs out and logs back in (to refresh permissions)
2. Opens side menu in Store Mode
3. Should now see **"Approvals"** menu item

---

## 🔄 Do You Need to Rebuild?

**NO REBUILD NEEDED!** ✅

For development:
```
In Metro terminal: Press 'r'
OR
Shake device → Tap "Reload"
```

The changes are:
- ✅ Database: Already migrated
- ✅ Backend: No code changes
- ✅ Mobile: Just JavaScript (Metro reload is enough)

---

## 🎯 Permission Logic

### Mobile Permission Check
```javascript
// In SideMenu.js
const canViewApprovals = hasPermission('view_approvals');
```
This checks if user has the **mobile permission** to see the menu item.

### Backend API Check
```php
// In ApprovalsAPIController.php
$hasLevel1Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
$hasLevel2Rights = RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);

if (!$hasLevel1Rights && !$hasLevel2Rights) {
    return response()->json(['success' => false, 'message' => 'You do not have approval rights'], 403);
}
```
This checks if user has **actual approval rights** (L1 or L2).

### Combined Logic
1. **Mobile Permission** (`view_approvals`) → Shows/hides menu item
2. **Approval Rights** (L1 or L2) → Determines what items they can see and approve
3. **Routing Rules** (Request Settings) → Determines which items are assigned to them

---

## 📊 Permission Hierarchy

```
User has role → Role has mobile permissions → Mobile app checks permissions
                ↓
User has role → Role has approval level (L1/L2) → Backend checks approval rights
                ↓
Category has routing rules → Virtual assignment → Dashboard filters items
```

---

## 🧪 Testing Checklist

### Test 1: User WITH Mobile Permission
- [ ] Assign `view_approvals` mobile permission to role
- [ ] User logs out and back in
- [ ] Opens side menu in Store Mode
- [ ] **Expected**: Sees "Approvals" menu item

### Test 2: User WITHOUT Mobile Permission
- [ ] Remove `view_approvals` mobile permission from role
- [ ] User logs out and back in
- [ ] Opens side menu in Store Mode
- [ ] **Expected**: Does NOT see "Approvals" menu item

### Test 3: User WITH Mobile Permission but NO Approval Rights
- [ ] Assign `view_approvals` mobile permission
- [ ] Remove from L1 and L2 approval levels
- [ ] User opens Approvals screen
- [ ] **Expected**: API returns 403 error "You do not have approval rights"

### Test 4: User WITH Both
- [ ] Assign `view_approvals` mobile permission
- [ ] Assign to L1 or L2 approval level
- [ ] Configure routing rules in Request Settings
- [ ] User opens Approvals screen
- [ ] **Expected**: Sees their assigned pending items

---

## 🔍 Troubleshooting

### Issue: Approvals menu not showing
**Check**:
1. ✅ User's role has `view_approvals` mobile permission
2. ✅ User logged out and back in (to refresh permissions)
3. ✅ In Store Mode (not Rider Mode)
4. ✅ Migration ran successfully (check database)

**Verify in console**:
```javascript
// In mobile app console
console.log('Permissions:', permissions);
// Should include 'view_approvals'
```

### Issue: Menu shows but API returns 403
**Check**:
1. ✅ User is assigned to L1 or L2 approval level
2. ✅ Check `t_sys_role_approval_level` table
3. ✅ Backend API checks approval rights

### Issue: Menu shows but no items
**Check**:
1. ✅ Routing rules configured in Request Settings
2. ✅ User is assigned to specific categories
3. ✅ There are actually pending items to approve

---

## 📚 Related Files

### Backend
- `app/Http/Controllers/API/ApprovalsAPIController.php` - API endpoint
- `app/Http/Controllers/SysAdmin/MobilePermissionController.php` - Permission management
- `database/migrations/add_approvals_mobile_permissions.sql` - Migration

### Mobile
- `src/components/SideMenu.js` - Menu item with permission check
- `src/screens/ApprovalsScreen.js` - Approvals screen
- `src/context/AppModeContext.js` - Permission context

### Web Admin
- `resources/views/pages/roles/mobile-permissions.blade.php` - Permission management UI
- Route: `/admin/roles/{id}/mobile-permissions`

---

## 🎓 For Future Permissions

To add new mobile permissions:

1. **Add to database**:
```sql
INSERT INTO t_sys_mobile_permission 
(permission_code, permission_name, permission_group, description, is_active)
VALUES 
('your_permission', 'Your Permission Name', 'your_group', 'Description', 1);
```

2. **Use in mobile app**:
```javascript
const canDoSomething = hasPermission('your_permission');
```

3. **Assign in web admin**:
- System Admin → Roles → Mobile App Permissions

---

## ✅ Summary

**What was done**:
1. ✅ Created `view_approvals` mobile permission
2. ✅ Updated SideMenu to check for it
3. ✅ Migration run successfully
4. ✅ Permission available in web admin

**What you need to do**:
1. 🔲 Assign permission to appropriate roles in web admin
2. 🔲 Reload Metro on mobile (press 'r')
3. 🔲 Test with users

**No rebuild needed** - just Metro reload!

---

**Status**: ✅ Complete  
**Date**: November 15, 2025  
**Ready for**: Testing

