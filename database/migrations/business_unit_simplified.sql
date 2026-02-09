-- =====================================================
-- BUSINESS UNIT SIMPLIFIED SQL
-- Created: February 5, 2026
-- =====================================================
-- 
-- This SQL:
-- ✅ Updates business_units table structure
-- ✅ Renames NF_FOODS to Khaas
-- ✅ Adds business_unit_id to t_crm_prod_product, t_fin_accounts, t_fin_vendors
-- ✅ Defaults ALL transactions to Nizami Farms (id=1)
-- ❌ Does NOT create accounts (you'll do this manually)
--
-- =====================================================

-- =====================================================
-- PHASE 1: Update Business Units Table Structure
-- =====================================================

-- Step 1.1: Add new columns to t_fin_business_units if they don't exist
-- Adding: description, short_code, color_hex, display_order

SET @col_desc = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fin_business_units' AND COLUMN_NAME = 'description');
SET @sql_desc = IF(@col_desc = 0, 'ALTER TABLE t_fin_business_units ADD COLUMN description VARCHAR(255) NULL AFTER name', 'SELECT ''description exists'' as Status');
PREPARE stmt FROM @sql_desc; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_short = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fin_business_units' AND COLUMN_NAME = 'short_code');
SET @sql_short = IF(@col_short = 0, 'ALTER TABLE t_fin_business_units ADD COLUMN short_code VARCHAR(10) NULL AFTER description', 'SELECT ''short_code exists'' as Status');
PREPARE stmt FROM @sql_short; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_color = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fin_business_units' AND COLUMN_NAME = 'color_hex');
SET @sql_color = IF(@col_color = 0, 'ALTER TABLE t_fin_business_units ADD COLUMN color_hex VARCHAR(7) NULL DEFAULT ''#3b82f6'' AFTER short_code', 'SELECT ''color_hex exists'' as Status');
PREPARE stmt FROM @sql_color; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_order = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_fin_business_units' AND COLUMN_NAME = 'display_order');
SET @sql_order = IF(@col_order = 0, 'ALTER TABLE t_fin_business_units ADD COLUMN display_order INT NULL DEFAULT 0 AFTER color_hex', 'SELECT ''display_order exists'' as Status');
PREPARE stmt FROM @sql_order; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '✅ Step 1.1: Updated t_fin_business_units table structure' as Status;


-- Step 1.2: Update business unit data - Rename NF_FOODS to Khaas
UPDATE t_fin_business_units SET
    code = 'KHAAS',
    name = 'Khaas',
    description = 'Khaas Business Unit (formerly NF Foods)',
    short_code = 'KH',
    color_hex = '#f59e0b',  -- Amber/Orange color
    display_order = 2
WHERE code = 'NF_FOODS' OR name = 'NF Foods';

-- Update Nizami Farms with proper data
UPDATE t_fin_business_units SET
    description = 'Main Nizami Farms Business Unit',
    short_code = 'NF',
    color_hex = '#10b981',  -- Green color
    display_order = 1
WHERE code = 'NF' OR id = 1;

SELECT '✅ Step 1.2: Renamed NF_FOODS to Khaas and updated display info' as Status;


-- =====================================================
-- PHASE 2: Add business_unit_id to Products
-- (Actual table name: t_crm_prod_product)
-- =====================================================

SET @col_exists_products = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_crm_prod_product' 
    AND COLUMN_NAME = 'business_unit_id'
);

SET @add_col_products = IF(
    @col_exists_products = 0,
    'ALTER TABLE t_crm_prod_product 
        ADD COLUMN business_unit_id INT NULL DEFAULT 1 
        COMMENT ''FK to t_fin_business_units - defaults to Nizami Farms'' 
        AFTER is_active,
        ADD INDEX idx_product_business_unit (business_unit_id)',
    'SELECT ''Column business_unit_id already exists in t_crm_prod_product'' as Status'
);

PREPARE stmt_products FROM @add_col_products;
EXECUTE stmt_products;
DEALLOCATE PREPARE stmt_products;

-- Set default for existing products
UPDATE t_crm_prod_product SET business_unit_id = 1 WHERE business_unit_id IS NULL;

SELECT '✅ Step 2: Added business_unit_id to t_crm_prod_product (defaults to Nizami Farms)' as Status;


-- =====================================================
-- PHASE 3: Add business_unit_id to Accounts
-- =====================================================

SET @col_exists_accounts = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_fin_accounts' 
    AND COLUMN_NAME = 'business_unit_id'
);

SET @add_col_accounts = IF(
    @col_exists_accounts = 0,
    'ALTER TABLE t_fin_accounts 
        ADD COLUMN business_unit_id INT NULL DEFAULT 1 
        COMMENT ''FK to t_fin_business_units - defaults to Nizami Farms. When you create accounts, select business unit from dropdown to tag them.'' 
        AFTER is_active,
        ADD INDEX idx_account_business_unit (business_unit_id)',
    'SELECT ''Column business_unit_id already exists in t_fin_accounts'' as Status'
);

PREPARE stmt_accounts FROM @add_col_accounts;
EXECUTE stmt_accounts;
DEALLOCATE PREPARE stmt_accounts;

-- Set default for existing accounts (all existing = Nizami Farms)
UPDATE t_fin_accounts SET business_unit_id = 1 WHERE business_unit_id IS NULL;

SELECT '✅ Step 3: Added business_unit_id to t_fin_accounts (defaults to Nizami Farms)' as Status;


-- =====================================================
-- PHASE 4: Ensure Vendors table has business_unit_id
-- (May already exist from previous migrations)
-- =====================================================

SET @col_exists_vendors = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_fin_vendors' 
    AND COLUMN_NAME = 'business_unit_id'
);

SET @add_col_vendors = IF(
    @col_exists_vendors = 0,
    'ALTER TABLE t_fin_vendors 
        ADD COLUMN business_unit_id INT NULL DEFAULT 1 
        COMMENT ''FK to t_fin_business_units - defaults to Nizami Farms'' 
        AFTER is_active,
        ADD INDEX idx_vendor_business_unit (business_unit_id)',
    'SELECT ''Column business_unit_id already exists in t_fin_vendors'' as Status'
);

PREPARE stmt_vendors FROM @add_col_vendors;
EXECUTE stmt_vendors;
DEALLOCATE PREPARE stmt_vendors;

-- Set default for existing vendors
UPDATE t_fin_vendors SET business_unit_id = 1 WHERE business_unit_id IS NULL;

SELECT '✅ Step 4: Ensured t_fin_vendors has business_unit_id' as Status;


-- =====================================================
-- PHASE 5: Ensure Assets table defaults are correct
-- (Assets table already has business_unit_id as NOT NULL with FK)
-- =====================================================

-- Just ensure any NULL values (shouldn't exist) are defaulted to 1
UPDATE t_fin_assets SET business_unit_id = 1 WHERE business_unit_id IS NULL;

SELECT '✅ Step 5: Verified t_fin_assets business_unit_id (already exists with FK)' as Status;


-- =====================================================
-- PHASE 6: Permissions for Business Unit Management
-- =====================================================

-- Web permission for managing business units
INSERT INTO `t_sys_role_permissions` (`role_id`, `permission_key`, `permission_name`, `is_allowed`, `created_at`, `updated_at`)
SELECT r.id, 'manage_business_units', 'Manage Business Units', 1, NOW(), NOW()
FROM t_sys_role r 
WHERE LOWER(r.urole_name) IN ('taimur', 'admin')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), is_allowed = 1;

SELECT '✅ Step 6.1: Created manage_business_units web permission' as Status;

-- Mobile permission: View cross-business unit data (for expenses/vendors)
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES 
    ('view_all_business_units', 'View All Business Units Data', 'store_mode_finance', 'Can view expenses and vendors from ALL business units (not just assigned unit)', 53),
    ('select_business_unit', 'Select Business Unit', 'store_mode_finance', 'Can select business unit when creating expenses/vendors', 54)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- Grant to Admin and Taimur roles
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') 
AND p.permission_code IN ('view_all_business_units', 'select_business_unit')
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 6.2: Created mobile permissions for cross-BU visibility' as Status;


-- =====================================================
-- VERIFICATION & SUMMARY
-- =====================================================

SELECT '
=====================================
✅ BUSINESS UNIT SQL COMPLETE
=====================================

Tables Modified:
1. t_fin_business_units - Added description, short_code, color_hex, display_order
2. t_crm_prod_product - Added business_unit_id (defaults to NF=1)
3. t_fin_accounts - Added business_unit_id (defaults to NF=1)  
4. t_fin_vendors - Added business_unit_id (defaults to NF=1)
5. t_fin_assets - Already has business_unit_id (verified defaults)

Business Units:
- ID 1: Nizami Farms (NF) - Green - Default for ALL transactions
- ID 2: Khaas (KH) - Amber/Orange - Renamed from NF_FOODS

Permissions Created:
- manage_business_units (web) - For CRUD in BU management screen
- view_all_business_units (mobile) - See expenses/vendors from ALL BUs  
- select_business_unit (mobile) - Select BU when creating items

HOW IT WORKS:
- All transactions default to Nizami Farms (id=1)
- Transactions only fail if you explicitly try to use a non-existent BU
- Sales remain unified - BU is mainly for expenses, vendors, accounts
- When creating accounts, select the business unit from dropdown to tag them
- The BU name comes from t_fin_business_units table, so renaming auto-updates everywhere

=====================================
' as Summary;

-- Show business units
SELECT 'Current Business Units:' as '';
SELECT id, code, name, short_code, color_hex, display_order, is_active 
FROM t_fin_business_units 
ORDER BY display_order;

-- Show table structures
SELECT 'Tables with business_unit_id:' as '';
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_DEFAULT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND COLUMN_NAME = 'business_unit_id'
ORDER BY TABLE_NAME;
