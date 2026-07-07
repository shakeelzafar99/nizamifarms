-- ============================================================================
-- Mobile permission: manage_shifts — lets a role assign/change rider shifts from
-- the mobile app (the new Store → Shifts screen). Run on local + prod. Idempotent.
-- ----------------------------------------------------------------------------
-- Grants to the SAME roles that already have `view_store_attendance` (your store /
-- attendance managers: Management, Taimur, Shabib, expense fund, supervisor 2).
-- Adjust later in the mobile Roles & Permissions UI if you want a different set.
-- ============================================================================

-- 1) Create the permission (only if it doesn't already exist).
INSERT INTO t_sys_mobile_permission
  (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'manage_shifts', 'Manage Shifts', 'store_mode_attendance',
       'Assign and change rider shifts from the mobile app', 22, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'manage_shifts');

-- 2) Grant it to every role that already holds view_store_attendance
--    (skips roles that already have manage_shifts, so it's safe to re-run).
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT rmp.role_id,
       (SELECT id FROM t_sys_mobile_permission WHERE permission_code = 'manage_shifts'),
       NOW()
FROM t_sys_role_mobile_permission rmp
JOIN t_sys_mobile_permission mp ON mp.id = rmp.mobile_permission_id
WHERE mp.permission_code = 'view_store_attendance'
  AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_mobile_permission x
    JOIN t_sys_mobile_permission mp2 ON mp2.id = x.mobile_permission_id
    WHERE x.role_id = rmp.role_id AND mp2.permission_code = 'manage_shifts'
  );

-- Verify:
--   SELECT r.urole_name FROM t_sys_role r
--   JOIN t_sys_role_mobile_permission rmp ON rmp.role_id=r.id
--   JOIN t_sys_mobile_permission mp ON mp.id=rmp.mobile_permission_id
--   WHERE mp.permission_code='manage_shifts';
