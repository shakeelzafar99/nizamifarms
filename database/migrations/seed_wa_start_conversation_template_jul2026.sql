-- =============================================================================
-- SEED: "Start Conversation" WhatsApp template (Jul 2026)  —  2 variables
--
-- PURPOSE: lets staff OPEN a fresh WhatsApp session with a customer whose 24-hour
-- free-messaging window is closed (or who never messaged us). WhatsApp only lets
-- the FIRST message be an approved TEMPLATE; once the customer replies, the 24h
-- free-text window opens and you can chat normally.
--
-- VARIABLES:
--   {{1}} = customer name   (auto-filled from the conversation)
--   {{2}} = order number    (app auto-fills the customer's LATEST order — any
--                            status, incl. delivered/cancelled — or a dummy for
--                            a brand-new customer with no order on file, so Meta
--                            does not reject the send for an empty variable)
--
-- =============================================================================
-- ⚠️  This template must already be CREATED + APPROVED in Meta (done) with the
--     EXACT name + language + variable count below. The app only passes the NAME
--     and the {{1}}/{{2}} values to Meta; if Meta has no approved template with
--     this exact name/language, the send FAILS.
--       Name:     start_conversation      (lowercase; MUST match `name`)
--       Language: English (en)
--       Body (Meta, keep {{1}} and {{2}}):
--         Hi {{1}},
--         I'm reaching out to you regarding your order {{2}} with Nizami Farms.
--         Please reply to this message, and our team will assist you
-- =============================================================================
--
-- SAFE TO RE-RUN: keyed on the UNIQUE (name, language) index; ON DUPLICATE KEY
-- UPDATE refreshes the copy in place, never creating a duplicate. No money/ledger
-- impact. Run on LOCAL (dev) and PROD, then clear cache so the picker refreshes:
--   DEV:  php artisan cache:clear      PROD:  /xclean
-- =============================================================================

INSERT INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`, `status`,
     `header_text`, `body_text`, `footer_text`,
     `variable_count`, `has_buttons`, `button_labels`, `show_in`,
     `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`,
     `created_at`, `updated_at`)
VALUES
    ('start_conversation',
     'Start Conversation',
     'utility',
     'en',
     'approved',
     NULL,
     'Hi {{1}},\n\nI''m reaching out to you regarding your order {{2}} with Nizami Farms. Please reply to this message, and our team will assist you',
     NULL,
     2,                                    -- variable_count: {{1}}=name, {{2}}=order number
     0, NULL,                              -- no buttons
     'messages,customers,orders,online_approvals',   -- where it shows in manual pickers
     0, 1, 0, 0,                           -- is_default=0, is_active=1, is_qurbani_only=0, is_regular_only=0 (Common)
     NOW(), NOW())
ON DUPLICATE KEY UPDATE
     `display_name`   = VALUES(`display_name`),
     `category`       = VALUES(`category`),
     `status`         = VALUES(`status`),
     `body_text`      = VALUES(`body_text`),
     `variable_count` = VALUES(`variable_count`),
     `show_in`        = VALUES(`show_in`),
     `is_active`      = VALUES(`is_active`);

-- Verify:
-- SELECT id, name, display_name, variable_count, show_in, status, is_active
--   FROM t_wa_templates WHERE name = 'start_conversation';
-- =============================================================================
