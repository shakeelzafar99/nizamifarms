-- ============================================================
-- Migration: Add online payment WhatsApp message tracking
-- Date: Feb 17, 2026
-- Purpose: Track whether rider sent WhatsApp payment reminder 
--          for online/bank transfer orders after delivery
-- ============================================================

-- Add columns to track WhatsApp message sent status
ALTER TABLE t_crm_prod_order 
ADD COLUMN online_message_sent_at DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when WhatsApp payment reminder was sent/opened',
ADD COLUMN online_message_sent_by INT NULL DEFAULT NULL COMMENT 'User ID of rider who sent the message';

-- Add index for efficient querying of pending messages  
-- (online orders that are delivered but message not sent)
ALTER TABLE t_crm_prod_order 
ADD INDEX idx_online_message_pending (order_status, payment_method, online_message_sent_at);

-- Verify columns were added
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 't_crm_prod_order' 
AND COLUMN_NAME IN ('online_message_sent_at', 'online_message_sent_by');
