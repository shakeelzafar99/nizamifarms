-- Check the status of the expense request

SELECT 
    request_number,
    title,
    amount,
    status,
    level_1_status,
    level_2_status,
    settlement_status,
    created_at,
    updated_at,
    level_1_approved_at
FROM t_req_master
WHERE id = 1 OR amount = 2000
ORDER BY created_at DESC
LIMIT 5;

