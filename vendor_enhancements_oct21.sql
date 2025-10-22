-- =============================================
-- Vendor Enhancements - October 21, 2025
-- =============================================
-- Purpose: Add default purchase method and bill image support
-- =============================================

USE nizamifarms_db;

-- =============================================
-- 1. Add default_purchase_method to vendors table
-- =============================================

SELECT '--- Step 1: Adding default_purchase_method column ---' as '';

-- Check if column exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 't_fin_vendors'
      AND COLUMN_NAME = 'default_purchase_method'
);

-- Add column if it doesn't exist
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE t_fin_vendors 
     ADD COLUMN default_purchase_method ENUM(''by_weight'', ''by_total'') NOT NULL DEFAULT ''by_total'' 
     COMMENT ''Default method for recording purchases: by_weight (weighted) or by_total (flat amount)'' 
     AFTER is_active',
    'SELECT ''Column default_purchase_method already exists'' as Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Step 1 Complete: default_purchase_method column ready' as Status;

-- =============================================
-- 2. Add bill_image to ledger table
-- =============================================

SELECT '' as '';
SELECT '--- Step 2: Adding bill_image column to ledger ---' as '';

-- Check if column exists
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 't_fin_ledger'
      AND COLUMN_NAME = 'bill_image'
);

-- Add column if it doesn't exist
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE t_fin_ledger 
     ADD COLUMN bill_image VARCHAR(500) NULL 
     COMMENT ''Path to uploaded bill/receipt image for vendor purchases'' 
     AFTER comments',
    'SELECT ''Column bill_image already exists'' as Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Step 2 Complete: bill_image column ready' as Status;

-- =============================================
-- 3. Verification
-- =============================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFICATION' as '';
SELECT '========================================' as '';

-- Show updated vendor structure
SELECT '' as '';
SELECT '--- t_fin_vendors (relevant columns) ---' as '';
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_vendors'
  AND COLUMN_NAME IN ('id', 'vendor_name', 'is_active', 'default_purchase_method')
ORDER BY ORDINAL_POSITION;

-- Show updated ledger structure
SELECT '' as '';
SELECT '--- t_fin_ledger (bill_image column) ---' as '';
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_ledger'
  AND COLUMN_NAME = 'bill_image';

-- Confirm vendor products are vendor-specific
SELECT '' as '';
SELECT '--- Confirming vendor_products are vendor-specific ---' as '';
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_vendor_products'
  AND COLUMN_NAME = 'vendor_id';

SELECT '' as '';
SELECT '✓ All schema changes complete!' as '';
SELECT '' as '';
SELECT 'Summary of Changes:' as '';
SELECT '1. Added default_purchase_method to t_fin_vendors (by_weight or by_total)' as '';
SELECT '2. Added bill_image to t_fin_ledger (stores image path)' as '';
SELECT '3. Confirmed vendor_products already have vendor_id (vendor-specific)' as '';


