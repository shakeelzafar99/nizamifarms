-- ============================================================================
-- Normalize t_sys_role.is_active  ('Y' -> '1')
-- Nizami Farms · 2026-07-16
--
-- THE PROBLEM
--   t_sys_role.is_active is CHAR(1) and holds a MIX of two conventions:
--       '1' × 11 roles   (the current convention)
--       'Y' × 2  roles   (Business Access Group, System Access Group — legacy)
--       '0' × 1  role    (Old Riders — deliberately inactive)
--   An earlier normalization converted t_sys_user and most roles but left these
--   two behind.
--
--   Every place the app reads this column compares it to the NUMBER 1:
--       app/Http/Controllers/SysAdmin/UserController.php:47      (role dropdown)
--       app/Http/Controllers/CRM/OrderStatusController.php:536   (status visibility roles)
--       app/Http/Controllers/Request/RequestSettingsController.php:40
--   MySQL casts a string to a number for that comparison, so '1' = 1 is TRUE but
--   'Y' casts to 0 and is FALSE. The two legacy roles are therefore treated as
--   INACTIVE today and are silently missing from all three pickers — even though
--   'Y' meant ACTIVE in the old convention. This script restores the intent.
--
-- SCOPE — READ THIS BEFORE COPYING THIS SCRIPT ANYWHERE ELSE
--   ONLY t_sys_role is mixed, and ONLY t_sys_role may be normalized.
--   Other tables (t_sys_menu, t_sys_email_template, t_sys_package,
--   t_sys_payment_method, t_sys_user_log, t_pdm_product) still use Y/N
--   CONSISTENTLY and their code compares to the STRING 'Y' — e.g.
--   app/Models/SysAdmin/MenuModel.php:101. Converting those would break them.
--   t_sys_user is already clean (only '1'/'0').
--
-- WHAT CHANGES, IN PRACTICE
--   Business Access Group and System Access Group start appearing in the three
--   role pickers above. Nothing else reads this column, so there is no effect on
--   login, permissions or menus (those resolve via t_sys_user_role and the
--   permission tables, which never look at role.is_active). No role gains or
--   loses any access.
--
-- SAFETY
--   * Idempotent — safe to re-run (the UPDATEs match nothing the second time).
--   * Data + column default only. No column TYPE change (CHAR(1) is kept, so
--     nothing that writes '1'/'0' or 1/0 is affected).
--   * STEP 1 is a preview: run it first and eyeball the two rows.
--   * Reversible: UPDATE t_sys_role SET is_active='Y' WHERE id IN (<the ids>).
--   * Run on LOCAL, check STEP 4, then run the SAME file on PROD.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- STEP 1 — PREVIEW. Expect exactly the 2 legacy roles. Run this alone first.
-- ----------------------------------------------------------------------------
SELECT id, urole_name, is_active AS current_value
FROM t_sys_role
WHERE is_active IN ('Y', 'N')
ORDER BY urole_name;

-- ----------------------------------------------------------------------------
-- STEP 2 — Normalize the values.
--   'Y' (active, old convention)   -> '1'
--   'N' (inactive, old convention) -> '0'   [no such rows today; here so the
--                                            script stays correct if one appears]
-- ----------------------------------------------------------------------------
UPDATE t_sys_role SET is_active = '1' WHERE is_active = 'Y';
UPDATE t_sys_role SET is_active = '0' WHERE is_active = 'N';

-- ----------------------------------------------------------------------------
-- STEP 3 — Fix the column DEFAULT, which is still the legacy 'Y'.
--   Any future INSERT that omits is_active would otherwise create a role that is
--   born invisible to all three pickers — the same bug, again. (The app itself
--   always sends a value: RoleController.php:56/85 use `$request->is_active ?? 1`.
--   This is belt-and-braces for hand-written SQL.)
--   ALTER COLUMN ... SET DEFAULT changes ONLY the default: the type, NOT NULL
--   and all existing data are untouched, and it does not rebuild the table.
-- ----------------------------------------------------------------------------
ALTER TABLE t_sys_role ALTER COLUMN is_active SET DEFAULT '1';

-- ----------------------------------------------------------------------------
-- STEP 4 — VERIFY.
--   4a) Expect ONLY '1' and '0'. Any 'Y'/'N' left = STEP 2 did not run.
-- ----------------------------------------------------------------------------
SELECT is_active, COUNT(*) AS roles
FROM t_sys_role
GROUP BY is_active
ORDER BY is_active;

--   4b) The two repaired roles should now read '1'.
SELECT id, urole_name, is_active
FROM t_sys_role
WHERE urole_name IN ('Business Access Group', 'System Access Group')
ORDER BY urole_name;

--   4c) The new default should show '1'.
SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_sys_role'
  AND COLUMN_NAME = 'is_active';
-- ============================================================================
