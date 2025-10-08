-- ========================================
-- FINANCIAL LEDGER SYSTEM - VERIFICATION SCRIPT
-- ========================================
-- Run this script to verify the installation was successful
-- Database: nizamifarms_db

USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'FINANCIAL LEDGER SYSTEM VERIFICATION' as '';
SELECT '========================================' as '';
SELECT '' as '';

-- ========================================
-- CHECK 1: Table Existence
-- ========================================

SELECT 'CHECK 1: Table Existence' as '';
SELECT '----------------------------------------' as '';

SELECT 
    CASE 
        WHEN COUNT(*) = 5 THEN '✓ PASS - All 5 tables exist'
        ELSE CONCAT('✗ FAIL - Only ', COUNT(*), ' of 5 tables exist')
    END as Result
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_fin_config');

SELECT '' as '';

-- List all fin tables
SELECT TABLE_NAME, TABLE_ROWS, TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME LIKE 't_fin_%'
ORDER BY TABLE_NAME;

SELECT '' as '';


-- ========================================
-- CHECK 2: Foreign Key Constraints
-- ========================================

SELECT 'CHECK 2: Foreign Key Constraints' as '';
SELECT '----------------------------------------' as '';

SELECT 
    CASE 
        WHEN COUNT(*) >= 13 THEN CONCAT('✓ PASS - ', COUNT(*), ' foreign keys created')
        ELSE CONCAT('✗ FAIL - Only ', COUNT(*), ' foreign keys exist (expected at least 13)')
    END as Result
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_req_master')
AND CONSTRAINT_TYPE = 'FOREIGN KEY';

SELECT '' as '';

-- List all FKs
SELECT 
    TABLE_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_req_master')
AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT '' as '';


-- ========================================
-- CHECK 3: Core System Accounts
-- ========================================

SELECT 'CHECK 3: Core System Accounts' as '';
SELECT '----------------------------------------' as '';

SELECT 
    CASE 
        WHEN COUNT(*) >= 20 THEN CONCAT('✓ PASS - ', COUNT(*), ' core accounts seeded')
        ELSE CONCAT('✗ FAIL - Only ', COUNT(*), ' core accounts (expected at least 20)')
    END as Result
FROM t_fin_accounts
WHERE account_code IN ('NF_CASH', 'ONLINE', 'REV_SALES', 'EXP_FUND', 'EXP_PURCHASES', 'EQUITY_OPENING');

SELECT '' as '';

-- Show core accounts
SELECT account_code, account_name, account_type, account_category
FROM t_fin_accounts
ORDER BY account_type, account_code;

SELECT '' as '';


-- ========================================
-- CHECK 4: Configuration Settings
-- ========================================

SELECT 'CHECK 4: Configuration Settings' as '';
SELECT '----------------------------------------' as '';

SELECT 
    CASE 
        WHEN COUNT(*) >= 5 THEN CONCAT('✓ PASS - ', COUNT(*), ' config entries')
        ELSE CONCAT('✗ FAIL - Only ', COUNT(*), ' config entries (expected at least 5)')
    END as Result
FROM t_fin_config;

SELECT '' as '';

-- Show config
SELECT config_key, config_value, description
FROM t_fin_config
ORDER BY config_key;

SELECT '' as '';


-- ========================================
-- CHECK 5: t_req_master Column
-- ========================================

SELECT 'CHECK 5: t_req_master Update' as '';
SELECT '----------------------------------------' as '';

SELECT 
    CASE 
        WHEN COUNT(*) = 1 THEN '✓ PASS - ledger_transaction_id column added'
        ELSE '✗ FAIL - ledger_transaction_id column missing'
    END as Result
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_req_master'
AND COLUMN_NAME = 'ledger_transaction_id';

SELECT '' as '';


-- ========================================
-- CHECK 6: Index Verification
-- ========================================

SELECT 'CHECK 6: Indexes' as '';
SELECT '----------------------------------------' as '';

SELECT 
    TABLE_NAME,
    COUNT(*) as IndexCount
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log')
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

SELECT '' as '';


-- ========================================
-- CHECK 7: Empty Data Check
-- ========================================

SELECT 'CHECK 7: Initial Data Status' as '';
SELECT '----------------------------------------' as '';

SELECT 'Accounts (expect 20+):' as Table_Name, COUNT(*) as Row_Count FROM t_fin_accounts
UNION ALL
SELECT 'Ledger (expect 0):', COUNT(*) FROM t_fin_ledger
UNION ALL
SELECT 'Vendors (expect 0):', COUNT(*) FROM t_fin_vendors
UNION ALL
SELECT 'Import Log (expect 0):', COUNT(*) FROM t_fin_import_log
UNION ALL
SELECT 'Config (expect 5):', COUNT(*) FROM t_fin_config;

SELECT '' as '';


-- ========================================
-- SUMMARY
-- ========================================

SELECT '========================================' as '';
SELECT 'VERIFICATION SUMMARY' as '';
SELECT '========================================' as '';

SET @table_count = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'nizamifarms_db' AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_fin_config'));
SET @fk_count = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db' AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_req_master') AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @account_count = (SELECT COUNT(*) FROM t_fin_accounts);
SET @config_count = (SELECT COUNT(*) FROM t_fin_config);

SELECT 
    CASE 
        WHEN @table_count = 5 AND @fk_count >= 13 AND @account_count >= 20 AND @config_count >= 5
        THEN '✓✓✓ ALL CHECKS PASSED - Installation Successful! ✓✓✓'
        ELSE '✗✗✗ SOME CHECKS FAILED - Review output above ✗✗✗'
    END as Overall_Status;

SELECT '' as '';
SELECT CONCAT('Tables: ', @table_count, '/5') as '';
SELECT CONCAT('Foreign Keys: ', @fk_count, ' (minimum 13)') as '';
SELECT CONCAT('Core Accounts: ', @account_count, ' (minimum 20)') as '';
SELECT CONCAT('Config Entries: ', @config_count, ' (minimum 5)') as '';

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Ready to proceed with Laravel models!' as '';
SELECT '========================================' as '';

