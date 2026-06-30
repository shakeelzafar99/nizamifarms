-- =============================================================================
-- FINAL SEED: WhatsApp automations — order-received rules + invoice templates +
--             payment-change template (Jun 2026)
--
-- Run THIS ONE file (instead of the two earlier seeds) once you've created the
-- templates in Meta and added them in the app (Messages -> Manage Templates).
-- It does three things:
--   (A) Forces the FOUR invoice template-name config keys to the deployed names
--       (incl. the corrected online-ETA name `invoice_online_eta_2`).
--   (B) Seeds the two order-received rules + their schedules (DISABLED).
--   (C) Seeds the payment-change rule with `invoice_paychange` selected (DISABLED).
--
-- PREREQUISITE: create_wa_automations_jun2026.sql must already be applied
-- (creates t_wa_automations + the wa_automations_* config keys).
--
-- SAFE: nothing auto-sends. The master "Automations" switch stays '0', every
-- rule seeds DISABLED, and the Send Invoice button stays MANUAL. No money/ledger
-- impact. Run on LOCAL first, then PROD (MySQL Workbench).
--
-- ⚠️ AFTER RUNNING, CLEAR THE CONFIG CACHE — REQUIRED. The invoice template
-- names in section (A) are read through ConfigModel::get(), which caches each
-- key for 1 hour. Raw SQL writes the row but does NOT bust that cache, so the
-- Invoice tab can keep showing the OLD template until the cache is cleared
-- (this is exactly the "online still shows the old template" symptom). Clear it:
--   * DEV  : php artisan cache:clear
--   * PROD : open /api/public/xclean (the deploy cache-clear endpoint)
-- Then reopen Messages -> 🤖 -> Invoice & dispatch to confirm the names.
--
-- IDEMPOTENT, with ONE deliberate exception:
--   * Section A uses ON DUPLICATE KEY UPDATE so re-running RESETS the four
--     invoice template names to exactly the values below. This is intended for
--     initial setup / the online_eta_2 rename. If you later change a template in
--     the app's Invoice tab, DON'T re-run section A or it will overwrite it.
--   * Sections B & C use INSERT IGNORE, so they NEVER overwrite a rule you've
--     already edited (schedules, on/off, template picks are preserved).
-- =============================================================================


-- (A) ── Invoice template-name pointers (read by the Send Invoice button) ──────
-- online vs cash is picked by the order's payment method; the WITH-ETA (3 vars:
-- name, order#, ETA) vs NO-ETA (2 vars: name, order#) version by whether the
-- delivery ETA exists yet. NOTE the online-ETA name is `invoice_online_eta_2`
-- (the original `invoice_online_eta` was recreated to include the image header).
--
-- If you did NOT create separate CASH templates, change the two cash values
-- below to 'invoice_online_eta_2' / 'invoice_online' so cash reuses the online
-- (bank-details) message.
INSERT INTO `t_fin_config` (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES
    ('invoice_template_online_eta',   'invoice_online_eta_2',
     'Send Invoice template: online/bank order, ETA known (image + 3 vars: name, order#, ETA).', NOW(), NOW()),
    ('invoice_template_online_noeta', 'invoice_online',
     'Send Invoice template: online/bank order, no ETA yet (image + 2 vars: name, order#).', NOW(), NOW()),
    ('invoice_template_cash_eta',     'invoice_cash_eta',
     'Send Invoice template: cash order, ETA known (image + 3 vars: name, order#, ETA).', NOW(), NOW()),
    ('invoice_template_cash_noeta',   'invoice_cash',
     'Send Invoice template: cash order, no ETA yet (image + 2 vars: name, order#).', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    `config_value` = VALUES(`config_value`),
    `description`  = VALUES(`description`),
    `updated_at`   = NOW();


-- (B) ── Order-received rules + schedules (Shopify-received orders only) ────────
-- Tuesday = OFF day; Wednesday = chicken only; mutton/beef resume Thursday.
-- Rows are evaluated top-to-bottom, FIRST MATCH WINS:
--   1. Wed 20:00-23:59, any           -> order_received_wed
--   2. Mon 20:00-23:59, chicken only  -> order_received_mon_chicken
--   3. Mon 20:00-23:59, anything else -> order_received_mon_mixed
--   4. Tue ALL DAY,     chicken only  -> order_received_mon_chicken
--   5. Tue ALL DAY,     anything else -> order_received_mon_mixed
--   6. Wed 00:00-20:00, chicken only  -> order_received_mon_chicken
--   7. Wed 00:00-20:00, anything else -> order_received_mon_mixed
--   8. Thu-Mon 10:00-20:00, any       -> order_received_workinghours
--   default                            -> order_received_offhours_existing
--
-- NEW = EXISTING: both lanes use the SAME templates (Meta treats a "welcome" as
-- marketing, not utility), so there is no order_received_offhours_new. Enable
-- BOTH lanes in Order actions — each order fires exactly one lane.
INSERT IGNORE INTO `t_wa_automations`
    (`rule_key`, `enabled`, `template_name`, `config_json`, `updated_by`, `created_at`, `updated_at`)
VALUES
    ('order_received_new', 0, NULL,
     '{"rows":[{"days":[3],"start":"20:00","end":"23:59","composition":"any","template":"order_received_wed"},{"days":[1],"start":"20:00","end":"23:59","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[1],"start":"20:00","end":"23:59","composition":"any","template":"order_received_mon_mixed"},{"days":[2],"start":null,"end":null,"composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[2],"start":null,"end":null,"composition":"any","template":"order_received_mon_mixed"},{"days":[3],"start":"00:00","end":"20:00","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[3],"start":"00:00","end":"20:00","composition":"any","template":"order_received_mon_mixed"},{"days":[4,5,6,0,1],"start":"10:00","end":"20:00","composition":"any","template":"order_received_workinghours"}],"default_template":"order_received_offhours_existing"}',
     NULL, NOW(), NOW()),

    ('order_received_existing', 0, NULL,
     '{"rows":[{"days":[3],"start":"20:00","end":"23:59","composition":"any","template":"order_received_wed"},{"days":[1],"start":"20:00","end":"23:59","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[1],"start":"20:00","end":"23:59","composition":"any","template":"order_received_mon_mixed"},{"days":[2],"start":null,"end":null,"composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[2],"start":null,"end":null,"composition":"any","template":"order_received_mon_mixed"},{"days":[3],"start":"00:00","end":"20:00","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[3],"start":"00:00","end":"20:00","composition":"any","template":"order_received_mon_mixed"},{"days":[4,5,6,0,1],"start":"10:00","end":"20:00","composition":"any","template":"order_received_workinghours"}],"default_template":"order_received_offhours_existing"}',
     NULL, NOW(), NOW());


-- (C) ── Payment-change rule: bank details when payment switches to online ──────
-- Fires when an OUT-FOR-DELIVERY order's payment method is changed TO online
-- (rider before delivery, or manager on web). TEXT template (no image), 2 vars
-- (name, order#). Seeded DISABLED with `invoice_paychange` pre-selected.
INSERT IGNORE INTO `t_wa_automations`
    (`rule_key`, `enabled`, `template_name`, `config_json`, `updated_by`, `created_at`, `updated_at`)
VALUES
    ('invoice_on_payment_change', 0, 'invoice_paychange', NULL, NULL, NOW(), NOW());


-- =============================================================================
-- VERIFY
--   SELECT config_key, config_value FROM t_fin_config WHERE config_key LIKE 'invoice_template_%';
--   SELECT rule_key, enabled, template_name FROM t_wa_automations
--     WHERE rule_key IN ('order_received_new','order_received_existing','invoice_on_payment_change');
--   SELECT rule_key, JSON_PRETTY(config_json) FROM t_wa_automations
--     WHERE rule_key IN ('order_received_new','order_received_existing');
--
-- THEN, to go live: Messages -> 🤖 ->
--   * Order actions: confirm the template on each schedule row, enable BOTH lanes.
--   * Invoice: confirm the online/cash templates; (Send Invoice is already manual).
--   * Bank-details-on-payment-change: turn it on if you want it.
--   * Flip the master "Automations" switch ON. Use 🧪 Test to my number first.
--
-- NOTE: the "Order accepted -> send location" rule is NOT seeded here — it reuses
-- your EXISTING location auto-send settings (config keys location_auto_send_enabled
-- + open_order_location_default_template), toggled from the same Order actions tab.
-- =============================================================================
