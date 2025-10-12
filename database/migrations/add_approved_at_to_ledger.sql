-- =====================================================
-- Add approved_at and approval_notes to t_fin_ledger
-- =====================================================
-- Purpose: Fix approval functionality
-- =====================================================

-- Add approved_at column if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 't_fin_ledger'
      AND COLUMN_NAME = 'approved_at'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_fin_ledger ADD COLUMN approved_at TIMESTAMP NULL AFTER approval_date',
    'SELECT "approved_at column already exists" AS Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add approval_notes column if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 't_fin_ledger'
      AND COLUMN_NAME = 'approval_notes'
);

SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE t_fin_ledger ADD COLUMN approval_notes TEXT NULL AFTER approved_at',
    'SELECT "approval_notes column already exists" AS Status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT '✓ Added approved_at and approval_notes columns to t_fin_ledger' as Status;

-- Verify
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_ledger'
  AND COLUMN_NAME IN ('approved_at', 'approval_notes', 'approval_date', 'approved_by');

