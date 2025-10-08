-- ========================================
-- FINANCIAL LEDGER SYSTEM - FIXED VERSION
-- ========================================
-- This version checks if tables exist before creating FKs
-- Database: nizamifarms_db

USE nizamifarms_db;

-- ========================================
-- STEP 1: DROP EXISTING TABLES (Clean slate)
-- ========================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS t_fin_import_log;
DROP TABLE IF EXISTS t_fin_vendors;
DROP TABLE IF EXISTS t_fin_ledger;
DROP TABLE IF EXISTS t_fin_accounts;
DROP TABLE IF EXISTS t_fin_config;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Dropped existing tables (if any)' as Status;


-- ========================================
-- STEP 2: CREATE TABLES WITHOUT FOREIGN KEYS
-- ========================================

-- ----------------------------------------
-- 1. ACCOUNTS TABLE
-- ----------------------------------------
CREATE TABLE t_fin_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    account_code VARCHAR(50) UNIQUE NOT NULL,
    account_name VARCHAR(255) NOT NULL,
    account_type ENUM('asset', 'liability', 'income', 'expense', 'equity') NOT NULL,
    account_category VARCHAR(50) NULL,
    
    user_id INT NULL,
    
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    
    is_active TINYINT(1) DEFAULT 1,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    INDEX idx_account_code (account_code),
    INDEX idx_account_type (account_type),
    INDEX idx_account_category (account_category),
    INDEX idx_user_id (user_id),
    INDEX idx_is_active (is_active),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Created t_fin_accounts' as Status;


-- ----------------------------------------
-- 2. LEDGER TABLE
-- ----------------------------------------
CREATE TABLE t_fin_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    transaction_date DATE NOT NULL,
    transaction_type VARCHAR(50) NOT NULL,
    description TEXT NULL,
    
    from_account_id INT NOT NULL,
    to_account_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    
    mode ENUM('cash', 'online') DEFAULT 'cash',
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    approval_date DATE NULL,
    approved_by INT NULL,
    
    external_source VARCHAR(100) NULL,
    external_txn_id VARCHAR(100) NULL,
    external_ref_id VARCHAR(100) NULL,
    content_hash VARCHAR(64) NULL,
    
    request_id INT NULL,
    order_id INT NULL,
    
    device VARCHAR(100) NULL,
    comments TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_from_account (from_account_id),
    INDEX idx_to_account (to_account_id),
    INDEX idx_approval_status (approval_status),
    INDEX idx_external_source (external_source),
    INDEX idx_external_txn_id (external_txn_id),
    INDEX idx_content_hash (content_hash),
    INDEX idx_request_id (request_id),
    INDEX idx_order_id (order_id),
    INDEX idx_approved_by (approved_by),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by),
    
    UNIQUE KEY unique_external_txn (external_source, external_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Created t_fin_ledger' as Status;


-- ----------------------------------------
-- 3. VENDORS TABLE
-- ----------------------------------------
CREATE TABLE t_fin_vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    vendor_code VARCHAR(50) UNIQUE NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    
    contact_person VARCHAR(255) NULL,
    contact_phone VARCHAR(50) NULL,
    contact_email VARCHAR(255) NULL,
    address TEXT NULL,
    
    payment_terms VARCHAR(100) NULL,
    
    account_id INT NOT NULL,
    
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    INDEX idx_vendor_code (vendor_code),
    INDEX idx_vendor_name (vendor_name),
    INDEX idx_account_id (account_id),
    INDEX idx_is_active (is_active),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Created t_fin_vendors' as Status;


-- ----------------------------------------
-- 4. IMPORT LOG TABLE
-- ----------------------------------------
CREATE TABLE t_fin_import_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    imported_by INT NULL,
    
    filename VARCHAR(255) NULL,
    total_rows INT DEFAULT 0,
    new_rows INT DEFAULT 0,
    duplicate_rows INT DEFAULT 0,
    error_rows INT DEFAULT 0,
    
    status ENUM('processing', 'completed', 'failed') DEFAULT 'processing',
    error_log TEXT NULL,
    summary TEXT NULL,
    
    processing_time_seconds INT NULL,
    
    INDEX idx_import_date (import_date),
    INDEX idx_imported_by (imported_by),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Created t_fin_import_log' as Status;


-- ----------------------------------------
-- 5. CONFIG TABLE
-- ----------------------------------------
CREATE TABLE t_fin_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT NULL,
    description TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Created t_fin_config' as Status;


-- ========================================
-- STEP 3: INSERT SEED DATA
-- ========================================

INSERT INTO t_fin_config (config_key, config_value, description) 
VALUES 
    ('expense_fund_account', 'EXP_FUND', 'Default account code for funding expenses'),
    ('legacy_import_enabled', '1', 'Allow CSV imports'),
    ('last_successful_import', NULL, 'Timestamp of last successful import'),
    ('high_balance_threshold_employee', '100000', 'Alert threshold for employee cash'),
    ('high_balance_threshold_vendor', '500000', 'Alert threshold for vendor payable')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

SELECT 'Inserted config data' as Status;

INSERT INTO t_fin_accounts (account_code, account_name, account_type, account_category, is_active, created_by)
VALUES
    ('NF_CASH', 'NF Cash (Main Till)', 'asset', 'cash', 1, 1),
    ('ONLINE', 'Online Bank', 'asset', 'bank', 1, 1),
    ('REV_SALES', 'Sales Revenue', 'income', 'revenue', 1, 1),
    ('REV_OTHER', 'Other Income', 'income', 'revenue', 1, 1),
    ('EXP_FUND', 'Expense Fund', 'asset', 'cash', 1, 1),
    ('EXP_PURCHASES', 'Purchases', 'expense', 'expense', 1, 1),
    ('EXP_FUEL', 'Fuel/Petrol', 'expense', 'expense', 1, 1),
    ('EXP_FOOD', 'Food', 'expense', 'expense', 1, 1),
    ('EXP_RENT', 'Rent', 'expense', 'expense', 1, 1),
    ('EXP_UTILITIES', 'Utility Bills', 'expense', 'expense', 1, 1),
    ('EXP_SALARIES', 'Staff Salaries', 'expense', 'expense', 1, 1),
    ('EXP_PACKAGING', 'Packaging', 'expense', 'expense', 1, 1),
    ('EXP_MARKETING', 'Marketing', 'expense', 'expense', 1, 1),
    ('EXP_EQUIPMENT', 'Equipment', 'expense', 'expense', 1, 1),
    ('EXP_MAINTENANCE', 'Maintenance', 'expense', 'expense', 1, 1),
    ('EXP_TELECOM', 'Telecommunication', 'expense', 'expense', 1, 1),
    ('EXP_INTERNET', 'Internet', 'expense', 'expense', 1, 1),
    ('EXP_BANK_CHARGES', 'Bank Charges', 'expense', 'expense', 1, 1),
    ('EXP_INDRIVE', 'Indrive Expenses', 'expense', 'expense', 1, 1),
    ('EXP_OTHER', 'Other Expenses', 'expense', 'expense', 1, 1),
    ('EQUITY_OPENING', 'Opening Balance Equity', 'equity', 'opening_balance', 1, 1)
ON DUPLICATE KEY UPDATE account_name = VALUES(account_name);

SELECT 'Inserted core accounts' as Status;


-- ========================================
-- STEP 4: CHECK WHICH TABLES EXIST FOR FKs
-- ========================================

SELECT 'Checking existing tables for FK creation...' as Status;

SET @user_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' AND TABLE_NAME = 't_sys_user');
    
SET @req_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' AND TABLE_NAME = 't_req_master');
    
SET @order_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' AND TABLE_NAME = 't_crm_prod_order');

SELECT 
    CASE WHEN @user_table_exists = 1 THEN '✓' ELSE '✗' END as t_sys_user,
    CASE WHEN @req_table_exists = 1 THEN '✓' ELSE '✗' END as t_req_master,
    CASE WHEN @order_table_exists = 1 THEN '✓' ELSE '✗' END as t_crm_prod_order;


-- ========================================
-- STEP 5: ADD FOREIGN KEYS (ONE AT A TIME)
-- ========================================

SELECT 'Adding foreign keys one by one...' as Status;

-- FK for t_fin_accounts -> t_sys_user (ONLY if t_sys_user exists)
SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_accounts 
        ADD CONSTRAINT fk_fin_accounts_user FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: t_sys_user not found" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_accounts 
        ADD CONSTRAINT fk_fin_accounts_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: created_by to t_sys_user" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_accounts 
        ADD CONSTRAINT fk_fin_accounts_updated_by FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: updated_by to t_sys_user" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Added FKs for t_fin_accounts' as Status;


-- FK for t_fin_ledger -> t_fin_accounts (ALWAYS - just created)
ALTER TABLE t_fin_ledger
    ADD CONSTRAINT fk_fin_ledger_from_account FOREIGN KEY (from_account_id) 
        REFERENCES t_fin_accounts(id) ON DELETE RESTRICT;
        
SELECT 'Added FK: t_fin_ledger.from_account_id -> t_fin_accounts' as Status;

ALTER TABLE t_fin_ledger
    ADD CONSTRAINT fk_fin_ledger_to_account FOREIGN KEY (to_account_id) 
        REFERENCES t_fin_accounts(id) ON DELETE RESTRICT;
        
SELECT 'Added FK: t_fin_ledger.to_account_id -> t_fin_accounts' as Status;


-- FK for t_fin_ledger -> t_sys_user (ONLY if exists)
SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_ledger 
        ADD CONSTRAINT fk_fin_ledger_approved_by FOREIGN KEY (approved_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: approved_by to t_sys_user" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_ledger 
        ADD CONSTRAINT fk_fin_ledger_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: created_by to t_sys_user" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_ledger 
        ADD CONSTRAINT fk_fin_ledger_updated_by FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: updated_by to t_sys_user" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Added FKs for t_fin_ledger -> t_sys_user (if available)' as Status;


-- FK for t_fin_ledger -> t_req_master (ONLY if exists)
SET @sql = IF(
    @req_table_exists = 1,
    'ALTER TABLE t_fin_ledger 
        ADD CONSTRAINT fk_fin_ledger_request FOREIGN KEY (request_id) REFERENCES t_req_master(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: request_id to t_req_master (table not found)" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Added FK for t_fin_ledger -> t_req_master (if available)' as Status;


-- FK for t_fin_ledger -> t_crm_prod_order (ONLY if exists)
SET @sql = IF(
    @order_table_exists = 1,
    'ALTER TABLE t_fin_ledger 
        ADD CONSTRAINT fk_fin_ledger_order FOREIGN KEY (order_id) REFERENCES t_crm_prod_order(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: order_id to t_crm_prod_order (table not found)" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Added FK for t_fin_ledger -> t_crm_prod_order (if available)' as Status;


-- FK for t_fin_vendors -> t_fin_accounts (ALWAYS)
ALTER TABLE t_fin_vendors
    ADD CONSTRAINT fk_fin_vendors_account FOREIGN KEY (account_id) 
        REFERENCES t_fin_accounts(id) ON DELETE RESTRICT;

SELECT 'Added FK: t_fin_vendors -> t_fin_accounts' as Status;


-- FK for t_fin_vendors -> t_sys_user (ONLY if exists)
SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_vendors 
        ADD CONSTRAINT fk_fin_vendors_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: t_fin_vendors.created_by" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_vendors 
        ADD CONSTRAINT fk_fin_vendors_updated_by FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: t_fin_vendors.updated_by" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Added FKs for t_fin_vendors' as Status;


-- FK for t_fin_import_log -> t_sys_user (ONLY if exists)
SET @sql = IF(
    @user_table_exists = 1,
    'ALTER TABLE t_fin_import_log 
        ADD CONSTRAINT fk_fin_import_log_imported_by FOREIGN KEY (imported_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: t_fin_import_log.imported_by" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Added FK for t_fin_import_log' as Status;


-- ========================================
-- STEP 6: ALTER t_req_master (if exists)
-- ========================================

SET @sql = IF(
    @req_table_exists = 1,
    'ALTER TABLE t_req_master ADD COLUMN IF NOT EXISTS ledger_transaction_id INT NULL, 
     ADD INDEX IF NOT EXISTS idx_ledger_transaction (ledger_transaction_id)',
    'SELECT "Skipped: t_req_master not found" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add FK for t_req_master -> t_fin_ledger (if t_req_master exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'ledger_transaction_id');

SET @sql = IF(
    @col_exists = 1,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_ledger_transaction 
        FOREIGN KEY (ledger_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL',
    'SELECT "Skipped FK: ledger_transaction_id column not added" as Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Updated t_req_master (if available)' as Status;


-- ========================================
-- STEP 7: FINAL VERIFICATION
-- ========================================

SELECT '========================================' as '';
SELECT 'INSTALLATION COMPLETE!' as Status;
SELECT '========================================' as '';

SELECT 'Created Tables:' as Info;
SHOW TABLES LIKE 't_fin_%';

SELECT '' as '';
SELECT 'Core Accounts Seeded:' as Info;
SELECT COUNT(*) as account_count FROM t_fin_accounts;

SELECT '' as '';
SELECT 'Foreign Keys Created:' as Info;
SELECT 
    TABLE_NAME,
    CONSTRAINT_NAME
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_req_master')
AND CONSTRAINT_TYPE = 'FOREIGN KEY'
ORDER BY TABLE_NAME;

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'Ready to proceed with Laravel models!' as '';
SELECT '========================================' as '';

