-- =============================================
-- Vendor Enhancements - Schema Check & Updates
-- =============================================
-- Date: October 21, 2025
-- =============================================

USE nizamifarms_db;

-- Check current vendor table structure
SELECT '=== Current t_fin_vendors Structure ===' as Info;
DESCRIBE t_fin_vendors;

-- Check current ledger table structure (for bill_image)
SELECT '' as '';
SELECT '=== Current t_fin_ledger Structure (relevant columns) ===' as Info;
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_ledger'
  AND COLUMN_NAME IN ('id', 'transaction_type', 'description', 'amount', 'attachments', 'bill_image', 'receipt_image')
ORDER BY ORDINAL_POSITION;

-- Check if vendor_id already exists in vendor_products
SELECT '' as '';
SELECT '=== Checking t_fin_vendor_products ===' as Info;
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_vendor_products'
  AND COLUMN_NAME = 'vendor_id';


