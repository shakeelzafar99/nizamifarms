-- =====================================================================
-- Rider Reports — MOBILE permission (Jul-2026)
-- Adds the `view_rider_reports` mobile permission (⚠ Issues screen on the app)
-- and grants it to the same roles as the web report. Idempotent. Run DEV + PROD.
--
-- After running, you can also toggle it per role at:
--   app.nizamifarms.com/roles/{roleId}/mobile-permissions
-- =====================================================================

-- 1) the permission itself (grouped with the other store-mode order tools)
INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active)
VALUES
    ('view_rider_reports', 'View Rider Reports (Issues)', 'store_mode_orders',
     'See the ⚠ Issues report — late / off-pin / not-dispatched / stop deliveries per rider', 18, 1)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    permission_group = VALUES(permission_group),
    description = VALUES(description),
    is_active = 1;

-- 2) grant to active admin-type roles + supervisor 2 (same as the web report)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
  FROM t_sys_role r
  CROSS JOIN t_sys_mobile_permission p
 WHERE p.permission_code = 'view_rider_reports'
   AND r.is_active = '1'
   AND (r.type = 'admin' OR r.id = 20)
ON DUPLICATE KEY UPDATE role_id = t_sys_role_mobile_permission.role_id;
