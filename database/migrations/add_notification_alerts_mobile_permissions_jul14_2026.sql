-- ============================================================================
-- Add MOBILE permissions for role-based actionable alerts
-- Nizami Farms · Role-Based Alerts (frozen store transfer + leave added) · 2026-07-14
--
-- WHAT THIS DOES
--   1. Adds two MOBILE permission codes to the catalog (group 'Notifications'):
--        receive_store_transfer_alerts  (frozen Warehouse->Store transfer awaiting acceptance)
--        receive_leave_alerts           (a new leave request was added / pending review)
--      These control BOTH the Android push AND the in-app banner: a user sees an alert
--      only if one of their roles holds the matching permission.
--   2. Grants each permission to its intended role (names CONFIRMED against the dev DB):
--        receive_store_transfer_alerts       -> role 'supervisor 2'  (note the space!)
--        receive_leave_alerts + approve_leaves -> role 'Management'
--
-- FUTURE CHANGES = NO CODE
--   To add another role (or a person via their role) to an alert later, just tick the
--   box on  Roles -> Manage Mobile Permissions  (a new "Notifications" section appears).
--
-- SAFETY
--   * Fully idempotent — safe to run more than once (INSERT ... WHERE NOT EXISTS).
--   * Data only, no schema change, no downtime.
--   * Run STEP 0 first to confirm your exact role names, then run STEP 1 + STEP 2.
--   * Run on LOCAL first, verify with STEP 3, then run on PROD.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- STEP 0 — CONFIRM YOUR ROLE NAMES  (run this SELECT by itself first)
--   Find the exact urole_name of the two roles. If they are NOT literally
--   'supervisor2' and 'management', edit the two WHERE clauses in STEP 2 to match.
-- ----------------------------------------------------------------------------
SELECT id, urole_name, is_active FROM t_sys_role ORDER BY urole_name;

-- ----------------------------------------------------------------------------
-- STEP 1 — Catalog: add the two permission codes (only if missing)
-- ----------------------------------------------------------------------------
INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'receive_store_transfer_alerts', 'Receive Store Transfer Alerts', 'Notifications',
       'Get a push + in-app banner when a frozen Warehouse->Store transfer arrives and is awaiting acceptance.',
       210, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'receive_store_transfer_alerts'
);

INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'receive_leave_alerts', 'Receive Leave Alerts', 'Notifications',
       'Get a push + in-app banner when a new leave request is added (pending review).',
       211, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'receive_leave_alerts'
);

-- Approve/reject leave requests from the mobile app (the Leave Approvals screen the
-- leave banner opens). Separate from the alert so "gets notified" and "can approve"
-- are independently grantable.
INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'approve_leaves', 'Approve Leave Requests', 'Notifications',
       'View pending leave requests and approve/reject them from the mobile app.',
       212, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'approve_leaves'
);

-- ----------------------------------------------------------------------------
-- STEP 2 — Grant each permission to its role (idempotent).
--   >>> Names below are CONFIRMED against the dev DB ('supervisor 2' with a space, and
--       'Management'). If your PROD role names differ (run STEP 0 to check), edit them.
--       Matching is case-insensitive and trims surrounding spaces. <<<
-- ----------------------------------------------------------------------------
-- 2a) receive_store_transfer_alerts  ->  supervisor 2   (dev role id 20; NOTE the space)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, mp.id, NOW()
FROM t_sys_role r
JOIN t_sys_mobile_permission mp ON mp.permission_code = 'receive_store_transfer_alerts'
WHERE LOWER(TRIM(r.urole_name)) = 'supervisor 2'
  AND NOT EXISTS (
      SELECT 1 FROM t_sys_role_mobile_permission rmp
      WHERE rmp.role_id = r.id AND rmp.mobile_permission_id = mp.id
  );

-- 2b) receive_leave_alerts  ->  management
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, mp.id, NOW()
FROM t_sys_role r
JOIN t_sys_mobile_permission mp ON mp.permission_code = 'receive_leave_alerts'
WHERE LOWER(TRIM(r.urole_name)) = 'management'
  AND NOT EXISTS (
      SELECT 1 FROM t_sys_role_mobile_permission rmp
      WHERE rmp.role_id = r.id AND rmp.mobile_permission_id = mp.id
  );

-- 2c) approve_leaves  ->  management  (can action leaves from the app)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, mp.id, NOW()
FROM t_sys_role r
JOIN t_sys_mobile_permission mp ON mp.permission_code = 'approve_leaves'
WHERE LOWER(TRIM(r.urole_name)) = 'management'
  AND NOT EXISTS (
      SELECT 1 FROM t_sys_role_mobile_permission rmp
      WHERE rmp.role_id = r.id AND rmp.mobile_permission_id = mp.id
  );

-- ----------------------------------------------------------------------------
-- STEP 3 — VERIFY (run these; each permission should list its intended role)
-- ----------------------------------------------------------------------------
SELECT mp.permission_code, r.id AS role_id, r.urole_name
FROM t_sys_mobile_permission mp
JOIN t_sys_role_mobile_permission rmp ON rmp.mobile_permission_id = mp.id
JOIN t_sys_role r ON r.id = rmp.role_id
WHERE mp.permission_code IN ('receive_store_transfer_alerts', 'receive_leave_alerts', 'approve_leaves')
ORDER BY mp.permission_code, r.urole_name;

-- If a permission shows NO rows above, the role name didn't match — re-check STEP 0
-- and re-run STEP 2 with the corrected name.
-- ============================================================================
