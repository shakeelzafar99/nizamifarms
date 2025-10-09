-- =====================================================
-- Add ledger_transaction_id to orders table
-- =====================================================
-- Purpose: Link orders to their ledger entries
-- =====================================================

-- Check if column exists, add if not
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'Column already exists'
        ELSE 'Adding column...'
    END AS Status
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
  AND COLUMN_NAME = 'ledger_transaction_id';

-- Add column if it doesn't exist
ALTER TABLE `t_crm_prod_order`
ADD COLUMN IF NOT EXISTS `ledger_transaction_id` INT NULL COMMENT 'FK to t_fin_ledger - invoice posting' AFTER `shopify_order_id`;

-- Add index for performance
ALTER TABLE `t_crm_prod_order`
ADD INDEX IF NOT EXISTS `idx_ledger_transaction` (`ledger_transaction_id`);

-- Add foreign key (only if t_fin_ledger exists)
-- Note: Run this after confirming t_fin_ledger table exists
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 't_crm_prod_order'
      AND CONSTRAINT_NAME = 'fk_order_ledger_transaction'
);

SET @sql = IF(
    @fk_exists = 0 AND 
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fin_ledger'),
    'ALTER TABLE `t_crm_prod_order`
     ADD CONSTRAINT `fk_order_ledger_transaction`
     FOREIGN KEY (`ledger_transaction_id`) REFERENCES `t_fin_ledger`(`id`)
     ON DELETE SET NULL
     ON UPDATE CASCADE',
    'SELECT "FK already exists or t_fin_ledger not found" AS Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify
SELECT 
    'Column added successfully' as Status,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
  AND COLUMN_NAME = 'ledger_transaction_id';

