-- Fix the two pending short cash requests that should have been auto-approved
-- REQ-202510-0024 and REQ-202510-0026

-- First, let's see their current state
SELECT 
    'Current State of Pending Short Cash Requests' as Section;

SELECT 
    rm.request_number,
    rm.title,
    rm.amount,
    rm.status,
    rm.settlement_status,
    rm.requires_level_1,
    rm.level_1_status,
    rm.level_1_approved_by,
    rm.level_1_approved_at
FROM t_req_master rm
WHERE rm.request_number IN ('REQ-202510-0024', 'REQ-202510-0026');

-- Update settlement_status to 'pending' for these requests
UPDATE t_req_master
SET settlement_status = 'pending'
WHERE request_number IN ('REQ-202510-0024', 'REQ-202510-0026');

SELECT CONCAT('Updated settlement_status to pending for ', ROW_COUNT(), ' requests') as Result;

-- Manually approve REQ-202510-0026 (since its deposit is already approved)
UPDATE t_req_master
SET 
    status = 'approved',
    level_1_status = 'approved',
    level_1_approved_by = 1, -- Admin user
    level_1_approved_at = NOW(),
    updated_at = NOW()
WHERE request_number = 'REQ-202510-0026';

SELECT CONCAT('Approved REQ-202510-0026') as Result;

-- Manually approve REQ-202510-0024 (since its deposit is already approved)
UPDATE t_req_master
SET 
    status = 'approved',
    level_1_status = 'approved',
    level_1_approved_by = 1, -- Admin user
    level_1_approved_at = NOW(),
    updated_at = NOW()
WHERE request_number = 'REQ-202510-0024';

SELECT CONCAT('Approved REQ-202510-0024') as Result;

-- Verify the updates
SELECT 
    'Updated State' as Section;

SELECT 
    rm.request_number,
    rm.title,
    rm.amount,
    rm.status,
    rm.settlement_status,
    rm.level_1_status,
    rm.level_1_approved_at
FROM t_req_master rm
WHERE rm.request_number IN ('REQ-202510-0024', 'REQ-202510-0026');

