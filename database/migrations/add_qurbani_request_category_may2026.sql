-- =====================================================
-- Migration: Request Type Management Enhancements
-- + Add Qurbani Request Category
-- Created: May 2026
-- =====================================================
-- RUN EACH BLOCK SEPARATELY if your client doesn't support multi-statement execution.
-- The ALTER TABLE statements are safe to re-run (will error harmlessly if column exists).
-- =====================================================


-- =====================================================
-- STEP 1: Add new columns to t_req_category
-- Run these one at a time. If a column already exists, it will error — just skip to next.
-- =====================================================

ALTER TABLE t_req_category ADD COLUMN show_in_expenses TINYINT(1) NOT NULL DEFAULT 0 AFTER sequence_order;
ALTER TABLE t_req_category ADD COLUMN expense_bu_type VARCHAR(20) DEFAULT NULL AFTER show_in_expenses;
ALTER TABLE t_req_category ADD COLUMN form_type VARCHAR(30) NOT NULL DEFAULT 'general' AFTER expense_bu_type;
ALTER TABLE t_req_category ADD COLUMN mobile_permission_code VARCHAR(100) DEFAULT NULL AFTER form_type;


-- =====================================================
-- STEP 2: Backfill existing categories
-- =====================================================

UPDATE t_req_category SET show_in_expenses = 1, expense_bu_type = 'nf', form_type = 'expense'
WHERE category_code = 'expense';

UPDATE t_req_category SET show_in_expenses = 1, expense_bu_type = 'nf', form_type = 'salary'
WHERE category_code = 'salary_advance';

UPDATE t_req_category SET show_in_expenses = 1, expense_bu_type = 'nf', form_type = 'leave'
WHERE category_code = 'leave';

UPDATE t_req_category SET show_in_expenses = 1, expense_bu_type = 'khaas', form_type = 'expense'
WHERE category_code = 'khaas_expense';


-- =====================================================
-- STEP 3: Create 'qurbani' request category (if not already created)
-- =====================================================

INSERT INTO t_req_category (
    category_code, category_name, description, icon, color_class,
    is_active, sequence_order, show_in_expenses, expense_bu_type, form_type, mobile_permission_code,
    created_at, updated_at
)
VALUES (
    'qurbani', 'Qurbani', 'Qurbani-related requests and expenses', '🐄', 'bg-amber-500',
    1, 20, 1, 'nf', 'expense', 'expense_type_qurbani',
    NOW(), NOW()
)
ON DUPLICATE KEY UPDATE 
    show_in_expenses = 1,
    expense_bu_type = 'nf',
    form_type = 'expense',
    mobile_permission_code = 'expense_type_qurbani',
    updated_at = NOW();


-- =====================================================
-- STEP 4: Create approval config for qurbani
-- =====================================================

INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at, updated_at)
SELECT 
    (SELECT id FROM t_req_category WHERE category_code = 'qurbani'),
    COALESCE(eac.requires_level_1, 1),
    COALESCE(eac.requires_level_2, 0),
    eac.auto_approve_threshold,
    NOW(),
    NOW()
FROM t_req_category ec
LEFT JOIN t_req_category_approval_config eac ON eac.category_id = ec.id
WHERE ec.category_code = 'expense'
LIMIT 1
ON DUPLICATE KEY UPDATE 
    requires_level_1 = VALUES(requires_level_1),
    requires_level_2 = VALUES(requires_level_2),
    updated_at = NOW();


-- =====================================================
-- STEP 5: Add mobile permission for qurbani expense type
-- =====================================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, description, permission_group, is_active, created_at, updated_at)
VALUES ('expense_type_qurbani', 'Qurbani Expenses', 'Allow creating Qurbani expense requests in store mode', 'store_mode_finance', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), updated_at = NOW();


-- =====================================================
-- STEP 6: Add request_category_code column to t_fin_config
-- (links expense sub-categories to specific request types)
-- =====================================================

ALTER TABLE t_fin_config ADD COLUMN request_category_code VARCHAR(50) DEFAULT NULL AFTER business_unit_id;


-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT id, category_code, category_name, show_in_expenses, expense_bu_type, form_type, mobile_permission_code, is_active
FROM t_req_category ORDER BY sequence_order;
