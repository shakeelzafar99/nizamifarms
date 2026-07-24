-- ============================================================================
-- Company-bike MORNING-START journey (U5) — wave 4 (portable, hand-run local + prod).
-- The mirror of the going-home flow, for the ride TO work:
--   rider records his meter AT HOME before leaving -> ETA (home -> today's shift
--   office) + buffer arms work_expected_by -> he checks in as normal; arriving
--   past the deadline / skipping the home reading is RECORDED, never blocked
--   (Phase 1). Phase 2 (config flip, no redeploy) turns on the check-in lock
--   with a manager unlock valve.
-- Pairs with add_home_journey_jul2026.sql / wave2 / wave3.
-- All additive + nullable. "Duplicate column" on re-run = already applied (ignore).
-- ============================================================================

-- 1) Attendance: the morning journey + where/when the START meter came from.
--    work_expected_by        = deadline to reach the shift office (arm time + ETA + buffer)
--    work_eta_min / _km      = the computed ride (audit of what the deadline was based on)
--    meter_start_source      = 'home' (GPS-confirmed at home pin) | 'checkin' (typed at the
--                              office — the "forgot" path) | 'manager' (entered/corrected by
--                              a manager). NULL = predates this wave.
--    meter_start_recorded_at = exact moment the start reading was typed
ALTER TABLE t_ops_attendance
  ADD COLUMN work_expected_by        DATETIME     NULL,
  ADD COLUMN work_eta_min            INT(11)      NULL,
  ADD COLUMN work_distance_km        DECIMAL(6,2) NULL,
  ADD COLUMN meter_start_source      VARCHAR(20)  NULL,
  ADD COLUMN meter_start_recorded_at DATETIME     NULL;

-- 2) Phase-2 check-in lock valve (plumbing now, enforcement OFF via config below).
--    Mirrors checkout_unlock_*: a manager opens a timed window so a locked rider
--    (late past ETA / skipped the home start once the lock is live) can check in.
ALTER TABLE t_ops_attendance
  ADD COLUMN checkin_unlock_until  DATETIME     NULL,
  ADD COLUMN checkin_unlock_by     INT(11)      NULL,
  ADD COLUMN checkin_unlock_reason VARCHAR(200) NULL;

-- 3) Config (idempotent). CHECKIN_ETA_LOCK stays 0 for the soft launch — flipping
--    it to 1 later enables the lock with NO code upload. METER_CONTINUITY_KM is
--    the "morning reading must match last night's" tolerance.
INSERT INTO t_fin_config (config_key, config_value)
SELECT 'WORK_ETA_BUFFER_MIN', '10' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'WORK_ETA_BUFFER_MIN');

INSERT INTO t_fin_config (config_key, config_value)
SELECT 'CHECKIN_ETA_LOCK', '0' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'CHECKIN_ETA_LOCK');

INSERT INTO t_fin_config (config_key, config_value)
SELECT 'METER_CONTINUITY_KM', '1' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'METER_CONTINUITY_KM');

-- 4) VERIFY — expect the three keys with values 10 / 0 / 1.
SELECT config_key, config_value FROM t_fin_config
WHERE config_key IN ('WORK_ETA_BUFFER_MIN', 'CHECKIN_ETA_LOCK', 'METER_CONTINUITY_KM')
ORDER BY config_key;
