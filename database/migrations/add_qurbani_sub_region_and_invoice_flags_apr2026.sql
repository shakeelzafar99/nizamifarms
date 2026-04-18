-- =====================================================
-- Migration: Add qurbani_sub_region + show_in_invoice
-- Created: April 2026
-- =====================================================

-- 1. Add qurbani_sub_region column to line items table
ALTER TABLE `t_crm_prod_order_line_item`
ADD COLUMN IF NOT EXISTS `qurbani_sub_region` VARCHAR(100) NULL
COMMENT 'Sub-region within qurbani region (dependent on qurbani_region)'
AFTER `qurbani_region`;

-- 2. Add show_in_invoice flag to field options table
ALTER TABLE `t_crm_qurbani_field_options`
ADD COLUMN IF NOT EXISTS `show_in_invoice` TINYINT(1) NOT NULL DEFAULT 0
COMMENT '1 = show this field type on invoices and WhatsApp messages'
AFTER `is_default`;

-- 3. Add index for sub_region filtering
ALTER TABLE `t_crm_prod_order_line_item`
ADD INDEX IF NOT EXISTS `idx_li_qurbani_sub_region` (`qurbani_sub_region`);

-- 4. Add qurbani_sub_region to the main order table too (for sync/filtering like other fields)
ALTER TABLE `t_crm_prod_order`
ADD COLUMN IF NOT EXISTS `qurbani_sub_region` VARCHAR(100) NULL
COMMENT 'Synced from first line item for quick filtering'
AFTER `qurbani_region`;
