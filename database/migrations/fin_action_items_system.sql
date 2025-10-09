-- =====================================================
-- Financial Action Items System
-- =====================================================
-- Purpose: Track ledger issues that require manual attention
-- Use cases:
--   - Orders delivered without rider assignment
--   - Import records skipped due to employee not found
--   - Failed ledger postings
--   - Data quality issues requiring resolution
-- =====================================================

-- Create Action Items table
CREATE TABLE IF NOT EXISTS `t_fin_action_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_type` VARCHAR(50) NOT NULL COMMENT 'Type: missing_rider, employee_not_found, posting_failed, data_issue',
  `severity` ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
  `status` ENUM('pending', 'in_progress', 'resolved', 'dismissed') DEFAULT 'pending',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `related_entity_type` VARCHAR(50) NULL COMMENT 'order, import, ledger, etc.',
  `related_entity_id` INT NULL,
  `order_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_order if order-related',
  `import_log_id` INT NULL COMMENT 'FK to t_fin_import_log if import-related',
  `ledger_id` INT NULL COMMENT 'FK to t_fin_ledger if ledger-related',
  `suggested_action` TEXT NULL,
  `resolution_notes` TEXT NULL,
  `resolved_by` INT NULL COMMENT 'FK to t_sys_user',
  `resolved_at` TIMESTAMP NULL,
  `created_by` INT NOT NULL DEFAULT 1,
  `updated_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_type` (`item_type`),
  INDEX `idx_severity` (`severity`),
  INDEX `idx_order` (`order_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add setting to control automatic ledger posting
INSERT INTO `t_fin_config` (`config_key`, `config_value`, `description`, `created_at`)
VALUES 
('LEDGER_AUTO_POST_ENABLED', '0', 'Enable/disable automatic ledger posting when orders marked delivered (0=disabled, 1=enabled)', NOW())
ON DUPLICATE KEY UPDATE 
config_value = VALUES(config_value);

-- Add foreign keys (after verifying tables exist)
-- Note: Run these separately if tables don't exist yet

-- FK to users table
ALTER TABLE `t_fin_action_items`
ADD CONSTRAINT `fk_action_resolved_by`
FOREIGN KEY (`resolved_by`) REFERENCES `t_sys_user`(`id`)
ON DELETE SET NULL
ON UPDATE CASCADE;

ALTER TABLE `t_fin_action_items`
ADD CONSTRAINT `fk_action_created_by`
FOREIGN KEY (`created_by`) REFERENCES `t_sys_user`(`id`)
ON DELETE RESTRICT
ON UPDATE CASCADE;

-- FK to import log
ALTER TABLE `t_fin_action_items`
ADD CONSTRAINT `fk_action_import_log`
FOREIGN KEY (`import_log_id`) REFERENCES `t_fin_import_log`(`id`)
ON DELETE SET NULL
ON UPDATE CASCADE;

-- FK to ledger
ALTER TABLE `t_fin_action_items`
ADD CONSTRAINT `fk_action_ledger`
FOREIGN KEY (`ledger_id`) REFERENCES `t_fin_ledger`(`id`)
ON DELETE SET NULL
ON UPDATE CASCADE;

-- =====================================================
-- Verification Query
-- =====================================================
SELECT 
    'Action Items table created' as Status,
    COUNT(*) as ActionItemCount
FROM `t_fin_action_items`;

SELECT 
    config_key,
    config_value,
    description
FROM `t_fin_config`
WHERE config_key = 'LEDGER_AUTO_POST_ENABLED';

-- =====================================================
-- Sample Action Items (for testing)
-- =====================================================
-- Uncomment to insert sample data:
/*
INSERT INTO `t_fin_action_items` 
(`item_type`, `severity`, `title`, `description`, `related_entity_type`, `order_id`, `suggested_action`, `created_by`)
VALUES
('missing_rider', 'high', 'Order #9145 delivered without rider', 'Order was marked as delivered but no rider was assigned. Cannot post to employee cash account.', 'order', 9145, 'Assign a rider to this order and retry posting to ledger.', 1),
('employee_not_found', 'medium', 'Import skipped for Asim Tahir - Indrive', 'Employee name "Asim Tahir - Indrive" could not be matched to any user in the system.', 'import', null, 'Create user account for this employee or correct the name in the import file.', 1);
*/

