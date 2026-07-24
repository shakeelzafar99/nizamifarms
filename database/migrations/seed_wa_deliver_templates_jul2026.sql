-- =============================================================================
-- SEED: the 4 "delivery-promise" accept-button templates (Jul 2026)
--
-- WHY: these are the templates the NEW order-accept buttons send (deliver_today /
-- tomorrow / wednesday / thursday). Registering them here (like the existing
-- order_received_* templates) gives the automation rule picker a clean name +
-- an in-app "view message" preview. Sending does NOT require this row — the app
-- passes the NAME straight to Meta; Meta holds the real approved copy. The
-- body_text below is for the IN-APP PREVIEW ONLY and mirrors what was submitted
-- to Meta on 2026-07-23 (currently IN REVIEW).
--
-- show_in = 'automation' → appears in the automation rule pickers, NOT in the
-- manual Send message / Send invoice lists (same as order_received_*).
--
-- status = 'approved' so the picker shows them immediately (the app never gates
-- sending on this field — Meta does). If Meta REJECTS one, edit it in Messages ->
-- Manage Templates (or flip status here) and resubmit.
--
-- SAFE TO RE-RUN: INSERT IGNORE on the UNIQUE (name, language) index — will NOT
-- overwrite a template you've already added/edited in the app.
--
-- AFTER RUNNING: clear cache if your template list is cached
--   DEV: php artisan cache:clear    PROD: /api/public/xclean
-- Run on LOCAL + PROD. No money/ledger impact.
-- =============================================================================

INSERT IGNORE INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`, `status`, `header_text`, `body_text`,
     `footer_text`, `variable_count`, `has_buttons`, `button_labels`, `show_in`,
     `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`, `created_at`, `updated_at`)
VALUES

('deliver_today', 'Deliver — today', 'utility', 'en', 'approved', NULL,
 'Dear {{1}}, We''ve received your order {{2}}. This order will be processed and delivered today. Thank you for ordering with us,\n\nNizami Farms Team',
 NULL, 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW()),

('deliver_tomorrow', 'Deliver — tomorrow', 'utility', 'en', 'approved', NULL,
 'Dear {{1}},\n\nYour order {{2}} is confirmed and will be delivered tomorrow.\n\nIf you''d like to change anything, just reply to this message.\n\nNizami Farms Team',
 NULL, 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW()),

('deliver_wednesday', 'Deliver — Wednesday', 'utility', 'en', 'approved', NULL,
 'Dear {{1}},\n\nYour order {{2}} is confirmed.\n\nAs our operations are closed on Tuesday, your order will be delivered on Wednesday. If you''d like to change anything, just reply to this message.\n\nNizami Farms Team',
 NULL, 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW()),

('deliver_thursday', 'Deliver — Thursday', 'utility', 'en', 'approved', NULL,
 'Dear {{1}},\n\nYour order #{{2}} is confirmed.\n\nPlease note that Tuesday and Wednesday are non-meat days, and our operations are closed. Your order will be delivered on Thursday.\n\nNizami Farms Team',
 NULL, 2, 0, NULL, 'automation', 0, 1, 0, 0, NOW(), NOW());

-- Verify:
--   SELECT name, display_name, status, variable_count, show_in
--     FROM t_wa_templates WHERE name LIKE 'deliver_%' ORDER BY name;
-- =============================================================================
