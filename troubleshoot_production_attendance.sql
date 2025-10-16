-- =====================================================================
-- Troubleshoot Production Attendance Issues
-- =====================================================================
-- Purpose: Check what's actually in production database and clear properly
-- Issue: Production shows old/duplicate data even after "deleting"
-- Date: 2025-10-15
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- STEP 1: Check if attendance table actually has data
-- =====================================================================

SELECT '=== CHECKING ATTENDANCE TABLE ===' as '';
SELECT '' as '';

SELECT 
    COUNT(*) as total_records,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT attendance_date) as unique_dates,
    MIN(attendance_date) as earliest_date,
    MAX(attendance_date) as latest_date
FROM t_ops_attendance;

SELECT '' as '';

-- =====================================================================
-- STEP 2: Check for DUPLICATES (this is the main issue!)
-- =====================================================================

SELECT '=== CHECKING FOR DUPLICATES ===' as '';
SELECT '' as '';

SELECT 
    user_id,
    u.fullname,
    attendance_date,
    COUNT(*) as duplicate_count,
    GROUP_CONCAT(id) as duplicate_ids,
    GROUP_CONCAT(CONCAT(login_time, '→', logout_time) SEPARATOR ' | ') as time_entries
FROM t_ops_attendance a
LEFT JOIN t_sys_user u ON u.id = a.user_id
GROUP BY user_id, attendance_date
HAVING COUNT(*) > 1
ORDER BY attendance_date DESC, fullname;

SELECT '' as '';

-- =====================================================================
-- STEP 3: Check October 2025 data specifically
-- =====================================================================

SELECT '=== OCTOBER 2025 DATA ===' as '';
SELECT '' as '';

SELECT 
    COUNT(*) as october_records,
    COUNT(DISTINCT user_id) as unique_users,
    COUNT(DISTINCT attendance_date) as unique_dates
FROM t_ops_attendance
WHERE attendance_date BETWEEN '2025-10-01' AND '2025-10-31';

SELECT '' as '';

-- Show sample records
SELECT 
    a.id,
    u.fullname,
    a.attendance_date,
    a.login_time,
    a.logout_time,
    a.notes
FROM t_ops_attendance a
LEFT JOIN t_sys_user u ON u.id = a.user_id
WHERE attendance_date BETWEEN '2025-10-01' AND '2025-10-31'
ORDER BY fullname, attendance_date
LIMIT 20;

SELECT '' as '';

-- =====================================================================
-- STEP 4: Check for Arslan Aslam specifically (from screenshot)
-- =====================================================================

SELECT '=== ARSLAN ASLAM DATA ===' as '';
SELECT '' as '';

SELECT 
    a.id,
    a.attendance_date,
    a.login_time,
    a.logout_time,
    a.created_at,
    a.updated_at,
    a.notes
FROM t_ops_attendance a
JOIN t_sys_user u ON u.id = a.user_id
WHERE u.fullname LIKE '%Arslan%Aslam%'
AND a.attendance_date BETWEEN '2025-10-01' AND '2025-10-31'
ORDER BY a.attendance_date, a.id;

SELECT '' as '';

-- =====================================================================
-- STEP 5: Check salary slips table
-- =====================================================================

SELECT '=== SALARY SLIPS TABLE ===' as '';
SELECT '' as '';

SELECT 
    COUNT(*) as total_slips,
    MIN(salary_month) as earliest_month,
    MAX(salary_month) as latest_month
FROM t_hr_salary_slips;

SELECT '' as '';

SELECT 
    slip_status,
    COUNT(*) as count
FROM t_hr_salary_slips
GROUP BY slip_status;

SELECT '' as '';

-- =====================================================================
-- STEP 6: SOLUTION - Delete duplicates keeping only the oldest record
-- =====================================================================

SELECT '=== STEP 6: DELETE DUPLICATES ===' as '';
SELECT 'UNCOMMENT BELOW TO DELETE DUPLICATE RECORDS' as '';
SELECT '' as '';

/*
-- Delete duplicate attendance records, keeping only the one with the smallest ID
DELETE a1 FROM t_ops_attendance a1
INNER JOIN t_ops_attendance a2 
WHERE 
    a1.user_id = a2.user_id
    AND a1.attendance_date = a2.attendance_date
    AND a1.id > a2.id;

SELECT 'Duplicates deleted!' as Status;
SELECT '' as '';
*/

-- =====================================================================
-- STEP 7: NUCLEAR OPTION - Delete ALL attendance data
-- =====================================================================

SELECT '=== STEP 7: DELETE ALL ATTENDANCE ===' as '';
SELECT 'UNCOMMENT BELOW TO DELETE ALL ATTENDANCE DATA' as '';
SELECT '' as '';

/*
-- Delete ALL attendance records
DELETE FROM t_ops_attendance;

SELECT 'All attendance records deleted!' as Status;
SELECT '' as '';

-- Verify
SELECT COUNT(*) as remaining_records FROM t_ops_attendance;
*/

-- =====================================================================
-- STEP 8: Clear Laravel cache (run from terminal)
-- =====================================================================

SELECT '=== STEP 8: CLEAR LARAVEL CACHE ===' as '';
SELECT 'Run these commands from terminal:' as '';
SELECT '' as '';
SELECT 'php artisan cache:clear' as Command;
SELECT 'php artisan config:clear' as Command;
SELECT 'php artisan route:clear' as Command;
SELECT 'php artisan view:clear' as Command;
SELECT '' as '';

-- =====================================================================
-- SUMMARY OF ISSUE
-- =====================================================================

SELECT '' as '';
SELECT '==================================================================' as '';
SELECT 'DIAGNOSIS:' as '';
SELECT '1. Production likely has DUPLICATE records' as '';
SELECT '2. Duplicates cause wrong counts (24/27 instead of actual)' as '';
SELECT '3. Browser may also be caching old data' as '';
SELECT '' as '';
SELECT 'SOLUTIONS:' as '';
SELECT 'A. Run STEP 6 to delete only duplicates (safe)' as '';
SELECT 'B. Run STEP 7 to delete everything (nuclear)' as '';
SELECT 'C. Clear Laravel cache (STEP 8)' as '';
SELECT 'D. Hard refresh browser (Ctrl+Shift+R or Ctrl+F5)' as '';
SELECT '==================================================================' as '';

