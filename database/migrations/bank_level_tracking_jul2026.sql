-- ============================================================================
-- Bank-level balance tracking on the single ONLINE ledger account
-- Jul 2026
-- ============================================================================
-- Goal: keep ONE "Online Bank" chart-of-accounts row (so the manager still sees
-- every online transaction in one window) while being able to read a CURRENT
-- BALANCE per individual receiving bank. We do this WITHOUT splitting ONLINE
-- into per-bank accounts: the bank is a *tag* (receiving_account_id) that must
-- be present on every online money movement (in AND out); per-bank balance is
-- then just a sum of tagged rows from a user-set opening balance/date.
--
-- This migration adds the columns that make that possible:
--   A. Banks lookup: last-4 account identifier (auto-seeded from existing short
--      codes) + opening balance + opening-balance date.
--   B. Payment signals: the receiving-account last-4 read from the WhatsApp
--      screenshot (Gemini) + an extraction-attempt counter (retry cap + bounds
--      the one-time re-extract backfill).
--   C. Expense requests: which of OUR banks an ONLINE expense is paid from.
--
-- All columns are nullable / defaulted. Existing rows, old APKs, and current
-- flows stay valid. Money is never touched by this script.
--
-- Run ONCE on local, then on prod (owner runs manually — no CI/CD). Portable
-- plain ALTER/UPDATE only (per AGENTS.md / CLAUDE.md).
-- ============================================================================


-- ----------------------------------------------------------------------------
-- A) Receiving-bank lookup: last-4 identifier + opening balance
-- ----------------------------------------------------------------------------
-- account_last4 is how a WhatsApp screenshot's "To Account: ...xxx4237" maps to
-- a specific chip (MBL-4237). bank_name is an OPTIONAL institution label used
-- ONLY to break ties if two chips ever share the same last-4 (none today) —
-- leave it NULL and last-4 alone resolves every current account.
ALTER TABLE `t_fin_online_receiving_accounts`
    ADD COLUMN `account_last4` VARCHAR(4) NULL
        COMMENT 'Last 4 digits of THIS receiving account. Used to auto-detect which bank a WhatsApp proof was received in.'
        AFTER `short_code`,
    ADD COLUMN `bank_name` VARCHAR(100) NULL
        COMMENT 'OPTIONAL institution name (e.g. Meezan, HBL). Only used to disambiguate if two accounts share the same last-4. Safe to leave NULL.'
        AFTER `account_last4`,
    ADD COLUMN `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00
        COMMENT 'Starting balance of this bank as of opening_balance_date. Per-bank current balance = opening_balance + tagged inflows - tagged outflows since that date.'
        AFTER `bank_name`,
    ADD COLUMN `opening_balance_date` DATE NULL
        COMMENT 'Date the opening_balance is measured from. Rows before this date are visible in history but do NOT count toward the balance.'
        AFTER `opening_balance`;

-- Auto-seed account_last4 from the trailing 4 digits already baked into your
-- short codes (HBL-4403 -> 4403, MBL-4237 -> 4237, ASCM-0971 -> 0971, ...).
-- Codes with no trailing 4 digits (EP, KM-MBL, GM-SCB, AN-SCB) stay NULL — set
-- those by hand in Manage Banks if/when you want them auto-detected.
UPDATE `t_fin_online_receiving_accounts`
SET `account_last4` = RIGHT(`short_code`, 4)
WHERE `account_last4` IS NULL
  AND `short_code` REGEXP '[0-9]{4}$';

-- Helpful (non-unique — last-4 may repeat across banks) lookup index.
CREATE INDEX `idx_ora_account_last4` ON `t_fin_online_receiving_accounts` (`account_last4`);


-- ----------------------------------------------------------------------------
-- B) Payment signals: receiving-account last-4 + extraction attempt counter
-- ----------------------------------------------------------------------------
ALTER TABLE `t_fin_payment_signal`
    ADD COLUMN `extracted_to_account_last4` VARCHAR(4) NULL
        COMMENT 'Last 4 digits of OUR receiving account read from the WhatsApp screenshot (Gemini). Maps to t_fin_online_receiving_accounts.account_last4.'
        AFTER `extracted_to_account_short`,
    ADD COLUMN `extraction_attempts` INT NOT NULL DEFAULT 0
        COMMENT 'Times Gemini extraction has run on this signal. Caps retries on permanent failures and bounds the one-time re-extract-for-bank backfill.'
        AFTER `extraction_confidence`;


-- ----------------------------------------------------------------------------
-- C) Expense requests: which of OUR banks an ONLINE expense was paid from
-- ----------------------------------------------------------------------------
ALTER TABLE `t_req_master`
    ADD COLUMN `receiving_account_id` INT UNSIGNED NULL
        COMMENT 'Which of OUR banks an ONLINE expense is paid from (FK to t_fin_online_receiving_accounts.id). NULL for cash / non-online sources.'
        AFTER `payment_source_account_id`;

ALTER TABLE `t_req_master`
    ADD CONSTRAINT `fk_req_receiving_account`
        FOREIGN KEY (`receiving_account_id`)
        REFERENCES `t_fin_online_receiving_accounts`(`id`)
        ON DELETE SET NULL;


-- ============================================================================
-- Post-migration sanity checks (optional — run to eyeball the result)
-- ============================================================================
-- SELECT id, name, short_code, account_last4, opening_balance, opening_balance_date
-- FROM   t_fin_online_receiving_accounts
-- ORDER  BY sort_order, name;
--
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
-- FROM   INFORMATION_SCHEMA.COLUMNS
-- WHERE  TABLE_SCHEMA = DATABASE()
--   AND (
--        (TABLE_NAME = 't_fin_online_receiving_accounts' AND COLUMN_NAME IN ('account_last4','bank_name','opening_balance','opening_balance_date'))
--     OR (TABLE_NAME = 't_fin_payment_signal'            AND COLUMN_NAME IN ('extracted_to_account_last4','extraction_attempts'))
--     OR (TABLE_NAME = 't_req_master'                    AND COLUMN_NAME = 'receiving_account_id')
--   )
-- ORDER BY TABLE_NAME, ORDINAL_POSITION;
