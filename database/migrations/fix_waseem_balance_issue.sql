-- =====================================================
-- Fix Waseem's Balance Issue
-- =====================================================
-- Problem: Approval logic was using wrong account_type check
-- Result: Balances went UP instead of DOWN on deposit approval
-- =====================================================

-- Step 1: Check current situation
SELECT 
    'BEFORE FIX - Current Balances' as Status;

SELECT 
    a.account_code,
    a.account_name,
    a.account_type,
    a.account_category,
    a.current_balance as 'Current Balance',
    a.opening_balance as 'Opening Balance'
FROM t_fin_accounts a
WHERE a.account_code IN ('CASH_EMP_WASEEM', 'NF_CASH')
ORDER BY a.account_code;

-- Step 2: Show approved deposits for Waseem
SELECT 
    'Approved Deposits' as Status;

SELECT 
    l.id,
    l.transaction_date,
    l.description,
    l.amount,
    l.approval_status,
    fa.account_name as 'From Account',
    ta.account_name as 'To Account'
FROM t_fin_ledger l
JOIN t_fin_accounts fa ON l.from_account_id = fa.id
JOIN t_fin_accounts ta ON l.to_account_id = ta.id
WHERE l.transaction_type = 'employee_deposit'
  AND fa.account_code = 'CASH_EMP_WASEEM'
  AND l.approval_status = 'approved'
ORDER BY l.transaction_date DESC;

-- Step 3: Recalculate correct balance
-- Employee Cash = Opening Balance + Invoices - Expenses - Deposits

SELECT 
    'Balance Calculation' as Status;

-- Get opening balance
SELECT @opening_balance := COALESCE(opening_balance, 0) 
FROM t_fin_accounts 
WHERE account_code = 'CASH_EMP_WASEEM';

-- Get total invoices (money coming IN to employee)
SELECT @total_invoices := COALESCE(SUM(amount), 0)
FROM t_fin_ledger
WHERE to_account_id = (SELECT id FROM t_fin_accounts WHERE account_code = 'CASH_EMP_WASEEM')
  AND transaction_type = 'invoice'
  AND approval_status = 'approved';

-- Get total expenses (money going OUT from employee)
SELECT @total_expenses := COALESCE(SUM(amount), 0)
FROM t_fin_ledger
WHERE from_account_id = (SELECT id FROM t_fin_accounts WHERE account_code = 'CASH_EMP_WASEEM')
  AND transaction_type = 'expense'
  AND approval_status = 'approved';

-- Get total deposits (money going OUT from employee to company)
SELECT @total_deposits := COALESCE(SUM(amount), 0)
FROM t_fin_ledger
WHERE from_account_id = (SELECT id FROM t_fin_accounts WHERE account_code = 'CASH_EMP_WASEEM')
  AND transaction_type = 'employee_deposit'
  AND approval_status = 'approved';

-- Calculate correct balance
SELECT @correct_balance := @opening_balance + @total_invoices - @total_expenses - @total_deposits;

SELECT 
    @opening_balance as 'Opening Balance',
    @total_invoices as 'Total Invoices (IN)',
    @total_expenses as 'Total Expenses (OUT)',
    @total_deposits as 'Total Deposits (OUT)',
    @correct_balance as 'Correct Balance';

-- Step 4: Update Waseem's balance to correct value
UPDATE t_fin_accounts
SET current_balance = @correct_balance
WHERE account_code = 'CASH_EMP_WASEEM';

SELECT '✓ Updated CASH_EMP_WASEEM balance' as Status;

-- Step 5: Fix NF Cash balance
-- NF Cash = Opening Balance + Deposits IN - Vendor Payments OUT - Online Expenses OUT

SELECT @nf_opening := COALESCE(opening_balance, 0) 
FROM t_fin_accounts 
WHERE account_code = 'NF_CASH';

SELECT @nf_deposits_in := COALESCE(SUM(amount), 0)
FROM t_fin_ledger
WHERE to_account_id = (SELECT id FROM t_fin_accounts WHERE account_code = 'NF_CASH')
  AND transaction_type = 'employee_deposit'
  AND approval_status = 'approved';

SELECT @nf_payments_out := COALESCE(SUM(amount), 0)
FROM t_fin_ledger
WHERE from_account_id = (SELECT id FROM t_fin_accounts WHERE account_code = 'NF_CASH')
  AND transaction_type IN ('vendor_payment', 'expense')
  AND approval_status = 'approved';

SELECT @nf_correct_balance := @nf_opening + @nf_deposits_in - @nf_payments_out;

UPDATE t_fin_accounts
SET current_balance = @nf_correct_balance
WHERE account_code = 'NF_CASH';

SELECT '✓ Updated NF_CASH balance' as Status;

-- Step 6: Verify fix
SELECT 
    'AFTER FIX - Updated Balances' as Status;

SELECT 
    a.account_code,
    a.account_name,
    a.current_balance as 'Current Balance',
    a.opening_balance as 'Opening Balance'
FROM t_fin_accounts a
WHERE a.account_code IN ('CASH_EMP_WASEEM', 'NF_CASH')
ORDER BY a.account_code;

SELECT '✓ Balance Fix Complete!' as Status;
SELECT 'Note: Future approvals will now calculate correctly' as Note;

