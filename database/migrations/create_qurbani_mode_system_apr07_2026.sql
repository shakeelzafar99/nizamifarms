-- =====================================================
-- Qurbani Mode System - Complete Database Migration
-- Date: April 7, 2026
-- Description: Adds Qurbani mode support including:
--   1. Mobile permission: access_qurbani_mode (granted to Taimur + Management + Admin)
--   2. Qurbani fields on orders: qurbani_day, qurbani_slot, qurbani_region, qurbani_delivery_type
--   3. Payment tracking: payment_status + total_paid on orders
--   4. Payment records: t_crm_order_payments table
--   5. Qurbani field options config: t_crm_qurbani_field_options
--
-- INSTRUCTIONS: Run each section one at a time in MySQL Workbench.
--   All statements are safe to re-run (ON DUPLICATE KEY / IF NOT EXISTS).
--   Backup your database before running.
-- =====================================================


-- ============================================================================
-- SECTION 1: Mobile Permission for Qurbani Mode
-- ============================================================================

INSERT INTO t_sys_mobile_permission 
    (permission_code, permission_name, permission_group, description, display_order, is_active) 
VALUES
    ('access_qurbani_mode', 'Access Qurbani Mode', 'qurbani_mode', 
     'Can switch to Qurbani mode to manage qurbani orders, payments, and customers', 1, 1)
ON DUPLICATE KEY UPDATE 
    permission_name = VALUES(permission_name),
    description = VALUES(description);

-- Grant to Taimur role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur'
AND p.permission_code = 'access_qurbani_mode'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Management role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) LIKE '%management%'
AND p.permission_code = 'access_qurbani_mode'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Grant to Admin role (role_id = 1) as fallback
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT 1, id FROM t_sys_mobile_permission WHERE permission_code = 'access_qurbani_mode'
ON DUPLICATE KEY UPDATE role_id = role_id;


-- ============================================================================
-- SECTION 2: Qurbani Fields on Orders Table (t_crm_prod_order)
-- Run these one at a time. If column already exists you'll get a harmless error.
-- ============================================================================

ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `qurbani_day` VARCHAR(50) NULL 
    COMMENT 'Qurbani day assignment: Day 1, Day 2, Day 3';

ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `qurbani_slot` VARCHAR(50) NULL 
    COMMENT 'Qurbani time slot: Afternoon, Evening';

ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `qurbani_region` VARCHAR(100) NULL 
    COMMENT 'Qurbani delivery region: DHA Phase 2, Bahria Phase 8, Islamabad, Rawalpindi';

ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `qurbani_delivery_type` VARCHAR(50) NULL 
    COMMENT 'Delivery or Self Collection';

ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `payment_status` VARCHAR(20) NOT NULL DEFAULT 'unpaid' 
    COMMENT 'Payment collection status: unpaid, partial, paid';

ALTER TABLE `t_crm_prod_order`
    ADD COLUMN `total_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00 
    COMMENT 'Cached sum of all payments received for this order';

-- Indexes for filtering
CREATE INDEX idx_order_qurbani_region ON t_crm_prod_order (qurbani_region);
CREATE INDEX idx_order_qurbani_day ON t_crm_prod_order (qurbani_day);
CREATE INDEX idx_order_payment_status ON t_crm_prod_order (payment_status);


-- ============================================================================
-- SECTION 3: Order Payments Table
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_crm_order_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL COMMENT 'FK to t_crm_prod_order.id',
    `amount` DECIMAL(12,2) NOT NULL COMMENT 'Payment amount received',
    `payment_method` VARCHAR(50) NOT NULL COMMENT 'cash, online, bank_transfer, card',
    `payment_date` DATE NOT NULL COMMENT 'Date payment was received',
    `reference` VARCHAR(255) NULL COMMENT 'Transaction reference / receipt number',
    `notes` TEXT NULL COMMENT 'Optional notes about this payment',
    `ledger_transaction_id` INT NULL COMMENT 'FK to t_fin_ledger.id',
    `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active or voided',
    `created_by` INT NULL COMMENT 'FK to t_sys_user.id - who recorded this payment',
    `updated_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_op_order_id` (`order_id`),
    INDEX `idx_op_payment_date` (`payment_date`),
    INDEX `idx_op_status` (`status`),
    INDEX `idx_op_ledger_txn` (`ledger_transaction_id`),
    INDEX `idx_op_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual payment records against orders for qurbani payment tracking.';


-- ============================================================================
-- SECTION 4: Qurbani Field Options (Customizable Dropdown Values)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_crm_qurbani_field_options` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `field_name` VARCHAR(50) NOT NULL COMMENT 'qurbani_day, qurbani_slot, qurbani_region, qurbani_delivery_type',
    `option_value` VARCHAR(100) NOT NULL COMMENT 'The selectable value',
    `display_order` INT NOT NULL DEFAULT 0 COMMENT 'Sort order in dropdowns',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete: 0 hides from dropdowns',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_field_value` (`field_name`, `option_value`),
    INDEX `idx_qfo_field_active` (`field_name`, `is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Customizable dropdown values for qurbani order fields.';

-- Seed: Qurbani Day
INSERT INTO t_crm_qurbani_field_options (field_name, option_value, display_order) VALUES
('qurbani_day', 'Day 1', 1),
('qurbani_day', 'Day 2', 2),
('qurbani_day', 'Day 3', 3)
ON DUPLICATE KEY UPDATE display_order = VALUES(display_order);

-- Seed: Qurbani Slot
INSERT INTO t_crm_qurbani_field_options (field_name, option_value, display_order) VALUES
('qurbani_slot', 'Afternoon', 1),
('qurbani_slot', 'Evening', 2)
ON DUPLICATE KEY UPDATE display_order = VALUES(display_order);

-- Seed: Qurbani Region (mirrors delivery regions)
INSERT INTO t_crm_qurbani_field_options (field_name, option_value, display_order) VALUES
('qurbani_region', 'DHA Phase 2', 1),
('qurbani_region', 'Bahria Phase 8', 2),
('qurbani_region', 'Islamabad', 3),
('qurbani_region', 'Rawalpindi', 4)
ON DUPLICATE KEY UPDATE display_order = VALUES(display_order);

-- Seed: Qurbani Delivery Type
INSERT INTO t_crm_qurbani_field_options (field_name, option_value, display_order) VALUES
('qurbani_delivery_type', 'Delivery', 1),
('qurbani_delivery_type', 'Self Collection', 2)
ON DUPLICATE KEY UPDATE display_order = VALUES(display_order);


-- ============================================================================
-- SECTION 5: Qurbani Operations Toggle (enabled by default)
-- ============================================================================

INSERT INTO t_fin_config (config_key, config_value, description) VALUES
('qurbani_mode_enabled', '1', 'Enable/disable Qurbani section in web sidebar and mobile')
ON DUPLICATE KEY UPDATE config_key = config_key;


-- ============================================================================
-- SECTION 6: Verify everything
-- ============================================================================

SELECT 
    mp.permission_code,
    mp.permission_name,
    mp.is_active,
    GROUP_CONCAT(r.urole_name ORDER BY r.urole_name) AS assigned_roles
FROM t_sys_mobile_permission mp
LEFT JOIN t_sys_role_mobile_permission rmp ON mp.id = rmp.mobile_permission_id
LEFT JOIN t_sys_role r ON rmp.role_id = r.id
WHERE mp.permission_code = 'access_qurbani_mode'
GROUP BY mp.id, mp.permission_code, mp.permission_name, mp.is_active;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 't_crm_prod_order'
AND COLUMN_NAME IN ('qurbani_day', 'qurbani_slot', 'qurbani_region', 'qurbani_delivery_type', 'payment_status', 'total_paid')
ORDER BY ORDINAL_POSITION;

SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('t_crm_order_payments', 't_crm_qurbani_field_options');

SELECT field_name, COUNT(*) AS option_count, GROUP_CONCAT(option_value ORDER BY display_order) AS options
FROM t_crm_qurbani_field_options
WHERE is_active = 1
GROUP BY field_name
ORDER BY field_name;
