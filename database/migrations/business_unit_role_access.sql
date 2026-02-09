-- =====================================================
-- BUSINESS UNIT ROLE-BASED ACCESS SQL
-- Created: February 5, 2026
-- =====================================================
-- 
-- This SQL adds:
-- ✅ Business unit access control to roles
-- ✅ Mapping table for roles to business units
-- ✅ Default business unit for each role
--
-- =====================================================

-- =====================================================
-- PHASE 1: Add Business Unit Access Columns to Roles
-- =====================================================

-- Add business_unit_access column to t_sys_role
-- Options: 'all', 'single', 'multiple' (determines if user sees dropdown)
SET @col_bu_access = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_sys_role' 
    AND COLUMN_NAME = 'business_unit_access'
);

SET @add_bu_access = IF(
    @col_bu_access = 0,
    'ALTER TABLE t_sys_role 
        ADD COLUMN business_unit_access VARCHAR(20) DEFAULT ''all'' 
        COMMENT ''all=can see all BUs, single=only default BU, multiple=select from assigned BUs''',
    'SELECT ''Column business_unit_access already exists in t_sys_role'' as Status'
);

PREPARE stmt FROM @add_bu_access;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add default_business_unit_id column to t_sys_role
SET @col_default_bu = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 't_sys_role' 
    AND COLUMN_NAME = 'default_business_unit_id'
);

SET @add_default_bu = IF(
    @col_default_bu = 0,
    'ALTER TABLE t_sys_role 
        ADD COLUMN default_business_unit_id INT DEFAULT 1 
        COMMENT ''Default business unit for this role (Nizami Farms=1)''',
    'SELECT ''Column default_business_unit_id already exists in t_sys_role'' as Status'
);

PREPARE stmt FROM @add_default_bu;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✅ Step 1: Added business unit access columns to t_sys_role' as Status;


-- =====================================================
-- PHASE 2: Create Role-Business Unit Mapping Table
-- (For roles with access='multiple')
-- =====================================================

CREATE TABLE IF NOT EXISTS t_sys_role_business_unit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    business_unit_id INT NOT NULL,
    is_default BOOLEAN DEFAULT 0 COMMENT 'Is this the default BU for this role?',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_role_bu (role_id, business_unit_id),
    
    CONSTRAINT fk_role_bu_role 
        FOREIGN KEY (role_id) REFERENCES t_sys_role(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_bu_unit 
        FOREIGN KEY (business_unit_id) REFERENCES t_fin_business_units(id) ON DELETE CASCADE
) COMMENT 'Maps roles to their allowed business units (for multi-BU access)';

SELECT '✅ Step 2: Created t_sys_role_business_unit mapping table' as Status;


-- =====================================================
-- PHASE 3: Set Default Access for Existing Roles
-- =====================================================

-- Admin and Taimur roles get 'all' access
UPDATE t_sys_role 
SET business_unit_access = 'all',
    default_business_unit_id = 1
WHERE LOWER(urole_name) IN ('admin', 'taimur');

-- All other roles default to 'single' (only Nizami Farms)
UPDATE t_sys_role 
SET business_unit_access = 'single',
    default_business_unit_id = 1
WHERE LOWER(urole_name) NOT IN ('admin', 'taimur')
AND (business_unit_access IS NULL OR business_unit_access = 'all');

SELECT '✅ Step 3: Set default business unit access for roles' as Status;


-- =====================================================
-- PHASE 4: Grant All BUs to Admin Roles
-- =====================================================

-- Insert mappings for admin roles to all business units
INSERT INTO t_sys_role_business_unit (role_id, business_unit_id, is_default)
SELECT r.id, bu.id, CASE WHEN bu.id = 1 THEN 1 ELSE 0 END
FROM t_sys_role r
CROSS JOIN t_fin_business_units bu
WHERE LOWER(r.urole_name) IN ('admin', 'taimur')
AND bu.is_active = 1
ON DUPLICATE KEY UPDATE is_default = CASE WHEN VALUES(business_unit_id) = 1 THEN 1 ELSE t_sys_role_business_unit.is_default END;

SELECT '✅ Step 4: Granted all business units to admin roles' as Status;


-- =====================================================
-- PHASE 5: Update Mobile Permissions
-- =====================================================

-- These permissions were already added in business_unit_simplified.sql
-- Just verify they exist
SELECT 
    CASE 
        WHEN COUNT(*) >= 2 THEN '✅ Step 5: Mobile permissions verified'
        ELSE '⚠️ Step 5: Some mobile permissions may be missing'
    END as Status
FROM t_sys_mobile_permission 
WHERE permission_code IN ('view_all_business_units', 'select_business_unit');


-- =====================================================
-- VERIFICATION & SUMMARY
-- =====================================================

SELECT '
=====================================
✅ ROLE-BASED BUSINESS UNIT ACCESS COMPLETE
=====================================

Columns Added to t_sys_role:
- business_unit_access: ''all'', ''single'', or ''multiple''
- default_business_unit_id: FK to t_fin_business_units

New Table:
- t_sys_role_business_unit: Maps roles to their allowed BUs

Access Levels:
- ''all'': User can create expenses/vendors for ANY business unit (dropdown shows all)
- ''single'': User can only use their default BU (no dropdown, auto-set)
- ''multiple'': User can select from assigned BUs only (dropdown shows assigned only)

Default Configuration:
- Admin/Taimur roles: access=''all'', can see everything
- Other roles: access=''single'', only see Nizami Farms

HOW IT WORKS:
1. When user creates expense/vendor:
   - If role.business_unit_access = ''single'' → auto-set to role.default_business_unit_id
   - If role.business_unit_access = ''multiple'' → show dropdown with assigned BUs
   - If role.business_unit_access = ''all'' → show dropdown with all active BUs

2. For viewing data:
   - Mobile permission ''view_all_business_units'' controls cross-BU visibility
   - Without permission, users only see data from their assigned BUs

=====================================
' as Summary;

-- Show role configurations
SELECT 'Role Business Unit Access:' as '';
SELECT id, urole_name, business_unit_access, default_business_unit_id 
FROM t_sys_role 
ORDER BY urole_name;

-- Show role-BU mappings
SELECT 'Role-Business Unit Mappings:' as '';
SELECT r.urole_name, bu.name as business_unit, rbu.is_default
FROM t_sys_role_business_unit rbu
JOIN t_sys_role r ON r.id = rbu.role_id
JOIN t_fin_business_units bu ON bu.id = rbu.business_unit_id
ORDER BY r.urole_name, bu.display_order;
