-- =====================================================================
-- Rider Reports — Phase 2 permission grant (Jul-2026)
-- Grants the `view_rider_reports` permission (⚠ Issues tab + Report Card).
-- Idempotent: only inserts where the role doesn't already have it. Re-runnable.
--
-- Grants to: every ACTIVE admin-type role  +  the "supervisor 2" role (id 20).
-- That resolves to: Manager(9), Management(10), Taimur(14), Adnan(16),
-- Shabib(18), expense fund(19), supervisor 2(20).
-- To add/remove a role later, use the normal Roles → Permissions screen,
-- or edit the WHERE clause below.
-- =====================================================================

INSERT INTO t_sys_role_permissions
    (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT r.id, 'view_rider_reports', 'View Rider Reports', 1, NOW(), NOW()
  FROM t_sys_role r
 WHERE r.is_active = '1'
   AND (r.type = 'admin' OR r.id = 20)
   AND NOT EXISTS (
        SELECT 1 FROM t_sys_role_permissions p
         WHERE p.role_id = r.id
           AND p.permission_key = 'view_rider_reports'
   );
