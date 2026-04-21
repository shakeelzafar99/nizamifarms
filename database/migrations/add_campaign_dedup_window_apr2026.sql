-- =====================================================================
-- add_campaign_dedup_window_apr2026.sql
-- =====================================================================
-- Purpose:
--   Persist the operator-chosen "dedup window" (in days) on the campaign
--   record itself. Previously this value was only accepted at create/add
--   time and used once to mark the initially-excluded customers; at
--   send-time we had no way to know which window the operator originally
--   intended, so we couldn't re-check and skip customers who had since
--   received the same template via another campaign.
--
--   Storing it on the campaign row lets us:
--     1. Re-run dedup as a safety guard in sendBulk / sendCampaignBulk
--        (so a pending customer who received the template via a newer
--        campaign in the window is auto-moved to Excluded instead of
--        being messaged again).
--     2. Offer a manual "Refresh Dedup" action on the campaign detail
--        that applies the same re-check without sending.
--
--   A value of 0 (the default) means dedup is OFF for that campaign —
--   existing campaigns created before this migration continue to
--   behave exactly as before (no send-time guard, no silent skips).
-- =====================================================================

ALTER TABLE `t_crm_campaigns`
ADD COLUMN IF NOT EXISTS `dedup_window_days` INT NOT NULL DEFAULT 0
    COMMENT 'Template-dedup window in days; 0 = disabled. Set at create time, honoured by sendBulk and Refresh Dedup.'
AFTER `tracking_window_days`;
