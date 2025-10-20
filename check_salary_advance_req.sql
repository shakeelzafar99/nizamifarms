-- Check the salary advance request details
SELECT 
    r.request_number,
    r.title,
    r.payment_source_account_id,
    a.account_name as payment_source_name,
    a.account_category,
    c.category_name,
    c.category_code,
    r.submitted_at,
    r.created_at
FROM t_req_master r
LEFT JOIN t_fin_accounts a ON r.payment_source_account_id = a.id
LEFT JOIN t_req_categories c ON r.category_id = c.id
WHERE r.request_number = 'REQ-202510-0005';

-- Check what the EXP_FUND account ID is
SELECT id, account_name, account_category
FROM t_fin_accounts
WHERE account_name LIKE '%Expense%Fund%' OR account_category = 'expense_fund';

