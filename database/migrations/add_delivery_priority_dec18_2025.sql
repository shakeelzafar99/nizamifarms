-- ============================================================================
-- MIGRATION: Add Delivery Priority to Orders
-- Date: December 18, 2025
-- Purpose: Allow store managers to set delivery sequence for riders
-- ============================================================================

-- STEP 1: Add delivery_priority column to orders table
-- Priority 1 = first delivery, 2 = second, etc.
-- NULL means no priority set (sort by date)
ALTER TABLE t_crm_prod_order 
ADD COLUMN delivery_priority INT NULL 
COMMENT 'Delivery sequence priority for rider (1=first, 2=second, etc). NULL = no priority set.';

-- STEP 2: Create index for efficient sorting by rider + status + priority
-- This index helps when fetching orders for a specific rider sorted by priority
CREATE INDEX idx_order_rider_priority 
ON t_crm_prod_order(assigned_rider_user_id, order_status, delivery_priority);

-- STEP 3: Verify the changes
SELECT 
    COLUMN_NAME, 
    DATA_TYPE, 
    IS_NULLABLE, 
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 't_crm_prod_order' 
AND COLUMN_NAME = 'delivery_priority';

-- Show index
SHOW INDEX FROM t_crm_prod_order WHERE Key_name = 'idx_order_rider_priority';

-- ============================================================================
-- NOTES:
-- - Priority only applies to 'out_for_delivery' orders in the UI
-- - Lower number = higher priority (delivered first)
-- - NULL priority orders sort after prioritized ones, by date
-- - Priority is cleared when order status changes or rider is reassigned
-- ============================================================================

