-- Migration: Add Store Mode Attendance Mobile Permission
-- Date: November 19, 2025
-- Description: Adds view_store_attendance permission for mobile app

-- Step 1: Add the new mobile permission
INSERT INTO t_sys_mobile_permission (
    permission_code,
    permission_name,
    permission_description,
    permission_group,
    is_active,
    created_at,
    updated_at
) VALUES (
    'view_store_attendance',
    'View Store Attendance',
    'View and manage attendance for all employees in store mode',
    'Store Mode',
    1,
    NOW(),
    NOW()
);

-- Step 2: Grant permission to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (
    role_id,
    permission_id,
    created_at,
    updated_at
)
SELECT 
    1, -- Admin role
    id,
    NOW(),
    NOW()
FROM t_sys_mobile_permission
WHERE permission_code = 'view_store_attendance'
AND NOT EXISTS (
    SELECT 1 
    FROM t_sys_role_mobile_permission 
    WHERE role_id = 1 
    AND permission_id = (SELECT id FROM t_sys_mobile_permission WHERE permission_code = 'view_store_attendance')
);

-- Verification Query (run separately to check)
-- SELECT 
--     p.permission_code,
--     p.permission_name,
--     r.urole_name
-- FROM t_sys_mobile_permission p
-- JOIN t_sys_role_mobile_permission rp ON rp.permission_id = p.id
-- JOIN t_sys_role r ON r.id = rp.role_id
-- WHERE p.permission_code = 'view_store_attendance';

