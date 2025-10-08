-- Add expense_category column to t_req_master
-- This will store the specific type of expense (Petrol, Rent, Food, etc.)

ALTER TABLE `t_req_master`
ADD COLUMN `expense_category` VARCHAR(255) NULL AFTER `amount`,
ADD INDEX `idx_expense_category` (`expense_category`);

-- Insert seed data for expense categories
-- These are extracted from the legacy expense sheet

INSERT INTO `t_fin_config` (`config_key`, `config_value`, `description`)
VALUES
('EXPENSE_CATEGORIES', '["Petrol","Rent","Utility Bills","Packaging - Shrink wrap","Packaging - Bags","Food","Office Supplies","Maintenance","Transportation","Communication","Marketing","Insurance","Professional Fees","Bank Charges","Miscellaneous","Staff Salary - Ali Raza (Butcher)","Staff Salary - Haider","Staff Salary - Waseem","Staff Salary - Farooq","Staff Salary - Arsalan","Staff Salary - Jazib","Staff Salary - Asim Tahir","Staff Salary - Abdul Malik","Staff Salary - Sajid Ali Khan"]', 
 'List of expense categories for request form dropdown')
ON DUPLICATE KEY UPDATE 
config_value = VALUES(config_value);

-- Create expense accounts for each category if they don't exist
-- These will be used when posting to ledger

INSERT INTO `t_fin_accounts` (`account_code`, `account_name`, `account_type`, `account_category`, `opening_balance`, `current_balance`, `is_active`, `created_by`)
SELECT 
    CONCAT('EXP_', UPPER(REPLACE(REPLACE(REPLACE(category, ' ', '_'), '-', ''), ',', ''))),
    CONCAT('Expense - ', category),
    'expense',
    'expense',
    0.00,
    0.00,
    1,
    1
FROM (
    SELECT 'Petrol' AS category UNION
    SELECT 'Rent' UNION
    SELECT 'Utility Bills' UNION
    SELECT 'Packaging - Shrink wrap' UNION
    SELECT 'Packaging - Bags' UNION
    SELECT 'Food' UNION
    SELECT 'Office Supplies' UNION
    SELECT 'Maintenance' UNION
    SELECT 'Transportation' UNION
    SELECT 'Communication' UNION
    SELECT 'Marketing' UNION
    SELECT 'Insurance' UNION
    SELECT 'Professional Fees' UNION
    SELECT 'Bank Charges' UNION
    SELECT 'Miscellaneous' UNION
    SELECT 'Staff Salaries'
) AS categories
WHERE NOT EXISTS (
    SELECT 1 FROM `t_fin_accounts` 
    WHERE account_code = CONCAT('EXP_', UPPER(REPLACE(REPLACE(REPLACE(category, ' ', '_'), '-', ''), ',', '')))
);

-- Note: The Staff Salary accounts will be handled differently
-- as they're per-person, not general expense categories

SELECT '✓ Created expense category accounts' as Status;

