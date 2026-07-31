-- =============================================================================
-- Campaigns revamp — Jul 2026
--
-- Run in MySQL Workbench on LOCAL first, then PROD, BEFORE uploading the
-- matching web code (per workspace deploy rules). Idempotent where MySQL
-- allows it; portable ALTER TABLE only (shared hosting safe).
--
-- What this adds:
--   §1  Delivery funnel — links each campaign recipient to the WhatsApp
--       message actually sent, so Meta's delivered/read/undelivered
--       webhooks (already stored on t_wa_messages) can be surfaced per
--       campaign. Today the campaign has no idea what happened after
--       "accepted by Meta".
--   §2  A 'sending' claim state so two operators (or the background
--       worker + a browser) can never send the same person twice.
--   §3  Send-session control on the campaign (session limit, run state)
--       so a send happens in controlled batches instead of "everyone now".
--   §4  A send-run log — one row per Send action, giving the operator a
--       history of "session 1 sent 100 on Jul-30, session 2 sent 100 on
--       Jul-31" and why a run stopped.
--   §5  Global settings: daily send cap (WhatsApp tier), default session
--       size, background-sender master switch, pacing.
--   §6  tracking_type widened — the column is ENUM('general','products')
--       but the web UI already offers other values; under STRICT_TRANS_TABLES
--       picking one of those FAILS campaign creation. Widened to VARCHAR and
--       given the new 'app_orders' type for the customer-app install campaign.
-- =============================================================================


-- =========================================================================
-- §1  Delivery funnel columns
-- =========================================================================
-- wa_message_id is Meta's wamid for the template we sent this person. It is
-- the join key back to t_wa_messages, whose status column ALREADY receives
-- sent/delivered/read/failed from the webhook. delivered_at / read_at /
-- undelivered_at are denormalised onto the campaign row so campaign stats
-- never need to join the (large) messages table.
ALTER TABLE `t_crm_campaign_customers`
ADD COLUMN IF NOT EXISTS `wa_message_id` VARCHAR(128) NULL DEFAULT NULL
    COMMENT 'Meta wamid of the campaign template sent to this customer; join key to t_wa_messages.wa_message_id'
AFTER `sent_by`,
ADD COLUMN IF NOT EXISTS `delivered_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Stamped by the WhatsApp webhook when Meta reports the message reached the handset'
AFTER `wa_message_id`,
ADD COLUMN IF NOT EXISTS `read_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Stamped by the WhatsApp webhook on read receipt. NULL does NOT mean unread — customers can disable read receipts, so this is a floor.'
AFTER `delivered_at`,
ADD COLUMN IF NOT EXISTS `undelivered_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Stamped when Meta reports a POST-ACCEPTANCE delivery failure (dead/blocked number). Distinct from status=failed, which means the send API call itself was rejected.'
AFTER `read_at`,
ADD COLUMN IF NOT EXISTS `claimed_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Set when a sender claims this row (status=sending). Rows stuck in sending are auto-recovered to pending after 15 minutes.'
AFTER `undelivered_at`;

-- Webhook lookup path: wamid -> campaign row.
ALTER TABLE `t_crm_campaign_customers`
ADD INDEX IF NOT EXISTS `idx_cc_wa_message` (`wa_message_id`);

-- Send-order + claim scanning: the sender repeatedly asks "next N pending
-- rows for this campaign", and the stuck-row recovery asks "sending rows
-- older than 15 min". Both ride this index.
ALTER TABLE `t_crm_campaign_customers`
ADD INDEX IF NOT EXISTS `idx_cc_campaign_claim` (`campaign_id`, `status`, `claimed_at`);


-- =========================================================================
-- §2  'sending' claim state
-- =========================================================================
-- Claiming a row (pending -> sending) inside a single UPDATE ... WHERE
-- status='pending' is what makes double-sending impossible: whoever's UPDATE
-- reports 1 affected row owns that recipient. Everyone else skips it.
-- Existing rows are untouched — no value is removed from the enum.
ALTER TABLE `t_crm_campaign_customers`
MODIFY COLUMN `status` ENUM('pending','sending','sent','skipped','failed') NOT NULL DEFAULT 'pending';


-- =========================================================================
-- §3  Send-session control on the campaign
-- =========================================================================
ALTER TABLE `t_crm_campaigns`
ADD COLUMN IF NOT EXISTS `send_state` VARCHAR(16) NOT NULL DEFAULT 'idle'
    COMMENT 'idle | running | paused — running means the background sender should keep working this campaign'
AFTER `status`,
ADD COLUMN IF NOT EXISTS `send_mode` VARCHAR(16) NOT NULL DEFAULT 'manual'
    COMMENT 'manual = browser-driven send; background = drained by the campaigns:send-process scheduler'
AFTER `send_state`,
ADD COLUMN IF NOT EXISTS `session_limit` INT NOT NULL DEFAULT 100
    COMMENT 'Default number of customers one Send session will message. The operator confirms/edits this in the send dialog every time.'
AFTER `dedup_window_days`,
ADD COLUMN IF NOT EXISTS `active_run_id` INT NULL DEFAULT NULL
    COMMENT 'FK to t_crm_campaign_send_runs — the run currently in progress (NULL when idle)'
AFTER `session_limit`,
ADD COLUMN IF NOT EXISTS `send_paused_reason` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Why the last run stopped (daily cap, rate limit, operator pause, ...). Shown in the UI so a stall is never silent.'
AFTER `active_run_id`,
ADD COLUMN IF NOT EXISTS `last_send_at` DATETIME NULL DEFAULT NULL
AFTER `send_paused_reason`;


-- =========================================================================
-- §4  Send-run log (one row per Send session)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `t_crm_campaign_send_runs` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id`     INT NOT NULL,
    `mode`            VARCHAR(16)  NOT NULL DEFAULT 'manual' COMMENT 'manual | background',
    `target_count`    INT NOT NULL DEFAULT 0 COMMENT 'How many the operator asked for in this session',
    `attempted`       INT NOT NULL DEFAULT 0,
    `sent_count`      INT NOT NULL DEFAULT 0,
    `failed_count`    INT NOT NULL DEFAULT 0,
    `excluded_count`  INT NOT NULL DEFAULT 0 COMMENT 'Auto-moved to Excluded by the send-time dedup guard',
    `stop_reason`     VARCHAR(64) NULL COMMENT 'completed | target_reached | daily_cap | rate_limited | auth_error | too_many_failures | operator_paused | no_eligible',
    `started_by`      INT NULL,
    `started_at`      DATETIME NOT NULL,
    `finished_at`     DATETIME NULL,
    INDEX `idx_run_campaign` (`campaign_id`, `started_at`),
    INDEX `idx_run_open` (`campaign_id`, `finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================================
-- §5  Global settings
-- =========================================================================
-- wa_daily_send_cap mirrors the WhatsApp Business messaging tier (the number
-- of unique customers you may START a conversation with in a rolling 24h).
-- Owner's tier as of Jul-2026 = 2000. Raise this when Meta upgrades the tier.
-- Counting is done against ALL outbound template messages (campaigns AND
-- one-off sends) so the cap reflects reality, not just campaign traffic.
INSERT IGNORE INTO `t_fin_config`
    (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES
    ('wa_daily_send_cap', '2000',
     'WhatsApp messaging-tier cap: max unique customers messaged with a template in a rolling 24h. Campaign sends stop cleanly when this is reached (recipients stay Pending). Set to 0 to disable the guard.',
     NOW(), NOW()),
    ('wa_campaign_session_default', '100',
     'Default batch size proposed in the campaign Send dialog. The operator can change it per send; this is only the pre-filled value.',
     NOW(), NOW()),
    ('wa_campaign_auto_send', '1',
     'Master switch for the background campaign sender (campaigns:send-process). 0 pauses ALL background sending without touching individual campaigns.',
     NOW(), NOW()),
    ('wa_campaign_pace_ms', '250',
     'Milliseconds to wait between two campaign template sends. 250ms = ~4/sec, comfortably inside Meta throughput. Raise if you ever see rate-limit errors.',
     NOW(), NOW());


-- =========================================================================
-- §6  tracking_type: widen + add app_orders
-- =========================================================================
-- The column was ENUM('general','products') while the Create-Campaign UI
-- offered 'reactivation' / 'promotion'. With STRICT_TRANS_TABLES (this
-- server's sql_mode) picking either of those makes the INSERT fail, so
-- campaign creation would error out. VARCHAR removes the trap and lets us
-- add 'app_orders' for the customer-app install campaign, where a conversion
-- means "placed an order THROUGH the app" (order_source_channel ios_app /
-- android_app) rather than any order at all.
ALTER TABLE `t_crm_campaigns`
MODIFY COLUMN `tracking_type` VARCHAR(24) NOT NULL DEFAULT 'general'
    COMMENT 'What counts as a conversion: general = any delivered order | products = order containing tracked_product_ids | app_orders = order placed via the customer mobile app';


-- =========================================================================
-- §7  Indexes for the LIVE customer spend / order-count aggregate
-- =========================================================================
-- Campaign spend and order-count filters (and the "sort by spend" send order)
-- compute lifetime totals live, because the stored total_spent / total_orders
-- columns are stale for thousands of customers. That aggregate scans both order
-- tables, which measured ~370ms and made opening a spend-sorted campaign feel
-- sluggish.
--
-- These indexes let MariaDB satisfy the aggregate from the index alone
-- (customer_id to group by, then the filter columns, then the summed value),
-- cutting it to ~100-180ms. Purely additive — no query needs rewriting, and
-- nothing else changes behaviour.
ALTER TABLE `t_crm_prod_order`
ADD INDEX IF NOT EXISTS `idx_order_custstats` (`customer_id`, `order_status`, `external_source`, `total_price`);

ALTER TABLE `t_crm_history_order`
ADD INDEX IF NOT EXISTS `idx_hist_custstats` (`customer_id`, `order_status`, `total_price`);


-- =========================================================================
-- VERIFICATION
-- =========================================================================
-- SHOW INDEX FROM t_crm_prod_order    WHERE Key_name = 'idx_order_custstats';
-- SHOW INDEX FROM t_crm_history_order WHERE Key_name = 'idx_hist_custstats';
-- SHOW COLUMNS FROM t_crm_campaign_customers LIKE 'wa_message_id';
-- SHOW COLUMNS FROM t_crm_campaign_customers LIKE 'status';       -- expect 'sending' in the enum
-- SHOW COLUMNS FROM t_crm_campaigns LIKE 'send_state';
-- SHOW COLUMNS FROM t_crm_campaigns LIKE 'tracking_type';         -- expect varchar(24)
-- SELECT COUNT(*) FROM t_crm_campaign_send_runs;                  -- expect 0
-- SELECT config_key, config_value FROM t_fin_config WHERE config_key LIKE 'wa_campaign%' OR config_key = 'wa_daily_send_cap';
