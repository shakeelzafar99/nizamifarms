-- ============================================================================
-- Payment Receiving-Bank + Invoice PAID-Stamp Metadata + Approval Txn Reference
-- Apr 2026
-- ============================================================================
-- This migration adds three capabilities:
--   A. Tag each online qurbani/order payment with which of OUR banks received
--      it (reuses the existing t_fin_online_receiving_accounts lookup that was
--      created for Online Approvals in Feb 2026).
--   B. Store per-order PAID-stamp display metadata (sending bank, stamp date,
--      ref mode) on the order itself so it can be edited later from the
--      invoice without touching the underlying payment rows — this is exactly
--      the "change the stamp date without changing the actual last payment
--      date" behaviour requested.
--   C. Capture the customer's transaction reference against the online
--      approval ledger entry (Online Approvals already captures the receiving
--      bank + proof image; this closes the last gap).
--
-- All columns are nullable. Old payments, orders, and ledger rows stay valid.
-- Old mobile APKs that don't know about any of these continue to work — every
-- new field is treated as optional on the server.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- A) Qurbani / order payments: which of OUR banks received the money
-- ----------------------------------------------------------------------------
ALTER TABLE `t_crm_order_payments`
    ADD COLUMN `receiving_account_id` INT UNSIGNED NULL
        COMMENT 'FK to t_fin_online_receiving_accounts.id (HBL, MBL, EP, JC, ...)'
        AFTER `payment_method`;

ALTER TABLE `t_crm_order_payments`
    ADD CONSTRAINT `fk_op_receiving_account`
        FOREIGN KEY (`receiving_account_id`)
        REFERENCES `t_fin_online_receiving_accounts`(`id`)
        ON DELETE SET NULL;

CREATE INDEX `idx_op_receiving_account` ON `t_crm_order_payments` (`receiving_account_id`);


-- ----------------------------------------------------------------------------
-- B) PAID-stamp metadata on the order (display only, not finance)
-- ----------------------------------------------------------------------------
ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `paid_stamp_sending_bank` VARCHAR(100) NULL
        COMMENT 'Customer-side bank that sent the payment; free text. Shown as "via: ..." on PAID stamp. For cash payments leave NULL and stamp shows "via: CASH".',
    ADD COLUMN `paid_stamp_date` DATE NULL
        COMMENT 'Date shown on PAID stamp. Pre-filled from last payment date but overridable without touching payment rows.',
    ADD COLUMN `paid_stamp_ref_mode` VARCHAR(20) NULL DEFAULT 'reference'
        COMMENT 'reference | customer_name | blank — controls third line of PAID stamp.';


-- ----------------------------------------------------------------------------
-- C) Transaction reference on online approval ledger rows
-- ----------------------------------------------------------------------------
ALTER TABLE `t_fin_ledger`
    ADD COLUMN `transaction_reference` VARCHAR(255) NULL
        COMMENT 'Optional customer-side transaction ID captured during Online Approval.'
        AFTER `bill_image`;


-- ============================================================================
-- Post-migration sanity: verify columns landed as expected
-- ============================================================================
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
-- FROM   INFORMATION_SCHEMA.COLUMNS
-- WHERE  TABLE_SCHEMA = DATABASE()
--   AND (
--        (TABLE_NAME = 't_crm_order_payments' AND COLUMN_NAME = 'receiving_account_id')
--     OR (TABLE_NAME = 't_crm_prod_order'     AND COLUMN_NAME LIKE 'paid_stamp_%')
--     OR (TABLE_NAME = 't_fin_ledger'         AND COLUMN_NAME = 'transaction_reference')
--   )
-- ORDER BY TABLE_NAME, ORDINAL_POSITION;
