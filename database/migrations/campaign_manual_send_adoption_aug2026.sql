-- =============================================================================
-- Campaigns: record HOW a recipient was messaged — Aug 2026
--
-- Run on LOCAL then PROD. Idempotent. Portable ALTER TABLE.
--
-- OPTIONAL: the code guards on Schema::hasColumn, so uploading the web files
-- WITHOUT running this is safe — manual sends still get adopted into campaigns,
-- you just don't see the "sent by hand" badge until the column exists. Deploy
-- order therefore does not matter.
--
-- WHY: the same WhatsApp template can reach a customer two ways —
--   1. the campaign sender works through its recipient list, or
--   2. someone opens the customer's chat and sends the template by hand
--      (e.g. "you just ordered, here's the app").
--
-- Until now (2) was invisible to campaigns: the person stayed 'pending', and the
-- next campaign send quietly moved them to Excluded. They had genuinely received
-- the message, but counted as neither reached nor converted. Now a manual send
-- marks the campaign row as sent; this column records that it happened by hand
-- so campaign-driven reach can still be told apart from personal follow-up.
-- =============================================================================

ALTER TABLE `t_crm_campaign_customers`
ADD COLUMN IF NOT EXISTS `sent_via` VARCHAR(16) NULL DEFAULT NULL
    COMMENT 'How this recipient was messaged: campaign = the campaign sender; manual = the template was sent by hand from the chat window and adopted into this campaign. NULL = sent before this column existed (treat as campaign).'
AFTER `sent_by`;


-- =========================================================================
-- Index for the template-wide results query
-- =========================================================================
-- "Results by template" now counts manual sends too, which means asking, for
-- each outbound template message, "did this conversation reply afterwards?".
-- t_wa_messages had only single-column indexes, so that EXISTS was scanning:
-- the union query measured 287ms, ~190ms of it in the reply check alone.
-- With this composite it drops to ~50-80ms. Purely additive.
ALTER TABLE `t_wa_messages`
ADD INDEX IF NOT EXISTS `idx_wa_conv_dir_created` (`conversation_id`, `direction`, `created_at`);


-- =========================================================================
-- VERIFICATION
-- =========================================================================
-- SHOW INDEX FROM t_wa_messages WHERE Key_name = 'idx_wa_conv_dir_created';
-- SHOW COLUMNS FROM t_crm_campaign_customers LIKE 'sent_via';
--
-- After some sending, the split per campaign:
-- SELECT c.name,
--        SUM(cc.sent_via = 'campaign') AS by_campaign,
--        SUM(cc.sent_via = 'manual')   AS by_hand,
--        SUM(cc.sent_via IS NULL AND cc.status = 'sent') AS before_tracking
-- FROM t_crm_campaigns c
-- JOIN t_crm_campaign_customers cc ON cc.campaign_id = c.id
-- WHERE cc.status = 'sent'
-- GROUP BY c.id, c.name;
