-- Migration: Create payment method history table
-- Date: November 8, 2025
-- Purpose: Track payment method changes for orders

-- Create payment method history table
CREATE TABLE IF NOT EXISTS `t_crm_order_payment_method_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT UNSIGNED NOT NULL,
    `old_method` VARCHAR(50) NULL,
    `new_method` VARCHAR(50) NOT NULL,
    `changed_by_user_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_changed_at` (`changed_at`),
    FOREIGN KEY (`order_id`) REFERENCES `t_crm_prod_order`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add comment
ALTER TABLE `t_crm_order_payment_method_history` 
COMMENT = 'Tracks payment method changes for orders';



