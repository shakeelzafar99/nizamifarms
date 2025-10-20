-- Verify that the 'expense' category exists in t_req_category
-- This is required for the Short Cash Settlement feature to work

SELECT 
    '=== Checking for Expense Category ===' as '';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Expense category exists'
        ELSE '✗ ERROR: Expense category NOT FOUND - Run approval_workflow_schema.sql'
    END as Status,
    COUNT(*) as Count
FROM t_req_category
WHERE category_code = 'expense';

-- Show the expense category details if it exists
SELECT 
    '=== Expense Category Details ===' as '';

SELECT 
    id,
    category_code,
    category_name,
    description,
    is_active,
    created_at
FROM t_req_category
WHERE category_code = 'expense';

-- If the category doesn't exist, insert it
INSERT INTO t_req_category (category_code, category_name, description, icon, color_class, sequence_order, is_active, created_by, created_at, updated_at)
SELECT 
    'expense',
    'Expense Reimbursement',
    'Expense reimbursement requests',
    'receipt',
    'purple',
    3,
    1,
    1,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM t_req_category WHERE category_code = 'expense'
);

SELECT 
    '=== Final Verification ===' as '';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Expense category is now available'
        ELSE '✗ ERROR: Failed to create expense category'
    END as Status
FROM t_req_category
WHERE category_code = 'expense';

