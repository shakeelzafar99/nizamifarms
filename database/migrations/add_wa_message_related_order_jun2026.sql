-- =============================================================================
-- Add per-order linkage to WhatsApp messages (Jun 2026)
--
-- WHY: when a customer taps a quick-reply button on an "order received" template
-- (Confirm Wednesday / Split / Cancel / Deliver Thursday), WhatsApp sends the tap
-- back with a `context.id` pointing at the template message. We resolve that to
-- the specific order (via t_wa_automation_log) and stamp the order_number here,
-- so the Shopify approval list + approve popup can show the choice on the EXACT
-- order instead of just "this customer replied".
--
-- SAFE: one nullable column + index. Nothing reads/writes it until the matching
-- app code is deployed, and that code is guarded with Schema::hasColumn() so it
-- silently falls back to the current per-customer behaviour if this hasn't run.
-- No money/ledger impact. Run on LOCAL + PROD (then /api/public/xclean on web).
--
-- order_NUMBER (e.g. SH-20846), not id — unique across the system, so it is
-- immune to the Shopify-staging vs prod id collision.
-- =============================================================================

ALTER TABLE `t_wa_messages`
    ADD COLUMN `related_order_number` VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Order this message relates to (e.g. a button reply to an order-received template). Stamped at webhook time.'
        AFTER `metadata`;

ALTER TABLE `t_wa_messages`
    ADD INDEX `idx_wa_msg_related_order` (`related_order_number`);

-- Verify:
--   SHOW COLUMNS FROM t_wa_messages LIKE 'related_order_number';
-- =============================================================================
