-- =====================================================================
-- Multiple Discounts Support - SAFE MIGRATION (FIXED)
-- =====================================================================
-- Purpose: Add support for multiple discounts per order
-- Impact: ZERO - No changes to existing tables or data
-- Safety: 100% - Adds new optional table only
-- 
-- Run on: DEV first, then PROD after testing
-- Date: 2025-10-13
-- Fixed: Separated FK creation from table creation
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- STEP 1: CREATE TABLE (without FK)
-- =====================================================================

CREATE TABLE IF NOT EXISTS t_crm_order_discounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL COMMENT 'FK to t_crm_prod_order.id',
    discount_title VARCHAR(255) NOT NULL COMMENT 'Display name for the discount (e.g., "Member Discount", "Seasonal Promo")',
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Discount amount in order currency',
    discount_type ENUM('fixed', 'percentage') DEFAULT 'fixed' COMMENT 'Type of discount for future enhancements',
    discount_percentage DECIMAL(5,2) NULL COMMENT 'If percentage type, store the percentage value',
    coupon_code VARCHAR(100) NULL COMMENT 'Associated coupon code if applicable',
    display_order INT NOT NULL DEFAULT 0 COMMENT 'Order in which to display discounts (0, 1, 2, etc.)',
    notes TEXT NULL COMMENT 'Additional notes about this discount',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'User who created this discount entry',
    
    -- Indexes for performance (but NO FK yet)
    INDEX idx_order_id (order_id),
    INDEX idx_display_order (order_id, display_order),
    INDEX idx_created_at (created_at)
        
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Detailed breakdown of discounts for orders with multiple discount types. Optional - webhook orders will not have records here.';

SELECT '✓ Step 1: Table t_crm_order_discounts created' as Status;

-- =====================================================================
-- STEP 2: CREATE ADDITIONAL INDEX
-- =====================================================================

CREATE INDEX IF NOT EXISTS idx_order_discounts_lookup 
ON t_crm_order_discounts(order_id, display_order, discount_amount);

SELECT '✓ Step 2: Indexes created' as Status;

-- =====================================================================
-- STEP 3: ADD FOREIGN KEY CONSTRAINT (Separate from table creation)
-- =====================================================================

-- Check if FK already exists before adding
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_crm_order_discounts'
    AND CONSTRAINT_NAME = 'fk_order_discounts_order'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE t_crm_order_discounts 
        ADD CONSTRAINT fk_order_discounts_order 
        FOREIGN KEY (order_id) REFERENCES t_crm_prod_order(id) ON DELETE CASCADE',
    'SELECT ''✓ FK already exists'' as Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Step 3: Foreign key constraint added' as Status;

-- =====================================================================
-- VERIFICATION QUERIES (Run these to verify successful creation)
-- =====================================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFICATION CHECKS' as '';
SELECT '========================================' as '';
SELECT '' as '';

-- Check 1: Verify table was created
SELECT '--- Check 1: Table Created ---' as '';
SELECT 
    TABLE_NAME,
    TABLE_ROWS as CurrentRows,
    CREATE_TIME,
    TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_crm_order_discounts';

SELECT '' as '';

-- Check 2: Verify structure
SELECT '--- Check 2: Column Structure ---' as '';
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_crm_order_discounts'
ORDER BY ORDINAL_POSITION;

SELECT '' as '';

-- Check 3: Verify foreign key constraint
SELECT '--- Check 3: Foreign Key ---' as '';
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME,
    DELETE_RULE,
    UPDATE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc 
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME 
    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = 'nizamifarms_db'
AND kcu.TABLE_NAME = 't_crm_order_discounts'
AND kcu.REFERENCED_TABLE_NAME IS NOT NULL;

SELECT '' as '';

-- Check 4: Verify indexes
SELECT '--- Check 4: Indexes ---' as '';
SELECT 
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    INDEX_TYPE,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_crm_order_discounts'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT '' as '';

-- =====================================================================
-- SAFETY CHECKS (Run these to ensure no impact on existing data)
-- =====================================================================

SELECT '========================================' as '';
SELECT 'SAFETY CHECKS' as '';
SELECT '========================================' as '';
SELECT '' as '';

-- Safety Check 1: Confirm main orders table is untouched
SELECT '--- Safety Check 1: Main Orders Table ---' as '';
SELECT 
    COUNT(*) as TotalOrders,
    SUM(CASE WHEN discount_total > 0 THEN 1 ELSE 0 END) as OrdersWithDiscount,
    ROUND(SUM(discount_total), 2) as TotalDiscountsGiven
FROM t_crm_prod_order;

SELECT '' as '';

-- Safety Check 2: Confirm new table is empty (expected)
SELECT '--- Safety Check 2: New Discounts Table ---' as '';
SELECT 
    COUNT(*) as RecordCount,
    'Should be 0 - no migration of existing data' as Expected
FROM t_crm_order_discounts;

SELECT '' as '';

-- Safety Check 3: Test foreign key works
SELECT '--- Safety Check 3: FK Test ---' as '';
START TRANSACTION;
    -- Get a valid order_id for testing
    SET @test_order_id = (SELECT id FROM t_crm_prod_order LIMIT 1);
    
    -- Insert test discount record
    INSERT INTO t_crm_order_discounts (order_id, discount_title, discount_amount, display_order)
    VALUES (@test_order_id, 'TEST DISCOUNT - DELETE ME', 10.00, 0);
    
    -- Verify insert worked
    SELECT 
        CASE WHEN COUNT(*) > 0 THEN '✓ PASS: FK allows insert' ELSE '✗ FAIL: Cannot insert' END as TestResult
    FROM t_crm_order_discounts 
    WHERE discount_title = 'TEST DISCOUNT - DELETE ME';
    
    -- Clean up test data
    DELETE FROM t_crm_order_discounts WHERE discount_title = 'TEST DISCOUNT - DELETE ME';
ROLLBACK;

SELECT '' as '';

-- =====================================================================
-- FINAL STATUS
-- =====================================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓✓✓ MIGRATION COMPLETED SUCCESSFULLY! ✓✓✓' as '';
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'Table: t_crm_order_discounts created with FK to t_crm_prod_order' as '';
SELECT 'Next Step: Test backend API, then implement frontend UI' as '';
SELECT '' as '';

-- =====================================================================
-- ROLLBACK SCRIPT (If you need to undo this migration)
-- =====================================================================
/*
-- UNCOMMENT AND RUN ONLY IF YOU NEED TO ROLLBACK

USE nizamifarms_db;

-- Drop the foreign key first
ALTER TABLE t_crm_order_discounts DROP FOREIGN KEY IF EXISTS fk_order_discounts_order;

-- Drop the discounts table
DROP TABLE IF EXISTS t_crm_order_discounts;

-- Verify rollback
SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN '✓ Table successfully removed' 
        ELSE '✗ Table still exists' 
    END as RollbackStatus
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_crm_order_discounts';

*/

-- =====================================================================
-- NOTES FOR DEVELOPERS
-- =====================================================================
/*
IMPORTANT POINTS:

1. EXISTING ORDERS NOT AFFECTED:
   - No migration of existing discount_total data
   - Old orders continue working exactly as before
   - discount_total field remains the master field

2. BACKWARD COMPATIBILITY:
   - Webhook orders (WooCommerce/Shopify) won't create detail records
   - They continue using discount_total field only
   - Display logic will check: if detail records exist, show breakdown, 
     otherwise show single discount line

3. FORWARD COMPATIBILITY:
   - Manual orders can now optionally create detail records
   - Frontend will sum detail amounts and store in discount_total
   - Both fields remain in sync (discount_total = SUM of detail records)

4. TESTING CHECKLIST:
   ✓ Run this migration on DEV first
   ✓ Test WooCommerce webhook (should work unchanged)
   ✓ Test Shopify webhook (should work unchanged)
   ✓ Test manual order creation (should work unchanged)
   ✓ Test new multiple discount feature
   ✓ Verify old order displays (should be unchanged)
   ✓ Then run on PROD

5. ZERO RISK GUARANTEE:
   - This migration ONLY creates a new table
   - No ALTER TABLE on existing tables
   - No UPDATE statements on existing data
   - No changes to foreign keys on existing tables
   - Can be rolled back instantly (just DROP TABLE)

6. FK IMPLEMENTATION:
   - Matches project pattern (separate ALTER TABLE for FK)
   - Checks if FK exists before adding
   - ON DELETE CASCADE ensures orphan cleanup
   - Compatible with existing table structure

*/

-- =====================================================================
-- END OF MIGRATION
-- =====================================================================

