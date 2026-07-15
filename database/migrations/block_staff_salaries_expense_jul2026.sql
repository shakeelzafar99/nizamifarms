-- ============================================================================
-- Block the legacy "Staff Salaries" expense category (July 2026)
-- ----------------------------------------------------------------------------
-- Salaries are now paid exclusively from the Payroll screen (t_hr_payroll_payment),
-- which records them as an expense (see LedgerKpiService / ExpenseManagementController).
-- The old path — logging a "Staff Salaries" expense request — is a duplicate-entry
-- risk, so remove the category from the picker.
--
-- Left UNTOUCHED on purpose:
--   * historical "Staff Salaries" expense rows in t_req_master (past reports intact)
--   * the EXP_STAFF_SALARIES ledger account (still referenced by those old rows)
--
-- Safe to run on LOCAL first, then PROD (manual). Idempotent.
-- NOTE: the backend also HARD-REJECTS any submission using this category
-- (RequestController::store, RiderController::createRequest/getExpenseCategories,
-- ExpenseCategoryController::store), so this DELETE is only menu cleanup — the
-- block does not depend on it.
-- ============================================================================

DELETE FROM t_fin_config
WHERE config_key = 'EXPENSE_CATEGORY_STAFF_SALARIES';

SELECT CONCAT('Remaining EXPENSE_CATEGORY_STAFF_SALARIES rows (expected 0): ', COUNT(*)) AS status
FROM t_fin_config
WHERE config_key = 'EXPENSE_CATEGORY_STAFF_SALARIES';
