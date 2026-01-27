-- =====================================================
-- Migration: Add Reports Mobile Permission
-- Created: January 26, 2026
-- =====================================================

-- Insert the new mobile permission for reports
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_reports', 'View Reports', 'store_mode_reports', 'Can view Monthly Summary and Daily Transaction Reports in Store Mode', 17)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- Grant to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'view_reports'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Taimur role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_reports'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Store Manager role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE r.urole_name LIKE '%store%manager%' 
AND p.permission_code = 'view_reports'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to anyone who has view_nf_ledger permission (they're likely to need reports access)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT rmp.role_id, 
       (SELECT id FROM t_sys_mobile_permission WHERE permission_code = 'view_reports')
FROM t_sys_role_mobile_permission rmp
JOIN t_sys_mobile_permission mp ON rmp.mobile_permission_id = mp.id
WHERE mp.permission_code = 'view_nf_ledger'
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
-- WHERE mp.permission_code = 'view_reports'
-- GROUP BY mp.id;
