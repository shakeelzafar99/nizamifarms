-- Check which users have HR profiles
USE nizamifarms_db;

-- All HR profiles that exist
SELECT * FROM t_hr_employee_profile;

-- Users with profiles
SELECT 
    u.id,
    u.fullname,
    p.id as profile_id,
    p.base_salary,
    p.is_active
FROM t_sys_user u
LEFT JOIN t_hr_employee_profile p ON u.id = p.user_id
WHERE u.is_active = 1
ORDER BY u.fullname;

