-- ============================================================================
-- Migration: Add two new Qurbani attributes — qurbani_type (standard/custom)
--            and qurbani_paya (standard/bhunnay paye)
-- Date: April 2026
-- Purpose:
--   * Lets orders tag each line item with "Qurbani Type" and "Paya"
--   * Values are customisable from the Qurbani Settings page like every
--     other qurbani dropdown (day / slot / region / etc.)
--   * Both web and mobile read these through the existing
--     t_crm_qurbani_field_options table, so adding rows below immediately
--     surfaces them in the admin + rider apps.
-- Notes:
--   * `t_crm_qurbani_field_options` already has field_name as VARCHAR, so
--     we don't need to touch its schema — just seed two new field_name
--     families: 'qurbani_type' and 'qurbani_paya'.
--   * New columns default to NULL (non-qurbani orders and legacy qurbani
--     orders stay happy; UI hides them unless set).
--   * If a step errors "Duplicate column name" / "Duplicate key name",
--     it's safe to ignore — it just means this part already ran.
-- Run each statement block one at a time on MySQL Workbench.
-- ============================================================================

-- STEP 1: Add new columns to the line-item table.
-- Kept VARCHAR to match the other qurbani_* text columns (day / slot / etc.).
ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_type` VARCHAR(50) NULL
    COMMENT 'Qurbani Type attribute (e.g. standard, custom). Customisable from Qurbani Settings.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_paya` VARCHAR(50) NULL
    COMMENT 'Qurbani Paya attribute (e.g. standard, bhunnay paye). Customisable from Qurbani Settings.';

-- STEP 2: Indexes so the main qurbani-orders grid can filter on these fast.
CREATE INDEX idx_li_qurbani_type ON t_crm_prod_order_line_item (qurbani_type);
CREATE INDEX idx_li_qurbani_paya ON t_crm_prod_order_line_item (qurbani_paya);

-- STEP 3: Seed the default option values into the shared options table.
-- Admins can rename / add / remove these from Qurbani Settings afterwards.
-- `show_in_invoice` defaults to 1 so the new attributes render on the
-- invoice out of the box (can be toggled off in Settings if desired).
INSERT INTO `t_crm_qurbani_field_options`
    (`field_name`, `option_value`, `parent_id`, `delivery_type_parent_id`,
     `display_order`, `is_active`, `is_default`, `show_in_invoice`,
     `created_at`, `updated_at`)
VALUES
    ('qurbani_type',  'Standard',     NULL, NULL, 1, 1, 1, 1, NOW(), NOW()),
    ('qurbani_type',  'Custom',       NULL, NULL, 2, 1, 0, 1, NOW(), NOW()),
    ('qurbani_paya',  'Standard',     NULL, NULL, 1, 1, 1, 1, NOW(), NOW()),
    ('qurbani_paya',  'Bhunnay Paye', NULL, NULL, 2, 1, 0, 1, NOW(), NOW());

-- ============================================================================
-- Rollback helper (run only if you need to fully remove the feature):
--
-- DELETE FROM t_crm_qurbani_field_options
--   WHERE field_name IN ('qurbani_type', 'qurbani_paya');
-- ALTER TABLE t_crm_prod_order_line_item DROP INDEX idx_li_qurbani_type;
-- ALTER TABLE t_crm_prod_order_line_item DROP INDEX idx_li_qurbani_paya;
-- ALTER TABLE t_crm_prod_order_line_item DROP COLUMN qurbani_type;
-- ALTER TABLE t_crm_prod_order_line_item DROP COLUMN qurbani_paya;
-- ============================================================================
