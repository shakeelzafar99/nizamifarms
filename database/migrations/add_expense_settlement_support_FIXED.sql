-- =====================================================
-- ADD EXPENSE SETTLEMENT SUPPORT - FIXED VERSION
-- =====================================================
-- This version checks actual column types before adding FKs
-- =====================================================

USE nizamifarms_db;

SELECT '========================================' as '';
SELECT 'ADDING SETTLEMENT SUPPORT (TYPE-SAFE)' as Status;
SELECT '========================================' as '';

-- =====================================================
-- STEP 1: DROP ANY PARTIALLY CREATED FKS (if exists)
-- =====================================================

SELECT 'Cleaning up any failed FK attempts...' as Status;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settled_by'
);

SET @sql = IF(
    @fk_exists > 0,
    'ALTER TABLE t_req_master DROP FOREIGN KEY fk_req_master_settled_by',
    'SELECT ''FK fk_req_master_settled_by does not exist'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settlement_transaction'
);

SET @sql = IF(
    @fk_exists > 0,
    'ALTER TABLE t_req_master DROP FOREIGN KEY fk_req_master_settlement_transaction',
    'SELECT ''FK fk_req_master_settlement_transaction does not exist'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settlement_destination'
);

SET @sql = IF(
    @fk_exists > 0,
    'ALTER TABLE t_req_master DROP FOREIGN KEY fk_req_master_settlement_destination',
    'SELECT ''FK fk_req_master_settlement_destination does not exist'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Cleanup complete' as Status;

-- =====================================================
-- STEP 2: ADD COLUMNS (if not exist)
-- =====================================================

SELECT '' as '';
SELECT 'Adding settlement columns...' as Status;

-- settlement_status
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
        COMMENT ''Settlement status for expenses'' AFTER payment_source_account_id,
        ADD INDEX idx_settlement_status (settlement_status)',
    'SELECT ''settlement_status already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- settled_at
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
        ADD COLUMN settled_at TIMESTAMP NULL AFTER settlement_status,
        ADD INDEX idx_settled_at (settled_at)',
    'SELECT ''settled_at already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- settled_by - MATCH TYPE WITH existing created_by column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settled_by'
);

-- Get the type of created_by to match it
SET @created_by_type = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'created_by'
);

SET @sql = IF(
    @col_exists = 0,
    CONCAT('ALTER TABLE t_req_master ADD COLUMN settled_by ', @created_by_type, ' NULL AFTER settled_at'),
    'SELECT ''settled_by already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- settlement_transaction_id - MATCH TYPE WITH ledger_transaction_id
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_transaction_id'
);

SET @ledger_tx_type = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'ledger_transaction_id'
);

SET @sql = IF(
    @col_exists = 0,
    CONCAT('ALTER TABLE t_req_master ADD COLUMN settlement_transaction_id ', @ledger_tx_type, ' NULL AFTER settled_by'),
    'SELECT ''settlement_transaction_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- settlement_destination_account_id - MATCH TYPE WITH payment_source_account_id
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_destination_account_id'
);

SET @account_id_type = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'payment_source_account_id'
);

SET @sql = IF(
    @col_exists = 0,
    CONCAT('ALTER TABLE t_req_master ADD COLUMN settlement_destination_account_id ', @account_id_type, ' NULL AFTER settlement_transaction_id'),
    'SELECT ''settlement_destination_account_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- settlement_notes
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
        ADD COLUMN settlement_notes TEXT NULL AFTER settlement_destination_account_id',
    'SELECT ''settlement_notes already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ All columns added' as Status;

-- =====================================================
-- STEP 3: ADD FOREIGN KEYS (type-safe)
-- =====================================================

SELECT '' as '';
SELECT 'Adding foreign keys...' as Status;

-- FK for settled_by -> t_sys_user.id (only if types match)
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settled_by'
);

SET @types_match = (
    SELECT COUNT(*) = 2
    FROM (
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'nizamifarms_db' 
        AND TABLE_NAME = 't_req_master' 
        AND COLUMN_NAME = 'settled_by'
        
        UNION ALL
        
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'nizamifarms_db' 
        AND TABLE_NAME = 't_sys_user' 
        AND COLUMN_NAME = 'id'
    ) AS types
    GROUP BY COLUMN_TYPE
    HAVING COUNT(*) = 2
);

SET @sql = IF(
    @fk_exists = 0 AND @types_match = 1,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settled_by 
        FOREIGN KEY (settled_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    IF(@types_match = 0,
        'SELECT ''⚠ Type mismatch: settled_by vs t_sys_user.id - FK not added'' as Status',
        'SELECT ''FK fk_req_master_settled_by already exists'' as Status'
    )
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

SET @types_match = (
    SELECT COUNT(*) = 2
    FROM (
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'nizamifarms_db' 
        AND TABLE_NAME = 't_req_master' 
        AND COLUMN_NAME = 'settlement_transaction_id'
        
        UNION ALL
        
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'nizamifarms_db' 
        AND TABLE_NAME = 't_fin_ledger' 
        AND COLUMN_NAME = 'id'
    ) AS types
    GROUP BY COLUMN_TYPE
    HAVING COUNT(*) = 2
);

SET @sql = IF(
    @fk_exists = 0 AND @types_match = 1,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settlement_transaction 
        FOREIGN KEY (settlement_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL',
    IF(@types_match = 0,
        'SELECT ''⚠ Type mismatch: settlement_transaction_id vs t_fin_ledger.id - FK not added'' as Status',
        'SELECT ''FK fk_req_master_settlement_transaction already exists'' as Status'
    )
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

SET @types_match = (
    SELECT COUNT(*) = 2
    FROM (
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'nizamifarms_db' 
        AND TABLE_NAME = 't_req_master' 
        AND COLUMN_NAME = 'settlement_destination_account_id'
        
        UNION ALL
        
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'nizamifarms_db' 
        AND TABLE_NAME = 't_fin_accounts' 
        AND COLUMN_NAME = 'id'
    ) AS types
    GROUP BY COLUMN_TYPE
    HAVING COUNT(*) = 2
);

SET @sql = IF(
    @fk_exists = 0 AND @types_match = 1,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settlement_destination 
        FOREIGN KEY (settlement_destination_account_id) REFERENCES t_fin_accounts(id) ON DELETE SET NULL',
    IF(@types_match = 0,
        'SELECT ''⚠ Type mismatch: settlement_destination_account_id vs t_fin_accounts.id - FK not added'' as Status',
        'SELECT ''FK fk_req_master_settlement_destination already exists'' as Status'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added FK: settlement_destination_account_id -> t_fin_accounts' as Status;

-- =====================================================
-- STEP 4: VERIFICATION
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFICATION' as Status;
SELECT '========================================' as '';

-- Show all new columns with their types
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
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
SELECT '✓ SETTLEMENT SUPPORT ADDED SUCCESSFULLY!' as Status;



