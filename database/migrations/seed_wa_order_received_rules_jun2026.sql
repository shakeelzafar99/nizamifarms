-- =============================================================================
-- SEED: pre-built "order received" schedules (Jun 2026, rev 2)
--
-- Ready-made starting point for the two order-received automations so you don't
-- build the schedule from scratch. Creates:
--   * order_received_new       (first-ever customers)
--   * order_received_existing  (returning customers)
--
-- Schedule logic (Tuesday = OFF day; Wednesday = chicken only; mutton/beef
-- resume Thursday). Rows are evaluated top-to-bottom, FIRST MATCH WINS:
--   1. Wed 20:00-23:59, any           -> order_received_wed        (Wed evening -> Thursday)
--   2. Mon 20:00-23:59, chicken only  -> order_received_mon_chicken
--   3. Mon 20:00-23:59, anything else -> order_received_mon_mixed
--   4. Tue ALL DAY,     chicken only  -> order_received_mon_chicken
--   5. Tue ALL DAY,     anything else -> order_received_mon_mixed
--   6. Wed 00:00-20:00, chicken only  -> order_received_mon_chicken
--   7. Wed 00:00-20:00, anything else -> order_received_mon_mixed
--   8. Thu-Mon 10:00-20:00, any       -> order_received_workinghours (normal working hours)
--   default (other nights / early mornings) -> order_received_offhours_(new|existing)
--
-- i.e. the chicken/mixed delivery-scheduling messages cover the whole closed
-- stretch (Mon after close -> Tue off-day -> Wed until 8pm); Wed after 8pm gets
-- the Thursday message; chicken-only -> "deliver Wednesday", anything with red
-- meat (or unclassified) -> the "choose Thursday option" message.
--
-- NEW = EXISTING: both lanes use the SAME templates (the new-customer lane's
-- off-hours default is order_received_offhours_EXISTING, not a separate welcome)
-- because Meta classifies a "welcome" as marketing, not utility. So you do NOT
-- need an order_received_offhours_new template. To message BOTH new and
-- returning customers, ENABLE BOTH lanes in Order actions — each order fires
-- exactly one lane, so every customer gets the one identical message.
--
-- FULLY CUSTOMISABLE AFTER SEEDING: open Messages -> 🤖 -> Order actions and
-- edit any row (days/times/composition/template), drag in your own templates,
-- "+ Add rule", remove a row (✕), or delete the whole rule. This seed is only
-- the starting point.
--
-- Both rules seed DISABLED (enabled = 0); nothing sends until you turn them on
-- AND flip the master "Automations" switch. Template names match the suggested
-- names in WHATSAPP-AUTOMATION-META-TEMPLATES.md; they show "(not in list)"
-- until you create them in Meta + add them to the app, then resolve on their own.
--
-- RE-RUNNABLE: uses INSERT IGNORE on the UNIQUE rule_key, so it will NOT
-- overwrite a rule you've already edited. ⚠️ If you ran the EARLIER version of
-- this seed (the Monday-only one) and want this corrected schedule instead,
-- uncomment the DELETE below FIRST (it only removes these two seeded rules):
--
--   DELETE FROM `t_wa_automations` WHERE `rule_key` IN ('order_received_new','order_received_existing');
--
-- Run in MySQL Workbench on LOCAL and PROD (after create_wa_automations_jun2026.sql).
-- No money/ledger impact.
-- =============================================================================

INSERT IGNORE INTO `t_wa_automations`
    (`rule_key`, `enabled`, `template_name`, `config_json`, `updated_by`, `created_at`, `updated_at`)
VALUES
    ('order_received_new', 0, NULL,
     '{"rows":[{"days":[3],"start":"20:00","end":"23:59","composition":"any","template":"order_received_wed"},{"days":[1],"start":"20:00","end":"23:59","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[1],"start":"20:00","end":"23:59","composition":"any","template":"order_received_mon_mixed"},{"days":[2],"start":null,"end":null,"composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[2],"start":null,"end":null,"composition":"any","template":"order_received_mon_mixed"},{"days":[3],"start":"00:00","end":"20:00","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[3],"start":"00:00","end":"20:00","composition":"any","template":"order_received_mon_mixed"},{"days":[4,5,6,0,1],"start":"10:00","end":"20:00","composition":"any","template":"order_received_workinghours"}],"default_template":"order_received_offhours_existing"}',
     NULL, NOW(), NOW()),

    ('order_received_existing', 0, NULL,
     '{"rows":[{"days":[3],"start":"20:00","end":"23:59","composition":"any","template":"order_received_wed"},{"days":[1],"start":"20:00","end":"23:59","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[1],"start":"20:00","end":"23:59","composition":"any","template":"order_received_mon_mixed"},{"days":[2],"start":null,"end":null,"composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[2],"start":null,"end":null,"composition":"any","template":"order_received_mon_mixed"},{"days":[3],"start":"00:00","end":"20:00","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[3],"start":"00:00","end":"20:00","composition":"any","template":"order_received_mon_mixed"},{"days":[4,5,6,0,1],"start":"10:00","end":"20:00","composition":"any","template":"order_received_workinghours"}],"default_template":"order_received_offhours_existing"}',
     NULL, NOW(), NOW());

-- Verify:
--   SELECT rule_key, enabled, JSON_PRETTY(config_json) FROM t_wa_automations
--   WHERE rule_key IN ('order_received_new','order_received_existing');
-- =============================================================================
