-- ==========================================================================
-- Attendance revamp — Phase E schema  (run once on LOCAL, then PROD)
-- Owner-run, manual. Portable plain SQL. Safe to re-run (IF NOT EXISTS).
-- ==========================================================================

-- Leave ledger: every ADJUSTMENT to a rider's leave balance (on top of the
-- yearly quota). One row = one +/- change, with who/why.
--   source = 'manual'       manager granted extra leaves (+days)
--   source = 'overtime'     earned from overtime hours (+days)          [Phase F]
--   source = 'late_penalty' cumulative lateness deduction (-days)        [Phase F]
--   source = 'half_day'     manager gave a half-day for a late day (-0.5)[Phase F]
-- Built SIGNED (days can be negative) with the full source list from day one
-- so Phase F needs no schema change — it just writes more rows here.
--
-- MySQL note: `IF NOT EXISTS` on CREATE TABLE is standard. The ENUM already
-- lists every source; no ALTER needed later.
CREATE TABLE IF NOT EXISTS t_hr_leave_grant (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  days       DECIMAL(5,1) NOT NULL,                       -- signed: +grant / -penalty
  reason     VARCHAR(200) NULL,
  source     ENUM('manual','overtime','late_penalty','half_day') NOT NULL DEFAULT 'manual',
  effective_date DATE NULL,                               -- which cycle it belongs to; NULL = created_at date
  created_by INT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  INDEX idx_leave_grant_user (user_id),
  INDEX idx_leave_grant_eff (effective_date)
);

-- "Not needed" day tags: a manager marks a rider as not required on a given day.
-- That day is PAID AS PRESENT — it stays a working day (per-day pay unchanged) but is
-- NOT counted as absent, and the attendance shows "Not needed" instead of a red Absent.
-- One row per (rider, day); UNIQUE prevents duplicates.
CREATE TABLE IF NOT EXISTS t_ops_day_tag (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  tag_date   DATE NOT NULL,
  tag        VARCHAR(30) NOT NULL DEFAULT 'not_needed',
  note       VARCHAR(200) NULL,
  created_by INT NULL,
  created_at DATETIME NULL,
  UNIQUE KEY uq_day_tag_user_date (user_id, tag_date),
  INDEX idx_day_tag_date (tag_date)
);

-- Leave-policy settings live in t_fin_config (created on first Save in
-- Attendance → Settings → Attendance Rules). No schema needed for them:
--   LEAVE_QUOTA_TOTAL      = 10     (yearly pool per cycle)
--   LEAVE_SAMEDAY_CAP      = 4      (max same-day applications per cycle)
--   LEAVE_SAMEDAY_CUTOFF   = 10:00  (same-day applications allowed until this local time)
--   SHIFT_TARGET_HOURS     = 9      (worked hours before overtime starts counting)
-- (Documentation only — nothing to run for these.)
