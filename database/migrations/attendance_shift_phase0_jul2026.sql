-- ============================================================================
-- Attendance & Shift Revamp — PHASE 0 (foundations)
-- ----------------------------------------------------------------------------
-- Run order: LOCAL first -> verify app still works -> then PROD.
-- Deploy the Phase-0 CODE files in the SAME release, then hit /api/public/xclean.
-- Portable MySQL. Section 4 is safe to re-run; sections 1 & 3 are one-time
-- (ADD COLUMN / DROP INDEX will error if already applied — that's expected,
-- it just means this script already ran).
--
-- What this enables:
--   * Shift assignments stop being deleted on change -> history is kept.
--   * Every check-in freezes that day's expected shift + late minutes onto the
--     attendance row, so changing a shift later can never rewrite the past.
--   * Salary settings keys exist (grace pool + overtime toggle), read in Phase 2.
-- Nothing here changes what any screen shows today; it only starts recording.
-- ============================================================================


-- ── 1. Keep shift-assignment HISTORY ────────────────────────────────────────
-- Drop the unique index that blocks re-assigning A -> B -> back to A.
-- (Verified index name on this DB: unique_user_shift on (user_id, shift_template_id).)
ALTER TABLE t_ops_user_shift_assignment DROP INDEX unique_user_shift;

-- Fast per-user / per-date resolution lookups now that a user can have many rows.
ALTER TABLE t_ops_user_shift_assignment
  ADD INDEX idx_usa_user_from (user_id, effective_from);

-- Rider acknowledgement tracking (rider taps "acknowledge" in the app — wired Phase 3).
ALTER TABLE t_ops_user_shift_assignment
  ADD COLUMN notified_at     DATETIME NULL AFTER updated_by,
  ADD COLUMN acknowledged_at DATETIME NULL AFTER notified_at;


-- ── 2. FREEZE existing history (owner decision: old records stay put) ────────
-- Make every CURRENT assignment "effective since forever" so that past dates
-- resolve to today's shift EXACTLY as the reports show now — and never move
-- again. Runs once. After this, each new change creates a dated row and the old
-- row is closed (not deleted), so real history accumulates from here on.
UPDATE t_ops_user_shift_assignment
   SET effective_from = NULL
 WHERE effective_to IS NULL;


-- ── 3. Attendance SNAPSHOT columns (frozen at check-in / check-out) ──────────
-- NULL on all historical rows; filled going forward by the app. Reports (Phase 1)
-- will PREFER these frozen values over recomputing against the current shift.
ALTER TABLE t_ops_attendance
  ADD COLUMN expected_shift_start TIME NULL AFTER logout_time,
  ADD COLUMN expected_shift_end   TIME NULL AFTER expected_shift_start,
  ADD COLUMN shift_template_id    INT  NULL AFTER expected_shift_end,
  ADD COLUMN late_minutes         INT  NULL AFTER shift_template_id,
  ADD COLUMN overtime_minutes     INT  NULL AFTER late_minutes;


-- ── 4. Salary settings (read in Phase 2; seeded here so the keys exist) ──────
-- config_key is UNIQUE on t_fin_config, so this is safe to re-run.
INSERT INTO t_fin_config (config_key, config_value, description, created_at)
VALUES
  ('salary_monthly_grace_minutes', '0', 'Attendance: free late minutes allowed per month before any deduction', NOW()),
  ('salary_overtime_enabled',      '0', 'Attendance: 1 = pay overtime in salary, 0 = ignore overtime', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;  -- no-op if the key already exists

-- Done. Verify: SELECT * FROM t_ops_user_shift_assignment LIMIT 5;  (effective_from NULL on current rows)
--              SHOW COLUMNS FROM t_ops_attendance LIKE 'expected_shift_start';
