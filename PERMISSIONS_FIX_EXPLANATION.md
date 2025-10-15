# 🔧 Permissions System - How It Works

## ❌ **What Was Wrong**

The original migration tried to insert into `t_sys_permissions`:
```sql
INSERT IGNORE INTO t_sys_permissions (permission_name, permission_description, ...)
```

**Error:** `Table 't_sys_permissions' doesn't exist`

---

## ✅ **How Permissions Actually Work**

Your system **does NOT use** a separate permissions table. Instead:

### **Table Structure:**
```
t_sys_role_permissions
├── id (PK)
├── role_id (FK → t_sys_role)
├── permission_key (e.g., 'view_orders', 'edit_products')
├── permission_name (e.g., 'View Orders', 'Edit Products')
├── is_allowed (boolean: 1 = granted, 0 = denied)
├── created_at
└── updated_at
```

### **How It's Checked:**
```php
// In User model
public function hasPermission(string $permissionKey): bool
{
    foreach ($this->roles as $role) {
        if (RolePermissionModel::hasPermission($role->id, $permissionKey)) {
            return true;
        }
    }
    return false;
}

// Usage in controllers
if (!auth()->user()->hasPermission('approve_invoice_ledger_adjustments')) {
    return response()->json(['error' => 'Unauthorized'], 403);
}
```

---

## ✅ **Corrected Migration**

```sql
-- Add permission to admin, super_admin, and manager roles
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'approve_invoice_ledger_adjustments' as permission_key,
    'Approve Invoice Ledger Adjustments (L1/L2)' as permission_name,
    1 as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE r.type IN ('admin', 'super_admin', 'manager')
AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'approve_invoice_ledger_adjustments'
);
```

### **What This Does:**
1. ✅ Selects all roles where `type` is admin, super_admin, or manager
2. ✅ Adds the permission `approve_invoice_ledger_adjustments` to each
3. ✅ Sets `is_allowed = 1` (granted)
4. ✅ Uses `NOT EXISTS` to avoid duplicates if run multiple times

---

## 📝 **How to Use the Permission**

### **In Controllers:**
```php
// Check if user has permission (checks all their roles)
if (!auth()->user()->hasPermission('approve_invoice_ledger_adjustments')) {
    abort(403, 'Unauthorized to approve ledger adjustments');
}
```

### **In Blade Templates:**
```blade
@can('approve_invoice_ledger_adjustments')
    <button>Approve Adjustment</button>
@endcan
```

### **In Routes (Middleware):**
```php
Route::post('/adjustments/{id}/approve', [Controller::class, 'approve'])
    ->middleware('permission:approve_invoice_ledger_adjustments');
```

---

## 🔍 **How to Verify**

After running the migration, check:

```sql
-- See which roles have the permission
SELECT 
    r.role_name,
    r.type,
    rp.permission_key,
    rp.permission_name,
    rp.is_allowed
FROM t_sys_role_permissions rp
INNER JOIN t_sys_role r ON rp.role_id = r.id
WHERE rp.permission_key = 'approve_invoice_ledger_adjustments';
```

**Expected Output:**
| role_name | type | permission_key | permission_name | is_allowed |
|-----------|------|----------------|-----------------|------------|
| Admin | admin | approve_invoice_ledger_adjustments | Approve Invoice Ledger... | 1 |
| Manager | manager | approve_invoice_ledger_adjustments | Approve Invoice Ledger... | 1 |
| Super Admin | super_admin | approve_invoice_ledger_adjustments | Approve Invoice Ledger... | 1 |

---

## 🎯 **Key Differences from Traditional Systems**

### **Traditional (Many Systems):**
```
t_permissions (id, name, description)
       ↓
t_role_permissions (role_id, permission_id)
```

### **Your System (Simpler):**
```
t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed)
```

**Benefits:**
- ✅ Faster lookups (one table instead of two)
- ✅ Role-specific permission names (can vary per role)
- ✅ Easy to add new permissions (just INSERT)
- ✅ No need to manage a separate permissions table

**Trade-offs:**
- ⚠️ Permission definitions duplicated per role
- ⚠️ Renaming a permission requires updating all roles
- ⚠️ No central "all available permissions" list

---

## 📦 **How to Add New Permissions in Future**

Always use this pattern:

```sql
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id,
    'your_new_permission_key' as permission_key,
    'Your New Permission Display Name' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin') THEN 1  -- Always grant to admin
        WHEN r.type = 'manager' THEN 1                   -- Grant to manager
        WHEN r.type = 'rider' THEN 0                     -- Deny to rider
        ELSE 0                                            -- Deny to others
    END as is_allowed,
    NOW(),
    NOW()
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'your_new_permission_key'
);
```

---

## ✅ **Updated Migration Ready**

The `add_ledger_adjustments_table.sql` migration is now **corrected** and will:
1. ✅ Create the table
2. ✅ Add all foreign keys
3. ✅ Add permission to appropriate roles (admin, super_admin, manager)
4. ✅ Verify everything worked

**Run it now!** 🚀

