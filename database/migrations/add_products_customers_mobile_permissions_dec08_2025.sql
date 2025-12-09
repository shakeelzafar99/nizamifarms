-- =====================================================
-- Add Products & Customers Mobile Permissions
-- Date: December 8, 2025
-- Description: Adds view_products and view_customers permissions for mobile app
-- =====================================================

-- =====================================================
-- 1. VIEW PRODUCTS PERMISSION
-- =====================================================

-- Insert the new mobile permission for products
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_products', 'View Products', 'store_mode_products', 'Can view and manage products, prices, and categories in Store Mode', 15)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- Grant to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'view_products'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Taimur role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_products'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Store Manager role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE r.urole_name LIKE '%store%manager%' 
AND p.permission_code = 'view_products'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- 2. VIEW CUSTOMERS PERMISSION
-- =====================================================

-- Insert the new mobile permission for customers
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_customers', 'View Customers', 'store_mode_customers', 'Can view customers, their orders, and create new invoices in Store Mode', 16)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- Grant to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'view_customers'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Taimur role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_customers'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Store Manager role if exists
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE r.urole_name LIKE '%store%manager%' 
AND p.permission_code = 'view_customers'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- VERIFICATION QUERY
-- Run this after the above to confirm permissions were added
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
WHERE mp.permission_code IN ('view_products', 'view_customers')
GROUP BY mp.id, mp.permission_code, mp.permission_name, mp.permission_group, mp.description
ORDER BY mp.display_order;

