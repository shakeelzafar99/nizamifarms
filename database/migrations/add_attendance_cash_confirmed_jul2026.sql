-- =============================================================================
-- Migration: rider cash-held confirmation at check-out (Jul 2026)
--
-- Adds two columns to the attendance row so a rider can CONFIRM, at check-out,
-- the exact cash he is still holding against him (the "open" Daily Closing
-- amount). Purely a record for the manager — no ledger/settlement impact.
--
--   cash_confirmed_amount = the figure he confirmed
--   cash_confirmed_at     = when he confirmed it
--
-- If he dismisses the popup without confirming, both stay NULL (the gap is the
-- signal). Idempotent columns; safe to re-run. Run on LOCAL (dev) and PROD.
-- =============================================================================

ALTER TABLE `t_ops_attendance`
    ADD COLUMN IF NOT EXISTS `cash_confirmed_amount` DECIMAL(12,2) NULL DEFAULT NULL
        COMMENT 'Cash the rider confirmed holding at check-out (Daily Closing open amount)',
    ADD COLUMN IF NOT EXISTS `cash_confirmed_at` DATETIME NULL DEFAULT NULL
        COMMENT 'When the rider confirmed the cash-held amount',
    ADD COLUMN IF NOT EXISTS `cash_confirm_status` VARCHAR(20) NULL DEFAULT NULL
        COMMENT "'confirmed' = rider agreed; 'issue' = rider flagged the amount as wrong";

-- Verify:
-- SHOW COLUMNS FROM t_ops_attendance LIKE 'cash_confirm%';
-- =============================================================================
