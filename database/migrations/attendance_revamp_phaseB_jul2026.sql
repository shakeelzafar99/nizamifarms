-- ==========================================================================
-- Attendance revamp — Phase B schema  (run once on LOCAL, then PROD)
-- Owner-run, manual. Portable plain SQL. Safe to re-run (IF NOT EXISTS).
-- ==========================================================================

-- Per-rider overnight grace (km). For a company-bike rider, if today's start
-- meter exceeds yesterday's end meter by MORE than this, the bike was used off
-- company hours. NULL = use the global default (t_fin_config ATTENDANCE_OVERNIGHT_GRACE_KM,
-- default 30). Set per rider on the Riders page when "Company bike" is ticked.
--
-- MySQL note: `ADD COLUMN IF NOT EXISTS` needs MySQL 8.0.29+. On older MySQL,
-- drop `IF NOT EXISTS` and run the plain ADD COLUMN once.
ALTER TABLE t_ops_rider_profile
  ADD COLUMN IF NOT EXISTS overnight_grace_km INT NULL;

-- The yearly attendance cycle (for "leaves this year" / "absent this year") is
-- configured, NOT a fixed Jan–Dec calendar. Stored in t_fin_config as
-- ATTENDANCE_CYCLE_START / ATTENDANCE_CYCLE_END (Y-m-d). No schema change needed —
-- the rows are created the first time you save the cycle in Attendance → Settings.
-- (This comment is documentation only; nothing to run for the cycle.)
