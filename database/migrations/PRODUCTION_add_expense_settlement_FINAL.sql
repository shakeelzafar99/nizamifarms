-- =====================================================
-- PRODUCTION: ADD EXPENSE SETTLEMENT SUPPORT
-- =====================================================
-- Database: napp_db-3735f1cb
-- Purpose: Add settlement tracking for expenses paid 
--          from non-Expense-Fund sources
-- Safe: Checks existing structure, no data loss
-- =====================================================

USE `napp_db-3735f1cb`;

SELECT '========================================' as '';
SELECT '🚀 EXPENSE SETTLEMENT - PRODUCTION INSTALL' as Status;
SELECT '========================================' as '';

-- =====================================================
-- STEP 1: PRE-FLIGHT CHECKS
-- =====================================================

SELECT 'Step 1: Pre-flight checks...' as Status;

-- Verify required tables exist
SET @tables_ok = (
    SELECT COUNT(*) = 4
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME IN ('t_req_master', 't_fin_ledger', 't_fin_accounts', 't_sys_user')
);

-- Verify required columns exist
SET @columns_ok = (
    SELECT COUNT(*) = 3
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME IN ('payment_source_account_id', 'ledger_transaction_id', 'expense_category')
);

SELECT 
    CASE 
        WHEN @tables_ok = 1 AND @columns_ok = 1 THEN '✓ READY TO PROCEED'
        WHEN @tables_ok = 0 THEN '✗ ERROR: Required tables missing!'
        WHEN @columns_ok = 0 THEN '✗ ERROR: Required columns missing! Run prerequisite migrations first.'
        ELSE '✗ ERROR: Unknown issue'
    END as 'Pre-flight Check';

-- =====================================================
-- STEP 2: ADD SETTLEMENT COLUMNS
-- =====================================================

SELECT '' as '';
SELECT 'Step 2: Adding settlement columns...' as Status;

-- 1. settlement_status
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_status'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_status ENUM(''not_required'', ''pending'', ''settled'') DEFAULT ''not_required'' 
        COMMENT ''Settlement status for expenses paid from non-Expense-Fund sources'' 
        AFTER payment_source_account_id,
        ADD INDEX idx_settlement_status (settlement_status)',
    'SELECT ''✓ settlement_status already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. settled_at
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settled_at'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settled_at TIMESTAMP NULL 
        COMMENT ''When the settlement was completed'' 
        AFTER settlement_status,
        ADD INDEX idx_settled_at (settled_at)',
    'SELECT ''✓ settled_at already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. settled_by (match created_by type: int(11))
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settled_by'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settled_by INT(11) NULL 
        COMMENT ''User who performed the settlement'' 
        AFTER settled_at',
    'SELECT ''✓ settled_by already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. settlement_transaction_id (match ledger_transaction_id type: int(11))
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_transaction_id'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_transaction_id INT(11) NULL 
        COMMENT ''Ledger transaction ID for the settlement transfer'' 
        AFTER settled_by',
    'SELECT ''✓ settlement_transaction_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. settlement_destination_account_id (match payment_source_account_id type: int(11))
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_destination_account_id'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_destination_account_id INT(11) NULL 
        COMMENT ''Account that received the settlement (e.g., NF Main Till)'' 
        AFTER settlement_transaction_id',
    'SELECT ''✓ settlement_destination_account_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. settlement_notes
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
    AND TABLE_NAME = 't_req_master' 
    AND COLUMN_NAME = 'settlement_notes'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_req_master 
        ADD COLUMN settlement_notes TEXT NULL 
        COMMENT ''Notes added during settlement'' 
        AFTER settlement_destination_account_id',
    'SELECT ''✓ settlement_notes already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ All settlement columns added' as Status;

-- =====================================================
-- STEP 3: ADD FOREIGN KEYS (if not exist)
-- =====================================================

SELECT '' as '';
SELECT 'Step 3: Adding foreign keys...' as Status;

-- FK: settled_by -> t_sys_user.id
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settled_by'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settled_by 
        FOREIGN KEY (settled_by) REFERENCES t_sys_user(id) ON DELETE SET NULL',
    'SELECT ''✓ FK settled_by already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FK: settlement_transaction_id -> t_fin_ledger.id
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settlement_transaction'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settlement_transaction 
        FOREIGN KEY (settlement_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL',
    'SELECT ''✓ FK settlement_transaction_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- FK: settlement_destination_account_id -> t_fin_accounts.id
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'napp_db-3735f1cb'
    AND TABLE_NAME = 't_req_master'
    AND CONSTRAINT_NAME = 'fk_req_master_settlement_destination'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE t_req_master 
        ADD CONSTRAINT fk_req_master_settlement_destination 
        FOREIGN KEY (settlement_destination_account_id) REFERENCES t_fin_accounts(id) ON DELETE SET NULL',
    'SELECT ''✓ FK settlement_destination_account_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ All foreign keys added' as Status;

-- =====================================================
-- STEP 4: VERIFICATION
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT 'VERIFICATION' as Status;
SELECT '========================================' as '';

-- Show all settlement columns
SELECT 
    COLUMN_NAME as 'Column',
    COLUMN_TYPE as 'Type',
    IS_NULLABLE as 'Nullable',
    COLUMN_DEFAULT as 'Default',
    COLUMN_COMMENT as 'Comment'
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
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

-- Show all settlement foreign keys
SELECT 
    CONSTRAINT_NAME as 'FK Constraint',
    COLUMN_NAME as 'Column',
    REFERENCED_TABLE_NAME as 'References Table',
    REFERENCED_COLUMN_NAME as 'References Column'
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = 'napp_db-3735f1cb' 
AND TABLE_NAME = 't_req_master' 
AND CONSTRAINT_NAME LIKE 'fk_req_master_settle%'
ORDER BY CONSTRAINT_NAME;

-- =====================================================
-- FINAL STATUS
-- =====================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✅ INSTALLATION COMPLETE!' as Status;
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'Next Steps:' as '';
SELECT '1. Update RequestModel $fillable array' as Action;
SELECT '2. Add relationships to RequestModel' as Action;
SELECT '3. Add TYPE_SETTLEMENT to LedgerModel' as Action;
SELECT '4. Update EmployeeCashController KPI calculations' as Action;
SELECT '5. Create ExpenseSettlementService' as Action;
SELECT '6. Create ExpenseManagementController & UI' as Action;
SELECT '' as '';
SELECT '📝 See EXPENSE_SETTLEMENT_IMPLEMENTATION_PLAN.md for details' as Note;



