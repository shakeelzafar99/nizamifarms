-- =============================================================================
-- Marketing-template dedup (manual + campaign) — Apr 2026
--
-- Run this in MySQL Workbench against production. Idempotent — safe to re-run.
--
-- This migration adds:
--   1. `wa_marketing_dedup_window_days` row in `t_fin_config` (default 30).
--      Setting it to 0 disables the entire feature (no checks, no popup, no
--      pinned indicator) so an operator can flip it off without a deploy.
--      Both manual sends (chat window) and new campaigns inherit this value.
--
--   2. `forced_dedup_override` column on `t_wa_messages` — flagged TRUE on
--      outbound rows where an authorised operator chose "Send Anyway"
--      despite the recent-marketing dedup hit. Lets us audit overrides
--      later (e.g. weekly report of who is bypassing the rule).
--
--   3. New mobile permission `whatsapp_marketing_dedup_override` —
--      grants the right to bypass the recent-marketing block via the
--      "Send Anyway" path. Seeded for Taimur, any Management role, and
--      Admin (role_id = 1). Anyone WITHOUT this permission gets a hard
--      block when they try to re-send a marketing template inside the
--      window. Anyone with view_whatsapp_messages can still SEND marketing
--      templates that are NOT inside the window — the gate is only on
--      override.
--
-- Why a separate setting and not reuse `dedup_window_days` on campaigns?
-- Per-campaign dedup is for batched sends (existing behaviour preserved).
-- This is a simpler, system-wide rule that prevents customer fatigue
-- regardless of how the template gets sent. New campaigns will read this
-- value as their default at create time.
-- =============================================================================

-- 1. Global setting (default 30 days; 0 disables the feature).
INSERT IGNORE INTO `t_fin_config`
    (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES
    ('wa_marketing_dedup_window_days',
     '30',
     'Number of days during which a marketing-category WhatsApp template cannot be re-sent to the same customer. 0 disables the rule entirely (manual chat sends and new campaigns).',
     NOW(), NOW());

-- 2. Audit column on outbound messages.
-- NOTE: MySQL versions differ on `IF NOT EXISTS` for ADD COLUMN; this form
-- works on MySQL 8 and MariaDB 10.0+. If your version errors with
-- "Duplicate column", that's harmless — the column already exists.
ALTER TABLE `t_wa_messages`
ADD COLUMN IF NOT EXISTS `forced_dedup_override` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Set to 1 when this outbound message was sent via the "Send Anyway" path despite a recent-marketing dedup hit. Used for override auditing only — has no behavioural effect on subsequent dedup checks.'
AFTER `template_name`;

-- 3. New mobile permission.
INSERT INTO `t_sys_mobile_permission`
    (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`, `is_active`)
VALUES
    ('whatsapp_marketing_dedup_override', 'WhatsApp: Override Marketing Dedup',
     'store_mode_orders',
     'Lets the user bypass the "marketing template already sent recently" guard via the Send Anyway prompt. Without this permission the user sees a hard block message and cannot re-send the template inside the configured window.',
     30, 1)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description     = VALUES(description),
    is_active       = VALUES(is_active);

-- 3a. Grant to Taimur + any Management role.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM `t_sys_role` r
CROSS JOIN `t_sys_mobile_permission` p
WHERE (LOWER(r.urole_name) = 'taimur' OR LOWER(r.urole_name) LIKE '%management%')
  AND p.permission_code = 'whatsapp_marketing_dedup_override'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 3b. Admin (role_id = 1) safety net.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT 1, p.id
FROM `t_sys_mobile_permission` p
WHERE p.permission_code = 'whatsapp_marketing_dedup_override'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =============================================================================
-- Verification (run after the above to confirm everything is wired up):
--
--   SELECT config_key, config_value, description
--   FROM t_fin_config
--   WHERE config_key = 'wa_marketing_dedup_window_days';
--
--   SELECT r.urole_name, p.permission_code
--   FROM t_sys_role r
--   JOIN t_sys_role_mobile_permission rp ON rp.role_id = r.id
--   JOIN t_sys_mobile_permission     p  ON p.id = rp.mobile_permission_id
--   WHERE p.permission_code = 'whatsapp_marketing_dedup_override'
--   ORDER BY r.urole_name;
--
--   SHOW COLUMNS FROM t_wa_messages LIKE 'forced_dedup_override';
-- =============================================================================
