-- ============================================================================
-- DIAGNOSTIC ONLY — safe to run on prod any time (SELECT only, changes nothing)
-- Jul 2026 — "Balance right now" vs statement running-balance drift
-- ============================================================================
-- For every company account:
--   stored   = t_fin_accounts.current_balance (what the header shows)
--   computed = opening + net of rows stamped balance_updated = 1
--              (what the Hub's running column counts)
--   unflagged_applied_net = net of approved/pending_l2 rows with
--              balance_updated = 0 — money the OLD code applied inline
--              without stamping the flag
--
-- READING THE RESULT:
--   drift = stored − computed.
--   Where drift == unflagged_applied_net (to the paisa), the gap is FULLY
--   explained by legacy unstamped rows → stamping them (backfill) is provably
--   safe and makes header == column exactly.
--   Where they differ, that account has deeper residue (deleted rows, pre-flag
--   history) and needs a re-baseline, not a blind backfill.
--
-- Dev replica results (2026-07-31): NF_CASH drift 17,200.68 == unflagged net
-- EXACTLY; same for NF food (288,500.00), khaas taimur (744,434.00),
-- QURBANI_CASH (607,300.00). ONLINE / EXP_FUND / QURBANI_ONLINE carry extra
-- residue beyond the unflagged rows.
-- ============================================================================

SELECT a.account_code,
       a.account_name,
       a.current_balance                                                   AS stored,
       ROUND(a.opening_balance
         + COALESCE(SUM(CASE WHEN l.balance_updated = 1 AND l.to_account_id   = a.id THEN  l.amount
                             WHEN l.balance_updated = 1 AND l.from_account_id = a.id THEN -l.amount
                             ELSE 0 END), 0), 2)                            AS computed,
       ROUND(a.current_balance - (a.opening_balance
         + COALESCE(SUM(CASE WHEN l.balance_updated = 1 AND l.to_account_id   = a.id THEN  l.amount
                             WHEN l.balance_updated = 1 AND l.from_account_id = a.id THEN -l.amount
                             ELSE 0 END), 0)), 2)                           AS drift,
       ROUND(COALESCE(SUM(CASE WHEN l.balance_updated = 0
                                AND l.approval_status IN ('approved','pending_l2')
                                AND l.to_account_id   = a.id THEN  l.amount
                               WHEN l.balance_updated = 0
                                AND l.approval_status IN ('approved','pending_l2')
                                AND l.from_account_id = a.id THEN -l.amount
                               ELSE 0 END), 0), 2)                          AS unflagged_applied_net
FROM t_fin_accounts a
LEFT JOIN t_fin_ledger l
       ON (l.to_account_id = a.id OR l.from_account_id = a.id)
WHERE a.account_category IN ('cash','bank')
  AND a.is_active = 1
GROUP BY a.id, a.account_code, a.account_name, a.current_balance, a.opening_balance
ORDER BY ABS(a.current_balance) DESC;

-- Optional drill-down: WHICH rows are unstamped-but-applied for one account
-- (replace 1 with the account id from t_fin_accounts):
-- SELECT id, transaction_type, approval_status, transaction_date, created_at,
--        CASE WHEN to_account_id = 1 THEN amount ELSE -amount END AS signed_amount,
--        description
-- FROM t_fin_ledger
-- WHERE (to_account_id = 1 OR from_account_id = 1)
--   AND approval_status IN ('approved','pending_l2') AND balance_updated = 0
-- ORDER BY created_at;
