-- ============================================================================
-- DASHBOARD VERIFICATION QUERIES - December 2025
-- Run these queries in MySQL Workbench to verify dashboard KPIs
-- 
-- IMPORTANT: After changes, clear the dashboard cache by clicking 
--            "Clear Cache" button in the dashboard or running:
--            POST /dashboard/clear-cache
-- ============================================================================

-- Set the month to verify
SET @month_key = '2025-12';
SET @start_date = '2025-12-01';
SET @end_date = '2025-12-31 23:59:59';

-- ============================================================================
-- TOP CARDS SECTION (Overview)
-- ============================================================================

-- -----------------------------------------------------------------
-- KPI 1: INVOICES (ALL delivered invoices, excluding reversed)
-- -----------------------------------------------------------------
-- NEW dashboard logic: Uses t_fin_ledger with transaction_type='invoice' 
-- and approval_status != 'reversed' (includes approved, pending, pending_l1, pending_l2)
-- Split by mode (online/cash) and approval status
-- -----------------------------------------------------------------

-- Total invoices (ALL delivered, excluding reversed)
SELECT 
    'TOTAL INVOICES (All Delivered)' AS kpi_name,
    COUNT(*) AS invoice_count,
    SUM(amount) AS invoice_total
FROM t_fin_ledger 
WHERE transaction_type = 'invoice'
  AND approval_status != 'reversed'
  AND transaction_date BETWEEN @start_date AND @end_date;

-- Invoice breakdown by mode and approval status
SELECT 
    COALESCE(mode, 'unknown') AS payment_mode,
    approval_status,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM t_fin_ledger 
WHERE transaction_type = 'invoice'
  AND approval_status != 'reversed'
  AND transaction_date BETWEEN @start_date AND @end_date
GROUP BY COALESCE(mode, 'unknown'), approval_status
ORDER BY mode, approval_status;

-- Online invoices breakdown
SELECT 
    'ONLINE INVOICES' AS category,
    approval_status,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM t_fin_ledger 
WHERE transaction_type = 'invoice'
  AND mode = 'online'
  AND approval_status != 'reversed'
  AND transaction_date BETWEEN @start_date AND @end_date
GROUP BY approval_status;

-- Cash invoices breakdown
SELECT 
    'CASH INVOICES' AS category,
    approval_status,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM t_fin_ledger 
WHERE transaction_type = 'invoice'
  AND mode = 'cash'
  AND approval_status != 'reversed'
  AND transaction_date BETWEEN @start_date AND @end_date
GROUP BY approval_status;

-- -----------------------------------------------------------------
-- KPI 2: EXPENSES (Total amount and count of approved expenses)
-- -----------------------------------------------------------------
SELECT 
    'EXPENSES' AS kpi_name,
    COUNT(*) AS expense_count,
    SUM(amount) AS expense_total,
    'Expected in Dashboard: 215 expenses, PKR 757,900' AS expected
FROM t_fin_ledger 
WHERE transaction_type = 'expense'
  AND approval_status = 'approved'
  AND transaction_date BETWEEN @start_date AND @end_date;

-- Check all expense statuses
SELECT 
    approval_status,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM t_fin_ledger 
WHERE transaction_type = 'expense'
  AND transaction_date BETWEEN @start_date AND @end_date
GROUP BY approval_status;

-- -----------------------------------------------------------------
-- KPI 3: VENDOR PAYMENTS (Total amount and count)
-- -----------------------------------------------------------------
SELECT 
    'VENDOR PAYMENTS' AS kpi_name,
    COUNT(*) AS payment_count,
    SUM(amount) AS payment_total,
    'Expected in Dashboard: 9 payments, PKR 521,000' AS expected
FROM t_fin_ledger 
WHERE transaction_type = 'vendor_payment'
  AND approval_status = 'approved'
  AND transaction_date BETWEEN @start_date AND @end_date;

-- Check vendor payments by status
SELECT 
    approval_status,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM t_fin_ledger 
WHERE transaction_type = 'vendor_payment'
  AND transaction_date BETWEEN @start_date AND @end_date
GROUP BY approval_status;

-- -----------------------------------------------------------------
-- KPI 4: PROFIT (Invoices - Expenses - Vendor Payments)
-- Expected: PKR 1,731,265
-- -----------------------------------------------------------------
SELECT 
    'PROFIT CALCULATION' AS kpi_name,
    (SELECT COALESCE(SUM(amount), 0) FROM t_fin_ledger 
     WHERE transaction_type = 'invoice' 
       AND approval_status = 'approved'
       AND transaction_date BETWEEN @start_date AND @end_date) AS invoices,
    (SELECT COALESCE(SUM(amount), 0) FROM t_fin_ledger 
     WHERE transaction_type = 'expense' 
       AND approval_status = 'approved'
       AND transaction_date BETWEEN @start_date AND @end_date) AS expenses,
    (SELECT COALESCE(SUM(amount), 0) FROM t_fin_ledger 
     WHERE transaction_type = 'vendor_payment' 
       AND approval_status = 'approved'
       AND transaction_date BETWEEN @start_date AND @end_date) AS vendor_payments,
    (SELECT COALESCE(SUM(amount), 0) FROM t_fin_ledger 
     WHERE transaction_type = 'invoice' 
       AND approval_status = 'approved'
       AND transaction_date BETWEEN @start_date AND @end_date) -
    (SELECT COALESCE(SUM(amount), 0) FROM t_fin_ledger 
     WHERE transaction_type = 'expense' 
       AND approval_status = 'approved'
       AND transaction_date BETWEEN @start_date AND @end_date) -
    (SELECT COALESCE(SUM(amount), 0) FROM t_fin_ledger 
     WHERE transaction_type = 'vendor_payment' 
       AND approval_status = 'approved'
       AND transaction_date BETWEEN @start_date AND @end_date) AS calculated_profit,
    'Expected in Dashboard: PKR 1,731,265' AS expected;

-- -----------------------------------------------------------------
-- KPI 5: ACTIVE CUSTOMERS (90 days)
-- Expected: 947
-- -----------------------------------------------------------------
SELECT 
    'ACTIVE CUSTOMERS (90d)' AS kpi_name,
    COUNT(*) AS count,
    'Expected in Dashboard: 947' AS expected
FROM t_crm_prod_customer 
WHERE is_active = 1
  AND last_order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY);

-- -----------------------------------------------------------------
-- KPI 6: NEW CUSTOMERS THIS MONTH
-- Expected: +75 new
-- -----------------------------------------------------------------
SELECT 
    'NEW CUSTOMERS (Dec 2025)' AS kpi_name,
    COUNT(*) AS count,
    'Expected in Dashboard: +75 new' AS expected
FROM t_crm_prod_customer 
WHERE first_order_date BETWEEN @start_date AND @end_date;


-- ============================================================================
-- MONTHLY TRENDS SECTION (Last 6 Months)
-- ============================================================================

-- -----------------------------------------------------------------
-- MONTHLY ORDERS, REVENUE, CUSTOMERS
-- Note: Dashboard excludes external_source='shopify' 
-- and uses order_number LIKE 'SH%' for Shopify classification
-- IMPORTANT: Only counts delivered/completed/processing orders
-- -----------------------------------------------------------------

SELECT 
    DATE_FORMAT(order_date, '%Y-%m') AS month,
    -- Only count delivered orders
    SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS total_orders,
    COUNT(DISTINCT CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN customer_id END) AS unique_customers,
    SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN total_price ELSE 0 END) AS total_revenue,
    -- Shopify/Manual split - only delivered orders
    SUM(CASE WHEN order_number LIKE 'SH%' AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN (order_number NOT LIKE 'SH%' OR order_number IS NULL) AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS manual_orders,
    SUM(CASE WHEN order_number LIKE 'SH%' AND order_status IN ('delivered', 'completed', 'processing') THEN total_price ELSE 0 END) AS shopify_revenue,
    SUM(CASE WHEN (order_number NOT LIKE 'SH%' OR order_number IS NULL) AND order_status IN ('delivered', 'completed', 'processing') THEN total_price ELSE 0 END) AS manual_revenue,
    AVG(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN total_price ELSE NULL END) AS avg_order_value
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(order_date, '%Y-%m')
ORDER BY month DESC;

-- -----------------------------------------------------------------
-- VERIFY ORDER STATUS DISTRIBUTION
-- -----------------------------------------------------------------
SELECT 
    order_status,
    COUNT(*) AS count,
    SUM(total_price) AS total_amount
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date BETWEEN @start_date AND @end_date
GROUP BY order_status
ORDER BY count DESC;

-- -----------------------------------------------------------------
-- CHECK ORDER NUMBER PATTERNS (Shopify detection)
-- Only counts delivered/completed/processing orders
-- -----------------------------------------------------------------
SELECT 
    CASE 
        WHEN order_number LIKE 'SH%' THEN 'Shopify Converted (SH%)'
        ELSE 'Manual'
    END AS source,
    SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS order_count,
    SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN total_price ELSE 0 END) AS revenue
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date BETWEEN @start_date AND @end_date
GROUP BY CASE WHEN order_number LIKE 'SH%' THEN 'Shopify Converted (SH%)' ELSE 'Manual' END;


-- ============================================================================
-- COMPARISON: ORDERS vs INVOICES
-- This helps identify if invoice count should match delivered orders count
-- ============================================================================

SELECT 
    'DELIVERED ORDERS (Dec 2025)' AS metric,
    COUNT(*) AS count,
    SUM(total_price) AS amount
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_status = 'delivered'
  AND order_date BETWEEN @start_date AND @end_date

UNION ALL

SELECT 
    'APPROVED INVOICES (Dec 2025)' AS metric,
    COUNT(*) AS count,
    SUM(amount) AS amount
FROM t_fin_ledger 
WHERE transaction_type = 'invoice'
  AND approval_status = 'approved'
  AND transaction_date BETWEEN @start_date AND @end_date;

-- ============================================================================
-- DETAILED INVOICE BREAKDOWN
-- Check if there are invoices from different sources
-- ============================================================================

SELECT 
    COALESCE(external_source, 'manual') AS source,
    approval_status,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM t_fin_ledger 
WHERE transaction_type = 'invoice'
  AND transaction_date BETWEEN @start_date AND @end_date
GROUP BY COALESCE(external_source, 'manual'), approval_status
ORDER BY source, approval_status;

-- ============================================================================
-- CHECK v_financial_monthly_summary VIEW (if exists)
-- This is what the dashboard tries to use first
-- ============================================================================

SELECT * FROM v_financial_monthly_summary WHERE month_key = @month_key;

-- ============================================================================
-- GRAPH 1: REVENUE BY MONTH (Stacked Bar Chart)
-- Shows Manual Revenue + Shopify Revenue stacked
-- ============================================================================

SELECT 
    DATE_FORMAT(order_date, '%b %Y') AS month_name,
    DATE_FORMAT(order_date, '%Y-%m') AS month_key,
    -- Manual Revenue (orders NOT starting with SH%)
    SUM(CASE 
        WHEN (order_number NOT LIKE 'SH%' OR order_number IS NULL) 
         AND order_status IN ('delivered', 'completed', 'processing') 
        THEN total_price ELSE 0 
    END) AS manual_revenue,
    -- Shopify Revenue (orders starting with SH%)
    SUM(CASE 
        WHEN order_number LIKE 'SH%' 
         AND order_status IN ('delivered', 'completed', 'processing') 
        THEN total_price ELSE 0 
    END) AS shopify_revenue,
    -- Total Revenue
    SUM(CASE 
        WHEN order_status IN ('delivered', 'completed', 'processing') 
        THEN total_price ELSE 0 
    END) AS total_revenue
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(order_date, '%Y-%m'), DATE_FORMAT(order_date, '%b %Y')
ORDER BY month_key ASC;


-- ============================================================================
-- GRAPH 2: ORDERS BY MONTH (Stacked Bar Chart)
-- Shows Manual Orders + Shopify Orders stacked
-- ONLY delivered/completed/processing orders
-- ============================================================================

SELECT 
    DATE_FORMAT(order_date, '%b %Y') AS month_name,
    DATE_FORMAT(order_date, '%Y-%m') AS month_key,
    -- Manual Orders (delivered only)
    SUM(CASE WHEN (order_number NOT LIKE 'SH%' OR order_number IS NULL) AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS manual_orders,
    -- Shopify Orders (delivered only)
    SUM(CASE WHEN order_number LIKE 'SH%' AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS shopify_orders,
    -- Total Orders (delivered only)
    SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS total_orders
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(order_date, '%Y-%m'), DATE_FORMAT(order_date, '%b %Y')
ORDER BY month_key ASC;


-- ============================================================================
-- GRAPH 3: ORDER SOURCE SPLIT (Pie/Doughnut Chart)
-- Shows percentage of Shopify vs Manual orders
-- ONLY delivered/completed/processing orders
-- ============================================================================

SELECT 
    'ORDER SOURCE SPLIT (Last 6 Months, Delivered Only)' AS metric,
    SUM(CASE WHEN order_number LIKE 'SH%' AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS shopify_converted,
    SUM(CASE WHEN (order_number NOT LIKE 'SH%' OR order_number IS NULL) AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS manual,
    SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) AS total,
    ROUND(SUM(CASE WHEN order_number LIKE 'SH%' AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) * 100.0 / 
          NULLIF(SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END), 0), 2) AS shopify_pct,
    ROUND(SUM(CASE WHEN (order_number NOT LIKE 'SH%' OR order_number IS NULL) AND order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END) * 100.0 / 
          NULLIF(SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN 1 ELSE 0 END), 0), 2) AS manual_pct
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH);


-- ============================================================================
-- GRAPH 4: MONTH-OVER-MONTH GROWTH TABLE
-- Shows revenue growth percentage month over month
-- ============================================================================

WITH monthly_data AS (
    SELECT 
        DATE_FORMAT(order_date, '%Y-%m') AS month_key,
        DATE_FORMAT(order_date, '%b %Y') AS month_name,
        SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN total_price ELSE 0 END) AS revenue,
        COUNT(*) AS orders
    FROM t_crm_prod_order
    WHERE (external_source IS NULL OR external_source != 'shopify')
      AND order_date >= DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
    GROUP BY DATE_FORMAT(order_date, '%Y-%m'), DATE_FORMAT(order_date, '%b %Y')
)
SELECT 
    m1.month_name,
    m1.revenue AS current_revenue,
    m2.revenue AS previous_revenue,
    CASE 
        WHEN m2.revenue > 0 THEN ROUND((m1.revenue - m2.revenue) * 100.0 / m2.revenue, 1)
        ELSE 0 
    END AS revenue_growth_pct
FROM monthly_data m1
LEFT JOIN monthly_data m2 ON m2.month_key = DATE_FORMAT(
    DATE_SUB(STR_TO_DATE(CONCAT(m1.month_key, '-01'), '%Y-%m-%d'), INTERVAL 1 MONTH), 
    '%Y-%m'
)
ORDER BY m1.month_key DESC
LIMIT 6;


-- ============================================================================
-- DETAILED BREAKDOWN BY DAY (for December 2025)
-- Use this to spot check individual days
-- ============================================================================

SELECT 
    DATE(order_date) AS order_day,
    COUNT(*) AS total_orders,
    SUM(CASE WHEN order_status IN ('delivered', 'completed', 'processing') THEN total_price ELSE 0 END) AS revenue,
    SUM(CASE WHEN order_number LIKE 'SH%' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN order_number NOT LIKE 'SH%' OR order_number IS NULL THEN 1 ELSE 0 END) AS manual_orders
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date BETWEEN @start_date AND @end_date
GROUP BY DATE(order_date)
ORDER BY order_day DESC;


-- ============================================================================
-- CHECK FOR DATA QUALITY ISSUES
-- ============================================================================

-- Orders with NULL order_number (should be treated as manual)
SELECT 
    'Orders with NULL order_number' AS issue,
    COUNT(*) AS count
FROM t_crm_prod_order
WHERE order_number IS NULL
  AND (external_source IS NULL OR external_source != 'shopify')
  AND order_date BETWEEN @start_date AND @end_date;

-- Orders with unusual statuses
SELECT 
    order_status,
    COUNT(*) AS count,
    SUM(total_price) AS total_amount
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_date BETWEEN @start_date AND @end_date
  AND order_status NOT IN ('delivered', 'completed', 'processing', 'pending', 'cancelled')
GROUP BY order_status;


-- ============================================================================
-- VERIFY INVOICES vs ORDERS RELATIONSHIP
-- This helps understand if invoice count matches delivered order count
-- ============================================================================

-- Count of delivered orders that should have invoices
SELECT 
    'Delivered orders in Dec 2025' AS metric,
    COUNT(*) AS count,
    SUM(total_price) AS order_total
FROM t_crm_prod_order
WHERE (external_source IS NULL OR external_source != 'shopify')
  AND order_status = 'delivered'
  AND order_date BETWEEN @start_date AND @end_date;

-- Check how many of these orders have linked invoices
SELECT 
    'Orders with linked invoices' AS metric,
    COUNT(DISTINCT o.id) AS orders_with_invoices,
    COUNT(l.id) AS invoice_count,
    SUM(l.amount) AS invoice_total
FROM t_crm_prod_order o
INNER JOIN t_fin_ledger l ON l.order_id = o.id AND l.transaction_type = 'invoice'
WHERE (o.external_source IS NULL OR o.external_source != 'shopify')
  AND o.order_status = 'delivered'
  AND o.order_date BETWEEN @start_date AND @end_date;

-- Check for invoices WITHOUT a linked order (might explain discrepancy)
SELECT 
    'Invoices without order link' AS metric,
    COUNT(*) AS count,
    SUM(amount) AS total
FROM t_fin_ledger
WHERE transaction_type = 'invoice'
  AND approval_status = 'approved'
  AND order_id IS NULL
  AND transaction_date BETWEEN @start_date AND @end_date;

-- Check for invoices linked to orders from different months
SELECT 
    'Invoices from orders in different months' AS metric,
    COUNT(*) AS count
FROM t_fin_ledger l
INNER JOIN t_crm_prod_order o ON l.order_id = o.id
WHERE l.transaction_type = 'invoice'
  AND l.approval_status = 'approved'
  AND l.transaction_date BETWEEN @start_date AND @end_date
  AND (o.order_date < @start_date OR o.order_date > @end_date);


-- ============================================================================
-- FINAL SUMMARY
-- ============================================================================

SELECT '=== DASHBOARD VERIFICATION SUMMARY ===' AS header;
SELECT 'Run each section above to verify dashboard KPIs' AS instructions;
SELECT 'Compare results with dashboard values to identify discrepancies' AS note;
