-- ============================================================================
-- Rider-list unification — BACKFILL (run BEFORE deploying the code that requires
-- an active rider profile for the assign lists).
-- ----------------------------------------------------------------------------
-- "Delivery rider" now = a user with an ACTIVE row in t_ops_rider_profile
-- (rider_profile.active=1). The web + mobile "assign riders" lists and the Shift
-- Planner's "Riders" filter all read this one flag, curated in the People & Rider
-- List (Attendance → Settings → Customize User List).
--
-- Some current delivery riders have a rider ROLE but no profile row yet — without a
-- profile they'd vanish from the assign lists once the code requires one. This gives
-- every current rider-role user an active profile so nobody is lost. AFTER running
-- this + deploying, the owner turns OFF the non-riders (e.g. ShahJee) in the People
-- & Rider List. Safe to re-run (only inserts missing rows).
-- ============================================================================

INSERT INTO t_ops_rider_profile (user_id, active, created_at, updated_at)
SELECT DISTINCT u.id, 1, NOW(), NOW()
FROM t_sys_user u
JOIN t_sys_user_role ur ON ur.user_id = u.id
JOIN t_sys_role r ON r.id = ur.role_id
LEFT JOIN t_ops_rider_profile p ON p.user_id = u.id
WHERE r.type = 'rider'
  AND u.is_active = '1'   -- string compare: is_active is CHAR ('1'/'0'/'Y'); '1' = the active set the app already uses
  AND p.user_id IS NULL;

-- Verify: SELECT COUNT(*) FROM t_ops_rider_profile WHERE active=1;  -- should now cover all real riders
