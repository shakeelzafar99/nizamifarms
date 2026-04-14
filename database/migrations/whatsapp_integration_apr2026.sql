-- ============================================================================
-- WhatsApp Business API Integration - Database Migration
-- Run this in MySQL Workbench against your production database
-- Date: April 2026
-- ============================================================================

-- 1. Conversations table
CREATE TABLE IF NOT EXISTS `t_wa_conversations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `wa_phone` VARCHAR(20) NOT NULL COMMENT 'WhatsApp number e.g. 923001234567',
    `wa_contact_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Profile name from WhatsApp',
    `status` ENUM('active', 'expired', 'closed') NOT NULL DEFAULT 'active',
    `last_message_at` DATETIME NULL DEFAULT NULL,
    `last_customer_message_at` DATETIME NULL DEFAULT NULL COMMENT 'Tracks the 24-hour free messaging window',
    `unread_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `assigned_to` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Staff user assigned to this conversation',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_wa_phone` (`wa_phone`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_assigned_to` (`assigned_to`),
    INDEX `idx_last_message_at` (`last_message_at`),
    INDEX `idx_unread` (`unread_count`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Messages table
CREATE TABLE IF NOT EXISTS `t_wa_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `wa_message_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Meta message ID for status tracking',
    `direction` ENUM('inbound', 'outbound') NOT NULL,
    `type` VARCHAR(30) NOT NULL DEFAULT 'text' COMMENT 'text, template, image, document, location, audio, video, reaction, button, interactive',
    `content` TEXT NULL DEFAULT NULL COMMENT 'Message text body or caption',
    `template_name` VARCHAR(100) NULL DEFAULT NULL COMMENT 'Template name if type=template',
    `template_params` JSON NULL DEFAULT NULL COMMENT 'Template parameter values',
    `media_url` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Local path to downloaded media file',
    `media_mime_type` VARCHAR(100) NULL DEFAULT NULL,
    `status` ENUM('sent', 'delivered', 'read', 'failed', 'received') NOT NULL DEFAULT 'sent',
    `status_updated_at` DATETIME NULL DEFAULT NULL,
    `sent_by` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Staff user who sent outbound message',
    `error_code` VARCHAR(50) NULL DEFAULT NULL,
    `error_message` VARCHAR(500) NULL DEFAULT NULL,
    `metadata` JSON NULL DEFAULT NULL COMMENT 'Extra data: location coords, button payload, reaction info',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_wa_message_id` (`wa_message_id`),
    INDEX `idx_conversation_id` (`conversation_id`),
    INDEX `idx_direction` (`direction`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sent_by` (`sent_by`),
    CONSTRAINT `fk_wa_messages_conversation` FOREIGN KEY (`conversation_id`) 
        REFERENCES `t_wa_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Templates table (tracks your approved WhatsApp templates)
CREATE TABLE IF NOT EXISTS `t_wa_templates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL COMMENT 'Template name as registered in WhatsApp Business Manager',
    `display_name` VARCHAR(255) NOT NULL COMMENT 'Human-readable name shown in UI',
    `category` ENUM('utility', 'marketing', 'authentication') NOT NULL DEFAULT 'utility',
    `language` VARCHAR(10) NOT NULL DEFAULT 'en',
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
    `header_text` VARCHAR(255) NULL DEFAULT NULL,
    `body_text` TEXT NOT NULL COMMENT 'Template body with {{1}} {{2}} placeholders',
    `footer_text` VARCHAR(60) NULL DEFAULT NULL,
    `variable_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `has_buttons` TINYINT(1) NOT NULL DEFAULT 0,
    `button_labels` JSON NULL DEFAULT NULL COMMENT 'Array of button label strings',
    `show_in` VARCHAR(100) NOT NULL DEFAULT 'messages,orders,customers,shopify' COMMENT 'Comma-separated contexts where template appears',
    `meta_template_id` VARCHAR(100) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_name_lang` (`name`, `language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Cost tracking log
CREATE TABLE IF NOT EXISTS `t_wa_cost_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `wa_phone` VARCHAR(20) NULL DEFAULT NULL,
    `message_type` VARCHAR(30) NOT NULL COMMENT 'text, template, image, etc.',
    `template_name` VARCHAR(100) NULL DEFAULT NULL,
    `category` VARCHAR(30) NOT NULL DEFAULT 'service' COMMENT 'utility, marketing, service, authentication',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Device tokens for push notifications (Firebase FCM)
CREATE TABLE IF NOT EXISTS `t_wa_device_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `fcm_token` VARCHAR(500) NOT NULL,
    `device_type` VARCHAR(20) NOT NULL DEFAULT 'android' COMMENT 'android or ios',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_fcm_token` (`fcm_token`(191)),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. Insert the two new mobile permissions
--    Columns: permission_code, permission_name, permission_group, description, display_order, is_active
-- ============================================================================
INSERT INTO `t_sys_mobile_permission` (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`, `is_active`)
VALUES 
    ('view_whatsapp_messages', 'View WhatsApp Messages', 'store_mode_orders', 'Can see the WhatsApp Messages inbox and read conversations', 25, 1),
    ('send_whatsapp_messages', 'Send WhatsApp Messages', 'store_mode_orders', 'Can send replies and template messages via WhatsApp', 26, 1)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- 6b. Grant both permissions to Taimur role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code IN ('view_whatsapp_messages', 'send_whatsapp_messages')
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 6c. Grant both permissions to Management role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) LIKE '%management%'
AND p.permission_code IN ('view_whatsapp_messages', 'send_whatsapp_messages')
ON DUPLICATE KEY UPDATE role_id = role_id;

-- 6d. Grant both permissions to Admin role (role_id = 1) as fallback
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission 
WHERE permission_code IN ('view_whatsapp_messages', 'send_whatsapp_messages')
ON DUPLICATE KEY UPDATE role_id = role_id;

-- ============================================================================
-- 7. Seed the approved template (capacity_full) - add others as they get approved
-- ============================================================================
INSERT INTO `t_wa_templates` (`name`, `display_name`, `category`, `language`, `status`, `header_text`, `body_text`, `footer_text`, `variable_count`, `has_buttons`, `button_labels`)
VALUES (
    'capacity_full',
    'Capacity Full - Tomorrow Delivery',
    'utility',
    'en',
    'approved',
    'Order Update',
    'Dear {{1}},\n\nWe received your order (Order No: {{2}}) on our website today.\n\nAs we are fully occupied today, your order can be delivered fresh tomorrow. Would you like to confirm delivery for tomorrow?\n\nThank you for choosing Nizami Farms.',
    'Nizami Farms',
    2,
    1,
    '["Yes, deliver tomorrow", "Cancel order"]'
);

-- ============================================================================
-- 8. Add show_in column for template context/visibility
-- ============================================================================
-- Run this if upgrading from the original schema (column already in CREATE TABLE above for new installs):
ALTER TABLE `t_wa_templates` ADD COLUMN IF NOT EXISTS `show_in` VARCHAR(100) NOT NULL DEFAULT 'messages,orders,customers,shopify' AFTER `button_labels`;
