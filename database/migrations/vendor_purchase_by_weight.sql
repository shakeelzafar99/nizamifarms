-- =============================================
-- Vendor Purchase by Weight Feature
-- =============================================
-- Purpose: Add vendor-specific products and 
--          track weighted purchases with line items
-- =============================================
-- Date: October 16, 2024
-- =============================================

USE nizamifarms_db;

-- =============================================
-- 1. Create Vendor Products Table
-- =============================================

CREATE TABLE IF NOT EXISTS `t_fin_vendor_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id` INT NOT NULL COMMENT 'FK to t_fin_vendors(id)',
    `product_name` VARCHAR(255) NOT NULL,
    `unit` VARCHAR(50) NOT NULL DEFAULT 'kg' COMMENT 'kg, liter, piece, dozen, pack, box, ton, etc',
    `rate_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Price per unit in PKR',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`vendor_id`) REFERENCES `t_fin_vendors`(`id`) ON DELETE CASCADE,
    INDEX `idx_vendor_active` (`vendor_id`, `is_active`),
    INDEX `idx_product_name` (`product_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Vendor-specific products catalog for weighted purchases';

SELECT '✓ Created t_fin_vendor_products' as Status;

-- =============================================
-- 2. Create Vendor Purchase Items Table
-- =============================================

CREATE TABLE IF NOT EXISTS `t_fin_vendor_purchase_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ledger_id` INT NOT NULL COMMENT 'FK to t_fin_ledger(id)',
    `vendor_product_id` INT NULL COMMENT 'FK to t_fin_vendor_products(id) - nullable for deleted products',
    `product_name` VARCHAR(255) NOT NULL COMMENT 'Snapshot of product name at time of purchase',
    `quantity` DECIMAL(10,3) NOT NULL COMMENT 'Quantity purchased (e.g., 25.5 kg)',
    `unit` VARCHAR(50) NOT NULL COMMENT 'Snapshot of unit (kg, liter, etc)',
    `rate_per_unit` DECIMAL(10,2) NOT NULL COMMENT 'Snapshot of rate at time of purchase',
    `line_total` DECIMAL(10,2) NOT NULL COMMENT 'Calculated: quantity * rate_per_unit',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`ledger_id`) REFERENCES `t_fin_ledger`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`vendor_product_id`) REFERENCES `t_fin_vendor_products`(`id`) ON DELETE SET NULL,
    INDEX `idx_ledger` (`ledger_id`),
    INDEX `idx_vendor_product` (`vendor_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Line items for weighted vendor purchases - stores detailed breakdown';

SELECT '✓ Created t_fin_vendor_purchase_items' as Status;

-- =============================================
-- 3. Verification Queries
-- =============================================

-- Show table structures
SELECT 
    '=== t_fin_vendor_products Structure ===' as Info;
    
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_vendor_products'
ORDER BY ORDINAL_POSITION;

SELECT 
    '=== t_fin_vendor_purchase_items Structure ===' as Info;
    
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_vendor_purchase_items'
ORDER BY ORDINAL_POSITION;

-- Show foreign key constraints
SELECT 
    '=== Foreign Key Constraints ===' as Info;
    
SELECT 
    TABLE_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('t_fin_vendor_products', 't_fin_vendor_purchase_items')
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Count rows
SELECT 
    '=== Row Counts ===' as Info;
    
SELECT 
    'Vendor Products' as TableName,
    COUNT(*) as RowCount
FROM t_fin_vendor_products
UNION ALL
SELECT 
    'Purchase Items' as TableName,
    COUNT(*) as RowCount
FROM t_fin_vendor_purchase_items;

-- =============================================
-- 4. Sample Data (Optional - for testing)
-- =============================================
-- Uncomment below to insert sample data for testing

/*
-- Get a test vendor ID (adjust as needed)
SET @test_vendor_id = (SELECT id FROM t_fin_vendors LIMIT 1);

-- Insert sample products
INSERT INTO t_fin_vendor_products (vendor_id, product_name, unit, rate_per_unit, is_active) VALUES
(@test_vendor_id, 'Chicken Breast', 'kg', 450.00, 1),
(@test_vendor_id, 'Mutton Leg', 'kg', 850.00, 1),
(@test_vendor_id, 'Beef Ribs', 'kg', 700.00, 1),
(@test_vendor_id, 'Chicken Wings', 'kg', 350.00, 1),
(@test_vendor_id, 'Mutton Chops', 'kg', 900.00, 1);

SELECT 'Sample products inserted for testing' as Status;
*/

-- =============================================
-- MIGRATION COMPLETE
-- =============================================

SELECT '
=====================================
✓ MIGRATION COMPLETED SUCCESSFULLY
=====================================
Tables Created:
1. t_fin_vendor_products
2. t_fin_vendor_purchase_items

Next Steps:
1. Go to Finance → Vendors
2. Click "Manage Products" on any vendor
3. Add vendor-specific products
4. Use "Purchase by Weight" to record purchases

Documentation:
- VENDOR_PURCHASE_BY_WEIGHT_IMPLEMENTATION_OCT16.md
- VENDOR_PURCHASE_BY_WEIGHT_QUICK_START.md
=====================================
' as FinalStatus;

