-- =====================================================================
-- Add Settlement Metadata to Ledger - Fix Cross-Session Settlement
-- =====================================================================
-- Problem: Settlement data stored in session is lost when a different
--          manager approves the deposit (different session)
-- Solution: Store settlement metadata directly in the ledger table
-- Date: 2025-10-14
-- =====================================================================

USE nizamifarms_db;

-- Add column to store settlement metadata (invoice IDs, amounts, etc.)
ALTER TABLE t_fin_ledger
ADD COLUMN settlement_metadata JSON NULL 
COMMENT 'Stores settlement details: invoice_ids, amounts, etc. for employee deposits' 
AFTER comments;

SELECT '✓ Added settlement_metadata column to t_fin_ledger' as Status;

-- Note: Index on JSON column not supported in MariaDB, skipping
-- (We query by deposit ID directly, not by JSON contents)

-- Verification
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
  AND TABLE_NAME = 't_fin_ledger'
  AND COLUMN_NAME = 'settlement_metadata';


