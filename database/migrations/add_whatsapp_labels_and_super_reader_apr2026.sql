-- =============================================================================
-- WhatsApp: Super-reader, Mark-Unread, Labels (Phase 1)
--
-- Run this in MySQL Workbench against production. Idempotent — safe to re-run.
--
-- This migration adds:
--   1. `global_read_at` column on `t_wa_conversations` — when a super-reader
--      (Taimur) marks a conversation as read, we stamp this column so the
--      conversation is considered read for every user. Other users' personal
--      read rows still take precedence over this column if they read first.
--   2. Two new mobile permissions:
--        - `whatsapp_super_reader`      → reading marks it read for all users.
--        - `manage_whatsapp_labels`     → can create/edit/delete labels in the
--                                         shared label library (applying
--                                         existing labels is open to anyone
--                                         with view_whatsapp_messages).
--   3. `t_wa_labels` — shared label library.
--        - `name`, `color`, `user_id` (nullable — user-mention labels set this
--          for Phase 2 notifications), `is_system` (seed-protected).
--   4. `t_wa_conversation_labels` — pivot table mapping labels to
--      conversations (many-to-many), with applied_by / applied_at for audit.
--   5. Seeded default labels so the UI isn't empty on first load.
-- =============================================================================

-- 1. Global-read marker for super-reader support.
ALTER TABLE `t_wa_conversations`
ADD COLUMN IF NOT EXISTS `global_read_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Set when a user with whatsapp_super_reader permission marks the conversation read. Any inbound message created before this timestamp is considered read for ALL users. Cleared when a super-reader marks-unread.'
AFTER `unread_count`;

-- Helpful for unread calculations that filter on this column.
-- NOTE: MySQL doesn't support `IF NOT EXISTS` on ADD INDEX reliably across
-- versions. If you ever re-run this migration, this one statement will
-- error with "Duplicate key name 'idx_global_read_at'" — that's harmless,
-- just skip it and continue with the rest of the file.
ALTER TABLE `t_wa_conversations`
    ADD INDEX `idx_global_read_at` (`global_read_at`);

-- 2. New mobile permissions.
INSERT INTO `t_sys_mobile_permission`
    (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`, `is_active`)
VALUES
    ('whatsapp_super_reader', 'WhatsApp: Super Reader',
     'store_mode_orders',
     'When a user with this permission marks a conversation as read, it becomes read for every staff member. Used for owner/management roles.',
     28, 1),
    ('manage_whatsapp_labels', 'Manage WhatsApp Labels',
     'store_mode_orders',
     'Can create, edit, and delete labels in the shared WhatsApp label library. Anyone with view_whatsapp_messages can apply existing labels to conversations.',
     29, 1)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description     = VALUES(description),
    is_active       = VALUES(is_active);

-- 2a. Grant whatsapp_super_reader to the Taimur role.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM `t_sys_role` r
CROSS JOIN `t_sys_mobile_permission` p
WHERE LOWER(r.urole_name) = 'taimur'
  AND p.permission_code = 'whatsapp_super_reader'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 2b. Grant manage_whatsapp_labels to Taimur + any Management role + Admin.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM `t_sys_role` r
CROSS JOIN `t_sys_mobile_permission` p
WHERE (LOWER(r.urole_name) = 'taimur' OR LOWER(r.urole_name) LIKE '%management%')
  AND p.permission_code = 'manage_whatsapp_labels'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Admin (role_id = 1) always gets both, as a safety net.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT 1, p.id
FROM `t_sys_mobile_permission` p
WHERE p.permission_code IN ('whatsapp_super_reader', 'manage_whatsapp_labels')
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 3. Shared label library.
CREATE TABLE IF NOT EXISTS `t_wa_labels` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(60) NOT NULL COMMENT 'Label display name — unique within the workspace.',
    `color` VARCHAR(20) NOT NULL DEFAULT '#6B7280'
        COMMENT 'Hex colour for label chips in the UI. Free-form so we can pick from a palette later without migrating.',
    `user_id` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'If set, this is a user-mention label — Phase 2 will send a push / highlight when applied. NULL for generic topic labels.',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Seeded/system label — protected from delete in the admin UI (users can still remove them from a conversation).',
    `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_label_name` (`name`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Conversation ↔ Label pivot (many-to-many). Cascade on conversation delete
-- so we don't leave orphan rows; labels themselves are deleted independently.
CREATE TABLE IF NOT EXISTS `t_wa_conversation_labels` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `label_id` BIGINT UNSIGNED NOT NULL,
    `applied_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_conv_label` (`conversation_id`, `label_id`),
    INDEX `idx_label_id` (`label_id`),
    INDEX `idx_applied_at` (`applied_at`),
    CONSTRAINT `fk_wacl_conv` FOREIGN KEY (`conversation_id`)
        REFERENCES `t_wa_conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wacl_label` FOREIGN KEY (`label_id`)
        REFERENCES `t_wa_labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Seed a small set of default generic labels. Admins can edit/delete them
-- later; they are flagged is_system=1 so the admin UI can warn (not block).
INSERT INTO `t_wa_labels` (`name`, `color`, `user_id`, `is_system`)
VALUES
    ('Order Request', '#16A34A', NULL, 1),
    ('Follow-up',     '#F59E0B', NULL, 1),
    ('Complaint',     '#DC2626', NULL, 1),
    ('VIP',           '#7C3AED', NULL, 1)
ON DUPLICATE KEY UPDATE color = VALUES(color), is_system = VALUES(is_system);

-- =============================================================================
-- Verification (run after the above to confirm everything is wired up):
--
--   SELECT r.urole_name, p.permission_code
--   FROM t_sys_role r
--   JOIN t_sys_role_mobile_permission rp ON rp.role_id = r.id
--   JOIN t_sys_mobile_permission     p  ON p.id = rp.mobile_permission_id
--   WHERE p.permission_code IN ('whatsapp_super_reader', 'manage_whatsapp_labels')
--   ORDER BY r.urole_name;
--
--   SELECT * FROM t_wa_labels;
-- =============================================================================
