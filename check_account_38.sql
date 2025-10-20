-- Check what account ID 38 is

SELECT 
    id,
    account_name,
    account_code,
    account_category
FROM t_fin_account
WHERE id = 38;

-- Also check all employee cash accounts
SELECT 
    'All Employee Cash Accounts' as Section;

SELECT 
    id,
    account_name,
    account_code,
    account_category
FROM t_fin_account
WHERE account_category = 'employee_cash'
ORDER BY account_name;

