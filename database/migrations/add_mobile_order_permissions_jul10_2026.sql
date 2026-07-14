-- ============================================================================
-- Phase 0.7 — Decouple MOBILE order creation / Shopify view from WEB permissions
-- Nizami Farms · Sidebar+Permissions UX revamp · 2026-07-10
--
-- WHAT THIS DOES
--   1. Adds two MOBILE permission codes to the catalog:
--        create_orders        (create orders/invoices from the app)
--        view_shopify_orders  (view the Shopify queue from the app)
--   2. Mirror-grants each new MOBILE code to EVERY role that already holds the
--      matching WEB permission (is_allowed = 1). This means NO role's current
--      ability to create orders or view Shopify changes — it is simply expressed
--      through the mobile permission system too.
--
-- WHY
--   Today the mobile app's order-create (POST /api/orders) and Shopify-list
--   (GET /api/rider/orders?source=shopify) are gated by WEB permissions, so a
--   mobile-only user is blocked. The matching OrderController change gates the
--   mobile branch with "mobile OR web", and this script seeds the mobile grants.
--
-- SAFETY
--   * Fully idempotent — safe to run more than once.
--   * Order of deploy does not matter: the code uses "mobile OR web", so running
--     this before OR after the OrderController deploy is safe. Nobody is ever
--     locked out during the transition.
--   * Run on LOCAL first, verify with the SELECTs at the bottom, then run on PROD.
--   * No schema changes; data only. No downtime.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1) Catalog: add the two mobile permission codes (only if missing)
-- ----------------------------------------------------------------------------
INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'create_orders', 'Create Orders', 'store_mode_orders',
       'Create new orders / invoices from the app (store mode).', 17, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'create_orders'
);

INSERT INTO t_sys_mobile_permission
    (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at)
SELECT 'view_shopify_orders', 'View Shopify Orders', 'store_mode_orders',
       'View the Shopify order queue from the app (store mode).', 19, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'view_shopify_orders'
);

-- ----------------------------------------------------------------------------
-- 2) Mirror-grant: every role holding the WEB key (is_allowed = 1) gets the
--    matching MOBILE grant. DISTINCT guards against duplicate source rows;
--    NOT EXISTS guards against re-runs.
-- ----------------------------------------------------------------------------
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT DISTINCT rp.role_id, mp.id, NOW()
FROM t_sys_role_permissions rp
JOIN t_sys_mobile_permission mp
      ON mp.permission_code = rp.permission_key
WHERE rp.permission_key IN ('create_orders', 'view_shopify_orders')
  AND rp.is_allowed = 1
  AND NOT EXISTS (
      SELECT 1 FROM t_sys_role_mobile_permission rmp
      WHERE rmp.role_id = rp.role_id
        AND rmp.mobile_permission_id = mp.id
  );

-- ----------------------------------------------------------------------------
-- 3) VERIFY — run these two SELECTs; the grant count per key must match
--    between web and mobile after this script.
-- ----------------------------------------------------------------------------
-- SELECT 'web' AS side, permission_key AS code, COUNT(*) AS grants
--   FROM t_sys_role_permissions
--   WHERE permission_key IN ('create_orders','view_shopify_orders') AND is_allowed = 1
--   GROUP BY permission_key
-- UNION ALL
-- SELECT 'mobile' AS side, mp.permission_code, COUNT(*)
--   FROM t_sys_role_mobile_permission rmp
--   JOIN t_sys_mobile_permission mp ON mp.id = rmp.mobile_permission_id
--   WHERE mp.permission_code IN ('create_orders','view_shopify_orders')
--   GROUP BY mp.permission_code;
-- ============================================================================
