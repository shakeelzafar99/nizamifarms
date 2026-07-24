-- ============================================================================
-- Payroll — Custom-schedule employees + Business-Unit tagging (July 2026)
--
-- Adds:
--   1) pay_schedule / rate_type / business_unit_id to the EMPLOYEE profile
--      (who is paid by custom date ranges, at a daily or monthly rate, and
--       which business unit their salary expense belongs to).
--   2) period_start / period_end / period_key / business_unit_id to the
--      PAYMENT table, and widens the double-pay uniqueness so a custom row
--      (with a period) coexists with the monthly row for the same month.
--
-- ⚠ PREREQUISITE: run attendance_revamp_phaseG_jul2026.sql FIRST — it creates
--    t_hr_payroll_payment. This file only ALTERs existing tables.
--
-- Run once on LOCAL, then on PROD (manual). NOT idempotent (plain ALTERs) — a
-- re-run errors harmlessly on "duplicate column"/"duplicate key". All new code
-- paths are Schema::hasColumn-guarded, so the web upload is safe whether it
-- lands before or after this SQL (before it: Payroll behaves exactly as today).
-- ============================================================================

-- 1) Employee profile: pay schedule + rate type + business unit tag
ALTER TABLE `t_hr_employee_profile`
  ADD COLUMN `pay_schedule`     VARCHAR(10)     NOT NULL DEFAULT 'monthly' COMMENT 'monthly | custom',
  ADD COLUMN `rate_type`        VARCHAR(10)     NOT NULL DEFAULT 'monthly' COMMENT 'custom employee: monthly | daily (base_salary is the rate)',
  ADD COLUMN `business_unit_id` BIGINT UNSIGNED NULL COMMENT 't_fin_business_units.id; NULL = NF (Nizami Farms)';

-- 2) Payment table: period columns + BU stamp + widened uniqueness
ALTER TABLE `t_hr_payroll_payment`
  ADD COLUMN `period_start`     DATE NULL COMMENT 'custom period first day; NULL for monthly rows',
  ADD COLUMN `period_end`       DATE NULL COMMENT 'custom period last day; NULL for monthly rows',
  ADD COLUMN `period_key`       VARCHAR(25) NOT NULL DEFAULT '' COMMENT 'custom: YYYY-MM-DD_YYYY-MM-DD; monthly: empty string',
  ADD COLUMN `business_unit_id` BIGINT UNSIGNED NULL COMMENT 'BU stamped at pay time (frozen)';

-- Swap the double-pay guard. Monthly rows keep period_key='' so
-- (user_id, pay_month, '') is byte-equivalent to the old (user_id, pay_month)
-- guard — monthly double-pay is still impossible. Custom rows differ by
-- period_key, so a custom period and a monthly month can coexist, while an
-- exact-duplicate custom range is still blocked.
ALTER TABLE `t_hr_payroll_payment` DROP KEY `uniq_user_month`;
ALTER TABLE `t_hr_payroll_payment`
  ADD UNIQUE KEY `uniq_user_month_period` (`user_id`, `pay_month`, `period_key`);
