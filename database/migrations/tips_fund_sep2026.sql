-- =====================================================================
--  TIPS FUND — Sep-2026
--  Run this BEFORE uploading the PHP. Two INSERTs, no ALTER, no new table.
--
--  What it is
--  ----------
--  Tips are inside the invoice (total = subtotal - discount + shipping +
--  tip), so every profit figure has been counting them as our income. They
--  are not ours: they are money we hold until it is paid out. From the date
--  below, each tipped invoice posts a companion ledger row that moves the tip
--  out of Sales Revenue and into this liability account, and a payout moves
--  it back out of a real cash/bank account.
--
--  Fund balance = opening + collected - paid out.
--
--  ⚠ Deliveries BEFORE the date below are untouched. No month that has
--    already been read and discussed is allowed to move.
--
--  ⚠⚠ The account_category is 'tips_fund' ON PURPOSE and must not be changed
--     to cash/bank "to make it show up". Bank pools, the rebalance tool, the
--     payment-source picker, employee-cash formulas and daily closing all
--     select accounts by category cash / bank / employee_cash. A brand-new
--     category is invisible to all of them by construction, which is what
--     stops tip money leaking into a balance it does not belong in. The
--     screens that SHOULD show it name the category explicitly.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. The liability account.
--
--    The ledger engine (BalancePostingService) uses credit arithmetic for
--    liability accounts — TO -amount, FROM +amount — so:
--
--      tip_collected (from = TIPS_FUND,        to = Sales Revenue)
--          -> fund +tip, revenue -tip     (revenue re-labelled as money owed)
--      tip_payout    (from = cash/bank,        to = TIPS_FUND)
--          -> cash -amount, fund -amount  (we hand the money over)
--      opening_balance (from = EQUITY_OPENING, to = TIPS_FUND)
--          -> fund +amount                (same shape a vendor opening uses)
-- ---------------------------------------------------------------------
INSERT INTO `t_fin_accounts`
  (`account_code`, `account_name`, `account_type`, `account_category`,
   `opening_balance`, `current_balance`, `is_active`, `is_private`,
   `business_unit_id`, `created_at`, `created_by`)
SELECT
  'TIPS_FUND', 'Tips Fund (held for staff)', 'liability', 'tips_fund',
  0.00, 0.00, 1, 0,
  1, NOW(), 1
WHERE NOT EXISTS (
  SELECT 1 FROM `t_fin_accounts` WHERE `account_code` = 'TIPS_FUND'
);


-- ---------------------------------------------------------------------
-- 2. The date tips stop counting as income.
--
--    Owner ruling (Sep-4-2026): start from 1 AUGUST. Tips before that are NOT
--    corrected and months before August do NOT move. August itself DOES: its
--    tips leave August profit and land in the pool (press "Collect missing
--    tips" once after deploy).
--
--    The code falls back to this same date if the row is missing, so a
--    forgotten INSERT cannot silently put tips back into profit. The row
--    exists so the date can be CHANGED, not so the rule can be switched off.
-- ---------------------------------------------------------------------
INSERT INTO `t_fin_config`
  (`config_key`, `config_value`, `description`, `created_at`)
SELECT
  'TIPS_FUND_START_DATE', '2026-08-01',
  'Deliveries on/after this date move their tip into TIPS_FUND and out of profit. Earlier months are left exactly as they were.',
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `t_fin_config` WHERE `config_key` = 'TIPS_FUND_START_DATE'
);


-- ---------------------------------------------------------------------
--  AFTER the PHP is uploaded and caches are cleared:
--    1. Taimur sets the opening balance   (Ledger Hub -> Tips -> Set opening balance)
--    2. Ledger Hub -> Tips -> "Collect missing tips"  (collects every tip on invoices
--       delivered since 1 Aug; prod has no shell for php artisan tips:backfill)
-- ---------------------------------------------------------------------


-- ---------------------------------------------------------------------
-- Verify
-- ---------------------------------------------------------------------
-- SELECT id, account_code, account_name, account_type, account_category, current_balance
--   FROM t_fin_accounts WHERE account_code = 'TIPS_FUND';
-- SELECT config_key, config_value FROM t_fin_config WHERE config_key = 'TIPS_FUND_START_DATE';
