-- #####################################################################################
--  👥 USERS LIST (the shift/attendance roster) — permission seed.        9-Sep-2026
--
--  Owner ruling: the Shift Planner now draws the ATTENDANCE list, and the planner page
--  carries the list itself so a missing person can be added on the spot — *"if someone
--  is missing the team can select and make him available in both shift and attendance"*.
--  And: *"this list is only for shabib abd taimur to modify"*.
--
--  This file seeds ONE new permission key, `manage_user_roster`, web + mobile halves,
--  allowed for role 14 (Taimur) and role 10 (Management / Shabib). Every other role is
--  seeded is_allowed = 0 so the key is VISIBLE on Roles → Permissions and can simply be
--  ticked later, no code change. Same shape as the manage_shift_rules seed.
--
--  ⚠⚠ ROLE 10 IS "Management" AND HAS TWO HOLDERS on prod: #79 Shabib and #67
--     "Nizami Farms" (the company account). Granting role 10 therefore also grants #67.
--     If that account should NOT be able to edit the roster, untick it afterwards on
--     Roles → Permissions — or change the CASE below to role 14 only and grant Shabib
--     through a role of his own. Read the post-flight before you walk away.
--
--  ⚠ Fails CLOSED: until this runs, `canManageRoster()` is false for everyone, so the
--    "Users list" button is simply invisible on web and mobile. Nothing else changes —
--    the planner itself works without it.
--
--  Safe to re-run (every statement is guarded by NOT EXISTS).
-- #####################################################################################

-- -------------------------------------------------------------------------------------
-- PRE-FLIGHT — read only. Run these first and read the answers.
-- -------------------------------------------------------------------------------------
SELECT 'pre-flight: key already seeded?' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions WHERE permission_key = 'manage_user_roster';
-- EXPECT 0 on a first run.

SELECT 'pre-flight: who holds the two roles' AS check_name, r.id AS role_id,
       r.urole_name, u.id AS user_id, u.fullname, u.is_active
  FROM t_sys_role r
  JOIN t_sys_user_role ur ON ur.role_id = r.id
  JOIN t_sys_user u ON u.id = ur.user_id
 WHERE r.id IN (10, 14)
 ORDER BY r.id, u.fullname;
-- EXPECT role 14 → Taimur; role 10 → Shabib AND "Nizami Farms". See the ⚠⚠ note above.


-- -------------------------------------------------------------------------------------
-- PART 1 — the WEB permission key.
-- -------------------------------------------------------------------------------------
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at)
SELECT r.id, 'manage_user_roster', 'Users list - who appears in Shift Planner and Attendance',
       CASE WHEN r.id IN (10, 14) THEN 1 ELSE 0 END, NOW()
  FROM t_sys_role r
 WHERE NOT EXISTS (
        SELECT 1 FROM t_sys_role_permissions p
         WHERE p.role_id = r.id AND p.permission_key = 'manage_user_roster');


-- -------------------------------------------------------------------------------------
-- PART 2 — the MOBILE half of the same key (the phone shows the same list).
-- -------------------------------------------------------------------------------------
-- ⚠⚠ `permission_group` is NOT NULL with NO default. Omitting it fails outright on a
--    STRICT connection, and on a non-strict one (stackcp's client) it silently stores ''
--    — which lands the key in NO group on the Roles → Permissions screen. That is exactly
--    what happened to `manage_shift_rules` (it is on prod today with permission_group = '').
--    So set it explicitly, matching `manage_shifts` (store_mode_attendance).
INSERT INTO t_sys_mobile_permission
       (permission_code, permission_name, permission_group, description, display_order, is_active, created_at)
SELECT 'manage_user_roster', 'Users list', 'store_mode_attendance',
       'Add or remove people from the Shift Planner and Attendance list', 23, 1, NOW()
 WHERE NOT EXISTS (
        SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'manage_user_roster');

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, mp.id, NOW()
  FROM t_sys_mobile_permission mp
  JOIN t_sys_role r ON r.id IN (10, 14)
 WHERE mp.permission_code = 'manage_user_roster'
   AND NOT EXISTS (
        SELECT 1 FROM t_sys_role_mobile_permission rmp
         WHERE rmp.role_id = r.id AND rmp.mobile_permission_id = mp.id);


-- #####################################################################################
--  POST-FLIGHT — read only. Confirm what landed.
-- #####################################################################################
SELECT 'web: who may edit the users list' AS what, r.id, r.urole_name, p.is_allowed
  FROM t_sys_role_permissions p JOIN t_sys_role r ON r.id = p.role_id
 WHERE p.permission_key = 'manage_user_roster' AND p.is_allowed = 1
 ORDER BY r.id;
-- EXPECT exactly TWO rows — role 10 (Management) and role 14 (Taimur).

SELECT 'mobile: who may edit the users list' AS what, rmp.role_id, r.urole_name
  FROM t_sys_role_mobile_permission rmp
  JOIN t_sys_mobile_permission mp ON mp.id = rmp.mobile_permission_id
  JOIN t_sys_role r ON r.id = rmp.role_id
 WHERE mp.permission_code = 'manage_user_roster'
 ORDER BY rmp.role_id;
-- EXPECT the same two roles.

SELECT 'people this actually reaches' AS what, u.id, u.fullname, r.urole_name
  FROM t_sys_user u
  JOIN t_sys_user_role ur ON ur.user_id = u.id
  JOIN t_sys_role r ON r.id = ur.role_id
 WHERE r.id IN (10, 14) AND u.is_active = 1
 ORDER BY u.fullname;
-- EXPECT Shabib, Taimur — and "Nizami Farms" unless you decided to exclude it.

SELECT 'roster size right now' AS what, COUNT(*) AS on_the_list
  FROM t_sys_user u
  LEFT JOIN t_ops_attendance_visibility av ON av.user_id = u.id
 WHERE u.is_active = 1 AND COALESCE(av.is_visible, 1) = 1;
-- This is the number of people the Shift Planner and Attendance both show.
