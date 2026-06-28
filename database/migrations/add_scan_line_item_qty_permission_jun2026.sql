-- =====================================================
-- Migration: Add scan_line_item_qty permission
-- Date: 2026-06-27
-- Purpose: Gate the barcode quantity-update endpoint (store mode). A user with
--          this permission can update an open order's line-item quantity by
--          scanning the product's weight barcode in the mobile app.
--
-- Safe + re-runnable. Run on LOCAL and PROD. No behaviour change until the new
-- mobile build ships. Grant additional store roles via the web Roles screen.
-- =====================================================

-- STEP 1: Create the mobile permission (store-mode group).
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('scan_line_item_qty', 'Scan to Update Quantity', 'store_mode',
        'Can update an open order''s line-item quantity by scanning the product weight barcode.', 70)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), display_order = VALUES(display_order);

-- STEP 2: Grant to Admin + Taimur by default (owner grants store roles via the UI).
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE LOWER(r.urole_name) IN ('admin', 'taimur')
AND p.permission_code = 'scan_line_item_qty'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- VERIFICATION (expect at least Admin + Taimur):
-- SELECT r.urole_name, p.permission_code
-- FROM t_sys_role_mobile_permission rpm
-- JOIN t_sys_role r ON r.id = rpm.role_id
-- JOIN t_sys_mobile_permission p ON p.id = rpm.mobile_permission_id
-- WHERE p.permission_code = 'scan_line_item_qty'
-- ORDER BY r.urole_name;
