-- ============================================================================
-- Salary advances — explicit payroll month (September 2026)
--
-- An advance is recovered from, and costed to, a PAYROLL MONTH. Until now that
-- month was implied by when the row was created, which is wrong twice:
--   * entered late  — the money really moved last month (manager types it on the
--                     1st for a transfer made in August)
--   * given forward — the money moves today but is recovered from NEXT month
--                     (this month's salary is already paid)
-- Those two dates genuinely differ, so the booking month gets its own column and
-- `expense_date` goes back to meaning only "the day the money moved" (which is
-- also what the ledger entry is dated, so it matches the bank statement).
--
--   payroll_month = 'YYYY-MM'  → the month this advance belongs to
--                 = NULL       → legacy row: fall back to month(expense_date),
--                                which reproduces today's behaviour exactly
--
-- Run once on LOCAL, then on PROD (manual). NOT idempotent — a re-run errors
-- harmlessly on "duplicate column". Every read is Schema::hasColumn-guarded, so
-- before this SQL runs the code behaves exactly as it does today.
-- ============================================================================

ALTER TABLE `t_req_master`
  ADD COLUMN `payroll_month` VARCHAR(7) NULL COMMENT 'Salary advances: YYYY-MM payroll month this is recovered from / costed to. NULL = derive from expense_date';

-- Reporting reads it per month across every employee, so index it.
CREATE INDEX `idx_req_payroll_month` ON `t_req_master` (`payroll_month`);
