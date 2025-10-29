-- Check picture column sizes in attendance table
SHOW COLUMNS FROM t_ops_attendance WHERE Field IN ('picture_start', 'picture_end');

-- Check current data
SELECT 
    id,
    user_id,
    attendance_date,
    LENGTH(picture_start) as picture_start_length,
    LENGTH(picture_end) as picture_end_length,
    picture_start,
    picture_end
FROM t_ops_attendance
WHERE picture_start IS NOT NULL OR picture_end IS NOT NULL
LIMIT 5;

