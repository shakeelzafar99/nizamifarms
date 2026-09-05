-- =====================================================================================
--  WORKSHOP VISITS — "take the bike in on Friday"  (Phase 2, Sep-02-2026)
--  Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md  §3
--
--  A manager (Qasim / Shabib / Taimur) sets a DATE for a rider to take a machine to the
--  workshop. The rider ACCEPTS it on his phone the way he accepts a shift change. The
--  date then appears everywhere the team already looks: the shift planner, attendance,
--  the Bikes screen, the management banner (including Farooq) and the rider app.
--
--  ⭐⭐ THIS IS NOT A DAY TAG. t_ops_day_tag means "not needed, paid, not counted absent"
--    and changes pay and absence treatment. OWNER RULING 2-Sep: a workshop day is a
--    NORMAL PAID WORKING DAY. The person planning the shift adjusts that day so no
--    lateness lands. Never write a day tag from this feature.
--
--  ⭐ location_id is reserved NOW for Phase 4 ("the workshop becomes a shift location"),
--    so that change needs no ALTER on a live table.
--
--  ⚠ RUN BEFORE UPLOADING THE PHP / BLADE FILES. Reads are schema-guarded, so the code
--    degrades to "no visits" without it — but the buttons would do nothing.
--  ⚠ AFTER RUNNING: users must LOG OUT AND BACK IN on mobile (permissions are a
--    login-time snapshot).
--  ⚠ Phase 1 (vehicle_tickets_sep2026.sql) should run first — a visit may reference a
--    ticket. It is only a soft reference, so the order is not fatal, but keep it.
--
--  SAFE TO RE-RUN. CREATE TABLE IF NOT EXISTS + every seed guarded by WHERE NOT EXISTS.
--  Nothing is dropped and no existing row is updated.
--
--  ⚠ DATETIME, never TIMESTAMP (prod renders TIMESTAMP +2h locally).
--  ⚠ No apostrophes and no semicolons inside any COMMENT string in this file — a naive
--    statement splitter is not string-aware and either one cuts a statement in half.
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT — read only. On prod every "found" should come back 0.
-- #####################################################################################
SELECT 'pre-flight: workshop visit table?' AS check_name, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit';

SELECT 'pre-flight: web permission seeded?' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions WHERE permission_key = 'schedule_workshop';

SELECT 'pre-flight: mobile alert permission seeded?' AS check_name, COUNT(*) AS found
  FROM t_sys_mobile_permission WHERE permission_code = 'receive_workshop_alerts';


-- #####################################################################################
--  PART 1 — the visit
-- #####################################################################################
CREATE TABLE IF NOT EXISTS `t_ops_workshop_visit` (
  `id` INT NOT NULL AUTO_INCREMENT,

  `vehicle_id` INT NOT NULL
      COMMENT 'The machine going in (t_ops_vehicle.id)',

  -- ⭐ THE VISIT NAMES A PERSON. The banner, the push and the Accept button all go to
  --    exactly this user and nobody else, so no other rider can see or accept it.
  --    Defaults to whoever the registry says holds the bike, but a manager may name
  --    someone else - if the bike changes hands before the date, the planner offers to
  --    re-point it (see superseded_by).
  `user_id` INT NOT NULL
      COMMENT 'The rider told to take it in (t_sys_user.id)',

  `visit_date` DATE NOT NULL,
  `visit_time` TIME NULL DEFAULT NULL
      COMMENT 'Appointment time when there is one - NULL means sometime that day',

  `workshop` VARCHAR(120) NULL DEFAULT NULL
      COMMENT 'Free-text name of the place, for a workshop not on the location list',

  -- ⭐ PHASE 4 lives here: once workshops are registered in t_ops_company_locations,
  --   scheduling can set that day location so checking in AT the workshop counts as on
  --   time by itself. Reserved now so Phase 4 alters nothing on a live table.
  `location_id` INT NULL DEFAULT NULL
      COMMENT 'Phase 4: the workshop as a registered shift location (t_ops_company_locations.id)',

  -- Strings, not ENUMs, for the same reason as the ticket tables: adding a purpose or a
  -- status later must not need a schema change on a live table.
  `purpose` VARCHAR(20) NOT NULL DEFAULT 'service'
      COMMENT 'service | repair | inspection | other',

  -- ⭐ WHICH job, when the purpose is a scheduled service. Marking the visit done then
  --   pre-fills the Record-service prompt with it, which is how a workshop visit ends up
  --   as a TYPED service record instead of another untyped guess.
  `maintenance_type_id` INT NULL DEFAULT NULL
      COMMENT 'purpose=service: the scheduled job being done (t_fleet_maintenance_types.id)',

  `ticket_id` INT NULL DEFAULT NULL
      COMMENT 'The bike ticket this visit answers, when it came from one',

  `note` VARCHAR(255) NULL DEFAULT NULL,

  -- scheduled   — set, waiting for the rider to accept
  -- accepted    — he has seen it and confirmed
  -- done        — the work happened (and the service record was written)
  -- cancelled   — called off
  -- rescheduled — replaced by a newer row, see superseded_by
  `status` VARCHAR(20) NOT NULL DEFAULT 'scheduled'
      COMMENT 'scheduled | accepted | done | cancelled | rescheduled',

  -- ⭐⭐ OWNER RULING 2-Sep: the RIDER is the primary acceptor. A manager may accept on
  --    his behalf when his app is not working, and that is recorded as such and shown as
  --    "accepted by X for Y" everywhere - never as the rider’s own confirmation.
  `accepted_at` DATETIME NULL DEFAULT NULL,
  `accepted_via` VARCHAR(10) NULL DEFAULT NULL
      COMMENT 'app = the rider confirmed | manager = someone confirmed for him',
  `accepted_by` INT NULL DEFAULT NULL
      COMMENT 'Who actually pressed accept - the rider himself, or the manager standing in',

  `superseded_by` INT NULL DEFAULT NULL
      COMMENT 'The newer visit that replaced this one, when it was rescheduled',

  -- Set by the day-before reminder sweep so it fires once. There is no cron on prod, so
  -- the sweep piggybacks a banner request (same pattern as the service-due push).
  `reminded_at` DATETIME NULL DEFAULT NULL,

  `done_at` DATETIME NULL DEFAULT NULL,
  `done_by` INT NULL DEFAULT NULL,
  `outcome_note` VARCHAR(500) NULL DEFAULT NULL,

  -- What the visit PRODUCED. Either a manual service-log row, or the maintenance claim
  -- that carried the bill. Both are optional - some visits are inspections.
  `service_log_id` INT NULL DEFAULT NULL
      COMMENT 'The t_fleet_service_log row written when this was marked done',
  `request_id` INT NULL DEFAULT NULL
      COMMENT 'The Maintenance claim that paid for it (t_req_master.id)',

  `created_by` INT NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_wv_date_status`    (`visit_date`, `status`),
  KEY `idx_wv_user_status`    (`user_id`, `status`),
  KEY `idx_wv_vehicle_status` (`vehicle_id`, `status`),
  KEY `idx_wv_ticket`         (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Workshop visits - a dated instruction to take a machine in, which the rider accepts';


-- #####################################################################################
--  PART 2 — permissions
--
--  ⭐ RULED 2-Sep: Farooq (role 20, supervisor 2) gets WORKSHOP alerts — he plans the
--    shifts and must know a rider is out that day — but NOT ticket alerts.
--  ⚠ Role 18 is NAMED "Shabib" but has ZERO members. Shabib is in role 10.
-- #####################################################################################

-- ── 2a. WEB: may set, reschedule, cancel and complete a visit ────────────────────────
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT r.id, 'schedule_workshop',
       'Schedule workshop visits for bikes',
       CASE WHEN r.id IN (10, 14, 17, 18) THEN 1 ELSE 0 END,
       NOW(), NOW()
  FROM t_sys_role r
 WHERE NOT EXISTS (
        SELECT 1 FROM t_sys_role_permissions p
         WHERE p.role_id = r.id AND p.permission_key = 'schedule_workshop');


-- ── 2b. MOBILE: the same right on the phone ──────────────────────────────────────────
INSERT INTO t_sys_mobile_permission
       (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'schedule_workshop', 'Schedule workshop visits', 'store_mode_orders',
       'Set the date a rider takes a bike to the workshop', 92, 1, NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission
                    WHERE permission_code = 'schedule_workshop');

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, p.id, NOW()
  FROM t_sys_role r
       CROSS JOIN t_sys_mobile_permission p
 WHERE p.permission_code = 'schedule_workshop'
   AND r.id IN (10, 14, 17)
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission x
                    WHERE x.role_id = r.id AND x.mobile_permission_id = p.id);


-- ── 2b-web. WEB: be told a workshop date has been set.
--
--  ⚠⚠ FOUND IN THE PERSONA AUDIT (3-Sep): this key was created as a MOBILE permission only,
--     so on the WEB `hasPermission(receive_workshop_alerts)` was false for EVERYONE. The web
--     corner banner then fell back to "can this person schedule?" — which is true for Qasim,
--     Shabib and Taimur but FALSE for Farooq, so the one man the owner named by name saw
--     nothing at all on the shift planner, the page he actually works in.
--  ⭐ Granted to 20 (Farooq) alongside 10/14/17. Deliberately NOT granted the ticket key —
--     that split is the whole reason there are two keys.
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT r.id, 'receive_workshop_alerts',
       'See workshop visit alerts (banner on attendance, riders map and the shift planner)',
       CASE WHEN r.id IN (10, 14, 17, 20) THEN 1 ELSE 0 END,
       NOW(), NOW()
  FROM t_sys_role r
 WHERE NOT EXISTS (
        SELECT 1 FROM t_sys_role_permissions p
         WHERE p.role_id = r.id AND p.permission_key = 'receive_workshop_alerts');


-- ── 2c. MOBILE: be told a workshop date has been set. ⭐ INCLUDES FAROOQ (role 20) ───
INSERT INTO t_sys_mobile_permission
       (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'receive_workshop_alerts', 'Workshop visit alerts', 'Notifications',
       'Be notified when a bike is booked into the workshop, and whether the rider accepted',
       93, 1, NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission
                    WHERE permission_code = 'receive_workshop_alerts');

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, p.id, NOW()
  FROM t_sys_role r
       CROSS JOIN t_sys_mobile_permission p
 WHERE p.permission_code = 'receive_workshop_alerts'
   AND r.id IN (10, 14, 17, 20)
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission x
                    WHERE x.role_id = r.id AND x.mobile_permission_id = p.id);


-- #####################################################################################
--  VERIFY — run after.
-- #####################################################################################
SELECT 'table created' AS check_name, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_workshop_visit';
-- EXPECT 1

SELECT 'web key: roles allowed' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions
 WHERE permission_key = 'schedule_workshop' AND is_allowed = 1;
-- EXPECT 4  (roles 10, 14, 17, 18)

SELECT p.permission_code, COUNT(rp.role_id) AS roles_granted
  FROM t_sys_mobile_permission p
       LEFT JOIN t_sys_role_mobile_permission rp ON rp.mobile_permission_id = p.id
 WHERE p.permission_code IN ('schedule_workshop', 'receive_workshop_alerts')
 GROUP BY p.permission_code;
-- EXPECT schedule_workshop = 3 (10, 14, 17)
--        receive_workshop_alerts = 4 (10, 14, 17 and 20 Farooq)
