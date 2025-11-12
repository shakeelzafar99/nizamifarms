-- =====================================================
-- Add Vendor Management Mobile Permissions
-- Date: November 12, 2025
-- Purpose: Add mobile permissions for vendor management
-- =====================================================

-- Insert Vendor Management Permissions
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_vendors', 'View Vendors', 'store_mode_vendors', 'Can view vendor list and details in store mode', 40),
('manage_vendor_transactions', 'Manage Vendor Transactions', 'store_mode_vendors', 'Can record purchases and payments for vendors', 41),
('manage_vendor_products', 'Manage Vendor Products', 'store_mode_vendors', 'Can add/edit products for weight-based vendors', 42)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    display_order = VALUES(display_order);

-- Grant Vendor Management permissions to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_group = 'store_mode_vendors'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- Verification Queries
-- =====================================================

-- Check inserted permissions
SELECT 
    permission_code,
    permission_name,
    permission_group,
    display_order
FROM t_sys_mobile_permission
WHERE permission_group = 'store_mode_vendors'
ORDER BY display_order;

-- Check admin role permissions
SELECT 
    r.urole_name,
    mp.permission_code,
    mp.permission_name
FROM t_sys_role r
JOIN t_sys_role_mobile_permission rmp ON r.id = rmp.role_id
JOIN t_sys_mobile_permission mp ON rmp.mobile_permission_id = mp.id
WHERE r.id = 1 AND mp.permission_group = 'store_mode_vendors'
ORDER BY mp.display_order;

-- =====================================================
-- Rollback (if needed)
-- =====================================================
-- DELETE FROM t_sys_role_mobile_permission 
-- WHERE mobile_permission_id IN (
--     SELECT id FROM t_sys_mobile_permission WHERE permission_group = 'store_mode_vendors'
-- );
-- DELETE FROM t_sys_mobile_permission WHERE permission_group = 'store_mode_vendors';

