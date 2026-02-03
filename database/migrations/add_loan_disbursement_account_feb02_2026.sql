-- =====================================================================
-- Migration: Add Loan Disbursement Account Tracking
-- Date: Feb 02, 2026
-- Purpose: Track loan disbursement source for proper accounting on cancellation
-- =====================================================================

-- STEP 1: Add disbursement_account_id to loans table
-- This tracks which account the loan was disbursed from
-- NULL means "outside cash" (not from company accounts)

ALTER TABLE t_hr_employee_loans
ADD COLUMN disbursement_account_id INT NULL COMMENT 'FK to t_fin_accounts.id - Source account for disbursement. NULL = outside cash'
AFTER ledger_transaction_id;

-- Add index for performance
ALTER TABLE t_hr_employee_loans
ADD INDEX idx_disbursement_account (disbursement_account_id);

SELECT '✓ Step 1: Added disbursement_account_id to t_hr_employee_loans' as Status;

-- =====================================================================
-- EXPLANATION OF LOAN DISBURSEMENT FLOW:
-- =====================================================================
-- 
-- SCENARIO 1: Disbursed from Company Account (NF_CASH, EXP_FUND, etc.)
--   - disbursement_account_id = account_id
--   - disburse_via_ledger = true
--   - Creates ledger entry: From account → Employee Cash
--   - On cancel: Refund remaining balance to disbursement_account_id
--
-- SCENARIO 2: Outside Cash
--   - disbursement_account_id = NULL
--   - disburse_via_ledger = false
--   - NO ledger entry created
--   - On cancel: No refund needed (was outside money)
--
-- =====================================================================

-- STEP 2: Backfill existing loans that have ledger entries
-- If a loan has ledger_transaction_id, find the source account from the ledger

UPDATE t_hr_employee_loans el
SET el.disbursement_account_id = (
    SELECT l.from_account_id 
    FROM t_fin_ledger l 
    WHERE l.id = el.ledger_transaction_id
)
WHERE el.ledger_transaction_id IS NOT NULL
AND el.disbursement_account_id IS NULL;

SELECT '✓ Step 2: Backfilled disbursement_account_id for existing loans with ledger entries' as Status;

-- =====================================================================
-- SUMMARY:
-- =====================================================================
-- 
-- Loan Creation Options:
--   1. "From Company Account" → Choose account → Creates ledger entry
--   2. "Outside Cash" → No account → No ledger entry
--
-- Loan Cancellation:
--   - If disbursement_account_id IS NOT NULL:
--     → Refund outstanding_balance to that account
--     → Create reversal ledger entry
--   - If disbursement_account_id IS NULL:
--     → No financial action needed (was outside cash)
--
-- =====================================================================
