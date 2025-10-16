-- =====================================================================
-- Cleanup Orphaned "Paya" Categories
-- =====================================================================
-- Purpose: Remove "Paya" category from products that no longer match 
--          any rule after the rule was removed
-- Safety: Only affects products with attribute_1 = 'Paya' that don't 
--         have "Paya" in their title
-- 
-- Run this AFTER deploying the fix and clicking "Apply Saved Rules"
-- Date: 2025-10-15
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- STEP 1: Preview what will be changed (SAFE - READ ONLY)
-- =====================================================================

SELECT 
    '--- Products that will have Paya category removed ---' as '';

SELECT 
    id,
    title,
    attribute_1 as current_category,
    vendor,
    status
FROM t_crm_prod_product
WHERE attribute_1 = 'Paya'
AND title NOT LIKE '%Paya%'  -- Don't clear if "Paya" is in the title
ORDER BY title;

SELECT '' as '';
SELECT 
    CONCAT('Will update ', COUNT(*), ' products') as Summary
FROM t_crm_prod_product
WHERE attribute_1 = 'Paya'
AND title NOT LIKE '%Paya%';

SELECT '' as '';
SELECT '✓ Preview complete - Review the list above' as Status;
SELECT '' as '';
SELECT 'If you want to proceed with the cleanup, run STEP 2 below' as '';

-- =====================================================================
-- STEP 2: Actually clear the orphaned categories (UNCOMMENT TO RUN)
-- =====================================================================

-- UNCOMMENT THE LINES BELOW TO RUN THE UPDATE:

/*
UPDATE t_crm_prod_product
SET 
    attribute_1 = NULL,
    updated_at = NOW()
WHERE attribute_1 = 'Paya'
AND title NOT LIKE '%Paya%';

SELECT 
    CONCAT('✓ Cleaned up ', ROW_COUNT(), ' products') as Status;
*/

-- =====================================================================
-- STEP 3: Verify the cleanup (run after STEP 2)
-- =====================================================================

-- UNCOMMENT THE LINES BELOW TO VERIFY:

/*
SELECT 
    '--- Remaining products with Paya category ---' as '';

SELECT 
    id,
    title,
    attribute_1,
    vendor
FROM t_crm_prod_product
WHERE attribute_1 = 'Paya'
ORDER BY title;

-- Should only show products where "Paya" actually appears in the title
*/

-- =====================================================================
-- Alternative: If you want to be more selective
-- =====================================================================

-- Option A: Only clear if product title contains "Trotters" 
-- (meaning it should have been updated to "Trotters" category)

/*
UPDATE t_crm_prod_product
SET 
    attribute_1 = 'Trotters',  -- Set to correct category instead of NULL
    updated_at = NOW()
WHERE attribute_1 = 'Paya'
AND title LIKE '%Trotters%';

SELECT 
    CONCAT('✓ Fixed ', ROW_COUNT(), ' Trotters products') as Status;
*/

-- =====================================================================
-- NOTES:
-- =====================================================================

-- 1. Run STEP 1 first to preview what will be changed
-- 2. If the list looks correct, uncomment and run STEP 2
-- 3. Then uncomment and run STEP 3 to verify
-- 
-- 4. After this cleanup, go to Products > Attributes page and click
--    "Apply Saved Rules" to ensure all products are properly categorized
-- 
-- 5. The new code fix ensures this won't happen again - when you click
--    "Apply Saved Rules", it will update ALL matching products regardless
--    of their current category value

