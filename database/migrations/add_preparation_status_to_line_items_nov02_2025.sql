-- =====================================================
-- Migration: Add Preparation Status to Line Items
-- Date: November 2, 2025
-- Description: Adds preparation_status column to both
--              regular and Shopify order line item tables
--              to track item preparation progress
-- =====================================================

-- Add preparation_status column to regular order line items table
ALTER TABLE `t_crm_prod_order_line_item`
ADD COLUMN `preparation_status` VARCHAR(20) NULL DEFAULT NULL
COMMENT 'Preparation status: NULL (not started), preparing'
AFTER `line_total`;

-- Add index for faster queries on preparation_status
ALTER TABLE `t_crm_prod_order_line_item`
ADD INDEX `idx_preparation_status` (`preparation_status`);

-- Add preparation_status column to Shopify order line items table
ALTER TABLE `t_crm_shopify_order_line_item`
ADD COLUMN `preparation_status` VARCHAR(20) NULL DEFAULT NULL
COMMENT 'Preparation status: NULL (not started), preparing'
AFTER `line_total`;

-- Add index for faster queries on preparation_status
ALTER TABLE `t_crm_shopify_order_line_item`
ADD INDEX `idx_preparation_status` (`preparation_status`);

-- =====================================================
-- ROLLBACK SCRIPT (if needed)
-- =====================================================
-- To rollback this migration, run the following:
--
-- ALTER TABLE `t_crm_prod_order_line_item`
-- DROP INDEX `idx_preparation_status`,
-- DROP COLUMN `preparation_status`;
--
-- ALTER TABLE `t_crm_shopify_order_line_item`
-- DROP INDEX `idx_preparation_status`,
-- DROP COLUMN `preparation_status`;
-- =====================================================

-- Verification queries (run after migration to verify)
-- SELECT COUNT(*) as total_line_items,
--        SUM(CASE WHEN preparation_status IS NULL THEN 1 ELSE 0 END) as not_started,
--        SUM(CASE WHEN preparation_status = 'preparing' THEN 1 ELSE 0 END) as preparing
-- FROM t_crm_prod_order_line_item;
--
-- SELECT COUNT(*) as total_line_items,
--        SUM(CASE WHEN preparation_status IS NULL THEN 1 ELSE 0 END) as not_started,
--        SUM(CASE WHEN preparation_status = 'preparing' THEN 1 ELSE 0 END) as preparing
-- FROM t_crm_shopify_order_line_item;

