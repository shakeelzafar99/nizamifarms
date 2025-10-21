-- =====================================================
-- Fix Salary Advance Duplicate - Direct Fix
-- =====================================================
-- Purpose: Remove duplicate Salary Advance categories
-- Date: October 21, 2025
-- Note: For testing environment, not worried about historical data
-- =====================================================

USE nizamifarms_db;

-- =====================================================
-- STEP 1: Show Current State (BEFORE)
-- =====================================================

SELECT '========================================' as '';
SELECT 'BEFORE FIX - Current Salary Advance Categories' as '';
SELECT '========================================' as '';

SELECT 
    c.id,
    c.category_code,
    c.category_name,
    c.is_active,
    cfg.requires_level_1 as L1,
    cfg.requires_level_2 as L2
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
ORDER BY c.id;

-- =====================================================
-- STEP 2: Keep Only the Correct One
-- =====================================================

SELECT '' as '';
SELECT '--- Step 2: Removing Duplicates ---' as '';

-- Delete approval configs for duplicates (keep only category_code = 'salary_advance')
DELETE FROM t_req_category_approval_config
WHERE category_id IN (
    SELECT id FROM t_req_category
    WHERE (category_name LIKE '%Salary%Advance%' OR category_code LIKE '%salary%advance%')
      AND category_code != 'salary_advance'
);

SELECT CONCAT('✓ Removed ', ROW_COUNT(), ' duplicate approval configs') as Status;

-- Delete duplicate categories (keep only category_code = 'salary_advance')
DELETE FROM t_req_category
WHERE (category_name LIKE '%Salary%Advance%' OR category_code LIKE '%salary%advance%')
  AND category_code != 'salary_advance';

SELECT CONCAT('✓ Removed ', ROW_COUNT(), ' duplicate categories') as Status;

-- =====================================================
-- STEP 3: Ensure Correct Configuration Exists
-- =====================================================

SELECT '' as '';
SELECT '--- Step 3: Ensuring Correct Configuration ---' as '';

-- Make sure the correct category exists
INSERT INTO t_req_category (category_code, category_name, description, is_active, created_at, updated_at)
VALUES 
    ('salary_advance', 'Salary Advance', 'Request for advance salary payment', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    category_name = 'Salary Advance',
    description = 'Request for advance salary payment',
    is_active = 1,
    updated_at = NOW();

SELECT '✓ Ensured salary_advance category exists' as Status;

-- Make sure it has correct approval configuration (L1 + L2)
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at, updated_at)
SELECT 
    c.id,
    1,  -- requires L1
    1,  -- requires L2
    NULL,  -- no auto-approve
    NOW(),
    NOW()
FROM t_req_category c
WHERE c.category_code = 'salary_advance'
ON DUPLICATE KEY UPDATE
    requires_level_1 = 1,
    requires_level_2 = 1,
    updated_at = NOW();

SELECT '✓ Ensured correct approval configuration (L1 + L2)' as Status;

-- =====================================================
-- STEP 4: Show Final State (AFTER)
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'AFTER FIX - Final Salary Advance Categories' as '';
SELECT '========================================' as '';

SELECT 
    c.id,
    c.category_code,
    c.category_name,
    c.is_active,
    cfg.requires_level_1 as L1,
    cfg.requires_level_2 as L2
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
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

SELECT '' as '';
SELECT '✓ Fix Complete' as '';
SELECT 'Going forward, only ONE "Salary Advance" option will appear in dropdown' as '';
SELECT 'Creating a salary advance will only create ONE approval (not two)' as '';

