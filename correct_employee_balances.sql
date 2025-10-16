-- =====================================================================
-- EMPLOYEE CASH BALANCE CORRECTION SCRIPT
-- =====================================================================
-- Purpose: Fix inflated employee cash balances caused by including
--          personal payments (salary, advances, loans) in balance
--
-- Background: Employee cash balance should ONLY reflect company money
--             they're holding (invoices, expenses, deposits), NOT
--             personal payments (salary, advances, loans)
--
-- When to run: OPTIONAL - only if you want to correct existing balances
--              New transactions after Oct 16, 2025 are already correct
--
-- Date: October 16, 2025
-- =====================================================================

-- Step 1: BACKUP current balances (IMPORTANT!)
CREATE TABLE IF NOT EXISTS t_fin_accounts_backup_oct16 AS
SELECT * FROM t_fin_accounts WHERE account_category = 'employee_cash';

-- Check backup
SELECT COUNT(*) as employee_accounts_backed_up FROM t_fin_accounts_backup_oct16;


-- Step 2: VIEW current balances vs corrected balances (DRY RUN)
-- This shows what WOULD change without actually changing anything

SELECT 
    a.id,
    a.account_code,
    a.account_name,
    CONCAT('Rs. ', FORMAT(a.current_balance, 2)) as current_balance,
    CONCAT('Rs. ', FORMAT(
        COALESCE((
            SELECT SUM(
                CASE 
                    -- Money coming IN (invoices, incoming transfers)
                    WHEN l.to_account_id = a.id 
                        AND l.transaction_type IN ('invoice', 'transfer')
                    THEN l.amount
                    
                    -- Money coming IN (expense reimbursements TO employee)
                    WHEN l.to_account_id = a.id 
                        AND l.transaction_type = 'expense'
                    THEN l.amount
                    
                    -- Money going OUT (deposits to company)
                    WHEN l.from_account_id = a.id 
                        AND l.transaction_type = 'employee_deposit'
                    THEN -l.amount
                    
                    -- Money going OUT (expenses FROM employee balance)
                    WHEN l.from_account_id = a.id 
                        AND l.transaction_type = 'expense'
                    THEN -l.amount
                    
                    -- Money going OUT (transfers FROM employee)
                    WHEN l.from_account_id = a.id 
                        AND l.transaction_type = 'transfer'
                    THEN -l.amount
                    
                    -- EXCLUDE: salary_payment (personal payment TO employee)
                    -- EXCLUDE: salary_advance (personal payment TO employee)
                    -- EXCLUDE: loan_disbursement (personal payment TO employee)
                    
                    ELSE 0
                END
            )
            FROM t_fin_ledger l
            WHERE l.to_account_id = a.id OR l.from_account_id = a.id
        ), 0), 2)
    ) as corrected_balance,
    CONCAT('Rs. ', FORMAT(
        a.current_balance - COALESCE((
            SELECT SUM(
                CASE 
                    WHEN l.to_account_id = a.id 
                        AND l.transaction_type IN ('invoice', 'transfer', 'expense')
                    THEN l.amount
                    WHEN l.from_account_id = a.id 
                        AND l.transaction_type IN ('employee_deposit', 'expense', 'transfer')
                    THEN -l.amount
                    ELSE 0
                END
            )
            FROM t_fin_ledger l
            WHERE l.to_account_id = a.id OR l.from_account_id = a.id
        ), 0), 2)
    ) as difference
FROM t_fin_accounts a
WHERE a.account_category = 'employee_cash'
ORDER BY ABS(a.current_balance - COALESCE((
    SELECT SUM(
        CASE 
            WHEN l.to_account_id = a.id 
                AND l.transaction_type IN ('invoice', 'transfer', 'expense')
            THEN l.amount
            WHEN l.from_account_id = a.id 
                AND l.transaction_type IN ('employee_deposit', 'expense', 'transfer')
            THEN -l.amount
            ELSE 0
        END
    )
    FROM t_fin_ledger l
    WHERE l.to_account_id = a.id OR l.from_account_id = a.id
), 0)) DESC;


-- Step 3: APPLY CORRECTION (CAUTION: This modifies data!)
-- Uncomment the following UPDATE statement only after reviewing Step 2 results

/*
UPDATE t_fin_accounts a
SET 
    current_balance = (
        SELECT COALESCE(SUM(
            CASE 
                -- Money coming IN (invoices, incoming transfers)
                WHEN l.to_account_id = a.id 
                    AND l.transaction_type IN ('invoice', 'transfer')
                THEN l.amount
                
                -- Money coming IN (expense reimbursements TO employee)
                WHEN l.to_account_id = a.id 
                    AND l.transaction_type = 'expense'
                THEN l.amount
                
                -- Money going OUT (deposits to company)
                WHEN l.from_account_id = a.id 
                    AND l.transaction_type = 'employee_deposit'
                THEN -l.amount
                
                -- Money going OUT (expenses FROM employee balance)
                WHEN l.from_account_id = a.id 
                    AND l.transaction_type = 'expense'
                THEN -l.amount
                
                -- Money going OUT (transfers FROM employee)
                WHEN l.from_account_id = a.id 
                    AND l.transaction_type = 'transfer'
                THEN -l.amount
                
                -- EXPLICITLY EXCLUDE personal payments:
                -- salary_payment, salary_advance, loan_disbursement
                
                ELSE 0
            END
        ), 0)
        FROM t_fin_ledger l
        WHERE l.to_account_id = a.id OR l.from_account_id = a.id
    ),
    updated_at = NOW()
WHERE a.account_category = 'employee_cash';
*/


-- Step 4: VERIFY correction (run after Step 3)
/*
SELECT 
    a.id,
    a.account_code,
    a.account_name,
    CONCAT('Rs. ', FORMAT(backup.current_balance, 2)) as old_balance,
    CONCAT('Rs. ', FORMAT(a.current_balance, 2)) as new_balance,
    CONCAT('Rs. ', FORMAT(a.current_balance - backup.current_balance, 2)) as change
FROM t_fin_accounts a
JOIN t_fin_accounts_backup_oct16 backup ON backup.id = a.id
WHERE a.account_category = 'employee_cash'
  AND ABS(a.current_balance - backup.current_balance) > 0.01
ORDER BY ABS(a.current_balance - backup.current_balance) DESC;
*/


-- Step 5: ROLLBACK (if something went wrong)
/*
UPDATE t_fin_accounts a
JOIN t_fin_accounts_backup_oct16 backup ON backup.id = a.id
SET 
    a.current_balance = backup.current_balance,
    a.updated_at = backup.updated_at
WHERE a.account_category = 'employee_cash';
*/


-- =====================================================================
-- EXPLANATION OF TRANSACTION TYPES
-- =====================================================================

/*
INCLUDED in employee balance (company money they're holding):
✅ invoice           - Cash collected from customers
✅ expense (TO)      - Reimbursement for expenses they paid
✅ transfer (TO)     - Money transferred to their account
✅ employee_deposit  - When they deposit cash to company (decreases balance)
✅ expense (FROM)    - Expenses paid from their balance (decreases)
✅ transfer (FROM)   - Money transferred from their account (decreases)

EXCLUDED from employee balance (personal payments):
❌ salary_payment    - Their earned salary (personal money)
❌ salary_advance    - Advance on salary (personal money)
❌ loan_disbursement - Loan given to them (personal money)

RATIONALE:
Employee cash balance represents COMPANY MONEY they're physically holding
or responsible for. Personal payments (salary, advances, loans) are their
own money, not company cash they're holding.
*/


-- =====================================================================
-- EXAMPLE: Before vs After
-- =====================================================================

/*
Employee: Asim Tahir - Indrive
Transactions:
1. Oct 1:  Delivered 5 cash invoices = Rs. 25,000 (invoice)
2. Oct 5:  Got salary advance = Rs. 5,000 (salary_advance)
3. Oct 10: Deposited cash to company = Rs. 25,000 (employee_deposit)
4. Oct 15: Received October salary = Rs. 45,000 (salary_payment)

OLD BALANCE (WRONG):
Rs. 25,000 (invoices) 
+ Rs. 5,000 (salary advance) ❌ WRONG!
- Rs. 25,000 (deposit)
+ Rs. 45,000 (salary) ❌ WRONG!
= Rs. 50,000 ❌ Looks like they're holding Rs. 50K company cash!

NEW BALANCE (CORRECT):
Rs. 25,000 (invoices)
- Rs. 25,000 (deposit)
= Rs. 0 ✅ They settled all company cash (salary & advance excluded)

Explanation:
- The Rs. 5,000 advance and Rs. 45,000 salary are PERSONAL MONEY
- They're not holding this as company cash
- Balance correctly shows Rs. 0 (no company cash held)
*/


-- =====================================================================
-- USAGE INSTRUCTIONS
-- =====================================================================

/*
1. Run Step 1 (backup) - ALWAYS do this first
2. Run Step 2 (dry run) - Review the differences
3. If differences look correct, uncomment and run Step 3
4. Run Step 4 to verify changes
5. If something went wrong, run Step 5 to rollback

RECOMMENDATION:
- Run this on DEV/TEST first
- Review results carefully
- Then run on PRODUCTION
- Keep backup table for at least 1 week
*/


-- =====================================================================
-- FREQUENTLY ASKED QUESTIONS
-- =====================================================================

/*
Q: Will this affect my ledger transactions?
A: No, ledger transactions remain unchanged. Only employee account 
   balances are corrected.

Q: Will this affect salary/advance tracking?
A: No, salary advances are still tracked in t_req_master and deducted
   from salary. This only corrects the employee cash balance.

Q: What if I don't run this script?
A: New transactions after Oct 16 are correct. Existing balances will
   remain inflated until you run this correction. It's optional but
   recommended for accuracy.

Q: Can I run this multiple times?
A: Yes, it's idempotent (safe to run multiple times). It recalculates
   from scratch each time.

Q: How long does it take?
A: Depends on number of employees and transactions. Typically < 1 second
   for up to 100 employees.
*/

