-- Debug TXN-21 Creation
USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'TXN-21 Full Details' as '';
SELECT '========================================' as '';

-- Get full TXN-21 details
SELECT 
    l.*,
    fa.account_code as from_account,
    fa.account_name as from_account_name,
    ta.account_code as to_account,
    ta.account_name as to_account_name
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts fa ON fa.id = l.from_account_id
LEFT JOIN t_fin_accounts ta ON ta.id = l.to_account_id
WHERE l.id = 21;

SELECT '' as '';
SELECT '--- Is TXN-21 linked to a request? ---' as '';

SELECT 
    l.id as ledger_id,
    l.request_id,
    l.approval_status,
    l.external_source,
    l.external_ref_id,
    r.request_number,
    r.category_id,
    c.category_code,
    c.category_name
FROM t_fin_ledger l
LEFT JOIN t_req_master r ON r.id = l.request_id
LEFT JOIN t_req_category c ON c.id = r.category_id
WHERE l.id = 21;

SELECT '' as '';
SELECT '--- REQ-202510-0008 Full Details ---' as '';

SELECT 
    r.*
FROM t_req_master r
WHERE r.request_number = 'REQ-202510-0008';

SELECT '' as '';
SELECT '--- Check if REQ-202510-0008 has a linked ledger ---' as '';

SELECT 
    r.id as request_id,
    r.request_number,
    r.ledger_transaction_id,
    l.id as ledger_id,
    l.approval_status as ledger_approval_status
FROM t_req_master r
LEFT JOIN t_fin_ledger l ON l.id = r.ledger_transaction_id
WHERE r.request_number = 'REQ-202510-0008';

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'THEORY: Are they the same or different?' as '';
SELECT '========================================' as '';

SELECT 
    CASE 
        WHEN (SELECT request_id FROM t_fin_ledger WHERE id = 21) = (SELECT id FROM t_req_master WHERE request_number = 'REQ-202510-0008')
        THEN '✅ TXN-21 and REQ-202510-0008 are LINKED (same transaction, different views)'
        ELSE '❌ TXN-21 and REQ-202510-0008 are SEPARATE (double creation bug!)'
    END as Status;

SELECT '' as '';
SELECT 'If they are LINKED:' as '';
SELECT '  - This is expected behavior (one request creates one ledger entry)' as '';
SELECT '  - The issue is just how they appear in the approvals dashboard' as '';
SELECT '' as '';
SELECT 'If they are SEPARATE:' as '';
SELECT '  - This is a bug (one request creating two separate entries)' as '';
SELECT '  - Need to find where the duplicate is being created' as '';

