-- =====================================================
-- Mobile Permissions: Overall Ledger & Daily Closing
-- Date: November 20, 2025
-- Description: Adds view_overall_ledger and view_daily_closing permissions for mobile app
-- =====================================================

-- Step 1: Add Overall Ledger Mobile Permission
INSERT INTO t_sys_mobile_permission (
    permission_code,
    permission_name,
    permission_group,
    description,
    display_order,
    is_active,
    created_at,
    updated_at
) VALUES (
    'view_overall_ledger',
    'View Overall Ledger',
    'finance',
    'Access to view overall ledger summary with KPIs and financial data',
    100,
    1,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    updated_at = NOW();

-- Step 2: Add Daily Closing Mobile Permission
INSERT INTO t_sys_mobile_permission (
    permission_code,
    permission_name,
    permission_group,
    description,
    display_order,
    is_active,
    created_at,
    updated_at
) VALUES (
    'view_daily_closing',
    'View Daily Closing',
    'finance',
    'Access to view and manage daily closing with invoice settlements and approvals',
    101,
    1,
    NOW(),
    NOW()
) ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description),
    updated_at = NOW();

-- Step 3: Grant permissions to Super Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (
    role_id,
    permission_id,
    created_at
)
SELECT 
    1, -- Super Admin role
    id,
    NOW()
FROM t_sys_mobile_permission
WHERE permission_code IN ('view_overall_ledger', 'view_daily_closing')
AND NOT EXISTS (
    SELECT 1 
    FROM t_sys_role_mobile_permission 
    WHERE role_id = 1 
    AND permission_id = t_sys_mobile_permission.id
);

-- Step 4: Grant permissions to Admin role (role_id = 2)
INSERT INTO t_sys_role_mobile_permission (
    role_id,
    permission_id,
    created_at
)
SELECT 
    2, -- Admin role
    id,
    NOW()
FROM t_sys_mobile_permission
WHERE permission_code IN ('view_overall_ledger', 'view_daily_closing')
AND NOT EXISTS (
    SELECT 1 
    FROM t_sys_role_mobile_permission 
    WHERE role_id = 2 
    AND permission_id = t_sys_mobile_permission.id
);

-- =====================================================
-- Verification Queries (run separately to check)
-- =====================================================

-- Check inserted permissions
-- SELECT 
--     permission_code,
--     permission_name,
--     permission_group,
--     description,
--     is_active
-- FROM t_sys_mobile_permission
-- WHERE permission_code IN ('view_overall_ledger', 'view_daily_closing');

-- Check role assignments
-- SELECT 
--     r.role_name,
--     p.permission_code,
--     p.permission_name
-- FROM t_sys_role r
-- JOIN t_sys_role_mobile_permission rp ON rp.role_id = r.id
-- JOIN t_sys_mobile_permission p ON p.id = rp.permission_id
-- WHERE p.permission_code IN ('view_overall_ledger', 'view_daily_closing')
-- ORDER BY r.role_name, p.permission_code;

