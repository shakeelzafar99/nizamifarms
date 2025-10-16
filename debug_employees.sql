-- Debug: Check what users exist and their types
USE nizamifarms_db;

-- Check all active users
SELECT 
    id,
    fullname,
    email,
    user_type,
    is_active
FROM t_sys_user
WHERE is_active = 1
ORDER BY fullname;

-- Count by user type
SELECT 
    user_type,
    is_active,
    COUNT(*) as count
FROM t_sys_user
GROUP BY user_type, is_active;

-- Check if any HR profiles exist
SELECT COUNT(*) as profile_count FROM t_hr_employee_profile;

-- Check users with profiles
SELECT 
    u.id,
    u.fullname,
    u.user_type,
    p.base_salary,
    p.is_active as profile_active
FROM t_sys_user u
LEFT JOIN t_hr_employee_profile p ON u.id = p.user_id
WHERE u.is_active = 1
ORDER BY u.fullname;

