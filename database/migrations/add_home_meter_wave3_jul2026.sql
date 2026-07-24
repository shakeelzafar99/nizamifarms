-- ============================================================================
-- Company-bike HOME-METER flow — wave 3 (portable, hand-run on local + prod).
-- Pairs with add_home_journey_jul2026.sql + add_home_meter_wave2_jul2026.sql.
--
-- What this wave does:
--   1) Records the EXACT time the home meter was entered (so the attendance page
--      can show "due 19:03 · entered 18:53" instead of only a state chip).
--   2) Widens the home-arrival geofence to 300 m (was 150 m) so a slightly-off
--      home pin still auto-confirms arrival — the Waseem/Arslan false-"late" cause.
--
-- All additive + nullable. "Duplicate column" on re-run = already applied (ignore).
-- ============================================================================

-- 1) When the rider (or a manager, on his behalf) actually recorded the home meter.
--    NULL until the going-home meter is submitted. Distinct from home_arrived_at
--    (GPS arrival) and logout_time (checkout). Drives the attendance-page timeline.
ALTER TABLE t_ops_attendance
  ADD COLUMN home_meter_recorded_at DATETIME NULL;

-- 2) Home-arrival geofence radius → 300 m. A t_fin_config row already exists at 150,
--    so this UPDATE is what actually changes prod (the code default is only a fallback
--    for a missing row). Per-rider home_radius_m overrides on t_ops_rider_profile still
--    win where set. If the row is somehow absent, the INSERT below seeds it.
UPDATE t_fin_config SET config_value = '300' WHERE config_key = 'HOME_RADIUS_M';

INSERT INTO t_fin_config (config_key, config_value)
SELECT 'HOME_RADIUS_M', '300'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'HOME_RADIUS_M');

-- 3) VERIFY — expect 300.
SELECT config_key, config_value FROM t_fin_config WHERE config_key = 'HOME_RADIUS_M';

-- ============================================================================
-- ONE-OFF REPAIRS for riders already stuck by the legacy End-tile loophole
-- (recorded meter_end but never meter_home → journey stayed "locked" all evening).
-- Run the SELECT first to see who is affected; then the UPDATE closes each journey
-- honestly (meter_home = the reading they DID enter, arrival stamped at the moment
-- the End photo was taken so late arrivals still read as "late", not on-time).
-- NOTE: Waseem (user 73, 2026-07-22) was already repaired individually — this
-- catch-all simply won't match him again (meter_home is no longer NULL).
-- ============================================================================

-- 3a) WHO is stuck (any date):
-- SELECT id, user_id, attendance_date, meter_end, meter_home, home_expected_by, picture_end
-- FROM t_ops_attendance
-- WHERE home_expected_by IS NOT NULL AND meter_home IS NULL AND meter_end IS NOT NULL;

-- 3b) Arslan (user 77, 2026-07-22) — meter entered 20:18:45, ~23 min late (honest):
-- UPDATE t_ops_attendance
-- SET meter_home = meter_end,
--     home_arrived_at = '2026-07-22 20:18:45',
--     home_arrival_source = 'manual',
--     home_meter_recorded_at = '2026-07-22 20:18:45'
-- WHERE user_id = 77 AND attendance_date = '2026-07-22'
--   AND meter_home IS NULL AND meter_end IS NOT NULL;
