-- =====================================================
-- BUSINESS UNIT FULL IMPLEMENTATION PLAN
-- Created: February 4, 2026
-- =====================================================
-- 
-- OVERVIEW:
-- This SQL implements full business unit segregation for:
-- 1. Vendors - Assign vendors to specific business units (NF, NF Foods)
-- 2. Expenses - Track expenses by business unit  
-- 3. Vendor Payments - Separate payment tracking per business unit
-- 4. Reports - Filter P&L by business unit
--
-- CURRENT STATE:
-- ✅ t_fin_business_units table exists (NF, NF_FOODS)
-- ✅ t_fin_assets.business_unit_id exists and works
-- ✅ t_fin_ledger.business_unit_id column exists (defaults to 1)
-- ✅ t_req_master.business_unit_id column exists (defaults to 1)
-- ❌ t_fin_vendors - NO business_unit_id column
-- ❌ UI for business unit selection in expenses/vendors
-- ❌ Reporting by business unit
--
-- =====================================================

-- =====================================================
-- PHASE 1: Add Business Unit to Vendors
-- =====================================================

-- Step 1.1: Add business_unit_id column to t_fin_vendors
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
        ADD INDEX idx_vendor_business_unit (business_unit_id),
        ADD CONSTRAINT fk_vendor_business_unit 
            FOREIGN KEY (business_unit_id) REFERENCES t_fin_business_units(id) ON DELETE RESTRICT',
    'SELECT ''Column business_unit_id already exists in t_fin_vendors'' as Status'
);

PREPARE stmt_vendors FROM @add_col_vendors;
EXECUTE stmt_vendors;
DEALLOCATE PREPARE stmt_vendors;

SELECT '✅ Step 1.1: Added business_unit_id to t_fin_vendors' as Status;

-- Step 1.2: Create separate vendor accounts for NF Foods
-- This allows tracking NF Foods vendor balances separately
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
    ('NF_FOODS_CASH', 'NF Foods Cash', 'asset', 'cash', 0.00, 0.00, 1, 1),
    ('NF_FOODS_VENDORS', 'NF Foods Vendors Payable', 'liability', 'vendor', 0.00, 0.00, 1, 1)
ON DUPLICATE KEY UPDATE 
    account_name = VALUES(account_name);

SELECT '✅ Step 1.2: Created NF Foods accounts' as Status;

-- =====================================================
-- PHASE 2: Business Unit Management Permission
-- =====================================================

-- Step 2.1: Add web permission for managing business units
INSERT INTO `t_sys_role_permissions` (`role_id`, `permission_key`, `permission_name`, `is_allowed`, `created_at`, `updated_at`)
SELECT r.id, 'view_business_units', 'View Business Units', 1, NOW(), NOW()
FROM t_sys_role r 
WHERE LOWER(r.urole_name) IN ('taimur', 'admin')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), is_allowed = 1;

SELECT '✅ Step 2.1: Created view_business_units permission' as Status;

-- =====================================================
-- PHASE 3: Add Mobile Permissions for Business Unit
-- =====================================================

-- Step 3.1: Create mobile permission for business unit selection in expenses
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('select_business_unit', 'Select Business Unit', 'store_mode_finance', 'Can select business unit when creating expenses', 52)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- Grant to Admin and Taimur roles
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') AND p.permission_code = 'select_business_unit'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 3.1: Created select_business_unit mobile permission' as Status;

-- =====================================================
-- PHASE 4: Update Existing Data
-- =====================================================

-- Step 4.1: Set all existing vendors to default business unit (NF = 1)
UPDATE t_fin_vendors 
SET business_unit_id = 1 
WHERE business_unit_id IS NULL;

SELECT '✅ Step 4.1: Updated existing vendors with default business unit' as Status;

-- Step 4.2: Set all existing ledger entries to default business unit (NF = 1)
UPDATE t_fin_ledger 
SET business_unit_id = 1 
WHERE business_unit_id IS NULL;

SELECT '✅ Step 4.2: Updated existing ledger entries with default business unit' as Status;

-- Step 4.3: Set all existing expense requests to default business unit (NF = 1)
UPDATE t_req_master 
SET business_unit_id = 1 
WHERE business_unit_id IS NULL AND request_type = 'expense';

SELECT '✅ Step 4.3: Updated existing expense requests with default business unit' as Status;

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================

SELECT '
=====================================
✅ BUSINESS UNIT IMPLEMENTATION COMPLETE
=====================================

Tables Modified:
1. t_fin_vendors - Added business_unit_id
2. t_fin_accounts - Added NF_FOODS_CASH, NF_FOODS_VENDORS

Permissions Created:
- view_business_units (web)
- select_business_unit (mobile)

Defaults Applied:
- All existing vendors → Nizami Farms (1)
- All existing ledger entries → Nizami Farms (1)
- All existing expense requests → Nizami Farms (1)

=====================================
NEXT STEPS (Code Changes Required):
=====================================
1. VendorModel.php - Add business_unit_id to fillable, add relationship
2. Vendor create/edit views - Add business unit dropdown
3. Expense request form (web + mobile) - Add business unit selector
4. Reports - Add business unit filter
5. Mobile VendorsScreen - Show business unit badge
6. Mobile StoreRequestScreen - Add business unit picker

=====================================
' as FinalStatus;

-- Show current business units
SELECT 'Business Units:' as '';
SELECT id, code, name, is_active FROM t_fin_business_units;

-- Show vendor table structure
SELECT 'Vendor Table Columns (business_unit related):' as '';
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 't_fin_vendors' 
AND COLUMN_NAME LIKE '%business%';

-- Count vendors by business unit
SELECT 'Vendors by Business Unit:' as '';
SELECT 
    COALESCE(bu.name, 'Unassigned') as business_unit,
    COUNT(*) as vendor_count
FROM t_fin_vendors v
LEFT JOIN t_fin_business_units bu ON v.business_unit_id = bu.id
GROUP BY v.business_unit_id;

-- Show mobile permissions
SELECT 'Business Unit Mobile Permissions:' as '';
SELECT permission_code, permission_name, permission_group 
FROM t_sys_mobile_permission 
WHERE permission_code IN ('select_business_unit', 'view_assets', 'add_assets');
