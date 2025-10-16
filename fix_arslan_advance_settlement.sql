-- FIX: Manually settle Arslan Aslam's salary advance that was deducted in slip #4
-- This is needed because slip #4 was created before the settlement logic was fixed

-- Step 1: Check the current state
SELECT 
    'Salary Slip #4' as check_item,
    s.id,
    s.user_id,
    u.fullname as employee,
    s.salary_advance,
    s.advance_request_ids,
    s.slip_status
FROM t_hr_salary_slips s
LEFT JOIN t_sys_user u ON s.user_id = u.id
WHERE s.id = 4;

-- Step 2: Check Arslan's salary advance requests
SELECT 
    'Arslan Advance Requests' as check_item,
    r.id as request_id,
    r.amount,
    r.status,
    r.settlement_status,
    r.created_at,
    r.settled_at,
    r.settlement_notes
FROM t_req_master r
LEFT JOIN t_req_category c ON r.category_id = c.id
WHERE r.requester_user_id = (SELECT user_id FROM t_hr_salary_slips WHERE id = 4)
AND c.category_code = 'salary_advance'
AND r.status = 'approved'
ORDER BY r.created_at DESC;

-- Step 3: Mark the salary advance as settled
-- INSTRUCTIONS:
-- 1. Run the SELECT queries above
-- 2. Find the request_id that should be settled (likely the most recent approved one with amount 5000)
-- 3. Replace REQUEST_ID below with the actual ID
-- 4. Uncomment and run the UPDATE

-- UPDATE t_req_master 
-- SET settlement_status = 'settled',
--     settled_at = NOW(),
--     settlement_notes = 'Deducted from salary slip #4 (SLIP-4) for October 2025'
-- WHERE id = REQUEST_ID  -- Replace with actual request ID
-- AND status = 'approved';

-- Step 4: Verify the fix
-- After running the UPDATE, check that Arslan's "Salary Adv. Pending" is now 0
SELECT 
    'Verification' as check_item,
    r.id as request_id,
    u.fullname as employee,
    r.amount,
    r.settlement_status,
    r.settled_at
FROM t_req_master r
LEFT JOIN t_req_category c ON r.category_id = c.id
LEFT JOIN t_sys_user u ON r.requester_user_id = u.id
WHERE r.requester_user_id = (SELECT user_id FROM t_hr_salary_slips WHERE id = 4)
AND c.category_code = 'salary_advance'
ORDER BY r.created_at DESC;

