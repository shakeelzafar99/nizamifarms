-- Investigation: Why aren't loan/advance balances updating after salary slip approval?

-- 1. Check slip #5 details
SELECT 
    'SLIP #5 DETAILS' as section,
    id,
    user_id,
    slip_status,
    approved_by,
    ledger_transaction_id,
    salary_advance,
    advance_request_ids,
    loan_installment,
    loan_ids
FROM t_hr_salary_slips
WHERE id = 5;

-- 2. Get user info
SELECT 
    'EMPLOYEE INFO' as section,
    u.id,
    u.fullname,
    u.email
FROM t_sys_user u
WHERE u.id = (SELECT user_id FROM t_hr_salary_slips WHERE id = 5);

-- 3. Check salary advance requests for this user
SELECT 
    'SALARY ADVANCES' as section,
    r.id as request_id,
    r.amount,
    r.status,
    r.settlement_status,
    r.settled_at,
    r.created_at,
    c.category_code
FROM t_req_master r
LEFT JOIN t_req_category c ON r.category_id = c.id
WHERE r.requester_user_id = (SELECT user_id FROM t_hr_salary_slips WHERE id = 5)
AND c.category_code = 'salary_advance'
ORDER BY r.created_at DESC;

-- 4. Check loans for this user
SELECT 
    'LOANS' as section,
    id as loan_id,
    principal_amount,
    monthly_installment,
    outstanding_balance,
    remaining_balance,
    loan_status,
    created_at
FROM t_hr_employee_loans
WHERE user_id = (SELECT user_id FROM t_hr_salary_slips WHERE id = 5)
ORDER BY created_at DESC;

-- 5. Check loan payments
SELECT 
    'LOAN PAYMENTS' as section,
    p.id as payment_id,
    p.loan_id,
    p.payment_amount,
    p.balance_before,
    p.balance_after,
    p.salary_slip_id,
    p.payment_date,
    p.created_at
FROM t_hr_loan_payments p
WHERE p.loan_id IN (
    SELECT id FROM t_hr_employee_loans 
    WHERE user_id = (SELECT user_id FROM t_hr_salary_slips WHERE id = 5)
)
ORDER BY p.created_at DESC;

-- 6. Check what the employee list query actually sees
-- This mimics the query in EmployeeProfileController
SELECT 
    'WHAT EMPLOYEE LIST SEES' as section,
    u.id,
    u.fullname,
    -- Loan Outstanding (sum of outstanding_balance from active loans)
    COALESCE((
        SELECT SUM(outstanding_balance)
        FROM t_hr_employee_loans
        WHERE user_id = u.id 
        AND loan_status = 'active'
    ), 0) as loan_outstanding,
    -- Salary Advance Pending (sum of approved advances not settled)
    COALESCE((
        SELECT SUM(r.amount)
        FROM t_req_master r
        JOIN t_req_category c ON r.category_id = c.id
        WHERE r.requester_user_id = u.id
        AND r.status = 'approved'
        AND c.category_code = 'salary_advance'
        AND (r.settlement_status IS NULL OR r.settlement_status != 'settled')
    ), 0) as salary_advance_pending
FROM t_sys_user u
WHERE u.id = (SELECT user_id FROM t_hr_salary_slips WHERE id = 5);

