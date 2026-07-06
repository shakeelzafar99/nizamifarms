-- =====================================================================
-- RESTRICTED WEB MENU ("adnan" HQ-only role) — Jul-2026
-- =====================================================================
-- Mechanism: if ANY of a user's roles has a permission_key starting with
-- 'web_menu_', the WEB sidebar shows ONLY the granted sections (+ Logout),
-- and /dashboard redirects the user to /hq. Users with no web_menu_* keys
-- are completely unaffected (normal full menu).
--
-- Available section keys (v1):
--   web_menu_hq          → HQ · Executive (/hq)
--   web_menu_dashboards  → Dashboards (/dashboard)
--   web_menu_invoices    → Invoices (/orders)
--   web_menu_customers   → Customers (/customers)
--   web_menu_finance     → NF Ledger + Expense Management
--
-- SAFE + ADDITIVE: inserts one role (if missing) and one permission row.
-- Run on LOCAL first, then PROD. Idempotent.
--
-- ⚠ NOTE (honest limitation): this hides the MENU and redirects the
-- dashboard — it does not yet hard-block typing other URLs directly.
-- A route-level lock is listed in the Phase-2 plan. Give this login to
-- trusted people only until then.
-- =====================================================================

-- 1) Create the 'adnan' role if it doesn't exist.
INSERT INTO t_sys_role (company_id, type, urole_name, description, is_default, is_active, created_at, created_by)
SELECT 1, 'staff', 'adnan', 'HQ-only web login (restricted web menu)', 'N', 'Y', NOW(), 1
WHERE NOT EXISTS (SELECT 1 FROM t_sys_role WHERE LOWER(urole_name) = 'adnan');

-- 2) Grant the HQ menu key to the adnan role.
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT r.id, 'web_menu_hq', 'Web menu: HQ Executive', 1, NOW(), NOW()
FROM t_sys_role r
WHERE LOWER(r.urole_name) = 'adnan'
  AND NOT EXISTS (
      SELECT 1 FROM t_sys_role_permissions p
      WHERE p.role_id = r.id AND p.permission_key = 'web_menu_hq'
  );

-- 3) OPTIONAL — grant more sections later by running one of these
--    (uncomment the ones you want):
-- INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
-- SELECT r.id, 'web_menu_dashboards', 'Web menu: Dashboards', 1, NOW(), NOW()
-- FROM t_sys_role r WHERE LOWER(r.urole_name)='adnan'
--   AND NOT EXISTS (SELECT 1 FROM t_sys_role_permissions p WHERE p.role_id=r.id AND p.permission_key='web_menu_dashboards');
--
-- INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
-- SELECT r.id, 'web_menu_invoices', 'Web menu: Invoices', 1, NOW(), NOW()
-- FROM t_sys_role r WHERE LOWER(r.urole_name)='adnan'
--   AND NOT EXISTS (SELECT 1 FROM t_sys_role_permissions p WHERE p.role_id=r.id AND p.permission_key='web_menu_invoices');
--
-- INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
-- SELECT r.id, 'web_menu_customers', 'Web menu: Customers', 1, NOW(), NOW()
-- FROM t_sys_role r WHERE LOWER(r.urole_name)='adnan'
--   AND NOT EXISTS (SELECT 1 FROM t_sys_role_permissions p WHERE p.role_id=r.id AND p.permission_key='web_menu_customers');
--
-- INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
-- SELECT r.id, 'web_menu_finance', 'Web menu: Finance', 1, NOW(), NOW()
-- FROM t_sys_role r WHERE LOWER(r.urole_name)='adnan'
--   AND NOT EXISTS (SELECT 1 FROM t_sys_role_permissions p WHERE p.role_id=r.id AND p.permission_key='web_menu_finance');

-- 4) To remove a section again:
-- DELETE p FROM t_sys_role_permissions p
-- JOIN t_sys_role r ON r.id = p.role_id
-- WHERE LOWER(r.urole_name)='adnan' AND p.permission_key='web_menu_invoices';

-- 5) Finally: assign the role to Adnan's user in the normal Users screen
--    (or: INSERT INTO t_sys_user_role (user_id, role_id) VALUES (<adnan_user_id>, (SELECT id FROM t_sys_role WHERE LOWER(urole_name)='adnan')); )

SELECT '✅ adnan role + web_menu_hq ready' AS status;
SELECT r.id, r.urole_name, p.permission_key
FROM t_sys_role r LEFT JOIN t_sys_role_permissions p ON p.role_id = r.id AND p.permission_key LIKE 'web\_menu\_%'
WHERE LOWER(r.urole_name) = 'adnan';
