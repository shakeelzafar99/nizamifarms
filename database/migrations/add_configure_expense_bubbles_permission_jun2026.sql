-- =====================================================
-- Add "Configure Expense Bubbles" mobile permission
-- Date: June 25, 2026
--
-- Gates the ⚙ gear on the mobile Create Request screen that lets an admin
-- choose which expense types appear as quick-select "bubbles" (and their order).
-- Granted to the Taimur and Admin roles by default.
--
-- Safe + re-runnable (ON DUPLICATE KEY UPDATE / idempotent assignment).
-- Run on LOCAL and PROD. No app behaviour changes until the new web + mobile
-- build ships.
-- =====================================================

-- 1) The permission itself.
INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order)
VALUES
    ('configure_expense_bubbles', 'Configure Expense Bubbles', 'store_mode_expenses',
     'Can choose which expense types show as quick-select bubbles on the Create Request screen', 34)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description     = VALUES(description),
    display_order   = VALUES(display_order);

-- 2) Grant it to the Taimur role (matched by name, case-insensitive) and the
--    Admin role (id = 1). Idempotent: re-running changes nothing.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, mp.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission mp
WHERE mp.permission_code = 'configure_expense_bubbles'
  AND (LOWER(r.urole_name) = 'taimur' OR r.id = 1)
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- Verify (expect at least the Taimur row):
-- SELECT r.urole_name, mp.permission_code
-- FROM t_sys_role r
-- JOIN t_sys_role_mobile_permission rmp ON rmp.role_id = r.id
-- JOIN t_sys_mobile_permission mp ON mp.id = rmp.mobile_permission_id
-- WHERE mp.permission_code = 'configure_expense_bubbles';
-- =====================================================
