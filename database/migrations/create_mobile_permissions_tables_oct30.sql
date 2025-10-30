-- =====================================================
-- Mobile Permissions System
-- Date: October 30, 2025
-- Purpose: Add mobile app permissions management
-- =====================================================

-- Table 1: Mobile Permissions
CREATE TABLE IF NOT EXISTS t_sys_mobile_permission (
    id INT PRIMARY KEY AUTO_INCREMENT,
    permission_code VARCHAR(100) UNIQUE NOT NULL,
    permission_name VARCHAR(255) NOT NULL,
    permission_group VARCHAR(100) NOT NULL COMMENT 'store_mode, rider_mode, etc.',
    description TEXT,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_permission_group (permission_group),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 2: Role-Mobile Permission Mapping
CREATE TABLE IF NOT EXISTS t_sys_role_mobile_permission (
    id INT PRIMARY KEY AUTO_INCREMENT,
    role_id INT NOT NULL,
    mobile_permission_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES t_sys_role(id) ON DELETE CASCADE,
    FOREIGN KEY (mobile_permission_id) REFERENCES t_sys_mobile_permission(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role_id, mobile_permission_id),
    INDEX idx_role_id (role_id),
    INDEX idx_permission_id (mobile_permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert Initial Mobile Permissions
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
-- Store Mode Access
('access_store_mode', 'Access Store Mode', 'store_mode', 'Can switch to and use Store Mode in mobile app', 1),

-- Open Orders Management
('view_open_orders', 'View Open Orders', 'store_mode_orders', 'Can view open orders list in store mode', 10),
('assign_riders', 'Assign Riders to Orders', 'store_mode_orders', 'Can assign riders to orders', 11),
('change_order_status', 'Change Order Status', 'store_mode_orders', 'Can change order status', 12),
('enter_packet_info', 'Enter/Edit Packet Information', 'store_mode_orders', 'Can enter and edit packet information for orders', 13),

-- Open Order Quantities
('view_open_quantities', 'View Open Order Quantities', 'store_mode_quantities', 'Can view open order quantities and drill down', 20),

-- Future Features (placeholder)
('manage_store_expenses', 'Manage Store Expenses', 'store_mode_future', 'Can manage expenses in store mode (future feature)', 90),
('view_store_reports', 'View Store Reports', 'store_mode_future', 'Can view store reports (future feature)', 91);

-- Grant Store Mode permissions to Admin role (role_id = 1, adjust if needed)
-- You can modify this based on your actual admin role ID
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_group LIKE 'store_mode%'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- Verification Queries
-- =====================================================

-- Check created tables
SELECT 
    TABLE_NAME, 
    TABLE_ROWS, 
    CREATE_TIME 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME IN ('t_sys_mobile_permission', 't_sys_role_mobile_permission');

-- Check inserted permissions
SELECT 
    permission_group,
    COUNT(*) as permission_count
FROM t_sys_mobile_permission
GROUP BY permission_group
ORDER BY display_order;

-- Check admin role permissions (adjust role_id if needed)
SELECT 
    r.role_name,
    COUNT(rmp.id) as mobile_permissions_count
FROM t_sys_role r
LEFT JOIN t_sys_role_mobile_permission rmp ON r.id = rmp.role_id
WHERE r.id = 1
GROUP BY r.id, r.role_name;

-- =====================================================
-- Rollback (if needed)
-- =====================================================
-- DROP TABLE IF EXISTS t_sys_role_mobile_permission;
-- DROP TABLE IF EXISTS t_sys_mobile_permission;

