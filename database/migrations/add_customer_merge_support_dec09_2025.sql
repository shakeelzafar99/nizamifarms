-- =====================================================
-- Migration: Add Customer Merge Support
-- Date: December 9, 2025
-- Purpose: Allow merging duplicate customers while preserving traceability
-- =====================================================

-- Add merged_into_customer_id column to track merged customers
-- First check if columns exist to avoid errors on re-run
SET @colExists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_crm_prod_customer' 
    AND COLUMN_NAME = 'merged_into_customer_id');

SET @query = IF(@colExists = 0,
    'ALTER TABLE t_crm_prod_customer 
     ADD COLUMN merged_into_customer_id INT UNSIGNED NULL COMMENT ''If set, this customer was merged into another customer'',
     ADD COLUMN merged_at DATETIME NULL COMMENT ''When the customer was merged'',
     ADD COLUMN merged_by INT UNSIGNED NULL COMMENT ''User who performed the merge''',
    'SELECT ''Columns already exist'' as message');

PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for efficient filtering (if not exists)
SET @idxExists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_crm_prod_customer' 
    AND INDEX_NAME = 'idx_merged_into');

SET @query = IF(@idxExists = 0,
    'CREATE INDEX idx_merged_into ON t_crm_prod_customer (merged_into_customer_id)',
    'SELECT ''Index already exists'' as message');

PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================
-- Verification Query
-- =====================================================
-- Check the new columns exist:
-- DESCRIBE t_crm_prod_customer;

-- See merged customers:
-- SELECT id, first_name, last_name, phone_normalized, merged_into_customer_id, merged_at 
-- FROM t_crm_prod_customer 
-- WHERE merged_into_customer_id IS NOT NULL;

-- =====================================================
-- How the merge works:
-- =====================================================
-- 1. User selects a "primary" customer and one or more "duplicate" customers
-- 2. System updates all orders (prod + history) from duplicates to point to primary
-- 3. System sets merged_into_customer_id on duplicate customers
-- 4. Duplicate customers are hidden from normal queries (WHERE merged_into_customer_id IS NULL)
-- 5. Full traceability is preserved - duplicate records still exist
-- 6. Can be undone by setting merged_into_customer_id back to NULL
-- =====================================================

