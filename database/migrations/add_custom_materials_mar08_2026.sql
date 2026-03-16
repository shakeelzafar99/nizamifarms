-- =====================================================
-- Custom Raw Materials for Recipe Mapping
-- Materials not tracked in inventory (e.g. Aloo, Cheese)
-- Run: mysql -u root -p nizamifarms < add_custom_materials_mar08_2026.sql
-- =====================================================

-- 1. Create custom materials table
CREATE TABLE IF NOT EXISTS `t_crm_khaas_custom_material` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `unit` VARCHAR(50) NOT NULL DEFAULT 'kg',
    `business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_name_bu` (`name`, `business_unit_id`),
    INDEX `idx_business_unit` (`business_unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Modify recipe table: allow custom materials
ALTER TABLE `t_crm_khaas_product_recipe`
    MODIFY COLUMN `storage_product_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product — NF raw material (NULL for custom materials)',
    ADD COLUMN `custom_material_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_khaas_custom_material (NULL for inventory materials)' AFTER `storage_variant_id`,
    ADD INDEX `idx_custom_material` (`custom_material_id`);

-- 3. Add unique key for custom material recipes (prevent duplicates)
ALTER TABLE `t_crm_khaas_product_recipe`
    ADD UNIQUE KEY `uq_khaas_custom` (`khaas_product_id`, `custom_material_id`);
