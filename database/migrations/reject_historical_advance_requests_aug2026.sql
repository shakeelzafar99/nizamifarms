-- ============================================================================
-- Retire the HISTORICAL salary-advance requests (August 2026)
--
-- WHAT THESE ARE: salary-advance requests that were raised (some by employees on
-- mobile, some entered by a manager) and never fully approved. 22 of 24 are stuck
-- at Level-2. Verified 2026-08-02: EVERY ONE has ledger_transaction_id IS NULL —
-- i.e. NO money ever left a company account through the system for them. In the
-- old way of working the cash was handed over directly (usually logged separately
-- as a "Staff Salaries" expense) and the request paperwork was simply abandoned.
--
-- WHY REJECT AND NOT APPROVE: approving one now would (a) post a REAL outflow from
-- NF Cash / the bank for money that was already handed over months ago, and
-- (b) queue it to be deducted from that person's next salary — paying twice and
-- then clawing back once. Rejecting moves NO money at all; it only closes the
-- request so the new Payroll "Advance requests" card shows genuinely new asks.
--
-- SAFETY: the WHERE clause is deliberately narrow —
--    category  = salary_advance
--    status    = 'pending'          (never touches approved/settled advances)
--    ledger_transaction_id IS NULL  (never touches anything that moved money)
-- Rows that fail ANY of those are left alone. Idempotent: a second run matches
-- nothing because the rows are no longer 'pending'.
--
-- Run once on LOCAL, then on PROD (manual). Ships with the payroll module.
-- ============================================================================

-- ── 0. PRE-FLIGHT: look before you leap. Expect ~24 rows, all with no ledger. ──
SELECT COUNT(*)                      AS will_be_rejected,
       SUM(amount)                   AS total_amount,
       SUM(ledger_transaction_id IS NOT NULL) AS with_ledger_MUST_BE_ZERO,
       MIN(DATE(created_at))         AS oldest,
       MAX(DATE(created_at))         AS newest
FROM t_req_master
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'salary_advance')
  AND status = 'pending'
  AND ledger_transaction_id IS NULL;

-- ⚠ If `with_ledger_MUST_BE_ZERO` is not 0, STOP and investigate before running §1.

-- ── 1. Close them as historical ───────────────────────────────────────────────
UPDATE t_req_master
SET status            = 'rejected',
    level_1_status    = 'rejected',
    level_2_status    = 'rejected',
    settlement_status = 'not_required',
    rejection_reason  = 'Historical backlog closed 2026-08-02 — advance was settled outside the system; never posted to the ledger.',
    completed_at      = NOW(),
    updated_at        = NOW()
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'salary_advance')
  AND status = 'pending'
  AND ledger_transaction_id IS NULL;

-- ── 2. VERIFY: expect 0 pending left, and the approved/settled counts UNCHANGED ─
SELECT status,
       COALESCE(settlement_status, '(null)') AS settlement,
       COUNT(*) AS n,
       SUM(amount) AS total
FROM t_req_master
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'salary_advance')
GROUP BY status, settlement
ORDER BY status;
-- Expected after the run: status='pending' has NO rows; 'approved'/'settled' rows
-- are exactly as they were before (this script cannot touch them).
