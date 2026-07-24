-- ============================================================================
-- Blocked-CHECKOUT capture (portable, hand-run local + prod).
-- When the mandatory-checkout rule (Phase H, config CHECKOUT_RULE_ENABLED) blocks
-- a rider's OUT press, the attempt used to vanish — no row, no log — so a manager
-- only learned about it hours later. This records the LATEST blocked attempt onto
-- the day's attendance row (where he was, where his last delivery was, how far, why)
-- so it can drive (a) a live dismissible manager alert and (b) the bypass modal
-- context. Latest-attempt-only + a counter of how many times he tried today.
-- All additive + nullable. "Duplicate column" on re-run = already applied (ignore).
-- Types mirror the existing checkout_latitude / checkin_distance_from_base columns.
-- ============================================================================

-- 1) Attendance: the latest blocked checkout attempt.
--    _reason  = too_far | too_late | wrong_place | no_gps
--    _lat/_lng      = where the rider was standing when he pressed OUT
--    _drop_lat/_lng = the reference he was measured against (his last delivery, or
--                     the office when he had no delivery today) — the "delivered location"
--    _distance_m    = metres from that reference; _limit_m = the allowed radius
--    _age_min       = minutes since that delivery (for the too_late case); NULL otherwise
--    _drop_label    = the reference order number (or 'Office'); _count = attempts today
ALTER TABLE t_ops_attendance
  ADD COLUMN checkout_attempt_at         DATETIME       NULL,
  ADD COLUMN checkout_attempt_lat        DECIMAL(10,8)  NULL,
  ADD COLUMN checkout_attempt_lng        DECIMAL(11,8)  NULL,
  ADD COLUMN checkout_attempt_reason     VARCHAR(16)    NULL,
  ADD COLUMN checkout_attempt_distance_m INT(11)        NULL,
  ADD COLUMN checkout_attempt_limit_m    INT(11)        NULL,
  ADD COLUMN checkout_attempt_age_min    INT(11)        NULL,
  ADD COLUMN checkout_attempt_drop_lat   DECIMAL(10,8)  NULL,
  ADD COLUMN checkout_attempt_drop_lng   DECIMAL(11,8)  NULL,
  ADD COLUMN checkout_attempt_drop_label VARCHAR(64)    NULL,
  ADD COLUMN checkout_attempt_count      SMALLINT       NOT NULL DEFAULT 0;

-- 2) Config (idempotent). How recent a blocked attempt must be to still raise the
--    live "rider stuck at checkout" alert (minutes). Also caps how long the bypass
--    modal treats the recorded attempt as "current".
INSERT INTO t_fin_config (config_key, config_value)
SELECT 'CHECKOUT_STUCK_ALERT_MINS', '25' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'CHECKOUT_STUCK_ALERT_MINS');

-- 3) VERIFY — expect the config key with value 25, and 11 new attempt columns.
SELECT config_key, config_value FROM t_fin_config WHERE config_key = 'CHECKOUT_STUCK_ALERT_MINS';
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_NAME = 't_ops_attendance' AND COLUMN_NAME LIKE 'checkout_attempt_%'
ORDER BY COLUMN_NAME;
