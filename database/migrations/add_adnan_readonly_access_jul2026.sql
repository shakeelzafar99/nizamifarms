-- =============================================================================
-- adnan → view-only owner access  (Jul-2026)   [USER-TARGETED, guarded]
-- =============================================================================
-- Makes the `adnan` login a VIEW-ONLY owner:
--   1. Opens his sidebar to HQ + Invoices (Analysis) + Customers + Ledger Hub.
--   2. Flags his account read-only (`account_read_only`) so ReadOnlyGuard blocks
--      every state-changing request across the whole web app. He can browse
--      everything but cannot change anything.
--
-- SAFETY — targeted to adnan's USER, cannot affect other admins:
--   * @uid is his user id (92 — same on dev and prod).
--   * @rid is resolved as the role adnan is on that ALREADY carries `web_menu_hq`
--     (his existing HQ-only role). A shared role could not carry web_menu_hq
--     without HQ-locking every user on it, so this is necessarily his own role.
--   * @share counts users on that role. Every write below is gated on
--     (@rid IS NOT NULL AND @share = 1), so if the role were ever shared, the
--     script writes NOTHING. Re-runnable / idempotent.
--
-- Requires the matching code upload (ReadOnlyGuard + bootstrap/app.php, sidebar,
-- /invoices page, etc.). After running, clear caches:
--   php artisan config:clear route:clear view:clear   (or hit /api/public/xclean)
-- -----------------------------------------------------------------------------

SET @uid := 92;  -- adnan (same user id on dev and prod)

-- His dedicated restricted-menu role = the role he's on that already has web_menu_hq.
SET @rid := (
    SELECT rp.role_id
    FROM t_sys_role_permissions rp
    JOIN t_sys_user_role ur ON ur.role_id = rp.role_id
    WHERE ur.user_id = @uid
      AND rp.permission_key = 'web_menu_hq'
      AND rp.is_allowed = 1
    LIMIT 1
);

-- How many users share that role? MUST be 1 (adnan only) for the grant to fire.
SET @share := (SELECT COUNT(*) FROM t_sys_user_role WHERE role_id = @rid);

-- >>> EYEBALL THIS before trusting the result: user should be adnan, users_on_role should be 1.
SELECT @uid AS user_id,
       (SELECT CONCAT(fullname, ' <', email, '>') FROM t_sys_user WHERE id = @uid) AS user,
       @rid AS role_id,
       (SELECT urole_name FROM t_sys_role WHERE id = @rid) AS role_name,
       @share AS users_on_role;

-- Clear the keys we manage here (only when the guard passes), then re-insert.
DELETE FROM t_sys_role_permissions
WHERE role_id = @rid
  AND @rid IS NOT NULL AND @share = 1
  AND permission_key IN (
        'account_read_only',
        'web_menu_invoices_analysis',
        'web_menu_customers',
        'web_menu_finance_hub'
  );

INSERT INTO t_sys_role_permissions
    (role_id, permission_key, permission_name, is_allowed, created_by, updated_by, created_at, updated_at)
SELECT @rid, k.permission_key, k.permission_name, 1, @uid, @uid, NOW(), NOW()
FROM (
        SELECT 'account_read_only'          AS permission_key, 'Account is view-only (blocks all changes)' AS permission_name
  UNION ALL SELECT 'web_menu_invoices_analysis', 'Restricted menu: Invoices — Analysis'
  UNION ALL SELECT 'web_menu_customers',         'Restricted menu: Customers'
  UNION ALL SELECT 'web_menu_finance_hub',       'Restricted menu: Ledger Hub'
) k
WHERE @rid IS NOT NULL AND @share = 1;

-- adnan already holds `web_menu_hq`. Combined, his sidebar now shows:
-- HQ · Executive, Invoices, Customers, Ledger Hub. Everything else stays
-- reachable by URL (read-only) since he is an owner.

-- Verify the final grant (should list the 4 keys above + web_menu_hq, all is_allowed=1):
SELECT permission_key, is_allowed
FROM t_sys_role_permissions
WHERE role_id = @rid
ORDER BY permission_key;

-- -----------------------------------------------------------------------------
-- OPTIONAL (performance) — speeds up the Invoices delivered-date query. Skip if
-- it errors with "Duplicate key name" (means the index already exists).
-- -----------------------------------------------------------------------------
-- CREATE INDEX idx_osh_status_order_changed
--     ON t_crm_order_status_history (status_code, order_id, changed_at);
