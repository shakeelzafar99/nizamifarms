-- ============================================================================
-- VERIFY NOVEMBER 2025 ISSUE - Investigation Query
-- Run these queries to understand why November shows abnormally high numbers
-- ============================================================================

-- IMPORTANT: The current dashboard query uses:
-- - getMonthlyLedgerAnalytics: Delivered orders by delivery_date from order_status_history
-- - Payment method from ORDER's payment_method field (not ledger)

-- ============================================================================
-- STEP 1: Basic count verification
-- ============================================================================

-- 1a. Check raw delivered orders for November 2025 (CORRECT query)
SELECT 
    DATE_FORMAT(h.delivered_at, '%Y-%m') as month,
    COUNT(DISTINCT o.id) as order_count,
    SUM(o.total_price) as total_revenue,
    COUNT(DISTINCT o.customer_id) as unique_customers
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-11'
GROUP BY DATE_FORMAT(h.delivered_at, '%Y-%m');

-- 2. Check if there are duplicate entries in order_status_history
SELECT 
    order_id,
    COUNT(*) as delivered_count,
    MIN(changed_at) as first_delivered,
    MAX(changed_at) as last_delivered
FROM t_crm_order_status_history
WHERE status_code = 'delivered'
GROUP BY order_id
HAVING COUNT(*) > 1
LIMIT 20;

-- 3. Compare with other months to see the pattern
SELECT 
    DATE_FORMAT(h.delivered_at, '%Y-%m') as month,
    COUNT(DISTINCT o.id) as order_count,
    SUM(o.total_price) as total_revenue,
    AVG(o.total_price) as avg_order_value
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
AND h.delivered_at >= '2025-07-01'
GROUP BY DATE_FORMAT(h.delivered_at, '%Y-%m')
ORDER BY month;

-- 4. Check November orders by payment method (from ORDER table, not ledger)
SELECT 
    o.payment_method,
    COUNT(DISTINCT o.id) as order_count,
    SUM(o.total_price) as total_revenue
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-11'
GROUP BY o.payment_method;

-- 5. Check top orders in November (maybe there's a large outlier)
SELECT 
    o.id,
    o.order_number,
    o.total_price,
    o.payment_method,
    o.order_date,
    h.delivered_at,
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
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-11'
ORDER BY o.total_price DESC
LIMIT 20;

-- 6. Check if the join is creating duplicates due to status history
SELECT 
    COUNT(*) as total_join_results,
    COUNT(DISTINCT o.id) as unique_orders,
    SUM(o.total_price) as sum_with_duplicates,
    (SELECT SUM(total_price) FROM t_crm_prod_order WHERE id IN (
        SELECT DISTINCT o2.id 
        FROM t_crm_prod_order o2
        JOIN t_crm_order_status_history h2 ON o2.id = h2.order_id
        WHERE h2.status_code = 'delivered'
        AND (o2.external_source != 'shopify' OR o2.external_source IS NULL)
        AND DATE_FORMAT(h2.changed_at, '%Y-%m') = '2025-11'
    )) as sum_unique_orders
FROM t_crm_prod_order o
JOIN t_crm_order_status_history h ON o.id = h.order_id
WHERE h.status_code = 'delivered'
AND (o.external_source != 'shopify' OR o.external_source IS NULL)
AND DATE_FORMAT(h.changed_at, '%Y-%m') = '2025-11';

-- 7. Correct Query - Only count unique orders
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

-- 8. Check if there's an issue with the external_source filter
SELECT 
    o.external_source,
    COUNT(*) as order_count,
    SUM(o.total_price) as total_revenue
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-11'
GROUP BY o.external_source;

-- ============================================================================
-- PRODUCT CATEGORY JOIN VERIFICATION
-- ============================================================================

-- 9. Check how line items link to products - via product_id vs via variant
SELECT 
    COUNT(*) as total_line_items,
    SUM(CASE WHEN li.product_id IS NOT NULL THEN 1 ELSE 0 END) as with_product_id,
    SUM(CASE WHEN li.variant_id IS NOT NULL THEN 1 ELSE 0 END) as with_variant_id,
    SUM(CASE WHEN li.sku IS NOT NULL AND li.sku != '' THEN 1 ELSE 0 END) as with_sku
FROM t_crm_prod_order_line_item li;

-- 10. Check product category via variant -> product
SELECT 
    COALESCE(p.attribute_1, 'Uncategorized') as category,
    COUNT(*) as line_count,
    SUM(li.quantity) as total_qty,
    SUM(li.line_total) as total_revenue
FROM t_crm_prod_order_line_item li
LEFT JOIN t_crm_prod_product_variant v ON li.variant_id = v.id
LEFT JOIN t_crm_prod_product p ON v.product_id = p.id
GROUP BY COALESCE(p.attribute_1, 'Uncategorized')
ORDER BY total_revenue DESC
LIMIT 20;

-- 11. Check product category via SKU matching
SELECT 
    COALESCE(p.attribute_1, 'Uncategorized') as category,
    COUNT(*) as line_count,
    SUM(li.quantity) as total_qty,
    SUM(li.line_total) as total_revenue
FROM t_crm_prod_order_line_item li
LEFT JOIN t_crm_prod_product_variant v ON li.sku = v.sku
LEFT JOIN t_crm_prod_product p ON v.product_id = p.id
GROUP BY COALESCE(p.attribute_1, 'Uncategorized')
ORDER BY total_revenue DESC
LIMIT 20;

-- ============================================================================
-- DASHBOARD QUERY (exact query used in getMonthlyLedgerAnalytics)
-- ============================================================================

-- 12. Full Dashboard Query - Monthly Analytics
-- This is the EXACT query the dashboard uses
SELECT 
    DATE_FORMAT(h.delivered_at, '%Y-%m') as month_key,
    DATE_FORMAT(h.delivered_at, '%b %Y') as month_name,
    -- Delivered order totals
    SUM(o.total_price) as invoice_total,
    COUNT(DISTINCT o.id) as invoice_count,
    -- Unique customers
    COUNT(DISTINCT o.customer_id) as unique_customers,
    -- Online/Cash split from ORDER's payment_method field
    SUM(CASE WHEN o.payment_method IN ('online', 'Online', 'ONLINE', 'card', 'Card') THEN o.total_price ELSE 0 END) as online_total,
    SUM(CASE WHEN o.payment_method IN ('online', 'Online', 'ONLINE', 'card', 'Card') THEN 1 ELSE 0 END) as online_count,
    SUM(CASE WHEN o.payment_method NOT IN ('online', 'Online', 'ONLINE', 'card', 'Card') OR o.payment_method IS NULL THEN o.total_price ELSE 0 END) as cash_total,
    SUM(CASE WHEN o.payment_method NOT IN ('online', 'Online', 'ONLINE', 'card', 'Card') OR o.payment_method IS NULL THEN 1 ELSE 0 END) as cash_count,
    -- Shopify/Manual split (based on order_number)
    SUM(CASE WHEN o.order_number LIKE 'SH%' OR o.order_number LIKE 'sh%' THEN o.total_price ELSE 0 END) as shopify_total,
    SUM(CASE WHEN o.order_number LIKE 'SH%' OR o.order_number LIKE 'sh%' THEN 1 ELSE 0 END) as shopify_count,
    SUM(CASE WHEN o.order_number NOT LIKE 'SH%' AND o.order_number NOT LIKE 'sh%' THEN o.total_price ELSE 0 END) as manual_total,
    SUM(CASE WHEN o.order_number NOT LIKE 'SH%' AND o.order_number NOT LIKE 'sh%' THEN 1 ELSE 0 END) as manual_count,
    -- New vs Returning customers (based on first_order_date in same month as delivery)
    SUM(CASE WHEN DATE_FORMAT(c.first_order_date, '%Y-%m') = DATE_FORMAT(h.delivered_at, '%Y-%m') THEN o.total_price ELSE 0 END) as new_customer_revenue,
    SUM(CASE WHEN DATE_FORMAT(c.first_order_date, '%Y-%m') = DATE_FORMAT(h.delivered_at, '%Y-%m') THEN 1 ELSE 0 END) as new_customer_orders,
    SUM(CASE WHEN DATE_FORMAT(c.first_order_date, '%Y-%m') != DATE_FORMAT(h.delivered_at, '%Y-%m') THEN o.total_price ELSE 0 END) as returning_customer_revenue,
    SUM(CASE WHEN DATE_FORMAT(c.first_order_date, '%Y-%m') != DATE_FORMAT(h.delivered_at, '%Y-%m') THEN 1 ELSE 0 END) as returning_customer_orders
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
LEFT JOIN t_crm_prod_customer c ON o.customer_id = c.id
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
AND h.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY DATE_FORMAT(h.delivered_at, '%Y-%m'), DATE_FORMAT(h.delivered_at, '%b %Y')
ORDER BY month_key;

-- 13. November breakdown by day
SELECT 
    DATE(h.delivered_at) as delivery_date,
    COUNT(DISTINCT o.id) as order_count,
    SUM(o.total_price) as total_revenue
FROM t_crm_prod_order o
JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-11'
GROUP BY DATE(h.delivered_at)
ORDER BY delivery_date;

-- 14. Check if external_source filter is correct
SELECT 
    external_source,
    COUNT(*) as order_count
FROM t_crm_prod_order
WHERE id IN (
    SELECT order_id FROM t_crm_order_status_history WHERE status_code = 'delivered'
)
GROUP BY external_source;
