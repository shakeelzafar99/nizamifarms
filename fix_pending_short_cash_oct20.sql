-- Fix the pending short cash expense requests
-- These were created but not auto-approved due to a bug in the parameter order

-- Check current status
SELECT 
    'Current Status' as Section;

SELECT 
    request_number,
    title,
    status,
    level_1_status,
    settlement_status,
    created_at,
    updated_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC;

-- Approve REQ-202510-0001 (Short Cash - Petrol, Rs. 2,000)
UPDATE t_req_master
SET 
    status = 'approved',
    level_1_status = 'approved',
    completed_at = NOW(),
    updated_at = NOW()
WHERE request_number = 'REQ-202510-0001';

SELECT CONCAT('Approved REQ-202510-0001') as Result;

-- Verify the update
SELECT 
    'After Update' as Section;

SELECT 
    request_number,
    title,
    status,
    level_1_status,
    settlement_status,
    completed_at,
    updated_at
FROM t_req_master
WHERE request_number = 'REQ-202510-0001';

-- Note: REQ-202510-0002 is a regular expense (not short cash), so leave it as pending

