-- =====================================================
-- Migration: Add Khaas Expense Request Category
-- Created: February 16, 2026
-- =====================================================
--
-- This SQL adds:
-- ✅ 'khaas_expense' request category in t_req_category
-- ✅ Approval config for khaas_expense (copies from expense)
-- ✅ business_unit_id column on t_fin_config for expense categories
-- ✅ Default existing expense categories to BU 1 (Nizami Farms)
--
-- PURPOSE:
-- - Khaas mode should only show 'khaas_expense' as the request type
-- - Expense categories are filtered by business_unit_id
-- - Only 'taimur' profile can create new Khaas expense categories
-- =====================================================


-- =====================================================
-- STEP 1: Create 'khaas_expense' request category
-- =====================================================

INSERT INTO t_req_category (category_code, category_name, description, icon, color_class, is_active, sequence_order, created_at, updated_at)
VALUES ('khaas_expense', 'Khaas Expense', 'Expense request for Khaas business unit', '🌿', 'bg-emerald-500', 1, 15, NOW(), NOW())
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name), description = VALUES(description), updated_at = NOW();

SELECT '✅ Step 1: Created khaas_expense request category' as Status;


-- =====================================================
-- STEP 2: Create approval config for khaas_expense
-- (Copy from expense category config if exists)
-- =====================================================

-- First get the expense category's approval config
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at, updated_at)
SELECT 
    (SELECT id FROM t_req_category WHERE category_code = 'khaas_expense'),
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

SELECT '✅ Step 2: Created approval config for khaas_expense (copied from expense)' as Status;


-- =====================================================
-- STEP 3: Add business_unit_id column to t_fin_config
-- (for categorizing expense categories by BU)
-- =====================================================

-- Check if column already exists before adding
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_fin_config' 
    AND COLUMN_NAME = 'business_unit_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE t_fin_config ADD COLUMN business_unit_id INT NULL DEFAULT NULL AFTER description',
    'SELECT "Column business_unit_id already exists in t_fin_config" as Info');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✅ Step 3: Added business_unit_id column to t_fin_config' as Status;


-- =====================================================
-- STEP 4: Default all existing expense categories to BU 1 (Nizami Farms)
-- =====================================================

UPDATE t_fin_config 
SET business_unit_id = 1 
WHERE config_key LIKE 'EXPENSE_CATEGORY_%' 
AND business_unit_id IS NULL;

SELECT '✅ Step 4: Defaulted existing expense categories to BU 1 (Nizami Farms)' as Status;


-- =====================================================
-- VERIFICATION & SUMMARY
-- =====================================================

SELECT 'Summary of add_khaas_expense_category_feb16_2026.sql migration:' as Summary;

-- Show new category
SELECT 'New Request Category:' as '';
SELECT id, category_code, category_name, description, is_active
FROM t_req_category
WHERE category_code = 'khaas_expense';

-- Show approval config
SELECT 'Approval Config:' as '';
SELECT c.category_code, c.category_name, ac.requires_level_1, ac.requires_level_2, ac.auto_approve_threshold
FROM t_req_category c
LEFT JOIN t_req_category_approval_config ac ON ac.category_id = c.id
WHERE c.category_code IN ('expense', 'khaas_expense');

-- Show expense categories with BU
SELECT 'Expense Categories with Business Unit:' as '';
SELECT config_key, config_value, business_unit_id
FROM t_fin_config
WHERE config_key LIKE 'EXPENSE_CATEGORY_%'
ORDER BY business_unit_id, config_value;
