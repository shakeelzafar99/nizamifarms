-- =====================================================
-- Qurbani Item Status Timeline Timestamps
-- Date: May 17, 2026
-- Description:
--   Adds three timestamp columns on t_crm_prod_order_line_item to record
--   the moment a line item transitioned INTO each downstream Qurbani
--   status (slaughtered / out_for_delivery / delivered). The existing
--   qurbani_status_updated_at column only tracks the last change — we
--   keep it for backward compatibility but add the per-state stamps so
--   the mobile region-view card can render a small timeline:
--
--     🔪 Slaughtered    14 May, 11:23 AM
--     🛵 Out for Delivery 14 May,  3:05 PM
--     ✅ Delivered       14 May,  4:42 PM
--
--   These are SET ONCE (or kept as the latest if a status is re-entered
--   after being undone). NULL means the item never reached that state.
--   `open` is the default initial state and doesn't need a timestamp —
--   if you need it, fall back to t_crm_prod_order_line_item.created_at.
--
-- INSTRUCTIONS: Run each ALTER one at a time. Safe to re-run because
-- MySQL throws on duplicate column names — copy-paste only the ones that
-- haven't run yet, or wrap with IF NOT EXISTS in your client if you have
-- MySQL 8+.
-- Apply in production with: mysql ... < add_qurbani_status_timeline_may2026.sql
-- =====================================================


ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_slaughtered_at` TIMESTAMP NULL
    COMMENT 'When this line item first transitioned to slaughtered. NULL if it never reached this state.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_out_for_delivery_at` TIMESTAMP NULL
    COMMENT 'When this line item first transitioned to out_for_delivery. NULL if it never reached this state.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_delivered_at` TIMESTAMP NULL
    COMMENT 'When this line item first transitioned to delivered. NULL if it never reached this state.';


-- ============================================================================
-- Verify
-- ============================================================================

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order_line_item'
  AND COLUMN_NAME IN (
    'qurbani_slaughtered_at',
    'qurbani_out_for_delivery_at',
    'qurbani_delivered_at'
  )
ORDER BY ORDINAL_POSITION;
