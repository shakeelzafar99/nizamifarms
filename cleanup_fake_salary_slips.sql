-- =====================================================================
-- CLEANUP FAKE SALARY SLIPS (No Ledger Entry)
-- =====================================================================
-- These slips show as "approved" but approval logic never executed
-- No ledger entry, no advances settled, no loans updated
-- 
-- Date: October 16, 2025
-- =====================================================================

-- Step 1: CHECK which slips are fake (show before deleting)
SELECT 
    id,
    slip_number,
    user_id,
    salary_month,
    net_salary,
    slip_status,
    ledger_transaction_id,
    created_at
FROM t_hr_salary_slips
WHERE slip_status IN ('approved', 'paid')
  AND ledger_transaction_id IS NULL;

-- Expected result: Should show 2 slips (Asim Tahir and Arslan Aslam)
-- These are the fake approved slips


-- Step 2: CHECK which salary advances might have been incorrectly marked as settled
-- (If the bug also affected advance settlement status in database)
SELECT 
    r.id,
    r.request_number,
    r.amount,
    r.settlement_status,
    r.settled_at,
    r.settlement_notes,
    s.slip_number,
    s.ledger_transaction_id
FROM t_req_master r
LEFT JOIN t_hr_salary_slips s ON FIND_IN_SET(r.id, s.advance_request_ids)
WHERE r.settlement_status = 'settled'
  AND r.category_id IN (SELECT id FROM t_req_category WHERE category_code = 'salary_advance')
  AND s.ledger_transaction_id IS NULL;

-- If this returns rows, those advances were incorrectly marked as settled


-- Step 3: DELETE the fake salary slips
DELETE FROM t_hr_salary_slips 
WHERE slip_status IN ('approved', 'paid')
  AND ledger_transaction_id IS NULL;

-- This will delete the fake slips (no ledger entry = no financial impact)


-- Step 4: RESET salary advances if they were incorrectly marked as settled
-- (Only run if Step 2 returned any rows)

UPDATE t_req_master
SET 
    settlement_status = NULL,
    settled_at = NULL,
    settlement_notes = NULL
WHERE id IN (
    SELECT r.id
    FROM (
        SELECT r.id
        FROM t_req_master r
        WHERE r.settlement_status = 'settled'
          AND r.category_id IN (SELECT id FROM t_req_category WHERE category_code = 'salary_advance')
          AND NOT EXISTS (
              SELECT 1 
              FROM t_hr_salary_slips s 
              WHERE FIND_IN_SET(r.id, s.advance_request_ids)
                AND s.ledger_transaction_id IS NOT NULL
          )
    ) AS subquery
);

-- This resets advances that were marked settled by fake slips


-- Step 5: VERIFY cleanup
-- Check that fake slips are gone
SELECT COUNT(*) as remaining_fake_slips
FROM t_hr_salary_slips
WHERE slip_status IN ('approved', 'paid')
  AND ledger_transaction_id IS NULL;

-- Should return: 0


-- Check that advances are back to correct status
SELECT 
    id,
    request_number,
    amount,
    settlement_status
FROM t_req_master
WHERE requester_user_id IN (71, 78)  -- Asim and Arslan
  AND category_id IN (SELECT id FROM t_req_category WHERE category_code = 'salary_advance')
  AND status = 'approved'
ORDER BY id DESC;

-- settlement_status should be NULL or 'pending' for unpaid advances


-- =====================================================================
-- READY TO TEST NEW APPROVAL FLOW
-- =====================================================================

-- After cleanup, create NEW salary slips:
-- 1. Go to HR → Salary Slips → Generate Salary Slip
-- 2. Select employee and month
-- 3. Calculate salary
-- 4. Click "Approve & Finalize"
-- 5. Check that:
--    - Ledger entry created (t_fin_ledger)
--    - EXP_FUND balance decreased (t_fin_accounts)
--    - Advances settled (t_req_master.settlement_status = 'settled')
--    - Loans updated (t_hr_employee_loans)
--    - "Salary Adv. Pending" column = 0 in employee list


-- VERIFICATION QUERY: Check that new slip has ledger entry
SELECT 
    s.id,
    s.slip_number,
    s.slip_status,
    s.net_salary,
    s.ledger_transaction_id,
    l.id as ledger_id,
    l.amount as ledger_amount,
    fa.account_name as from_account,
    ta.account_name as to_account
FROM t_hr_salary_slips s
LEFT JOIN t_fin_ledger l ON l.id = s.ledger_transaction_id
LEFT JOIN t_fin_accounts fa ON fa.id = l.from_account_id
LEFT JOIN t_fin_accounts ta ON ta.id = l.to_account_id
WHERE s.slip_status IN ('approved', 'paid')
ORDER BY s.id DESC
LIMIT 5;

-- All approved slips should now have:
-- - ledger_transaction_id NOT NULL
-- - Ledger entry showing EXP_FUND → Employee Cash

