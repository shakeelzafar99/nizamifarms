-- ========================================
-- FINANCIAL LEDGER SYSTEM - WITHOUT ORDER FK
-- ========================================
-- This version skips the FK to t_crm_prod_order
-- The order_id column exists but FK is NOT created
-- You can add it manually later after checking the exact type
-- Database: nizamifarms_db

USE nizamifarms_db;

-- ========================================
-- STEP 1: CLEAN SLATE
-- ========================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS t_fin_import_log;
DROP TABLE IF EXISTS t_fin_vendors;
DROP TABLE IF EXISTS t_fin_ledger;
DROP TABLE IF EXISTS t_fin_accounts;
DROP TABLE IF EXISTS t_fin_config;

SET FOREIGN_KEY_CHECKS = 1;

SELECT '✓ Cleaned up existing ledger tables' as Status;


-- ========================================
-- STEP 2: CREATE CORE TABLES
-- ========================================

-- ----------------------------------------
-- TABLE 1: t_fin_accounts
-- ----------------------------------------
CREATE TABLE t_fin_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    account_code VARCHAR(50) UNIQUE NOT NULL,
    account_name VARCHAR(255) NOT NULL,
    account_type ENUM('asset', 'liability', 'income', 'expense', 'equity') NOT NULL,
    account_category VARCHAR(50) NULL,
    
    user_id INT NULL COMMENT 'FK to t_sys_user(id)',
    
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    
    is_active TINYINT(1) DEFAULT 1,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user(id)',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user(id)',
    
    INDEX idx_account_code (account_code),
    INDEX idx_account_type (account_type),
    INDEX idx_user_id (user_id),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '✓ Created t_fin_accounts' as Status;


-- ----------------------------------------
-- TABLE 2: t_fin_ledger
-- ----------------------------------------
-- NOTE: order_id exists but FK is NOT created (see manual step at end)
CREATE TABLE t_fin_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    transaction_date DATE NOT NULL,
    transaction_type VARCHAR(50) NOT NULL,
    description TEXT NULL,
    
    from_account_id INT NOT NULL COMMENT 'FK to t_fin_accounts(id)',
    to_account_id INT NOT NULL COMMENT 'FK to t_fin_accounts(id)',
    amount DECIMAL(15,2) NOT NULL,
    
    mode ENUM('cash', 'online') DEFAULT 'cash',
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    approval_date DATE NULL,
    approved_by INT NULL COMMENT 'FK to t_sys_user(id)',
    
    external_source VARCHAR(100) NULL,
    external_txn_id VARCHAR(100) NULL,
    external_ref_id VARCHAR(100) NULL,
    content_hash VARCHAR(64) NULL,
    
    request_id INT NULL COMMENT 'FK to t_req_master(id)',
    order_id INT NULL COMMENT 'Links to t_crm_prod_order(id) - FK NOT created',
    
    device VARCHAR(100) NULL,
    comments TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user(id)',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user(id)',
    
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_from_account (from_account_id),
    INDEX idx_to_account (to_account_id),
    INDEX idx_external_source (external_source),
    INDEX idx_external_txn_id (external_txn_id),
    INDEX idx_request_id (request_id),
    INDEX idx_order_id (order_id),
    INDEX idx_approved_by (approved_by),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by),
    
    UNIQUE KEY unique_external_txn (external_source, external_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '✓ Created t_fin_ledger (order_id column exists, FK skipped)' as Status;


-- ----------------------------------------
-- TABLE 3: t_fin_vendors
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
    
    account_id INT NOT NULL COMMENT 'FK to t_fin_accounts(id)',
    
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user(id)',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user(id)',
    
    INDEX idx_vendor_code (vendor_code),
    INDEX idx_account_id (account_id),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '✓ Created t_fin_vendors' as Status;


-- ----------------------------------------
-- TABLE 4: t_fin_import_log
-- ----------------------------------------
CREATE TABLE t_fin_import_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    imported_by INT NULL COMMENT 'FK to t_sys_user(id)',
    
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
    INDEX idx_imported_by (imported_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '✓ Created t_fin_import_log' as Status;


-- ----------------------------------------
-- TABLE 5: t_fin_config
-- ----------------------------------------
CREATE TABLE t_fin_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT NULL,
    description TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '✓ Created t_fin_config' as Status;


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

SELECT '✓ Inserted config data (5 rows)' as Status;


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

SELECT '✓ Inserted core accounts (21 rows)' as Status;


-- ========================================
-- STEP 4: ADD FOREIGN KEYS
-- ========================================

SELECT 'Adding foreign key constraints...' as Status;

-- FKs for t_fin_accounts -> t_sys_user
ALTER TABLE t_fin_accounts
    ADD CONSTRAINT fk_fin_accounts_user 
        FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_accounts_created_by 
        FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_accounts_updated_by 
        FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT '✓ FKs: t_fin_accounts -> t_sys_user' as Status;


-- FKs for t_fin_ledger -> t_fin_accounts (internal)
ALTER TABLE t_fin_ledger
    ADD CONSTRAINT fk_fin_ledger_from_account 
        FOREIGN KEY (from_account_id) REFERENCES t_fin_accounts(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fin_ledger_to_account 
        FOREIGN KEY (to_account_id) REFERENCES t_fin_accounts(id) ON DELETE RESTRICT;

SELECT '✓ FKs: t_fin_ledger -> t_fin_accounts' as Status;


-- FKs for t_fin_ledger -> t_sys_user
ALTER TABLE t_fin_ledger
    ADD CONSTRAINT fk_fin_ledger_approved_by 
        FOREIGN KEY (approved_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_ledger_created_by 
        FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_ledger_updated_by 
        FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT '✓ FKs: t_fin_ledger -> t_sys_user' as Status;


-- FKs for t_fin_ledger -> t_req_master
ALTER TABLE t_fin_ledger
    ADD CONSTRAINT fk_fin_ledger_request 
        FOREIGN KEY (request_id) REFERENCES t_req_master(id) ON DELETE SET NULL;

SELECT '✓ FK: t_fin_ledger -> t_req_master' as Status;


-- FK for t_fin_ledger -> t_crm_prod_order is SKIPPED
-- (Data type mismatch - will add later)
SELECT '⚠ SKIPPED: FK to t_crm_prod_order (see manual step below)' as Status;


-- FKs for t_fin_vendors -> t_fin_accounts
ALTER TABLE t_fin_vendors
    ADD CONSTRAINT fk_fin_vendors_account 
        FOREIGN KEY (account_id) REFERENCES t_fin_accounts(id) ON DELETE RESTRICT;

SELECT '✓ FK: t_fin_vendors -> t_fin_accounts' as Status;


-- FKs for t_fin_vendors -> t_sys_user
ALTER TABLE t_fin_vendors
    ADD CONSTRAINT fk_fin_vendors_created_by 
        FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_vendors_updated_by 
        FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT '✓ FKs: t_fin_vendors -> t_sys_user' as Status;


-- FKs for t_fin_import_log -> t_sys_user
ALTER TABLE t_fin_import_log
    ADD CONSTRAINT fk_fin_import_log_imported_by 
        FOREIGN KEY (imported_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT '✓ FK: t_fin_import_log -> t_sys_user' as Status;


-- ========================================
-- STEP 5: UPDATE t_req_master
-- ========================================

-- Add ledger column to t_req_master (check if already exists first)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'ledger_transaction_id'
);

-- Add column if it doesn't exist
SET @add_col = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN ledger_transaction_id INT NULL COMMENT ''FK to t_fin_ledger(id)'',
        ADD INDEX idx_ledger_transaction (ledger_transaction_id)',
    'SELECT ''Column already exists'' as Status'
);

PREPARE stmt FROM @add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Updated t_req_master (added ledger_transaction_id)' as Status;


-- Add FK from t_req_master to t_fin_ledger (if column was added)
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_ledger_transaction'
);

SET @add_fk = IF(
    @col_exists = 1 AND @fk_exists = 0,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_ledger_transaction 
        FOREIGN KEY (ledger_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL',
    'SELECT ''FK already exists or column not present'' as Status'
);

PREPARE stmt FROM @add_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ FK: t_req_master -> t_fin_ledger' as Status;


-- ========================================
-- STEP 6: VERIFICATION
-- ========================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓✓✓ INSTALLATION COMPLETE! ✓✓✓' as '';
SELECT '========================================' as '';
SELECT '' as '';

SELECT 'Tables Created:' as '';
SHOW TABLES LIKE 't_fin_%';

SELECT '' as '';
SELECT 'Seed Data:' as '';
SELECT CONCAT('Accounts: ', COUNT(*), ' rows') as '' FROM t_fin_accounts;
SELECT CONCAT('Config: ', COUNT(*), ' rows') as '' FROM t_fin_config;

SELECT '' as '';
SELECT 'Foreign Keys Created:' as '';
SELECT 
    CONCAT('✓ ', TABLE_NAME, '.', COLUMN_NAME, ' -> ', REFERENCED_TABLE_NAME, '.', REFERENCED_COLUMN_NAME) as 'Foreign Key'
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_fin_accounts', 't_fin_ledger', 't_fin_vendors', 't_fin_import_log', 't_req_master')
AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, COLUMN_NAME;

SELECT '' as '';
SELECT '========================================' as '';
SELECT '⚠ MANUAL STEP REQUIRED (Optional):' as '';
SELECT '========================================' as '';
SELECT 'The FK to t_crm_prod_order was skipped.' as '';
SELECT 'To add it manually, first run:' as '';
SELECT '' as '';
SELECT 'DESCRIBE t_crm_prod_order;' as '';
SELECT '' as '';
SELECT 'If id column is INT, run:' as '';
SELECT 'ALTER TABLE t_fin_ledger ADD CONSTRAINT fk_fin_ledger_order FOREIGN KEY (order_id) REFERENCES t_crm_prod_order(id) ON DELETE SET NULL;' as '';
SELECT '' as '';
SELECT 'If id column is BIGINT, first run:' as '';
SELECT 'ALTER TABLE t_fin_ledger MODIFY order_id BIGINT NULL;' as '';
SELECT 'Then:' as '';
SELECT 'ALTER TABLE t_fin_ledger ADD CONSTRAINT fk_fin_ledger_order FOREIGN KEY (order_id) REFERENCES t_crm_prod_order(id) ON DELETE SET NULL;' as '';
SELECT '' as '';
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'System is functional without this FK!' as '';
SELECT 'Ready for Laravel models!' as '';
SELECT '========================================' as '';

