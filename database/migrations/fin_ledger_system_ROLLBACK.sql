-- ========================================
-- FINANCIAL LEDGER SYSTEM - ROLLBACK SCRIPT
-- ========================================
-- Use this script ONLY if you need to completely remove the ledger system
-- WARNING: This will delete all financial data!
-- Database: nizamifarms_db

USE nizamifarms_db;

-- ========================================
-- CONFIRMATION PROMPT
-- ========================================
-- Before running this script, make sure you have a backup!
-- This action CANNOT be undone!

SELECT '========================================' as '';
SELECT 'ROLLBACK SCRIPT FOR FINANCIAL LEDGER' as '';
SELECT '========================================' as '';
SELECT 'WARNING: This will delete:' as '';
SELECT '  - t_fin_accounts' as '';
SELECT '  - t_fin_ledger' as '';
SELECT '  - t_fin_vendors' as '';
SELECT '  - t_fin_import_log' as '';
SELECT '  - t_fin_config' as '';
SELECT '  - ledger_transaction_id from t_req_master' as '';
SELECT '' as '';
SELECT 'ALL FINANCIAL DATA WILL BE LOST!' as '';
SELECT '' as '';
SELECT 'Press Ctrl+C to CANCEL or wait 5 seconds...' as '';
SELECT SLEEP(5) as 'Waiting...';

-- ========================================
-- STEP 1: DROP FOREIGN KEYS FIRST
-- ========================================

SELECT 'Dropping foreign key constraints...' as Status;

-- Drop FKs from t_req_master
ALTER TABLE t_req_master DROP FOREIGN KEY IF EXISTS fk_req_master_ledger_transaction;

-- Drop FKs from t_fin_import_log
ALTER TABLE t_fin_import_log DROP FOREIGN KEY IF EXISTS fk_fin_import_log_imported_by;

-- Drop FKs from t_fin_vendors
ALTER TABLE t_fin_vendors DROP FOREIGN KEY IF EXISTS fk_fin_vendors_account;
ALTER TABLE t_fin_vendors DROP FOREIGN KEY IF EXISTS fk_fin_vendors_created_by;
ALTER TABLE t_fin_vendors DROP FOREIGN KEY IF EXISTS fk_fin_vendors_updated_by;

-- Drop FKs from t_fin_ledger
ALTER TABLE t_fin_ledger DROP FOREIGN KEY IF EXISTS fk_fin_ledger_from_account;
ALTER TABLE t_fin_ledger DROP FOREIGN KEY IF EXISTS fk_fin_ledger_to_account;
ALTER TABLE t_fin_ledger DROP FOREIGN KEY IF EXISTS fk_fin_ledger_approved_by;
ALTER TABLE t_fin_ledger DROP FOREIGN KEY IF EXISTS fk_fin_ledger_request;
ALTER TABLE t_fin_ledger DROP FOREIGN KEY IF EXISTS fk_fin_ledger_order;
ALTER TABLE t_fin_ledger DROP FOREIGN KEY IF EXISTS fk_fin_ledger_created_by;
ALTER TABLE t_fin_ledger DROP FOREIGN KEY IF EXISTS fk_fin_ledger_updated_by;

-- Drop FKs from t_fin_accounts
ALTER TABLE t_fin_accounts DROP FOREIGN KEY IF EXISTS fk_fin_accounts_user;
ALTER TABLE t_fin_accounts DROP FOREIGN KEY IF EXISTS fk_fin_accounts_created_by;
ALTER TABLE t_fin_accounts DROP FOREIGN KEY IF EXISTS fk_fin_accounts_updated_by;

SELECT 'Foreign keys dropped' as Status;


-- ========================================
-- STEP 2: DROP TABLES
-- ========================================

SELECT 'Dropping tables...' as Status;

DROP TABLE IF EXISTS t_fin_import_log;
SELECT 'Dropped t_fin_import_log' as Status;

DROP TABLE IF EXISTS t_fin_vendors;
SELECT 'Dropped t_fin_vendors' as Status;

DROP TABLE IF EXISTS t_fin_ledger;
SELECT 'Dropped t_fin_ledger' as Status;

DROP TABLE IF EXISTS t_fin_accounts;
SELECT 'Dropped t_fin_accounts' as Status;

DROP TABLE IF EXISTS t_fin_config;
SELECT 'Dropped t_fin_config' as Status;


-- ========================================
-- STEP 3: REMOVE COLUMN FROM t_req_master
-- ========================================

SELECT 'Removing ledger column from t_req_master...' as Status;

ALTER TABLE t_req_master DROP COLUMN IF EXISTS ledger_transaction_id;

SELECT 'Removed ledger_transaction_id from t_req_master' as Status;


-- ========================================
-- STEP 4: VERIFICATION
-- ========================================

SELECT '========================================' as '';
SELECT 'ROLLBACK COMPLETE!' as Status;
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'Removed Tables:' as '';
SELECT '  - t_fin_accounts' as '';
SELECT '  - t_fin_ledger' as '';
SELECT '  - t_fin_vendors' as '';
SELECT '  - t_fin_import_log' as '';
SELECT '  - t_fin_config' as '';
SELECT '' as '';
SELECT 'Updated Tables:' as '';
SELECT '  - t_req_master (removed ledger_transaction_id)' as '';
SELECT '' as '';
SELECT 'All financial ledger data has been removed.' as '';
SELECT '========================================' as '';

-- Show remaining tables (should not include t_fin_*)
SHOW TABLES LIKE 't_fin_%';

