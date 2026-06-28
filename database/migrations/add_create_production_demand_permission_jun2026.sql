-- =====================================================
-- Migration: Add create_production_demand permission
-- Date: 2026-06-25
-- Purpose: A DEDICATED permission to create a production plan (demand) in
--          Khaas / Frozen mode — separate from approve_khaas_transfer.
--          Transfer acceptance stays intentionally broad (store-mode users
--          can accept transfers); plan CREATION is now its own clean gate.
--
-- Safe + re-runnable. Run on LOCAL and PROD. No behaviour changes until the
-- new web + mobile build ships.
-- =====================================================

-- STEP 1: Create the mobile permission (same group as the other Khaas perms).
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('create_production_demand', 'Create Production Plan', 'khaas_mode',
        'Can create a new production plan (demand) in Khaas/Frozen mode. Separate from approving transfers.', 62)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), display_order = VALUES(display_order);

-- STEP 2: Grant to Admin and Taimur roles (the admin roles) by default.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE LOWER(r.urole_name) IN ('admin', 'taimur')
AND p.permission_code = 'create_production_demand'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- VERIFICATION (expect at least Admin + Taimur):
-- SELECT r.urole_name, p.permission_code
-- FROM t_sys_role_mobile_permission rpm
-- JOIN t_sys_role r ON r.id = rpm.role_id
-- JOIN t_sys_mobile_permission p ON p.id = rpm.mobile_permission_id
-- WHERE p.permission_code = 'create_production_demand'
-- ORDER BY r.urole_name;
