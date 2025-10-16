-- =====================================================================
-- Clear Attendance Data for Fresh Re-Import
-- =====================================================================
-- Purpose: Delete all attendance data to prepare for fresh import from
--          legacy CSV file
-- 
-- IMPORTANT: This will delete ALL attendance records!
-- 
-- ✅ Safe to delete because:
-- 1. Lateness/overtime are calculated on-the-fly (not stored)
-- 2. Salary slips are separate (but may need regeneration if based on old data)
-- 3. No foreign key dependencies
--
-- Date: 2025-10-15
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- STEP 1: Preview what will be deleted (SAFE - READ ONLY)
-- =====================================================================

SELECT '--- Current attendance data summary ---' as '';

SELECT 
    COUNT(*) as total_records,
    COUNT(DISTINCT user_id) as unique_employees,
    MIN(attendance_date) as earliest_date,
    MAX(attendance_date) as latest_date,
    SUM(CASE WHEN login_time IS NOT NULL THEN 1 ELSE 0 END) as records_with_login,
    SUM(CASE WHEN logout_time IS NOT NULL THEN 1 ELSE 0 END) as records_with_logout
FROM t_ops_attendance;

SELECT '' as '';

-- Top 10 employees by record count
SELECT 
    u.fullname,
    COUNT(*) as attendance_records,
    MIN(a.attendance_date) as first_date,
    MAX(a.attendance_date) as last_date
FROM t_ops_attendance a
JOIN t_sys_user u ON u.id = a.user_id
GROUP BY u.id, u.fullname
ORDER BY attendance_records DESC
LIMIT 10;

SELECT '' as '';
SELECT 'Review the data above before proceeding!' as '';

-- =====================================================================
-- STEP 2: Check if salary slips exist based on this attendance data
-- =====================================================================

SELECT '--- Checking salary slips that may be affected ---' as '';

SELECT 
    COUNT(*) as total_salary_slips,
    MIN(salary_month) as earliest_month,
    MAX(salary_month) as latest_month
FROM t_hr_salary_slips
WHERE slip_status != 'cancelled';

SELECT '' as '';
SELECT '⚠️  WARNING: If salary slips exist, they may need regeneration!' as '';
SELECT '' as '';

-- =====================================================================
-- STEP 3: BACKUP attendance data (OPTIONAL BUT RECOMMENDED)
-- =====================================================================

-- UNCOMMENT THE LINES BELOW TO CREATE A BACKUP:

/*
CREATE TABLE IF NOT EXISTS t_ops_attendance_backup_20251015 LIKE t_ops_attendance;

INSERT INTO t_ops_attendance_backup_20251015 
SELECT * FROM t_ops_attendance;

SELECT 
    CONCAT('✓ Backed up ', COUNT(*), ' attendance records') as Status
FROM t_ops_attendance_backup_20251015;

SELECT '' as '';
*/

-- =====================================================================
-- STEP 4: DELETE all attendance data (CAREFUL!)
-- =====================================================================

-- UNCOMMENT THE LINES BELOW TO DELETE:

/*
SELECT '--- DELETING all attendance data ---' as '';

DELETE FROM t_ops_attendance;

SELECT '✓ DELETED all attendance records' as Status;
SELECT '' as '';
*/

-- =====================================================================
-- STEP 5: Verify deletion (run after STEP 4)
-- =====================================================================

-- UNCOMMENT THE LINES BELOW TO VERIFY:

/*
SELECT 
    COUNT(*) as remaining_records
FROM t_ops_attendance;

-- Should show 0 if deletion was successful

SELECT '✓ Attendance table is now empty and ready for fresh import' as Status;
*/

-- =====================================================================
-- NOTES FOR RE-IMPORT:
-- =====================================================================

-- After running this script:
--
-- 1. Go to: Operations → Bulk Attendance Upload
-- 2. Upload your legacy attendance CSV file
-- 3. The import will create fresh attendance records
--
-- 4. Calculations will work automatically:
--    ✅ Lateness = calculated from login_time vs shift_start
--    ✅ Overtime = calculated from logout_time vs shift_end
--    ✅ Hours worked = calculated from login_time to logout_time
--    ✅ No stored calculations to worry about!
--
-- 5. If you have salary slips that were based on old attendance:
--    - They will show incorrect data
--    - You should regenerate them after fresh import
--    - OR delete them and let managers create new ones
--
-- 6. Shift data (t_ops_rider_profile) is NOT affected
--    - User shifts remain intact
--    - Calculations will use existing shift times
--
-- =====================================================================
-- WHAT GETS DELETED:
-- =====================================================================

-- ✅ t_ops_attendance - ALL records deleted
--    - login_time, logout_time
--    - login_lat, login_lng, logout_lat, logout_lng
--    - device_id, meter_start, meter_end
--    - picture_start, picture_end
--    - notes, created_at, etc.
--
-- =====================================================================
-- WHAT DOES NOT GET DELETED (remains intact):
-- =====================================================================

-- ✅ t_sys_user - User accounts (employees, riders)
-- ✅ t_ops_rider_profile - User shifts (shift_start, shift_end)
-- ✅ t_hr_salary_slips - Salary slips (but may show wrong data)
-- ✅ t_req_master - Leave requests
-- ✅ t_ops_attendance_visibility - Attendance visibility settings
-- ✅ All other tables
--
-- =====================================================================
-- RECOVERY:
-- =====================================================================

-- If you created a backup (STEP 3), you can restore it:
--
-- DROP TABLE IF EXISTS t_ops_attendance;
-- RENAME TABLE t_ops_attendance_backup_20251015 TO t_ops_attendance;
--
-- =====================================================================

SELECT '' as '';
SELECT '==================================================================' as '';
SELECT 'SUMMARY: Run STEP 1 to preview, then uncomment and run STEP 4 to delete' as '';
SELECT '==================================================================' as '';

