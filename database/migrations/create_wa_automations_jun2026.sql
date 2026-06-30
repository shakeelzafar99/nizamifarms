-- =============================================================================
-- WhatsApp Automations engine — Phase 1 scaffolding (Jun 2026)
--
-- Foundation for the event-triggered WhatsApp template automations (Tabs 2 & 3
-- of the WhatsApp automations feature: order-received, accepted->location,
-- invoice online/cash). Tab 1 (out-of-office replies) keeps using
-- t_wa_auto_replies and is NOT touched here.
--
-- The rule CATALOG lives in code (App\Services\WhatsApp\Automation\
-- AutomationRegistry) — these tables only hold (a) the operator's per-rule
-- on/off + template + settings, and (b) a send log used for "send once"
-- dedup and the activity tracker.
--
-- SAFETY: everything ships OFF.
--   * wa_automations_enabled defaults to '0' (master switch).
--   * A rule with no row in t_wa_automations is treated as DISABLED, so the
--     absence of data == off. No rules are seeded.
--   * Nothing in Phase 1 calls the dispatcher — the order/invoice trigger
--     seams are wired in Phases 2 & 3. This migration adds no behaviour.
--
-- Run in MySQL Workbench against LOCAL and PROD. Idempotent. No money/ledger
-- impact, no data modified.
-- =============================================================================

-- 1. Per-rule operator config. One row per rule the operator has touched.
CREATE TABLE IF NOT EXISTS `t_wa_automations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_key` VARCHAR(64) NOT NULL COMMENT 'Stable key matching a code-defined rule type in AutomationRegistry (e.g. order_received_new).',
  `enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Per-rule on/off. DEFAULT 0 — every automation is off until the operator turns it on.',
  `template_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Operator-chosen WhatsApp template (t_wa_templates.name). NULL = none chosen / use the type default.',
  `config_json` TEXT NULL DEFAULT NULL COMMENT 'JSON of the editable params this rule type permits (schedule rows, trigger choice, online/cash templates, etc.).',
  `updated_by` INT NULL DEFAULT NULL COMMENT 'User id who last saved this rule.',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule_key` (`rule_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='WhatsApp automation per-rule settings. Catalog is in code; see create_wa_automations_jun2026.sql.';

-- 2. Send log — powers both "send once per entity" dedup AND the activity tracker.
CREATE TABLE IF NOT EXISTS `t_wa_automation_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_key` VARCHAR(64) NOT NULL COMMENT 'Which rule produced this attempt.',
  `trigger_event` VARCHAR(64) NOT NULL COMMENT 'The event that fired (order.created, order.accepted, invoice.dispatch, ...).',
  `dedup_key` VARCHAR(191) NOT NULL COMMENT 'Stable per-entity key (e.g. order:SH-123). With rule_key this guarantees one send per entity per rule across the Shopify-staging/prod id split.',
  `order_id` BIGINT NULL DEFAULT NULL COMMENT 'Best-effort order id for display (may be staging OR prod — never resolve cross-table by this alone).',
  `conversation_id` BIGINT NULL DEFAULT NULL,
  `template_name` VARCHAR(255) NULL DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL COMMENT 'sent | failed | skipped.',
  `skip_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Why a skip happened (e.g. "location already saved", "already sent", "no phone").',
  `wa_message_id` VARCHAR(255) NULL DEFAULT NULL,
  `error_message` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rule_dedup` (`rule_key`, `dedup_key`),
  KEY `idx_created` (`created_at`),
  KEY `idx_status` (`status`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='WhatsApp automation send log: send-once dedup + activity tracker.';

-- 3. Config: master switch (OFF) + optional test-phone redirect.
INSERT IGNORE INTO `t_fin_config`
    (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES
    ('wa_automations_enabled', '0',
     'Master switch for event-triggered WhatsApp automations (order/invoice). 0 = nothing fires regardless of per-rule toggles. Default 0.',
     NOW(), NOW()),
    ('wa_automations_test_phone', '',
     'When set to a phone number, ALL automation sends are redirected to this number instead of the customer (safe live testing). Blank = send to real customers.',
     NOW(), NOW());

-- Management is gated by the EXISTING manage_wa_auto_reply permission (same
-- managers who handle the auto-reply modal). No new permission is added.

-- Verify:
--   SHOW TABLES LIKE 't_wa_automation%';
--   SELECT config_key, config_value FROM t_fin_config WHERE config_key LIKE 'wa_automations%';
