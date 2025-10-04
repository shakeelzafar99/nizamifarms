-- Fix the status for approved leave requests
-- This updates any requests where level_1_status is 'approved' but main status is still 'pending'

UPDATE t_req_master 
SET status = 'approved',
    completed_at = NOW(),
    updated_at = NOW()
WHERE level_1_status = 'approved' 
  AND requires_level_1 = 1
  AND (requires_level_2 = 0 OR level_2_status = 'approved')
  AND status != 'approved';

-- Verify the fix
SELECT 
    id,
    request_number,
    status as main_status,
    level_1_status,
    level_2_status,
    requires_level_1,
    requires_level_2,
    u.fullname as requester
FROM t_req_master r
JOIN t_sys_user u ON u.id = r.requester_user_id
WHERE id IN (1, 2)
ORDER BY id;

