-- =====================================================
-- Qurbani Invoices Mobile Permission
-- Date: May 19, 2026
--
-- Adds a new `view_qurbani_invoices` mobile permission so the owner
-- can grant general Qurbani-mode access (`access_qurbani_mode`)
-- without exposing the All Invoices view, which is payment-sensitive.
--
-- A user must have BOTH `access_qurbani_mode` AND
-- `view_qurbani_invoices` to see the Qurbani Invoices entry in the
-- mobile sidebar. Missing the second permission keeps Qurbani mode
-- usable for ops (orders, riders, summary) while hiding payment
-- info from operations staff.
--
-- Grants the new permission to every role that currently holds
-- `access_qurbani_mode`, so the rollout is non-destructive — the
-- repo owner can then revoke it from specific roles afterwards
-- via the existing role/permission UI.
--
-- Re-runnable: ON DUPLICATE KEY guards every INSERT.
-- =====================================================

-- ─── Section 1: insert the new permission row ─────────────────
INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active)
VALUES
    ('view_qurbani_invoices', 'View Qurbani Invoices', 'qurbani_mode',
     'Can view the Qurbani Invoices (All Invoices) screen inside Qurbani mode. Use this to gate access to payment-sensitive views — without this permission the user can still use Qurbani mode for orders, riders, and summary, but the All Invoices entry is hidden.',
     2, 1)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description     = VALUES(description),
    display_order   = VALUES(display_order),
    is_active       = VALUES(is_active);


-- ─── Section 2: grant to every role that already has Qurbani access ─
-- Non-destructive bootstrap: anyone who can enter Qurbani mode
-- today keeps invoice visibility today. Revoke per-role afterwards.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT rmp.role_id, p_new.id
FROM t_sys_role_mobile_permission rmp
JOIN t_sys_mobile_permission p_old ON p_old.id = rmp.mobile_permission_id
                                   AND p_old.permission_code = 'access_qurbani_mode'
JOIN t_sys_mobile_permission p_new ON p_new.permission_code = 'view_qurbani_invoices'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);


-- ─── Section 3: verification ─────────────────────────────────
-- Run this after to confirm — the row count should match the
-- number of roles that already had access_qurbani_mode.
-- SELECT r.urole_name, mp.permission_code
-- FROM t_sys_role_mobile_permission rmp
-- JOIN t_sys_role r              ON r.id = rmp.role_id
-- JOIN t_sys_mobile_permission mp ON mp.id = rmp.mobile_permission_id
-- WHERE mp.permission_code IN ('access_qurbani_mode', 'view_qurbani_invoices')
-- ORDER BY r.urole_name, mp.permission_code;
