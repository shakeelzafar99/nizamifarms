-- =============================================================================
-- Campaigns: split view vs manage, and give the analyst login (adnan) the
-- ability to RUN campaigns — Jul 2026
--
-- Run on LOCAL first, then PROD, alongside `campaigns_revamp_jul2026.sql` and
-- BEFORE uploading the campaign web files. Idempotent — safe to re-run.
--
-- WHY: campaigns now separate two rights.
--   view_campaigns    — open the page and read results (already existed)
--   manage_campaigns  — create / add customers / send / pause / dedup / skip / end
--
-- That split is what lets `adnan` run campaigns. His account is deliberately
-- GLOBALLY VIEW-ONLY (`account_read_only` + the ReadOnlyGuard middleware blocks
-- every non-GET request) so he can browse the whole operational system without
-- being able to change anything. Rather than weaken that lock, ReadOnlyGuard now
-- makes ONE narrow exception: a view-only user holding `manage_campaigns` may
-- POST to /campaigns paths and nothing else. Ledger, orders, customers, vendors
-- etc. stay blocked for him exactly as before.
--
-- ORDER OF PLAY inside this file matters:
--   §2 grants manage_campaigns to EVERY role that can already view campaigns.
--       Without that step, introducing the write permission would take campaign
--       sending away from Taimur/management the moment the code goes up.
--   §3/§4 then add adnan.
-- =============================================================================


-- =========================================================================
-- §1  Create the manage_campaigns permission
-- =========================================================================
INSERT INTO `t_sys_mobile_permission`
    (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`, `is_active`)
VALUES
    ('manage_campaigns', 'Campaigns: Create & Send', 'store_mode_campaigns',
     'Lets the user create campaigns, add customers, send/retry WhatsApp messages, pause sending, refresh dedup, skip customers and end campaigns. Without this a user with view_campaigns can only READ campaigns and their results. Also the one permission that allows a view-only account to act on campaigns (and only campaigns).',
     23, 1)
ON DUPLICATE KEY UPDATE
    permission_name  = VALUES(permission_name),
    permission_group = VALUES(permission_group),
    description      = VALUES(description),
    is_active        = VALUES(is_active);


-- =========================================================================
-- §2  Preserve today's behaviour: everyone who can VIEW can still SEND
-- =========================================================================
-- Every role that currently holds view_campaigns gets manage_campaigns, so no
-- existing operator loses the ability to send. Tighten later per role from the
-- roles screen if you want a read-only campaign viewer.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT DISTINCT rp_view.role_id, p_manage.id
FROM `t_sys_role_mobile_permission` rp_view
JOIN `t_sys_mobile_permission` p_view   ON p_view.id = rp_view.mobile_permission_id
                                       AND p_view.permission_code = 'view_campaigns'
CROSS JOIN `t_sys_mobile_permission` p_manage
WHERE p_manage.permission_code = 'manage_campaigns'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- Admin (role_id = 1) safety net, matching how other campaign permissions were seeded.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT 1, p.id FROM `t_sys_mobile_permission` p
WHERE p.permission_code IN ('view_campaigns', 'manage_campaigns')
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);


-- =========================================================================
-- §3  adnan — grant view + manage campaigns
-- =========================================================================
-- Targeted the same guarded way as `add_adnan_readonly_access_jul2026.sql`:
-- @uid is adnan's user id (92, identical on dev and prod), @rid is the role he
-- is actually on, and @share proves that role is HIS ALONE. If the role is ever
-- shared with another user, @share becomes 0 and every write below inserts
-- nothing — so this can never accidentally hand campaign-sending to a group.
SET @uid := 92;

SET @rid := (
    SELECT ur.role_id
    FROM t_sys_user_role ur
    WHERE ur.user_id = @uid
    ORDER BY ur.role_id
    LIMIT 1
);

SET @share := (
    SELECT CASE WHEN COUNT(DISTINCT ur.user_id) = 1 THEN 1 ELSE 0 END
    FROM t_sys_user_role ur
    WHERE ur.role_id = @rid
);

-- Sanity check before the writes — read this output.
SELECT @uid AS adnan_user_id,
       @rid AS role_id,
       (SELECT urole_name FROM t_sys_role WHERE id = @rid) AS role_name,
       @share AS role_is_adnan_only_must_be_1;

INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT @rid, p.id
FROM `t_sys_mobile_permission` p
WHERE p.permission_code IN ('view_campaigns', 'manage_campaigns')
  AND @share = 1
  AND @rid IS NOT NULL
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);


-- =========================================================================
-- §4  Make the Campaigns menu item visible to adnan
-- =========================================================================
-- adnan is on the curated ("restricted") sidebar: because his role carries
-- web_menu_* keys, the sidebar renders ONLY the granted rows and skips the whole
-- normal menu — so the ordinary Campaigns entry never appears for him no matter
-- what campaign permissions he holds. He needs the `web_menu_campaigns` key AND
-- the matching restricted-menu row added to sidebar.blade.php (done in the same
-- change as this SQL).
--
-- Keys live in t_sys_role_permissions (role_id + permission_key + is_allowed) —
-- note the plural table name, and that it is key-based rather than id-based.
-- The table has a UNIQUE key on (role_id, permission_key), so ON DUPLICATE KEY
-- handles both "already there" and "there but switched off" in one statement.
INSERT INTO `t_sys_role_permissions` (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT @rid, 'web_menu_campaigns', 'Web Menu: Campaigns', 1, NOW(), NOW()
FROM DUAL
WHERE @share = 1
  AND @rid IS NOT NULL
ON DUPLICATE KEY UPDATE
    is_allowed = 1,
    updated_at = NOW();


-- =========================================================================
-- VERIFICATION — run these after the inserts
-- =========================================================================
-- 1) The permission exists and who holds it:
-- SELECT r.id, r.urole_name, MAX(p.permission_code = 'view_campaigns')   AS can_view,
--                            MAX(p.permission_code = 'manage_campaigns') AS can_manage
-- FROM t_sys_role r
-- JOIN t_sys_role_mobile_permission rp ON rp.role_id = r.id
-- JOIN t_sys_mobile_permission p       ON p.id = rp.mobile_permission_id
-- WHERE p.permission_code IN ('view_campaigns','manage_campaigns')
-- GROUP BY r.id, r.urole_name
-- ORDER BY r.urole_name;
--
-- 2) adnan specifically — expect can_view = 1 AND can_manage = 1:
-- SELECT MAX(p.permission_code = 'view_campaigns')   AS can_view,
--        MAX(p.permission_code = 'manage_campaigns') AS can_manage
-- FROM t_sys_user_role ur
-- JOIN t_sys_role_mobile_permission rp ON rp.role_id = ur.role_id
-- JOIN t_sys_mobile_permission p       ON p.id = rp.mobile_permission_id
-- WHERE ur.user_id = 92;
--
-- 3) adnan is still globally view-only (expect 1 — do NOT remove this).
--    NOTE: account_read_only is a WEB key in t_sys_role_permissions, NOT a
--    mobile permission — querying the mobile table returns 0 and looks alarming.
-- SELECT COUNT(*) AS still_read_only
-- FROM t_sys_role_permissions rp
-- JOIN t_sys_user_role ur ON ur.role_id = rp.role_id
-- WHERE ur.user_id = 92
--   AND rp.permission_key = 'account_read_only'
--   AND rp.is_allowed = 1;
--
-- 4) adnan's sidebar keys (expect web_menu_campaigns among them):
-- SELECT rp.permission_key, rp.is_allowed
-- FROM t_sys_role_permissions rp
-- JOIN t_sys_user_role ur ON ur.role_id = rp.role_id
-- WHERE ur.user_id = 92 AND rp.permission_key LIKE 'web\_menu\_%'
-- ORDER BY rp.permission_key;
