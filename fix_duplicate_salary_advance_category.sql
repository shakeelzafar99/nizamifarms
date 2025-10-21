-- =====================================================
-- Fix Duplicate Salary Advance Category
-- =====================================================
-- Purpose: Remove duplicate "Salary Advance" categories
-- Date: October 21, 2025
-- =====================================================

USE nizamifarms_db;

-- =====================================================
-- STEP 1: Identify Duplicates
-- =====================================================

SELECT '--- Step 1: Identifying Duplicate Salary Advance Categories ---' as '';

SELECT 
    id,
    category_code,
    category_name,
    description,
    is_active,
    created_at,
    updated_at
FROM t_req_category
WHERE category_name LIKE '%Salary%Advance%' 
   OR category_code LIKE '%salary%advance%'
ORDER BY created_at;

-- =====================================================
-- STEP 2: Check Approval Configuration
-- =====================================================

SELECT '--- Step 2: Checking Approval Configuration ---' as '';

SELECT 
    c.id as category_id,
    c.category_code,
    c.category_name,
    cfg.requires_level_1,
    cfg.requires_level_2,
    cfg.auto_approve_threshold
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
ORDER BY c.created_at;

-- =====================================================
-- STEP 3: Check if Any Requests Use These Categories
-- =====================================================

SELECT '--- Step 3: Checking Existing Requests ---' as '';

SELECT 
    c.id as category_id,
    c.category_code,
    c.category_name,
    COUNT(r.id) as request_count
FROM t_req_category c
LEFT JOIN t_req_master r ON c.id = r.category_id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
GROUP BY c.id, c.category_code, c.category_name
ORDER BY c.created_at;

-- =====================================================
-- STEP 4: Identify Which One to Keep
-- =====================================================

SELECT '--- Step 4: Recommended Action ---' as '';

SELECT 
    CASE 
        WHEN COUNT(*) > 1 THEN 'DUPLICATE FOUND - Action Required'
        WHEN COUNT(*) = 1 THEN 'No duplicates - All good'
        ELSE 'No Salary Advance category found - Need to create one'
    END as Status,
    COUNT(*) as Total_Categories
FROM t_req_category
WHERE category_name LIKE '%Salary%Advance%' 
   OR category_code LIKE '%salary%advance%';

-- =====================================================
-- STEP 5: Manual Fix Instructions
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'MANUAL FIX INSTRUCTIONS' as '';
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'If duplicates found above:' as '';
SELECT '1. Identify which category to KEEP (usually the one with category_code = "salary_advance")' as '';
SELECT '2. Note the ID of the category to DELETE' as '';
SELECT '3. Run the following SQL (replace X with the ID to delete):' as '';
SELECT '' as '';
SELECT '   -- Update any existing requests to use the correct category' as '';
SELECT '   UPDATE t_req_master' as '';
SELECT '   SET category_id = (SELECT id FROM t_req_category WHERE category_code = "salary_advance" LIMIT 1)' as '';
SELECT '   WHERE category_id = X;' as '';
SELECT '' as '';
SELECT '   -- Delete the duplicate category' as '';
SELECT '   DELETE FROM t_req_category_approval_config WHERE category_id = X;' as '';
SELECT '   DELETE FROM t_req_category WHERE id = X;' as '';
SELECT '' as '';
SELECT '4. Verify only one remains:' as '';
SELECT '   SELECT * FROM t_req_category WHERE category_name LIKE "%Salary%Advance%";' as '';
SELECT '' as '';

-- =====================================================
-- STEP 6: Recommended Category Configuration
-- =====================================================

SELECT '--- Step 6: Recommended Configuration ---' as '';

SELECT 'The correct Salary Advance category should have:' as '';
SELECT '  category_code: salary_advance' as '';
SELECT '  category_name: Salary Advance' as '';
SELECT '  requires_level_1: 1 (Yes)' as '';
SELECT '  requires_level_2: 1 (Yes)' as '';
SELECT '  is_active: 1 (Yes)' as '';

-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'CURRENT STATE' as '';
SELECT '========================================' as '';

SELECT 
    c.id,
    c.category_code,
    c.category_name,
    c.is_active,
    cfg.requires_level_1 as L1,
    cfg.requires_level_2 as L2,
    COUNT(r.id) as requests_using_this
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
LEFT JOIN t_req_master r ON c.id = r.category_id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
GROUP BY c.id, c.category_code, c.category_name, c.is_active, cfg.requires_level_1, cfg.requires_level_2
ORDER BY c.created_at;

SELECT '' as '';
SELECT '✓ Analysis Complete' as '';
SELECT 'Review the results above and follow manual fix instructions if duplicates found' as '';

