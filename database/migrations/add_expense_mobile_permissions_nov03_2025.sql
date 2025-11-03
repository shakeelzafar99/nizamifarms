-- =====================================================
-- Add Expense Management Mobile Permissions
-- Date: November 3, 2025
-- Purpose: Add mobile permissions for expense management
-- =====================================================

-- Insert Expense Management Permissions
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_expenses', 'View Expenses', 'store_mode_expenses', 'Can view expense management dashboard in store mode', 30),
('approve_expenses', 'Approve Expenses', 'store_mode_expenses', 'Can approve pending expense requests', 31),
('settle_expenses', 'Settle Expenses', 'store_mode_expenses', 'Can settle approved expenses', 32)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    display_order = VALUES(display_order);

-- Grant Expense Management permissions to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_group = 'store_mode_expenses'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- Verification Queries
-- =====================================================

-- Check inserted permissions
SELECT 
    permission_code,
    permission_name,
    permission_group,
    display_order
FROM t_sys_mobile_permission
WHERE permission_group = 'store_mode_expenses'
ORDER BY display_order;

-- Check admin role permissions
SELECT 
    r.role_name,
    mp.permission_code,
    mp.permission_name
FROM t_sys_role r
JOIN t_sys_role_mobile_permission rmp ON r.id = rmp.role_id
JOIN t_sys_mobile_permission mp ON rmp.mobile_permission_id = mp.id
WHERE r.id = 1 AND mp.permission_group = 'store_mode_expenses'
ORDER BY mp.display_order;

-- =====================================================
-- Rollback (if needed)
-- =====================================================
-- DELETE FROM t_sys_role_mobile_permission 
-- WHERE mobile_permission_id IN (
--     SELECT id FROM t_sys_mobile_permission WHERE permission_group = 'store_mode_expenses'
-- );
-- DELETE FROM t_sys_mobile_permission WHERE permission_group = 'store_mode_expenses';

