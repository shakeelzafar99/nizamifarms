-- =====================================================
-- Add Delivery Regions Mobile Permission
-- Date: March 30, 2026
-- Description: Adds view_delivery_regions permission for mobile & web sidebar.
--              Granted by default to Taimur and Management roles.
--              Other roles can be enabled from Mobile Permissions settings.
-- =====================================================

-- 1. Insert the new mobile permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order, is_active) VALUES
('view_delivery_regions', 'View Delivery Regions', 'store_mode_orders', 'Can access Delivery Regions page to manage regions, polygons, rider mapping & auto-assign', 15, 1)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- 2. Grant to Taimur role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_delivery_regions'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 3. Grant to Management role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) LIKE '%management%'
AND p.permission_code = 'view_delivery_regions'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 4. Grant to Admin role (role_id = 1) as fallback
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'view_delivery_regions'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 5. Verify
SELECT 
    mp.permission_code,
    mp.permission_name,
    mp.permission_group,
    GROUP_CONCAT(r.urole_name ORDER BY r.urole_name) as assigned_roles
FROM t_sys_mobile_permission mp
LEFT JOIN t_sys_role_mobile_permission rmp ON mp.id = rmp.mobile_permission_id
LEFT JOIN t_sys_role r ON rmp.role_id = r.id
WHERE mp.permission_code = 'view_delivery_regions'
GROUP BY mp.id;
