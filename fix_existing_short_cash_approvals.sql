-- Fix existing short cash expense requests that were created without approval levels
-- This will make them visible in the Approvals Dashboard

-- =====================================================
-- STEP 1: Check current state
-- =====================================================
SELECT '=== Current Short Cash Requests ===' as '';

SELECT 
    request_number,
    title,
    amount,
    status,
    requires_level_1,
    requires_level_2,
    level_1_status,
    level_2_status,
    created_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC;

-- =====================================================
-- STEP 2: Update existing short cash requests
-- =====================================================
SELECT '=== Updating Short Cash Requests ===' as '';

UPDATE t_req_master r
JOIN t_req_category c ON r.category_id = c.id
JOIN t_req_category_approval_config ac ON c.id = ac.category_id
SET 
    r.requires_level_1 = ac.requires_level_1,
    r.requires_level_2 = ac.requires_level_2,
    r.level_1_status = CASE 
        WHEN ac.requires_level_1 = 1 THEN 'pending' 
        ELSE NULL 
    END,
    r.level_2_status = CASE 
        WHEN ac.requires_level_2 = 1 THEN 'pending' 
        ELSE NULL 
    END
WHERE r.title LIKE 'Short Cash%'
AND r.status = 'pending'
AND (r.requires_level_1 IS NULL OR r.level_1_status IS NULL);

SELECT ROW_COUNT() as 'Requests Updated';

-- =====================================================
-- STEP 3: Verify the fix
-- =====================================================
SELECT '=== Verification - After Update ===' as '';

SELECT 
    request_number,
    title,
    amount,
    status,
    requires_level_1,
    requires_level_2,
    level_1_status,
    level_2_status,
    CASE 
        WHEN requires_level_1 = 1 AND level_1_status = 'pending' THEN '✓ Will appear in Approvals Dashboard'
        ELSE '✗ Still has issues'
    END as 'Dashboard Status',
    created_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC;

-- =====================================================
-- STEP 4: Check category configuration
-- =====================================================
SELECT '=== Expense Category Approval Config ===' as '';

SELECT 
    c.category_code,
    c.category_name,
    ac.requires_level_1,
    ac.requires_level_2,
    CASE 
        WHEN ac.requires_level_1 = 1 THEN 'Requires L1 Approval'
        ELSE 'No L1 Required'
    END as 'L1 Status',
    CASE 
        WHEN ac.requires_level_2 = 1 THEN 'Requires L2 Approval'
        ELSE 'No L2 Required'
    END as 'L2 Status'
FROM t_req_category c
LEFT JOIN t_req_category_approval_config ac ON c.id = ac.category_id
WHERE c.category_code = 'expense';

-- =====================================================
-- STEP 5: Summary
-- =====================================================
SELECT '=== Summary ===' as '';

SELECT 
    COUNT(*) as 'Total Short Cash Requests',
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as 'Pending',
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as 'Approved',
    SUM(CASE WHEN requires_level_1 = 1 AND level_1_status = 'pending' THEN 1 ELSE 0 END) as 'Visible in Dashboard (L1 Pending)',
    SUM(CASE WHEN requires_level_1 IS NULL THEN 1 ELSE 0 END) as 'Still Missing Approval Levels'
FROM t_req_master
WHERE title LIKE 'Short Cash%';

SELECT '✓ Script completed. Short cash requests should now appear in Approvals Dashboard.' as 'Status';

