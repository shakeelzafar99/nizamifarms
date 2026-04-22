-- =============================================================================
-- WhatsApp Labels — Phase 2: User Mentions (Apr 2026)
--
-- Run in MySQL Workbench against production. Idempotent — safe to re-run.
--
-- This migration does TWO things:
--
--   1. Adds `mention_seen_at` to the conversation-label pivot so the server
--      can track "this @mention has not yet been seen by the mentioned
--      user". Cleared automatically when the mentioned user opens the
--      conversation via markRead.
--
--   2. Seeds one user-mention label per staff member that currently holds
--      the `view_whatsapp_messages` (or limited) mobile permission. The
--      label name is the user's first-word-of-fullname prefixed with @,
--      coloured blue to visually distinguish from generic topic labels.
--      is_system=0 so admins can rename/delete them from the library UI.
--
--   Admins can still create MORE user-mention labels manually via the
--   label library — the seed only fills in the obvious ones.
-- =============================================================================

-- 1. Per-mention seen-at on the pivot. Only set for rows whose parent
--    label has user_id set (i.e. user-mention labels). Backend clears it
--    when the mentioned user opens the conversation.
ALTER TABLE `t_wa_conversation_labels`
ADD COLUMN IF NOT EXISTS `mention_seen_at` DATETIME NULL DEFAULT NULL
    COMMENT 'For user-mention labels (parent t_wa_labels.user_id IS NOT NULL): set when the mentioned user opens the conversation AFTER this label was applied. NULL = unread mention. Cleared on markRead.'
AFTER `applied_at`;

-- Composite lookup for the "unread mentions for user X" query. Covers the
-- typical inbox badge + filter use-case.
-- NOTE: re-run will error "Duplicate key name" — harmless, just skip.
ALTER TABLE `t_wa_conversation_labels`
    ADD INDEX `idx_mention_seen_at` (`mention_seen_at`);

-- 2. Seed user-mention labels for staff who can currently view WhatsApp.
--    Name pattern: "@Firstname" where Firstname is the first space-
--    separated token of t_sys_user.fullname. If two users share the same
--    first name the second seed will quietly skip (UNIQUE idx on name).
INSERT INTO `t_wa_labels` (`name`, `color`, `user_id`, `is_system`)
SELECT
    CONCAT('@', SUBSTRING_INDEX(TRIM(u.fullname), ' ', 1)) AS name,
    '#3B82F6' AS color,
    u.id AS user_id,
    0 AS is_system
FROM `t_sys_user` u
WHERE u.is_active = 1
  AND TRIM(COALESCE(u.fullname, '')) <> ''
  AND EXISTS (
        SELECT 1
        FROM `t_sys_user_role` ur
        JOIN `t_sys_role_mobile_permission` rmp ON rmp.role_id = ur.role_id
        JOIN `t_sys_mobile_permission` mp ON mp.id = rmp.mobile_permission_id
        WHERE ur.user_id = u.id
          AND mp.permission_code IN ('view_whatsapp_messages', 'view_whatsapp_messages_limited')
  )
  AND NOT EXISTS (
        SELECT 1 FROM `t_wa_labels` l WHERE l.user_id = u.id
  )
ON DUPLICATE KEY UPDATE
    -- If the label name clashed with an existing one we just keep whatever
    -- is already there — the library page lets admins fix it manually.
    color = VALUES(color);

-- =============================================================================
-- Verification (run these after the above):
--
--   SELECT l.id, l.name, l.color, l.user_id, u.fullname
--   FROM t_wa_labels l
--   LEFT JOIN t_sys_user u ON u.id = l.user_id
--   WHERE l.user_id IS NOT NULL
--   ORDER BY l.name;
--
--   DESC t_wa_conversation_labels;   -- should show mention_seen_at
-- =============================================================================
