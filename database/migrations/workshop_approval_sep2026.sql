-- =====================================================================================
--  WORKSHOP DAY: PROPOSE -> PLANNER APPROVES -> RIDER TOLD   (Sep-06-2026)
--  Plan: WORKSHOP-APPROVAL-FLOW-PLAN-SEP2026.md
--
--  OWNER + TEAM RULING (6-Sep): Qasim books a workshop day, but it must NOT reach the
--  rider automatically. It goes to the shift planners (Shabib, Farooq, Taimur) as a
--  banner; ONE of them approves; only then is the rider told - and told clearly that it
--  is a LOCATION change for attendance, not a time change. A planner raising it himself
--  is asked "assign now, or send for approval?".
--
--  ⭐⭐ WHY THIS IS SAFE TO RUN BEFORE THE PHP GOES UP. Every column below is NULLable
--    with no default behaviour attached, and no existing row is touched. Until the new
--    PHP is uploaded nothing writes them and nothing reads them, so running this file
--    alone changes NOTHING that anyone can see. Run it FIRST anyway - the PHP is
--    schema-guarded and silently falls back to the OLD flow (book = scheduled, rider
--    told immediately) while the columns are missing, which is correct but is not the
--    behaviour the team asked for.
--
--  ⭐ NO NEW STATUS COLUMN IS NEEDED. `status` is already VARCHAR(20); the two new values
--    'proposed' and 'declined' fit. That is deliberate - see the class note in
--    WorkshopVisitService: statuses are strings precisely so adding one needs no ALTER
--    on a live table.
--
--  ⚠⚠ 'proposed' IS NOT A LIVE STATUS. LIVE_STATUSES stays ['scheduled','accepted'], so
--     every rider-facing reader - his next visit, the attendance banner, the day-before
--     reminder, the day-of "ho gaya?" prompt - ignores a proposal by construction. That
--     is also what keeps OLD APKs safe: a phone that has never heard of 'proposed' is
--     never sent one as his, so it cannot show it.
--
--  SAFE TO RE-RUN? The two ALTERs are plain (no PREPARE - shared StackCP hosting), so a
--  second run errors with 1060 Duplicate column name, which is harmless and means that
--  part is already applied. The pre-flight below tells you before you start. The
--  permission INSERTs are guarded by WHERE NOT EXISTS and are always safe.
--
--  ⚠ DATETIME, never TIMESTAMP (prod renders TIMESTAMP +2h locally).
--  ⚠ No apostrophes and no semicolons inside any COMMENT string - a naive statement
--    splitter is not string-aware and either one cuts a statement in half.
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT - read only. On prod every "found" should come back 0.
-- #####################################################################################
SELECT 'pre-flight: approval columns already there?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_ops_workshop_visit'
   AND COLUMN_NAME IN ('proposed_by','approved_by','approved_at',
                       'declined_by','declined_at','decline_reason');
-- EXPECT 0. If it comes back 6, PART 1 has already been applied - skip it.

SELECT 'pre-flight: manage_shifts as a WEB key?' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions WHERE permission_key = 'manage_shifts';
-- EXPECT 0 (today it exists only as a MOBILE permission - see PART 2).

SELECT 'pre-flight: locations ticked as workshop' AS check_name, COUNT(*) AS found
  FROM t_ops_company_locations WHERE is_workshop = 1;
-- ⚠⚠ EXPECT AT LEAST 1, AND ON PROD TODAY IT IS 0. This is the live configuration hole
--    behind the 6-Sep report: a booking with no registered workshop pins NOTHING, so the
--    rider keeps his normal shift location and would be marked remote at the workshop.
--    Tick the real workshops on the Locations page (name + lat/long + radius + the 🔧 box)
--    or the picker in the booking form will still be empty after this file runs.


-- #####################################################################################
--  PART 1 - who proposed it, who approved or declined it, and why not
--
--  ⭐ All six are NULL for every existing row and that is correct: everything booked
--    before today was booked under the old rule (book = assigned), and this feature must
--    not retro-label history as "approved by nobody".
-- #####################################################################################
ALTER TABLE `t_ops_workshop_visit`
  ADD COLUMN `proposed_by` INT NULL DEFAULT NULL
      COMMENT 'Who sent it for approval - set when the row is created as proposed',
  ADD COLUMN `approved_by` INT NULL DEFAULT NULL
      COMMENT 'The planner who approved it (holder of manage_shifts)',
  ADD COLUMN `approved_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN `declined_by` INT NULL DEFAULT NULL,
  ADD COLUMN `declined_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN `decline_reason` VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Why the planner said no - shown back to the person who booked it';

-- The planners banner asks one question: which proposals are still waiting, soonest
-- first. Without this it is a full scan of the table every poll.
ALTER TABLE `t_ops_workshop_visit`
  ADD INDEX `idx_wv_status_date` (`status`, `visit_date`);


-- #####################################################################################
--  PART 2 - manage_shifts on the WEB
--
--  ⚠⚠ THE SAME TRAP THE PERSONA AUDIT FOUND IN THE PHASE-2 FILE. `manage_shifts` was
--     created in Jul-2026 as a MOBILE permission ONLY. So on the WEB,
--     hasPermission('manage_shifts') is FALSE FOR EVERYONE - including Shabib, Farooq and
--     Taimur - and the approval banner, the planner cell buttons and the "assign now"
--     choice would all be invisible on the desk, which is where Farooq actually works.
--
--  ⭐ Granted to exactly the roles that hold the MOBILE key today, so the same three men
--    are planners on both surfaces and there is ONE answer to "who may approve":
--       10 Management (Shabib)   14 Taimur   18 Shabib (named role, 0 members)   20 Farooq
--
--  ⚠ This grants NO new power. It only lets the web ASK a question the phone could
--    already answer. The shift screens themselves are unchanged and were never gated on
--    this key.
--  ⚠ AFTER RUNNING: web users must reload; mobile users must LOG OUT AND BACK IN
--    (mobile permissions are a login-time snapshot).
-- #####################################################################################
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT r.id, 'manage_shifts',
       'Plan shifts - approve or decline a proposed workshop day',
       CASE WHEN r.id IN (10, 14, 18, 20) THEN 1 ELSE 0 END,
       NOW(), NOW()
  FROM t_sys_role r
 WHERE NOT EXISTS (
        SELECT 1 FROM t_sys_role_permissions p
         WHERE p.role_id = r.id AND p.permission_key = 'manage_shifts');


-- #####################################################################################
--  VERIFY - run after.
-- #####################################################################################
SELECT 'six approval columns added' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_ops_workshop_visit'
   AND COLUMN_NAME IN ('proposed_by','approved_by','approved_at',
                       'declined_by','declined_at','decline_reason');
-- EXPECT 6

SELECT 'web planners' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions
 WHERE permission_key = 'manage_shifts' AND is_allowed = 1;
-- EXPECT 4  (roles 10, 14, 18, 20)

SELECT u.id, u.fullname, ur.role_id
  FROM t_sys_user u
  JOIN t_sys_user_role ur ON ur.user_id = u.id
  JOIN t_sys_role_permissions p ON p.role_id = ur.role_id
 WHERE u.is_active = '1'
   AND p.permission_key = 'manage_shifts' AND p.is_allowed = 1
 ORDER BY u.fullname;
-- EXPECT the planners by name: Shabib, Taimur, Farooq (and the Nizami Farms admin login).
-- These are the people who will now see the "awaiting your approval" banner.

SELECT 'existing visits touched (must be 0)' AS check_name, COUNT(*) AS found
  FROM t_ops_workshop_visit
 WHERE proposed_by IS NOT NULL OR approved_by IS NOT NULL OR declined_by IS NOT NULL;
-- EXPECT 0

SELECT id, location_name, latitude, longitude, radius_meters, is_workshop, is_active
  FROM t_ops_company_locations
 ORDER BY is_workshop DESC, location_name;
-- ⚠ Tick at least one row as is_workshop = 1 (Locations page, or
--   UPDATE t_ops_company_locations SET is_workshop = 1 WHERE id = <the workshop>)
--   or a booked workshop day still pins nothing.
