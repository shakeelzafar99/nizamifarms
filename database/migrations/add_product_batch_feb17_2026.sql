-- =============================================
-- Migration: Product Batch Tracking (Khaas Mode)
-- Date: 2026-02-17
-- Description: Adds batch production tracking for Khaas products
--   - Each product can have active batches
--   - Batch end auto-triggers warehouse stock_in
--   - Tracks batch timing and quantity produced
--
-- FK COMPATIBILITY NOTES:
--   - t_fin_business_units.id = INT (signed)
--   - t_crm_prod_product.id = BIGINT UNSIGNED
--   - t_crm_prod_product_variant.id = BIGINT UNSIGNED
--   - t_sys_user.id = INT (signed, not unsigned)
--   - t_crm_warehouse_inventory_log.id = BIGINT UNSIGNED
-- =============================================

-- =============================================
-- STEP 1: Add batch_number column to products table
-- (Stores current/last batch reference on the product itself for quick display)
-- =============================================

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_crm_prod_product' 
    AND COLUMN_NAME = 'current_batch_number');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE t_crm_prod_product 
        ADD COLUMN current_batch_number VARCHAR(50) NULL DEFAULT NULL 
        COMMENT ''Current or last batch number for this product'' 
        AFTER is_active',
    'SELECT ''Column current_batch_number already exists in t_crm_prod_product'' as Info');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✅ Step 1: Added current_batch_number to t_crm_prod_product' as Status;


-- =============================================
-- STEP 2: Create product batch tracking table
-- =============================================

CREATE TABLE IF NOT EXISTS `t_crm_product_batch` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `product_variant_id` BIGINT UNSIGNED NULL,
    `business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units (INT, matches parent)',
    `batch_number` VARCHAR(50) NOT NULL COMMENT 'Auto-generated: B{buId}-P{productId}-YYYYMMDD-SEQ',
    `status` ENUM('in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'in_progress',
    `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` TIMESTAMP NULL DEFAULT NULL,
    `quantity_produced` INT NULL DEFAULT NULL COMMENT 'Filled when batch ends',
    `notes_start` TEXT NULL COMMENT 'Notes entered when starting batch',
    `notes_end` TEXT NULL COMMENT 'Notes entered when ending batch',
    `started_by` INT NOT NULL COMMENT 'FK to t_sys_user (INT, matches parent)',
    `ended_by` INT NULL COMMENT 'FK to t_sys_user (INT, matches parent)',
    `warehouse_stock_log_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_warehouse_inventory_log - auto-created stock-in on batch end',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for common queries
    INDEX `idx_batch_product` (`product_id`),
    INDEX `idx_batch_bu` (`business_unit_id`),
    INDEX `idx_batch_status` (`status`),
    INDEX `idx_batch_started` (`started_at`),
    INDEX `idx_batch_active` (`product_id`, `business_unit_id`, `status`),
    INDEX `idx_batch_started_by` (`started_by`),
    
    -- Foreign keys
    CONSTRAINT `fk_batch_product` FOREIGN KEY (`product_id`) 
        REFERENCES `t_crm_prod_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_batch_variant` FOREIGN KEY (`product_variant_id`) 
        REFERENCES `t_crm_prod_product_variant` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_batch_bu` FOREIGN KEY (`business_unit_id`) 
        REFERENCES `t_fin_business_units` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_batch_started_by` FOREIGN KEY (`started_by`) 
        REFERENCES `t_sys_user` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_batch_ended_by` FOREIGN KEY (`ended_by`) 
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_batch_stock_log` FOREIGN KEY (`warehouse_stock_log_id`) 
        REFERENCES `t_crm_warehouse_inventory_log` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks production batches per product. Batch end auto-triggers warehouse stock_in.';


-- =============================================
-- VERIFICATION
-- =============================================

SELECT '✅ Step 2: Created t_crm_product_batch table' as Status;

-- Verify product table alteration
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 't_crm_prod_product' 
AND COLUMN_NAME = 'current_batch_number';

-- Verify batch table exists
SELECT COUNT(*) AS batch_count FROM t_crm_product_batch;

SELECT '✅ Migration add_product_batch_feb17_2026.sql completed successfully' as Status;
