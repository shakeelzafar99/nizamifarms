-- ============================================================================
-- R1 — per-rider "may check in at ANY office" allowance (portable, hand-run on
-- local + prod). Additive + guarded (Schema::hasColumn) so code works before it runs.
-- "Duplicate column" on re-run = already applied (ignore).
-- ============================================================================
ALTER TABLE t_ops_rider_profile
  ADD COLUMN checkin_any_office TINYINT(1) NOT NULL DEFAULT 0;
