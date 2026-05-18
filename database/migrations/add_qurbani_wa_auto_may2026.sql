-- =====================================================
-- Qurbani Auto WhatsApp Updates — log table
-- Date: May 2026
-- Description:
--   Tracks every automatic WhatsApp message the system sends as part
--   of the Qurbani operational updates feature (slaughter notice +
--   out-for-delivery notice). One row per (line_item, trigger_event)
--   that we successfully send — used for idempotency so the worker
--   never double-sends, and as an audit trail for the daily
--   /qurbani/wa-log review.
--
--   Skipped + failed attempts also write a row (status column) so
--   admins can see *why* something didn't go out (no phone, eta
--   missing, template not configured, etc).
--
--   No new columns on t_crm_prod_order_line_item — the per-trigger
--   scheduling fields (delay minutes, template names, master
--   switch) live in t_crm_config via ConfigModel::set(...) so they
--   can be edited from the settings page without a schema change.
--
-- INSTRUCTIONS:
--   Run once on production. Safe to re-run — IF NOT EXISTS
--   guard makes the CREATE idempotent.
-- =====================================================


CREATE TABLE IF NOT EXISTS `t_ops_qurbani_wa_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `line_item_id` BIGINT UNSIGNED NOT NULL,
    `order_id` BIGINT UNSIGNED NOT NULL,
    `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `trigger_event` ENUM('slaughtered','ofd') NOT NULL
        COMMENT 'Which Qurbani lifecycle event fired this message.',
    `template_name` VARCHAR(100) NOT NULL
        COMMENT 'WhatsApp template name as configured in t_wa_templates.',
    `wa_phone` VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'Phone number we actually sent to. Will equal qurbani_wa_test_phone when test mode is on.',
    `wa_message_id` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Meta message ID returned by WhatsAppService::sendTemplateMessage on success.',
    `conversation_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Foreign key into t_wa_conversations for the customer thread.',
    `status` ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent'
        COMMENT 'sent=Meta accepted; failed=API/network error; skipped=condition not met (no phone, eta missing, etc).',
    `skip_reason` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'When status=skipped or failed, the human-readable reason. Empty for status=sent.',
    `delivery_time_used` DATETIME NULL DEFAULT NULL
        COMMENT 'For OFD ETA-based mode, the computed (eta + buffer) timestamp we evaluated against. Lets you reverse-engineer why something fired when it did.',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Idempotency lookup — the worker checks
    -- "is there already a row WHERE li=? AND trig=? AND status='sent'"
    -- before sending. The composite index covers that lookup AND the
    -- /qurbani/wa-log audit query "all logs for this trigger today".
    INDEX `idx_li_trig_status` (`line_item_id`, `trigger_event`, `status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- Verify
-- =====================================================

SELECT
    COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_ops_qurbani_wa_log'
ORDER BY ORDINAL_POSITION;
