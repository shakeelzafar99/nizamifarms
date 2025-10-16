-- =====================================================================
-- Check Arslan Aslam's Full 30-Day Attendance (Sep 16 - Oct 16)
-- =====================================================================
-- This matches what the API is querying
-- =====================================================================

USE nizamifarms_db;

-- Get the date range the API uses (30 days before Oct 16)
SET @end_date = '2025-10-16';
SET @start_date = DATE_SUB(@end_date, INTERVAL 30 DAY);  -- Will be Sep 16

SELECT 
    CONCAT('Date range: ', @start_date, ' to ', @end_date) as Info;

SELECT '' as '';

-- Check total records for Arslan Aslam in this full 30-day period
SELECT 
    COUNT(*) as total_records,
    SUM(CASE WHEN login_time IS NOT NULL THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN login_time IS NULL THEN 1 ELSE 0 END) as absent_days
FROM t_ops_attendance a
JOIN t_sys_user u ON u.id = a.user_id
WHERE u.fullname LIKE '%Arslan%Aslam%'
AND a.attendance_date BETWEEN @start_date AND @end_date;

SELECT '' as '';

-- Show all records to see if there are September records too
SELECT 
    a.id,
    a.attendance_date,
    DAYNAME(a.attendance_date) as day_name,
    a.login_time,
    a.logout_time,
    CASE 
        WHEN a.login_time IS NOT NULL THEN 'Present'
        ELSE 'Absent'
    END as status,
    a.notes
FROM t_ops_attendance a
JOIN t_sys_user u ON u.id = a.user_id
WHERE u.fullname LIKE '%Arslan%Aslam%'
AND a.attendance_date BETWEEN @start_date AND @end_date
ORDER BY a.attendance_date;

SELECT '' as '';

-- Check if there are duplicates in the full range
SELECT 
    attendance_date,
    COUNT(*) as count
FROM t_ops_attendance a
JOIN t_sys_user u ON u.id = a.user_id
WHERE u.fullname LIKE '%Arslan%Aslam%'
AND a.attendance_date BETWEEN @start_date AND @end_date
GROUP BY attendance_date
HAVING COUNT(*) > 1;

SELECT '' as '';
SELECT 'If the query above returns rows, there are DUPLICATES!' as Note;
SELECT 'If it returns empty, check if present_days matches what you see in UI' as Note;

