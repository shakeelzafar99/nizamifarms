-- =====================================================
-- Migration: Add Weight Factor to Products
-- Date: November 19, 2025
-- Description: Adds weight_factor column to products table
--              for multiplying quantities in invoice edits
-- =====================================================

-- Add weight_factor column to products table
ALTER TABLE `t_crm_prod_product`
ADD COLUMN `weight_factor` DECIMAL(10,2) NOT NULL DEFAULT 1.00
COMMENT 'Factor to multiply quantity by when editing invoices (default: 1.00)'
AFTER `is_lean`;

-- Add index for faster queries if needed
ALTER TABLE `t_crm_prod_product`
ADD INDEX `idx_weight_factor` (`weight_factor`);

-- =====================================================
-- ROLLBACK SCRIPT (if needed)
-- =====================================================
-- To rollback this migration, run the following:
--
-- ALTER TABLE `t_crm_prod_product`
-- DROP INDEX `idx_weight_factor`,
-- DROP COLUMN `weight_factor`;
-- =====================================================

-- Verification queries (run after migration to verify)
-- SELECT COUNT(*) as total_products,
--        AVG(weight_factor) as avg_weight_factor,
--        MIN(weight_factor) as min_weight_factor,
--        MAX(weight_factor) as max_weight_factor
-- FROM t_crm_prod_product;
--
-- -- Check products with non-default weight factor
-- SELECT id, title, weight_factor, is_lean
-- FROM t_crm_prod_product
-- WHERE weight_factor != 1.00;

