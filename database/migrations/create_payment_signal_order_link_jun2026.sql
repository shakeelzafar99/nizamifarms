-- =====================================================
-- Combined / bulk payment support — signal⇄order link table
-- Date: June 25, 2026
--
-- WHAT THIS IS
-- ------------
-- One NEW, fully-decoupled table that lets a SINGLE payment signal (one
-- WhatsApp screenshot or one bank email) be tied to SEVERAL open invoices —
-- the "combined payment" case where a customer pays for the latest invoice
-- plus a few older ones in one transfer.
--
-- Until now a signal had exactly one `matched_order_id`, so a bulk transfer
-- could only decorate one invoice and was flagged `amount_mismatch`. This
-- table records every invoice a combined signal covers, so all of them show
-- the same proof (and "Verified" once the bank email corroborates).
--
-- LIKE THE OTHER SIGNAL TABLES, THIS NEVER TOUCHES MONEY. It only records
-- which invoices a proof appears to cover; the ledger / order payments /
-- balances are still written only by the human approval flow.
--
-- DESIGN NOTES (mirrors t_fin_payment_signal convention)
-- ------------------------------------------------------
-- * No FK constraints — decoupled cache; must survive order/signal pruning
--   and must never fail this manual migration on a column-type mismatch.
-- * signal_id matches t_fin_payment_signal.id (INT UNSIGNED).
-- * order_id  matches t_crm_prod_order.id      (BIGINT UNSIGNED).
-- * UNIQUE(signal_id, order_id) makes re-matching idempotent (the matcher
--   clears + rewrites a signal's links on every run).
-- * Re-runnable: CREATE uses IF NOT EXISTS.
-- =====================================================

CREATE TABLE IF NOT EXISTS `t_fin_payment_signal_order` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `signal_id`        INT UNSIGNED NOT NULL
                       COMMENT 't_fin_payment_signal.id of the combined proof. No FK.',
    `order_id`         BIGINT UNSIGNED NOT NULL
                       COMMENT 't_crm_prod_order.id this proof also covers. No FK.',
    `balance_at_match` DECIMAL(12,2) NULL
                       COMMENT "The invoice's outstanding balance when the link was made (snapshot, for display/debug).",
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    -- One link per (signal, order). Re-matching deletes + reinserts.
    UNIQUE KEY `uq_signal_order` (`signal_id`, `order_id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_signal` (`signal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify:
-- SHOW CREATE TABLE t_fin_payment_signal_order;
-- SELECT COUNT(*) FROM t_fin_payment_signal_order;  -- expect 0 on first run
