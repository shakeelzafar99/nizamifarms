-- ============================================================
-- Fix rider assignment history desync caused by auto-assign
-- 
-- Problem: autoAssignRiders() was updating t_crm_prod_order.assigned_rider_user_id
-- without updating t_ops_order_rider_history, causing a desync where the order
-- shows one rider but history shows another as "current".
--
-- This script reconciles all open orders where the order table rider
-- does not match the history table's is_current=1 rider.
-- ============================================================

-- Step 1: Identify desynced orders (preview - run SELECT first to see what will be fixed)
SELECT 
    o.id AS order_id,
    o.order_number,
    o.assigned_rider_user_id AS order_table_rider,
    u_order.fullname AS order_table_rider_name,
    h.rider_user_id AS history_current_rider,
    u_hist.fullname AS history_current_rider_name,
    o.order_status
FROM t_crm_prod_order o
LEFT JOIN t_ops_order_rider_history h 
    ON h.order_id = o.id AND h.is_current = 1
LEFT JOIN t_sys_user u_order ON u_order.id = o.assigned_rider_user_id
LEFT JOIN t_sys_user u_hist ON u_hist.id = h.rider_user_id
WHERE o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
  AND o.assigned_rider_user_id IS NOT NULL
  AND (
      -- Case A: Order has a rider but history has a DIFFERENT rider marked current
      (h.rider_user_id IS NOT NULL AND h.rider_user_id != o.assigned_rider_user_id)
      OR
      -- Case B: Order has a rider but NO history entry exists at all
      h.id IS NULL
  );

-- Step 2: Fix Case A - Demote stale history entries where a different rider is marked current
-- (the order table has the correct/latest rider from auto-assign)
UPDATE t_ops_order_rider_history h
INNER JOIN t_crm_prod_order o ON o.id = h.order_id
SET h.is_current = 0,
    h.unassigned_at = NOW()
WHERE h.is_current = 1
  AND o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
  AND o.assigned_rider_user_id IS NOT NULL
  AND h.rider_user_id != o.assigned_rider_user_id;

-- Step 3: Insert correct history entries for the rider currently in the order table
-- This covers both Case A (after demoting) and Case B (no history at all)
INSERT INTO t_ops_order_rider_history (order_id, rider_user_id, is_current, assigned_at, assigned_by, source, notes, created_at)
SELECT 
    o.id,
    o.assigned_rider_user_id,
    1,
    NOW(),
    NULL,
    'api',
    'Reconciled: syncing history with order table (auto-assign fix)',
    NOW()
FROM t_crm_prod_order o
LEFT JOIN t_ops_order_rider_history h 
    ON h.order_id = o.id AND h.is_current = 1
WHERE o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
  AND o.assigned_rider_user_id IS NOT NULL
  AND h.id IS NULL;

-- Step 4: Verify - after running, this should return 0 rows
SELECT 
    o.id AS order_id,
    o.order_number,
    o.assigned_rider_user_id AS order_table_rider,
    h.rider_user_id AS history_current_rider
FROM t_crm_prod_order o
LEFT JOIN t_ops_order_rider_history h 
    ON h.order_id = o.id AND h.is_current = 1
WHERE o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
  AND o.assigned_rider_user_id IS NOT NULL
  AND (
      (h.rider_user_id IS NOT NULL AND h.rider_user_id != o.assigned_rider_user_id)
      OR h.id IS NULL
  );
