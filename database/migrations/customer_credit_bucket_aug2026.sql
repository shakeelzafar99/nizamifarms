-- =====================================================================
-- Customer Credit ("bucket") — Phase 1 schema
-- Aug-2026. Run this ONCE on production BEFORE uploading the PHP files.
--
-- What this creates:
--   1. t_crm_customer_credit  — the event log. A customer's balance is
--      ALWAYS the SUM of these rows, never a stored column, so it can
--      never silently drift away from its own history.
--   2. The CUSTOMER_CREDIT liability account — one aggregate account that
--      says "this much customer money is sitting in our banks but is not
--      ours yet".
--
-- NOTE: no ALTER on any existing table. t_fin_ledger.transaction_type is
-- varchar(50), so the two new row types (customer_credit_grant /
-- customer_credit_consume) need no schema change.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `t_crm_customer_credit` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`            BIGINT UNSIGNED NOT NULL,

  -- grant  = money added to the bucket        (amount POSITIVE)
  -- consume = money spent on an order         (amount NEGATIVE)
  -- adjust  = manual correction / zero-out    (amount NEGATIVE)
  `entry_type`             VARCHAR(20)     NOT NULL,

  -- SIGNED on purpose: the balance is a plain SUM(amount), so there is no
  -- second "direction" column that could disagree with the sign.
  `amount`                 DECIMAL(12,2)   NOT NULL,

  -- pending  = grant awaiting approval          (does NOT count)
  -- reserved = consume applied to an undelivered order (counts: money is spoken for)
  -- active   = grant approved / consume delivered      (counts)
  -- voided   = cancelled or rejected                   (does NOT count)
  `status`                 VARCHAR(20)     NOT NULL,

  -- consume: the order the credit is being spent on.
  -- grant:   the order whose overpayment created the credit (for history).
  `order_id`               BIGINT UNSIGNED NULL,

  -- overpayment | manual | cancellation | zero_out
  `source`                 VARCHAR(30)     NOT NULL DEFAULT 'manual',
  `source_payment_id`      INT             NULL,  -- t_crm_order_payments.id
  `signal_id`              INT UNSIGNED    NULL,  -- t_fin_payment_signal.id
  `receiving_account_id`   INT UNSIGNED    NULL,  -- which bank actually holds the money

  `ledger_transaction_id`  BIGINT UNSIGNED NULL,  -- t_fin_ledger.id once posted

  `reason`                 VARCHAR(255)    NULL,
  `created_by`             INT             NULL,
  `approved_by`            INT             NULL,
  `approved_at`            DATETIME        NULL,
  `voided_by`              INT             NULL,
  `voided_at`              DATETIME        NULL,
  `voided_reason`          VARCHAR(255)    NULL,
  `created_at`             TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`             TIMESTAMP       NULL DEFAULT NULL,

  -- DB-level backstop for "one live consume per order". MySQL/MariaDB allow
  -- repeated NULLs in a unique index, so only live consume rows collide.
  -- Mirrors the existing unique active-invoice-per-order guard on the ledger.
  `active_consume_order_id` BIGINT UNSIGNED
      GENERATED ALWAYS AS (
        CASE WHEN `entry_type` = 'consume' AND `status` IN ('reserved', 'active')
             THEN `order_id` ELSE NULL END
      ) STORED,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cc_active_consume_per_order` (`active_consume_order_id`),
  KEY `idx_cc_customer_status` (`customer_id`, `status`),
  KEY `idx_cc_order`           (`order_id`),
  KEY `idx_cc_ledger`          (`ledger_transaction_id`),
  KEY `idx_cc_status_type`     (`status`, `entry_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- The aggregate liability account.
-- liability + the ledger engine's credit-arithmetic rule means:
--   grant   (from = CUSTOMER_CREDIT, to = bank)          -> liability +, bank +
--   consume (from = Sales Revenue,   to = CUSTOMER_CREDIT) -> revenue +, liability -
-- ---------------------------------------------------------------------
INSERT INTO `t_fin_accounts`
  (`account_code`, `account_name`, `account_type`, `account_category`,
   `opening_balance`, `current_balance`, `is_active`, `is_private`,
   `business_unit_id`, `created_at`, `created_by`)
SELECT
  'CUSTOMER_CREDIT', 'Customer Credit (advance held)', 'liability', 'customer_credit',
  0.00, 0.00, 1, 0,
  1, NOW(), 1
WHERE NOT EXISTS (
  SELECT 1 FROM `t_fin_accounts` WHERE `account_code` = 'CUSTOMER_CREDIT'
);


-- ---------------------------------------------------------------------
-- Verify
-- ---------------------------------------------------------------------
-- SELECT COUNT(*) AS credit_table_ok FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_customer_credit';
-- SELECT id, account_code, account_name, account_type, current_balance
--   FROM t_fin_accounts WHERE account_code = 'CUSTOMER_CREDIT';
