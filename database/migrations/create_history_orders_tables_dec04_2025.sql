-- ============================================================================
-- MIGRATION: Create Historical Orders Tables
-- Date: December 04, 2025
-- Purpose: Store historical order data from CSV import (2021-2025)
--          and support monthly migrations from production
-- 
-- Tables Created:
--   1. t_crm_history_order - Historical orders
--   2. t_crm_history_order_line_item - Historical line items
--   3. v_crm_all_orders - Unified view of all orders
--   4. v_crm_all_order_line_items - Unified view of all line items
--   5. v_crm_customer_order_summary - Customer summary with all orders
--
-- NOTE: Foreign keys are NOT enforced to allow flexibility with historical data
--       Application-level integrity is maintained via import logic
-- ============================================================================

-- Disable FK checks during table creation
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- TABLE 1: t_crm_history_order
-- Structure matches t_crm_prod_order for consistency
-- ============================================================================
DROP TABLE IF EXISTS `t_crm_history_order_line_item`;
DROP TABLE IF EXISTS `t_crm_history_order`;

CREATE TABLE `t_crm_history_order` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT 'Auto-generated ID',
    `customer_id` INT UNSIGNED NULL COMMENT 'Reference to t_crm_prod_customer (not FK enforced)',
    
    -- External identifiers
    `external_source` VARCHAR(50) DEFAULT 'csv_import' COMMENT 'Source: csv_import, monthly_migration',
    `external_id` VARCHAR(255) NULL COMMENT 'Original external order ID if any',
    `external_customer_id` VARCHAR(255) NULL,
    
    -- Order basics
    `order_number` VARCHAR(255) NOT NULL COMMENT 'From CSV Order Number (can repeat)',
    `order_status` VARCHAR(50) DEFAULT 'delivered' COMMENT 'Normalized: delivered, cancelled',
    `order_date` DATETIME NOT NULL COMMENT 'When order was placed',
    `delivered_at` DATETIME NULL COMMENT 'Actual delivery date/time from CSV or status history',
    `name` VARCHAR(255) NULL COMMENT 'Customer full name',
    `currency` VARCHAR(10) DEFAULT 'PKR',
    `contact_email` VARCHAR(255) NULL,
    
    -- Money fields (DECIMAL for precision)
    `subtotal_price` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Sum of line items before discount/shipping',
    `discount_total` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Cart Discount Amount',
    `shipping_total` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Order Shipping Amount',
    `total_tax` DECIMAL(12,2) DEFAULT 0.00,
    `tip_amount` DECIMAL(12,2) DEFAULT 0.00,
    `total_price` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Order Total Amount',
    `total_weight` INT DEFAULT 0,
    
    -- Address fields
    `address_first_name` VARCHAR(255) NULL COMMENT 'First Name (Billing)',
    `address_last_name` VARCHAR(255) NULL COMMENT 'Last Name (Billing)',
    `address_company` VARCHAR(255) NULL,
    `address_email` VARCHAR(255) NULL COMMENT 'Email (Billing)',
    `address_phone` VARCHAR(50) NULL COMMENT 'Mobile Number (cleaned 10-digit)',
    `address_line1` TEXT NULL COMMENT 'Address 1 (Billing)',
    `address_line2` TEXT NULL,
    `address_city` VARCHAR(255) NULL COMMENT 'City (Billing)',
    `address_province` VARCHAR(255) NULL,
    `address_postal_code` VARCHAR(50) NULL,
    `address_country` VARCHAR(100) DEFAULT 'Pakistan',
    
    -- Order metadata
    `coupon_code` VARCHAR(255) NULL COMMENT 'Coupon Code',
    `payment_method` VARCHAR(100) NULL COMMENT 'Payment method (normalized)',
    `note` TEXT NULL COMMENT 'Notes from CSV',
    `expected_packets` INT NULL,
    `actual_packets` INT NULL,
    `raw_products_text` TEXT NULL COMMENT 'Products column (full product list)',
    `converted` TINYINT(1) DEFAULT 0,
    `ledger_transaction_id` INT NULL,
    
    -- Migration metadata
    `migrated_from_prod` TINYINT(1) DEFAULT 0 COMMENT '1 if migrated from t_crm_prod_order',
    `original_prod_order_id` INT NULL COMMENT 'Original ID if migrated from production',
    `migration_date` DATETIME NULL COMMENT 'When migrated from production',
    `import_batch_id` VARCHAR(50) NULL COMMENT 'Batch ID for CSV imports',
    
    -- Audit fields
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historical orders: CSV imports (2021-2025) + monthly migrations from production';

-- Add indexes separately for better control
CREATE INDEX `idx_ho_customer_id` ON `t_crm_history_order` (`customer_id`);
CREATE INDEX `idx_ho_order_number` ON `t_crm_history_order` (`order_number`);
CREATE INDEX `idx_ho_external_id` ON `t_crm_history_order` (`external_id`);
CREATE INDEX `idx_ho_order_date` ON `t_crm_history_order` (`order_date`);
CREATE INDEX `idx_ho_delivered_at` ON `t_crm_history_order` (`delivered_at`);
CREATE INDEX `idx_ho_order_status` ON `t_crm_history_order` (`order_status`);
CREATE INDEX `idx_ho_phone` ON `t_crm_history_order` (`address_phone`);
CREATE INDEX `idx_ho_customer_date` ON `t_crm_history_order` (`customer_id`, `order_date`);
CREATE INDEX `idx_ho_customer_delivered` ON `t_crm_history_order` (`customer_id`, `delivered_at`);
CREATE INDEX `idx_ho_migration` ON `t_crm_history_order` (`migrated_from_prod`, `migration_date`);
CREATE INDEX `idx_ho_import_batch` ON `t_crm_history_order` (`import_batch_id`);

-- ============================================================================
-- TABLE 2: t_crm_history_order_line_item
-- Structure matches t_crm_prod_order_line_item for consistency
-- ============================================================================
CREATE TABLE `t_crm_history_order_line_item` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL COMMENT 'Reference to t_crm_history_order',
    `external_line_item_id` VARCHAR(255) NULL COMMENT 'Item # from CSV',
    
    -- Product references (may not match current products for historical SKUs)
    `product_id` INT NULL COMMENT 'Reference to t_crm_prod_product (NULL for old SKUs)',
    `variant_id` INT NULL COMMENT 'Reference to t_crm_prod_product_variant (NULL for old SKUs)',
    `sku` VARCHAR(255) NULL COMMENT 'SKU from CSV (historical, may not exist now)',
    `name` VARCHAR(500) NULL COMMENT 'Item Name from CSV',
    `vendor` VARCHAR(255) NULL,
    
    -- Quantity and pricing (DECIMAL for precision with weights)
    `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000 COMMENT 'Quantity (supports decimals like 1.25kg)',
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Item Cost Before Discount',
    `line_subtotal` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Order Line Subtotal',
    `discount_amount` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Line-level Discount Amount',
    `tax_amount` DECIMAL(12,2) DEFAULT 0.00,
    `line_total` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Final line total',
    `preparation_status` VARCHAR(50) NULL,
    
    -- Audit fields
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign key to history order (this one we enforce since both tables are created together)
    CONSTRAINT `fk_holi_order` FOREIGN KEY (`order_id`) 
        REFERENCES `t_crm_history_order`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historical order line items from CSV import + monthly migrations';

-- Add indexes for line items
CREATE INDEX `idx_holi_order_id` ON `t_crm_history_order_line_item` (`order_id`);
CREATE INDEX `idx_holi_sku` ON `t_crm_history_order_line_item` (`sku`);
CREATE INDEX `idx_holi_product_id` ON `t_crm_history_order_line_item` (`product_id`);
CREATE INDEX `idx_holi_order_sku` ON `t_crm_history_order_line_item` (`order_id`, `sku`);

-- Re-enable FK checks
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VIEW 1: v_crm_all_orders (Unified Orders View)
-- Combines production and history orders with consistent delivered_at handling
-- ============================================================================
DROP VIEW IF EXISTS `v_crm_all_orders`;

CREATE VIEW `v_crm_all_orders` AS
SELECT 
    -- Source identifier
    'production' AS source_type,
    
    -- Order fields
    o.id,
    o.customer_id,
    o.external_source,
    o.external_id,
    o.order_number,
    o.order_status,
    o.order_date,
    
    -- Delivery date: Get from status history for production orders
    COALESCE(
        (SELECT sh.changed_at 
         FROM t_crm_order_status_history sh
         WHERE sh.order_id = o.id 
           AND sh.status_code = 'delivered' 
           AND sh.is_current = 1 
         LIMIT 1),
        -- Fallback to order_date if delivered but no status history
        CASE WHEN o.order_status IN ('delivered', 'completed') THEN o.order_date ELSE NULL END
    ) AS delivered_at,
    
    o.name,
    o.currency,
    o.contact_email,
    o.subtotal_price,
    o.discount_total,
    o.shipping_total,
    o.total_tax,
    o.tip_amount,
    o.total_price,
    o.total_weight,
    o.address_first_name,
    o.address_last_name,
    o.address_company,
    o.address_email,
    o.address_phone,
    o.address_line1,
    o.address_line2,
    o.address_city,
    o.address_province,
    o.address_postal_code,
    o.address_country,
    o.coupon_code,
    o.payment_method,
    o.note,
    o.expected_packets,
    o.actual_packets,
    o.raw_products_text,
    o.converted,
    o.ledger_transaction_id,
    o.created_by,
    o.updated_by,
    o.created_at,
    o.updated_at
    
FROM `t_crm_prod_order` o

UNION ALL

SELECT 
    -- Source identifier
    'history' AS source_type,
    
    -- Order fields (directly from history table)
    h.id,
    h.customer_id,
    h.external_source,
    h.external_id,
    h.order_number,
    h.order_status,
    h.order_date,
    
    -- Delivery date: Direct from column for history orders
    h.delivered_at,
    
    h.name,
    h.currency,
    h.contact_email,
    h.subtotal_price,
    h.discount_total,
    h.shipping_total,
    h.total_tax,
    h.tip_amount,
    h.total_price,
    h.total_weight,
    h.address_first_name,
    h.address_last_name,
    h.address_company,
    h.address_email,
    h.address_phone,
    h.address_line1,
    h.address_line2,
    h.address_city,
    h.address_province,
    h.address_postal_code,
    h.address_country,
    h.coupon_code,
    h.payment_method,
    h.note,
    h.expected_packets,
    h.actual_packets,
    h.raw_products_text,
    h.converted,
    h.ledger_transaction_id,
    h.created_by,
    h.updated_by,
    h.created_at,
    h.updated_at
    
FROM `t_crm_history_order` h;

-- ============================================================================
-- VIEW 2: v_crm_all_order_line_items (Unified Line Items View)
-- ============================================================================
DROP VIEW IF EXISTS `v_crm_all_order_line_items`;

CREATE VIEW `v_crm_all_order_line_items` AS
SELECT 
    'production' AS source_type,
    li.id,
    li.order_id,
    li.external_line_item_id,
    li.product_id,
    li.variant_id,
    li.sku,
    li.name,
    li.vendor,
    li.quantity,
    li.unit_price,
    li.line_subtotal,
    li.discount_amount,
    li.tax_amount,
    li.line_total,
    li.preparation_status,
    li.created_by,
    li.updated_by,
    li.created_at,
    li.updated_at
FROM `t_crm_prod_order_line_item` li

UNION ALL

SELECT 
    'history' AS source_type,
    li.id,
    li.order_id,
    li.external_line_item_id,
    li.product_id,
    li.variant_id,
    li.sku,
    li.name,
    li.vendor,
    li.quantity,
    li.unit_price,
    li.line_subtotal,
    li.discount_amount,
    li.tax_amount,
    li.line_total,
    li.preparation_status,
    li.created_by,
    li.updated_by,
    li.created_at,
    li.updated_at
FROM `t_crm_history_order_line_item` li;

-- ============================================================================
-- VIEW 3: v_crm_customer_order_summary (Customer with ALL orders)
-- Used for customer detail page showing production + history totals
-- ============================================================================
DROP VIEW IF EXISTS `v_crm_customer_order_summary`;

CREATE VIEW `v_crm_customer_order_summary` AS
SELECT 
    c.id AS customer_id,
    c.phone,
    c.phone_normalized,
    c.first_name,
    c.last_name,
    CONCAT_WS(' ', c.first_name, c.last_name) AS full_name,
    c.email,
    c.city,
    c.address1,
    
    -- Production stats (WooCommerce orders, excludes Shopify per your logic)
    c.first_order_date AS prod_first_order_date,
    c.last_order_date AS prod_last_order_date,
    c.total_orders AS prod_order_count,
    c.total_spent AS prod_total_spent,
    
    -- History order stats (subquery for performance)
    (SELECT COUNT(*) 
     FROM t_crm_history_order ho 
     WHERE ho.customer_id = c.id 
       AND ho.order_status IN ('delivered', 'completed')
    ) AS history_order_count,
    
    (SELECT COALESCE(SUM(ho.total_price), 0) 
     FROM t_crm_history_order ho 
     WHERE ho.customer_id = c.id 
       AND ho.order_status IN ('delivered', 'completed')
    ) AS history_total_spent,
    
    (SELECT MIN(ho.order_date) 
     FROM t_crm_history_order ho 
     WHERE ho.customer_id = c.id 
       AND ho.order_status IN ('delivered', 'completed')
    ) AS history_first_order_date,
    
    (SELECT MAX(ho.order_date) 
     FROM t_crm_history_order ho 
     WHERE ho.customer_id = c.id 
       AND ho.order_status IN ('delivered', 'completed')
    ) AS history_last_order_date,
    
    -- Combined all-time stats
    (c.total_orders + 
     COALESCE((SELECT COUNT(*) FROM t_crm_history_order ho 
               WHERE ho.customer_id = c.id AND ho.order_status IN ('delivered', 'completed')), 0)
    ) AS all_time_order_count,
    
    (c.total_spent + 
     COALESCE((SELECT SUM(ho.total_price) FROM t_crm_history_order ho 
               WHERE ho.customer_id = c.id AND ho.order_status IN ('delivered', 'completed')), 0)
    ) AS all_time_total_spent,
    
    c.is_active,
    c.created_at,
    c.updated_at
    
FROM `t_crm_prod_customer` c;

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================
SELECT '✅ Migration completed successfully!' AS status;
SELECT 'Tables created: t_crm_history_order, t_crm_history_order_line_item' AS tables;
SELECT 'Views created: v_crm_all_orders, v_crm_all_order_line_items, v_crm_customer_order_summary' AS views;
SELECT 'Ready for CSV import via Operations page!' AS next_step;
