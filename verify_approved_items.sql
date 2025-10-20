-- Check all approved expense requests from today
SELECT 
    'Approved Expense Requests Today' as Section;

SELECT 
    request_number,
    title,
    amount,
    status,
    completed_at,
    updated_at,
    created_at,
    CASE 
        WHEN completed_at IS NOT NULL THEN 'Has completed_at'
        ELSE 'completed_at is NULL'
    END as completion_status
FROM t_req_master
WHERE status = 'approved'
AND (DATE(completed_at) = CURDATE() OR DATE(updated_at) = CURDATE())
ORDER BY COALESCE(completed_at, updated_at) DESC;

-- Check if the specific requests are approved
SELECT 
    'Specific Requests Status' as Section;

SELECT 
    request_number,
    title,
    amount,
    status,
    completed_at,
    updated_at,
    level_1_approved_at
FROM t_req_master
WHERE request_number IN ('REQ-202510-0004', 'REQ-202510-0003', 'REQ-202510-0002', 'REQ-202510-0001');

