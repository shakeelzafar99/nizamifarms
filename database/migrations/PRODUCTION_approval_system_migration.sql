-- =====================================================
-- PRODUCTION MIGRATION: Approval Routing System
-- Date: 2025-11-15
-- Description: Complete migration for multi-level approval system
-- Note: This script uses the currently selected database schema
-- =====================================================

-- =====================================================
-- PART 1: Update approval_status ENUM to support multi-level approvals
-- =====================================================

SELECT '--- Step 1: Updating t_fin_ledger approval_status ENUM ---' as '';

ALTER TABLE t_fin_ledger 
MODIFY COLUMN approval_status ENUM('pending', 'pending_l1', 'pending_l2', 'approved', 'rejected', 'reversed') 
DEFAULT 'approved'
COMMENT 'pending/pending_l1 = L1 approval needed, pending_l2 = L2 approval needed';

SELECT '✓ approval_status column updated successfully' as 'Status';

-- =====================================================
-- PART 2: Create Approval Routing Tables
-- =====================================================

SELECT '--- Step 2: Creating t_req_approval_rules table ---' as '';

CREATE TABLE IF NOT EXISTS `t_req_approval_rules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rule_name` VARCHAR(255) NOT NULL COMMENT 'Descriptive name for the rule',
    `area_type` ENUM('request_category', 'ledger_transaction', 'ledger_adjustment') NOT NULL COMMENT 'Type of approval area',
    `area_identifier` VARCHAR(100) NOT NULL COMMENT 'Category code, transaction type, or adjustment type',
    `approval_level` TINYINT NOT NULL COMMENT '1 or 2',
    `payment_source_account_id` INT NULL COMMENT 'Filter by payment source account (t_fin_accounts.id)',
    `payment_mode` ENUM('cash', 'online') NULL COMMENT 'Filter by payment mode',
    `min_amount` DECIMAL(15,2) NULL COMMENT 'Minimum amount for this rule',
    `max_amount` DECIMAL(15,2) NULL COMMENT 'Maximum amount for this rule',
    `assignment_strategy` ENUM('single_primary', 'round_robin', 'all_can_act') DEFAULT 'single_primary' COMMENT 'How to assign tasks',
    `priority` INT DEFAULT 100 COMMENT 'Lower number = higher priority when multiple rules match',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    INDEX `idx_area` (`area_type`, `area_identifier`),
    INDEX `idx_level` (`approval_level`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Defines approval routing rules for requests and ledger transactions';

SELECT '✓ t_req_approval_rules table created successfully' as 'Status';

SELECT '--- Step 3: Creating t_req_approval_rule_assignees table ---' as '';

CREATE TABLE IF NOT EXISTS `t_req_approval_rule_assignees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rule_id` INT NOT NULL COMMENT 'FK to t_req_approval_rules(id)',
    `user_id` INT NOT NULL COMMENT 'FK to t_sys_user(id)',
    `is_primary` TINYINT(1) DEFAULT 0 COMMENT 'Primary assignee for this rule',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rule` (`rule_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_primary` (`is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Maps users to approval rules for assignment';

SELECT '✓ t_req_approval_rule_assignees table created successfully' as 'Status';

-- =====================================================
-- PART 3: Add columns to existing tables (if they don't exist)
-- =====================================================

SELECT '--- Step 4: Adding columns to t_req_master ---' as '';

-- Check and add level_1_assigned_to
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'level_1_assigned_to'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE t_req_master ADD COLUMN level_1_assigned_to INT NULL COMMENT "User assigned for L1 approval" AFTER payment_source_account_id',
    'SELECT "Column level_1_assigned_to already exists" as Info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add level_2_assigned_to
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'level_2_assigned_to'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE t_req_master ADD COLUMN level_2_assigned_to INT NULL COMMENT "User assigned for L2 approval" AFTER level_1_assigned_to',
    'SELECT "Column level_2_assigned_to already exists" as Info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add order_id
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'order_id'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE t_req_master ADD COLUMN order_id INT NULL COMMENT "FK to t_crm_prod_order for invoice approvals" AFTER level_2_assigned_to',
    'SELECT "Column order_id already exists" as Info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ t_req_master columns added/verified' as 'Status';

SELECT '--- Step 5: Adding invoice_request_id to t_crm_prod_order ---' as '';

-- Check and add invoice_request_id
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_crm_prod_order' 
    AND COLUMN_NAME = 'invoice_request_id'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE t_crm_prod_order ADD COLUMN invoice_request_id INT NULL COMMENT "FK to t_req_master for invoice approval request"',
    'SELECT "Column invoice_request_id already exists" as Info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ t_crm_prod_order column added/verified' as 'Status';

-- =====================================================
-- PART 4: Ensure invoice_approval category exists
-- =====================================================

SELECT '--- Step 6: Ensuring invoice_approval category exists ---' as '';

INSERT IGNORE INTO t_req_category (category_code, category_name, icon, color_class, description, is_active, created_at, updated_at)
VALUES (
    'invoice_approval',
    'Invoice Approval',
    'receipt',
    'bg-blue-100 text-blue-800',
    'Approval workflow for online invoices',
    1,
    NOW(),
    NOW()
);

SELECT '✓ invoice_approval category ensured' as 'Status';

-- =====================================================
-- SUMMARY
-- =====================================================

SELECT '========================================' as '';
SELECT '✓ MIGRATION COMPLETED SUCCESSFULLY' as 'Status';
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'Changes Applied:' as '';
SELECT '1. Updated t_fin_ledger.approval_status to support pending_l1 and pending_l2' as '';
SELECT '2. Created t_req_approval_rules table' as '';
SELECT '3. Created t_req_approval_rule_assignees table' as '';
SELECT '4. Added level_1_assigned_to, level_2_assigned_to, order_id to t_req_master' as '';
SELECT '5. Added invoice_request_id to t_crm_prod_order' as '';
SELECT '6. Ensured invoice_approval category exists' as '';
SELECT '' as '';
SELECT 'Next Steps:' as '';
SELECT '- Configure approval routing in Request Settings UI' as '';
SELECT '- Test the approval flow with a new online invoice' as '';
SELECT '- Verify multi-level approvals work correctly' as '';

