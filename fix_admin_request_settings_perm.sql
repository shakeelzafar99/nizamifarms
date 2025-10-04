-- Fix: Ensure manage_request_settings permission exists for admin roles
-- This permission should have been there but may be missing

-- Add manage_request_settings permission to all admin/super_admin roles if missing
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'manage_request_settings' as permission_key,
    'Manage Request Settings' as permission_name,
    1 as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE r.type IN ('admin', 'super_admin')
AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id 
    AND permission_key = 'manage_request_settings'
);

-- Verify the fix
SELECT 
    r.urole_name,
    r.type,
    rp.permission_key,
    rp.is_allowed
FROM t_sys_role r
LEFT JOIN t_sys_role_permissions rp ON rp.role_id = r.id AND rp.permission_key = 'manage_request_settings'
WHERE r.type IN ('admin', 'super_admin', 'manager')
ORDER BY r.type, r.urole_name;

