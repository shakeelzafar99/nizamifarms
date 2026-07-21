-- ============================================================================
-- Company-bike HOME-METER flow — wave 2 (portable, hand-run on local + prod).
-- Adds: manager-bypass breach audit, management-escalation dedup, a generic
-- dismissable-banner table, and the management alert permission.
-- All additive + nullable / IF NOT EXISTS. "Duplicate column" / "table exists"
-- on re-run = already applied (ignore). Pairs with add_home_journey_jul2026.sql.
-- ============================================================================

-- 1) Attendance: why a manager had to bypass, and management-escalation dedup.
--    home_bypass_breach   = what was wrong when the manager unlocked/entered the
--                           meter: 'location' | 'time' | 'both' (audit trail).
--    home_mgmt_alerted_at = when management was escalated for a missed/late meter
--                           (so the sweep + banners don't re-alert the same day).
ALTER TABLE t_ops_attendance
  ADD COLUMN home_bypass_breach   VARCHAR(20) NULL,
  ADD COLUMN home_mgmt_alerted_at DATETIME    NULL;

-- 2) Generic per-user dismissable-banner acknowledgements. alert_key namespaces
--    the source, e.g. 'home_meter:2693' (the attendance id). Reusable for future
--    dismissable banners.
CREATE TABLE IF NOT EXISTS t_ops_alert_dismissal (
  id           INT(11)     NOT NULL AUTO_INCREMENT,
  user_id      INT(11)     NOT NULL,
  alert_key    VARCHAR(64) NOT NULL,
  dismissed_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alert_dismissal (user_id, alert_key),
  KEY idx_alert_dismissal_key (alert_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Per-rider "meter reading is compulsory" tag (riders page). Default 1 = required
--    for everyone; the owner UNTICKS management users who don't record meters.
--    0 = exempt (no meter nag at check-in/checkout). Company-bike riders keep their
--    home-meter flow regardless; this gates the at-checkout reading for the rest.
ALTER TABLE t_ops_rider_profile
  ADD COLUMN meter_required TINYINT(1) NOT NULL DEFAULT 1;

-- 4) Management alert permission. Mirrors receive_leave_alerts (id 59).
--    STEP 5 below GRANTS it automatically — no UI step needed. To add/remove a
--    role later: Roles -> Manage Mobile Permissions -> Notifications.
INSERT INTO t_sys_mobile_permission
  (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'receive_bike_meter_alerts', 'Receive Bike Meter Alerts', 'Notifications',
       'Get a push + in-app banner when a company-bike rider comes home late or forgets to record his meter.',
       214, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'receive_bike_meter_alerts'
);

-- 5) GRANT it to the three intended roles: Taimur, Management, supervisor 2.
--    Resolved BY NAME (case-insensitive + space-trimmed, so 'supervisor 2' with
--    its space matches) — no ids, so this runs unchanged on prod. Idempotent.
--    NOTE: deliberately NOT filtered on r.is_active. That column carries a mixed
--    'Y'/'1' convention, and comparing it to 1 would silently skip any role still
--    stored as 'Y'.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, mp.id, NOW()
FROM t_sys_role r
JOIN t_sys_mobile_permission mp ON mp.permission_code = 'receive_bike_meter_alerts'
WHERE LOWER(TRIM(r.urole_name)) IN ('taimur', 'management', 'supervisor 2')
  AND NOT EXISTS (
      SELECT 1 FROM t_sys_role_mobile_permission rmp
      WHERE rmp.role_id = r.id AND rmp.mobile_permission_id = mp.id
  );

-- 6) VERIFY — expect EXACTLY 3 rows: Management, supervisor 2, Taimur.
--    Fewer rows = a role name differs on this database. Check with
--      SELECT id, urole_name FROM t_sys_role ORDER BY urole_name;
--    then re-run STEP 5 with the corrected name.
SELECT mp.permission_code, r.id AS role_id, r.urole_name
FROM t_sys_mobile_permission mp
JOIN t_sys_role_mobile_permission rmp ON rmp.mobile_permission_id = mp.id
JOIN t_sys_role r ON r.id = rmp.role_id
WHERE mp.permission_code = 'receive_bike_meter_alerts'
ORDER BY r.urole_name;

-- 7) Checkout BYPASS for non-company-bike riders. The checkout-location rule blocks
--    checking out away from the office / last delivery — if a rider FORGETS and goes
--    home, a manager grants a timed unlock (attendance page -> Rider bypasses) so he
--    can press OUT from where he is. The columns are the audit trail (who allowed it,
--    until when, why); the checkout GPS still records where he actually was.
ALTER TABLE t_ops_attendance
  ADD COLUMN checkout_unlock_until  DATETIME     NULL,
  ADD COLUMN checkout_unlock_by     INT(11)      NULL,
  ADD COLUMN checkout_unlock_reason VARCHAR(200) NULL;
