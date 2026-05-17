-- =====================================================
-- Qurbani Item Status & Per-Item Rider Assignment
-- Date: May 14, 2026
-- Description:
--   1. Adds per-line-item Qurbani status (open/slaughtered/out_for_delivery/delivered)
--      to t_crm_prod_order_line_item. Deliberately separate from the existing
--      `preparation_status` column so:
--        * regular orders' Mark-as-Prepared inventory flow stays untouched
--        * Qurbani items can have a richer 4-step lifecycle without affecting
--          inventory deduction triggers.
--   2. Adds per-line-item rider assignment (qurbani_assigned_rider_user_id) so
--      operations can split one customer order across multiple riders by slot.
--      The order-level t_crm_prod_order.assigned_rider_user_id is left alone
--      so non-Qurbani flows are unaffected.
--   3. Adds audit timestamps for status changes.
--   4. Seeds 4 default status options into t_crm_qurbani_field_options so the
--      labels are admin-customisable from the existing Qurbani Settings page.
--      `delivered` is given a high display_order so it always sorts last; the
--      mobile client also has a hard rule that NULL/open is always first and
--      delivered is always last regardless of display_order.
--
-- INSTRUCTIONS: Run each section one at a time. Safe to re-run.
-- Apply in production with: mysql ... < add_qurbani_item_status_and_rider_may2026.sql
-- =====================================================


-- ============================================================================
-- SECTION 1: Per-line-item Qurbani status + rider assignment columns
-- ============================================================================

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_item_status` VARCHAR(50) NULL
    COMMENT 'Qurbani-only per-line-item lifecycle status. Values come from t_crm_qurbani_field_options where field_name="qurbani_item_status". NULL is treated as "open" / unassigned.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_assigned_rider_user_id` INT NULL
    COMMENT 'Per-line-item rider assignment (qurbani only). FK to t_sys_user.id. Allows splitting one order across multiple riders by slot. Leave NULL for regular (non-qurbani) orders.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_status_updated_at` TIMESTAMP NULL
    COMMENT 'When qurbani_item_status was last changed.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_status_updated_by` INT NULL
    COMMENT 'User who last changed qurbani_item_status (FK to t_sys_user.id).';

CREATE INDEX `idx_li_qurbani_item_status` ON `t_crm_prod_order_line_item` (`qurbani_item_status`);
CREATE INDEX `idx_li_qurbani_assigned_rider` ON `t_crm_prod_order_line_item` (`qurbani_assigned_rider_user_id`);


-- ============================================================================
-- SECTION 2: Seed status options into t_crm_qurbani_field_options
-- option_value stores the slug (open / slaughtered / out_for_delivery / delivered)
-- so DB rows are stable even if the operator renames the user-facing label.
-- A future column `option_label` would let labels diverge from the slug; for
-- now we keep slugs human-readable and let the mobile client title-case them.
-- ============================================================================

INSERT INTO `t_crm_qurbani_field_options`
    (`field_name`, `option_value`, `display_order`, `is_active`)
VALUES
    ('qurbani_item_status', 'open',             1, 1),
    ('qurbani_item_status', 'slaughtered',      2, 1),
    ('qurbani_item_status', 'out_for_delivery', 3, 1),
    ('qurbani_item_status', 'delivered',       99, 1)
ON DUPLICATE KEY UPDATE
    display_order = VALUES(display_order),
    is_active     = VALUES(is_active);


-- ============================================================================
-- SECTION 3: Verify
-- ============================================================================

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order_line_item'
  AND COLUMN_NAME IN (
    'qurbani_item_status',
    'qurbani_assigned_rider_user_id',
    'qurbani_status_updated_at',
    'qurbani_status_updated_by'
  )
ORDER BY ORDINAL_POSITION;

SELECT field_name, option_value, display_order, is_active
FROM t_crm_qurbani_field_options
WHERE field_name = 'qurbani_item_status'
ORDER BY display_order;
