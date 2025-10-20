-- Check the short cash expense request REQ-202510-0027

SELECT 
    request_number,
    title,
    status,
    requires_level_1,
    requires_level_2,
    level_1_status,
    level_2_status,
    category_id,
    submitted_at,
    created_at
FROM t_req_master
WHERE request_number = 'REQ-202510-0027';

