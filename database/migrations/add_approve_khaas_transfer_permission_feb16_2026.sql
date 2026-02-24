-- =====================================================
-- Migration: Add approve_khaas_transfer permission
-- Date: 2026-02-16
-- Purpose: Admin-only permission for approving Khaas warehouse-to-shop transfers
-- =====================================================

-- STEP 1: Create the mobile permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('approve_khaas_transfer', 'Approve Khaas Transfers', 'khaas_mode', 'Can approve/reject warehouse-to-shop transfer requests in Khaas mode', 61)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description);

SELECT '✅ Step 1: Created approve_khaas_transfer mobile permission' as Status;

-- STEP 2: Grant to Admin and Taimur roles (these are the admin roles)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') 
AND p.permission_code = 'approve_khaas_transfer'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 2: Granted approve_khaas_transfer to Admin and Taimur roles' as Status;

-- VERIFICATION
SELECT 'Roles with approve_khaas_transfer permission:' as '';
SELECT r.urole_name, p.permission_code
FROM t_sys_role_mobile_permission rpm
JOIN t_sys_role r ON r.id = rpm.role_id
JOIN t_sys_mobile_permission p ON p.id = rpm.mobile_permission_id
WHERE p.permission_code = 'approve_khaas_transfer'
ORDER BY r.urole_name;
