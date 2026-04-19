-- =============================================================================
-- Add the "view_whatsapp_messages_limited" mobile permission.
--
-- Users with this permission can access the WhatsApp Messages inbox but will
-- only see conversations / messages from the last two days (today and
-- yesterday). This is enforced in both the web and mobile API controllers.
-- Useful for giving limited store staff visibility into recent orders
-- without exposing the full customer-message history.
--
-- Safe to re-run: uses INSERT ... ON DUPLICATE KEY UPDATE.
-- =============================================================================

INSERT INTO `t_sys_mobile_permission`
    (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`, `is_active`)
VALUES
    ('view_whatsapp_messages_limited', 'View WhatsApp Messages (Last 2 Days)', 'store_mode_orders',
     'Can see the WhatsApp Messages inbox but restricted to conversations and messages from today and yesterday only.', 27, 1)
ON DUPLICATE KEY UPDATE
    permission_name = VALUES(permission_name),
    description     = VALUES(description),
    is_active       = VALUES(is_active);

-- Admin role always gets the new permission so it stays fully functional.
INSERT INTO `t_sys_role_mobile_permission` (role_id, mobile_permission_id)
SELECT 1, id
FROM `t_sys_mobile_permission`
WHERE permission_code = 'view_whatsapp_messages_limited'
ON DUPLICATE KEY UPDATE role_id = role_id;
