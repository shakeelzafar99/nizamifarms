-- =====================================================
-- Migration: Campaign Management Tables + Permission
-- Created: March 8, 2026
-- =====================================================

-- 1. Create t_crm_campaigns table
CREATE TABLE IF NOT EXISTS t_crm_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
    filters_json JSON NULL,
    message_template TEXT NULL,
    tracking_type ENUM('general', 'products') NOT NULL DEFAULT 'general',
    tracked_product_ids JSON NULL,
    tracking_window_days INT NOT NULL DEFAULT 30,
    total_customers INT NOT NULL DEFAULT 0,
    sent_count INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create t_crm_campaign_customers table
CREATE TABLE IF NOT EXISTS t_crm_campaign_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    customer_id INT NOT NULL,
    status ENUM('pending', 'sent', 'skipped') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    sent_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_campaign_customer (campaign_id, customer_id),
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_customer (customer_id),
    CONSTRAINT fk_cc_campaign FOREIGN KEY (campaign_id) REFERENCES t_crm_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Insert view_campaigns mobile permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order) VALUES
('view_campaigns', 'View Campaigns', 'store_mode_campaigns', 'Can view and manage marketing campaigns in Store Mode', 22)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- 4. Grant to Taimur role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'view_campaigns'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- VERIFICATION (run to check)
-- =====================================================
-- SELECT 
--     mp.permission_code,
--     mp.permission_name,
--     GROUP_CONCAT(r.urole_name ORDER BY r.urole_name SEPARATOR ', ') as assigned_roles
-- FROM t_sys_mobile_permission mp
-- LEFT JOIN t_sys_role_mobile_permission rmp ON mp.id = rmp.mobile_permission_id
-- LEFT JOIN t_sys_role r ON rmp.role_id = r.id
-- WHERE mp.permission_code = 'view_campaigns'
-- GROUP BY mp.id;
