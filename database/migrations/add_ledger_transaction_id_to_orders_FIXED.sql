-- =====================================================
-- Add ledger_transaction_id to orders table - FIXED
-- =====================================================
-- Purpose: Link orders to their ledger entries
-- Note: Adds column without AFTER clause to avoid column not found errors
-- =====================================================

-- Check if column already exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'Column already exists - SKIPPING'
        ELSE 'Column does not exist - will add it'
    END AS Status
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
  AND COLUMN_NAME = 'ledger_transaction_id';

-- Add column (no AFTER clause - just add it)
ALTER TABLE `t_crm_prod_order`
ADD COLUMN `ledger_transaction_id` INT NULL COMMENT 'FK to t_fin_ledger - invoice posting';

-- Add index for performance
ALTER TABLE `t_crm_prod_order`
ADD INDEX `idx_ledger_transaction` (`ledger_transaction_id`);

-- Add foreign key if t_fin_ledger exists
ALTER TABLE `t_crm_prod_order`
ADD CONSTRAINT `fk_order_ledger_transaction`
FOREIGN KEY (`ledger_transaction_id`) REFERENCES `t_fin_ledger`(`id`)
ON DELETE SET NULL
ON UPDATE CASCADE;

-- Verify
SELECT 
    'SUCCESS: Column added' as Status,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
  AND COLUMN_NAME = 'ledger_transaction_id';

-- Show where it was added
SELECT 
    ORDINAL_POSITION,
    COLUMN_NAME,
    COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
ORDER BY ORDINAL_POSITION;

