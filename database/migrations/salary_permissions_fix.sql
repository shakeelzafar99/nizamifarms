-- =====================================================================
-- FIX: Add Salary Management Permissions to Role-Based System
-- =====================================================================
-- Purpose: Add HR/Salary permissions to t_sys_role_permissions table
-- Date: October 15, 2025
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- Add Salary Management Permissions to Roles
-- =====================================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Adding Salary Management Permissions' as '';
SELECT '========================================' as '';
SELECT '' as '';

-- 1. View Employee Salaries
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_employee_salaries' as permission_key,
    'View Employee Salaries' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0  -- Riders and others: NO access
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_employee_salaries'
);

SELECT '✓ Added view_employee_salaries permission' as Status;

-- 2. Manage Employee Salaries (Edit configurations)
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'manage_employee_salaries' as permission_key,
    'Manage Employee Salaries' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin') THEN 1
        WHEN r.type = 'manager' THEN 1  -- Managers can also manage
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'manage_employee_salaries'
);

SELECT '✓ Added manage_employee_salaries permission' as Status;

-- 3. Generate Salary Slips
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'generate_salary_slips' as permission_key,
    'Generate Salary Slips' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'generate_salary_slips'
);

SELECT '✓ Added generate_salary_slips permission' as Status;

-- 4. Approve Salary Slips
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'approve_salary_slips' as permission_key,
    'Approve Salary Slips' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin') THEN 1
        WHEN r.type = 'manager' THEN 1  -- Managers can approve
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'approve_salary_slips'
);

SELECT '✓ Added approve_salary_slips permission' as Status;

-- 5. View Employee Loans
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_employee_loans' as permission_key,
    'View Employee Loans' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_employee_loans'
);

SELECT '✓ Added view_employee_loans permission' as Status;

-- 6. Manage Employee Loans (Create, Edit)
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'manage_employee_loans' as permission_key,
    'Manage Employee Loans' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin') THEN 1
        WHEN r.type = 'manager' THEN 1  -- Managers can manage loans
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'manage_employee_loans'
);

SELECT '✓ Added manage_employee_loans permission' as Status;

-- 7. View Own Salary (All employees can view their own)
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'view_own_salary' as permission_key,
    'View Own Salary' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager', 'rider', 'employee') THEN 1
        ELSE 1  -- Everyone can view their own salary
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'view_own_salary'
);

SELECT '✓ Added view_own_salary permission' as Status;

-- 8. Approve Salary Advances (L1 Approvers)
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'approve_salary_advance_l1' as permission_key,
    'Approve Salary Advance (L1)' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin', 'manager') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'approve_salary_advance_l1'
);

SELECT '✓ Added approve_salary_advance_l1 permission' as Status;

-- 9. Approve Salary Advances (L2 Approvers)
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at, updated_at)
SELECT 
    r.id as role_id,
    'approve_salary_advance_l2' as permission_key,
    'Approve Salary Advance (L2)' as permission_name,
    CASE 
        WHEN r.type IN ('admin', 'super_admin') THEN 1
        ELSE 0
    END as is_allowed,
    NOW() as created_at,
    NOW() as updated_at
FROM t_sys_role r
WHERE NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions 
    WHERE role_id = r.id AND permission_key = 'approve_salary_advance_l2'
);

SELECT '✓ Added approve_salary_advance_l2 permission' as Status;

-- =====================================================================
-- VERIFICATION
-- =====================================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Verification - Salary Permissions by Role' as '';
SELECT '========================================' as '';
SELECT '' as '';

SELECT 
    r.urole_name as 'Role',
    r.type as 'Type',
    rp.permission_key as 'Permission',
    rp.permission_name as 'Name',
    CASE WHEN rp.is_allowed = 1 THEN '✓ YES' ELSE '✗ NO' END as 'Allowed'
FROM t_sys_role r
LEFT JOIN t_sys_role_permissions rp ON rp.role_id = r.id
WHERE rp.permission_key IN (
    'view_employee_salaries',
    'manage_employee_salaries',
    'generate_salary_slips',
    'approve_salary_slips',
    'view_employee_loans',
    'manage_employee_loans',
    'view_own_salary',
    'approve_salary_advance_l1',
    'approve_salary_advance_l2'
)
ORDER BY r.type, r.urole_name, rp.permission_key;

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓✓✓ Salary Permissions Added Successfully! ✓✓✓' as '';
SELECT '========================================' as '';
SELECT 'Total: 9 salary management permissions' as '';
SELECT 'Applied to all roles with appropriate access levels' as '';
SELECT '' as '';


