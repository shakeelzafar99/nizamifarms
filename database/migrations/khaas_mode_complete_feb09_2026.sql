-- =====================================================
-- KHAAS MODE COMPLETE SQL MIGRATION
-- Created: February 9, 2026
-- =====================================================
-- 
-- This SQL covers EVERYTHING needed for Khaas Mode:
--
-- PART A: Mobile Permissions
--   ✅ access_khaas_mode permission
--   ✅ create_expense_category permission
--   ✅ Grants to Admin and Taimur roles
--
-- PART B: Warehouse Inventory Table (NEW)
--   ✅ Creates t_crm_warehouse_inventory table
--   ✅ Tracks per-product warehouse stock (separate from store inventory)
--   ✅ Links to business_unit_id for BU-level filtering
--
-- PRE-REQUISITES (should already be done):
--   - t_fin_business_units exists with Khaas (id=2)
--   - t_crm_prod_product has business_unit_id column
--   - business_unit_simplified.sql was already run
--
-- =====================================================


-- =====================================================
-- PART A: MOBILE PERMISSIONS
-- =====================================================

-- =====================================================
-- STEP 1: Add access_khaas_mode permission
-- =====================================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('access_khaas_mode', 'Access Khaas Mode', 'khaas_mode', 'Can switch to and use Khaas Mode in mobile app (view Khaas BU expenses, vendors, products)', 60)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description);

SELECT '✅ Step 1: Created access_khaas_mode mobile permission' as Status;


-- =====================================================
-- STEP 2: Add create_expense_category permission
-- =====================================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('create_expense_category', 'Create Expense Category', 'store_mode_finance', 'Can create new expense categories from mobile app', 55)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description);

SELECT '✅ Step 2: Created create_expense_category mobile permission' as Status;


-- =====================================================
-- STEP 3: Grant access_khaas_mode to Admin and Taimur roles
-- =====================================================

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') 
AND p.permission_code = 'access_khaas_mode'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 3: Granted access_khaas_mode to Admin and Taimur' as Status;


-- =====================================================
-- STEP 4: Grant create_expense_category to Admin and Taimur
-- =====================================================

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id 
FROM t_sys_role r 
CROSS JOIN t_sys_mobile_permission p 
WHERE LOWER(r.urole_name) IN ('admin', 'taimur') 
AND p.permission_code = 'create_expense_category'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 4: Granted create_expense_category to Admin and Taimur' as Status;


-- =====================================================
-- PART B: WAREHOUSE INVENTORY TABLE
-- =====================================================
-- 
-- WHY: Products already have "store inventory" via:
--   - t_crm_prod_product.total_inventory (sum across variants)
--   - t_crm_prod_product_variant.inventory_quantity (per variant)
-- 
-- This new table tracks WAREHOUSE inventory separately.
-- In Khaas Mode, the Products screen shows BOTH:
--   1. Store Inventory = product_variant.inventory_quantity
--   2. Warehouse Inventory = warehouse_inventory.quantity
--
-- =====================================================

-- STEP 5: Create warehouse inventory table
CREATE TABLE IF NOT EXISTS t_crm_warehouse_inventory (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  -- Product Reference
  product_id            BIGINT UNSIGNED NOT NULL,              -- FK to t_crm_prod_product
  product_variant_id    BIGINT UNSIGNED NULL,                  -- FK to t_crm_prod_product_variant (optional, for variant-level tracking)
  
  -- Business Unit (denormalized from product for faster queries)
  business_unit_id      INT NOT NULL DEFAULT 1,                -- FK to t_fin_business_units
  
  -- Inventory Data
  quantity              INT NOT NULL DEFAULT 0,                -- Current warehouse stock quantity
  unit                  VARCHAR(20) NULL DEFAULT 'pcs',        -- Unit of measure (pcs, kg, liters, etc.)
  min_stock_level       INT NULL DEFAULT 0,                    -- Minimum stock alert threshold
  max_stock_level       INT NULL,                              -- Maximum storage capacity
  
  -- Location & Organization
  warehouse_location    VARCHAR(100) NULL,                     -- Warehouse section/shelf/bin (e.g., "Shelf A-3", "Cold Storage 1")
  
  -- Last Count / Audit
  last_counted_at       DATETIME NULL,                         -- When was this last physically counted
  last_counted_by       BIGINT NULL,                           -- FK to t_sys_user (who counted)
  
  -- Notes
  notes                 TEXT NULL,                             -- Any notes about this inventory item
  
  -- Status
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  
  -- Audit
  created_by            BIGINT NULL,
  updated_by            BIGINT NULL,
  created_at            DATETIME NULL,
  updated_at            DATETIME NULL,
  
  -- Indexes & Constraints
  INDEX idx_wh_inv_product (product_id),
  INDEX idx_wh_inv_variant (product_variant_id),
  INDEX idx_wh_inv_business_unit (business_unit_id),
  INDEX idx_wh_inv_location (warehouse_location),
  UNIQUE KEY uq_wh_inv_product_variant_bu (product_id, product_variant_id, business_unit_id),
  
  CONSTRAINT fk_wh_inv_product FOREIGN KEY (product_id) REFERENCES t_crm_prod_product(id) ON DELETE CASCADE,
  CONSTRAINT fk_wh_inv_variant FOREIGN KEY (product_variant_id) REFERENCES t_crm_prod_product_variant(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Warehouse inventory tracking - separate from store inventory (product_variant.inventory_quantity). Used in Khaas Mode to show both store and warehouse stock levels.';

SELECT '✅ Step 5: Created t_crm_warehouse_inventory table' as Status;


-- =====================================================
-- STEP 6: Create warehouse inventory history/log table
-- (tracks changes: stock in, stock out, adjustments)
-- =====================================================

CREATE TABLE IF NOT EXISTS t_crm_warehouse_inventory_log (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  -- Reference
  warehouse_inventory_id BIGINT UNSIGNED NOT NULL,             -- FK to t_crm_warehouse_inventory
  product_id            BIGINT UNSIGNED NOT NULL,              -- FK to t_crm_prod_product (denormalized for fast lookups)
  business_unit_id      INT NOT NULL,                          -- FK to t_fin_business_units (denormalized)
  
  -- Change Details
  change_type           ENUM('stock_in', 'stock_out', 'adjustment', 'transfer', 'count') NOT NULL,
  quantity_before       INT NOT NULL DEFAULT 0,                -- Stock before change
  quantity_change       INT NOT NULL DEFAULT 0,                -- +/- change amount
  quantity_after        INT NOT NULL DEFAULT 0,                -- Stock after change
  
  -- Context
  reference_type        VARCHAR(50) NULL,                      -- e.g., 'purchase', 'transfer_to_store', 'manual_adjustment', 'stock_count'
  reference_id          BIGINT NULL,                           -- ID of related record (e.g., purchase order ID)
  notes                 TEXT NULL,                             -- Reason for change
  
  -- Audit
  created_by            BIGINT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  -- Indexes
  INDEX idx_wh_log_inventory (warehouse_inventory_id),
  INDEX idx_wh_log_product (product_id),
  INDEX idx_wh_log_bu (business_unit_id),
  INDEX idx_wh_log_type (change_type),
  INDEX idx_wh_log_date (created_at),
  
  CONSTRAINT fk_wh_log_inventory FOREIGN KEY (warehouse_inventory_id) REFERENCES t_crm_warehouse_inventory(id) ON DELETE CASCADE,
  CONSTRAINT fk_wh_log_product FOREIGN KEY (product_id) REFERENCES t_crm_prod_product(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit log for warehouse inventory changes. Tracks every stock in/out/adjustment with before/after quantities.';

SELECT '✅ Step 6: Created t_crm_warehouse_inventory_log table' as Status;


-- =====================================================
-- VERIFICATION & SUMMARY
-- =====================================================

SELECT '
=====================================
✅ KHAAS MODE COMPLETE SQL DONE
=====================================

PART A - Mobile Permissions:
- access_khaas_mode: Can switch to Khaas Mode (Admin + Taimur)
- create_expense_category: Can add new expense types (Admin + Taimur)

PART B - Warehouse Inventory Tables:
- t_crm_warehouse_inventory: Tracks warehouse stock per product/variant/BU
- t_crm_warehouse_inventory_log: Audit trail for all stock changes

HOW INVENTORY WORKS IN KHAAS MODE:
┌─────────────────────────────────────────────────────┐
│  Khaas Products Screen shows TWO inventory types:   │
│                                                     │
│  1. STORE INVENTORY (existing)                      │
│     Source: t_crm_prod_product_variant               │
│     Field:  inventory_quantity                       │
│     = Stock currently in the store/shop             │
│                                                     │
│  2. WAREHOUSE INVENTORY (new)                       │
│     Source: t_crm_warehouse_inventory               │
│     Field:  quantity                                │
│     = Stock sitting in the warehouse                │
│                                                     │
│  Both filtered by business_unit_id for Khaas (id=2) │
└─────────────────────────────────────────────────────┘

WAREHOUSE INVENTORY LOG tracks:
- stock_in: New stock received at warehouse
- stock_out: Stock sent out (e.g., transferred to store)
- adjustment: Manual corrections
- transfer: Moved between locations/BUs
- count: Physical stock count result

=====================================
' as Summary;

-- Show new permissions
SELECT 'New Mobile Permissions:' as '';
SELECT id, permission_code, permission_name, permission_group 
FROM t_sys_mobile_permission 
WHERE permission_code IN ('access_khaas_mode', 'create_expense_category');

-- Show role grants
SELECT 'Roles with new permissions:' as '';
SELECT r.urole_name, p.permission_code
FROM t_sys_role_mobile_permission rpm
JOIN t_sys_role r ON r.id = rpm.role_id
JOIN t_sys_mobile_permission p ON p.id = rpm.mobile_permission_id
WHERE p.permission_code IN ('access_khaas_mode', 'create_expense_category')
ORDER BY p.permission_code, r.urole_name;

-- Show new tables
SELECT 'New Tables Created:' as '';
SELECT TABLE_NAME, TABLE_COMMENT 
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME LIKE 't_crm_warehouse%';

-- Show warehouse inventory table structure
SELECT 'Warehouse Inventory Table Structure:' as '';
DESCRIBE t_crm_warehouse_inventory;
