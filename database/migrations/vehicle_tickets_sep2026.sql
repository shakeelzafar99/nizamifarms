-- =====================================================================================
--  BIKE TICKETS — a conversation about a MACHINE  (Phase 1, Sep-02-2026)
--  Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md  §2
--
--  A rider reports a problem with the bike he is holding (text, photos, voice notes);
--  Qasim / Shabib / Taimur reply and close it. The thread IS the audit trail — status
--  changes and (in Phase 2) scheduled workshop visits are written into it as system
--  lines, so "what happened to this complaint" is answerable from one place.
--
--  ⭐ A TICKET BELONGS TO THE MACHINE, not to the rider. Hand the bike over and its
--    history goes with it: the new keeper can read what was already reported, and a
--    manager looking at the bike sees every complaint it has ever had.
--
--  ⚠ RUN BEFORE UPLOADING THE PHP / BLADE FILES. Every read is schema-guarded, so the
--    code degrades to "no tickets" without these tables — but the buttons would do
--    nothing, which reads as broken.
--  ⚠ AFTER RUNNING: users must LOG OUT AND BACK IN on mobile — mobile permissions are
--    a login-time snapshot (see [[mobile-startup-and-apk-size-project]]).
--
--  SAFE TO RE-RUN. Tables use CREATE TABLE IF NOT EXISTS; every seed row is guarded by
--  WHERE NOT EXISTS. Nothing is dropped and no existing row is updated.
--
--  ⚠ DATETIME everywhere, never TIMESTAMP — prod renders TIMESTAMP +2h locally
--    (see [[rider-gps-two-clock-offset]]).
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT — read only. On prod every "found" should come back 0.
-- #####################################################################################
SELECT 'pre-flight: ticket table?' AS check_name, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_vehicle_ticket';

SELECT 'pre-flight: ticket message table?' AS check_name, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_vehicle_ticket_message';

SELECT 'pre-flight: web permission seeded?' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions WHERE permission_key = 'manage_vehicle_tickets';

SELECT 'pre-flight: mobile permission seeded?' AS check_name, COUNT(*) AS found
  FROM t_sys_mobile_permission WHERE permission_code = 'receive_vehicle_ticket_alerts';


-- #####################################################################################
--  PART 1 — the ticket
-- #####################################################################################
CREATE TABLE IF NOT EXISTS `t_ops_vehicle_ticket` (
  `id` INT NOT NULL AUTO_INCREMENT,

  -- ⭐ THE MACHINE IS THE SUBJECT. Not nullable: a ticket with no bike is a message,
  --    and this is not a messaging table.
  `vehicle_id` INT NOT NULL
      COMMENT 'The machine this ticket is about (t_ops_vehicle.id)',

  -- WHO RAISED IT. Normally the rider holding the bike. A manager may open one for a
  -- rider, in which case opened_by is the manager and opened_for_user_id is the rider
  -- it concerns.
  -- ⚠ No apostrophes and no semicolons inside any COMMENT string in this file. A naive
  --   splitter that strips "--" lines and cuts on ";" is not string-aware, so either
  --   one silently cuts a statement in half. Workbench copes; a script may not.
  `opened_by` INT NOT NULL
      COMMENT 'Who created the ticket (t_sys_user.id)',
  `opened_for_user_id` INT NULL DEFAULT NULL
      COMMENT 'The rider it concerns - equals opened_by when he raised it himself',

  -- Stored as strings, not ENUMs: adding a category or a status later must not need
  -- a schema change on a live table (same reasoning as t_ops_vehicle_handover_request).
  `category` VARCHAR(20) NOT NULL DEFAULT 'problem'
      COMMENT 'problem | service | accident | other',

  -- ⚠ "The bike cannot be ridden" — drives the red banner and the ordering, and is
  --   deliberately separate from category: an accident may be driveable and a routine
  --   noise may not be.
  `urgent` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = bike not rideable / needs attention now',

  `title` VARCHAR(120) NOT NULL
      COMMENT 'One-line summary, shown in every list',

  -- open          — raised, nobody has answered
  -- acknowledged  — a manager has replied (set automatically on his first message)
  -- scheduled     — a workshop visit is attached (Phase 2, set automatically)
  -- closed        — dealt with; only a manager may close (owner ruling)
  `status` VARCHAR(20) NOT NULL DEFAULT 'open'
      COMMENT 'open | acknowledged | scheduled | closed',

  `assigned_to` INT NULL DEFAULT NULL
      COMMENT 'Manager who took it; a rider reply notifies him rather than the whole group',

  -- Phase 2 fills this in. Declared now so the FK-less join exists from day one and
  -- Phase 2 needs no ALTER on a live table.
  `workshop_visit_id` INT NULL DEFAULT NULL
      COMMENT 'Phase 2: the workshop visit scheduled off this ticket',

  -- ⭐ The bill stays in the normal Maintenance claim; this only points at it, so the
  --   ledger and the expense reports are untouched by tickets.
  `request_id` INT NULL DEFAULT NULL
      COMMENT 'The Maintenance claim that paid for the fix (t_req_master.id)',

  `opened_at` DATETIME NOT NULL,
  `first_response_at` DATETIME NULL DEFAULT NULL
      COMMENT 'First manager reply — how long a rider waited',
  `closed_at` DATETIME NULL DEFAULT NULL,
  `closed_by` INT NULL DEFAULT NULL,
  `close_note` VARCHAR(500) NULL DEFAULT NULL,

  -- ⭐ Denormalised so a list of tickets does not need a per-row MAX() on the message
  --   table, and so "newest activity first" is one indexed sort.
  `last_message_at` DATETIME NULL DEFAULT NULL,

  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_vt_vehicle_status` (`vehicle_id`, `status`),
  KEY `idx_vt_for_status`     (`opened_for_user_id`, `status`),
  KEY `idx_vt_status_recent`  (`status`, `last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bike tickets — a rider report about a machine, answered and closed by a manager';


-- #####################################################################################
--  PART 2 — the thread
-- #####################################################################################
CREATE TABLE IF NOT EXISTS `t_ops_vehicle_ticket_message` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ticket_id` INT NOT NULL,

  -- NULL only for a system line the app itself wrote (a status change).
  `user_id` INT NULL DEFAULT NULL
      COMMENT 'Author; NULL = written by the system',

  -- text  — what someone typed
  -- photo — an image of the fault
  -- voice — a recorded note (the rider often cannot type what he means)
  -- system— "Qasim closed this", "workshop set for 5 Sep" — the audit trail
  `kind` VARCHAR(10) NOT NULL DEFAULT 'text'
      COMMENT 'text | photo | voice | system',

  `body` TEXT NULL DEFAULT NULL
      COMMENT 'Message text, or the system line',

  -- Relative path on the public disk, served through /public-storage/{path} —
  -- the same door the condition photos and WhatsApp media already use, which works
  -- on shared hosting with no symlink.
  `media_path` VARCHAR(255) NULL DEFAULT NULL,
  `media_mime` VARCHAR(60)  NULL DEFAULT NULL,
  `duration_ms` INT NULL DEFAULT NULL
      COMMENT 'Voice notes: length, so the app can show it without downloading first',

  `created_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_vtm_ticket` (`ticket_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Messages on a bike ticket — text, photos, voice notes and system lines';


-- #####################################################################################
--  PART 3 — per-person read marker (drives the unread badge)
-- #####################################################################################
CREATE TABLE IF NOT EXISTS `t_ops_vehicle_ticket_read` (
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `last_read_message_id` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`ticket_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='How far each person has read a ticket thread';


-- #####################################################################################
--  PART 4 — permissions
--
--  ⭐ RULED 2-Sep: tickets go to Qasim (role 17 khaas), Shabib (role 10 Management)
--    and Taimur (role 14). Farooq (role 20) gets WORKSHOP alerts only — Phase 2 —
--    and is deliberately NOT granted anything here.
--  ⚠ Role 18 is NAMED "Shabib" but has ZERO members; Shabib is in role 10. Never
--    seed to 18 expecting him to get it. (18 is granted below only for parity with
--    the other bike keys, exactly as manage_bike_service was.)
--  ⚠ Riders need NO key: opening a ticket and reading their own is self-scoped
--    through the vehicle registry.
-- #####################################################################################

-- ── 4a. WEB: may respond to and close tickets ────────────────────────────────────────
--     One row per role, is_allowed 1 for the three who hold it. Mirrors how
--     manage_bike_service was seeded. The label must match
--     RolePermissionController::$permissions or the Roles screen shows a bare key.
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT r.id, 'manage_vehicle_tickets',
       'Respond to and close bike tickets',
       CASE WHEN r.id IN (10, 14, 17, 18) THEN 1 ELSE 0 END,
       NOW(), NOW()
  FROM t_sys_role r
 WHERE NOT EXISTS (
        SELECT 1 FROM t_sys_role_permissions p
         WHERE p.role_id = r.id AND p.permission_key = 'manage_vehicle_tickets');


-- ── 4b. MOBILE: the same right on the phone ──────────────────────────────────────────
INSERT INTO t_sys_mobile_permission
       (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'manage_vehicle_tickets', 'Manage bike tickets', 'store_mode_orders',
       'Reply to and close the bike problems riders report', 90, 1, NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission
                    WHERE permission_code = 'manage_vehicle_tickets');

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, p.id, NOW()
  FROM t_sys_role r
       CROSS JOIN t_sys_mobile_permission p
 WHERE p.permission_code = 'manage_vehicle_tickets'
   AND r.id IN (10, 14, 17)
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission x
                    WHERE x.role_id = r.id AND x.mobile_permission_id = p.id);


-- ── 4c. MOBILE: be told when a rider raises one ──────────────────────────────────────
INSERT INTO t_sys_mobile_permission
       (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'receive_vehicle_ticket_alerts', 'Bike ticket alerts', 'Notifications',
       'Be notified when a rider reports a problem with a bike', 91, 1, NOW(), NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission
                    WHERE permission_code = 'receive_vehicle_ticket_alerts');

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, p.id, NOW()
  FROM t_sys_role r
       CROSS JOIN t_sys_mobile_permission p
 WHERE p.permission_code = 'receive_vehicle_ticket_alerts'
   AND r.id IN (10, 14, 17)
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission x
                    WHERE x.role_id = r.id AND x.mobile_permission_id = p.id);


-- #####################################################################################
--  VERIFY — run after. Expect: 3 tables, 1 web key across every role (4 allowed),
--  2 mobile keys, 3 roles on each.
-- #####################################################################################
SELECT 'tables created' AS check_name, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('t_ops_vehicle_ticket', 't_ops_vehicle_ticket_message', 't_ops_vehicle_ticket_read');
-- EXPECT 3

SELECT 'web key: roles allowed' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions
 WHERE permission_key = 'manage_vehicle_tickets' AND is_allowed = 1;
-- EXPECT 4  (roles 10, 14, 17, 18)

SELECT p.permission_code, COUNT(rp.role_id) AS roles_granted
  FROM t_sys_mobile_permission p
       LEFT JOIN t_sys_role_mobile_permission rp ON rp.mobile_permission_id = p.id
 WHERE p.permission_code IN ('manage_vehicle_tickets', 'receive_vehicle_ticket_alerts')
 GROUP BY p.permission_code;
-- EXPECT 2 rows, 3 roles each (10 Management/Shabib · 14 Taimur · 17 khaas/Qasim)
