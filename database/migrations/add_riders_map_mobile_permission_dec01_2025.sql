-- =====================================================
-- Add Riders Map Mobile Permission
-- Date: December 1, 2025
-- Purpose: Add permission to view riders map in Store Mode
-- =====================================================

-- Insert the new mobile permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_riders_map', 'View Riders Map', 'store_mode_orders', 'Can view riders map with live locations and order status in Store Mode', 14)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- Grant to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'view_riders_map'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Store Manager role if exists (typically role_id = 3)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE r.urole_name LIKE '%store%manager%' 
AND p.permission_code = 'view_riders_map'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- Verification Query
-- =====================================================
SELECT 
    mp.permission_code,
    mp.permission_name,
    mp.permission_group,
    GROUP_CONCAT(r.urole_name) as assigned_roles
FROM t_sys_mobile_permission mp
LEFT JOIN t_sys_role_mobile_permission rmp ON mp.id = rmp.mobile_permission_id
LEFT JOIN t_sys_role r ON rmp.role_id = r.id
WHERE mp.permission_code = 'view_riders_map'
GROUP BY mp.id;

