-- ============================================================================
-- Phase 2 L1 — audit trail table (2026-07-03)
-- Portable, plain DDL. Run on LOCAL and PROD (owner's manual workflow).
-- One row per audited business action; written by App\Services\AuditLogger via
-- Eloquent observers on Order / Ledger / OrderPayment. The PHP writer is
-- fail-safe: if this table does not exist yet, auditing silently no-ops and the
-- business action still succeeds — so it is SAFE to upload the PHP before
-- running this script (though nothing is logged until it exists).
-- `changes` is LONGTEXT holding JSON (kept as text for MySQL-version safety on
-- shared hosting; encoded/decoded in PHP).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_sys_audit_log` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `at`               DATETIME        NOT NULL,
  `user_id`          INT             NULL,
  `source`           VARCHAR(20)     NOT NULL DEFAULT 'web',   -- web | mobile | customer_app | system
  `action`           VARCHAR(40)     NOT NULL,                 -- created | updated | deleted | status_* | payment_recorded | payment_voided ...
  `entity_type`      VARCHAR(40)     NOT NULL,                 -- order | ledger | order_payment | ...
  `entity_id`        BIGINT          NULL,
  `entity_label`     VARCHAR(80)     NULL,                     -- human tag (order_number, "#123 invoice", "Rs 1180")
  `related_order_id` BIGINT          NULL,                     -- prod order id this action touched (for the order timeline)
  `changes`          LONGTEXT        NULL,                     -- JSON {field:{old,new}}
  `note`             VARCHAR(255)    NULL,
  `created_at`       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity`        (`entity_type`, `entity_id`),
  KEY `idx_audit_related_order` (`related_order_id`),
  KEY `idx_audit_at`            (`at`),
  KEY `idx_audit_user`          (`user_id`),
  KEY `idx_audit_action`        (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
