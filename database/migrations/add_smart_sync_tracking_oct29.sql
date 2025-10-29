-- =====================================================================
-- SMART SYNC TRACKING - Database Migration
-- =====================================================================
-- Date: October 29, 2025
-- Purpose: Add sync tracking columns for mobile app smart sync feature
-- 
-- What this does:
-- 1. Tracks when orders are assigned to riders (sync_required flag)
-- 2. Tracks when rider app last fetched the order (last_sync_at)
-- 3. Tracks when requests status changes (sync_required flag)
-- 4. Tracks when requester app last fetched the request (last_sync_at)
--
-- NO BREAKING CHANGES - All columns are nullable/default values
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- STEP 1: Add sync tracking to ORDERS table
-- =====================================================================

-- Check if columns already exist
SELECT 'Checking t_crm_prod_order table...' as Status;

-- Add rider_sync_required flag
-- When an order is assigned to a rider, this is set to TRUE
-- When rider app fetches orders, this is set to FALSE
ALTER TABLE t_crm_prod_order
ADD COLUMN IF NOT EXISTS rider_sync_required BOOLEAN DEFAULT FALSE 
COMMENT 'Flag: TRUE when order assigned/updated, FALSE when rider app synced';

-- Add rider_last_sync_at timestamp
-- Records when the rider app last fetched this specific order
-- Used to show "Synced 2 mins ago" in webapp
ALTER TABLE t_crm_prod_order
ADD COLUMN IF NOT EXISTS rider_last_sync_at TIMESTAMP NULL
COMMENT 'Timestamp when rider app last fetched this order';

SELECT 'âœ" Added sync columns to t_crm_prod_order' as Status;

-- =====================================================================
-- STEP 2: Add sync tracking to REQUESTS table
-- =====================================================================

-- Check if columns already exist
SELECT 'Checking t_req_master table...' as Status;

-- Add requester_sync_required flag
-- When a request status changes (approved/rejected), this is set to TRUE
-- When requester app fetches requests, this is set to FALSE
ALTER TABLE t_req_master
ADD COLUMN IF NOT EXISTS requester_sync_required BOOLEAN DEFAULT FALSE
COMMENT 'Flag: TRUE when request status changed, FALSE when requester app synced';

-- Add requester_last_sync_at timestamp
-- Records when the requester app last fetched this specific request
-- Used to show "Synced 1 min ago" in webapp
ALTER TABLE t_req_master
ADD COLUMN IF NOT EXISTS requester_last_sync_at TIMESTAMP NULL
COMMENT 'Timestamp when requester app last fetched this request';

SELECT 'âœ" Added sync columns to t_req_master' as Status;

-- =====================================================================
-- STEP 3: Create indexes for better performance
-- =====================================================================

-- Index on rider_sync_required for fast filtering
-- Used when webapp queries "pending sync" orders
CREATE INDEX IF NOT EXISTS idx_rider_sync_required 
ON t_crm_prod_order(rider_sync_required, assigned_rider_user_id);

-- Index on requester_sync_required for fast filtering
-- Used when webapp queries "pending sync" requests
CREATE INDEX IF NOT EXISTS idx_requester_sync_required
ON t_req_master(requester_sync_required, requester_user_id);

SELECT 'âœ" Created performance indexes' as Status;

-- =====================================================================
-- STEP 4: Verification
-- =====================================================================

-- Verify orders table structure
SELECT 
    'ORDERS TABLE' as Table_Name,
    COLUMN_NAME as Column_Name,
    DATA_TYPE as Data_Type,
    COLUMN_DEFAULT as Default_Value,
    IS_NULLABLE as Nullable,
    COLUMN_COMMENT as Comment
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
  AND TABLE_NAME = 't_crm_prod_order'
  AND COLUMN_NAME IN ('rider_sync_required', 'rider_last_sync_at')
ORDER BY ORDINAL_POSITION;

-- Verify requests table structure
SELECT 
    'REQUESTS TABLE' as Table_Name,
    COLUMN_NAME as Column_Name,
    DATA_TYPE as Data_Type,
    COLUMN_DEFAULT as Default_Value,
    IS_NULLABLE as Nullable,
    COLUMN_COMMENT as Comment
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
  AND TABLE_NAME = 't_req_master'
  AND COLUMN_NAME IN ('requester_sync_required', 'requester_last_sync_at')
ORDER BY ORDINAL_POSITION;

-- =====================================================================
-- STEP 5: Migration Complete
-- =====================================================================

SELECT 'âœ… MIGRATION COMPLETE!' as Status;
SELECT 'Next steps:' as Info;
SELECT '1. Test that existing orders/requests still work' as Step_1;
SELECT '2. Proceed with mobile app enhancement' as Step_2;
SELECT '3. Proceed with backend enhancement' as Step_3;
SELECT '4. Proceed with webapp enhancement' as Step_4;

-- =====================================================================
-- ROLLBACK SCRIPT (if needed)
-- =====================================================================
/*
-- Uncomment and run if you need to rollback:

ALTER TABLE t_crm_prod_order 
DROP COLUMN rider_sync_required,
DROP COLUMN rider_last_sync_at;

ALTER TABLE t_req_master
DROP COLUMN requester_sync_required,
DROP COLUMN requester_last_sync_at;

DROP INDEX idx_rider_sync_required ON t_crm_prod_order;
DROP INDEX idx_requester_sync_required ON t_req_master;

SELECT 'Rollback complete' as Status;
*/

