-- =====================================================
-- Migration: Campaign qurbani-year filter + reply tracking + match tags
--            Template active-flag + qurbani-only flag
-- Created: April 2026
-- Safe to re-run (uses IF NOT EXISTS)
-- =====================================================
--
-- 1) CAMPAIGNS — Multi-filter groups + qurbani-year filter
--    No schema change needed. Both live inside the existing `filters_json`
--    column on t_crm_campaigns. New campaigns store `filters_json` in the
--    shape:
--      { "groups": [ {...}, {...} ],
--        "exclude_campaign_ids": [..],
--        "exclude_campaign_customer_ids": [..],
--        "sort_by": "...", "sort_dir": "..." }
--    Old single-object campaigns keep working (backend detects shape and
--    treats a legacy object as a single group).
--
-- 2) CAMPAIGNS — Reply tracking (replied_at)
--    Webhook stamps this column on the campaign_customers row the first
--    time an inbound WhatsApp message is received from that customer
--    inside the campaign's tracking window.
--
-- 3) CAMPAIGNS — Per-customer match tags (match_tags)
--    Human-readable labels of which filter group(s) matched the customer,
--    e.g. ["Qurbani 2025","90d active · Lahore"]. Displayed as small
--    pills in the campaign customer list so users can see at a glance
--    why each person is in the campaign.
--
-- 4) WHATSAPP TEMPLATES — is_active + is_qurbani_only
--    Let the user toggle a template off without deleting it (hidden
--    from all pickers) and mark a template as qurbani-mode only so it
--    doesn't show up on general pickers.

-- ========= 2) Reply tracking =========
ALTER TABLE `t_crm_campaign_customers`
ADD COLUMN IF NOT EXISTS `replied_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Timestamp of first inbound WhatsApp reply from this customer after the campaign send (populated by webhook)'
AFTER `error_message`;

ALTER TABLE `t_crm_campaign_customers`
ADD INDEX IF NOT EXISTS `idx_customer_status_replied` (`customer_id`, `status`, `replied_at`);

-- ========= 3) Match tags =========
ALTER TABLE `t_crm_campaign_customers`
ADD COLUMN IF NOT EXISTS `match_tags` JSON NULL DEFAULT NULL
    COMMENT 'Array of short labels identifying which filter group(s) matched this customer, e.g. ["Qurbani 2025","90d active"]'
AFTER `replied_at`;

-- ========= 4) Templates: active + qurbani-only =========
ALTER TABLE `t_wa_templates`
ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'When 0, the template is hidden from every picker (soft-disable without deleting)'
AFTER `is_default`;

ALTER TABLE `t_wa_templates`
ADD COLUMN IF NOT EXISTS `is_qurbani_only` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'When 1, the template only shows up inside Qurbani-mode pickers (qurbani_invoice/qurbani_orders contexts); hidden from everything else regardless of show_in'
AFTER `is_active`;

-- ========= VERIFICATION =========
-- SHOW COLUMNS FROM t_crm_campaign_customers LIKE 'replied_at';
-- SHOW COLUMNS FROM t_crm_campaign_customers LIKE 'match_tags';
-- SHOW COLUMNS FROM t_wa_templates LIKE 'is_active';
-- SHOW COLUMNS FROM t_wa_templates LIKE 'is_qurbani_only';
-- SHOW INDEX FROM t_crm_campaign_customers WHERE Key_name = 'idx_customer_status_replied';
