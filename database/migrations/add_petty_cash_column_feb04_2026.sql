-- =====================================================
-- ADD PETTY CASH COLUMN TO ACCOUNTS TABLE
-- Created: February 4, 2026
-- Purpose: Track petty cash (change money) for employee accounts
-- =====================================================

-- Add petty_cash column to t_fin_accounts table
ALTER TABLE `t_fin_accounts` 
ADD COLUMN `petty_cash` DECIMAL(12,2) DEFAULT 0.00 AFTER `current_balance`;

-- Add comment for clarity
ALTER TABLE `t_fin_accounts` 
MODIFY COLUMN `petty_cash` DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Petty cash/change money for customer transactions';

SELECT '✅ Added petty_cash column to t_fin_accounts' as Status;

-- =====================================================
-- NOTE: This column stores the petty cash amount assigned
-- to each rider for handling customer change.
-- - Viewable by all users
-- - Modifiable only by Taimur
-- - Shows in the NF Ledger detail screen
-- =====================================================
