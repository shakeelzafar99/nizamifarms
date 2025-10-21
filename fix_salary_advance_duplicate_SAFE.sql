-- =====================================================
-- Fix Salary Advance Duplicate - SAFE VERSION
-- =====================================================
-- Purpose: Migrate existing requests, then remove duplicate
-- Date: October 21, 2025
-- =====================================================

USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'SAFE FIX - Migrating Existing Data First' as '';
SELECT '========================================' as '';

-- =====================================================
-- STEP 1: Show Current State
-- =====================================================

SELECT '' as '';
SELECT '--- Step 1: Current Salary Advance Categories ---' as '';

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
LEFT JOIN t_req_master r ON r.category_id = c.id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
GROUP BY c.id, c.category_code, c.category_name, c.is_active, cfg.requires_level_1, cfg.requires_level_2
ORDER BY c.id;

-- =====================================================
-- STEP 2: Identify Which Requests Use the Duplicate
-- =====================================================

SELECT '' as '';
SELECT '--- Step 2: Requests Using Duplicate Category (ID 2) ---' as '';

SELECT 
    r.id,
    r.request_number,
    r.category_id,
    c.category_code,
    r.status,
    r.amount,
    r.created_at
FROM t_req_master r
JOIN t_req_category c ON c.id = r.category_id
WHERE r.category_id = 2  -- The duplicate 'advance' category
ORDER BY r.created_at DESC;

-- =====================================================
-- STEP 3: Migrate Requests from Duplicate to Correct Category
-- =====================================================

SELECT '' as '';
SELECT '--- Step 3: Migrating Requests to Correct Category ---' as '';

-- Update all requests using category ID 2 (advance) to use category ID 11 (salary_advance)
UPDATE t_req_master
SET category_id = 11  -- The correct 'salary_advance' category
WHERE category_id = 2;  -- The duplicate 'advance' category

SELECT CONCAT('✓ Migrated ', ROW_COUNT(), ' requests from duplicate to correct category') as Status;

-- =====================================================
-- STEP 4: Now Safe to Delete Duplicate Category
-- =====================================================

SELECT '' as '';
SELECT '--- Step 4: Removing Duplicate Category ---' as '';

-- Delete approval config for duplicate
DELETE FROM t_req_category_approval_config
WHERE category_id = 2;

SELECT CONCAT('✓ Removed approval config for duplicate category (affected ', ROW_COUNT(), ' rows)') as Status;

-- Delete the duplicate category
DELETE FROM t_req_category
WHERE id = 2;

SELECT CONCAT('✓ Removed duplicate category (affected ', ROW_COUNT(), ' rows)') as Status;

-- =====================================================
-- STEP 5: Ensure Correct Category Has Proper Config
-- =====================================================

SELECT '' as '';
SELECT '--- Step 5: Ensuring Correct Configuration ---' as '';

-- Make sure the correct category exists and is active
UPDATE t_req_category
SET 
    category_name = 'Salary Advance',
    description = 'Request for advance salary payment',
    is_active = 1,
    updated_at = NOW()
WHERE id = 11;

SELECT '✓ Updated correct category configuration' as Status;

-- Ensure it has correct approval configuration (L1 + L2)
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at, updated_at)
VALUES (11, 1, 1, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    requires_level_1 = 1,
    requires_level_2 = 1,
    updated_at = NOW();

SELECT '✓ Ensured correct approval configuration (L1 + L2)' as Status;

-- =====================================================
-- STEP 6: Verify Final State
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'AFTER FIX - Final State' as '';
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
LEFT JOIN t_req_master r ON r.category_id = c.id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
GROUP BY c.id, c.category_code, c.category_name, c.is_active, cfg.requires_level_1, cfg.requires_level_2
ORDER BY c.id;

-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFICATION' as '';
SELECT '========================================' as '';

SELECT 
    CASE 
        WHEN COUNT(*) = 1 THEN '✅ SUCCESS - Only ONE Salary Advance category exists'
        WHEN COUNT(*) > 1 THEN '❌ ERROR - Still have duplicates'
        ELSE '❌ ERROR - No Salary Advance category found'
    END as Status,
    COUNT(*) as Total_Categories
FROM t_req_category
WHERE category_name LIKE '%Salary%Advance%' 
   OR category_code LIKE '%salary%advance%';

-- Check that all requests now use the correct category
SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN '✅ SUCCESS - No requests using old duplicate category'
        ELSE CONCAT('⚠️ WARNING - ', COUNT(*), ' requests still using old category')
    END as Migration_Status
FROM t_req_master
WHERE category_id = 2;

SELECT '' as '';
SELECT '✓ Fix Complete!' as '';
SELECT '' as '';
SELECT 'What Changed:' as '';
SELECT '1. Migrated all existing requests from duplicate category (ID 2) to correct category (ID 11)' as '';
SELECT '2. Deleted duplicate category approval config' as '';
SELECT '3. Deleted duplicate category' as '';
SELECT '4. Verified correct category has proper L1 + L2 approval configuration' as '';
SELECT '' as '';
SELECT 'Going Forward:' as '';
SELECT '- Only ONE "Salary Advance" option will appear in dropdown' as '';
SELECT '- Creating a salary advance will only create ONE approval (not two)' as '';
SELECT '- All salary advances will use consistent approval flow (L1 + L2)' as '';

