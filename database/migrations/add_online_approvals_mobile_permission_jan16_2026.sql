-- =====================================================
-- Add Online Approvals Mobile Permission
-- Date: January 16, 2026
-- Description: Adds view_online_approvals permission for mobile app
--              to control access to the dedicated Online Approvals screen
-- =====================================================

-- =====================================================
-- 1. VIEW ONLINE APPROVALS PERMISSION
-- =====================================================

-- Insert the new mobile permission for online approvals
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_online_approvals', 'View Online Approvals', 'store_mode_approvals', 'Can view and approve online invoices in the dedicated Online Approvals screen in Store Mode', 14)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- Grant to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'view_online_approvals'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Taimur role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_online_approvals'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to anyone who has view_approvals permission (they should also get online approvals)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT rmp.role_id, 
       (SELECT id FROM t_sys_mobile_permission WHERE permission_code = 'view_online_approvals')
FROM t_sys_role_mobile_permission rmp
JOIN t_sys_mobile_permission mp ON rmp.mobile_permission_id = mp.id
WHERE mp.permission_code = 'view_approvals'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- VERIFICATION QUERY
-- Run this after the above to confirm permission was added
-- =====================================================
SELECT 
    mp.permission_code,
    mp.permission_name,
    mp.permission_group,
    mp.description,
    GROUP_CONCAT(r.urole_name ORDER BY r.urole_name SEPARATOR ', ') as assigned_roles
FROM t_sys_mobile_permission mp
LEFT JOIN t_sys_role_mobile_permission rmp ON mp.id = rmp.mobile_permission_id
LEFT JOIN t_sys_role r ON rmp.role_id = r.id
WHERE mp.permission_code = 'view_online_approvals'
GROUP BY mp.id, mp.permission_code, mp.permission_name, mp.permission_group, mp.description;
