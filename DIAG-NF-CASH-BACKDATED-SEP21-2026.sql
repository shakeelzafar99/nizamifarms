-- ============================================================================
-- DIAG — "the NF Cash (Main Till) balance moved, but I can't find it in the ledger"
-- Sep-21-2026 · SELECT-ONLY · safe to run on production.
--
-- THE ANSWER TO THE QUESTION ASKED:
--   t_fin_ledger.created_at  = when the row was really INSERTED (server clock)
--   t_fin_ledger.updated_at  = when the row was last CHANGED (ON UPDATE current_timestamp)
--   Neither is editable from any screen. `transaction_date` is the date the user
--   picks; `created_at` is the truth. Backdating = the gap between the two.
--
-- NF Cash (Main Till) = t_fin_accounts.id = 1  (account_code 'NF_CASH')
-- Window = the last 5 days. Edit the two dates below to move the window.
-- ============================================================================

SET @acct      := 1;
SET @win_start := '2026-09-16 00:00:00';
SET @win_end   := '2026-09-22 00:00:00';

-- ---------------------------------------------------------------------------
-- 0. CLOCK SANITY. Run first. On PROD mysql_now should read Pakistan time.
-- ---------------------------------------------------------------------------
SELECT '0. CLOCK' AS step;
SELECT @@session.time_zone AS session_tz, NOW() AS mysql_now, UTC_TIMESTAMP() AS utc_now;

-- ---------------------------------------------------------------------------
-- 1. DOES THE LEDGER STILL EXPLAIN THE WHOLE BALANCE?
--    "Balance right now" on the Hub is the stored column current_balance, which is
--    only ever moved by BalancePostingService, one ledger row at a time.
--    rebuilt_from_ledger = stored_balance  -> every rupee is a ledger row, nothing
--    was hand-edited in the database. A non-zero unexplained_gap is your answer.
-- ---------------------------------------------------------------------------
SELECT '1. RECONCILIATION' AS step;
SELECT a.account_code,
       a.current_balance AS stored_balance,
       a.opening_balance,
       ROUND(a.opening_balance + COALESCE(SUM(
         CASE WHEN l.transaction_type = 'vendor_purchase'
              THEN CASE WHEN l.from_account_id = a.id THEN l.amount ELSE -l.amount END
              ELSE CASE WHEN l.to_account_id   = a.id THEN l.amount ELSE -l.amount END
         END), 0), 2) AS rebuilt_from_ledger,
       ROUND(a.current_balance - (a.opening_balance + COALESCE(SUM(
         CASE WHEN l.transaction_type = 'vendor_purchase'
              THEN CASE WHEN l.from_account_id = a.id THEN l.amount ELSE -l.amount END
              ELSE CASE WHEN l.to_account_id   = a.id THEN l.amount ELSE -l.amount END
         END), 0)), 2) AS unexplained_gap,
       a.updated_at AS balance_last_touched
FROM t_fin_accounts a
LEFT JOIN t_fin_ledger l
       ON (l.from_account_id = a.id OR l.to_account_id = a.id)
      AND l.balance_updated = 1
WHERE a.id = @acct
GROUP BY a.id, a.account_code, a.current_balance, a.opening_balance, a.updated_at;

-- ---------------------------------------------------------------------------
-- 2. THE MAIN ANSWER — everything TYPED into the till inside the window,
--    no matter which date it shows in the ledger.
--    days_backdated > 0 = entered later than the date it carries.
-- ---------------------------------------------------------------------------
SELECT '2. ENTERED IN WINDOW' AS step;
SELECT l.id,
       l.created_at AS typed_at,
       l.transaction_date AS shows_as,
       DATEDIFF(DATE(l.created_at), l.transaction_date) AS days_backdated,
       l.transaction_type,
       l.amount,
       CASE WHEN l.transaction_type = 'vendor_purchase'
            THEN CASE WHEN l.from_account_id = @acct THEN l.amount ELSE -l.amount END
            ELSE CASE WHEN l.to_account_id   = @acct THEN l.amount ELSE -l.amount END
       END AS effect_on_till,
       l.balance_updated AS counted_in_balance,
       l.approval_status,
       u.fullname AS typed_by,
       l.device,
       LEFT(l.description, 70) AS description
FROM t_fin_ledger l
LEFT JOIN t_sys_user u ON u.id = l.created_by
WHERE (l.from_account_id = @acct OR l.to_account_id = @acct)
  AND l.created_at >= @win_start AND l.created_at < @win_end
ORDER BY l.created_at DESC;

-- ---------------------------------------------------------------------------
-- 3. OLD ROWS TOUCHED IN THE WINDOW — created before the window, changed inside it.
--    This is how an already-dated transaction quietly changes today's balance
--    (edited, approved, reversed, or re-dated).
-- ---------------------------------------------------------------------------
SELECT '3. OLD ROWS EDITED IN WINDOW' AS step;
SELECT l.id,
       l.created_at AS originally_typed,
       l.updated_at AS changed_at,
       l.transaction_date AS shows_as,
       l.transaction_type,
       l.amount,
       CASE WHEN l.transaction_type = 'vendor_purchase'
            THEN CASE WHEN l.from_account_id = @acct THEN l.amount ELSE -l.amount END
            ELSE CASE WHEN l.to_account_id   = @acct THEN l.amount ELSE -l.amount END
       END AS effect_on_till,
       l.balance_updated AS counted_in_balance,
       l.approval_status,
       uc.fullname AS typed_by,
       uu.fullname AS last_changed_by,
       LEFT(l.description, 70) AS description
FROM t_fin_ledger l
LEFT JOIN t_sys_user uc ON uc.id = l.created_by
LEFT JOIN t_sys_user uu ON uu.id = l.updated_by
WHERE (l.from_account_id = @acct OR l.to_account_id = @acct)
  AND l.updated_at >= @win_start AND l.updated_at < @win_end
  AND l.created_at <  @win_start
ORDER BY l.updated_at DESC;

-- ---------------------------------------------------------------------------
-- 4. THE AUDIT TRAIL — including DELETED rows, which are gone from t_fin_ledger.
--    `at` is a plain DATETIME (true Pakistan wall clock, never shifted).
-- ---------------------------------------------------------------------------
SELECT '4. AUDIT TRAIL (incl. deletes)' AS step;
SELECT g.at,
       u.fullname AS who,
       g.source,
       g.action,
       g.entity_id AS ledger_id,
       g.entity_label,
       COALESCE(l.from_account_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(g.changes, '$.from_account_id.old')) AS UNSIGNED)) AS from_acc,
       COALESCE(l.to_account_id,   CAST(JSON_UNQUOTE(JSON_EXTRACT(g.changes, '$.to_account_id.old'))   AS UNSIGNED)) AS to_acc,
       l.transaction_date AS shows_as,
       g.changes,
       g.note
FROM t_sys_audit_log g
LEFT JOIN t_sys_user u ON u.id = g.user_id
LEFT JOIN t_fin_ledger l ON l.id = g.entity_id
WHERE g.entity_type = 'ledger'
  AND g.at >= @win_start AND g.at < @win_end
  AND (l.from_account_id = @acct OR l.to_account_id = @acct
       OR JSON_EXTRACT(g.changes, '$.from_account_id.old') = @acct
       OR JSON_EXTRACT(g.changes, '$.to_account_id.old')   = @acct)
ORDER BY g.at DESC;

-- ---------------------------------------------------------------------------
-- 5. SILENT EDITS — the audit observer watches only amount / from / to /
--    approval_status / mode / settlement_status. It does NOT watch
--    transaction_date, so "someone changed the date" writes NO audit row.
--    These rows moved their updated_at with nothing in the audit log to explain
--    it: the date, description, comments or reference was edited. Check by hand.
-- ---------------------------------------------------------------------------
SELECT '5. SILENT EDITS (a date change leaves no audit row)' AS step;
SELECT l.id,
       l.created_at AS originally_typed,
       l.updated_at AS silently_changed_at,
       l.transaction_date AS shows_as_now,
       l.transaction_type,
       l.amount,
       l.approval_status,
       uu.fullname AS last_changed_by,
       LEFT(l.description, 70) AS description
FROM t_fin_ledger l
LEFT JOIN t_sys_user uu ON uu.id = l.updated_by
WHERE (l.from_account_id = @acct OR l.to_account_id = @acct)
  AND l.updated_at >= @win_start AND l.updated_at < @win_end
  AND l.updated_at > DATE_ADD(l.created_at, INTERVAL 120 SECOND)
  AND NOT EXISTS (
        SELECT 1 FROM t_sys_audit_log g
         WHERE g.entity_type = 'ledger' AND g.entity_id = l.id
           AND ABS(TIMESTAMPDIFF(SECOND, g.at, l.updated_at)) <= 180
  )
ORDER BY l.updated_at DESC;

-- ---------------------------------------------------------------------------
-- 6. SUMMARY — net effect on the till per day it was TYPED (not per ledger date).
-- ---------------------------------------------------------------------------
SELECT '6. NET EFFECT BY DAY TYPED' AS step;
SELECT DATE(l.created_at) AS typed_on,
       COUNT(*) AS rows_,
       SUM(DATE(l.created_at) <> l.transaction_date) AS not_todays_date,
       MAX(DATEDIFF(DATE(l.created_at), l.transaction_date)) AS worst_backdate_days,
       ROUND(SUM(CASE WHEN l.balance_updated = 1 THEN
         CASE WHEN l.transaction_type = 'vendor_purchase'
              THEN CASE WHEN l.from_account_id = @acct THEN l.amount ELSE -l.amount END
              ELSE CASE WHEN l.to_account_id   = @acct THEN l.amount ELSE -l.amount END
         END ELSE 0 END), 2) AS net_effect_on_till
FROM t_fin_ledger l
WHERE (l.from_account_id = @acct OR l.to_account_id = @acct)
  AND l.created_at >= @win_start AND l.created_at < @win_end
GROUP BY DATE(l.created_at)
ORDER BY typed_on;

-- ============================================================================
-- RUNNING THIS ON THE DEV REPLICA INSTEAD OF PROD:
--   The replica renders TIMESTAMP columns (created_at / updated_at) 2h ahead of
--   true Pakistan time, while DATETIME columns (audit_log.at) are literal.
--   Add this line at the top so both agree and read as real PKT:
--       SET SESSION time_zone = '+01:00';
--   Do NOT add it on prod — prod already runs on one clock.
-- ============================================================================
