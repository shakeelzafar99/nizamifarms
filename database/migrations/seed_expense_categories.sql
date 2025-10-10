-- =====================================================
-- Seed Initial Expense Categories
-- =====================================================
-- This script creates all the expense categories that were
-- hardcoded in the application, along with their corresponding
-- expense accounts in the ledger system.
-- =====================================================

-- Insert Expense Categories into Config Table
-- Format: EXPENSE_CATEGORY_[NAME] → [Display Name]

INSERT INTO t_fin_config (config_key, config_value, description, created_at) VALUES
('EXPENSE_CATEGORY_PETROL', 'Petrol', 'Expense category: Petrol. Account: EXP_PETROL', NOW()),
('EXPENSE_CATEGORY_RENT', 'Rent', 'Expense category: Rent. Account: EXP_RENT', NOW()),
('EXPENSE_CATEGORY_UTILITY_BILLS', 'Utility Bills', 'Expense category: Utility Bills. Account: EXP_UTILITY_BILLS', NOW()),
('EXPENSE_CATEGORY_PACKAGING_SHRINK_WRAP', 'Packaging - Shrink wrap', 'Expense category: Packaging - Shrink wrap. Account: EXP_PACKAGING_SHRINK_WRAP', NOW()),
('EXPENSE_CATEGORY_PACKAGING_BAGS', 'Packaging - Bags', 'Expense category: Packaging - Bags. Account: EXP_PACKAGING_BAGS', NOW()),
('EXPENSE_CATEGORY_FOOD', 'Food', 'Expense category: Food. Account: EXP_FOOD', NOW()),
('EXPENSE_CATEGORY_OFFICE_SUPPLIES', 'Office Supplies', 'Expense category: Office Supplies. Account: EXP_OFFICE_SUPPLIES', NOW()),
('EXPENSE_CATEGORY_MAINTENANCE', 'Maintenance', 'Expense category: Maintenance. Account: EXP_MAINTENANCE', NOW()),
('EXPENSE_CATEGORY_TRANSPORTATION', 'Transportation', 'Expense category: Transportation. Account: EXP_TRANSPORTATION', NOW()),
('EXPENSE_CATEGORY_COMMUNICATION', 'Communication', 'Expense category: Communication. Account: EXP_COMMUNICATION', NOW()),
('EXPENSE_CATEGORY_MARKETING', 'Marketing', 'Expense category: Marketing. Account: EXP_MARKETING', NOW()),
('EXPENSE_CATEGORY_INSURANCE', 'Insurance', 'Expense category: Insurance. Account: EXP_INSURANCE', NOW()),
('EXPENSE_CATEGORY_PROFESSIONAL_FEES', 'Professional Fees', 'Expense category: Professional Fees. Account: EXP_PROFESSIONAL_FEES', NOW()),
('EXPENSE_CATEGORY_BANK_CHARGES', 'Bank Charges', 'Expense category: Bank Charges. Account: EXP_BANK_CHARGES', NOW()),
('EXPENSE_CATEGORY_STAFF_SALARIES', 'Staff Salaries', 'Expense category: Staff Salaries. Account: EXP_STAFF_SALARIES', NOW()),
('EXPENSE_CATEGORY_MISCELLANEOUS', 'Miscellaneous', 'Expense category: Miscellaneous. Account: EXP_MISCELLANEOUS', NOW())
ON DUPLICATE KEY UPDATE 
    config_value = VALUES(config_value),
    description = VALUES(description);

SELECT '✓ Inserted/Updated 16 expense categories in t_fin_config' as Status;

-- =====================================================
-- Create Corresponding Expense Accounts
-- =====================================================

INSERT INTO t_fin_accounts (
    account_code,
    account_name,
    account_type,
    account_category,
    opening_balance,
    current_balance,
    is_active,
    created_at,
    created_by
) VALUES
('EXP_PETROL', 'Expense - Petrol', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_RENT', 'Expense - Rent', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_UTILITY_BILLS', 'Expense - Utility Bills', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_PACKAGING_SHRINK_WRAP', 'Expense - Packaging - Shrink wrap', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_PACKAGING_BAGS', 'Expense - Packaging - Bags', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_FOOD', 'Expense - Food', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_OFFICE_SUPPLIES', 'Expense - Office Supplies', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_MAINTENANCE', 'Expense - Maintenance', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_TRANSPORTATION', 'Expense - Transportation', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_COMMUNICATION', 'Expense - Communication', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_MARKETING', 'Expense - Marketing', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_INSURANCE', 'Expense - Insurance', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_PROFESSIONAL_FEES', 'Expense - Professional Fees', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_BANK_CHARGES', 'Expense - Bank Charges', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_STAFF_SALARIES', 'Expense - Staff Salaries', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1),
('EXP_MISCELLANEOUS', 'Expense - Miscellaneous', 'expense', 'expense', 0.00, 0.00, 1, NOW(), 1)
ON DUPLICATE KEY UPDATE 
    account_name = VALUES(account_name),
    is_active = VALUES(is_active);

SELECT '✓ Inserted/Updated 16 expense accounts in t_fin_accounts' as Status;

-- =====================================================
-- Verification Query
-- =====================================================

SELECT 
    c.config_key,
    c.config_value as 'Category Name',
    a.account_code as 'Account Code',
    a.account_name as 'Account Name',
    a.account_type as 'Type',
    a.is_active as 'Active'
FROM t_fin_config c
LEFT JOIN t_fin_accounts a ON CONCAT('EXP_', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.config_value, ' ', '_'), '-', ''), '.', ''), '(', ''), ')', '')) = a.account_code
WHERE c.config_key LIKE 'EXPENSE_CATEGORY_%'
ORDER BY c.config_value;

SELECT '✓ Expense Categories Setup Complete!' as Status;
SELECT '✓ Check the table above to verify all categories have matching accounts' as Note;

-- =====================================================
-- Summary
-- =====================================================

SELECT 
    'Categories' as Item,
    COUNT(*) as Count
FROM t_fin_config
WHERE config_key LIKE 'EXPENSE_CATEGORY_%'

UNION ALL

SELECT 
    'Expense Accounts' as Item,
    COUNT(*) as Count
FROM t_fin_accounts
WHERE account_category = 'expense' AND account_code LIKE 'EXP_%';

