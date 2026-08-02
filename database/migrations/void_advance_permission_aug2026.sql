-- ============================================================================
-- Void-salary-advance permission (August 2026) — `void_salary_advance`
--
-- Gates the 🗑 Void button + POST /hr/payroll/void-advance on the WEB payroll
-- page. Voiding un-does a wrongly-given advance: it reverses the advance's
-- ledger entry through BalancePostingService (money returns to NF Cash / the
-- bank it left from) and cancels the request.
--
-- WEB ONLY — there is deliberately no mobile void, so no t_sys_mobile_permission
-- row is needed here (unlike manage_payroll). Mobile screens self-correct: they
-- all filter status='approved', so a voided advance simply disappears.
--
-- Granted to: role 14 (Taimur) ONLY — verified 2026-08-02 as a single-member
-- role (user 68, Taimur). This is a ROLE grant, so anyone assigned that role
-- later inherits the access automatically, which is the owner's intent.
--
-- NOTE this is deliberately NARROWER than `manage_payroll` (roles 10 + 14):
-- role 10 (Management) can run payroll and give advances but cannot void one.
-- To widen later, run the same INSERT with a different role_id, or use
-- web Roles → Permissions (the key appears there automatically after deploy).
--
-- Run once on LOCAL, then on PROD (manual). Idempotent — safe to re-run.
-- ⚠ Deploy ORDER: this SQL + the code upload must go together with the payroll
-- module, because the same upload carries the salary_advance delete-block in
-- ExpenseManagementController::destroy and RiderController::deleteExpense.
-- ============================================================================

INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 14, 'void_salary_advance', 'Void a salary advance (owner: returns the money & cancels it)', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM t_sys_role_permissions WHERE role_id = 14 AND permission_key = 'void_salary_advance'
);

-- Verify (expect exactly one row, role 14):
-- SELECT role_id, permission_key, is_allowed FROM t_sys_role_permissions
--  WHERE permission_key = 'void_salary_advance';
