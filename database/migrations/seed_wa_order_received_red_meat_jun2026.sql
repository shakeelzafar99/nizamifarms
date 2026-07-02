-- =============================================================================
-- SEED: red-meat-only "resume Thursday" order-received message (Jun 2026)
--
-- WHY: the classifier already tells chicken_only / mixed / red_meat_only apart,
-- but the seeded schedule only had "Chicken only" + "Any items" rows — so a
-- RED-MEAT-ONLY order fell through to the mixed template (which offers a
-- "Split: Chicken Wed / Meat Thu" button that makes no sense with no chicken).
-- This adds a dedicated Thursday message + routes red-meat-only to it for the
-- whole closed stretch: Mon 20:00 -> all Tue -> Wed until 20:00.
--
-- Result after running (first match wins, off-window):
--   chicken only  -> order_received_mon_chicken   (deliver Wednesday)
--   red meat only -> order_received_red_meat       (deliver Thursday)   <-- NEW
--   mixed / other -> order_received_mon_mixed       (choose: all Thu / split / cancel)
--
-- PREREQUISITE: create the `order_received_red_meat` template in Meta first
-- (see WHATSAPP-AUTOMATION-META-TEMPLATES.md). Buttons: Confirm Thursday / Cancel.
--
-- SAFE: nothing auto-sends beyond what the (already-enabled) order-received rules
-- do. AFTER RUNNING clear cache (DEV: php artisan cache:clear / PROD: /xclean) and
-- refresh. Run on LOCAL + PROD. No money/ledger impact.
-- =============================================================================


-- (A) ── Register the template in the app (hidden from manual pickers) ──────────
INSERT IGNORE INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`, `status`, `header_text`, `body_text`,
     `footer_text`, `variable_count`, `has_buttons`, `button_labels`, `show_in`,
     `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`, `created_at`, `updated_at`)
VALUES
('order_received_red_meat', 'Order received — red meat only (Thursday)', 'utility', 'en', 'approved', NULL,
 'Hi {{1}}, we have received your order #{{2}}.\n\nPlease note our team is off on Tuesdays, and our mutton/beef operations resume on Thursday. Your order will be delivered on Thursday.\n\nPlease reply to confirm.',
 NULL, 2, 1, '["Confirm Thursday","Cancel order"]', 'automation', 0, 1, 0, 0, NOW(), NOW());


-- (B) ── Route red-meat-only to it in the schedule (BOTH lanes) ─────────────────
-- Replaces the schedule for order_received_new + order_received_existing with your
-- current 8 rows PLUS three "red_meat_only" rows, each placed just BEFORE its
-- "Any items" row so red-meat-only is caught first and mixed still gets the choose
-- message.
--
-- ⚠️ This OVERWRITES config_json for both lanes. It matches the seeded schedule
-- exactly, so it's safe if you have NOT hand-edited the schedule in the UI since
-- seeding. If you HAVE edited it, skip (B) and instead add these rows in
-- Messages -> 🤖 -> Order actions -> Edit schedule (composition "Red meat only" ->
-- order_received_red_meat, one above each "Any items" row):
--   * Mon 20:00-23:59   * all Tuesday   * Wed 00:00-20:00
UPDATE `t_wa_automations`
SET `config_json` = '{"rows":[{"days":[3],"start":"20:00","end":"23:59","composition":"any","template":"order_received_wed"},{"days":[1],"start":"20:00","end":"23:59","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[1],"start":"20:00","end":"23:59","composition":"red_meat_only","template":"order_received_red_meat"},{"days":[1],"start":"20:00","end":"23:59","composition":"any","template":"order_received_mon_mixed"},{"days":[2],"start":null,"end":null,"composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[2],"start":null,"end":null,"composition":"red_meat_only","template":"order_received_red_meat"},{"days":[2],"start":null,"end":null,"composition":"any","template":"order_received_mon_mixed"},{"days":[3],"start":"00:00","end":"20:00","composition":"chicken_only","template":"order_received_mon_chicken"},{"days":[3],"start":"00:00","end":"20:00","composition":"red_meat_only","template":"order_received_red_meat"},{"days":[3],"start":"00:00","end":"20:00","composition":"any","template":"order_received_mon_mixed"},{"days":[4,5,6,0,1],"start":"10:00","end":"20:00","composition":"any","template":"order_received_workinghours"}],"default_template":"order_received_offhours_existing"}',
    `updated_at` = NOW()
WHERE `rule_key` IN ('order_received_new', 'order_received_existing');

-- Verify:
--   SELECT name, show_in, variable_count, has_buttons, button_labels
--     FROM t_wa_templates WHERE name = 'order_received_red_meat';
--   SELECT rule_key, JSON_PRETTY(config_json) FROM t_wa_automations
--     WHERE rule_key IN ('order_received_new','order_received_existing');
-- =============================================================================
