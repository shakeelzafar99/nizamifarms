-- =====================================================
-- Add Expense All Payment Sources Mobile Permission
-- Date: January 29, 2026
-- Purpose: Add mobile permission to view/create expenses from all payment sources
-- Users WITHOUT this permission: See only EXP_FUND expenses (default behavior)
-- Users WITH this permission: See all sources (EXP_FUND, NF_CASH, ONLINE) with filter
-- 
-- NOTE: This permission is NOT auto-granted to Admin role.
-- Assign manually via Mobile Permissions UI to specific roles/users.
-- =====================================================

-- Insert the new permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('expense_all_payment_sources', 'All Payment Sources', 'store_mode_expenses', 'Can view/create expenses from all payment sources (EXP_FUND, NF_CASH, ONLINE). Without this, only EXP_FUND expenses are visible.', 33)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    display_order = VALUES(display_order);

-- ⚠️ NOT granting to Admin role - assign manually to specific roles
-- Your exp fund manager (who is also admin) will NOT get this permission automatically

-- Grant ONLY to Taimur role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'expense_all_payment_sources'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================

SELECT '--- Permission Created ---' as '';
SELECT permission_code, permission_name, permission_group, description 
FROM t_sys_mobile_permission 
WHERE permission_code = 'expense_all_payment_sources';

SELECT '--- Roles with this Permission ---' as '';
SELECT r.urole_name, p.permission_code
FROM t_sys_role r
JOIN t_sys_role_mobile_permission rmp ON r.id = rmp.role_id
JOIN t_sys_mobile_permission p ON rmp.mobile_permission_id = p.id
WHERE p.permission_code = 'expense_all_payment_sources';

-- =====================================================
-- Verify system accounts exist for payment sources
-- =====================================================
SELECT '--- System Accounts for Payment Sources ---' as '';
SELECT id, account_code, account_name, account_type, current_balance 
FROM t_fin_accounts 
WHERE account_code IN ('EXP_FUND', 'NF_CASH', 'ONLINE')
ORDER BY FIELD(account_code, 'EXP_FUND', 'NF_CASH', 'ONLINE');

-- =====================================================
-- HOW TO MANUALLY GRANT TO OTHER ROLES
-- =====================================================
-- Option 1: Use the Mobile Permissions UI in the web app
--   Go to: Roles > Select Role > Mobile Permissions
--   Check "All Payment Sources" under Store Mode - Expenses
--
-- Option 2: SQL (replace ROLE_NAME with the actual role name)
-- INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
-- SELECT r.id, p.id 
-- FROM t_sys_role r 
-- CROSS JOIN t_sys_mobile_permission p 
-- WHERE r.urole_name = 'ROLE_NAME'
-- AND p.permission_code = 'expense_all_payment_sources'
-- ON DUPLICATE KEY UPDATE role_id = role_id;
