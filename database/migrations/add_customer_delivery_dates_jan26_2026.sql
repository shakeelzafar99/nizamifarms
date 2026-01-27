-- ============================================================================
-- MIGRATION: Add Delivery Date Columns to Customer Table
-- Date: January 26, 2026
-- Purpose: Track first and last DELIVERY dates (not order dates) per customer
-- 
-- USES: v_crm_all_orders view which combines:
--   - Production orders (t_crm_prod_order) with delivered_at from status history
--   - History orders (t_crm_history_order) with delivered_at directly
-- 
-- EXCLUDES: reversed, cancelled orders
-- 
-- For shared hosting - NO TRIGGERS needed
-- Uses Laravel Observer instead (app/Observers/OrderStatusHistoryObserver.php)
-- ============================================================================

-- Step 1: Add new columns (run these one by one if needed)
ALTER TABLE t_crm_prod_customer 
ADD COLUMN first_delivery_date DATETIME NULL AFTER first_order_date;

ALTER TABLE t_crm_prod_customer 
ADD COLUMN last_delivery_date DATETIME NULL AFTER last_order_date;

ALTER TABLE t_crm_prod_customer 
ADD COLUMN last_delivered_order_id INT UNSIGNED NULL AFTER last_delivery_date;

-- Step 2: Add indexes for fast dashboard queries
CREATE INDEX idx_customer_first_delivery ON t_crm_prod_customer (first_delivery_date);
CREATE INDEX idx_customer_last_delivery ON t_crm_prod_customer (last_delivery_date);

-- ============================================================================
-- Step 3: Backfill using v_crm_all_orders view (Production + History combined)
-- This correctly handles:
--   - Production orders: delivered_at from status history
--   - History orders: delivered_at from the table column
--   - Excludes: cancelled, reversed orders
-- ============================================================================

-- First, let's verify the view exists and check sample data
SELECT 
    source_type,
    COUNT(*) as order_count,
    COUNT(delivered_at) as with_delivery_date,
    MIN(delivered_at) as earliest_delivery,
    MAX(delivered_at) as latest_delivery
FROM v_crm_all_orders
WHERE order_status NOT IN ('cancelled', 'reversed')
  AND delivered_at IS NOT NULL
GROUP BY source_type;

-- ============================================================================
-- Option A: Backfill ALL in one query (try this first)
-- If it times out, use Option B below
-- ============================================================================
UPDATE t_crm_prod_customer c
SET 
    first_delivery_date = (
        SELECT MIN(o.delivered_at)
        FROM v_crm_all_orders o
        WHERE o.customer_id = c.id
        AND o.delivered_at IS NOT NULL
        AND o.order_status NOT IN ('cancelled', 'reversed')
    ),
    last_delivery_date = (
        SELECT MAX(o.delivered_at)
        FROM v_crm_all_orders o
        WHERE o.customer_id = c.id
        AND o.delivered_at IS NOT NULL
        AND o.order_status NOT IN ('cancelled', 'reversed')
    )
WHERE c.merged_into_customer_id IS NULL;

-- Update last_delivered_order_id separately (needs subquery with ORDER BY)
UPDATE t_crm_prod_customer c
SET last_delivered_order_id = (
    SELECT o.id
    FROM v_crm_all_orders o
    WHERE o.customer_id = c.id
    AND o.delivered_at IS NOT NULL
    AND o.order_status NOT IN ('cancelled', 'reversed')
    AND o.source_type = 'production'  -- Only production orders have usable IDs
    ORDER BY o.delivered_at DESC
    LIMIT 1
)
WHERE c.merged_into_customer_id IS NULL
AND c.last_delivery_date IS NOT NULL;

-- ============================================================================
-- Option B: If Option A times out, run these separately
-- ============================================================================

-- B1: Update first_delivery_date only
/*
UPDATE t_crm_prod_customer c
SET first_delivery_date = (
    SELECT MIN(o.delivered_at)
    FROM v_crm_all_orders o
    WHERE o.customer_id = c.id
    AND o.delivered_at IS NOT NULL
    AND o.order_status NOT IN ('cancelled', 'reversed')
)
WHERE c.merged_into_customer_id IS NULL;
*/

-- B2: Update last_delivery_date only
/*
UPDATE t_crm_prod_customer c
SET last_delivery_date = (
    SELECT MAX(o.delivered_at)
    FROM v_crm_all_orders o
    WHERE o.customer_id = c.id
    AND o.delivered_at IS NOT NULL
    AND o.order_status NOT IN ('cancelled', 'reversed')
)
WHERE c.merged_into_customer_id IS NULL;
*/

-- B3: Update last_delivered_order_id only (production orders only)
/*
UPDATE t_crm_prod_customer c
SET last_delivered_order_id = (
    SELECT o.id
    FROM v_crm_all_orders o
    WHERE o.customer_id = c.id
    AND o.delivered_at IS NOT NULL
    AND o.order_status NOT IN ('cancelled', 'reversed')
    AND o.source_type = 'production'
    ORDER BY o.delivered_at DESC
    LIMIT 1
)
WHERE c.merged_into_customer_id IS NULL
AND c.last_delivery_date IS NOT NULL;
*/

-- ============================================================================
-- Option C: Batch update if Options A/B time out (process in batches)
-- ============================================================================

/*
-- Run multiple times, changing the ID range each time
-- Batch 1: IDs 1-1000
UPDATE t_crm_prod_customer c
SET 
    first_delivery_date = (
        SELECT MIN(o.delivered_at)
        FROM v_crm_all_orders o
        WHERE o.customer_id = c.id
        AND o.delivered_at IS NOT NULL
        AND o.order_status NOT IN ('cancelled', 'reversed')
    ),
    last_delivery_date = (
        SELECT MAX(o.delivered_at)
        FROM v_crm_all_orders o
        WHERE o.customer_id = c.id
        AND o.delivered_at IS NOT NULL
        AND o.order_status NOT IN ('cancelled', 'reversed')
    )
WHERE c.id BETWEEN 1 AND 1000
AND c.merged_into_customer_id IS NULL;

-- Batch 2: IDs 1001-2000
-- ... and so on
*/

-- ============================================================================
-- Step 4: Verification Queries
-- ============================================================================

-- Count summary
SELECT 
    COUNT(*) as total_customers,
    COUNT(first_delivery_date) as with_delivery,
    COUNT(*) - COUNT(first_delivery_date) as never_delivered,
    ROUND(COUNT(first_delivery_date) * 100.0 / COUNT(*), 1) as delivery_pct
FROM t_crm_prod_customer 
WHERE merged_into_customer_id IS NULL;

-- Check difference between order_date and delivery_date
SELECT 
    c.id,
    CONCAT(c.first_name, ' ', c.last_name) as name,
    c.first_order_date,
    c.first_delivery_date,
    DATEDIFF(c.first_delivery_date, c.first_order_date) as days_to_first_delivery,
    c.last_order_date,
    c.last_delivery_date,
    c.total_orders
FROM t_crm_prod_customer c
WHERE c.first_delivery_date IS NOT NULL
AND c.merged_into_customer_id IS NULL
ORDER BY c.last_delivery_date DESC
LIMIT 20;

-- Check for customers with history-only deliveries
-- (first_delivery_date should be set from history orders too)
SELECT 
    c.id,
    CONCAT(c.first_name, ' ', c.last_name) as name,
    c.first_delivery_date,
    c.last_delivery_date,
    (SELECT COUNT(*) FROM t_crm_prod_order o WHERE o.customer_id = c.id) as prod_orders,
    (SELECT COUNT(*) FROM t_crm_history_order h WHERE h.customer_id = c.id) as history_orders
FROM t_crm_prod_customer c
WHERE c.first_delivery_date IS NOT NULL
AND c.merged_into_customer_id IS NULL
ORDER BY c.first_delivery_date ASC
LIMIT 20;

-- Verify data from both sources
SELECT 
    'Production' as source,
    COUNT(DISTINCT customer_id) as customers_with_deliveries
FROM v_crm_all_orders 
WHERE source_type = 'production' 
AND delivered_at IS NOT NULL 
AND order_status NOT IN ('cancelled', 'reversed')

UNION ALL

SELECT 
    'History' as source,
    COUNT(DISTINCT customer_id) as customers_with_deliveries
FROM v_crm_all_orders 
WHERE source_type = 'history' 
AND delivered_at IS NOT NULL 
AND order_status NOT IN ('cancelled', 'reversed');

-- ============================================================================
-- Success!
-- ============================================================================
SELECT '✅ Customer delivery date tracking added successfully!' AS status;
SELECT 'Data sourced from: Production orders (via status history) + History orders (delivered_at column)' AS source;
SELECT 'Excluded: cancelled, reversed orders' AS exclusions;
SELECT 'Laravel Observer will auto-update delivery dates for new deliveries' AS note;
