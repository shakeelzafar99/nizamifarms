-- ============================================================================
-- Payroll permission (July 2026) — `manage_payroll`
-- Gates the new Payroll screen on BOTH web and mobile:
--   web    : sidebar link + all /hr/payroll/* routes (view, generate, pay)
--   mobile : /rider/payroll manager endpoints + the 🧾 Payroll button
--
-- Granted to: role 10 (Management — Shabib/owner) and role 14 (Taimur).
-- Any other role can be granted later from the normal permissions screens
-- (web: Roles → Permissions; mobile: Roles → Mobile Permissions) — the new
-- permission appears there automatically after this script + code deploy.
--
-- Run once on LOCAL, then on PROD (manual). Idempotent — safe to re-run.
-- ============================================================================

-- ── WEB permission rows (t_sys_role_permissions) ────────────────────────────
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 10, 'manage_payroll', 'Manage Payroll (view, generate & pay salaries)', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM t_sys_role_permissions WHERE role_id = 10 AND permission_key = 'manage_payroll'
);

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 14, 'manage_payroll', 'Manage Payroll (view, generate & pay salaries)', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM t_sys_role_permissions WHERE role_id = 14 AND permission_key = 'manage_payroll'
);

-- ── MOBILE permission (t_sys_mobile_permission + role links) ────────────────
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'manage_payroll', 'Manage Payroll', 'Store Mode', 'View the payroll month, set base salaries, give advances and pay the team from the mobile Payroll screen', 99, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'manage_payroll'
);

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT 10, mp.id, NOW()
FROM t_sys_mobile_permission mp
WHERE mp.permission_code = 'manage_payroll'
  AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_mobile_permission rmp
    WHERE rmp.role_id = 10 AND rmp.mobile_permission_id = mp.id
  );

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT 14, mp.id, NOW()
FROM t_sys_mobile_permission mp
WHERE mp.permission_code = 'manage_payroll'
  AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_mobile_permission rmp
    WHERE rmp.role_id = 14 AND rmp.mobile_permission_id = mp.id
  );
