-- Add New Permissions for Enhanced Role Control
-- Run this SQL to add all new permissions to existing roles

-- Add new permissions to all roles with appropriate defaults

-- For ALL roles: Add the new permissions
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_shopify_orders' as permission_key,
    'View Shopify Orders' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0  -- Riders: NO access to Shopify orders
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_shopify_orders'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_invoices' as permission_key,
    'View Invoices' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager', 'rider') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_invoices'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_all_invoices' as permission_key,
    'View All Invoices (vs own orders)' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0  -- Riders: See only invoices for their assigned orders
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_all_invoices'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_open_quantities' as permission_key,
    'View Open Order Quantities' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0  -- Riders: NO access to open quantities page
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_open_quantities'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_riders' as permission_key,
    'View Riders List' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager', 'rider') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_riders'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_all_riders' as permission_key,
    'View All Riders (vs only self)' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0  -- Riders: See only themselves in the riders list
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_all_riders'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'edit_riders' as permission_key,
    'Edit Rider Profiles' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'edit_riders'
);

-- Now add view_all_requests if it doesn't exist (from earlier fix)
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_all_requests' as permission_key,
    'View All Requests (vs own)' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_all_requests'
);

-- Verify the new permissions
SELECT 
    r.urole_name,
    r.type,
    GROUP_CONCAT(
        CASE WHEN rp.is_allowed = 1 THEN rp.permission_key ELSE NULL END
        ORDER BY rp.permission_key
        SEPARATOR ', '
    ) as allowed_permissions
FROM t_sys_role r
LEFT JOIN t_sys_role_permissions rp ON rp.role_id = r.id
WHERE rp.permission_key IN (
    'view_shopify_orders', 
    'view_invoices', 
    'view_all_invoices', 
    'view_open_quantities',
    'view_riders',
    'view_all_riders',
    'edit_riders',
    'view_all_requests'
)
GROUP BY r.id, r.urole_name, r.type
ORDER BY r.type, r.urole_name;

