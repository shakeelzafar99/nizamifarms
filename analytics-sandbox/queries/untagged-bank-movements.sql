-- ============================================================================
-- Untagged bank movements — "where has the per-bank split already drifted?"
-- Aug-27-2026.  SELECT ONLY.  Safe to run on production.
-- ============================================================================
--
-- WHY THIS EXISTS
-- "Online Bank" is a SINGLE chart account (account_category = 'bank'). WHICH
-- physical bank the money moved through lives on the ledger row as
-- receiving_account_id. BankBalanceService computes a bank's balance as:
--
--     opening + SUM(tagged, balance-applied rows, signed by which side the
--                   bank-category account sits on)
--
-- so a row that moves money on a bank account WITHOUT that tag is invisible to
-- the per-bank balances. The ONLINE account total stays correct; the split
-- across HBL / Meezan / … is short by exactly that amount, and nothing later
-- goes back and guesses.
--
-- Several surfaces used to book money from "Online Bank" with no bank field at
-- all (Daily Closing approve on web AND mobile, the store salary advance, the
-- loan disbursement). Those are fixed going forward — this query shows what is
-- ALREADY on the books, so the remaining gap can be closed deliberately with a
-- per-bank adjustment on the Bank Balances page (⚖ Adjust) rather than guessed.
--
-- THE PREDICATE IS BankBalanceService's OWN, INVERTED:
--   * status must be one that has actually applied a balance
--     ('approved' or 'pending_l2' — balance applies at L1, L2 is verification)
--   * exactly ONE side is a bank-category account. A row with a bank account on
--     BOTH sides (e.g. ONLINE -> QURBANI_ONLINE) is the same physical money and
--     nets to zero there, so it needs no tag and must NOT be listed here.
--
-- READ THE RESULT AS: for each transaction_type, how many rows and what NET
-- rupee amount the per-bank balances are missing (+ = money that arrived in a
-- bank but was never credited to one; − = money that left).
-- ============================================================================

-- ── 1. Summary by transaction type (start here) ──────────────────────────────
SELECT
    l.transaction_type,
    COUNT(*)                                                     AS untagged_rows,
    SUM(CASE WHEN l.to_account_id IN (SELECT id FROM t_fin_accounts WHERE account_category = 'bank')
             THEN l.amount ELSE -l.amount END)                    AS net_missing_from_split,
    MIN(l.transaction_date)                                      AS first_seen,
    MAX(l.transaction_date)                                      AS last_seen
FROM t_fin_ledger l
WHERE l.receiving_account_id IS NULL
  AND l.approval_status IN ('approved', 'pending_l2')
  AND (
        (l.to_account_id   IN (SELECT id FROM t_fin_accounts WHERE account_category = 'bank'))
      + (l.from_account_id IN (SELECT id FROM t_fin_accounts WHERE account_category = 'bank'))
      ) = 1                          -- exactly one side, never an internal bank↔bank move
GROUP BY l.transaction_type
ORDER BY ABS(SUM(CASE WHEN l.to_account_id IN (SELECT id FROM t_fin_accounts WHERE account_category = 'bank')
                      THEN l.amount ELSE -l.amount END)) DESC;


-- ── 2. The individual rows, newest first (drill into a type from #1) ─────────
-- Add:  AND l.transaction_type = 'salary_advance'   to isolate one.
SELECT
    l.id                AS ledger_id,
    l.transaction_date,
    l.transaction_type,
    l.amount,
    CASE WHEN l.to_account_id IN (SELECT id FROM t_fin_accounts WHERE account_category = 'bank')
         THEN 'IN  (money arrived in a bank)'
         ELSE 'OUT (money left a bank)' END          AS direction,
    af.account_name     AS from_account,
    at.account_name     AS to_account,
    l.request_id,
    l.order_id,
    l.external_source,
    l.description
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts af ON af.id = l.from_account_id
LEFT JOIN t_fin_accounts at ON at.id = l.to_account_id
WHERE l.receiving_account_id IS NULL
  AND l.approval_status IN ('approved', 'pending_l2')
  AND (
        (l.to_account_id   IN (SELECT id FROM t_fin_accounts WHERE account_category = 'bank'))
      + (l.from_account_id IN (SELECT id FROM t_fin_accounts WHERE account_category = 'bank'))
      ) = 1
ORDER BY l.transaction_date DESC, l.id DESC
LIMIT 500;


-- ── 3. Sanity check: which chart accounts count as "bank" at all? ────────────
-- If this returns something unexpected (a cash account mis-categorised, or a
-- real bank account NOT categorised 'bank'), fix that FIRST — every number
-- above, and every per-bank balance, is derived from this list.
SELECT id, account_code, account_name, account_category, business_unit_id, is_active
FROM t_fin_accounts
WHERE account_category = 'bank'
ORDER BY id;
