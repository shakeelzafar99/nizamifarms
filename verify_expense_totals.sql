-- Verify total expenses calculation includes both regular expenses and salary payments

-- 1. Regular expenses and salary advances (from requests)
SELECT 
    'Regular Expenses & Salary Advances' as expense_type,
    COUNT(*) as count,
    SUM(amount) as total_amount
FROM t_req_master r
LEFT JOIN t_req_category c ON r.category_id = c.id
WHERE c.category_code IN ('expense', 'salary_advance')
AND r.ledger_transaction_id IS NOT NULL;

-- 2. Salary slip payments (from HR system)
SELECT 
    'Salary Slip Payments' as expense_type,
    COUNT(*) as count,
    SUM(net_salary) as total_amount
FROM t_hr_salary_slips
WHERE slip_status IN ('approved', 'paid')
AND ledger_transaction_id IS NOT NULL;

-- 3. Combined total (what Expense Management should show)
SELECT 
    'TOTAL EXPENSES (Combined)' as expense_type,
    (
        SELECT COUNT(*) 
        FROM t_req_master r
        LEFT JOIN t_req_category c ON r.category_id = c.id
        WHERE c.category_code IN ('expense', 'salary_advance')
        AND r.ledger_transaction_id IS NOT NULL
    ) + (
        SELECT COUNT(*) 
        FROM t_hr_salary_slips
        WHERE slip_status IN ('approved', 'paid')
        AND ledger_transaction_id IS NOT NULL
    ) as count,
    (
        SELECT COALESCE(SUM(amount), 0)
        FROM t_req_master r
        LEFT JOIN t_req_category c ON r.category_id = c.id
        WHERE c.category_code IN ('expense', 'salary_advance')
        AND r.ledger_transaction_id IS NOT NULL
    ) + (
        SELECT COALESCE(SUM(net_salary), 0)
        FROM t_hr_salary_slips
        WHERE slip_status IN ('approved', 'paid')
        AND ledger_transaction_id IS NOT NULL
    ) as total_amount;

-- 4. Verify EXP_FUND balance reflects all expenses
SELECT 
    'EXP_FUND Account Balance' as check_item,
    account_code,
    account_name,
    opening_balance,
    current_balance,
    opening_balance - current_balance as total_spent
FROM t_fin_accounts
WHERE account_code = 'EXP_FUND';

-- 5. Show recent salary slips for verification
SELECT 
    'Recent Salary Slips' as check_item,
    s.id,
    s.slip_number,
    u.fullname as employee,
    s.net_salary,
    s.slip_status,
    s.ledger_transaction_id,
    s.created_at
FROM t_hr_salary_slips s
LEFT JOIN t_sys_user u ON s.user_id = u.id
WHERE s.slip_status IN ('approved', 'paid')
AND s.ledger_transaction_id IS NOT NULL
ORDER BY s.created_at DESC
LIMIT 10;

