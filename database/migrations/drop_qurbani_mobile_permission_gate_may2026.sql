-- =====================================================
-- Migration: Drop mobile permission gate on Qurbani request category
-- Created: May 2026
-- =====================================================
-- The original `add_qurbani_request_category_may2026.sql` migration set
-- mobile_permission_code = 'expense_type_qurbani' on the qurbani request
-- category, which gates visibility in the mobile expense flow.
--
-- The permission row was inserted but never granted to any role, so in
-- practice nobody could see the Qurbani request type in the mobile
-- expense screen. The web (Expense Management) doesn't honour this
-- permission gate, which is why the type appears there but not on mobile.
--
-- Since Qurbani expenses are a normal operational category (paying out
-- vendors, transport, etc. during the Qurbani season), they should be
-- available to anyone who already has expense access on mobile. This
-- migration NULLs the permission gate so the category surfaces for
-- everyone in /rider/requests/categories.
--
-- The permission row 'expense_type_qurbani' is left in place — if the
-- admin wants to re-gate Qurbani expenses to a specific role later,
-- they can re-enable the column and grant the permission to that role.
-- =====================================================

UPDATE t_req_category
SET mobile_permission_code = NULL,
    updated_at = NOW()
WHERE category_code = 'qurbani';


-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT id, category_code, category_name, show_in_expenses, mobile_permission_code, is_active
FROM t_req_category
WHERE category_code = 'qurbani';
