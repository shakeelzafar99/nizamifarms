-- Check the column types for timestamp fields
-- This will verify if they're DATETIME or DATE

-- 1. Check t_req_master columns
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
  AND TABLE_NAME = 't_req_master'
  AND COLUMN_NAME IN ('submitted_at', 'completed_at', 'created_at', 'updated_at');

-- 2. Check t_fin_ledger columns
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
  AND TABLE_NAME = 't_fin_ledger'
  AND COLUMN_NAME IN ('transaction_date', 'approval_date', 'created_at', 'updated_at');

-- 3. Check t_fin_ledger_adjustments columns
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    COLUMN_TYPE,
    IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
  AND TABLE_NAME = 't_fin_ledger_adjustments'
  AND COLUMN_NAME IN ('requested_at', 'approved_at', 'created_at', 'updated_at');

