-- =====================================================
-- Migration: Add Khaas Store Inventory & Attendance Reports Permissions
-- Created: March 5, 2026
-- =====================================================

-- 1. Insert view_khaas_store_inventory permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_khaas_store_inventory', 'View Khaas Store Inventory', 'store_mode_khaas', 'Can view Khaas Store Inventory in Store Mode sidebar', 20)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- 2. Insert view_attendance_reports permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_attendance_reports', 'View Attendance Reports', 'store_mode_attendance', 'Can view monthly Attendance Reports (separate from daily Attendance)', 21)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- 3. Grant both to Taimur role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_khaas_store_inventory'
ON DUPLICATE KEY UPDATE role_id = role_id;

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_attendance_reports'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- VERIFICATION QUERY (Run to check results)
-- =====================================================
-- SELECT 
--     mp.permission_code,
--     mp.permission_name,
--     mp.description,
--     GROUP_CONCAT(r.urole_name ORDER BY r.urole_name SEPARATOR ', ') as assigned_roles
-- FROM t_sys_mobile_permission mp
-- LEFT JOIN t_sys_role_mobile_permission rmp ON mp.id = rmp.mobile_permission_id
-- LEFT JOIN t_sys_role r ON rmp.role_id = r.id
-- WHERE mp.permission_code IN ('view_khaas_store_inventory', 'view_attendance_reports')
-- GROUP BY mp.id;
