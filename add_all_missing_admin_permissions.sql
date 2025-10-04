-- Add ALL Missing Permissions to Admin Roles
-- This ensures admin/super_admin roles have complete access to everything

-- Add all missing permissions to admin and super_admin roles
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    perm.key as permission_key,
    perm.name as permission_name,
    1 as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
CROSS JOIN (
    -- Core Access
    SELECT 'view_dashboard' as `key`, 'View Dashboard' as `name` UNION ALL
    
    -- Orders & Deliveries
    SELECT 'view_orders', 'View Orders' UNION ALL
    SELECT 'view_all_orders', 'View All Orders (vs own assigned)' UNION ALL
    SELECT 'edit_orders', 'Edit Orders' UNION ALL
    SELECT 'view_shopify_orders', 'View Shopify Orders' UNION ALL
    SELECT 'view_order_status', 'Manage Order Status' UNION ALL
    SELECT 'view_status_history', 'View Status History' UNION ALL
    SELECT 'assign_riders', 'Assign Riders to Orders' UNION ALL
    SELECT 'bulk_operations', 'Bulk Operations (status, rider assign)' UNION ALL
    
    -- Invoices & Quantities
    SELECT 'view_invoices', 'View Invoices' UNION ALL
    SELECT 'view_all_invoices', 'View All Invoices (vs own orders)' UNION ALL
    SELECT 'view_open_quantities', 'View Open Order Quantities' UNION ALL
    
    -- Riders
    SELECT 'view_riders', 'View Riders List' UNION ALL
    SELECT 'view_all_riders', 'View All Riders (vs only self)' UNION ALL
    SELECT 'edit_riders', 'Edit Rider Profiles' UNION ALL
    
    -- Customers & Products
    SELECT 'view_customers', 'View Customers' UNION ALL
    SELECT 'edit_customers', 'Edit Customers' UNION ALL
    SELECT 'view_products', 'View Products' UNION ALL
    SELECT 'edit_products', 'Edit Products' UNION ALL
    
    -- Attendance
    SELECT 'view_attendance', 'View Attendance' UNION ALL
    SELECT 'view_all_attendance', 'View All Attendance (vs own)' UNION ALL
    
    -- Requests & Approvals
    SELECT 'view_requests', 'View Requests' UNION ALL
    SELECT 'view_all_requests', 'View All Requests (vs own)' UNION ALL
    SELECT 'create_requests', 'Create Requests' UNION ALL
    SELECT 'approve_requests', 'Approve/Reject Requests' UNION ALL
    SELECT 'manage_request_settings', 'Manage Request Settings' UNION ALL
    
    -- Administration
    SELECT 'view_users', 'Manage Users' UNION ALL
    SELECT 'view_roles', 'Manage Roles' UNION ALL
    SELECT 'view_logs', 'View Error Logs' UNION ALL
    SELECT 'view_operations', 'Access Operations (imports, bulk actions)'
) as perm
WHERE r.type IN ('admin', 'super_admin')
AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id 
    AND permission_key = perm.key
);

-- Show summary of what was added
SELECT 
    'Permissions added for admin roles' as summary,
    COUNT(*) as permissions_added
FROM t_sys_role_permissions rp
JOIN t_sys_role r ON r.id = rp.role_id
WHERE r.type IN ('admin', 'super_admin')
AND rp.created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE);

-- Verify: Show all admin roles and their permission counts
SELECT 
    r.urole_name,
    r.type,
    COUNT(rp.permission_key) as total_permissions,
    SUM(CASE WHEN rp.is_allowed = 1 THEN 1 ELSE 0 END) as allowed_permissions
FROM t_sys_role r
LEFT JOIN t_sys_role_permissions rp ON rp.role_id = r.id
WHERE r.type IN ('admin', 'super_admin')
GROUP BY r.id, r.urole_name, r.type
ORDER BY r.type, r.urole_name;

-- List specific NEW permissions added
SELECT 
    r.urole_name,
    rp.permission_key,
    rp.permission_name,
    rp.is_allowed
FROM t_sys_role r
JOIN t_sys_role_permissions rp ON rp.role_id = r.id
WHERE r.type IN ('admin', 'super_admin')
AND rp.permission_key IN (
    'view_shopify_orders', 
    'view_all_invoices', 
    'view_open_quantities',
    'view_all_riders',
    'edit_riders',
    'view_invoices',
    'view_riders'
)
ORDER BY r.urole_name, rp.permission_key;

