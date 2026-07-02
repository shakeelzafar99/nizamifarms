-- ============================================================================
-- Manual per-bank balance adjustments (Bank Balances page)
-- Jul 2026 — run AFTER bank_level_tracking_jul2026.sql
-- ============================================================================
-- Lets the Taimur role true-up each bank's computed balance (+/-) so the card
-- matches the real bank account — mainly a one-time initial correction for the
-- half-tagged Feb–Jun history, but usable later for e.g. discovered bank fees.
--
-- DELIBERATELY NOT a t_fin_ledger row: a fake ledger transaction would change
-- the real ONLINE account balance, KPIs and audit checks. This is an
-- attribution-layer entry that only moves the per-bank card, shown in the
-- bank's statement as an "adjustment" row (and deletable if mis-entered).
--
-- Run ONCE on local, then on prod. Portable plain SQL.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_fin_bank_balance_adjustment` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `receiving_account_id` INT UNSIGNED NOT NULL
        COMMENT 'Which bank this adjustment applies to (FK t_fin_online_receiving_accounts).',
    `amount` DECIMAL(15,2) NOT NULL
        COMMENT 'Signed: positive adds to the bank balance, negative reduces it.',
    `adjustment_date` DATE NOT NULL
        COMMENT 'Date the adjustment applies (statement placement + period filters).',
    `note` VARCHAR(255) NULL
        COMMENT 'Why the adjustment was made (e.g. "initial true-up to real bank statement").',
    `created_by` INT NULL COMMENT 'FK t_sys_user.id',
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_bba_receiving_account`
        FOREIGN KEY (`receiving_account_id`)
        REFERENCES `t_fin_online_receiving_accounts`(`id`)
        ON DELETE CASCADE,
    INDEX `idx_bba_bank_date` (`receiving_account_id`, `adjustment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Post-migration sanity:
-- SELECT COUNT(*) FROM t_fin_bank_balance_adjustment;
