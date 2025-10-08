-- ========================================
-- VERIFY LEDGER SYSTEM INSTALLATION
-- ========================================
-- Run this to verify everything is correctly installed
-- Database: nizamifarms_db

USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'LEDGER SYSTEM VERIFICATION' as '';
SELECT '========================================' as '';
SELECT '' as '';

-- Check 1: Tables Exist
SELECT 'CHECK 1: Tables Created' as '';
SELECT '----------------------------------------' as '';

SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) as 'Size (MB)'
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME LIKE 't_fin_%'
ORDER BY TABLE_NAME;

SELECT '' as '';

-- Check 2: Seed Data
SELECT 'CHECK 2: Seed Data' as '';
SELECT '----------------------------------------' as '';

SELECT CONCAT('✓ Accounts: ', COUNT(*), ' rows (expected 21)') as Status FROM t_fin_accounts;
SELECT CONCAT('✓ Config: ', COUNT(*), ' rows (expected 5)') as Status FROM t_fin_config;
SELECT CONCAT('✓ Ledger: ', COUNT(*), ' rows (expected 0 - empty until import)') as Status FROM t_fin_ledger;
SELECT CONCAT('✓ Vendors: ', COUNT(*), ' rows (expected 0 - empty until import)') as Status FROM t_fin_vendors;
SELECT CONCAT('✓ Import Log: ', COUNT(*), ' rows (expected 0 - empty until first import)') as Status FROM t_fin_import_log;

SELECT '' as '';

-- Check 3: Core Accounts
SELECT 'CHECK 3: Core System Accounts' as '';
SELECT '----------------------------------------' as '';

SELECT 
    account_code,
    account_name,
    account_type
FROM t_fin_accounts
WHERE account_code IN ('NF_CASH', 'ONLINE', 'REV_SALES', 'EXP_FUND', 'EQUITY_OPENING')
ORDER BY account_type, account_code;

SELECT '' as '';

-- Check 4: Foreign Keys
SELECT 'CHECK 4: Foreign Key Constraints' as '';
SELECT '----------------------------------------' as '';

SELECT 
    CONCAT('✓ ', TABLE_NAME, '.', COLUMN_NAME, ' -> ', REFERENCED_TABLE_NAME, '.', REFERENCED_COLUMN_NAME) as 'Foreign Key'
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_req_master')
AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, COLUMN_NAME;

SELECT '' as '';

-- Check 5: Indexes
SELECT 'CHECK 5: Indexes Created' as '';
SELECT '----------------------------------------' as '';

SELECT 
    TABLE_NAME,
    COUNT(DISTINCT INDEX_NAME) as 'Index Count'
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log')
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

SELECT '' as '';

-- Check 6: t_req_master Update
SELECT 'CHECK 6: t_req_master Integration' as '';
SELECT '----------------------------------------' as '';

SELECT 
    CASE 
        WHEN COUNT(*) = 1 THEN '✓ ledger_transaction_id column exists'
        ELSE '✗ ledger_transaction_id column MISSING'
    END as Status
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_req_master'
AND COLUMN_NAME = 'ledger_transaction_id';

SELECT 
    CASE 
        WHEN COUNT(*) = 1 THEN '✓ FK to t_fin_ledger exists'
        ELSE '⚠ FK to t_fin_ledger NOT FOUND (may be OK if not added yet)'
    END as Status
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_req_master'
AND COLUMN_NAME = 'ledger_transaction_id'
AND REFERENCED_TABLE_NAME = 't_fin_ledger';

SELECT '' as '';

-- Check 7: Order FK Status
SELECT 'CHECK 7: Order Integration' as '';
SELECT '----------------------------------------' as '';

SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_fin_ledger'
AND COLUMN_NAME = 'order_id';

SELECT 
    CASE 
        WHEN COUNT(*) = 1 THEN '✓ FK to t_crm_prod_order exists'
        ELSE '⚠ FK to t_crm_prod_order NOT FOUND (run add_order_fk_manually.sql)'
    END as Status
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_fin_ledger'
AND COLUMN_NAME = 'order_id'
AND REFERENCED_TABLE_NAME = 't_crm_prod_order';

SELECT '' as '';

-- Check 8: Configuration Values
SELECT 'CHECK 8: System Configuration' as '';
SELECT '----------------------------------------' as '';

SELECT 
    config_key,
    config_value,
    description
FROM t_fin_config
ORDER BY config_key;

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'SUMMARY' as '';
SELECT '========================================' as '';

SET @tables_count = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'nizamifarms_db' AND TABLE_NAME LIKE 't_fin_%');
SET @fk_count = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db' AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_req_master') AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @accounts_count = (SELECT COUNT(*) FROM t_fin_accounts);
SET @config_count = (SELECT COUNT(*) FROM t_fin_config);

SELECT 
    CASE 
        WHEN @tables_count = 5 AND @accounts_count >= 20 AND @config_count >= 5 AND @fk_count >= 10
        THEN '✓✓✓ INSTALLATION SUCCESSFUL ✓✓✓'
        ELSE '⚠ Some issues detected - review output above'
    END as 'Overall Status';

SELECT CONCAT('Tables: ', @tables_count, '/5') as '';
SELECT CONCAT('Foreign Keys: ', @fk_count, ' (10+ expected)') as '';
SELECT CONCAT('Core Accounts: ', @accounts_count, ' (20+ expected)') as '';
SELECT CONCAT('Config Entries: ', @config_count, ' (5 expected)') as '';

SELECT '' as '';
SELECT 'Next Steps:' as '';
SELECT '1. If Order FK missing: Run add_order_fk_manually.sql' as '';
SELECT '2. Ready for Laravel models!' as '';
SELECT '3. Ready for CSV import service!' as '';
SELECT '========================================' as '';

