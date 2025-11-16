-- Add Approvals Mobile Permissions
-- Date: November 15, 2025
-- Purpose: Add mobile permissions for viewing and managing approvals

-- Insert approval permissions
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, is_active, created_at, updated_at)
VALUES 
    ('view_approvals', 'View Approvals', 'store_mode_approvals', 'View pending approval requests in mobile app', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    permission_group = VALUES(permission_group),
    description = VALUES(description),
    updated_at = NOW();

-- Note: This permission will be checked in the mobile app to show/hide the Approvals menu item
-- Users must also have level_1_approval or level_2_approval role permissions (from t_sys_role_approval_level)
-- to actually see and approve items

