-- =====================================================
-- FIX SETTLEMENT_TRANSACTION_ID TYPE MISMATCH
-- =====================================================
-- Change bigint(20) unsigned to int(11) to match t_fin_ledger.id
-- =====================================================

USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'FIXING TYPE MISMATCH' as Status;
SELECT '========================================' as '';

-- Show current type
SELECT 'Current type of settlement_transaction_id:' as Info;
SELECT COLUMN_NAME, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'settlement_transaction_id';

SELECT '' as '';
SELECT 'Target type (from t_fin_ledger.id):' as Info;
SELECT COLUMN_NAME, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_fin_ledger' 
AND COLUMN_NAME = 'id';

-- Fix the type
SELECT '' as '';
SELECT 'Changing settlement_transaction_id from bigint(20) unsigned to int(11)...' as Status;

ALTER TABLE t_req_master 
MODIFY COLUMN settlement_transaction_id INT(11) NULL 
COMMENT 'Ledger transaction ID for the settlement transfer';

SELECT '✓ Type changed successfully' as Status;

-- Verify the change
SELECT '' as '';
SELECT 'Verification - New type:' as Info;
SELECT COLUMN_NAME, COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME = 'settlement_transaction_id';

-- =====================================================
-- NOW ADD THE FOREIGN KEYS
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
-- FINAL VERIFICATION
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'FINAL VERIFICATION' as Status;
SELECT '========================================' as '';

-- Show all settlement columns and their types
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE
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

SELECT '' as '';

-- Show all settlement-related foreign keys
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

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓ SETTLEMENT SUPPORT COMPLETED!' as Status;
SELECT '========================================' as '';



