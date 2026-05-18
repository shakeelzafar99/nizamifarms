-- =====================================================
-- Qurbani Live ETA Tracking — per-dispatch flag
-- Date: May 18, 2026
-- Description:
--   Adds a single boolean column on t_crm_prod_order_line_item that
--   gates the "Live ETA tracking" auto-refresh loop on the rider's
--   delivery screen.
--
--   Replaces the earlier client-side AsyncStorage flag:
--     - Server-stored so manager + rider stay in sync without polling
--       each other. Either one can flip it.
--     - Per-dispatch by construction — every new dispatch resets the
--       column to 0 in the dispatchQurbaniRoute() target='pending'
--       UPDATE block. So a rider who turned it on for one trip won't
--       have it silently still on for the next one.
--     - 1 column, no separate table — feature is a single flag and
--       all line items in a dispatch share its value.
--
--   Default 0 is critical: most dispatches don't want live tracking
--   (it costs a Google Directions call every N minutes). Riders or
--   managers opt in per dispatch when they expect traffic surprises.
--
-- INSTRUCTIONS: Run the ALTER once. Safe to re-run because MySQL
-- throws a clean error on duplicate column names — copy-paste only
-- if the previous run failed.
-- Apply in production with: mysql ... < add_qurbani_live_tracking_may2026.sql
-- =====================================================


ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_live_tracking_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Per-dispatch live ETA tracking flag (0/1). When 1 the rider app auto-refreshes ETAs every qurbani_eta_refresh_minutes from rider GPS. Reset to 0 on every new dispatch press.';


-- ============================================================================
-- Verify
-- ============================================================================

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order_line_item'
  AND COLUMN_NAME = 'qurbani_live_tracking_enabled';
