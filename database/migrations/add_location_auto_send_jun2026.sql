-- =============================================
-- Auto Location-Request (Open Orders "Get Locations" automation)
-- Created: 2026-06-18
-- Purpose: Let the system automatically send the location-request WhatsApp
--          template to qualifying customers (same rule as the manual "Get
--          Locations" button) when a Shopify order is ACCEPTED (converted) or a
--          new NF/app order is created — WITHOUT staff pressing the button.
--
--          Sends respect a daily message window (default 09:00–18:00 Asia/
--          Karachi). Orders that qualify outside the window are queued and
--          flushed automatically when the window next opens. A master switch
--          keeps the whole automation OFF until the owner turns it on; while
--          off, nothing is queued (so turning it on later never dumps a
--          backlog of old orders).
--
-- NOTE: This is an additive feature. The manual Get Locations flow, eligibility
--       query, and WhatsApp send path are reused unchanged.
-- =============================================

-- ---------------------------------------------------------------------------
-- 1. Outbox/queue table — one pending row per customer awaiting an auto send.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS t_crm_location_auto_queue (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id     INT NOT NULL COMMENT 't_crm_prod_customer.id to message.',
    order_id        INT NULL COMMENT 't_crm_prod_order.id that triggered the enqueue (audit only).',
    trigger_source  VARCHAR(32) NOT NULL DEFAULT 'unknown' COMMENT 'shopify_convert | nf_create.',
    status          VARCHAR(16) NOT NULL DEFAULT 'queued' COMMENT 'queued | sent | skipped | failed.',
    attempts        INT NOT NULL DEFAULT 0 COMMENT 'Number of send attempts made by the drain worker.',
    template_name   VARCHAR(100) NULL COMMENT 'Template actually used when sent.',
    last_error      VARCHAR(500) NULL COMMENT 'Last failure reason (if status=failed).',
    enqueued_at     DATETIME NOT NULL COMMENT 'When the row was queued.',
    sent_at         DATETIME NULL COMMENT 'When the message was sent (status=sent).',
    created_at      DATETIME NULL,
    updated_at      DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_loc_auto_status (status),
    KEY idx_loc_auto_customer (customer_id),
    KEY idx_loc_auto_status_enqueued (status, enqueued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Queue for auto location-request WhatsApp sends (Open Orders automation).';

-- ---------------------------------------------------------------------------
-- 2. Runtime settings in t_fin_config (editable from the web, no redeploy).
--    Inserted only if missing, so re-running this script is safe.
-- ---------------------------------------------------------------------------

-- Master switch — OFF by default. Owner turns it on once happy.
INSERT INTO t_fin_config (config_key, config_value, description)
SELECT 'location_auto_send_enabled', '0',
       'Auto-send the location request template to qualifying customers when a Shopify order is accepted or a new NF order is created. 1 = on, 0 = off.'
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'location_auto_send_enabled');

-- Daily message window (24h HH:MM). Outside this window, sends are queued and
-- flushed when the window next opens.
INSERT INTO t_fin_config (config_key, config_value, description)
SELECT 'location_auto_window_start', '09:00',
       'Start of the daily auto location-request send window (HH:MM, Asia/Karachi).'
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'location_auto_window_start');

INSERT INTO t_fin_config (config_key, config_value, description)
SELECT 'location_auto_window_end', '18:00',
       'End of the daily auto location-request send window (HH:MM, Asia/Karachi).'
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'location_auto_window_end');

-- Global default template (used by both the automation and the manual button).
-- Empty until an admin sets it on the web OR the first mobile send auto-adopts
-- the chosen template. When empty, code falls back to config('open_order_location.default_template').
INSERT INTO t_fin_config (config_key, config_value, description)
SELECT 'open_order_location_default_template', '',
       'Global default WhatsApp template for Open Orders location requests. Empty = fall back to config file default.'
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'open_order_location_default_template');

INSERT INTO t_fin_config (config_key, config_value, description)
SELECT 'open_order_location_default_lang', 'en',
       'Global default language for the Open Orders location-request template.'
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'open_order_location_default_lang');

-- Verify:
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 't_crm_location_auto_queue';
SELECT config_key, config_value FROM t_fin_config
WHERE config_key IN (
    'location_auto_send_enabled', 'location_auto_window_start', 'location_auto_window_end',
    'open_order_location_default_template', 'open_order_location_default_lang'
);
SELECT 'Auto location-request table + settings ready!' as status;
