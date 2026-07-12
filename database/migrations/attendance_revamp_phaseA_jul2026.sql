-- ==========================================================================
-- Attendance revamp — Phase A schema  (run once on LOCAL, then PROD)
-- Owner-run, manual. Portable plain SQL (no Laravel migration).
-- Safe to re-run: guarded so it won't error if the column already exists.
-- ==========================================================================

-- Company-bike flag on the rider profile.
-- Riders who take a company bike home get the overnight-usage meter check
-- (today's start meter vs yesterday's end meter beyond the grace = off-hours use).
-- Default 0 (personal vehicle / no overnight check). Set per rider on the Riders page.
--
-- MySQL note: `ADD COLUMN IF NOT EXISTS` needs MySQL 8.0.29+. If your MySQL is
-- older and errors, drop the `IF NOT EXISTS` and just run the plain ADD COLUMN once.
ALTER TABLE t_ops_rider_profile
  ADD COLUMN IF NOT EXISTS company_bike TINYINT(1) NOT NULL DEFAULT 0;

-- No data migration needed. hire_date already exists on this table (fill it per
-- rider on the Riders page so attendance never counts a rider absent before they
-- joined). No other tables change in Phase A.
