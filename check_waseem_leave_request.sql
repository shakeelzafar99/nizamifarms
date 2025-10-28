-- Check Waseem's leave request for Oct 27, 2025
SELECT 
    rm.id,
    rm.request_number,
    rm.requester_user_id,
    u.fullname,
    rc.category_name,
    rc.category_code,
    rm.status,
    rm.leave_type,
    rm.leave_start_date,
    rm.leave_end_date,
    rm.leave_days,
    rm.created_at,
    rm.submitted_at,
    rm.level_1_status,
    rm.level_2_status
FROM t_req_master rm
JOIN t_sys_user u ON u.id = rm.requester_user_id
JOIN t_req_category rc ON rc.id = rm.category_id
WHERE u.fullname = 'Waseem'
AND rc.category_code = 'leave'
AND rm.leave_start_date <= '2025-10-27'
AND rm.leave_end_date >= '2025-10-27'
ORDER BY rm.created_at DESC;

