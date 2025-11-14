-- =====================================================
-- APPROVAL ROUTING SYSTEM MIGRATION
-- =====================================================
-- Purpose: Add routing rules for approval assignments
-- Allows configuring which users should handle specific
-- approval types based on payment source, method, etc.
-- =====================================================

-- =====================================================
-- 1. CREATE APPROVAL RULES TABLE
-- =====================================================
-- Stores routing rules for different approval scenarios
CREATE TABLE IF NOT EXISTS `t_req_approval_rules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `rule_name` VARCHAR(255) NOT NULL COMMENT 'Descriptive name for the rule',
    `area_type` ENUM('request_category', 'ledger_transaction', 'ledger_adjustment') NOT NULL COMMENT 'Type of approval area',
    `area_identifier` VARCHAR(100) NOT NULL COMMENT 'Category code, transaction type, or adjustment type',
    `approval_level` TINYINT NOT NULL COMMENT '1 or 2',
    
    -- Contextual filters (all optional)
    `payment_source_account_id` INT UNSIGNED NULL COMMENT 'Filter by payment source account',
    `payment_mode` ENUM('cash', 'online') NULL COMMENT 'Filter by payment mode',
    `min_amount` DECIMAL(15,2) NULL COMMENT 'Minimum amount for this rule',
    `max_amount` DECIMAL(15,2) NULL COMMENT 'Maximum amount for this rule',
    
    -- Assignment strategy
    `assignment_strategy` ENUM('single_primary', 'round_robin', 'all_can_act') DEFAULT 'single_primary' COMMENT 'How to assign tasks',
    `priority` INT DEFAULT 100 COMMENT 'Lower number = higher priority when multiple rules match',
    
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    
    INDEX `idx_area` (`area_type`, `area_identifier`),
    INDEX `idx_level` (`approval_level`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_priority` (`priority`),
    FOREIGN KEY (`payment_source_account_id`) REFERENCES `t_fin_accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. CREATE APPROVAL RULE ASSIGNEES TABLE
-- =====================================================
-- Maps rules to specific users who should receive assignments
CREATE TABLE IF NOT EXISTS `t_req_approval_rule_assignees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `rule_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'User who should be assigned',
    `is_primary` TINYINT(1) DEFAULT 1 COMMENT 'Primary assignee (shown first)',
    `sequence_order` INT DEFAULT 0 COMMENT 'Order for round-robin assignment',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY `unique_rule_user` (`rule_id`, `user_id`),
    INDEX `idx_rule` (`rule_id`),
    INDEX `idx_user` (`user_id`),
    FOREIGN KEY (`rule_id`) REFERENCES `t_req_approval_rules`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `t_sys_user`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. ADD ASSIGNEE TRACKING TO REQUESTS
-- =====================================================
-- Add columns to track who is assigned at each level
ALTER TABLE `t_req_master`
ADD COLUMN `level_1_assigned_to` INT UNSIGNED NULL COMMENT 'User assigned to approve at L1' AFTER `level_1_status`,
ADD COLUMN `level_2_assigned_to` INT UNSIGNED NULL COMMENT 'User assigned to approve at L2' AFTER `level_2_status`,
ADD INDEX `idx_l1_assigned` (`level_1_assigned_to`),
ADD INDEX `idx_l2_assigned` (`level_2_assigned_to`);

-- =====================================================
-- 4. ADD ASSIGNEE TRACKING TO LEDGER ADJUSTMENTS
-- =====================================================
-- Add columns to track who is assigned at each level for adjustments
ALTER TABLE `t_fin_ledger_adjustments`
ADD COLUMN `level_1_assigned_to` INT UNSIGNED NULL COMMENT 'User assigned to approve at L1' AFTER `level_1_status`,
ADD COLUMN `level_2_assigned_to` INT UNSIGNED NULL COMMENT 'User assigned to approve at L2' AFTER `level_2_status`,
ADD INDEX `idx_l1_assigned` (`level_1_assigned_to`),
ADD INDEX `idx_l2_assigned` (`level_2_assigned_to`);

-- =====================================================
-- 5. ENSURE INVOICE_APPROVAL CATEGORY EXISTS
-- =====================================================
-- This category will be used for online invoices that go through request workflow
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

-- =====================================================
-- 6. ADD ORDER REFERENCE TO REQUESTS
-- =====================================================
-- Link requests to orders for invoice approval workflow
ALTER TABLE `t_req_master`
ADD COLUMN `order_id` INT UNSIGNED NULL COMMENT 'Reference to order for invoice approvals' AFTER `ledger_transaction_id`,
ADD INDEX `idx_order` (`order_id`);

-- =====================================================
-- 7. ADD INVOICE REQUEST TRACKING TO ORDERS
-- =====================================================
-- Track which request handles the invoice approval
ALTER TABLE `t_crm_prod_order`
ADD COLUMN `invoice_request_id` INT UNSIGNED NULL COMMENT 'Request for online invoice approval' AFTER `ledger_transaction_id`,
ADD INDEX `idx_invoice_request` (`invoice_request_id`);

-- =====================================================
-- 8. CREATE DEFAULT ROUTING RULES (EXAMPLES)
-- =====================================================
-- These are starter rules - you can modify/delete as needed

-- Example 1: Expense requests from EXP_FUND → Assign to specific user at L1
-- Replace USER_ID_HERE with actual user ID
-- INSERT INTO t_req_approval_rules (rule_name, area_type, area_identifier, approval_level, payment_source_account_id, assignment_strategy, priority)
-- SELECT 
--     'Expenses from EXP_FUND - L1',
--     'request_category',
--     'expense',
--     1,
--     (SELECT id FROM t_fin_accounts WHERE account_code = 'EXP_FUND'),
--     'single_primary',
--     10
-- WHERE EXISTS (SELECT 1 FROM t_fin_accounts WHERE account_code = 'EXP_FUND');

-- Example 2: Online invoice approvals → Assign to specific user at L1
-- INSERT INTO t_req_approval_rules (rule_name, area_type, area_identifier, approval_level, payment_mode, assignment_strategy, priority)
-- VALUES (
--     'Online Invoice Approval - L1',
--     'request_category',
--     'invoice_approval',
--     1,
--     'online',
--     'single_primary',
--     10
-- );

-- Example 3: Vendor payments from NF_CASH → Assign to specific user
-- INSERT INTO t_req_approval_rules (rule_name, area_type, area_identifier, approval_level, payment_source_account_id, assignment_strategy, priority)
-- SELECT 
--     'Vendor Payments from NF_CASH - L1',
--     'ledger_transaction',
--     'vendor_payment',
--     1,
--     (SELECT id FROM t_fin_accounts WHERE account_code = 'NF_CASH'),
--     'single_primary',
--     10
-- WHERE EXISTS (SELECT 1 FROM t_fin_accounts WHERE account_code = 'NF_CASH');

-- =====================================================
-- 9. VERIFICATION QUERIES
-- =====================================================

-- Check tables created
SELECT 'Tables created successfully' as Status;

SELECT 
    TABLE_NAME,
    TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('t_req_approval_rules', 't_req_approval_rule_assignees');

-- Check columns added to requests
SELECT 
    'Request assignee columns' as Check_Type,
    COLUMN_NAME,
    COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 't_req_master'
AND COLUMN_NAME IN ('level_1_assigned_to', 'level_2_assigned_to', 'order_id');

-- Check columns added to orders
SELECT 
    'Order invoice request column' as Check_Type,
    COLUMN_NAME,
    COLUMN_TYPE
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
SELECT '⚠️  Next: Configure approval rules and assignees in Request Settings' as Next_Step;

