-- ============================================================================
-- FIX LEGACY DELIVERY DATES
-- Orders where delivery was marked much later than actual delivery
-- If delivery_date > order_date + 20 days, set delivery_date = order_date
-- ============================================================================

-- STEP 1: Preview affected records (DO NOT RUN UPDATE YET)
SELECT 
    o.id as order_id,
    o.order_number,
    o.order_date,
    h.delivered_at as current_delivery_date,
    DATEDIFF(h.delivered_at, o.order_date) as days_difference,
    o.total_price,
    o.customer_id,
    c.first_name,
    c.last_name
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
LEFT JOIN t_crm_prod_customer c ON o.customer_id = c.id
WHERE DATEDIFF(h.delivered_at, o.order_date) > 20
ORDER BY h.delivered_at DESC;

-- STEP 2: Count affected records
SELECT 
    COUNT(*) as total_affected_orders,
    SUM(o.total_price) as total_revenue_affected,
    DATE_FORMAT(h.delivered_at, '%Y-%m') as recorded_delivery_month
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE DATEDIFF(h.delivered_at, o.order_date) > 20
GROUP BY DATE_FORMAT(h.delivered_at, '%Y-%m')
ORDER BY recorded_delivery_month;

-- STEP 3: Monthly impact breakdown
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') as actual_order_month,
    DATE_FORMAT(h.delivered_at, '%Y-%m') as recorded_delivery_month,
    COUNT(*) as order_count,
    SUM(o.total_price) as total_revenue
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE DATEDIFF(h.delivered_at, o.order_date) > 20
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), DATE_FORMAT(h.delivered_at, '%Y-%m')
ORDER BY recorded_delivery_month, actual_order_month;

-- ============================================================================
-- STEP 4: UPDATE SCRIPT (RUN ONLY AFTER REVIEWING ABOVE)
-- This updates the delivery date in order_status_history to match order_date
-- ============================================================================

-- Create backup first
CREATE TABLE IF NOT EXISTS t_crm_order_status_history_backup_delivery_fix AS
SELECT * FROM t_crm_order_status_history WHERE 1=0;

-- Insert backup records
INSERT INTO t_crm_order_status_history_backup_delivery_fix
SELECT osh.* 
FROM t_crm_order_status_history osh
JOIN t_crm_prod_order o ON osh.order_id = o.id
WHERE osh.status_code = 'delivered'
AND DATEDIFF(osh.changed_at, o.order_date) > 20;

-- Verify backup
SELECT COUNT(*) as backup_count FROM t_crm_order_status_history_backup_delivery_fix;

-- ============================================================================
-- ACTUAL UPDATE - Updates delivery timestamp to order_date + 1 day at noon
-- (Adding 1 day at noon to simulate "delivered next day afternoon")
-- ============================================================================

UPDATE t_crm_order_status_history osh
JOIN t_crm_prod_order o ON osh.order_id = o.id
SET osh.changed_at = DATE_ADD(o.order_date, INTERVAL 1 DAY) + INTERVAL 12 HOUR
WHERE osh.status_code = 'delivered'
AND DATEDIFF(osh.changed_at, o.order_date) > 20;

-- Verify update
SELECT 
    DATE_FORMAT(h.delivered_at, '%Y-%m') as month,
    COUNT(*) as order_count,
    SUM(o.total_price) as total_revenue
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
GROUP BY DATE_FORMAT(h.delivered_at, '%Y-%m')
ORDER BY month;

-- ============================================================================
-- ROLLBACK SCRIPT (If needed)
-- ============================================================================

-- To rollback, run:
-- UPDATE t_crm_order_status_history osh
-- JOIN t_crm_order_status_history_backup_delivery_fix b ON osh.id = b.id
-- SET osh.changed_at = b.changed_at;
