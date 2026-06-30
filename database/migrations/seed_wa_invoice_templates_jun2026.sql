-- =============================================================================
-- SEED: invoice / out-for-delivery template config (Jun 2026)
--
-- ⚠️ RUN THIS WITH (or after) THE ETA-AWARE SEND-INVOICE UPDATE — not before.
-- These FOUR keys (invoice_template_{online,cash}_{eta,noeta}) are read by that
-- update's send logic. The CURRENT code reads a different 2-key scheme
-- (invoice_template_online / invoice_template_cash), so until the ETA-aware
-- update ships these keys sit unused and your existing Send Invoice keeps using
-- its default template. (Harmless to run early — just inert — but it does
-- nothing visible until the send logic that reads these keys is deployed.)
--
-- Points the Send Invoice button at your new templates. The send logic picks
-- online vs cash by the order's payment method, and the WITH-ETA (3 vars: name,
-- order#, ETA) vs NO-ETA (2 vars: name, order#) version by whether the delivery
-- ETA exists yet. These four config keys hold those names:
--
--   invoice_template_online_eta   = invoice_online_eta   (image + 3 vars)
--   invoice_template_online_noeta = invoice_online       (image + 2 vars)
--   invoice_template_cash_eta     = invoice_cash_eta     (image + 3 vars)
--   invoice_template_cash_noeta   = invoice_cash         (image + 2 vars)
--
-- Create the templates in Meta with EXACTLY these names (see
-- WHATSAPP-AUTOMATION-META-TEMPLATES.md), add them in the app, then this config
-- already points at them.
--
-- OFF / SAFE: these are just template-name pointers. Nothing auto-sends — the
-- master automations switch stays '0' and the invoice send is MANUAL (the Send
-- Invoice button). Your current Send Invoice keeps working unchanged until the
-- ETA-aware send logic ships; once it does, it reads these keys.
--
-- If you are NOT making separate cash templates, change the two cash values to
-- 'invoice_online_eta' / 'invoice_online' so cash orders use the same message.
--
-- SAFE TO RE-RUN: INSERT IGNORE on the unique config_key — won't overwrite a
-- value you later change in the app. Run on LOCAL + PROD (after
-- create_wa_automations_jun2026.sql). No money/ledger impact.
-- =============================================================================

INSERT IGNORE INTO `t_fin_config`
    (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES
    ('invoice_template_online_eta',   'invoice_online_eta',
     'Send Invoice template: online/bank order, ETA known (3 vars: name, order#, ETA).', NOW(), NOW()),
    ('invoice_template_online_noeta', 'invoice_online',
     'Send Invoice template: online/bank order, no ETA yet (2 vars: name, order#).', NOW(), NOW()),
    ('invoice_template_cash_eta',     'invoice_cash_eta',
     'Send Invoice template: cash order, ETA known (3 vars: name, order#, ETA).', NOW(), NOW()),
    ('invoice_template_cash_noeta',   'invoice_cash',
     'Send Invoice template: cash order, no ETA yet (2 vars: name, order#).', NOW(), NOW());

-- Verify:
--   SELECT config_key, config_value FROM t_fin_config WHERE config_key LIKE 'invoice_template_%';
-- =============================================================================
