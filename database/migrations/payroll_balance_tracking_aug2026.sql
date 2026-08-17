-- ============================================================================
-- Payroll — Custom-salary RUNNING BALANCE ("khata") — August 2026
--
-- For custom-schedule employees who don't use the app (the butchers): instead of
-- advances + settle-periods, the manager records PAYMENTS and crosses out days
-- the man didn't come. The balance is DERIVED, never stored:
--
--     balance = opening + payments(since start) − Σ day-rate over counted days
--
-- Positive = paid ahead, negative = still to pay. It carries across months
-- forever because the account is continuous and starts at ONE anchor date.
--
-- Adds:
--   1) balance_track_start / balance_opening on the EMPLOYEE profile
--      (non-NULL start = tracking is ON, and IS the anchor: nothing before that
--       date is ever counted, which is what makes "start fresh from the 1st" safe)
--   2) entry_kind on the PAYMENT table — '' = every existing row (monthly salary
--      or custom period), 'balance_payment' = the new khata payments
--   3) t_hr_custom_absence — the crossed-out days (NEVER attendance: this table
--      is read by payroll only and cannot affect attendance/leave anywhere)
--   4) t_hr_custom_rate — dated day-rate history, so a rate change applies from
--      the date the manager chooses and past days keep their old price
--   5) the `void_salary_payment` permission key (Taimur + Management)
--
-- ⚠ PREREQUISITES: attendance_revamp_phaseG_jul2026.sql (creates
--    t_hr_payroll_payment) and payroll_custom_schedule_bu_jul2026.sql (adds the
--    period columns + pay_schedule). This file only ALTERs / CREATEs on top.
--
-- Run once on LOCAL, then on PROD (manual). The ALTERs are plain (a re-run errors
-- harmlessly on "duplicate column"); the CREATEs and the INSERT are idempotent.
-- Every new code path is Schema-guarded, so the web upload is safe whether it
-- lands before or after this SQL — before it, Payroll behaves exactly as today.
--
-- ⚠ REGULAR (monthly) PAYROLL IS UNAFFECTED: monthly rows keep entry_kind='',
--    the two profile columns stay NULL/0 for everyone not on a khata, and both
--    new tables are read only for balance-tracked custom employees.
-- ============================================================================

-- 1) Employee profile: the anchor + the opening balance.
ALTER TABLE `t_hr_employee_profile`
  ADD COLUMN `balance_track_start` DATE NULL COMMENT 'Custom khata anchor - first day the running balance counts. NULL = not balance-tracked',
  ADD COLUMN `balance_opening`     DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Balance at the anchor date. Positive = employee already paid ahead, negative = we owe him';

-- 2) Payment table: tell a khata payment apart from a salary/period row.
ALTER TABLE `t_hr_payroll_payment`
  ADD COLUMN `entry_kind` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'empty = monthly salary or custom period (all existing rows). balance_payment = custom running-balance payment';

-- 3) Crossed-out days. One row per (employee, date) — toggling = insert/delete.
--    Payroll-only by design: no attendance/leave surface reads this table.
CREATE TABLE IF NOT EXISTS `t_hr_custom_absence` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `absent_date` DATE NOT NULL COMMENT 'A day that does NOT earn (crossed on the payroll calendar)',
  `marked_by`   BIGINT UNSIGNED NULL COMMENT 't_sys_user.id who crossed it',
  `created_at`  TIMESTAMP NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_date` (`user_id`, `absent_date`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Dated day-rate history. The manager picks the effective date, so days before
--    it keep the old rate and days from it price at the new one. One row is written
--    when a khata is opened, so every counted day always has a rate.
CREATE TABLE IF NOT EXISTS `t_hr_custom_rate` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `effective_date` DATE NOT NULL COMMENT 'First day this day-rate applies',
  `day_rate`       DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Resolved PER-DAY rate (a monthly rate is stored here already divided by 30)',
  `base_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'What the manager typed (per day, or per month before the /30)',
  `rate_type`      VARCHAR(10) NOT NULL DEFAULT 'daily' COMMENT 'daily | monthly — the unit base_amount was entered in',
  `created_by`     BIGINT UNSIGNED NULL,
  `created_at`     TIMESTAMP NULL DEFAULT NULL,
  `updated_at`     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_effective` (`user_id`, `effective_date`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Void a khata payment (reverses its ledger entry and drops it from every money
--    surface). Owner ruling Aug-16: Taimur AND Shabib — role 14 (Taimur) and role 10
--    (Management: Shabib + the Nizami Farms account). Role grants, so anyone later
--    assigned those roles inherits it. Deliberately separate from `manage_payroll`.
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 14, 'void_salary_payment', 'Void a custom-salary payment (returns the money & removes it from the balance)', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM t_sys_role_permissions WHERE role_id = 14 AND permission_key = 'void_salary_payment'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 10, 'void_salary_payment', 'Void a custom-salary payment (returns the money & removes it from the balance)', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM t_sys_role_permissions WHERE role_id = 10 AND permission_key = 'void_salary_payment'
);

-- Verify:
--   SHOW COLUMNS FROM t_hr_employee_profile LIKE 'balance_%';
--   SHOW COLUMNS FROM t_hr_payroll_payment LIKE 'entry_kind';
--   SELECT role_id, permission_key FROM t_sys_role_permissions WHERE permission_key = 'void_salary_payment';
--   -- must stay 0 until someone opens a khata:
--   SELECT COUNT(*) FROM t_hr_employee_profile WHERE balance_track_start IS NOT NULL;
