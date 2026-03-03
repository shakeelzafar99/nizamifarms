-- =============================================
-- Store Inventory Manual Adjustment Log
-- Date: Feb 27, 2026
-- Purpose: Track manual store inventory adjustments 
--          (same concept as warehouse inventory log but for store-side)
-- =============================================

CREATE TABLE IF NOT EXISTS t_crm_store_inventory_adjustment (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  -- Product reference
  product_id            BIGINT UNSIGNED NOT NULL,              -- FK to t_crm_prod_product
  product_variant_id    BIGINT UNSIGNED NULL,                  -- FK to t_crm_prod_product_variant
  business_unit_id      INT NOT NULL,                          -- FK to t_fin_business_units
  
  -- Change Details
  change_type           VARCHAR(30) NOT NULL,                  -- 'store_stock_in', 'store_stock_out', 'store_count', 'store_adjustment'
  quantity_before       INT NOT NULL DEFAULT 0,                -- Store stock before change
  quantity_change       INT NOT NULL DEFAULT 0,                -- +/- change amount
  quantity_after        INT NOT NULL DEFAULT 0,                -- Store stock after change
  
  -- Context
  notes                 TEXT NULL,                             -- Reason for change
  
  -- Audit
  created_by            BIGINT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  -- Indexes
  INDEX `idx_store_adj_product` (`product_id`),
  INDEX `idx_store_adj_bu` (`business_unit_id`),
  INDEX `idx_store_adj_created` (`created_at`),
  INDEX `idx_store_adj_product_bu` (`product_id`, `business_unit_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks manual store inventory adjustments for Khaas products';

SELECT '✅ Created t_crm_store_inventory_adjustment table' as Status;
