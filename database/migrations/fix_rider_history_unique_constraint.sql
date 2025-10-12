-- Fix unique constraint on t_ops_order_rider_history
-- Problem: Current constraint doesn't allow multiple is_current=0 records
-- Solution: Use trigger-based approach that works on all MySQL versions

-- Step 1: Drop the problematic unique constraint
ALTER TABLE t_ops_order_rider_history 
DROP INDEX IF EXISTS uq_order_current_rider;

-- Step 2: Add a regular nullable column (not generated)
ALTER TABLE t_ops_order_rider_history
ADD COLUMN IF NOT EXISTS is_current_order_id INT NULL DEFAULT NULL
COMMENT 'Helper column for partial unique index - NULL for history, order_id for current';

-- Step 3: Create unique index on the helper column
-- This allows multiple NULL values (historical records) but only one non-NULL value per order (current assignment)
CREATE UNIQUE INDEX uq_order_current_rider 
ON t_ops_order_rider_history (is_current_order_id);

-- Step 4: Add index for common queries
CREATE INDEX IF NOT EXISTS idx_order_current 
ON t_ops_order_rider_history (order_id, is_current);

-- Step 5: Populate the helper column for existing records
UPDATE t_ops_order_rider_history
SET is_current_order_id = CASE WHEN is_current = 1 THEN order_id ELSE NULL END;

-- Step 6 & 7: Triggers (OPTIONAL - Skip if you don't have TRIGGER privileges)
-- If you can create triggers, uncomment these. Otherwise, the application code handles it.
/*
DROP TRIGGER IF EXISTS trg_rider_history_insert;
DELIMITER //
CREATE TRIGGER trg_rider_history_insert
BEFORE INSERT ON t_ops_order_rider_history
FOR EACH ROW
BEGIN
    IF NEW.is_current = 1 THEN
        SET NEW.is_current_order_id = NEW.order_id;
    ELSE
        SET NEW.is_current_order_id = NULL;
    END IF;
END//
DELIMITER ;

DROP TRIGGER IF EXISTS trg_rider_history_update;
DELIMITER //
CREATE TRIGGER trg_rider_history_update
BEFORE UPDATE ON t_ops_order_rider_history
FOR EACH ROW
BEGIN
    IF NEW.is_current = 1 THEN
        SET NEW.is_current_order_id = NEW.order_id;
    ELSE
        SET NEW.is_current_order_id = NULL;
    END IF;
END//
DELIMITER ;
*/

-- NOTE: Triggers are commented out because they're optional.
-- The application code in app/Models/CRM/OrderModel.php maintains the is_current_order_id column.
-- Triggers would provide extra safety for direct database updates, but they're not required.

-- Verification queries:
-- 1. Check that constraint works correctly:
-- SELECT order_id, rider_user_id, is_current, is_current_order_id 
-- FROM t_ops_order_rider_history 
-- WHERE order_id = 3276
-- ORDER BY assigned_at DESC;
--
-- Expected result:
-- - One record with is_current=1 and is_current_order_id=3276
-- - Multiple records with is_current=0 and is_current_order_id=NULL
--
-- 2. Verify triggers exist:
-- SHOW TRIGGERS LIKE 't_ops_order_rider_history';

