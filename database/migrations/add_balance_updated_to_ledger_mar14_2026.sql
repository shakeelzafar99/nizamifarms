-- ============================================================
-- Migration: Add balance_updated flag to t_fin_ledger
-- Purpose: Track whether account balances have been applied
--          for L1 approval so L2 acts as verification only
-- Date: 2026-03-14
--
-- INSTRUCTIONS:
--   Run PART 1 (Steps 1-3) first.
--   Then run the PRE-CHECK queries and share the output.
--   Then run PART 2 (Steps 4a-4c) to backfill historical data.
--   Finally run the POST-CHECK queries to verify.
-- ============================================================


-- ===================== PART 1 =====================
-- Run these first (safe, no balance changes)
-- ==================================================

-- Step 1: Add the balance_updated column
ALTER TABLE t_fin_ledger 
ADD COLUMN balance_updated TINYINT(1) NOT NULL DEFAULT 0 
COMMENT 'Whether account balances have already been updated (1=yes, used for L1-early-balance-update flow)';

-- Step 2: Mark all fully approved records as balance_updated (they already have balances applied)
UPDATE t_fin_ledger 
SET balance_updated = 1 
WHERE approval_status = 'approved';

-- Step 3: Mark all reversed records as balance_updated (they had balances applied then reversed)
UPDATE t_fin_ledger 
SET balance_updated = 1 
WHERE approval_status = 'reversed';


-- ===================== PRE-CHECKS =====================
-- Run these AFTER Part 1, BEFORE Part 2.
-- Share the output so we can verify before proceeding.
-- ======================================================

-- CHECK 1: How many historical pending_l2 records need backfilling?
SELECT COUNT(*) AS records_count, COALESCE(SUM(amount), 0) AS total_amount
FROM t_fin_ledger
WHERE approval_status = 'pending_l2'
  AND mode = 'online'
  AND transaction_type = 'invoice'
  AND balance_updated = 0;

-- CHECK 2: What accounts are involved and what are their types?
-- (The balance direction depends on account_type)
SELECT DISTINCT 'from' AS side, acc.id, acc.account_code, acc.account_name, acc.account_type, acc.current_balance
FROM t_fin_accounts acc
INNER JOIN t_fin_ledger l ON l.from_account_id = acc.id
WHERE l.approval_status = 'pending_l2' AND l.mode = 'online' AND l.transaction_type = 'invoice' AND l.balance_updated = 0
UNION ALL
SELECT DISTINCT 'to' AS side, acc.id, acc.account_code, acc.account_name, acc.account_type, acc.current_balance
FROM t_fin_accounts acc
INNER JOIN t_fin_ledger l ON l.to_account_id = acc.id
WHERE l.approval_status = 'pending_l2' AND l.mode = 'online' AND l.transaction_type = 'invoice' AND l.balance_updated = 0;

-- CHECK 3: List all the individual records that will be backfilled (for review)
SELECT l.id, l.transaction_date, l.description, l.amount, l.mode, l.approval_status,
       fa.account_code AS from_account, fa.account_type AS from_type,
       ta.account_code AS to_account, ta.account_type AS to_type
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts fa ON l.from_account_id = fa.id
LEFT JOIN t_fin_accounts ta ON l.to_account_id = ta.id
WHERE l.approval_status = 'pending_l2'
  AND l.mode = 'online'
  AND l.transaction_type = 'invoice'
  AND l.balance_updated = 0
ORDER BY l.transaction_date DESC;


-- ===================== PART 2 =====================
-- Run these AFTER reviewing the pre-check output.
-- This applies balances for historical pending_l2 records.
-- ==================================================

-- Step 4a: Update to_account balances
-- Uses same account-type logic as LedgerController::approve():
--   Asset accounts: balance INCREASES (money coming in)
--   Non-asset accounts (revenue/liability): balance DECREASES
UPDATE t_fin_accounts acc
INNER JOIN (
    SELECT to_account_id, SUM(amount) AS total_amount
    FROM t_fin_ledger
    WHERE approval_status = 'pending_l2'
      AND mode = 'online'
      AND transaction_type = 'invoice'
      AND balance_updated = 0
    GROUP BY to_account_id
) ledger_sum ON acc.id = ledger_sum.to_account_id
SET acc.current_balance = CASE 
    WHEN acc.account_type = 'asset' THEN acc.current_balance + ledger_sum.total_amount
    ELSE acc.current_balance - ledger_sum.total_amount
END;

-- Step 4b: Update from_account balances
-- Uses same account-type logic as LedgerController::approve():
--   Asset accounts: balance DECREASES (money going out)
--   Non-asset accounts (revenue/liability): balance INCREASES
UPDATE t_fin_accounts acc
INNER JOIN (
    SELECT from_account_id, SUM(amount) AS total_amount
    FROM t_fin_ledger
    WHERE approval_status = 'pending_l2'
      AND mode = 'online'
      AND transaction_type = 'invoice'
      AND balance_updated = 0
    GROUP BY from_account_id
) ledger_sum ON acc.id = ledger_sum.from_account_id
SET acc.current_balance = CASE 
    WHEN acc.account_type = 'asset' THEN acc.current_balance - ledger_sum.total_amount
    ELSE acc.current_balance + ledger_sum.total_amount
END;

-- Step 4c: Mark these historical pending_l2 records as balance_updated
UPDATE t_fin_ledger 
SET balance_updated = 1 
WHERE approval_status = 'pending_l2'
  AND mode = 'online'
  AND transaction_type = 'invoice'
  AND balance_updated = 0;


-- ===================== POST-CHECKS =====================
-- Run these AFTER Part 2 to verify everything looks correct.
-- ========================================================

-- VERIFY 1: All pending_l2 online invoices should now have balance_updated = 1
SELECT 
    balance_updated,
    COUNT(*) AS record_count,
    SUM(amount) AS total_amount
FROM t_fin_ledger
WHERE approval_status = 'pending_l2'
  AND mode = 'online'
  AND transaction_type = 'invoice'
GROUP BY balance_updated;

-- VERIFY 2: Check the account balances after backfill
SELECT id, account_code, account_name, account_type, current_balance 
FROM t_fin_accounts 
WHERE account_code IN ('ONLINE', 'REV_SALES') 
   OR account_code LIKE '%ONLINE%' 
   OR account_code LIKE '%SALES%';

-- VERIFY 3: No remaining un-balanced pending_l2 online invoices (should return 0)
SELECT COUNT(*) AS remaining_unbalanced
FROM t_fin_ledger
WHERE approval_status = 'pending_l2'
  AND mode = 'online'
  AND transaction_type = 'invoice'
  AND balance_updated = 0;
