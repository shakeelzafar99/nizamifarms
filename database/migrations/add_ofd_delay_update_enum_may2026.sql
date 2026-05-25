-- =====================================================
-- Qurbani Auto WhatsApp — extend trigger_event ENUM
-- Date: May 2026 (rev2 — delay-update flow)
-- Description:
--   Adds 'ofd_delay_update' to t_ops_qurbani_wa_log.trigger_event so
--   the new manager-triggered "Send updated times" button on the
--   mobile Qurbani Rider Route screen can log its sends.
--
--   The new value is independent of the existing 'ofd' value so the
--   original auto-send and any subsequent delay-updates can coexist
--   in the log without overwriting each other (the manual flow
--   compares delivery_time_used across BOTH trigger values to find
--   the most-recent baseline).
--
--   Cooldown for the delay-update is enforced in the service layer
--   (default 15 min/line-item via qurbani_wa_ofd_delay_resend_cooldown_minutes),
--   not at the schema level — adjustable without a migration.
--
-- INSTRUCTIONS:
--   Run once on production. The ALTER is idempotent in the sense
--   that adding an existing ENUM value is a no-op error, so re-running
--   is safe to attempt (MySQL will reject with "duplicate value" but
--   not change anything).
-- =====================================================


ALTER TABLE `t_ops_qurbani_wa_log`
    MODIFY COLUMN `trigger_event` ENUM('slaughtered','ofd','ofd_delay_update') NOT NULL
        COMMENT 'Which Qurbani lifecycle event fired this message. ofd_delay_update is a manager-triggered re-send when the route ETA has slipped > qurbani_wa_ofd_delay_threshold_minutes (default 30).';


-- =====================================================
-- Verify
-- =====================================================

SELECT
    COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_ops_qurbani_wa_log'
  AND COLUMN_NAME = 'trigger_event';
