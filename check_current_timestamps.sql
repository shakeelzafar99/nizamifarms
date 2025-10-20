-- Check current timezone configuration and actual stored timestamps
-- This will help us understand what's being stored vs what's being displayed

-- 1. Check Laravel timezone config
SELECT 'Laravel Config' as info, 'UTC (default)' as timezone;

-- 2. Check MySQL timezone
SELECT 'MySQL Timezone' as info, @@session.time_zone as timezone;

-- 3. Check recent expense requests with all timestamp fields
SELECT 
    'Recent Expense Requests' as section,
    request_number,
    title,
    created_at,
    submitted_at,
    completed_at,
    updated_at,
    NOW() as current_db_time,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_since_creation
FROM t_req_master
ORDER BY created_at DESC
LIMIT 5;

-- 4. Check recent ledger transactions
SELECT 
    'Recent Ledger Transactions' as section,
    id,
    description,
    transaction_date,
    created_at,
    approval_date,
    NOW() as current_db_time,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_since_creation
FROM t_fin_ledger
ORDER BY created_at DESC
LIMIT 5;

-- 5. Check recent salary slips
SELECT 
    'Recent Salary Slips' as section,
    slip_number,
    created_at,
    approved_at,
    paid_at,
    NOW() as current_db_time,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_since_creation
FROM t_hr_salary_slips
ORDER BY created_at DESC
LIMIT 5;

-- 6. Check recent attendance records
SELECT 
    'Recent Attendance' as section,
    id,
    employee_id,
    check_in_time,
    check_out_time,
    created_at,
    NOW() as current_db_time,
    TIMESTAMPDIFF(HOUR, created_at, NOW()) as hours_since_creation
FROM t_hr_attendance
ORDER BY created_at DESC
LIMIT 5;

-- 7. Compare: What time is it RIGHT NOW?
SELECT 
    'Current Time Comparison' as info,
    NOW() as mysql_now,
    UTC_TIMESTAMP() as mysql_utc,
    TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), NOW()) as timezone_offset_hours;

