-- ============================================================================
-- Start every custom employee's running balance automatically — August 2026
--
-- Saves opening each butcher's khata by hand. For every custom-schedule employee
-- who is not on a running balance yet, this sets the anchor to THE DAY AFTER the
-- last day they have already been paid for:
--
--     last paid period 7–10 Aug  ->  balance starts 11 Aug
--
-- "Already paid for" means both kinds of row: a custom period (its period_end)
-- and a whole monthly salary (that month's last day). This is the same rule the
-- Start-a-running-balance screen applies, so the dates come out identical to
-- doing it by hand — no day can be both period-paid and balance-accrued.
--
-- ⚠ PREREQUISITE: payroll_balance_tracking_aug2026.sql must be applied FIRST.
--
-- Run once on LOCAL, then on PROD (manual). IDEMPOTENT — it only touches
-- employees whose balance is still NULL, so a re-run changes nothing, and anyone
-- already started by hand is left exactly as they are.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 0 — DRY RUN. Run this on its own FIRST and read the output. It changes
-- nothing and shows exactly what steps 1–3 will do, per employee.
-- ─────────────────────────────────────────────────────────────────────────────
SELECT
    u.fullname                                   AS employee,
    p.base_salary                                AS rate,
    p.rate_type                                  AS rate_per,
    MAX(x.covered_end)                           AS paid_up_to,
    DATE_ADD(MAX(x.covered_end), INTERVAL 1 DAY) AS balance_starts,
    COALESCE(a.adv_total, 0)                     AS opening_paid_ahead,
    COALESCE(a.adv_count, 0)                     AS advances_closed_off,
    CASE WHEN DATE_ADD(MAX(x.covered_end), INTERVAL 1 DAY) > CURDATE()
         THEN 'SKIPPED - paid up to today already'
         ELSE 'will start' END                   AS action
FROM t_hr_employee_profile p
JOIN t_sys_user u ON u.id = p.user_id
JOIN (
    SELECT user_id,
           CASE WHEN period_key <> '' AND period_end IS NOT NULL
                THEN period_end
                ELSE LAST_DAY(STR_TO_DATE(CONCAT(pay_month, '-01'), '%Y-%m-%d'))
           END AS covered_end
    FROM t_hr_payroll_payment
    WHERE status = 'paid' AND entry_kind <> 'balance_payment'
) x ON x.user_id = p.user_id
LEFT JOIN (
    SELECT r.requester_user_id AS user_id, SUM(r.amount) AS adv_total, COUNT(*) AS adv_count
    FROM t_req_master r
    JOIN t_req_category c ON c.id = r.category_id
    WHERE c.category_code = 'salary_advance'
      AND r.status = 'approved'
      AND (r.settlement_status IS NULL OR r.settlement_status <> 'settled')
    GROUP BY r.requester_user_id
) a ON a.user_id = p.user_id
WHERE p.pay_schedule = 'custom'
  AND p.balance_track_start IS NULL
  AND p.base_salary > 0
GROUP BY u.fullname, p.base_salary, p.rate_type, a.adv_total, a.adv_count
ORDER BY u.fullname;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 1 — set the anchor + the opening balance.
--
-- OPENING BALANCE = 0. Every khata starts CLEAN on its anchor date (owner ruling
-- Aug-16): the past is closed, and any advance the employee is still holding is
-- written off rather than recovered from the days he is about to work.
--
--   ⇄ TO CARRY THE ADVANCES INSTEAD (so the money he holds counts against his
--     coming days), change the marked line to:
--         p.balance_opening = COALESCE(a.adv_total, 0),
--     STEP 3's note adjusts itself to match — no other edit needed.
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE t_hr_employee_profile p
JOIN (
    SELECT user_id, DATE_ADD(MAX(covered_end), INTERVAL 1 DAY) AS start_date
    FROM (
        SELECT user_id,
               CASE WHEN period_key <> '' AND period_end IS NOT NULL
                    THEN period_end
                    ELSE LAST_DAY(STR_TO_DATE(CONCAT(pay_month, '-01'), '%Y-%m-%d'))
               END AS covered_end
        FROM t_hr_payroll_payment
        WHERE status = 'paid' AND entry_kind <> 'balance_payment'
    ) y
    GROUP BY user_id
    HAVING DATE_ADD(MAX(covered_end), INTERVAL 1 DAY) <= CURDATE()
) d ON d.user_id = p.user_id
LEFT JOIN (
    SELECT r.requester_user_id AS user_id, SUM(r.amount) AS adv_total
    FROM t_req_master r
    JOIN t_req_category c ON c.id = r.category_id
    WHERE c.category_code = 'salary_advance'
      AND r.status = 'approved'
      AND (r.settlement_status IS NULL OR r.settlement_status <> 'settled')
    GROUP BY r.requester_user_id
) a ON a.user_id = p.user_id
SET p.balance_track_start = d.start_date,
    p.balance_opening     = 0,                          -- <<< marked line (see above)
    p.updated_at          = NOW()
WHERE p.pay_schedule = 'custom'
  AND p.balance_track_start IS NULL
  AND p.base_salary > 0;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 2 — write each employee's first rate segment, effective from the anchor.
--
-- ⚠ NOT OPTIONAL. Rates are dated so that a future rate change only affects days
-- from the date it applies. Without this opening segment, a later rate change
-- would be treated as the ONLY known rate and would re-price every earlier day.
-- A monthly rate is stored already divided by 30 (the same convention the
-- date-range periods use). Safe to re-run: it skips anyone who already has one.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO t_hr_custom_rate
    (user_id, effective_date, day_rate, base_amount, rate_type, created_by, created_at, updated_at)
SELECT p.user_id,
       p.balance_track_start,
       CASE WHEN p.rate_type = 'daily' THEN p.base_salary ELSE ROUND(p.base_salary / 30, 4) END,
       p.base_salary,
       COALESCE(NULLIF(p.rate_type, ''), 'monthly'),
       NULL,
       NOW(), NOW()
FROM t_hr_employee_profile p
WHERE p.pay_schedule = 'custom'
  AND p.balance_track_start IS NOT NULL
  AND p.base_salary > 0
  AND NOT EXISTS (
      SELECT 1 FROM t_hr_custom_rate r
      WHERE r.user_id = p.user_id AND r.effective_date = p.balance_track_start
  );


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 3 — close off the old advances. Run this whichever way step 1 was set.
--
-- Advances stop existing for anyone on a running balance, so they must not stay
-- open: with a zero opening they are written off, and with a carried opening they
-- are already part of the balance. Either way they must never ALSO be recovered
-- from a future payment. The note below tells whichever truth applies.
--
-- The ledger rows are NOT touched: the cash-out history stays exactly where it is.
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE t_req_master r
JOIN t_req_category c ON c.id = r.category_id
JOIN t_hr_employee_profile p ON p.user_id = r.requester_user_id
SET r.settlement_status = 'settled',
    r.settled_at        = NOW(),
    r.settlement_notes  = CONCAT(
        CASE WHEN p.balance_opening > 0
             THEN 'Carried into the running balance started '
             ELSE 'Written off - running balance started clean on ' END,
        DATE_FORMAT(p.balance_track_start, '%e %b %Y')),
    r.updated_at        = NOW()
WHERE c.category_code = 'salary_advance'
  AND r.status = 'approved'
  AND (r.settlement_status IS NULL OR r.settlement_status <> 'settled')
  AND p.pay_schedule = 'custom'
  AND p.balance_track_start IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- STEP 4 — verify. Expect one row per custom employee, each with a start date,
-- a rate segment on that date, and no open advances left.
-- ─────────────────────────────────────────────────────────────────────────────
SELECT u.fullname                AS employee,
       p.balance_track_start     AS balance_starts,
       p.balance_opening         AS opening_paid_ahead,
       (SELECT COUNT(*) FROM t_hr_custom_rate r
         WHERE r.user_id = p.user_id AND r.effective_date = p.balance_track_start) AS rate_segment,
       (SELECT COALESCE(SUM(r2.amount), 0) FROM t_req_master r2
          JOIN t_req_category c2 ON c2.id = r2.category_id
         WHERE c2.category_code = 'salary_advance' AND r2.requester_user_id = p.user_id
           AND r2.status = 'approved'
           AND (r2.settlement_status IS NULL OR r2.settlement_status <> 'settled')) AS advances_still_open
FROM t_hr_employee_profile p
JOIN t_sys_user u ON u.id = p.user_id
WHERE p.pay_schedule = 'custom'
ORDER BY u.fullname;

-- Anyone left un-started (no payment history at all, or no rate set) — start
-- these from the screen, where you can choose the date yourself:
--   SELECT u.fullname, p.base_salary FROM t_hr_employee_profile p
--     JOIN t_sys_user u ON u.id = p.user_id
--    WHERE p.pay_schedule = 'custom' AND p.balance_track_start IS NULL;
