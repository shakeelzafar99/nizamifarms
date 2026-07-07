-- ============================================================================
-- Normalize t_sys_user.is_active — unify the legacy 'Y' value to '1'.
-- ----------------------------------------------------------------------------
-- The column is CHAR and holds mixed values: '1' (active), '0' (inactive), and a
-- legacy 'Y' (3 rows). The whole app filters with `is_active = 1`, which matches
-- '1' but NOT 'Y' — so 'Y' users are wrongly treated as INACTIVE everywhere
-- (invisible in attendance, rider lists, dropdowns). This sets them to '1'.
--
-- Independent of the shift/rider changes — safe to run ANY time. Run it BEFORE
-- re-running rider_list_backfill_jul2026.sql if you want any newly-active riders
-- picked up. Safe to re-run.
--
-- 1) SEE who will change (run first, eyeball the names — should be real active people):
--    SELECT id, fullname, email, is_active FROM t_sys_user WHERE is_active = 'Y';
--
-- 2) APPLY:
UPDATE t_sys_user SET is_active = '1' WHERE is_active = 'Y';

-- 3) VERIFY (should be only '1' and '0' now):
--    SELECT is_active, COUNT(*) FROM t_sys_user GROUP BY is_active;
