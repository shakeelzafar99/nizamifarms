-- =====================================================
-- Migration: Create Open Quantities Global Settings Table
-- Date: November 5, 2025
-- Description: Creates table to store global settings for
--              open order quantities including hierarchy levels
--              and status filters that apply to all users
-- =====================================================

-- Create global settings table
CREATE TABLE IF NOT EXISTS `t_crm_open_quantities_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `setting_type` ENUM('hierarchy', 'status_filter', 'other') DEFAULT 'other',
    `updated_by_user_id` INT UNSIGNED NULL,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`),
    INDEX `idx_setting_type` (`setting_type`),
    FOREIGN KEY (`updated_by_user_id`) REFERENCES `t_sys_user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Global settings for open order quantities view - applies to all users';

-- Insert default settings
INSERT INTO `t_crm_open_quantities_settings` (`setting_key`, `setting_value`, `setting_type`) VALUES
('hierarchy_levels', '["product_type", "product_name", "orders"]', 'hierarchy'),
('excluded_statuses', '["delivered", "completed", "cancelled", "refunded"]', 'status_filter')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- =====================================================
-- ROLLBACK SCRIPT (if needed)
-- =====================================================
-- To rollback this migration, run the following:
--
-- DROP TABLE IF EXISTS `t_crm_open_quantities_settings`;
-- =====================================================

-- Verification query (run after migration to verify)
-- SELECT * FROM t_crm_open_quantities_settings;

