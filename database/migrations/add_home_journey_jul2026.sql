-- ============================================================================
-- Company-bike "going home" journey (U4) — portable, hand-run on local + prod.
-- Adds: a per-rider HOME pin (Riders page) + per-attendance journey/meter columns.
-- All additive + nullable → safe to run any time; code is guarded (Schema::hasColumn)
-- so it works before this runs (the journey simply never arms).
-- "Duplicate column" on re-run = already applied (ignore).
-- ============================================================================

-- 1) Rider HOME location (set on the Riders page; paste a Google Maps link or coords).
ALTER TABLE t_ops_rider_profile
  ADD COLUMN home_latitude  DECIMAL(10,7) NULL,
  ADD COLUMN home_longitude DECIMAL(10,7) NULL,
  ADD COLUMN home_radius_m  INT(11) NULL,
  ADD COLUMN home_set_by    INT(11) NULL,
  ADD COLUMN home_set_at    DATETIME NULL;

-- 2) Per-day home journey on the attendance row (one journey per day).
ALTER TABLE t_ops_attendance
  ADD COLUMN home_eta_min           INT(11) NULL,
  ADD COLUMN home_distance_km       DECIMAL(6,2) NULL,
  ADD COLUMN home_expected_by       DATETIME NULL,
  ADD COLUMN home_arrived_at        DATETIME NULL,
  ADD COLUMN home_arrival_source    VARCHAR(12) NULL,   -- geofence | manual | manager
  ADD COLUMN meter_home             INT(11) NULL,
  ADD COLUMN picture_home           VARCHAR(255) NULL,
  ADD COLUMN home_meter_unlock_until DATETIME NULL,     -- manager bypass window end
  ADD COLUMN home_meter_unlock_by   INT(11) NULL,
  ADD COLUMN home_late_reason       VARCHAR(200) NULL;

-- 3) Config defaults (read like the other attendance keys; auto-created on first save,
--    but seed them so the settings modal shows sensible values).
INSERT INTO t_fin_config (config_key, config_value) VALUES
  ('HOME_ETA_BUFFER_MIN',   '15'),
  ('HOME_LATE_UNLOCK_MINS', '10'),
  ('HOME_RADIUS_M',         '150')
  ON DUPLICATE KEY UPDATE config_key = config_key;
