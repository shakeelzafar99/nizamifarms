-- =====================================================================================
--  WORKSHOP AS A SHIFT LOCATION  (Phase 4, Sep-02-2026)
--  Plan: VEHICLE-TICKETS-AND-WORKSHOP-PLAN-SEP2026.md  §3 / Phase 4
--
--  OWNER INTENT (2-Sep): "later workshop will be added as the shift location" — so a rider
--  who checks in AT the workshop on his visit day is measured against the WORKSHOP, not the
--  office, and no lateness or remote-check-in flag lands on a day he was told to be there.
--
--  ⭐⭐ HOW IT WORKS, and why this is only one column. Attendance already resolves the
--    check-in base from the rider SHIFT for that day:
--        RiderController check-in -> ShiftResolutionService::getUserShift(user, today)
--        -> resolveLocation() -> the assignment location -> LocationService
--    So making a workshop day work needs nothing new in attendance at all: the workshop must
--    simply BE a company location, and that day's shift must carry it. Scheduling a visit with
--    a location now writes a ONE-DAY, location-only shift override, and cancelling removes it.
--
--  ⚠ THIS COLUMN IS ONLY A LABEL. It marks which locations are workshops so that:
--      (a) the "which workshop?" picker lists them, and
--      (b) the shift-assign office bubbles EXCLUDE them — a workshop must never be picked as
--          somebody permanent place of work by accident. Exactly how is_handover_point already
--          keeps van meet-up points out of that same picker.
--
--  ⚠ NO BEHAVIOUR CHANGES ON RUNNING THIS FILE. Until a manager ticks a location as a
--    workshop AND schedules a visit against it, nothing differs. Phase 4 is opt-in per visit.
--
--  ⚠ SAFE TO RE-RUN, BUT READ THIS: the two ALTERs below are plain (no PREPARE — shared
--    StackCP hosting). Running the file a SECOND time errors with
--        1060 Duplicate column name
--    which is harmless and means that part is already applied. The pre-flight above tells
--    you before you start: found = 1 means skip that ALTER. Nothing is dropped or rewritten
--    either way, and the VERIFY block at the end is the real check.
--  ⚠ No apostrophes and no semicolons inside any COMMENT string (naive splitters are not
--    string-aware).
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT — expect found = 0 on prod.
-- #####################################################################################
SELECT 'pre-flight: is_workshop column?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_ops_company_locations'
   AND COLUMN_NAME = 'is_workshop';

SELECT 'pre-flight: shift override link column?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_ops_user_shift_assignment'
   AND COLUMN_NAME = 'workshop_visit_id';


-- #####################################################################################
--  PART 1 — mark which company locations are workshops
--
--  ⚠ Plain ALTER, no PREPARE — shared StackCP hosting. If it errors with "duplicate column"
--    the column is already there and the file has been run before, which is harmless.
-- #####################################################################################
ALTER TABLE `t_ops_company_locations`
  ADD COLUMN `is_workshop` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Label only - 1 marks a bike workshop, so it is offered when booking a visit and hidden from the office picker';


-- #####################################################################################
--  PART 2 — mark which shift overrides a workshop visit created
--
--  ⭐⭐ THIS IS THE SAFETY COLUMN, and the reason Phase 4 can be trusted near attendance.
--    Booking a visit at a registered workshop writes a ONE-DAY, location-only override on
--    t_ops_user_shift_assignment. Cancelling or moving the visit must remove that row again —
--    and must remove ONLY its own. Without this column the cleanup would have to match on
--    user plus date, which would happily delete an override a PLANNER made by hand for the
--    same day. Every write and delete is keyed on this id instead.
--
--  ⚠ Also how applyShiftLocation() decides not to touch a human decision: a bounded row for
--    that day with this column NULL was made by a person, and is left exactly as it is.
-- #####################################################################################
ALTER TABLE `t_ops_user_shift_assignment`
  ADD COLUMN `workshop_visit_id` INT NULL DEFAULT NULL
  COMMENT 'Set when this one-day override was created by a workshop visit - never set by hand',
  ADD INDEX `idx_usa_workshop_visit` (`workshop_visit_id`);


-- #####################################################################################
--  VERIFY
-- #####################################################################################
SELECT 'both columns added' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 't_ops_company_locations' AND COLUMN_NAME = 'is_workshop')
     OR (TABLE_NAME = 't_ops_user_shift_assignment' AND COLUMN_NAME = 'workshop_visit_id'));
-- EXPECT 2

SELECT 'shift overrides created by a visit (expect 0 on a fresh run)' AS check_name,
       COUNT(*) AS found
  FROM t_ops_user_shift_assignment WHERE workshop_visit_id IS NOT NULL;

SELECT id, location_name, is_primary, is_handover_point, is_workshop, is_active
  FROM t_ops_company_locations
 ORDER BY id;
-- EXPECT every is_workshop = 0. Tick the real workshops by hand afterwards, e.g.
--   UPDATE t_ops_company_locations SET is_workshop = 1 WHERE id = <the workshop>
-- or add the workshop as a new location first (name + lat/long + radius), then tick it.
-- ⚠ Until a workshop is ticked AND a visit is booked against it, nothing changes.
