-- Fix short cash expense requests to require settlement
-- They should have settlement_status = 'pending' not 'not_required'

-- =====================================================
-- STEP 1: Check current short cash expenses
-- =====================================================
SELECT '=== Current Short Cash Expenses ===' as '';

SELECT 
    request_number,
    title,
    amount,
    expense_category,
    payment_source_account_id,
    status,
    settlement_status,
    ledger_transaction_id,
    created_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC;

-- =====================================================
-- STEP 2: Update settlement_status for short cash expenses
-- =====================================================
SELECT '=== Updating Settlement Status ===' as '';

UPDATE t_req_master
SET settlement_status = 'pending'
WHERE title LIKE 'Short Cash%'
AND settlement_status = 'not_required';

SELECT ROW_COUNT() as 'Expenses Updated';

-- =====================================================
-- STEP 3: Verify the fix
-- =====================================================
SELECT '=== Verification - After Update ===' as '';

SELECT 
    request_number,
    title,
    amount,
    expense_category,
    payment_source_account_id,
    status,
    settlement_status,
    ledger_transaction_id,
    CASE 
        WHEN settlement_status = 'pending' THEN '✓ Needs Settlement (Correct)'
        WHEN settlement_status = 'not_required' THEN '✗ No Settlement Needed (Wrong for short cash)'
        WHEN settlement_status = 'settled' THEN '✓ Already Settled'
        ELSE '? Unknown Status'
    END as 'Settlement Status Check',
    created_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC;

-- =====================================================
-- STEP 4: Check if approved expenses have ledger entries
-- =====================================================
SELECT '=== Check Ledger Posting ===' as '';

SELECT 
    r.request_number,
    r.title,
    r.status,
    r.settlement_status,
    r.ledger_transaction_id,
    CASE 
        WHEN r.status = 'approved' AND r.ledger_transaction_id IS NOT NULL THEN '✓ Posted to Ledger'
        WHEN r.status = 'approved' AND r.ledger_transaction_id IS NULL THEN '✗ Approved but NOT posted to ledger'
        WHEN r.status = 'pending' THEN '⏳ Still Pending Approval'
        ELSE '? Other Status'
    END as 'Ledger Status',
    l.id as ledger_id,
    l.amount as ledger_amount,
    l.description as ledger_description
FROM t_req_master r
LEFT JOIN t_fin_ledger l ON r.ledger_transaction_id = l.id
WHERE r.title LIKE 'Short Cash%'
ORDER BY r.created_at DESC;

-- =====================================================
-- STEP 5: Summary
-- =====================================================
SELECT '=== Summary ===' as '';

SELECT 
    COUNT(*) as 'Total Short Cash Expenses',
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as 'Pending',
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as 'Approved',
    SUM(CASE WHEN settlement_status = 'pending' THEN 1 ELSE 0 END) as 'Needs Settlement',
    SUM(CASE WHEN settlement_status = 'not_required' THEN 1 ELSE 0 END) as 'No Settlement Needed (Should be 0)',
    SUM(CASE WHEN settlement_status = 'settled' THEN 1 ELSE 0 END) as 'Already Settled',
    SUM(CASE WHEN status = 'approved' AND ledger_transaction_id IS NOT NULL THEN 1 ELSE 0 END) as 'Posted to Ledger',
    SUM(CASE WHEN status = 'approved' AND ledger_transaction_id IS NULL THEN 1 ELSE 0 END) as 'Approved but Not Posted'
FROM t_req_master
WHERE title LIKE 'Short Cash%';

SELECT '✓ Script completed. Short cash expenses should now require settlement.' as 'Status';

