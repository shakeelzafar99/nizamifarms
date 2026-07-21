-- ============================================================================
-- Add the MOBILE permission for the "route re-timed mid-run" alert
-- Nizami Farms · ETA accountability · 2026-07-16
--
-- WHAT THIS DOES
--   1. Registers one MOBILE permission in the catalog (group 'Notifications'):
--          receive_dispatch_alerts
--      It decides who gets the push when a rider re-times his OWN route while
--      already part-way through his deliveries. That is the press that moves the
--      goalposts: the customers behind him were promised times that quietly
--      change. The reports protect the PROMISE itself (EtaPromiseService) — this
--      alert is so the store hears about it WHILE it happens, with his name.
--   2. Grants it to every role that can already see the store board
--      (i.e. holds `view_open_orders`) — which today is exactly:
--          Management, Shabib, Taimur, System Access Group, expense fund,
--          supervisor, supervisor 2.
--
-- WHY IT TARGETS view_open_orders INSTEAD OF NAMES OR IDs
--   No ids to copy and no role names to keep in sync: "can watch the store
--   board" IS the definition of who can act on this alert, so the grant derives
--   itself. That also means this script runs UNCHANGED on production even if the
--   role names or ids differ there — nothing to check first, nothing to edit.
--
-- AFTERWARDS = NO CODE, NO SQL
--   Tune it from the UI: Roles -> Manage Mobile Permissions -> Notifications.
--   Untick any role that shouldn't be buzzed (e.g. 'expense fund'), or tick a
--   new one. This script only seeds the starting set; it does not keep the two
--   permissions in step afterwards, which is intended — the UI is the owner.
--
-- SAFETY
--   * Fully idempotent (INSERT ... WHERE NOT EXISTS) — safe to run repeatedly.
--   * Data only. No schema change, no downtime, nothing dropped or updated.
--   * The app is fail-safe without this script: with no role holding the
--     permission nobody is pushed, and dispatch/reporting are unaffected.
--   * Run on LOCAL, check STEP 3, then run the SAME file on PROD.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- STEP 1 — Catalog: register the permission code (only if missing)
-- ----------------------------------------------------------------------------
INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'receive_dispatch_alerts', 'Receive Dispatch Alerts', 'Notifications',
       'Get a push when a rider re-times his own route mid-run (after he has already delivered stops).',
       213, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'receive_dispatch_alerts'
);

-- ----------------------------------------------------------------------------
-- STEP 2 — Grant it to every role that can already see the store board.
--   Self-targeting: no ids, no names. Idempotent.
-- ----------------------------------------------------------------------------
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT DISTINCT board.role_id, mp_new.id, NOW()
FROM t_sys_role_mobile_permission AS board
JOIN t_sys_mobile_permission AS mp_board
      ON mp_board.id = board.mobile_permission_id
     AND mp_board.permission_code = 'view_open_orders'
JOIN t_sys_mobile_permission AS mp_new
      ON mp_new.permission_code = 'receive_dispatch_alerts'
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_mobile_permission rmp
    WHERE rmp.role_id = board.role_id
      AND rmp.mobile_permission_id = mp_new.id
);

-- ----------------------------------------------------------------------------
-- STEP 3 — VERIFY. Expect one row per store-board role (incl. supervisor and
--   supervisor 2). Untick any you don't want from the UI afterwards.
-- ----------------------------------------------------------------------------
SELECT r.id AS role_id, r.urole_name
FROM t_sys_mobile_permission mp
JOIN t_sys_role_mobile_permission rmp ON rmp.mobile_permission_id = mp.id
JOIN t_sys_role r ON r.id = rmp.role_id
WHERE mp.permission_code = 'receive_dispatch_alerts'
ORDER BY r.urole_name;

-- Zero rows above would mean no role holds `view_open_orders` on this database
-- (not the case on dev or prod today). If that ever happens, grant it by hand
-- from the UI instead.
-- ============================================================================
