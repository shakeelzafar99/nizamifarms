-- =====================================================
-- Investigate TXN-20 and REQ-202510-0007
-- =====================================================
-- Purpose: Find out why Arsalan has two approvals
-- Date: October 21, 2025
-- =====================================================

USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'INVESTIGATION: Double Approval for Arsalan' as '';
SELECT '========================================' as '';

-- =====================================================
-- STEP 1: Check TXN-20 (Ledger Transaction)
-- =====================================================

SELECT '' as '';
SELECT '--- Step 1: TXN-20 Details ---' as '';

SELECT 
    l.id as ledger_id,
    l.transaction_date,
    l.transaction_type,
    l.description,
    l.amount,
    l.approval_status,
    l.request_id,
    l.from_account_id,
    l.to_account_id,
    fa.account_code as from_account_code,
    fa.account_name as from_account_name,
    ta.account_code as to_account_code,
    ta.account_name as to_account_name,
    l.external_source,
    l.external_ref_id,
    l.created_at,
    l.created_by
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts fa ON fa.id = l.from_account_id
LEFT JOIN t_fin_accounts ta ON ta.id = l.to_account_id
WHERE l.id = 20;

-- =====================================================
-- STEP 2: Check REQ-202510-0007 (Request)
-- =====================================================

SELECT '' as '';
SELECT '--- Step 2: REQ-202510-0007 Details ---' as '';

SELECT 
    r.id as request_id,
    r.request_number,
    r.category_id,
    c.category_code,
    c.category_name,
    r.requester_user_id,
    u.fullname as requester_name,
    r.title,
    r.amount,
    r.payment_source_account_id,
    pa.account_code as payment_source_code,
    pa.account_name as payment_source_name,
    r.status,
    r.requires_level_1,
    r.requires_level_2,
    r.level_1_status,
    r.level_2_status,
    r.ledger_transaction_id,
    r.submitted_at,
    r.completed_at,
    r.created_at,
    r.created_by
FROM t_req_master r
LEFT JOIN t_req_category c ON c.id = r.category_id
LEFT JOIN t_sys_user u ON u.id = r.requester_user_id
LEFT JOIN t_fin_accounts pa ON pa.id = r.payment_source_account_id
WHERE r.request_number = 'REQ-202510-0007';

-- =====================================================
-- STEP 3: Check if TXN-20 is linked to a request
-- =====================================================

SELECT '' as '';
SELECT '--- Step 3: Is TXN-20 Linked to a Request? ---' as '';

SELECT 
    l.id as ledger_id,
    l.request_id,
    r.request_number,
    r.category_id,
    c.category_code,
    c.category_name
FROM t_fin_ledger l
LEFT JOIN t_req_master r ON r.id = l.request_id
LEFT JOIN t_req_category c ON c.id = r.category_id
WHERE l.id = 20;

-- =====================================================
-- STEP 4: Check ALL Salary Advance Categories
-- =====================================================

SELECT '' as '';
SELECT '--- Step 4: All Salary Advance Categories ---' as '';

SELECT 
    c.id,
    c.category_code,
    c.category_name,
    c.description,
    c.is_active,
    cfg.requires_level_1,
    cfg.requires_level_2,
    cfg.auto_approve_threshold,
    c.created_at
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON cfg.category_id = c.id
WHERE c.category_name LIKE '%Salary%Advance%' 
   OR c.category_code LIKE '%salary%advance%'
ORDER BY c.id;

-- =====================================================
-- STEP 5: Check ALL Requests for Arsalan (Today)
-- =====================================================

SELECT '' as '';
SELECT '--- Step 5: All Requests for Arsalan (Recent) ---' as '';

SELECT 
    r.id,
    r.request_number,
    r.category_id,
    c.category_code,
    c.category_name,
    r.amount,
    r.status,
    r.payment_source_account_id,
    pa.account_code as payment_source,
    r.submitted_at,
    r.created_at
FROM t_req_master r
LEFT JOIN t_req_category c ON c.id = r.category_id
LEFT JOIN t_sys_user u ON u.id = r.requester_user_id
LEFT JOIN t_fin_accounts pa ON pa.id = r.payment_source_account_id
WHERE u.fullname LIKE '%Arsalan%'
  AND DATE(r.created_at) >= CURDATE() - INTERVAL 1 DAY
ORDER BY r.created_at DESC;

-- =====================================================
-- STEP 6: Check ALL Ledger Entries for Arsalan (Today)
-- =====================================================

SELECT '' as '';
SELECT '--- Step 6: All Ledger Entries for Arsalan (Recent) ---' as '';

SELECT 
    l.id,
    l.transaction_date,
    l.transaction_type,
    l.description,
    l.amount,
    l.approval_status,
    l.request_id,
    r.request_number,
    fa.account_code as from_account,
    ta.account_code as to_account,
    l.created_at
FROM t_fin_ledger l
LEFT JOIN t_req_master r ON r.id = l.request_id
LEFT JOIN t_fin_accounts fa ON fa.id = l.from_account_id
LEFT JOIN t_fin_accounts ta ON ta.id = l.to_account_id
WHERE l.description LIKE '%Arsalan%'
  AND DATE(l.created_at) >= CURDATE() - INTERVAL 1 DAY
ORDER BY l.created_at DESC;

-- =====================================================
-- ANALYSIS SUMMARY
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'ANALYSIS SUMMARY' as '';
SELECT '========================================' as '';

SELECT 
    CASE 
        WHEN (SELECT COUNT(*) FROM t_req_category WHERE category_name LIKE '%Salary%Advance%') > 1 
        THEN '❌ DUPLICATE CATEGORIES FOUND'
        ELSE '✅ Only one Salary Advance category'
    END as Category_Status;

SELECT 
    CASE 
        WHEN (SELECT request_id FROM t_fin_ledger WHERE id = 20) IS NOT NULL
        THEN CONCAT('✅ TXN-20 is linked to request: ', (SELECT request_number FROM t_req_master WHERE id = (SELECT request_id FROM t_fin_ledger WHERE id = 20)))
        ELSE '❌ TXN-20 is NOT linked to any request (orphaned transaction)'
    END as TXN20_Link_Status;

SELECT '' as '';
SELECT 'Review the results above to understand:' as '';
SELECT '1. Are there duplicate Salary Advance categories?' as '';
SELECT '2. Is TXN-20 linked to a request, or is it orphaned?' as '';
SELECT '3. Did Arsalan create one request or two?' as '';
SELECT '4. What payment sources were used?' as '';

