-- =====================================================
-- KHAAS MODE + CREATE EXPENSE CATEGORY PERMISSIONS
-- Created: February 9, 2026
-- =====================================================
-- 
-- This SQL adds:
-- ✅ access_khaas_mode mobile permission (for Khaas mode in mobile app)
-- ✅ create_expense_category mobile permission (for adding new expense types)
-- ✅ Grants both to Admin and Taimur roles
--
-- =====================================================

-- =====================================================
-- STEP 1: Add access_khaas_mode permission
-- =====================================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('access_khaas_mode', 'Access Khaas Mode', 'khaas_mode', 'Can switch to and use Khaas Mode in mobile app (view Khaas BU expenses, vendors, products)', 60)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description);

SELECT '✅ Step 1: Created access_khaas_mode mobile permission' as Status;


-- =====================================================
-- STEP 2: Add create_expense_category permission
-- =====================================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('create_expense_category', 'Create Expense Category', 'store_mode_finance', 'Can create new expense categories from mobile app', 55)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description);

SELECT '✅ Step 2: Created create_expense_category mobile permission' as Status;


-- =====================================================
-- STEP 3: Grant access_khaas_mode to Admin and Taimur roles
-- =====================================================

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') 
AND p.permission_code = 'access_khaas_mode'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 3: Granted access_khaas_mode to Admin and Taimur' as Status;


-- =====================================================
-- STEP 4: Grant create_expense_category to Taimur role ONLY
-- (Admin can also create categories for safety)
-- =====================================================

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') 
AND p.permission_code = 'create_expense_category'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 4: Granted create_expense_category to Admin and Taimur' as Status;


-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT '
=====================================
✅ KHAAS MODE PERMISSIONS COMPLETE
=====================================

New Mobile Permissions:
- access_khaas_mode: Can switch to Khaas Mode (granted to Admin + Taimur)
- create_expense_category: Can add new expense types (granted to Admin + Taimur)

HOW IT WORKS:
1. Khaas Mode: A new mobile mode (like Store Mode) that shows:
   - Expenses filtered for Khaas business unit (id=2)
   - Vendors filtered for Khaas business unit
   - Products/Inventory filtered for Khaas business unit

2. Create Expense Category: Only users with this permission see
   the "Add New Category..." option in the expense type dropdown

=====================================
' as Summary;

-- Show all mobile permissions
SELECT 'Mobile Permissions:' as '';
SELECT id, permission_code, permission_name, permission_group 
FROM t_sys_mobile_permission 
ORDER BY permission_group, display_order;

-- Show role-permission mappings for new permissions
SELECT 'Roles with new permissions:' as '';
SELECT r.urole_name, p.permission_code
FROM t_sys_role_mobile_permission rpm
JOIN t_sys_role r ON r.id = rpm.role_id
JOIN t_sys_mobile_permission p ON p.id = rpm.mobile_permission_id
WHERE p.permission_code IN ('access_khaas_mode', 'create_expense_category')
ORDER BY p.permission_code, r.urole_name;
