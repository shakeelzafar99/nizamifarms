-- =====================================================
-- Add Finance Request Categories
-- =====================================================
-- Purpose: Add categories for Financial Transactions
-- so they can be configured in Request Settings
-- with L1/L2 approval assignments
-- =====================================================

-- Check if categories already exist before inserting
INSERT INTO t_req_category (category_code, category_name, description, icon, color, is_active, created_at)
SELECT * FROM (
    SELECT 
        'employee_deposit' as category_code,
        'Employee Deposit' as category_name,
        'Employee depositing cash to company (NF Cash, Online, etc.)' as description,
        '💵' as icon,
        'blue' as color,
        1 as is_active,
        NOW() as created_at
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM t_req_category WHERE category_code = 'employee_deposit'
);

INSERT INTO t_req_category (category_code, category_name, description, icon, color, is_active, created_at)
SELECT * FROM (
    SELECT 
        'vendor_payment' as category_code,
        'Vendor Payment' as category_name,
        'Payment to vendors for purchases' as description,
        '💸' as icon,
        'red' as color,
        1 as is_active,
        NOW() as created_at
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM t_req_category WHERE category_code = 'vendor_payment'
);

INSERT INTO t_req_category (category_code, category_name, description, icon, color, is_active, created_at)
SELECT * FROM (
    SELECT 
        'account_transfer' as category_code,
        'Account Transfer' as category_name,
        'Transfer funds between accounts' as description,
        '🔄' as icon,
        'purple' as color,
        1 as is_active,
        NOW() as created_at
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM t_req_category WHERE category_code = 'account_transfer'
);

INSERT INTO t_req_category (category_code, category_name, description, icon, color, is_active, created_at)
SELECT * FROM (
    SELECT 
        'invoice_approval' as category_code,
        'Invoice Approval' as category_name,
        'Online invoices requiring approval' as description,
        '📄' as icon,
        'green' as color,
        1 as is_active,
        NOW() as created_at
) AS tmp
WHERE NOT EXISTS (
    SELECT 1 FROM t_req_category WHERE category_code = 'invoice_approval'
);

SELECT '✓ Added finance request categories' as Status;

-- =====================================================
-- Add default approval configurations for new categories
-- =====================================================

-- Employee Deposit: Requires L1 only (Manager approval)
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at)
SELECT 
    id,
    1 as requires_level_1,
    0 as requires_level_2,
    NULL as auto_approve_threshold,
    NOW() as created_at
FROM t_req_category 
WHERE category_code = 'employee_deposit'
AND NOT EXISTS (
    SELECT 1 FROM t_req_category_approval_config 
    WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'employee_deposit')
);

-- Vendor Payment: Requires L1 only
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at)
SELECT 
    id,
    1 as requires_level_1,
    0 as requires_level_2,
    NULL as auto_approve_threshold,
    NOW() as created_at
FROM t_req_category 
WHERE category_code = 'vendor_payment'
AND NOT EXISTS (
    SELECT 1 FROM t_req_category_approval_config 
    WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'vendor_payment')
);

-- Account Transfer: Requires L1 and L2 (higher scrutiny)
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at)
SELECT 
    id,
    1 as requires_level_1,
    1 as requires_level_2,
    NULL as auto_approve_threshold,
    NOW() as created_at
FROM t_req_category 
WHERE category_code = 'account_transfer'
AND NOT EXISTS (
    SELECT 1 FROM t_req_category_approval_config 
    WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'account_transfer')
);

-- Invoice Approval: Requires L1 only
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at)
SELECT 
    id,
    1 as requires_level_1,
    0 as requires_level_2,
    NULL as auto_approve_threshold,
    NOW() as created_at
FROM t_req_category 
WHERE category_code = 'invoice_approval'
AND NOT EXISTS (
    SELECT 1 FROM t_req_category_approval_config 
    WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'invoice_approval')
);

SELECT '✓ Added default approval configurations' as Status;

-- =====================================================
-- Verification Query
-- =====================================================

SELECT 
    c.category_code,
    c.category_name,
    c.icon,
    c.color,
    c.is_active,
    CASE WHEN ac.requires_level_1 = 1 THEN '✓ L1' ELSE '✗' END as 'Level 1',
    CASE WHEN ac.requires_level_2 = 1 THEN '✓ L2' ELSE '✗' END as 'Level 2'
FROM t_req_category c
LEFT JOIN t_req_category_approval_config ac ON c.id = ac.category_id
WHERE c.category_code IN ('employee_deposit', 'vendor_payment', 'account_transfer', 'invoice_approval', 'expense')
ORDER BY c.category_code;

SELECT '✓ Finance Request Categories Setup Complete!' as Status;
SELECT '✓ Go to Requests → Settings to configure L1/L2 approvers' as Note;

