-- ============================================================================
-- Salary advances — carry the uncovered part to the NEXT month (September 2026)
--
-- Owner ruling (7 Sep 2026): an advance is only ever DEDUCTED from salary; the
-- employee never pays it back. When a month's salary cannot absorb the whole
-- advance, the leftover must come off the next month — not vanish. Until now a
-- request row was either fully `settled` or fully open, so the pay screen's only
-- honest choices were "write the rest off" or "don't deduct absences". Measured on
-- August 2026: Rs 156,199 would have been written off across four people.
--
--   settled_amount  how much of `amount` salaries have already deducted.
--                   0 = untouched (every existing row). Remaining = amount − this.
--   carry_month     'YYYY-MM' the still-open remainder is recovered from — stamped
--                   at pay time when a month could only absorb part of it.
--                   NULL = recovered from payroll_month as before.
--
-- Recovery month = COALESCE(carry_month, payroll_month, month(expense_date)).
-- The Expenses page keeps listing the row under payroll_month (the month the cash
-- actually left); only RECOVERY and ACCRUAL follow carry_month, so August books
-- the part August absorbed and September books the part it inherits.
--
-- Also enables the give-advance CAP: an advance may not exceed what is left of
-- that month's salary after the advances already open against it; the excess is
-- recorded against a later month.
--
-- Run once on LOCAL, then on PROD (manual). NOT idempotent — a re-run errors
-- harmlessly on "duplicate column". Every read is Schema::hasColumn-guarded, so
-- before this SQL runs the code behaves exactly as it does today (full settle,
-- and the pay dialog cannot offer "move to next month").
-- ============================================================================

ALTER TABLE `t_req_master`
  ADD COLUMN `settled_amount` DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'Salary advances: amount already deducted from salaries. Remaining = amount - settled_amount',
  ADD COLUMN `carry_month` VARCHAR(7) NULL
    COMMENT 'Salary advances: YYYY-MM the still-open remainder is recovered from (set at pay time). NULL = payroll_month';

-- Recovery is queried per month across every employee, like payroll_month.
CREATE INDEX `idx_req_carry_month` ON `t_req_master` (`carry_month`);
