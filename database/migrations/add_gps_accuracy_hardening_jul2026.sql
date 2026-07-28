-- ============================================================================
-- GPS ACCURACY HARDENING (portable, hand-run local + prod).
--
-- WHY: three July incidents (Kanan "delivered away", Waseem "typed at office",
-- Asim "stuck at checkout") all came down to the same two gaps:
--   1. The system JUDGED a rider on a GPS fix it then THREW AWAY — the morning
--      meter's position + the home/away/no-GPS verdict were computed in
--      RiderController::uploadMeterPicture and never stored, so afterwards
--      nobody could say how far from home he actually was.
--   2. Nothing recorded the fix ACCURACY, so a +-100 m network fix was judged
--      against a 150 m / 300 m radius exactly like a +-5 m satellite fix.
-- Plus: the attendance row keeps only the LATEST blocked checkout attempt, so
-- Asim's real 248 m attempt was overwritten by a later 7.5 km one.
--
-- All additive + nullable. "Duplicate column"/"table exists" on re-run = already
-- applied (ignore). Types mirror the existing checkin_accuracy / checkout_latitude
-- columns so nothing new is introduced.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Attendance: keep the morning start-meter fix that we judge the rider on.
--    _lat/_lng     = where he stood when he recorded the start meter
--    _accuracy_m   = the fix's own accuracy (NULL from older APKs that don't send it)
--    _distance_m   = metres from his HOME pin  (already computed, never stored)
--    _office_m     = metres from his shift/office location (so "at office" can be
--                    stated as a measurement instead of assumed)
--    _gps          = the verdict: home | office | away | none   ('none' = no usable
--                    fix; today that case is mislabelled "typed at office")
-- ---------------------------------------------------------------------------
ALTER TABLE t_ops_attendance
  ADD COLUMN meter_start_lat        DECIMAL(10,8) NULL,
  ADD COLUMN meter_start_lng        DECIMAL(11,8) NULL,
  ADD COLUMN meter_start_accuracy_m FLOAT         NULL,
  ADD COLUMN meter_start_distance_m INT(11)       NULL,
  ADD COLUMN meter_start_office_m   INT(11)       NULL,
  ADD COLUMN meter_start_gps        VARCHAR(8)    NULL;

-- 2) Attendance: accuracy of the fix used on a BLOCKED checkout attempt.
ALTER TABLE t_ops_attendance
  ADD COLUMN checkout_attempt_accuracy_m FLOAT NULL;

-- ---------------------------------------------------------------------------
-- 3) EVERY blocked checkout attempt (the row above still holds the latest one,
--    which the live alert + bypass modal read — unchanged). Asim pressed OUT 7
--    times on Jul-26: the first at 248 m from the drop, the last at 7.5 km an
--    hour later. Only the last survived, which inverted how the day looked.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS t_ops_checkout_attempt_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT           NOT NULL COMMENT 'FK to t_ops_attendance.id',
  user_id       INT           NOT NULL COMMENT 'FK to t_sys_user (rider)',
  attempted_at  DATETIME      NOT NULL COMMENT 'When he pressed OUT',
  latitude      DECIMAL(10,8) NULL COMMENT 'Where he stood (NULL when GPS was off)',
  longitude     DECIMAL(11,8) NULL,
  accuracy_m    FLOAT         NULL COMMENT 'Fix accuracy in metres, NULL from older APKs',
  reason        VARCHAR(16)   NULL COMMENT 'too_far | too_late | wrong_place | no_gps',
  distance_m    INT(11)       NULL COMMENT 'Metres from the reference point',
  limit_m       INT(11)       NULL COMMENT 'Allowed radius at the time',
  age_min       INT(11)       NULL COMMENT 'Minutes since that delivery (too_late case)',
  ref_lat       DECIMAL(10,8) NULL COMMENT 'Reference: last delivery, else office',
  ref_lng       DECIMAL(11,8) NULL,
  ref_label     VARCHAR(64)   NULL COMMENT 'Order number, or Office',
  created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_attendance (attendance_id),
  INDEX idx_user_attempted (user_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Every blocked checkout attempt (attendance row keeps only the latest)';

-- ---------------------------------------------------------------------------
-- 4) Delivery presses: how good was the fix we stamp the drop with? The
--    "delivered X m from pin" alert AND the checkout rule both measure against
--    this point, so a bad fix here punishes the rider twice. NULL until the new
--    APK sends them; nothing reads them as anything but extra context.
-- ---------------------------------------------------------------------------
ALTER TABLE t_crm_order_status_history
  ADD COLUMN delivery_accuracy_m FLOAT   NULL,
  ADD COLUMN delivery_fix_age_s  INT(11) NULL;

-- ---------------------------------------------------------------------------
-- 5) Config (idempotent). A fix whose own accuracy is worse than this many
--    metres cannot be called "away from home"/"off pin" with confidence — it is
--    reported as coarse/unverified instead. Judgement only: the hard checkout
--    block is NOT relaxed by this.
-- ---------------------------------------------------------------------------
INSERT INTO t_fin_config (config_key, config_value)
SELECT 'COARSE_FIX_M', '150' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'COARSE_FIX_M');

-- 5b) How many metres of benefit-of-the-doubt the CHECKOUT gate gives a fix for its
--     own accuracy. DEFAULT 0 = the gate behaves exactly as it does today; the
--     accuracy is still recorded on every blocked attempt so a manager can see it.
--     Raise it (e.g. 100) only if you decide coarse fixes should be let through.
INSERT INTO t_fin_config (config_key, config_value)
SELECT 'CHECKOUT_ACCURACY_SLACK_M', '0' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'CHECKOUT_ACCURACY_SLACK_M');

-- ---------------------------------------------------------------------------
-- 6) VERIFY — expect 6 meter_start_* new columns, checkout_attempt_accuracy_m,
--    2 delivery_* columns, the log table, and COARSE_FIX_M = 150.
-- ---------------------------------------------------------------------------
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_attendance'
  AND (COLUMN_NAME LIKE 'meter_start_%' OR COLUMN_NAME = 'checkout_attempt_accuracy_m')
ORDER BY COLUMN_NAME;

SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_order_status_history'
  AND COLUMN_NAME LIKE 'delivery_%'
ORDER BY COLUMN_NAME;

SELECT COUNT(*) AS checkout_attempt_log_exists FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_checkout_attempt_log';

SELECT config_key, config_value FROM t_fin_config
WHERE config_key IN ('COARSE_FIX_M', 'CHECKOUT_ACCURACY_SLACK_M');
