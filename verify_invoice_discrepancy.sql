-- ==============================================================================
-- DASHBOARD VERIFICATION QUERIES - USING DELIVERED ORDERS
-- The dashboard now uses DELIVERED ORDERS as the primary source
-- Data is grouped by DELIVERY DATE (when order was marked delivered)
-- ==============================================================================

-- ==============================================================================
-- MONTHLY GRAPHS DATA (what the dashboard shows)
-- ==============================================================================

-- 1. Monthly Delivered Orders Summary (Last 6 months)
-- This is exactly what the monthly graphs show
SELECT
    DATE_FORMAT(h.delivered_at, '%Y-%m') as month_key,
    DATE_FORMAT(h.delivered_at, '%b %Y') as month_name,
    COUNT(DISTINCT o.id) as delivered_orders,
    SUM(o.total_price) as total_revenue,
    COUNT(DISTINCT o.customer_id) as unique_customers,
    -- Shopify/Manual split
    SUM(CASE WHEN o.order_number LIKE 'SH%' OR o.order_number LIKE 'sh%' THEN o.total_price ELSE 0 END) as shopify_revenue,
    SUM(CASE WHEN o.order_number LIKE 'SH%' OR o.order_number LIKE 'sh%' THEN 1 ELSE 0 END) as shopify_orders,
    SUM(CASE WHEN o.order_number NOT LIKE 'SH%' AND o.order_number NOT LIKE 'sh%' THEN o.total_price ELSE 0 END) as manual_revenue,
    SUM(CASE WHEN o.order_number NOT LIKE 'SH%' AND o.order_number NOT LIKE 'sh%' THEN 1 ELSE 0 END) as manual_orders
FROM t_crm_prod_order o
INNER JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source IS NULL OR o.external_source != 'shopify')
  AND h.delivered_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(h.delivered_at, '%Y-%m'), DATE_FORMAT(h.delivered_at, '%b %Y')
ORDER BY month_key;

-- ==============================================================================
-- SINGLE MONTH VERIFICATION (for top cards)
-- ==============================================================================

-- 2. December 2025 Delivered Orders (change date as needed)
SELECT
    COUNT(DISTINCT o.id) as delivered_orders,
    SUM(o.total_price) as total_revenue,
    COUNT(DISTINCT o.customer_id) as unique_customers,
    -- Shopify/Manual
    SUM(CASE WHEN o.order_number LIKE 'SH%' THEN 1 ELSE 0 END) as shopify_orders,
    SUM(CASE WHEN o.order_number NOT LIKE 'SH%' THEN 1 ELSE 0 END) as manual_orders
FROM t_crm_prod_order o
INNER JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source IS NULL OR o.external_source != 'shopify')
  AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-12';

-- ==============================================================================
-- DAILY DATA (for Daily tab)
-- ==============================================================================

-- 3. Daily delivered orders for a specific month
SELECT
    DATE(h.delivered_at) as delivery_date,
    COUNT(DISTINCT o.id) as orders,
    SUM(o.total_price) as revenue,
    COUNT(DISTINCT o.customer_id) as customers
FROM t_crm_prod_order o
INNER JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
WHERE (o.external_source IS NULL OR o.external_source != 'shopify')
  AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-12'
GROUP BY DATE(h.delivered_at)
ORDER BY delivery_date;

-- ==============================================================================
-- TOP CARDS VERIFICATION (with payment mode from ledger)
-- ==============================================================================

-- 4. Top Cards Data - Using delivery date for invoices
SELECT
    COUNT(DISTINCT o.id) as invoice_count,
    SUM(o.total_price) as invoice_total,
    -- Online/Cash split from ledger
    SUM(CASE WHEN l.mode = 'online' THEN o.total_price ELSE 0 END) as online_total,
    SUM(CASE WHEN l.mode = 'cash' OR l.mode IS NULL THEN o.total_price ELSE 0 END) as cash_total
FROM t_crm_prod_order o
INNER JOIN (
    SELECT order_id, MIN(changed_at) as delivered_at 
    FROM t_crm_order_status_history 
    WHERE status_code = 'delivered' 
    GROUP BY order_id
) h ON o.id = h.order_id
LEFT JOIN t_fin_ledger l ON o.id = l.order_id 
    AND l.transaction_type = 'invoice' 
    AND l.approval_status != 'reversed'
WHERE (o.external_source IS NULL OR o.external_source != 'shopify')
  AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-12';

-- ==============================================================================
-- EXPENSES AND VENDOR PAYMENTS (still by transaction_date)
-- ==============================================================================

-- 5. Expenses for a month
SELECT 
    COUNT(*) as expense_count,
    SUM(amount) as expense_total
FROM t_fin_ledger
WHERE transaction_type = 'expense'
  AND approval_status = 'approved'
  AND DATE_FORMAT(transaction_date, '%Y-%m') = '2025-12';

-- 6. Vendor Payments for a month
SELECT 
    COUNT(*) as payment_count,
    SUM(amount) as payment_total
FROM t_fin_ledger
WHERE transaction_type = 'vendor_payment'
  AND approval_status = 'approved'
  AND DATE_FORMAT(transaction_date, '%Y-%m') = '2025-12';

-- ==============================================================================
-- PROFIT CALCULATION
-- Profit = Total Delivered Order Revenue - Expenses - Vendor Payments
-- ==============================================================================

-- 7. Monthly Profit Summary
SELECT
    'December 2025' as month,
    (SELECT SUM(o.total_price) 
     FROM t_crm_prod_order o
     INNER JOIN (SELECT order_id, MIN(changed_at) as delivered_at FROM t_crm_order_status_history WHERE status_code = 'delivered' GROUP BY order_id) h ON o.id = h.order_id
     WHERE (o.external_source IS NULL OR o.external_source != 'shopify')
       AND DATE_FORMAT(h.delivered_at, '%Y-%m') = '2025-12') as revenue,
    (SELECT SUM(amount) FROM t_fin_ledger WHERE transaction_type = 'expense' AND approval_status = 'approved' AND DATE_FORMAT(transaction_date, '%Y-%m') = '2025-12') as expenses,
    (SELECT SUM(amount) FROM t_fin_ledger WHERE transaction_type = 'vendor_payment' AND approval_status = 'approved' AND DATE_FORMAT(transaction_date, '%Y-%m') = '2025-12') as vendor_payments;
