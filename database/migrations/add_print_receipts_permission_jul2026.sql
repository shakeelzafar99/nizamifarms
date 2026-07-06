-- =====================================================
-- Migration: Add print_receipts permission
-- Date: 2026-07-05
-- Purpose: Gate the Bluetooth thermal-printing feature (store mode). A user with
--          this permission sees "Printer Settings" in the side menu and (later)
--          the Print button on store orders. Everyone else's app is unchanged.
--
-- Safe + re-runnable. Run on LOCAL and PROD. No behaviour change until the new
-- mobile build ships AND a role is granted this permission. Grant additional store
-- roles via the web Roles screen. This is also the instant kill-switch: revoke the
-- permission to hide the whole feature with no rebuild.
-- =====================================================

-- STEP 1: Create the mobile permission (store-mode group).
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('print_receipts', 'Print Receipts', 'store_mode',
        'Can connect a Bluetooth receipt printer and print order receipts and package labels.', 71)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), display_order = VALUES(display_order);

-- STEP 2: Grant to Admin by default (owner grants other store roles via the UI).
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE LOWER(r.urole_name) IN ('admin')
AND p.permission_code = 'print_receipts'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- VERIFICATION (expect at least Admin):
-- SELECT r.urole_name, p.permission_code
-- FROM t_sys_role_mobile_permission rpm
-- JOIN t_sys_role r ON r.id = rpm.role_id
-- JOIN t_sys_mobile_permission p ON p.id = rpm.mobile_permission_id
-- WHERE p.permission_code = 'print_receipts'
-- ORDER BY r.urole_name;
