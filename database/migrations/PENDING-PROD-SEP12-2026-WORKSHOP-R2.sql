-- ═══════════════════════════════════════════════════════════════════════════════
-- WORKSHOP ROUND 2 — 12-Sep-2026
--
-- One file, four parts, all ADDITIVE and IDEMPOTENT. No money moves, no row is
-- deleted, nothing is back-filled that changes an existing reading.
--
--   1. t_fleet_service_log  — the PHOTO, which may now exist WITHOUT an amount.
--   2. t_ops_workshop_visit — the arrival-prompt state for a workshop with no pin,
--                             plus who/how the arrival was stamped.
--   3. permission DATA      — Taimur hears service alerts; Qasim may hand a machine on.
--   4. verify.
--
-- ⚠ RUN THIS BEFORE UPLOADING THE WEB FILES. Every reader is Schema::hasColumn-
--   guarded, so the code is safe either way, but the features stay invisible until
--   the columns exist.
--
-- ⚠⚠ MySQL COMMITS DDL IMPLICITLY — an ALTER cannot be rolled back by wrapping it
--    in a transaction. Each statement below is therefore guarded by an
--    information_schema check and is safe to run twice.
-- ═══════════════════════════════════════════════════════════════════════════════


-- ── 1 ─ THE SERVICE PHOTO ──────────────────────────────────────────────────────
--
-- ⭐ WHY THIS IS NOT ON THE EXPENSE CLAIM (owner ruling, 11-Sep-2026). Until now a
--   photo could only ride on a bill, so a rider who was handed a receipt but no
--   money — the ordinary case at a workshop — had nowhere to put it. The manager
--   then typed an amount he could not see the evidence for. The photo belongs to
--   the WORK, not to the payment: it is taken at the workshop, it proves what was
--   done, and the bill (if one is ever filed) inherits it.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_service_log'
             AND COLUMN_NAME = 'photo_path');
SET @s := IF(@c = 0,
    'ALTER TABLE t_fleet_service_log
       ADD COLUMN photo_path VARCHAR(255) NULL DEFAULT NULL
         COMMENT ''Proof photo for the work itself. Independent of any bill - a photo may exist with no amount.'',
       ADD COLUMN photo_by INT NULL DEFAULT NULL
         COMMENT ''t_sys_user.id who attached it'',
       ADD COLUMN photo_at DATETIME NULL DEFAULT NULL',
    'SELECT "t_fleet_service_log.photo_path already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ── 2 ─ ARRIVAL AT A WORKSHOP THAT HAS NO PIN ─────────────────────────────────
--
-- ⭐⭐ THE WORKSHOP PINS ITSELF (owner ask, 11-Sep-2026). Arrival is proved by a
--    geofence, and a geofence needs a pin — so a visit booked with the free-text
--    workshop name could never reach `at_workshop`, and the rider showed "going to
--    the workshop" all day. Rather than ban free text, the rider's OWN arrival
--    becomes the pin: he is asked once, and answering "yes" both stamps the arrival
--    and registers the workshop at his position. Every later visit there is
--    automatic.
--
-- ⚠ THE COUNTER AND THE COOLDOWN LIVE HERE, NOT ON THE DEVICE. A count kept in the
--   app resets when the app does, which is exactly how a "confirm?" prompt turns
--   into a prompt that will not go away. The server decides whether to ask.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit'
             AND COLUMN_NAME = 'arrival_ask_at');
SET @s := IF(@c = 0,
    'ALTER TABLE t_ops_workshop_visit
       ADD COLUMN arrival_ask_at DATETIME NULL DEFAULT NULL
         COMMENT ''When he was last asked "are you there?" - the 45-minute half of the cooldown'',
       ADD COLUMN arrival_ask_lat DECIMAL(10,7) NULL DEFAULT NULL
         COMMENT ''Where he was when asked - the 300-metre half of the cooldown'',
       ADD COLUMN arrival_ask_lng DECIMAL(10,7) NULL DEFAULT NULL,
       ADD COLUMN arrival_ask_count TINYINT NOT NULL DEFAULT 0
         COMMENT ''Hard cap: he is asked at most twice per visit, ever'',
       ADD COLUMN arrived_by INT NULL DEFAULT NULL
         COMMENT ''t_sys_user.id who proved the arrival (the rider himself, or a manager)'',
       ADD COLUMN arrived_source VARCHAR(20) NULL DEFAULT NULL
         COMMENT ''geofence | rider_confirmed | manager - NULL = stamped before this column existed''',
    'SELECT "t_ops_workshop_visit.arrival_ask_at already present" AS note');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing arrivals were all geofenced; say so rather than leaving them unexplained.
UPDATE t_ops_workshop_visit
   SET arrived_source = 'geofence'
 WHERE arrived_at IS NOT NULL AND arrived_source IS NULL;


-- ── 3 ─ PERMISSION DATA (two open rulings, closed 11-Sep) ─────────────────────
--
-- ⚠⚠ permission_group IS NOT NULL WITH NO DEFAULT. An INSERT that omits it fails,
--    and one that passes '' leaves a permission that no group screen can show
--    (that is how `manage_shift_rules` reached prod with an empty group). Both
--    codes below already exist, so these are GRANTS only — no new permission rows.
--
-- Granted BY ROLE, resolved from the user, so a role id that differs on prod
-- cannot silently grant the wrong people.

-- 3a. Taimur hears that a bike is due for service.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT DISTINCT ur.role_id, mp.id
  FROM t_sys_user_role ur
  JOIN t_sys_user u  ON u.id = ur.user_id
  JOIN t_sys_mobile_permission mp ON mp.permission_code = 'receive_service_alerts'
 WHERE u.fullname LIKE 'Taimur%'
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission x
                    WHERE x.role_id = ur.role_id AND x.mobile_permission_id = mp.id);

-- 3b. Qasim may hand a machine over.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT DISTINCT ur.role_id, mp.id
  FROM t_sys_user_role ur
  JOIN t_sys_user u  ON u.id = ur.user_id
  JOIN t_sys_mobile_permission mp ON mp.permission_code = 'assign_vehicles'
 WHERE u.fullname LIKE 'Qasim%'
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission x
                    WHERE x.role_id = ur.role_id AND x.mobile_permission_id = mp.id);


-- ── 4 ─ VERIFY ────────────────────────────────────────────────────────────────

SELECT 'service_log photo columns' AS check_name, COUNT(*) AS found_expect_3
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fleet_service_log'
   AND COLUMN_NAME IN ('photo_path','photo_by','photo_at');

SELECT 'workshop_visit arrival columns' AS check_name, COUNT(*) AS found_expect_6
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit'
   AND COLUMN_NAME IN ('arrival_ask_at','arrival_ask_lat','arrival_ask_lng',
                       'arrival_ask_count','arrived_by','arrived_source');

SELECT u.fullname, mp.permission_code
  FROM t_sys_user u
  JOIN t_sys_user_role ur ON ur.user_id = u.id
  JOIN t_sys_role_mobile_permission rmp ON rmp.role_id = ur.role_id
  JOIN t_sys_mobile_permission mp ON mp.id = rmp.mobile_permission_id
 WHERE mp.permission_code IN ('receive_service_alerts','assign_vehicles')
   AND (u.fullname LIKE 'Taimur%' OR u.fullname LIKE 'Qasim%')
 ORDER BY u.fullname, mp.permission_code;

-- ⚠ Workshops with NO pin today — each one is a visit whose arrival cannot be
--   geofenced until somebody goes there and answers "yes". Expected to shrink on
--   its own from here.
SELECT id, location_name, latitude, longitude, radius_meters
  FROM t_ops_company_locations
 WHERE is_workshop = 1 AND (latitude IS NULL OR longitude IS NULL);
