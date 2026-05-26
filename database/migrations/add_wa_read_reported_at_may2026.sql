-- =====================================================
-- WhatsApp read-receipt tracking column
-- Date: May 2026
-- Description:
--   Adds `read_reported_at` to t_wa_messages so we know which
--   inbound wamids have already been pushed to Meta via the
--   markAsRead endpoint. Without this column the polling code in
--   WhatsAppService::markInboundAsReadOnWhatsApp() can't track
--   per-message state and ends up retrying the SAME dead wamids
--   every minute — spamming the error log with
--     (#100) Invalid parameter — Message ID does not exist
--   for messages older than 7 days (Meta's wamid retention window),
--   wamids belonging to a previous WABA context, or wamids that
--   were already marked read by another channel.
--
--   How the application uses it:
--     - Column exists  → poller filters whereNull('read_reported_at')
--                        so each wamid is attempted exactly once.
--     - Column missing → poller falls back to "last 50 inbound +
--                        Cache-based dead-wamid sentinel" so the
--                        spam is bounded even before this migration
--                        runs (defence in depth — see
--                        WhatsAppService::markInboundAsReadOnWhatsApp).
--
--   Safe to run multiple times — INFORMATION_SCHEMA guard skips
--   the ALTER when the column already exists.
-- =====================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 't_wa_messages'
         AND COLUMN_NAME = 'read_reported_at') = 0,
    'ALTER TABLE `t_wa_messages` ADD COLUMN `read_reported_at` TIMESTAMP NULL DEFAULT NULL COMMENT ''When we last called Meta markAsRead for this inbound wamid (success OR failure — stamps after one attempt either way to prevent retry loops on dead wamids).'' AFTER `created_at`',
    'SELECT ''read_reported_at column already exists on t_wa_messages — skipping'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index so the whereNull('read_reported_at') AND direction='inbound'
-- query on conversation open doesn't scan the full table when
-- t_wa_messages grows past a million rows.
SET @sql := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 't_wa_messages'
         AND INDEX_NAME = 'idx_wa_msg_inbound_unreported') = 0,
    'CREATE INDEX idx_wa_msg_inbound_unreported ON t_wa_messages (conversation_id, direction, read_reported_at)',
    'SELECT ''idx_wa_msg_inbound_unreported already exists — skipping'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill: stamp every existing inbound wamid with NOW() so we
-- don't try to re-mark-as-read 100k historic messages the next time
-- a manager opens a conversation. This is a one-shot UPDATE that's
-- idempotent — only NULL rows are touched, and the column was just
-- added as NULL above for first runs.
UPDATE `t_wa_messages`
   SET `read_reported_at` = NOW()
 WHERE `direction` = 'inbound'
   AND `wa_message_id` IS NOT NULL
   AND `read_reported_at` IS NULL;
