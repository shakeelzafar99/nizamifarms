-- =====================================================
-- Add tip_amount field to Orders Tables
-- =====================================================
-- Purpose: Capture tip amounts from Shopify orders
-- Date: October 16, 2025
-- Impact: Adds new column to both order tables
-- Safety: 100% - Column is nullable, webhooks won't fail
-- =====================================================

USE nizamifarms_db;

-- =====================================================
-- STEP 1: Add tip_amount to t_crm_prod_order
-- =====================================================

SELECT '--- Adding tip_amount to t_crm_prod_order ---' as '';

-- Check if column already exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'Column tip_amount already exists in t_crm_prod_order'
        ELSE 'Adding column tip_amount to t_crm_prod_order...'
    END AS Status
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
  AND COLUMN_NAME = 'tip_amount';

-- Add column if it doesn't exist
ALTER TABLE `t_crm_prod_order`
ADD COLUMN IF NOT EXISTS `tip_amount` DECIMAL(10,2) NULL DEFAULT 0.00 
COMMENT 'Tip amount from order (primarily for Shopify orders)' 
AFTER `total_tax`;

SELECT '✓ Step 1: tip_amount added to t_crm_prod_order' as Status;

-- =====================================================
-- STEP 2: Add tip_amount to t_crm_shopify_order
-- =====================================================

SELECT '--- Adding tip_amount to t_crm_shopify_order ---' as '';

-- Check if column already exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'Column tip_amount already exists in t_crm_shopify_order'
        ELSE 'Adding column tip_amount to t_crm_shopify_order...'
    END AS Status
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_shopify_order'
  AND COLUMN_NAME = 'tip_amount';

-- Add column if it doesn't exist
ALTER TABLE `t_crm_shopify_order`
ADD COLUMN IF NOT EXISTS `tip_amount` DECIMAL(10,2) NULL DEFAULT 0.00 
COMMENT 'Tip amount from Shopify order' 
AFTER `total_tax`;

SELECT '✓ Step 2: tip_amount added to t_crm_shopify_order' as Status;

-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFICATION' as '';
SELECT '========================================' as '';

-- Verify t_crm_prod_order
SELECT 
    'T_CRM_PROD_ORDER' as Table_Name,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
  AND COLUMN_NAME = 'tip_amount';

-- Verify t_crm_shopify_order
SELECT 
    'T_CRM_SHOPIFY_ORDER' as Table_Name,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_shopify_order'
  AND COLUMN_NAME = 'tip_amount';

SELECT '' as '';
SELECT '✅ Migration Complete!' as '';
SELECT '📝 Note: tip_amount is nullable, so existing webhooks will not fail' as '';
SELECT '📝 Note: Both order notes and tip amount will now be captured from Shopify' as '';

