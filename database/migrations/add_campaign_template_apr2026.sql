-- =====================================================
-- Migration: Add WhatsApp template fields to campaigns
-- Created: April 2026
-- =====================================================

ALTER TABLE `t_crm_campaigns`
ADD COLUMN IF NOT EXISTS `wa_template_name` VARCHAR(255) NULL DEFAULT NULL
COMMENT 'WhatsApp Business API template name for campaign sends'
AFTER `message_template`,
ADD COLUMN IF NOT EXISTS `wa_template_language` VARCHAR(10) NOT NULL DEFAULT 'en'
COMMENT 'Template language code'
AFTER `wa_template_name`;

-- Also add failed_count for tracking API send failures
ALTER TABLE `t_crm_campaign_customers`
MODIFY COLUMN `status` ENUM('pending', 'sent', 'skipped', 'failed') NOT NULL DEFAULT 'pending';

ALTER TABLE `t_crm_campaign_customers`
ADD COLUMN IF NOT EXISTS `error_message` VARCHAR(500) NULL DEFAULT NULL
AFTER `sent_by`;
