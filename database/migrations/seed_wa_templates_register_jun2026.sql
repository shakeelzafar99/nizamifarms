-- =============================================================================
-- SEED: register the automation + invoice templates in the app (Jun 2026)
--
-- WHY: sending a template does NOT require it to be in this table — WhatsAppService
-- passes the NAME straight to Meta. But registering it here gives three things:
--   1. the rule pickers show a clean name instead of "(name) (not in list)",
--   2. the Send Invoice picker can offer the online/cash invoice templates,
--   3. each rule can show a "view message" preview (reads body_text below).
--
-- NO LIST BLOAT: the order-received + payment-change templates are registered
-- with show_in = 'automation' — a context NO manual send picker queries (those
-- use invoice / orders / messages / customers / shopify). So they appear in the
-- automation rule pickers (which load all templates) but NOT in the manual
-- "Send message" / "Send invoice" lists. The four invoice templates use
-- show_in = 'invoice' so they DO appear in the Send Invoice picker (only).
--
-- The body_text is copied from WHATSAPP-AUTOMATION-META-TEMPLATES.md (used for
-- the in-app preview only — Meta holds the real approved copy). Tweak any of it
-- later in Messages -> Manage Templates.
--
-- SAFE TO RE-RUN: INSERT IGNORE on the UNIQUE (name, language) index — it will
-- NOT overwrite a template you've already added/edited in the app. To force a
-- refresh of one, delete it first in Manage Templates, then re-run.
--
-- AFTER RUNNING: clear caches if your template list is cached
--   DEV: php artisan cache:clear   PROD: /api/public/xclean
-- Run on LOCAL + PROD. No money/ledger impact.
-- =============================================================================

INSERT IGNORE INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`, `status`, `header_text`, `body_text`,
     `footer_text`, `variable_count`, `has_buttons`, `button_labels`, `show_in`,
     `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`, `created_at`, `updated_at`)
VALUES
-- ── Order-received + payment-change (automation-only; hidden from manual pickers) ──
('order_received_offhours_existing', 'Order received — off hours', 'utility', 'en', 'approved', NULL,
 'Hi {{1}}, we have received your order #{{2}}.\n\nOur operations are currently off. Our working hours are Wednesday to Monday, 10:00 AM to 8:00 PM (Tuesday is our off day).\n\nYour order is saved in our system. Our operations team will review it and share your delivery schedule right here once business hours resume.',
 'Nizami Farms', 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW()),

('order_received_workinghours', 'Order received — working hours', 'utility', 'en', 'approved', NULL,
 'Hi {{1}}, we have received your order #{{2}}.\n\nOur operations team will reach out to you shortly to coordinate your delivery schedule. Thank you!',
 'Nizami Farms', 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW()),

('order_received_mon_chicken', 'Order received — chicken (off window)', 'utility', 'en', 'approved', NULL,
 'Hi {{1}}, we have received your order #{{2}}.\n\nPlease note our team is off on Tuesdays. Since your order is chicken only, your earliest delivery is Wednesday.\n\nPlease confirm if this works for you.',
 NULL, 2, 1, '["Confirm Wednesday","Cancel order"]', 'automation', 0, 1, 0, 0, NOW(), NOW()),

('order_received_mon_mixed', 'Order received — mixed/red meat (off window)', 'utility', 'en', 'approved', NULL,
 'Hi {{1}}, we have received your order #{{2}}.\n\nPlease note our team is off on Tuesdays, and our mutton/beef delivery resumes on Thursday. As your order includes red meat, please choose your preferred delivery option below.\n\n(If you choose split delivery, the delivery charge still applies only once.)',
 NULL, 2, 1, '["Deliver all Thursday","Split: Chicken Wed / Meat Thu","Cancel order"]', 'automation', 0, 1, 0, 0, NOW(), NOW()),

('order_received_wed', 'Order received — Wed evening', 'utility', 'en', 'approved', NULL,
 'Hi {{1}}, we have received your order #{{2}}.\n\nOur operations team is currently off. Your order will be scheduled for delivery on Thursday, when our regular operations resume.\n\nPlease reply to confirm this works for you, and our team will process your order tomorrow.\n\nBest regards,\nNizami Meat Team',
 NULL, 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW()),

('invoice_paychange', 'Bank details — payment switched to online', 'utility', 'en', 'approved', NULL,
 'Dear {{1}}, your order #{{2}} is now out for delivery!\n\nYou''ve chosen to pay online. Please transfer the amount to any of the accounts below and share the transfer slip with us to ensure a smooth delivery process:\n\nAccount Title: "Nizami Meat"\n* Bank: Bank Alfalah\n* IBAN: PK87ALFH5866005002904343\n\nAccount Title: "Nizami Farms"\n* Bank: Meezan Bank Limited\n* IBAN: PK75MEZN0003050106554237\n\n* Bank: Askari Bank Limited\n* IBAN: PK10ASCM0000080200000971\n\nThank you for choosing Nizami Farms!',
 'Nizami Farms', 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW()),

-- ── Invoice templates (show_in='invoice' so they appear in the Send Invoice picker) ──
('invoice_online_eta_2', 'Invoice — online + ETA', 'utility', 'en', 'approved', NULL,
 'Dear {{1}}, your order #{{2}} is now out for delivery! Estimated delivery: {{3}}.\nOur rider will contact you upon arrival at your location.\n\nPayment options:\n* Cash on delivery\n* Online transfer\n\nAccount Title: "Nizami Meat"\n* Bank: Bank Alfalah\n* IBAN: PK87ALFH5866005002904343\n\nAccount Title: "Nizami Farms"\n* Bank: Meezan Bank Limited\n* IBAN: PK75MEZN0003050106554237\n\n* Bank: Askari Bank Limited\n* IBAN: PK10ASCM0000080200000971\n\n(Please share the transfer slip with us to ensure a smooth delivery process)\n\nThank you for choosing Nizami Farms!',
 NULL, 3, 0, NULL, 'invoice', 0, 1, 0, 0, NOW(), NOW()),

('invoice_online', 'Invoice — online', 'utility', 'en', 'approved', NULL,
 'Dear {{1}}, your order #{{2}} is now out for delivery!\nOur rider will contact you upon arrival at your location.\n\nPayment options:\n* Cash on delivery\n* Online transfer\n\nAccount Title: "Nizami Meat"\n* Bank: Bank Alfalah\n* IBAN: PK87ALFH5866005002904343\n\nAccount Title: "Nizami Farms"\n* Bank: Meezan Bank Limited\n* IBAN: PK75MEZN0003050106554237\n\n* Bank: Askari Bank Limited\n* IBAN: PK10ASCM0000080200000971\n\n(Please share the transfer slip with us to ensure a smooth delivery process)\n\nThank you for choosing Nizami Farms!',
 NULL, 2, 0, NULL, 'invoice', 0, 1, 0, 0, NOW(), NOW()),

('invoice_cash_eta', 'Invoice — cash + ETA', 'utility', 'en', 'approved', NULL,
 'Dear {{1}}, your order #{{2}} is now out for delivery! Estimated delivery: {{3}}.\nOur rider will contact you upon arrival. Please keep the cash amount ready for payment on delivery.\n\nThank you for choosing Nizami Farms!',
 NULL, 3, 0, NULL, 'invoice', 0, 1, 0, 0, NOW(), NOW()),

('invoice_cash', 'Invoice — cash', 'utility', 'en', 'approved', NULL,
 'Dear {{1}}, your order #{{2}} is now out for delivery!\nOur rider will contact you upon arrival. Please keep the cash amount ready for payment on delivery.\n\nThank you for choosing Nizami Farms!',
 NULL, 2, 0, NULL, 'invoice', 0, 1, 0, 0, NOW(), NOW());

-- Verify:
--   SELECT name, show_in, variable_count, has_buttons FROM t_wa_templates
--   WHERE name LIKE 'order_received_%' OR name LIKE 'invoice_%' ORDER BY show_in, name;
-- =============================================================================
