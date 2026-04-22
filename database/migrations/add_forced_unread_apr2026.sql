-- =============================================================================
-- WhatsApp: "forced_unread_at" signal on t_wa_conversation_reads (Apr 2026)
--
-- Run this in MySQL Workbench against production. Idempotent — safe to re-run.
--
-- WHY this exists:
--   The normal unread calc in getConversations() excludes any inbound message
--   that has a subsequent outbound reply (the "reply = read for everyone"
--   rule). That's the right default, but it means clicking "Mark as Unread"
--   on a conversation where staff has already replied produces 0 unread —
--   because there are no eligible inbound messages left to count.
--
--   This column is a per-user override: when set, the conversation is
--   reported as unread (count = 1 minimum) regardless of the NOT EXISTS
--   check. Cleared automatically on the next markRead so it never lingers.
-- =============================================================================

ALTER TABLE `t_wa_conversation_reads`
ADD COLUMN IF NOT EXISTS `forced_unread_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Per-user Mark-Unread signal. When NOT NULL, the conversation shows as unread for this user even if they have already replied. Cleared when the user opens the chat again (markRead).'
AFTER `last_read_at`;

-- Index to keep the post-processing "WHERE forced_unread_at IS NOT NULL"
-- lookups in the inbox cheap — typically only a handful of rows per user.
-- NOTE: MySQL doesn't support IF NOT EXISTS on ADD INDEX reliably across
-- versions. On a re-run this line may throw "Duplicate key name" — harmless,
-- just skip it and continue.
ALTER TABLE `t_wa_conversation_reads`
    ADD INDEX `idx_forced_unread_at` (`user_id`, `forced_unread_at`);
