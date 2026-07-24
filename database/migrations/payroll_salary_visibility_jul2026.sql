-- ============================================================================
-- Payroll — separate "Show in Salary" visibility (July 2026)
--
-- Lets an employee appear on the Payroll screen WITHOUT appearing in attendance
-- tracking (and vice-versa). Adds one nullable flag to the attendance-visibility
-- table that the "👥 Customize User List" modal already manages:
--
--   show_in_payroll = NULL  → follow "Show in Attendance" (backward-compatible)
--                   = 1     → on the Payroll screen even if hidden from attendance
--                   = 0     → off the Payroll screen even if shown in attendance
--
-- Run once on LOCAL, then on PROD (manual). NOT idempotent — a re-run errors
-- harmlessly on "duplicate column". All code paths are Schema::hasColumn-guarded,
-- so before this SQL runs, Payroll keeps following attendance visibility exactly
-- as today and the modal simply doesn't show the extra column.
-- ============================================================================

ALTER TABLE `t_ops_attendance_visibility`
  ADD COLUMN `show_in_payroll` TINYINT(1) NULL COMMENT 'NULL = follow is_visible; 1 = on Payroll; 0 = off Payroll';
