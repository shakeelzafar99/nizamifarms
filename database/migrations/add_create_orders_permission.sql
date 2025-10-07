-- Add create_orders permission to existing roles
-- Run this SQL in your MySQL workbench or via artisan

-- This adds the create_orders permission to all existing roles
-- Riders will get it as FALSE (denied), Managers/Admins will get it as TRUE (allowed)

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id,
    'create_orders' as permission_key,
    'Create New Orders' as permission_name,
    CASE 
        WHEN r.type = 'rider' THEN 0  -- Riders cannot create orders
        ELSE 1  -- Managers, Admins, Super Admins can create orders
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 
    FROM t_sys_role_permissions rp 
    WHERE rp.role_id = r.id 
    AND rp.permission_key = 'create_orders'
);

-- Verify the changes
SELECT 
    r.urole_name as role_name,
    r.type as role_type,
    rp.permission_key,
    rp.permission_name,
    rp.is_allowed
FROM t_sys_role r
LEFT JOIN t_sys_role_permissions rp ON r.id = rp.role_id
WHERE rp.permission_key = 'create_orders'
ORDER BY r.type, r.urole_name;
