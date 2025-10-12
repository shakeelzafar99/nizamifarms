-- =====================================================
-- VERIFICATION SCRIPT: Check Before Settlement Install
-- =====================================================
-- Run this to verify your current structure is ready
-- for the settlement feature installation
-- =====================================================

USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'PRE-INSTALLATION VERIFICATION' as Status;
SELECT '========================================' as '';

-- =====================================================
-- 1. CHECK REQUIRED TABLES
-- =====================================================

SELECT '' as '';
SELECT '1. Checking Required Tables...' as Status;
SELECT '---' as '';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ PASS'
        ELSE '✗ FAIL'
    END as 'Check',
    't_req_master exists' as 'Test'
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ PASS'
        ELSE '✗ FAIL'
    END as 'Check',
    't_fin_ledger exists' as 'Test'
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_fin_ledger';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ PASS'
        ELSE '✗ FAIL'
    END as 'Check',
    't_fin_accounts exists' as 'Test'
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_fin_accounts';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ PASS'
        ELSE '✗ FAIL'
    END as 'Check',
    't_sys_user exists' as 'Test'
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_sys_user';

-- =====================================================
-- 2. CHECK REQUIRED COLUMNS IN t_req_master
-- =====================================================

SELECT '' as '';
SELECT '2. Checking Required Columns in t_req_master...' as Status;
SELECT '---' as '';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ PASS'
        ELSE '✗ FAIL - Run add_payment_source_to_requests.sql first!'
    END as 'Check',
    'payment_source_account_id exists' as 'Test'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'payment_source_account_id';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ PASS'
        ELSE '✗ FAIL - Finance ledger system not installed!'
    END as 'Check',
    'ledger_transaction_id exists' as 'Test'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'ledger_transaction_id';

SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ PASS'
        ELSE '✗ FAIL - Run add_expense_category_to_requests.sql first!'
    END as 'Check',
    'expense_category exists' as 'Test'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'expense_category';

-- =====================================================
-- 3. CHECK IF SETTLEMENT COLUMNS ALREADY EXIST
-- =====================================================

SELECT '' as '';
SELECT '3. Checking if Settlement Columns Already Exist...' as Status;
SELECT '---' as '';

SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN '✓ READY TO ADD'
        ELSE '⚠ ALREADY EXISTS'
    END as 'Check',
    'settlement_status' as 'Column'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'settlement_status';

SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN '✓ READY TO ADD'
        ELSE '⚠ ALREADY EXISTS'
    END as 'Check',
    'settled_at' as 'Column'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'settled_at';

SELECT 
    CASE 
        WHEN COUNT(*) = 0 THEN '✓ READY TO ADD'
        ELSE '⚠ ALREADY EXISTS'
    END as 'Check',
    'settlement_transaction_id' as 'Column'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'settlement_transaction_id';

-- =====================================================
-- 4. CHECK CURRENT t_req_master STRUCTURE
-- =====================================================

SELECT '' as '';
SELECT '4. Current t_req_master Columns (Finance-related):' as Status;
SELECT '---' as '';

SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND (
    COLUMN_NAME LIKE '%amount%' 
    OR COLUMN_NAME LIKE '%payment%' 
    OR COLUMN_NAME LIKE '%ledger%'
    OR COLUMN_NAME LIKE '%expense%'
    OR COLUMN_NAME LIKE '%settlement%'
)
ORDER BY ORDINAL_POSITION;

-- =====================================================
-- 5. CHECK EXISTING FOREIGN KEYS
-- =====================================================

SELECT '' as '';
SELECT '5. Existing Foreign Keys on t_req_master:' as Status;
SELECT '---' as '';

SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

-- =====================================================
-- 6. CHECK DATA THAT WILL BE AFFECTED
-- =====================================================

SELECT '' as '';
SELECT '6. Current Expense Request Data:' as Status;
SELECT '---' as '';

SELECT 
    COUNT(*) as 'Total Expense Requests',
    SUM(CASE WHEN ledger_transaction_id IS NOT NULL THEN 1 ELSE 0 END) as 'Posted to Ledger',
    SUM(CASE WHEN payment_source_account_id IS NOT NULL THEN 1 ELSE 0 END) as 'Has Payment Source',
    SUM(CASE WHEN payment_source_account_id IS NULL AND ledger_transaction_id IS NOT NULL THEN 1 ELSE 0 END) as 'Needs Payment Source (NULL)'
FROM t_req_master r
JOIN t_req_category c ON r.category_id = c.id
WHERE c.category_code = 'expense';

-- =====================================================
-- 7. FINAL VERDICT
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFICATION COMPLETE!' as Status;
SELECT '========================================' as '';

SET @all_tables_exist = (
    SELECT COUNT(*) = 4
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME IN ('t_req_master', 't_fin_ledger', 't_fin_accounts', 't_sys_user')
);

SET @required_columns_exist = (
    SELECT COUNT(*) = 3
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME IN ('payment_source_account_id', 'ledger_transaction_id', 'expense_category')
);

SET @settlement_columns_absent = (
    SELECT COUNT(*) = 0
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME IN ('settlement_status', 'settled_at', 'settlement_transaction_id')
);

SELECT 
    CASE 
        WHEN @all_tables_exist = 1 AND @required_columns_exist = 1 AND @settlement_columns_absent = 1 
        THEN '✓ READY TO INSTALL'
        WHEN @all_tables_exist = 0 
        THEN '✗ MISSING REQUIRED TABLES'
        WHEN @required_columns_exist = 0 
        THEN '✗ MISSING REQUIRED COLUMNS - Run prerequisite migrations first'
        WHEN @settlement_columns_absent = 0 
        THEN '⚠ SETTLEMENT COLUMNS ALREADY EXIST - Safe to re-run SQL anyway'
        ELSE '⚠ UNKNOWN STATUS - Check output above'
    END as 'Installation Status';

SELECT '' as '';
SELECT 'Next Steps:' as '';
SELECT '1. If status is READY TO INSTALL, run: add_expense_settlement_support.sql' as Step;
SELECT '2. If status is MISSING REQUIRED COLUMNS, run prerequisite SQLs first' as Step;
SELECT '3. If ALREADY EXISTS, settlement feature is already installed' as Step;



