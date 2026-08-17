-- ============================================================================
-- Reset every running balance to a CLEAN start — August 2026
--
-- ⚠ ONLY needed on a database where the backfill was already run with advances
-- CARRIED into the opening balance (the dev machine, 16 Aug). A database where
-- the backfill has not run yet needs nothing — that file now starts everyone at
-- zero by default.
--
-- Owner ruling Aug-16: a khata starts CLEAN. The past is closed on the anchor
-- date, and an advance the employee is still holding is written off rather than
-- recovered from the days he is about to work.
--
-- This sets every opening balance to 0 and corrects the note on the advances
-- that were closed off, so the record says what actually happened. It does NOT
-- reopen the advances (they must stay closed either way) and does NOT touch the
-- ledger — the original cash-out history is unchanged.
--
-- Anchors, calendars, crossed days and payments are all untouched.
--
-- Idempotent: a re-run finds nothing left to change.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 0 — DRY RUN. What is about to change, and what each balance becomes.
-- (`earned_since_start` assumes no days crossed out yet; crossing days reduces it.)
-- ─────────────────────────────────────────────────────────────────────────────
SELECT u.fullname                AS employee,
       p.balance_track_start     AS balance_starts,
       p.balance_opening         AS opening_now,
       0                         AS opening_after,
       CASE WHEN p.rate_type = 'daily' THEN p.base_salary ELSE ROUND(p.base_salary / 30, 2) END
                                 AS day_rate,
       (DATEDIFF(CURDATE(), p.balance_track_start) + 1)
                                 AS days_since_start,
       p.balance_opening         AS written_off
FROM t_hr_employee_profile p
JOIN t_sys_user u ON u.id = p.user_id
WHERE p.pay_schedule = 'custom'
  AND p.balance_track_start IS NOT NULL
ORDER BY u.fullname;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 1 — every khata starts clean.
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE t_hr_employee_profile p
SET p.balance_opening = 0,
    p.updated_at      = NOW()
WHERE p.pay_schedule = 'custom'
  AND p.balance_track_start IS NOT NULL
  AND p.balance_opening <> 0;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 2 — correct the note on the advances that were closed off. They said
-- "Converted to running balance", which implied the money was carried forward.
-- It was not: the balance starts clean, so the money is a write-off and the
-- record should say so.
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE t_req_master r
JOIN t_req_category c ON c.id = r.category_id
JOIN t_hr_employee_profile p ON p.user_id = r.requester_user_id
SET r.settlement_notes = CONCAT('Written off - running balance started clean on ',
                                DATE_FORMAT(p.balance_track_start, '%e %b %Y')),
    r.updated_at       = NOW()
WHERE c.category_code = 'salary_advance'
  AND r.settlement_status = 'settled'
  AND r.settlement_notes LIKE 'Converted to running balance%'
  AND p.pay_schedule = 'custom'
  AND p.balance_track_start IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 3 — verify. Every opening must be 0, and no advance may still be open.
-- ─────────────────────────────────────────────────────────────────────────────
SELECT u.fullname            AS employee,
       p.balance_track_start AS balance_starts,
       p.balance_opening     AS opening,
       (SELECT COALESCE(SUM(r2.amount), 0) FROM t_req_master r2
          JOIN t_req_category c2 ON c2.id = r2.category_id
         WHERE c2.category_code = 'salary_advance' AND r2.requester_user_id = p.user_id
           AND r2.status = 'approved'
           AND (r2.settlement_status IS NULL OR r2.settlement_status <> 'settled')) AS advances_still_open
FROM t_hr_employee_profile p
JOIN t_sys_user u ON u.id = p.user_id
WHERE p.pay_schedule = 'custom' AND p.balance_track_start IS NOT NULL
ORDER BY u.fullname;

-- The written-off advances, for the record:
--   SELECT u.fullname, r.request_number, r.amount, r.settlement_notes
--     FROM t_req_master r
--     JOIN t_req_category c ON c.id = r.category_id
--     JOIN t_sys_user u ON u.id = r.requester_user_id
--    WHERE c.category_code = 'salary_advance'
--      AND r.settlement_notes LIKE 'Written off - running balance%';
