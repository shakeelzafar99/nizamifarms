-- =====================================================
-- App Webhook Events (Outbox Table)
-- Date: May 20, 2026
--
-- Phase 1 of the customer-app integration: NF emits signed,
-- server-to-server webhooks to the Vercel-hosted customer app for
-- order-status changes. This table is the *outbox* — a durable buffer
-- between the request thread (where the status change happens) and
-- the dispatcher (a scheduled artisan command that drains it).
--
-- Design rationale
-- ----------------
-- 1. Decouples NF latency from Vercel reachability. If Vercel is down,
--    the request thread still completes — we just queue the row and
--    retry later. No HTTP call ever runs on the request thread.
--
-- 2. Survives crashes / deploys. Pending rows live until they are
--    sent or marked dead, so a deploy mid-flight doesn't lose events.
--
-- 3. Replayable. Support can re-fire any single event_uuid (or every
--    pending row for an order) via:
--      php artisan app:dispatch-customer-webhooks --event-uuid=...
--      php artisan app:dispatch-customer-webhooks --order=SH-1234
--
-- 4. Multi-target ready. The `target` column defaults to
--    'customer_app' but a future SMS gateway / BI pipeline can write
--    rows with its own target value without schema changes.
--
-- No FK constraints — the outbox must survive even if an order row is
-- pruned later, and the dispatcher just needs the order_number string
-- in the JSON payload anyway.
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS guards the create.
-- =====================================================

CREATE TABLE IF NOT EXISTS `t_app_webhook_events` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_uuid`      CHAR(36) NOT NULL COMMENT 'UUID v4. Sent in payload as the customer-app dedupe key.',
    `event_type`      VARCHAR(64) NOT NULL COMMENT 'e.g. order.status_changed. Phase 1 only emits this one type.',
    `order_id`        BIGINT UNSIGNED NULL COMMENT 't_crm_prod_order.id — used for first-event detection and replay-by-order. No FK so the outbox survives order pruning.',
    `order_number`    VARCHAR(64) NULL COMMENT 'NF order_number with prefix (e.g. SH-1234). Mirrors the JSON payload for fast SQL filtering.',
    `payload`         JSON NOT NULL COMMENT 'The exact body that will be sent to the customer-app webhook URL.',
    `target`          VARCHAR(32) NOT NULL DEFAULT 'customer_app' COMMENT 'Future-proofs the outbox for multiple consumers.',
    `status`          ENUM('pending','sent','failed','dead','skipped') NOT NULL DEFAULT 'pending'
                      COMMENT 'pending = never tried or due for retry. sent = 2xx received. failed = transient failure, will retry. dead = retries exhausted, ops alert. skipped = intentionally not delivered (e.g. stale backlog neutralized by ops).',
    `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error`      TEXT NULL,
    `next_attempt_at` DATETIME NULL COMMENT 'NULL means try on next dispatcher tick. Used by retry backoff.',
    `sent_at`         DATETIME NULL,
    `created_at`      DATETIME NOT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_event_uuid` (`event_uuid`),
    KEY `idx_status_next` (`status`, `next_attempt_at`) COMMENT 'Drives the dispatcher dequeue query.',
    KEY `idx_order` (`order_id`) COMMENT 'First-event detection + replay-by-order.',
    KEY `idx_order_number` (`order_number`) COMMENT 'Replay-by-order-number convenience.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Verification ─────────────────────────────────
-- After running, confirm the table exists with the right columns:
--
-- SHOW CREATE TABLE t_app_webhook_events;
--
-- And that the dispatcher's primary index is in place:
--
-- SHOW INDEX FROM t_app_webhook_events WHERE Key_name = 'idx_status_next';


-- =====================================================
-- ONE-TIME MAINTENANCE (run manually on an EXISTING prod table only)
-- =====================================================
-- Tables created from THIS file already include 'skipped' in the enum.
-- Production was created from an earlier version of this file, so on prod
-- only, add the value first:
--
-- ALTER TABLE `t_app_webhook_events`
--   MODIFY COLUMN `status`
--   ENUM('pending','sent','failed','dead','skipped') NOT NULL DEFAULT 'pending';
--
-- Then neutralize the stale backlog that accumulated before the delivery
-- mechanism (terminating() drain) went live, so the customer app is not
-- flooded with old, out-of-date status events. Only NEW status changes from
-- this point forward will be delivered:
--
-- UPDATE `t_app_webhook_events`
-- SET `status` = 'skipped',
--     `last_error` = 'Neutralized: stale pre-delivery backlog (not sent)'
-- WHERE `target` = 'customer_app'
--   AND `status` = 'pending';
--
-- The drain only ever selects status = 'pending', so 'skipped' rows are
-- permanently ignored (and excluded from replay-by-order too).
-- =====================================================
