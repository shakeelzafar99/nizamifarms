-- =====================================================
-- Add NF Ledger Mobile Permission
-- Date: November 18, 2025
-- Purpose: Add mobile permission for viewing NF Ledger (accounts and transactions)
-- =====================================================

-- Insert NF Ledger Permission
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order, is_active, created_at, updated_at) VALUES
('view_nf_ledger', 'View NF Ledger', 'store_mode_finance', 'Can view NF Ledger accounts and transaction history in store mode', 50, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    permission_group = VALUES(permission_group),
    description = VALUES(description),
    display_order = VALUES(display_order),
    is_active = VALUES(is_active),
    updated_at = NOW();

-- Grant NF Ledger permission to Admin role (role_id = 1)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'view_nf_ledger'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- =====================================================
-- Verification Queries
-- =====================================================

-- Check if permission was created
SELECT * FROM t_sys_mobile_permission WHERE permission_code = 'view_nf_ledger';

-- Check if permission was assigned to Admin role
SELECT 
    r.role_name,
    mp.permission_code,
    mp.permission_name,
    mp.permission_group,
    mp.description
FROM t_sys_role_mobile_permission rmp
JOIN t_sys_role r ON rmp.role_id = r.id
JOIN t_sys_mobile_permission mp ON rmp.mobile_permission_id = mp.id
WHERE mp.permission_code = 'view_nf_ledger';

-- View all finance-related mobile permissions
SELECT * FROM t_sys_mobile_permission WHERE permission_group = 'store_mode_finance' ORDER BY display_order;

