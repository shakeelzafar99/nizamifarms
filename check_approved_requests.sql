-- Check the approved requests and their completed_at dates

SELECT 
    request_number,
    title,
    amount,
    status,
    submitted_at,
    completed_at,
    level_1_approved_at,
    created_at,
    updated_at
FROM t_req_master
WHERE request_number IN ('REQ-202510-0004', 'REQ-202510-0003', 'REQ-202510-0002')
ORDER BY created_at DESC;

-- Check all approved requests from today
SELECT 
    'All Approved Requests Today' as Section;

SELECT 
    request_number,
    title,
    amount,
    status,
    completed_at,
    level_1_approved_at,
    DATE(COALESCE(completed_at, level_1_approved_at, updated_at)) as approval_date
FROM t_req_master
WHERE status = 'approved'
AND DATE(created_at) = CURDATE()
ORDER BY created_at DESC;

