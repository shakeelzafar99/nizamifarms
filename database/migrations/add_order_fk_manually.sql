-- ========================================
-- ADD ORDER FK MANUALLY
-- ========================================
-- Run this AFTER the main installation completes
-- This adds the FK from t_fin_ledger to t_crm_prod_order
-- Database: nizamifarms_db

USE nizamifarms_db;

-- Step 1: Modify order_id to match t_crm_prod_order.id type exactly
-- t_crm_prod_order.id is BIGINT(20) UNSIGNED
-- So order_id must also be BIGINT UNSIGNED

ALTER TABLE t_fin_ledger 
    MODIFY order_id BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_order(id)';

SELECT '✓ Modified order_id to BIGINT UNSIGNED' as Status;


-- Step 2: Add the foreign key constraint
ALTER TABLE t_fin_ledger 
    ADD CONSTRAINT fk_fin_ledger_order 
    FOREIGN KEY (order_id) REFERENCES t_crm_prod_order(id) ON DELETE SET NULL;

SELECT '✓ Added FK: t_fin_ledger.order_id -> t_crm_prod_order.id' as Status;


-- Step 3: Verify
SELECT 'Verification:' as '';

SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_fin_ledger'
AND COLUMN_NAME = 'order_id';

SELECT '' as '';

SELECT 
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_fin_ledger'
AND COLUMN_NAME = 'order_id'
AND REFERENCED_TABLE_NAME IS NOT NULL;

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓✓✓ ORDER FK ADDED SUCCESSFULLY! ✓✓✓' as '';
SELECT '========================================' as '';

