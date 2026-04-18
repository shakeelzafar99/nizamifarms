-- Migration: Add delivery_type_parent_id to qurbani field options for slot-delivery type dependency
-- Also fix unique key to allow same slot value under different parents
-- Run on both dev and prod

-- 1. Drop the old unique key that prevents duplicate slot values across days
ALTER TABLE `t_crm_qurbani_field_options`
DROP INDEX IF EXISTS `uq_field_value`;

-- 2. Add delivery_type_parent_id column for slot -> delivery type linkage
ALTER TABLE `t_crm_qurbani_field_options`
ADD COLUMN IF NOT EXISTS `delivery_type_parent_id` INT NULL DEFAULT NULL
COMMENT 'For slots: links to delivery type option id'
AFTER `parent_id`;

-- 3. Create new unique key that includes parent_id and delivery_type_parent_id
-- This allows same option_value under different parents (e.g., "9-1" under Day 1 and Day 2)
ALTER TABLE `t_crm_qurbani_field_options`
ADD UNIQUE KEY `uq_field_value_parents` (`field_name`, `option_value`, `parent_id`, `delivery_type_parent_id`);

-- 4. Add qurbani_delete_enabled config for order deletion toggle
INSERT IGNORE INTO `t_fin_config` (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES ('qurbani_delete_enabled', '0', 'Enable/disable order deletion for Qurbani orders (Taimur only)', NOW(), NOW());
