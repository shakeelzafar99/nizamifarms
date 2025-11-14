-- =====================================================
-- APPROVAL ROUTING SYSTEM MIGRATION - FIXED
-- =====================================================
-- Purpose: Add routing rules for approval assignments
-- Allows configuring which users should handle specific
-- approval types based on payment source, method, etc.
-- =====================================================
-- NOTE: No foreign key constraints (matching existing schema pattern)
-- =====================================================

-- =====================================================
-- 1. CREATE APPROVAL RULES TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `t_req_approval_rules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rule_name` VARCHAR(255) NOT NULL COMMENT 'Descriptive name for the rule',
    `area_type` ENUM('request_category', 'ledger_transaction', 'ledger_adjustment') NOT NULL COMMENT 'Type of approval area',
    `area_identifier` VARCHAR(100) NOT NULL COMMENT 'Category code, transaction type, or adjustment type',
    `approval_level` TINYINT NOT NULL COMMENT '1 or 2',
    
    -- Contextual filters (all optional)
    `payment_source_account_id` INT NULL COMMENT 'Filter by payment source account (t_fin_accounts.id)',
    `payment_mode` ENUM('cash', 'online') NULL COMMENT 'Filter by payment mode',
    `min_amount` DECIMAL(15,2) NULL COMMENT 'Minimum amount for this rule',
    `max_amount` DECIMAL(15,2) NULL COMMENT 'Maximum amount for this rule',
    
    -- Assignment strategy
    `assignment_strategy` ENUM('single_primary', 'round_robin', 'all_can_act') DEFAULT 'single_primary' COMMENT 'How to assign tasks',
    `priority` INT DEFAULT 100 COMMENT 'Lower number = higher priority when multiple rules match',
    
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    
    INDEX `idx_area` (`area_type`, `area_identifier`),
    INDEX `idx_level` (`approval_level`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_payment_source` (`payment_source_account_id`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '✓ Created t_req_approval_rules' as Status;

-- =====================================================
-- 2. CREATE APPROVAL RULE ASSIGNEES TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `t_req_approval_rule_assignees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rule_id` INT NOT NULL COMMENT 'Reference to t_req_approval_rules.id',
    `user_id` INT NOT NULL COMMENT 'User who should be assigned (t_sys_user.id)',
    `is_primary` TINYINT(1) DEFAULT 1 COMMENT 'Primary assignee (shown first)',
    `sequence_order` INT DEFAULT 0 COMMENT 'Order for round-robin assignment',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY `unique_rule_user` (`rule_id`, `user_id`),
    INDEX `idx_rule` (`rule_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '✓ Created t_req_approval_rule_assignees' as Status;

-- =====================================================
-- 3. ADD ASSIGNEE TRACKING TO REQUESTS
-- =====================================================
-- Check if columns already exist before adding
SET @exist_l1_assigned := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'level_1_assigned_to');

SET @exist_l2_assigned := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'level_2_assigned_to');

SET @exist_order_id := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'order_id');

SET @sql_l1 = IF(@exist_l1_assigned = 0,
    'ALTER TABLE `t_req_master` ADD COLUMN `level_1_assigned_to` INT NULL COMMENT ''User assigned to approve at L1'' AFTER `level_1_status`, ADD INDEX `idx_l1_assigned` (`level_1_assigned_to`)',
    'SELECT ''Column level_1_assigned_to already exists'' as Info');
PREPARE stmt FROM @sql_l1;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_l2 = IF(@exist_l2_assigned = 0,
    'ALTER TABLE `t_req_master` ADD COLUMN `level_2_assigned_to` INT NULL COMMENT ''User assigned to approve at L2'' AFTER `level_2_status`, ADD INDEX `idx_l2_assigned` (`level_2_assigned_to`)',
    'SELECT ''Column level_2_assigned_to already exists'' as Info');
PREPARE stmt FROM @sql_l2;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_order = IF(@exist_order_id = 0,
    'ALTER TABLE `t_req_master` ADD COLUMN `order_id` INT NULL COMMENT ''Reference to order for invoice approvals (t_crm_prod_order.id)'' AFTER `ledger_transaction_id`, ADD INDEX `idx_order` (`order_id`)',
    'SELECT ''Column order_id already exists'' as Info');
PREPARE stmt FROM @sql_order;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added assignee columns to t_req_master' as Status;

-- =====================================================
-- 4. ADD ASSIGNEE TRACKING TO LEDGER ADJUSTMENTS
-- =====================================================
-- Check if table exists first
SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_fin_ledger_adjustments');

SET @exist_adj_l1 := IF(@table_exists > 0,
    (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_fin_ledger_adjustments' 
    AND COLUMN_NAME = 'level_1_assigned_to'),
    0);

SET @exist_adj_l2 := IF(@table_exists > 0,
    (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_fin_ledger_adjustments' 
    AND COLUMN_NAME = 'level_2_assigned_to'),
    0);

SET @sql_adj_l1 = IF(@table_exists > 0 AND @exist_adj_l1 = 0,
    'ALTER TABLE `t_fin_ledger_adjustments` ADD COLUMN `level_1_assigned_to` INT NULL COMMENT ''User assigned to approve at L1'' AFTER `level_1_status`, ADD INDEX `idx_l1_assigned` (`level_1_assigned_to`)',
    'SELECT ''Table t_fin_ledger_adjustments does not exist or column already exists'' as Info');
PREPARE stmt FROM @sql_adj_l1;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_adj_l2 = IF(@table_exists > 0 AND @exist_adj_l2 = 0,
    'ALTER TABLE `t_fin_ledger_adjustments` ADD COLUMN `level_2_assigned_to` INT NULL COMMENT ''User assigned to approve at L2'' AFTER `level_2_status`, ADD INDEX `idx_l2_assigned` (`level_2_assigned_to`)',
    'SELECT ''Table t_fin_ledger_adjustments does not exist or column already exists'' as Info');
PREPARE stmt FROM @sql_adj_l2;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added assignee columns to t_fin_ledger_adjustments (if table exists)' as Status;

-- =====================================================
-- 5. ENSURE INVOICE_APPROVAL CATEGORY EXISTS
-- =====================================================
INSERT INTO t_req_category (category_code, category_name, description, icon, color_class, is_active, sequence_order, created_at)
SELECT * FROM (
    SELECT 
        'invoice_approval' as category_code,
        'Invoice Approval' as category_name,
        'Online invoices requiring approval before posting to ledger' as description,
        '📄' as icon,
        'bg-blue-100 text-blue-800' as color_class,
        1 as is_active,
        (SELECT COALESCE(MAX(sequence_order), 0) + 1 FROM t_req_category) as sequence_order,
        NOW() as created_at
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM t_req_category WHERE category_code = 'invoice_approval'
);

SELECT '✓ Ensured invoice_approval category exists' as Status;

-- Add approval config for invoice_approval (L1 required, L2 for ledger posting)
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at)
SELECT 
    id,
    1 as requires_level_1,
    0 as requires_level_2,  -- L2 will be for ledger approval, not request approval
    NULL as auto_approve_threshold,
    NOW() as created_at
FROM t_req_category 
WHERE category_code = 'invoice_approval'
AND NOT EXISTS (
    SELECT 1 FROM t_req_category_approval_config 
    WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'invoice_approval')
);

SELECT '✓ Added approval config for invoice_approval' as Status;

-- =====================================================
-- 6. ADD INVOICE REQUEST TRACKING TO ORDERS
-- =====================================================
SET @exist_invoice_req := (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_crm_prod_order' 
    AND COLUMN_NAME = 'invoice_request_id');

SET @sql_invoice_req = IF(@exist_invoice_req = 0,
    'ALTER TABLE `t_crm_prod_order` ADD COLUMN `invoice_request_id` INT NULL COMMENT ''Request for online invoice approval (t_req_master.id)'' AFTER `ledger_transaction_id`, ADD INDEX `idx_invoice_request` (`invoice_request_id`)',
    'SELECT ''Column invoice_request_id already exists'' as Info');
PREPARE stmt FROM @sql_invoice_req;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added invoice_request_id to t_crm_prod_order' as Status;

-- =====================================================
-- 7. VERIFICATION QUERIES
-- =====================================================

-- Check tables created
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('t_req_approval_rules', 't_req_approval_rule_assignees')
ORDER BY TABLE_NAME;

-- Check columns added to requests
SELECT 
    'Request assignee columns' as Check_Type,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 't_req_master'
AND COLUMN_NAME IN ('level_1_assigned_to', 'level_2_assigned_to', 'order_id')
ORDER BY ORDINAL_POSITION;

-- Check columns added to orders
SELECT 
    'Order invoice request column' as Check_Type,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 't_crm_prod_order'
AND COLUMN_NAME = 'invoice_request_id';

-- Check invoice_approval category
SELECT 
    'Invoice Approval Category' as Check_Type,
    c.id,
    c.category_code,
    c.category_name,
    c.is_active,
    CASE WHEN ac.requires_level_1 = 1 THEN '✓ L1' ELSE '✗' END as 'Level 1',
    CASE WHEN ac.requires_level_2 = 1 THEN '✓ L2' ELSE '✗' END as 'Level 2'
FROM t_req_category c
LEFT JOIN t_req_category_approval_config ac ON c.id = ac.category_id
WHERE c.category_code = 'invoice_approval';

SELECT '✅ Migration completed successfully!' as Status;
SELECT '⚠️  Next: Configure approval rules and assignees' as Next_Step;
SELECT 'ℹ️  See APPROVAL_ROUTING_QUICK_START.md for examples' as Documentation;

-- =====================================================
-- 8. EXAMPLE ROUTING RULES (COMMENTED OUT)
-- =====================================================
-- Uncomment and modify these examples to create your first rules

-- Example 1: Route online invoices to specific user
-- Replace USER_ID_HERE with actual user ID from t_sys_user
/*
INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_mode, assignment_strategy, priority, created_at, created_by)
VALUES 
('Online Invoices - L1', 'request_category', 'invoice_approval', 1, 'online', 'single_primary', 10, NOW(), 1);

SET @rule_id = LAST_INSERT_ID();

INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order, created_at)
VALUES (@rule_id, USER_ID_HERE, 1, 0, NOW());

SELECT CONCAT('✓ Created rule: Online Invoices → User ', USER_ID_HERE) as Status;
*/

-- Example 2: Route expenses from EXP_FUND to specific user
/*
INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_source_account_id, assignment_strategy, priority, created_at, created_by)
SELECT 
    'Expenses from EXP_FUND - L1',
    'request_category',
    'expense',
    1,
    (SELECT id FROM t_fin_accounts WHERE account_code = 'EXP_FUND'),
    'single_primary',
    10,
    NOW(),
    1
WHERE EXISTS (SELECT 1 FROM t_fin_accounts WHERE account_code = 'EXP_FUND');

SET @rule_id = LAST_INSERT_ID();

INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order, created_at)
VALUES (@rule_id, USER_ID_HERE, 1, 0, NOW());

SELECT CONCAT('✓ Created rule: Expenses from EXP_FUND → User ', USER_ID_HERE) as Status;
*/

-- Example 3: Route vendor payments from NF_CASH to specific user
/*
INSERT INTO t_req_approval_rules 
(rule_name, area_type, area_identifier, approval_level, payment_source_account_id, assignment_strategy, priority, created_at, created_by)
SELECT 
    'Vendor Payments from NF_CASH - L1',
    'ledger_transaction',
    'vendor_payment',
    1,
    (SELECT id FROM t_fin_accounts WHERE account_code = 'NF_CASH'),
    'single_primary',
    10,
    NOW(),
    1
WHERE EXISTS (SELECT 1 FROM t_fin_accounts WHERE account_code = 'NF_CASH');

SET @rule_id = LAST_INSERT_ID();

INSERT INTO t_req_approval_rule_assignees (rule_id, user_id, is_primary, sequence_order, created_at)
VALUES (@rule_id, USER_ID_HERE, 1, 0, NOW());

SELECT CONCAT('✓ Created rule: Vendor Payments from NF_CASH → User ', USER_ID_HERE) as Status;
*/

