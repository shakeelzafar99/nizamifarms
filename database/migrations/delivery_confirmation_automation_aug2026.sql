-- =============================================================================
-- Delivered → payment confirmation automation (Aug 2026)
--
-- Automates the delivery-confirmation WhatsApp that has been sent BY HAND from
-- Daily Closing. Adds:
--   1. t_wa_messages.unread_exempt  — lets an inbound the system already
--      answered stay in the chat history without raising an unread badge.
--   2. The two new delivery-confirmation templates (online with a
--      "Get bank details" button, and cash).
--   3. The automation rule row, pre-filled and DISABLED.
--   4. The bank-details text used by the button reply (single source of truth).
--
-- Run in MySQL Workbench against LOCAL then PROD.
--
-- ORDER: run this AFTER uploading the web files and clearing caches. Nothing
-- here changes behaviour on its own — the rule ships OFF and every code path is
-- schema-guarded, so the app works identically before and after.
--
-- IDEMPOTENCE: steps 2-4 are INSERT IGNORE / re-runnable. Step 1 is a plain
-- ALTER (MySQL has no ADD COLUMN IF NOT EXISTS) — if it has already run it
-- errors with "Duplicate column name", which is safe to ignore. The check
-- query directly above it tells you whether to run it at all.
-- =============================================================================

-- ── 1. Unread exemption flag ────────────────────────────────────────────────
-- Check FIRST (expect 0 rows = not yet added):
--   SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
--   WHERE TABLE_NAME = 't_wa_messages' AND COLUMN_NAME = 'unread_exempt'
--
-- Why a column and not the conversation counter: the real per-user unread badge
-- is computed from t_wa_messages against t_wa_conversation_reads.last_read_at,
-- so t_wa_conversations.unread_count alone changes nothing. And marking our
-- automatic answer as staff-sent would suppress every OLDER unanswered message
-- in the same chat. See app/Services/WhatsApp/UnreadQuery.php.

ALTER TABLE t_wa_messages
ADD COLUMN unread_exempt TINYINT(1) NOT NULL DEFAULT 0
COMMENT 'Aug-2026: 1 = inbound the system already answered (e.g. a Get bank details tap). Visible in chat history, excluded from every unread badge.';

-- ── 2. The two new templates ────────────────────────────────────────────────
-- The APPROVED body lives on Meta; these rows exist so the templates appear in
-- the automation pickers and render a preview in-app. `has_buttons`/
-- `button_labels` are metadata only — a static quick-reply needs no component
-- at send time, which is why no code change was needed to send one.
--
-- NOTE: the retired `delivery_confirmation_online` was never registered here at
-- all (it lived only on Meta), which is why it never appeared in any picker.

INSERT IGNORE INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`, `status`, `header_text`,
     `body_text`, `footer_text`, `variable_count`, `has_buttons`, `button_labels`,
     `show_in`, `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`,
     `created_at`, `updated_at`)
VALUES
('delivery_confirmation_online_v2', 'Delivered — online (bank details button)', 'utility', 'en', 'approved', NULL,
 'Dear {{1}},\n\nWe are happy to confirm that your order {{2}} has been successfully delivered on {{3}} by our rider {{4}}.\n\nYour payment method is Online Bank Transfer. Tap the button below to get our bank details, and please share a screenshot of the transfer here once the transaction has been made.\n\nPlease IGNORE this message if you have already transferred and shared the payment slip.\n\nThank you for choosing Nizami Farms!',
 NULL, 4, 1, '["Get bank details"]', 'automation,messages', 0, 1, 0, 0, NOW(), NOW()),

('delivery_confirmation_cash', 'Delivered — cash', 'utility', 'en', 'approved', NULL,
 'Dear {{1}},\n\nWe are happy to confirm that your order {{2}} has been successfully delivered on {{3}} by our rider {{4}}.\n\nYour payment method for this order is Cash — no bank transfer is needed.\n\nThank you for choosing Nizami Farms!',
 NULL, 4, 0, NULL, 'automation,messages', 0, 1, 0, 0, NOW(), NOW());

-- ── 3. The automation rule row — PRE-FILLED BUT DISABLED ────────────────────
-- enabled = 0 is deliberate: nothing fires until the owner turns the card on in
-- Messages → 🤖 automations. The row exists only so the card opens with both
-- templates already selected.
--
-- A rule with NO row is treated as disabled too, so this is purely convenience.

INSERT IGNORE INTO `t_wa_automations`
    (`rule_key`, `enabled`, `template_name`, `config_json`, `updated_by`, `created_at`, `updated_at`)
VALUES
('order_delivered_payment_confirmation', 0, NULL,
 '{"online_template":"delivery_confirmation_online_v2","cash_template":"delivery_confirmation_cash"}',
 NULL, NOW(), NOW());

-- ── 4. Bank details used by the "Get bank details" reply ────────────────────
-- Owner ruling Aug-31-2026: use the SAME accounts as the invoice_paychange
-- template (Alfalah / Meezan / Askari). NO HBL — the retired delivery template
-- listed it, and the three hardcoded copies in the codebase had drifted apart.
-- Editing this value changes the reply WITHOUT a code deploy. Blank/absent
-- falls back to the identical text in BankDetailsProvider::DEFAULT_ACCOUNTS.

INSERT IGNORE INTO `t_fin_config`
    (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES
('wa_bank_details_text',
 'Account Title: "Nizami Meat"\n* Bank: Bank Alfalah\n* IBAN: PK87ALFH5866005002904343\n\nAccount Title: "Nizami Farms"\n* Bank: Meezan Bank Limited\n* IBAN: PK75MEZN0003050106554237\n\n* Bank: Askari Bank Limited\n* IBAN: PK10ASCM0000080200000971',
 'Bank accounts sent when a customer taps "Get bank details" on a delivery confirmation, and used by the Daily Closing wa.me fallback. Single source of truth — see App\\Services\\WhatsApp\\BankDetailsProvider.',
 NOW(), NOW());

-- ── Verify ──────────────────────────────────────────────────────────────────
--   SELECT COLUMN_NAME, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
--   WHERE TABLE_NAME = 't_wa_messages' AND COLUMN_NAME = 'unread_exempt'
--
--   SELECT name, variable_count, has_buttons, button_labels, show_in
--   FROM t_wa_templates WHERE name LIKE 'delivery_confirmation%'
--
--   SELECT rule_key, enabled, config_json FROM t_wa_automations
--   WHERE rule_key = 'order_delivered_payment_confirmation'
--
--   SELECT config_key, config_value FROM t_fin_config
--   WHERE config_key = 'wa_bank_details_text'
-- =============================================================================
