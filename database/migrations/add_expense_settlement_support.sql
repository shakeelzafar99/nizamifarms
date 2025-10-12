-- =====================================================
-- ADD EXPENSE SETTLEMENT SUPPORT
-- =====================================================
-- Purpose: Add columns to track expense settlements
-- when expenses are paid from non-Expense-Fund sources
-- and need to be reconciled later
-- =====================================================

-- Use your database
USE nizamifarms_db;

-- =====================================================
-- STEP 1: VERIFY CURRENT STRUCTURE
-- =====================================================

SELECT '========================================' as '';
SELECT 'VERIFYING CURRENT STRUCTURE' as Status;
SELECT '========================================' as '';

-- Check if t_req_master exists and has required columns
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ t_req_master table exists'
        ELSE '✗ ERROR: t_req_master table not found!'
    END as 'Table Check'
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master';

-- Check if payment_source_account_id already exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ payment_source_account_id column exists'
        ELSE '✗ WARNING: payment_source_account_id not found (you need to run add_payment_source_to_requests.sql first!)'
    END as 'Column Check'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'payment_source_account_id';

-- Check if ledger_transaction_id already exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ ledger_transaction_id column exists'
        ELSE '✗ WARNING: ledger_transaction_id not found'
    END as 'Column Check'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'ledger_transaction_id';

-- =====================================================
-- STEP 2: ADD SETTLEMENT COLUMNS TO t_req_master
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'ADDING SETTLEMENT COLUMNS' as Status;
SELECT '========================================' as '';

-- Add settlement_status column (if not exists)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_status'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_status ENUM(''not_required'', ''pending'', ''settled'') DEFAULT ''not_required'' 
        COMMENT ''Settlement status for expenses paid from non-Expense-Fund sources'' AFTER payment_source_account_id,
        ADD INDEX idx_settlement_status (settlement_status)',
    'SELECT ''Column settlement_status already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added settlement_status column' as Status;

-- Add settled_at column (if not exists)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settled_at'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settled_at TIMESTAMP NULL 
        COMMENT ''When the settlement was completed'' AFTER settlement_status,
        ADD INDEX idx_settled_at (settled_at)',
    'SELECT ''Column settled_at already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added settled_at column' as Status;

-- Add settled_by column (if not exists)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settled_by'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settled_by INT NULL 
        COMMENT ''User who performed the settlement'' AFTER settled_at',
    'SELECT ''Column settled_by already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added settled_by column' as Status;

-- Add settlement_transaction_id column (if not exists)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_transaction_id'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_transaction_id BIGINT UNSIGNED NULL 
        COMMENT ''Ledger transaction ID for the settlement transfer'' AFTER settled_by',
    'SELECT ''Column settlement_transaction_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added settlement_transaction_id column' as Status;

-- Add settlement_destination_account_id column (if not exists)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_destination_account_id'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_destination_account_id INT NULL 
        COMMENT ''Account that received the settlement (e.g., NF Main Till)'' AFTER settlement_transaction_id',
    'SELECT ''Column settlement_destination_account_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added settlement_destination_account_id column' as Status;

-- Add settlement_notes column (if not exists)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_notes'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_notes TEXT NULL 
        COMMENT ''Notes added during settlement'' AFTER settlement_destination_account_id',
    'SELECT ''Column settlement_notes already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added settlement_notes column' as Status;

-- =====================================================
-- STEP 3: ADD FOREIGN KEYS (safely)
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'ADDING FOREIGN KEYS' as Status;
SELECT '========================================' as '';

-- FK for settled_by -> t_sys_user.id
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settled_by'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settled_by 
        FOREIGN KEY (settled_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT ''FK fk_req_master_settled_by already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added FK: settled_by -> t_sys_user' as Status;

-- FK for settlement_transaction_id -> t_fin_ledger.id
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settlement_transaction'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settlement_transaction 
        FOREIGN KEY (settlement_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL',
    'SELECT ''FK fk_req_master_settlement_transaction already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added FK: settlement_transaction_id -> t_fin_ledger' as Status;

-- FK for settlement_destination_account_id -> t_fin_accounts.id
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settlement_destination'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settlement_destination 
        FOREIGN KEY (settlement_destination_account_id) REFERENCES t_fin_accounts(id) ON DELETE SET NULL',
    'SELECT ''FK fk_req_master_settlement_destination already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added FK: settlement_destination_account_id -> t_fin_accounts' as Status;

-- =====================================================
-- STEP 4: VERIFY NEW STRUCTURE
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFYING NEW STRUCTURE' as Status;
SELECT '========================================' as '';

-- Show all new columns
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME IN (
    'settlement_status',
    'settled_at',
    'settled_by',
    'settlement_transaction_id',
    'settlement_destination_account_id',
    'settlement_notes'
)
ORDER BY ORDINAL_POSITION;

-- Show all foreign keys
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND CONSTRAINT_NAME LIKE 'fk_req_master_settle%'
ORDER BY CONSTRAINT_NAME;

-- =====================================================
-- STEP 5: UPDATE RequestModel $fillable (MANUAL STEP!)
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT '⚠️  MANUAL STEP REQUIRED!' as Status;
SELECT '========================================' as '';
SELECT 'You need to add these fields to RequestModel $fillable array:' as Instructions;
SELECT '  - settlement_status' as Field;
SELECT '  - settled_at' as Field;
SELECT '  - settled_by' as Field;
SELECT '  - settlement_transaction_id' as Field;
SELECT '  - settlement_destination_account_id' as Field;
SELECT '  - settlement_notes' as Field;
SELECT '' as '';
SELECT 'File: app/Models/Request/RequestModel.php' as Location;
SELECT 'Line: ~20-46 (in $fillable array)' as Location;

-- =====================================================
-- FINAL STATUS
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓ SETTLEMENT COLUMNS ADDED SUCCESSFULLY!' as Status;
SELECT '========================================' as '';
SELECT 'Next steps:' as '';
SELECT '1. Update RequestModel $fillable array (see above)' as Step;
SELECT '2. Add relationships in RequestModel:' as Step;
SELECT '   - settledBy() -> BelongsTo UserModel' as SubStep;
SELECT '   - settlementTransaction() -> BelongsTo LedgerModel' as SubStep;
SELECT '   - settlementDestinationAccount() -> BelongsTo AccountModel' as SubStep;
SELECT '3. Update LedgerModel to add TYPE_SETTLEMENT constant' as Step;
SELECT '4. Implement settlement controller and service' as Step;



