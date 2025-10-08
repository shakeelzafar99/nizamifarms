-- ========================================
-- FINANCIAL LEDGER SYSTEM - SAFE VERSION
-- ========================================
-- This script creates a simplified ledger system with proper FK relationships
-- Following existing nizamifarms_db conventions
-- Database: nizamifarms_db

USE nizamifarms_db;

-- ========================================
-- STEP 1: CREATE TABLES WITHOUT FOREIGN KEYS
-- ========================================

-- ----------------------------------------
-- 1. ACCOUNTS TABLE (Master registry)
-- ----------------------------------------
DROP TABLE IF EXISTS t_fin_accounts;
CREATE TABLE t_fin_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Account identification
    account_code VARCHAR(50) UNIQUE NOT NULL COMMENT 'Unique code: NF_CASH, ONLINE, CASH_EMP_JAZIB, VEN_LACARNE',
    account_name VARCHAR(255) NOT NULL COMMENT 'Display name',
    account_type ENUM('asset', 'liability', 'income', 'expense', 'equity') NOT NULL,
    account_category VARCHAR(50) NULL COMMENT 'cash, bank, employee_cash, vendor_payable, expense, revenue',
    
    -- Links to existing system
    user_id INT NULL COMMENT 'FK to t_sys_user for employee cash accounts',
    
    -- Balances
    opening_balance DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Initial balance from legacy import',
    current_balance DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Running balance',
    
    -- Status
    is_active TINYINT(1) DEFAULT 1,
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user',
    
    -- Indexes
    INDEX idx_account_code (account_code),
    INDEX idx_account_type (account_type),
    INDEX idx_account_category (account_category),
    INDEX idx_user_id (user_id),
    INDEX idx_is_active (is_active),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Account registry for ledger system';

SELECT 'Created t_fin_accounts (without FKs)' as Status;


-- ----------------------------------------
-- 2. LEDGER TABLE (All transactions)
-- ----------------------------------------
DROP TABLE IF EXISTS t_fin_ledger;
CREATE TABLE t_fin_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Transaction header
    transaction_date DATE NOT NULL COMMENT 'Date of transaction',
    transaction_type VARCHAR(50) NOT NULL COMMENT 'invoice, expense, vendor_purchase, vendor_payment, employee_deposit, opening_balance',
    description TEXT NULL COMMENT 'Transaction description',
    
    -- From → To pattern
    from_account_id INT NOT NULL COMMENT 'FK to t_fin_accounts (source)',
    to_account_id INT NOT NULL COMMENT 'FK to t_fin_accounts (destination)',
    amount DECIMAL(15,2) NOT NULL COMMENT 'Transaction amount',
    
    -- Payment mode & approval
    mode ENUM('cash', 'online') DEFAULT 'cash',
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    approval_date DATE NULL,
    approved_by INT NULL COMMENT 'FK to t_sys_user',
    
    -- External references (for deduplication and traceability)
    external_source VARCHAR(100) NULL COMMENT 'legacy_import, webapp_expense, webapp_invoice, appsheet',
    external_txn_id VARCHAR(100) NULL COMMENT 'Original transaction ID from source',
    external_ref_id VARCHAR(100) NULL COMMENT 'Reference ID (e.g., invoice #, ref id)',
    content_hash VARCHAR(64) NULL COMMENT 'MD5 hash for duplicate detection',
    
    -- Links to existing system
    request_id INT NULL COMMENT 'FK to t_req_master for expense requests',
    order_id INT NULL COMMENT 'FK to t_crm_prod_order for invoices',
    
    -- Additional metadata
    device VARCHAR(100) NULL COMMENT 'Device ID for mobile transactions',
    comments TEXT NULL COMMENT 'Additional comments',
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user',
    
    -- Indexes
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
    
    -- Unique constraint for deduplication
    UNIQUE KEY unique_external_txn (external_source, external_txn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='From-To ledger with deduplication';

SELECT 'Created t_fin_ledger (without FKs)' as Status;


-- ----------------------------------------
-- 3. VENDORS TABLE (Master data)
-- ----------------------------------------
DROP TABLE IF EXISTS t_fin_vendors;
CREATE TABLE t_fin_vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Vendor identification
    vendor_code VARCHAR(50) UNIQUE NOT NULL COMMENT 'Unique code: VEN_LACARNE',
    vendor_name VARCHAR(255) NOT NULL COMMENT 'Display name',
    
    -- Contact details
    contact_person VARCHAR(255) NULL,
    contact_phone VARCHAR(50) NULL,
    contact_email VARCHAR(255) NULL,
    address TEXT NULL,
    
    -- Payment terms
    payment_terms VARCHAR(100) NULL COMMENT 'Net 30, Cash on delivery, etc.',
    
    -- Link to account
    account_id INT NOT NULL COMMENT 'FK to t_fin_accounts (their payable account)',
    
    -- Status
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    
    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user',
    
    -- Indexes
    INDEX idx_vendor_code (vendor_code),
    INDEX idx_vendor_name (vendor_name),
    INDEX idx_account_id (account_id),
    INDEX idx_is_active (is_active),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Vendor master data';

SELECT 'Created t_fin_vendors (without FKs)' as Status;


-- ----------------------------------------
-- 4. IMPORT LOG TABLE (Track CSV imports)
-- ----------------------------------------
DROP TABLE IF EXISTS t_fin_import_log;
CREATE TABLE t_fin_import_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Import details
    import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    imported_by INT NULL COMMENT 'FK to t_sys_user',
    
    filename VARCHAR(255) NULL,
    total_rows INT DEFAULT 0,
    new_rows INT DEFAULT 0 COMMENT 'Actually imported',
    duplicate_rows INT DEFAULT 0 COMMENT 'Skipped (already exists)',
    error_rows INT DEFAULT 0,
    
    status ENUM('processing', 'completed', 'failed') DEFAULT 'processing',
    error_log TEXT NULL COMMENT 'JSON of errors',
    summary TEXT NULL COMMENT 'JSON of results',
    
    processing_time_seconds INT NULL,
    
    -- Indexes
    INDEX idx_import_date (import_date),
    INDEX idx_imported_by (imported_by),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='CSV import history and logs';

SELECT 'Created t_fin_import_log (without FKs)' as Status;


-- ----------------------------------------
-- 5. CONFIG TABLE (System settings)
-- ----------------------------------------
DROP TABLE IF EXISTS t_fin_config;
CREATE TABLE t_fin_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    config_key VARCHAR(100) UNIQUE NOT NULL,
    config_value TEXT NULL,
    description TEXT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Financial system configuration';

SELECT 'Created t_fin_config (without FKs)' as Status;


-- ========================================
-- STEP 2: ALTER t_req_master (Add ledger link)
-- ========================================

-- Check if column already exists before adding
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'ledger_transaction_id'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master ADD COLUMN ledger_transaction_id INT NULL COMMENT ''FK to t_fin_ledger when posted'', ADD INDEX idx_ledger_transaction (ledger_transaction_id)',
    'SELECT ''Column ledger_transaction_id already exists in t_req_master'' as Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Updated t_req_master (added ledger link if needed)' as Status;


-- ========================================
-- STEP 3: INSERT SEED DATA
-- ========================================

-- Insert default system configuration
INSERT INTO t_fin_config (config_key, config_value, description) 
VALUES 
    ('expense_fund_account', 'EXP_FUND', 'Default account code for funding expenses'),
    ('legacy_import_enabled', '1', 'Allow CSV imports (0 to disable when fully migrated)'),
    ('last_successful_import', NULL, 'Timestamp of last successful import'),
    ('high_balance_threshold_employee', '100000', 'Alert threshold for employee cash balance'),
    ('high_balance_threshold_vendor', '500000', 'Alert threshold for vendor payable balance')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

SELECT 'Inserted seed data into t_fin_config' as Status;


-- Insert core system accounts (will be used by everyone)
INSERT INTO t_fin_accounts (account_code, account_name, account_type, account_category, is_active, created_by)
VALUES
    -- Assets
    ('NF_CASH', 'NF Cash (Main Till)', 'asset', 'cash', 1, 1),
    ('ONLINE', 'Online Bank', 'asset', 'bank', 1, 1),
    
    -- Income
    ('REV_SALES', 'Sales Revenue', 'income', 'revenue', 1, 1),
    ('REV_OTHER', 'Other Income', 'income', 'revenue', 1, 1),
    
    -- Expenses
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
    
    -- Equity (for opening balances)
    ('EQUITY_OPENING', 'Opening Balance Equity', 'equity', 'opening_balance', 1, 1)
ON DUPLICATE KEY UPDATE account_name = VALUES(account_name);

SELECT 'Inserted core system accounts into t_fin_accounts' as Status;


-- ========================================
-- STEP 4: ADD FOREIGN KEY CONSTRAINTS
-- ========================================
-- We add these separately so we can see exactly which one fails if any

SELECT 'Now adding foreign key constraints...' as Status;

-- FK for t_fin_accounts
ALTER TABLE t_fin_accounts
    ADD CONSTRAINT fk_fin_accounts_user FOREIGN KEY (user_id) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_accounts_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_accounts_updated_by FOREIGN KEY (updated_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT 'Added FKs: t_fin_accounts -> t_sys_user' as Status;


-- FK for t_fin_ledger
ALTER TABLE t_fin_ledger
    ADD CONSTRAINT fk_fin_ledger_from_account FOREIGN KEY (from_account_id) 
        REFERENCES t_fin_accounts(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fin_ledger_to_account FOREIGN KEY (to_account_id) 
        REFERENCES t_fin_accounts(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fin_ledger_approved_by FOREIGN KEY (approved_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_ledger_request FOREIGN KEY (request_id) 
        REFERENCES t_req_master(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_ledger_order FOREIGN KEY (order_id) 
        REFERENCES t_crm_prod_order(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_ledger_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_ledger_updated_by FOREIGN KEY (updated_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT 'Added FKs: t_fin_ledger -> t_fin_accounts, t_sys_user, t_req_master, t_crm_prod_order' as Status;


-- FK for t_fin_vendors
ALTER TABLE t_fin_vendors
    ADD CONSTRAINT fk_fin_vendors_account FOREIGN KEY (account_id) 
        REFERENCES t_fin_accounts(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_fin_vendors_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_fin_vendors_updated_by FOREIGN KEY (updated_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT 'Added FKs: t_fin_vendors -> t_fin_accounts, t_sys_user' as Status;


-- FK for t_fin_import_log
ALTER TABLE t_fin_import_log
    ADD CONSTRAINT fk_fin_import_log_imported_by FOREIGN KEY (imported_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT 'Added FKs: t_fin_import_log -> t_sys_user' as Status;


-- FK for t_req_master (if ledger column was added)
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_ledger_transaction'
);

SET @sql = IF(
    @fk_exists = 0 AND @col_exists = 1,
    'ALTER TABLE t_req_master ADD CONSTRAINT fk_req_master_ledger_transaction FOREIGN KEY (ledger_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL',
    'SELECT ''FK for t_req_master.ledger_transaction_id already exists or column not present'' as Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Added FK: t_req_master -> t_fin_ledger (if applicable)' as Status;


-- ========================================
-- STEP 5: VERIFICATION
-- ========================================

SELECT '========================================' as '';
SELECT 'INSTALLATION COMPLETE!' as Status;
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'Created Tables:' as '';
SELECT '  1. t_fin_accounts (Account registry)' as '';
SELECT '  2. t_fin_ledger (All transactions)' as '';
SELECT '  3. t_fin_vendors (Vendor master)' as '';
SELECT '  4. t_fin_import_log (Import history)' as '';
SELECT '  5. t_fin_config (System settings)' as '';
SELECT '' as '';
SELECT 'Updated Tables:' as '';
SELECT '  - t_req_master (added ledger_transaction_id)' as '';
SELECT '' as '';
SELECT 'Seeded Data:' as '';
SELECT '  - Core system accounts (NF_CASH, ONLINE, etc.)' as '';
SELECT '  - Default configuration settings' as '';
SELECT '' as '';
SELECT 'Foreign Keys:' as '';
SELECT '  - All FK relationships established' as '';
SELECT '' as '';
SELECT 'Next Steps:' as '';
SELECT '  1. Verify table structure' as '';
SELECT '  2. Test CSV import functionality' as '';
SELECT '  3. Create Laravel models and controllers' as '';
SELECT '' as '';
SELECT '========================================' as '';

-- Show created tables
SHOW TABLES LIKE 't_fin_%';

