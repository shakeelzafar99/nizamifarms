-- =============================================================================
-- SEED: the monsoon meat-storage guidelines template (Jul 2026)
--
-- WHY: registering the template here gives the automation rule picker a clean
-- name + an in-app "view message" preview. Sending does NOT require this row —
-- the app passes the NAME straight to Meta, and Meta holds the real approved
-- copy. The body_text below is for the IN-APP PREVIEW ONLY and mirrors what was
-- submitted to Meta on 2026-07-30.
--
-- ⚠ THE NAME MUST MATCH META EXACTLY. Confirmed with the owner on 2026-07-30:
-- the template is live in WhatsApp Manager as `monsoon`, so that is the `name`
-- below (the friendlier "Monsoon — meat storage guidelines" is display_name,
-- which is what the picker shows). A mismatch fails at send time with a Meta
-- error, logged as `failed` in the automation Activity log.
--
-- show_in = 'automation' → appears in the automation rule picker, NOT in the
-- manual Send message / Send invoice lists (same as order_received_* and
-- deliver_*).
--
-- Variables (fixed in code, do not change the count):
--   {{1}} = customer first name      {{2}} = order number (e.g. NF-12312)
-- The template writes the "#" itself ("order #{{2}}"), matching the existing
-- order_received_* templates.
--
-- status = 'approved' so the picker shows it immediately (the app never gates
-- sending on this field — Meta does). If Meta REJECTS it, edit it in Messages ->
-- Manage Templates (or flip status here) and resubmit.
--
-- SAFE TO RE-RUN: INSERT IGNORE on the UNIQUE (name, language) index — will NOT
-- overwrite a template you've already added/edited in the app.
--
-- AFTER RUNNING: clear cache if your template list is cached
--   DEV: php artisan cache:clear    PROD: /api/public/xclean
-- Run on LOCAL + PROD. No money/ledger impact. No behaviour change on its own —
-- the rule that sends it ships OFF (see AutomationRegistry).
-- =============================================================================

INSERT IGNORE INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`, `status`, `header_text`, `body_text`,
     `footer_text`, `variable_count`, `has_buttons`, `button_labels`, `show_in`,
     `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`, `created_at`, `updated_at`)
VALUES

('monsoon', 'Monsoon — meat storage guidelines', 'utility', 'en', 'approved', NULL,
 'Dear {{1}},\n\n🌧 Monsoon Meat Storage Guidelines for your order #{{2}}\n\nImportant: Please store your meat in the freezer immediately after receiving it to maintain freshness and quality.\n\nTips for Best Results:\n• Allow air circulation: Place meat packs side by side instead of stacking them. This allows proper airflow and helps prevent moisture buildup, especially for chicken.\n• Store minced meat properly: Keep smaller packs, such as minced meat, side by side to maintain good air circulation.\n• Keep meat dry: If you wash the meat before storing it, ensure it is completely dry before placing it in the freezer.\n\nFood Safety Reminder: Always handle and store meat hygienically to prevent contamination.\n\nThank you for choosing Nizami Farms!',
 NULL, 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW());

-- -----------------------------------------------------------------------------
-- 2. Pre-select `monsoon` on the automation card, still SWITCHED OFF.
--
-- The engine's safety rule is "no row in t_wa_automations == disabled", and no
-- rule has ever been seeded. This row does NOT break that: `enabled = 0`, so
-- nothing can send. It only saves the operator from hunting for the template in
-- the dropdown — open Messages -> 🤖 -> Order actions and the template + the
-- 30-day cooldown are already filled in; tick **Enabled** and Save to go live.
--
-- INSERT IGNORE on the UNIQUE rule_key: if you have ALREADY configured this rule
-- (or turned it on), re-running this file will NOT touch your settings and will
-- NOT switch anything off.
-- -----------------------------------------------------------------------------

INSERT IGNORE INTO `t_wa_automations`
    (`rule_key`, `enabled`, `template_name`, `config_json`, `updated_by`, `created_at`, `updated_at`)
VALUES
    ('order_delivered_storage_tips', 0, 'monsoon', '{"cooldown_days":30}', NULL, NOW(), NOW());

-- Verify:
--   SELECT name, display_name, status, variable_count, show_in
--     FROM t_wa_templates WHERE name = 'monsoon';
--   SELECT rule_key, enabled, template_name, config_json
--     FROM t_wa_automations WHERE rule_key = 'order_delivered_storage_tips';
--   -- expect enabled = 0. Turning it ON is a deliberate click in the UI.
-- =============================================================================
