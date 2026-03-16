-- ============================================================
-- Khaas Production Demand & Recipe Mapping
-- Created: 2026-03-03
-- ============================================================

-- 1. Product Recipe Mapping: Raw storage material → Finished Khaas product
-- Maps NF raw materials (in storage) to Khaas finished products
CREATE TABLE IF NOT EXISTS `t_crm_khaas_product_recipe` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `khaas_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_product — the finished Khaas product (e.g. Chicken Cheese Samosa)',
    `storage_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_product — the NF raw material in storage (e.g. Chicken Thigh Boneless)',
    `storage_variant_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product_variant — optional specific variant',
    `ratio_kg` DECIMAL(8,3) NOT NULL DEFAULT 1.000 COMMENT 'Kg of raw material needed per kg of demand',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_khaas_product` (`khaas_product_id`),
    INDEX `idx_storage_product` (`storage_product_id`),
    UNIQUE KEY `uq_khaas_storage` (`khaas_product_id`, `storage_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Production Demand: Daily demand header
CREATE TABLE IF NOT EXISTS `t_crm_khaas_production_demand` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units',
    `demand_date` DATE NOT NULL COMMENT 'The date this demand is for (typically next day)',
    `status` ENUM('draft', 'submitted', 'accepted', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    `notes` TEXT NULL,
    `created_by` INT NOT NULL COMMENT 'FK to t_sys_user — who created the demand',
    `accepted_by` INT NULL COMMENT 'FK to t_sys_user — who accepted/started processing',
    `accepted_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_bu_date` (`business_unit_id`, `demand_date`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Production Demand Items: Individual product demands
CREATE TABLE IF NOT EXISTS `t_crm_khaas_production_demand_item` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `demand_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_khaas_production_demand',
    `khaas_product_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_product — the finished product to produce',
    `quantity_kg` DECIMAL(8,3) NOT NULL COMMENT 'Weight in kg demanded',
    `storage_product_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product — resolved raw material from recipe',
    `storage_variant_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product_variant',
    `storage_deducted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether storage inventory has been deducted',
    `storage_deducted_qty` DECIMAL(8,3) NULL COMMENT 'Actual qty deducted from storage',
    `batch_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_product_batch — auto-created batch',
    `status` ENUM('pending', 'accepted', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_demand` (`demand_id`),
    INDEX `idx_product` (`khaas_product_id`),
    INDEX `idx_batch` (`batch_id`),
    CONSTRAINT `fk_demand_item_demand` FOREIGN KEY (`demand_id`) REFERENCES `t_crm_khaas_production_demand` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Recipe mappings are managed through the mobile app UI
-- (Storage tab → Inventory → Recipes button)
-- No seed data needed — admin maps products in the app.
-- ============================================================
