-- =====================================================
-- ASSETS MANAGEMENT WITH BUSINESS UNIT SEGREGATION
-- Migration Date: January 29, 2026
-- =====================================================
-- This migration adds:
-- 1. t_fin_business_units - Business unit master (with role-based admin)
-- 2. t_fin_asset_categories - Asset type categories
-- 3. t_fin_assets - Company assets tracking
-- 4. Mobile permission for assets
-- =====================================================
-- NOTE: Run this on your database (no USE statement - select DB before running)
-- =====================================================

-- =====================================================
-- STEP 1: Create Business Units Table
-- =====================================================
-- This table stores business units like "Nizami Farms", "NF Foods"
-- Only certain roles (Taimur) can add new business units

CREATE TABLE IF NOT EXISTS `t_fin_business_units` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    
    INDEX `idx_business_unit_code` (`code`),
    INDEX `idx_business_unit_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Business units for expense/asset segregation (NF, NF Foods, etc.)';

SELECT '✅ Created t_fin_business_units table' as Status;

-- Insert default business units
INSERT INTO `t_fin_business_units` (`code`, `name`, `description`, `sort_order`, `is_active`, `created_by`)
VALUES 
    ('NF', 'Nizami Farms', 'Main Nizami Farms operations', 1, 1, 1),
    ('NF_FOODS', 'NF Foods', 'NF Foods subsidiary operations', 2, 1, 1)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    description = VALUES(description);

SELECT '✅ Inserted default business units (Nizami Farms, NF Foods)' as Status;

-- =====================================================
-- STEP 2: Create Asset Categories Table
-- =====================================================

CREATE TABLE IF NOT EXISTS `t_fin_asset_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `useful_life_years` INT NULL COMMENT 'Default useful life for depreciation',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Asset categories (equipment, vehicle, furniture, etc.)';

SELECT '✅ Created t_fin_asset_categories table' as Status;

-- Insert default asset categories
INSERT INTO `t_fin_asset_categories` (`code`, `name`, `useful_life_years`, `sort_order`, `is_active`)
VALUES 
    ('EQUIPMENT', 'Equipment', 5, 1, 1),
    ('VEHICLE', 'Vehicle', 8, 2, 1),
    ('FURNITURE', 'Furniture', 10, 3, 1),
    ('ELECTRONICS', 'Electronics', 3, 4, 1),
    ('MACHINERY', 'Machinery', 10, 5, 1),
    ('REFRIGERATION', 'Refrigeration/Chillers', 8, 6, 1),
    ('KITCHEN', 'Kitchen Equipment', 5, 7, 1),
    ('OTHER', 'Other', 5, 99, 1)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name);

SELECT '✅ Inserted default asset categories' as Status;

-- =====================================================
-- STEP 3: Create Assets Table
-- =====================================================

CREATE TABLE IF NOT EXISTS `t_fin_assets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Asset Identity
    `asset_code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Auto-generated: AST-YYYY-XXXX',
    `asset_name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    
    -- Classification
    `business_unit_id` INT NOT NULL COMMENT 'FK to t_fin_business_units',
    `category_id` INT NOT NULL COMMENT 'FK to t_fin_asset_categories',
    
    -- Purchase Details
    `purchase_date` DATE NOT NULL,
    `purchase_amount` DECIMAL(15,2) NOT NULL,
    `vendor_id` INT NULL COMMENT 'Optional: FK to t_fin_vendors',
    `vendor_name_snapshot` VARCHAR(255) NULL COMMENT 'Vendor name at time of purchase',
    `payment_account_id` INT NOT NULL COMMENT 'FK to t_fin_accounts (NF_CASH, ONLINE, etc.)',
    `payment_mode` ENUM('cash', 'online') DEFAULT 'cash',
    
    -- Physical Details
    `serial_number` VARCHAR(100) NULL,
    `model_number` VARCHAR(100) NULL,
    `location` VARCHAR(255) NULL COMMENT 'Physical location/warehouse',
    `condition` ENUM('new', 'good', 'fair', 'poor', 'disposed') DEFAULT 'new',
    
    -- Depreciation (for future)
    `useful_life_years` INT NULL,
    `salvage_value` DECIMAL(15,2) DEFAULT 0.00,
    `depreciation_method` ENUM('none', 'straight_line') DEFAULT 'none',
    `current_book_value` DECIMAL(15,2) NULL,
    
    -- Who Purchased
    `purchased_by` INT NOT NULL COMMENT 'FK to t_sys_user - who made the purchase',
    
    -- Ledger Link
    `ledger_transaction_id` INT NULL COMMENT 'FK to t_fin_ledger',
    
    -- Status
    `status` ENUM('active', 'disposed', 'sold', 'written_off', 'transferred') DEFAULT 'active',
    `disposal_date` DATE NULL,
    `disposal_amount` DECIMAL(15,2) NULL,
    `disposal_notes` TEXT NULL,
    
    -- Attachments (bill/invoice images)
    `bill_image` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    
    -- Audit
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_at` TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    
    -- Indexes
    INDEX `idx_asset_code` (`asset_code`),
    INDEX `idx_asset_business_unit` (`business_unit_id`),
    INDEX `idx_asset_category` (`category_id`),
    INDEX `idx_asset_purchase_date` (`purchase_date`),
    INDEX `idx_asset_status` (`status`),
    INDEX `idx_asset_purchased_by` (`purchased_by`),
    INDEX `idx_asset_ledger` (`ledger_transaction_id`),
    
    -- Foreign Keys
    CONSTRAINT `fk_asset_business_unit` FOREIGN KEY (`business_unit_id`) 
        REFERENCES `t_fin_business_units`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_asset_category` FOREIGN KEY (`category_id`) 
        REFERENCES `t_fin_asset_categories`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_asset_vendor` FOREIGN KEY (`vendor_id`) 
        REFERENCES `t_fin_vendors`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_asset_payment_account` FOREIGN KEY (`payment_account_id`) 
        REFERENCES `t_fin_accounts`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_asset_purchased_by` FOREIGN KEY (`purchased_by`) 
        REFERENCES `t_sys_user`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_asset_ledger` FOREIGN KEY (`ledger_transaction_id`) 
        REFERENCES `t_fin_ledger`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_asset_created_by` FOREIGN KEY (`created_by`) 
        REFERENCES `t_sys_user`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_asset_updated_by` FOREIGN KEY (`updated_by`) 
        REFERENCES `t_sys_user`(`id`) ON DELETE SET NULL
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Company assets registry (equipment, vehicles, furniture, etc.)';

SELECT '✅ Created t_fin_assets table' as Status;

-- =====================================================
-- STEP 4: Create ASSETS Account in Chart of Accounts
-- =====================================================
-- This account will track the total value of fixed assets

INSERT INTO `t_fin_accounts` (
    `account_code`, 
    `account_name`, 
    `account_type`, 
    `account_category`,
    `opening_balance`,
    `current_balance`,
    `is_active`, 
    `created_by`
)
VALUES 
    ('FIXED_ASSETS', 'Fixed Assets', 'asset', 'fixed_asset', 0.00, 0.00, 1, 1),
    ('FIXED_ASSETS_NF', 'Fixed Assets - Nizami Farms', 'asset', 'fixed_asset', 0.00, 0.00, 1, 1),
    ('FIXED_ASSETS_NFFOODS', 'Fixed Assets - NF Foods', 'asset', 'fixed_asset', 0.00, 0.00, 1, 1)
ON DUPLICATE KEY UPDATE 
    account_name = VALUES(account_name),
    account_category = VALUES(account_category);

SELECT '✅ Created Fixed Assets accounts' as Status;

-- =====================================================
-- STEP 5: Add Business Unit to Expenses (t_req_master)
-- =====================================================
-- Add business_unit_id column if it doesn't exist

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'business_unit_id'
);

SET @add_col = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN business_unit_id INT NULL DEFAULT 1 COMMENT ''FK to t_fin_business_units - defaults to Nizami Farms'' AFTER expense_category,
        ADD INDEX idx_req_business_unit (business_unit_id)',
    'SELECT ''Column business_unit_id already exists in t_req_master'' as Status'
);

PREPARE stmt FROM @add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✅ Added business_unit_id to t_req_master (expenses)' as Status;

-- =====================================================
-- STEP 6: Add Business Unit to Ledger (t_fin_ledger)
-- =====================================================

SET @col_exists2 = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_fin_ledger' 
    AND COLUMN_NAME = 'business_unit_id'
);

SET @add_col2 = IF(
    @col_exists2 = 0,
    'ALTER TABLE t_fin_ledger 
        ADD COLUMN business_unit_id INT NULL DEFAULT 1 COMMENT ''FK to t_fin_business_units - defaults to Nizami Farms'' AFTER transaction_type,
        ADD INDEX idx_ledger_business_unit (business_unit_id)',
    'SELECT ''Column business_unit_id already exists in t_fin_ledger'' as Status'
);

PREPARE stmt2 FROM @add_col2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SELECT '✅ Added business_unit_id to t_fin_ledger' as Status;

-- =====================================================
-- STEP 7: Create Permission for Managing Business Units (Web)
-- =====================================================
-- Only Taimur role can add new business units
-- Note: t_sys_role_permissions stores permissions directly with permission_key

INSERT INTO `t_sys_role_permissions` (`role_id`, `permission_key`, `permission_name`, `is_allowed`, `created_at`, `updated_at`)
SELECT r.id, 'manage_business_units', 'Manage Business Units', 1, NOW(), NOW()
FROM t_sys_role r 
WHERE LOWER(r.urole_name) = 'taimur'
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), is_allowed = 1;

SELECT '✅ Created manage_business_units permission (granted to Taimur)' as Status;

-- =====================================================
-- STEP 8: Create Permission for Managing Asset Categories (Web)
-- =====================================================
-- Only Taimur role can add new asset categories

INSERT INTO `t_sys_role_permissions` (`role_id`, `permission_key`, `permission_name`, `is_allowed`, `created_at`, `updated_at`)
SELECT r.id, 'manage_asset_categories', 'Manage Asset Categories', 1, NOW(), NOW()
FROM t_sys_role r 
WHERE LOWER(r.urole_name) = 'taimur'
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), is_allowed = 1;

SELECT '✅ Created manage_asset_categories permission (granted to Taimur)' as Status;

-- =====================================================
-- STEP 9: Create Mobile Permission for Assets
-- =====================================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('view_assets', 'View Assets', 'store_mode_finance', 'Can view company assets in NF Ledger', 50)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

SELECT '✅ Created view_assets mobile permission' as Status;

-- Grant to Admin role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'admin' AND p.permission_code = 'view_assets'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- Grant to Taimur role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'taimur' AND p.permission_code = 'view_assets'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- Grant to Store Manager role
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) = 'store manager' AND p.permission_code = 'view_assets'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- Grant to anyone who has view_nf_ledger permission
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT DISTINCT rpm.role_id, p.id 
FROM t_sys_role_mobile_permission rpm
JOIN t_sys_mobile_permission mp ON rpm.mobile_permission_id = mp.id
CROSS JOIN t_sys_mobile_permission p 
WHERE mp.permission_code = 'view_nf_ledger'
AND p.permission_code = 'view_assets'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Granted view_assets permission to appropriate roles' as Status;

-- =====================================================
-- STEP 10: Add add_assets mobile permission
-- =====================================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('add_assets', 'Add Assets', 'store_mode_finance', 'Can add new company assets', 51)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- Grant to Admin and Taimur roles
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') AND p.permission_code = 'add_assets'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Created add_assets mobile permission' as Status;

-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT '
=====================================
✅ ASSETS MANAGEMENT MIGRATION COMPLETE
=====================================

Tables Created:
1. t_fin_business_units (Nizami Farms, NF Foods)
2. t_fin_asset_categories (8 categories)
3. t_fin_assets (main assets registry)

Accounts Created:
- FIXED_ASSETS (General)
- FIXED_ASSETS_NF (Nizami Farms)  
- FIXED_ASSETS_NFFOODS (NF Foods)

Columns Added:
- t_req_master.business_unit_id
- t_fin_ledger.business_unit_id

Permissions Created:
- manage_business_units (web - Taimur only)
- manage_asset_categories (web - Taimur only)
- view_assets (mobile)
- add_assets (mobile)

=====================================
' as FinalStatus;

-- Show created data
SELECT 'Business Units:' as '';
SELECT id, code, name, is_active FROM t_fin_business_units;

SELECT 'Asset Categories:' as '';
SELECT id, code, name FROM t_fin_asset_categories ORDER BY sort_order;

SELECT 'Fixed Asset Accounts:' as '';
SELECT account_code, account_name, account_type, account_category 
FROM t_fin_accounts 
WHERE account_code LIKE 'FIXED_ASSETS%';

SELECT 'Mobile Permissions:' as '';
SELECT permission_code, permission_name, permission_group 
FROM t_sys_mobile_permission 
WHERE permission_code IN ('view_assets', 'add_assets');
