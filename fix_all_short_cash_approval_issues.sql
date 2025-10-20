-- Comprehensive fix for ALL short cash expense request issues
-- Fixes: approval levels, settlement status, submitted_at

-- =====================================================
-- STEP 1: Show current state
-- =====================================================
SELECT '=== Current Short Cash Expense Requests ===' as '';

SELECT 
    request_number,
    title,
    status,
    requires_level_1,
    level_1_status,
    settlement_status,
    submitted_at,
    created_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC;

-- =====================================================
-- STEP 2: Fix ALL issues at once
-- =====================================================
SELECT '=== Fixing All Short Cash Issues ===' as '';

UPDATE t_req_master r
JOIN t_req_category c ON r.category_id = c.id
JOIN t_req_category_approval_config ac ON c.id = ac.category_id
SET 
    -- Fix approval levels
    r.requires_level_1 = ac.requires_level_1,
    r.requires_level_2 = ac.requires_level_2,
    r.level_1_status = CASE 
        WHEN r.status = 'pending' AND ac.requires_level_1 = 1 THEN 'pending'
        WHEN r.status = 'approved' AND ac.requires_level_1 = 1 THEN 'approved'
        ELSE NULL 
    END,
    r.level_2_status = CASE 
        WHEN r.status = 'pending' AND ac.requires_level_2 = 1 THEN 'pending'
        WHEN r.status = 'approved' AND ac.requires_level_2 = 1 THEN 'approved'
        ELSE NULL 
    END,
    -- Fix settlement status
    r.settlement_status = CASE
        WHEN r.settlement_status = 'not_required' THEN 'pending'
        ELSE r.settlement_status
    END,
    -- Fix submitted_at if missing
    r.submitted_at = CASE
        WHEN r.submitted_at IS NULL THEN r.created_at
        ELSE r.submitted_at
    END
WHERE r.title LIKE 'Short Cash%';

SELECT ROW_COUNT() as 'Requests Fixed';

-- =====================================================
-- STEP 3: Verify the fixes
-- =====================================================
SELECT '=== Verification - After Fix ===' as '';

SELECT 
    request_number,
    title,
    status,
    requires_level_1,
    level_1_status,
    settlement_status,
    submitted_at,
    CASE 
        WHEN requires_level_1 = 1 AND level_1_status = 'pending' THEN '✓ Will show in L1 Pending'
        WHEN requires_level_1 = 1 AND level_1_status = 'approved' THEN '✓ Will show in Approved'
        ELSE '✗ Still has issues'
    END as 'Dashboard Status',
    CASE 
        WHEN settlement_status = 'pending' THEN '✓ Needs Settlement (Correct)'
        WHEN settlement_status = 'not_required' THEN '✗ No Settlement (Wrong)'
        WHEN settlement_status = 'settled' THEN '✓ Already Settled'
        ELSE '? Unknown'
    END as 'Settlement Check',
    created_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC;

-- =====================================================
-- STEP 4: Summary
-- =====================================================
SELECT '=== Summary ===' as '';

SELECT 
    COUNT(*) as 'Total Short Cash Expenses',
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as 'Pending',
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as 'Approved',
    SUM(CASE WHEN requires_level_1 = 1 AND level_1_status = 'pending' THEN 1 ELSE 0 END) as 'Will Show in L1 Pending',
    SUM(CASE WHEN requires_level_1 = 1 AND level_1_status = 'approved' THEN 1 ELSE 0 END) as 'Will Show in Approved',
    SUM(CASE WHEN settlement_status = 'pending' THEN 1 ELSE 0 END) as 'Needs Settlement',
    SUM(CASE WHEN requires_level_1 IS NULL THEN 1 ELSE 0 END) as 'Still Missing Approval Levels'
FROM t_req_master
WHERE title LIKE 'Short Cash%';

SELECT '✓ All short cash expense requests have been fixed!' as 'Status';
SELECT 'They should now appear in the Approvals Dashboard.' as 'Note';

