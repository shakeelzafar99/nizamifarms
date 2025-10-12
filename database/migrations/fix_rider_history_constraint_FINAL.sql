-- FINAL FIX: Drop and recreate the is_current_order_id column as a regular column
-- Run this in DEV to fix the generated column issue

-- Step 1: Drop the unique index (if exists)
ALTER TABLE t_ops_order_rider_history 
DROP INDEX IF EXISTS uq_order_current_rider;

-- Step 2: Drop the problematic GENERATED column completely
ALTER TABLE t_ops_order_rider_history
DROP COLUMN IF EXISTS is_current_order_id;

-- Step 3: Add it back as a regular nullable column (NOT GENERATED)
ALTER TABLE t_ops_order_rider_history
ADD COLUMN is_current_order_id INT NULL DEFAULT NULL
COMMENT 'Helper column for partial unique index - NULL for history, order_id for current';

-- Step 4: Create unique index on the helper column
-- This enforces uniqueness: only ONE record can have is_current_order_id = order_id (the current rider)
-- Multiple records can have is_current_order_id = NULL (historical riders)
CREATE UNIQUE INDEX uq_order_current_rider 
ON t_ops_order_rider_history (is_current_order_id);

-- Step 5: Add regular index for common queries
CREATE INDEX IF NOT EXISTS idx_order_current 
ON t_ops_order_rider_history (order_id, is_current);

-- Step 6: Populate the helper column for existing records
UPDATE t_ops_order_rider_history
SET is_current_order_id = CASE WHEN is_current = 1 THEN order_id ELSE NULL END;

-- Verification queries:
-- SELECT * FROM t_ops_order_rider_history WHERE order_id = 2596 ORDER BY assigned_at DESC;
-- SHOW CREATE TABLE t_ops_order_rider_history;



