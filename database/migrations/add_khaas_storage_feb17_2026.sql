-- =============================================
-- Migration: Khaas Storage Feature
-- Date: 2026-02-17
-- Description: Storage view for meat products from Nizami Farms → Khaas warehouse
--
-- Features:
--   1. Configure which NF products are available for Khaas storage orders
--   2. Place meat orders (creates order in main app with "Khaas Warehouse" customer)
--   3. Track storage inventory (delivered items → inventory, depleted by "use")
--
-- FK COMPATIBILITY NOTES:
--   - t_fin_business_units.id = INT (signed)
--   - t_crm_prod_product.id = BIGINT UNSIGNED
--   - t_crm_prod_product_variant.id = BIGINT UNSIGNED
--   - t_crm_prod_order.id = BIGINT UNSIGNED
--   - t_crm_prod_customer.id = BIGINT UNSIGNED
--   - t_sys_user.id = INT (signed)
-- =============================================


-- =============================================
-- STEP 1: Khaas Storage Product Config
-- Maps which Nizami Farms products appear in the Khaas storage order screen
-- Only configurable by admin/Taimur role
-- =============================================

CREATE TABLE IF NOT EXISTS `t_crm_khaas_storage_config` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_product — the NF product available for storage orders',
    `source_variant_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product_variant — specific variant (null = default variant)',
    `khaas_business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units — the Khaas BU receiving the stock',
    `display_name` VARCHAR(255) NULL COMMENT 'Override display name (null = use product title)',
    `default_unit` VARCHAR(20) NOT NULL DEFAULT 'kg' COMMENT 'Default unit for this product (kg, pcs, etc.)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_by` INT NULL COMMENT 'FK to t_sys_user',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uq_source_product_bu` (`source_product_id`, `khaas_business_unit_id`),
    INDEX `idx_ksc_bu` (`khaas_business_unit_id`),
    INDEX `idx_ksc_active` (`is_active`, `khaas_business_unit_id`),
    
    CONSTRAINT `fk_ksc_product` FOREIGN KEY (`source_product_id`) 
        REFERENCES `t_crm_prod_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ksc_variant` FOREIGN KEY (`source_variant_id`) 
        REFERENCES `t_crm_prod_product_variant` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ksc_bu` FOREIGN KEY (`khaas_business_unit_id`) 
        REFERENCES `t_fin_business_units` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ksc_created_by` FOREIGN KEY (`created_by`) 
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Maps NF products available for Khaas storage orders. Configurable by admin.';

SELECT '✅ Step 1: Created t_crm_khaas_storage_config' as Status;


-- =============================================
-- STEP 2: Khaas Storage Inventory
-- Tracks meat stock received from NF and currently on-hand in Khaas warehouse
-- Separate from the regular warehouse inventory (which tracks Khaas-made products)
-- =============================================

CREATE TABLE IF NOT EXISTS `t_crm_khaas_storage_inventory` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_product — the NF product',
    `source_variant_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product_variant',
    `khaas_business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units — the Khaas BU',
    `quantity_on_hand` DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Current stock on hand (supports decimals for kg)',
    `unit` VARCHAR(20) NOT NULL DEFAULT 'kg',
    `total_received` DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Lifetime total received',
    `total_used` DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'Lifetime total used/consumed',
    `last_received_at` TIMESTAMP NULL DEFAULT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uq_storage_product_bu` (`source_product_id`, `source_variant_id`, `khaas_business_unit_id`),
    INDEX `idx_ksi_bu` (`khaas_business_unit_id`),
    INDEX `idx_ksi_product` (`source_product_id`),
    
    CONSTRAINT `fk_ksi_product` FOREIGN KEY (`source_product_id`) 
        REFERENCES `t_crm_prod_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ksi_variant` FOREIGN KEY (`source_variant_id`) 
        REFERENCES `t_crm_prod_product_variant` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ksi_bu` FOREIGN KEY (`khaas_business_unit_id`) 
        REFERENCES `t_fin_business_units` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Current storage inventory levels for NF meat in Khaas warehouse. Separate from Khaas production inventory.';

SELECT '✅ Step 2: Created t_crm_khaas_storage_inventory' as Status;


-- =============================================
-- STEP 3: Khaas Storage Log
-- Tracks all movements: received (from delivered NF orders) and used (consumed)
-- =============================================

CREATE TABLE IF NOT EXISTS `t_crm_khaas_storage_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `storage_inventory_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_khaas_storage_inventory',
    `source_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_product (denormalized)',
    `khaas_business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units (denormalized)',
    `change_type` ENUM('received', 'used', 'adjustment', 'wastage') NOT NULL COMMENT 'Type of movement',
    `quantity_before` DECIMAL(10,3) NOT NULL COMMENT 'Stock before change',
    `quantity_change` DECIMAL(10,3) NOT NULL COMMENT 'Amount changed (+received, -used)',
    `quantity_after` DECIMAL(10,3) NOT NULL COMMENT 'Stock after change',
    `reference_order_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_order — the NF order that delivered these items',
    `notes` TEXT NULL,
    `created_by` INT NULL COMMENT 'FK to t_sys_user',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_ksl_inventory` (`storage_inventory_id`),
    INDEX `idx_ksl_product` (`source_product_id`),
    INDEX `idx_ksl_bu` (`khaas_business_unit_id`),
    INDEX `idx_ksl_type` (`change_type`),
    INDEX `idx_ksl_order` (`reference_order_id`),
    INDEX `idx_ksl_created` (`created_at`),
    
    CONSTRAINT `fk_ksl_inventory` FOREIGN KEY (`storage_inventory_id`) 
        REFERENCES `t_crm_khaas_storage_inventory` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ksl_product` FOREIGN KEY (`source_product_id`) 
        REFERENCES `t_crm_prod_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ksl_bu` FOREIGN KEY (`khaas_business_unit_id`) 
        REFERENCES `t_fin_business_units` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ksl_order` FOREIGN KEY (`reference_order_id`) 
        REFERENCES `t_crm_prod_order` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ksl_created_by` FOREIGN KEY (`created_by`) 
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks all storage movements: received from NF orders, used/consumed, adjustments, wastage.';

SELECT '✅ Step 3: Created t_crm_khaas_storage_log' as Status;


-- =============================================
-- STEP 4: Khaas Storage Orders
-- Links Khaas storage orders to the main order system
-- Tracks which orders were placed from the storage screen
-- =============================================

CREATE TABLE IF NOT EXISTS `t_crm_khaas_storage_order` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_order — the actual order in the main system',
    `khaas_business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units',
    `status` ENUM('pending', 'confirmed', 'delivered', 'received', 'cancelled') NOT NULL DEFAULT 'pending'
        COMMENT 'pending=just placed, confirmed=NF confirmed, delivered=NF delivered, received=Khaas confirmed receipt into storage, cancelled=cancelled',
    `received_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When Khaas confirmed receipt into storage inventory',
    `received_by` INT NULL COMMENT 'FK to t_sys_user — who confirmed receipt',
    `notes` TEXT NULL,
    `created_by` INT NULL COMMENT 'FK to t_sys_user — who placed the storage order',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uq_storage_order` (`order_id`),
    INDEX `idx_kso_bu` (`khaas_business_unit_id`),
    INDEX `idx_kso_status` (`status`),
    INDEX `idx_kso_created` (`created_at`),
    
    CONSTRAINT `fk_kso_order` FOREIGN KEY (`order_id`) 
        REFERENCES `t_crm_prod_order` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_kso_bu` FOREIGN KEY (`khaas_business_unit_id`) 
        REFERENCES `t_fin_business_units` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_kso_received_by` FOREIGN KEY (`received_by`) 
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_kso_created_by` FOREIGN KEY (`created_by`) 
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks orders placed from Khaas storage screen. Links to main order system.';

SELECT '✅ Step 4: Created t_crm_khaas_storage_order' as Status;


-- =============================================
-- STEP 5: Khaas Storage Settings (config)
-- Stores the phone number and customer ID for the "Khaas Warehouse" customer
-- =============================================

INSERT INTO t_fin_config (config_key, config_value, description, business_unit_id) VALUES
    ('KHAAS_STORAGE_CUSTOMER_PHONE', '', 'Phone number for Khaas Warehouse customer (used when placing NF meat orders)', 2),
    ('KHAAS_STORAGE_CUSTOMER_ID', '', 'Customer ID in t_crm_prod_customer for Khaas Warehouse (auto-created on first order)', 2)
ON DUPLICATE KEY UPDATE updated_at = NOW();

SELECT '✅ Step 5: Created Khaas storage config entries' as Status;


-- =============================================
-- VERIFICATION
-- =============================================

SELECT '✅ Migration add_khaas_storage_feb17_2026.sql completed successfully' as Status;

-- Show all new tables
SELECT TABLE_NAME, TABLE_COMMENT 
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME LIKE 't_crm_khaas_storage%'
ORDER BY TABLE_NAME;

-- Show config entries
SELECT config_key, config_value, description, business_unit_id
FROM t_fin_config
WHERE config_key LIKE 'KHAAS_STORAGE%';

-- =============================================
-- NOTES FOR IMPLEMENTATION
-- =============================================
-- 
-- FLOW 1: Place a storage order
-- 1. User picks products from t_crm_khaas_storage_config (configured NF products)
-- 2. Backend creates a real order in t_crm_prod_order with:
--    - customer = "Khaas Warehouse" (auto-created, phone from config)
--    - items from the NF product catalog
--    - order_status = 'new'
-- 3. A record is created in t_crm_khaas_storage_order linking to the order
-- 4. The order follows normal NF fulfillment flow
--
-- FLOW 2: Receive delivered order into storage
-- 1. When the NF order is delivered (status changes to 'delivered' or 'completed')
--    - Manual: User presses "Receive into Storage" in the Khaas storage screen
--    - (Future: auto-detect via order status webhook/listener)
-- 2. For each line item in the order:
--    - t_crm_khaas_storage_inventory.quantity_on_hand += delivered quantity
--    - A "received" log entry is created in t_crm_khaas_storage_log
-- 3. t_crm_khaas_storage_order.status = 'received'
--
-- FLOW 3: Use storage items
-- 1. User selects a product and enters quantity used
-- 2. t_crm_khaas_storage_inventory.quantity_on_hand -= used quantity
-- 3. A "used" log entry is created in t_crm_khaas_storage_log
--
-- ACCESS CONTROL:
-- - Only Taimur/admin can configure which products appear (t_crm_khaas_storage_config)
-- - Only Taimur/admin can edit the storage customer phone number
-- - Any Khaas user can place storage orders and mark items as used
-- - Any Khaas user can view storage inventory and order history
