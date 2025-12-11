-- ============================================================================
-- VERIFICATION QUERIES FOR DASHBOARD ANALYTICS (COMPLETE)
-- Run these after executing create_dashboard_analytics_views_dec10_2025.sql
-- Date: December 10, 2025
-- ============================================================================

-- ============================================================================
-- 1. VERIFY ALL VIEWS & FUNCTION EXIST
-- ============================================================================
SELECT 
    TABLE_NAME AS view_name,
    'VIEW' AS type,
    'EXISTS' AS status
FROM information_schema.VIEWS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'v_%'
ORDER BY TABLE_NAME;

-- Verify function exists
SELECT 
    'fn_normalize_phone' AS function_name,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END AS status
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
  AND ROUTINE_NAME = 'fn_normalize_phone'
  AND ROUTINE_TYPE = 'FUNCTION';

-- ============================================================================
-- 2. TEST PHONE NORMALIZATION FUNCTION
-- ============================================================================
SELECT 
    'Testing fn_normalize_phone()' AS test,
    fn_normalize_phone('+92-300-1234567') AS test1_should_be_3001234567,
    fn_normalize_phone('03001234567') AS test2_should_be_3001234567,
    fn_normalize_phone('00923001234567') AS test3_should_be_3001234567,
    fn_normalize_phone('300-123-4567') AS test4_should_be_3001234567;

-- ============================================================================
-- 3. ORDER SOURCE CLASSIFICATION TEST
-- Check how orders are being classified (Shopify Converted vs Manual)
-- ============================================================================
SELECT 
    order_source,
    data_source,
    COUNT(*) AS order_count,
    FORMAT(SUM(total_price), 0) AS total_revenue
FROM v_order_source_classification
GROUP BY order_source, data_source
ORDER BY data_source, order_source;

-- Sample of classified orders
SELECT * FROM v_order_source_classification
ORDER BY order_date DESC
LIMIT 20;

-- ============================================================================
-- 4. ORDER SOURCE SUMMARY BY MONTH
-- Shopify Converted vs Manual split by month
-- ============================================================================
SELECT 
    month_key,
    shopify_converted_count AS shopify_orders,
    manual_count AS manual_orders,
    total_count,
    CONCAT(shopify_percentage, '%') AS shopify_pct,
    CONCAT(manual_percentage, '%') AS manual_pct,
    FORMAT(shopify_revenue, 0) AS shopify_rev,
    FORMAT(manual_revenue, 0) AS manual_rev,
    FORMAT(total_revenue, 0) AS total_rev
FROM v_order_source_summary
ORDER BY month_key DESC
LIMIT 12;

-- ============================================================================
-- 5. MONTHLY ORDER SUMMARY (Last 12 months) with Source Split
-- ============================================================================
SELECT 
    month_name,
    order_count,
    unique_customers,
    shopify_converted_orders AS shopify,
    manual_orders AS manual,
    FORMAT(total_revenue, 0) AS total_rev,
    FORMAT(shopify_revenue, 0) AS shopify_rev,
    FORMAT(manual_revenue, 0) AS manual_rev,
    FORMAT(avg_order_value, 0) AS avg_order
FROM v_monthly_order_summary
ORDER BY month_key DESC
LIMIT 12;

-- ============================================================================
-- 6. DAILY SUMMARY FOR CURRENT MONTH with Source Split
-- ============================================================================
SELECT 
    order_day,
    day_name,
    order_count,
    shopify_converted_orders AS shopify,
    manual_orders AS manual,
    FORMAT(total_revenue, 0) AS revenue,
    FORMAT(shopify_revenue, 0) AS shop_rev,
    FORMAT(manual_revenue, 0) AS man_rev
FROM v_daily_order_summary
WHERE order_year = YEAR(CURDATE()) 
  AND order_month = MONTH(CURDATE())
ORDER BY order_day DESC;

-- ============================================================================
-- 7. PRODUCT CATEGORY BREAKDOWN (Current Month, Level 1) with Source Split
-- ============================================================================
SELECT 
    category_level_1,
    SUM(order_count) AS total_orders,
    SUM(shopify_orders) AS shopify,
    SUM(manual_orders) AS manual,
    FORMAT(SUM(total_quantity), 2) AS total_qty,
    FORMAT(SUM(total_revenue), 0) AS revenue,
    FORMAT(SUM(shopify_revenue), 0) AS shop_rev,
    FORMAT(SUM(manual_revenue), 0) AS man_rev
FROM v_product_category_summary
WHERE month_key = DATE_FORMAT(CURDATE(), '%Y-%m')
GROUP BY category_level_1
ORDER BY SUM(total_revenue) DESC;

-- ============================================================================
-- 8. CATEGORY LEVEL 2 DRILL-DOWN (Example: Top Category)
-- ============================================================================
SELECT 
    category_level_1,
    category_level_2,
    SUM(order_count) AS orders,
    SUM(shopify_orders) AS shopify,
    SUM(manual_orders) AS manual,
    FORMAT(SUM(total_quantity), 2) AS qty,
    FORMAT(SUM(total_revenue), 0) AS revenue
FROM v_product_category_summary
WHERE month_key = DATE_FORMAT(CURDATE(), '%Y-%m')
GROUP BY category_level_1, category_level_2
ORDER BY SUM(total_revenue) DESC
LIMIT 15;

-- ============================================================================
-- 9. CUSTOMER NEW VS RETURNING (Current Month)
-- ============================================================================
SELECT 
    customer_classification,
    COUNT(DISTINCT customer_id) AS customer_count,
    SUM(orders_this_month) AS total_orders,
    SUM(shopify_orders) AS shopify_orders,
    SUM(manual_orders) AS manual_orders,
    FORMAT(SUM(spend_this_month), 0) AS total_spend
FROM v_customer_monthly_classification
WHERE month_key = DATE_FORMAT(CURDATE(), '%Y-%m')
GROUP BY customer_classification;

-- ============================================================================
-- 10. FINANCIAL SUMMARY (Current Month)
-- ============================================================================
SELECT 
    month_name,
    FORMAT(invoice_total, 0) AS invoices,
    invoice_count,
    FORMAT(expense_total, 0) AS expenses,
    expense_count,
    FORMAT(vendor_payment_total, 0) AS vendor_payments,
    FORMAT(monthly_profit, 0) AS profit
FROM v_financial_monthly_summary
WHERE month_key = DATE_FORMAT(CURDATE(), '%Y-%m');

-- ============================================================================
-- 11. CUSTOMER SEGMENTS SUMMARY
-- ============================================================================
SELECT 
    activity_segment,
    COUNT(*) AS customer_count,
    FORMAT(SUM(total_spent), 0) AS total_lifetime_value,
    FORMAT(AVG(avg_order_value), 0) AS avg_order_value
FROM v_customer_activity_segments
GROUP BY activity_segment
ORDER BY FIELD(activity_segment, 'ACTIVE_7D', 'ACTIVE_30D', 'ACTIVE_60D', 'ACTIVE_90D', 'DORMANT_6M', 'CHURNED', 'NEVER_ORDERED');

-- ============================================================================
-- 12. BEST SELLING DAYS OF WEEK with Source Split
-- ============================================================================
SELECT 
    day_name,
    FORMAT(total_orders, 0) AS orders,
    shopify_orders,
    manual_orders,
    FORMAT(total_revenue, 0) AS revenue,
    FORMAT(avg_order_value, 0) AS avg_order
FROM v_weekly_day_performance
ORDER BY total_revenue DESC;

-- ============================================================================
-- 13. TOP CITIES BY REVENUE with Source Split
-- ============================================================================
SELECT 
    city,
    customer_count,
    order_count,
    shopify_orders,
    manual_orders,
    FORMAT(total_revenue, 0) AS revenue
FROM v_city_performance
ORDER BY total_revenue DESC
LIMIT 10;

-- ============================================================================
-- 14. NEW CUSTOMER PRODUCT PREFERENCES with Source Split
-- ============================================================================
SELECT 
    category_level_1,
    category_level_2,
    unique_new_customers,
    order_count,
    shopify_orders,
    manual_orders,
    FORMAT(total_quantity, 2) AS qty,
    FORMAT(total_revenue, 0) AS revenue
FROM v_new_customer_product_preferences
WHERE month_key = DATE_FORMAT(CURDATE(), '%Y-%m')
ORDER BY total_revenue DESC
LIMIT 10;

-- ============================================================================
-- 15. ACTIVE CUSTOMERS COUNT (90 Days)
-- ============================================================================
SELECT 
    COUNT(*) AS active_90d_customers
FROM v_customer_activity_segments
WHERE activity_segment IN ('ACTIVE_7D', 'ACTIVE_30D', 'ACTIVE_60D', 'ACTIVE_90D');

-- ============================================================================
-- 16. NEW CUSTOMERS THIS MONTH
-- ============================================================================
SELECT 
    COUNT(DISTINCT customer_id) AS new_customers_this_month
FROM v_customer_monthly_classification
WHERE month_key = DATE_FORMAT(CURDATE(), '%Y-%m')
  AND customer_classification = 'NEW';

-- ============================================================================
-- 17. MONTH-OVER-MONTH GROWTH
-- ============================================================================
SELECT 
    month_key,
    month_name,
    current_orders,
    previous_orders,
    CONCAT(COALESCE(order_growth_pct, 0), '%') AS order_growth,
    FORMAT(current_revenue, 0) AS current_rev,
    FORMAT(previous_revenue, 0) AS previous_rev,
    CONCAT(COALESCE(revenue_growth_pct, 0), '%') AS revenue_growth,
    current_shopify,
    current_manual
FROM v_month_over_month_growth
ORDER BY month_key DESC
LIMIT 12;

-- ============================================================================
-- 18. CUSTOMER COHORT ANALYSIS
-- ============================================================================
SELECT 
    cohort_month,
    cohort_size,
    active_30d,
    active_60d,
    active_90d,
    CONCAT(retention_rate_90d, '%') AS retention_90d,
    FORMAT(avg_lifetime_value, 0) AS avg_ltv
FROM v_customer_cohort
ORDER BY cohort_month DESC
LIMIT 12;

-- ============================================================================
-- 19. HOURLY ORDER DISTRIBUTION
-- ============================================================================
SELECT 
    order_hour,
    time_slot,
    order_count,
    shopify_orders,
    manual_orders,
    FORMAT(revenue, 0) AS revenue
FROM v_hourly_order_distribution
ORDER BY order_hour;

-- ============================================================================
-- 20. QUANTITY SUMMARY (Current Month)
-- ============================================================================
SELECT 
    SUM(total_quantity) AS total_quantity_this_month,
    SUM(shopify_quantity) AS shopify_qty,
    SUM(manual_quantity) AS manual_qty,
    SUM(order_count) AS total_orders,
    FORMAT(AVG(avg_qty_per_order), 2) AS avg_qty_per_order,
    FORMAT(AVG(revenue_per_unit), 0) AS avg_revenue_per_unit
FROM v_quantity_summary
WHERE month_key = DATE_FORMAT(CURDATE(), '%Y-%m');

-- ============================================================================
-- 21. TOP CARDS DATA QUERY (All-in-one for Dashboard Top Cards)
-- ============================================================================
SET @target_month = DATE_FORMAT(CURDATE(), '%Y-%m');

SELECT 
    -- Order Stats
    (SELECT SUM(order_count) FROM v_daily_order_summary WHERE DATE_FORMAT(order_day, '%Y-%m') = @target_month) AS total_orders,
    (SELECT SUM(shopify_converted_orders) FROM v_daily_order_summary WHERE DATE_FORMAT(order_day, '%Y-%m') = @target_month) AS shopify_orders,
    (SELECT SUM(manual_orders) FROM v_daily_order_summary WHERE DATE_FORMAT(order_day, '%Y-%m') = @target_month) AS manual_orders,
    (SELECT SUM(total_revenue) FROM v_daily_order_summary WHERE DATE_FORMAT(order_day, '%Y-%m') = @target_month) AS total_order_revenue,
    
    -- Financial Stats
    (SELECT invoice_total FROM v_financial_monthly_summary WHERE month_key = @target_month) AS invoice_total,
    (SELECT invoice_count FROM v_financial_monthly_summary WHERE month_key = @target_month) AS invoice_count,
    (SELECT expense_total FROM v_financial_monthly_summary WHERE month_key = @target_month) AS expense_total,
    (SELECT vendor_payment_total FROM v_financial_monthly_summary WHERE month_key = @target_month) AS vendor_payment_total,
    (SELECT monthly_profit FROM v_financial_monthly_summary WHERE month_key = @target_month) AS profit,
    
    -- Customer Stats
    (SELECT COUNT(*) FROM v_customer_activity_segments WHERE activity_segment IN ('ACTIVE_7D', 'ACTIVE_30D', 'ACTIVE_60D', 'ACTIVE_90D')) AS active_90d_customers,
    (SELECT COUNT(DISTINCT customer_id) FROM v_customer_monthly_classification WHERE month_key = @target_month AND customer_classification = 'NEW') AS new_customers_month;

-- ============================================================================
-- 22. DISCOUNT ANALYSIS
-- ============================================================================
SELECT 
    month_key,
    orders_with_discount,
    orders_without_discount,
    FORMAT(total_discount_amount, 0) AS discount_given,
    CONCAT(ROUND(discount_percentage, 1), '%') AS discount_pct,
    orders_with_coupon
FROM v_discount_analysis
WHERE month_key >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 6 MONTH), '%Y-%m')
ORDER BY month_key DESC;

-- ============================================================================
-- 23. PAYMENT METHOD ANALYSIS with Source Split
-- ============================================================================
SELECT 
    month_key,
    payment_method,
    order_count,
    shopify_orders,
    manual_orders,
    FORMAT(total_amount, 0) AS amount
FROM v_payment_method_analysis
WHERE month_key >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 3 MONTH), '%Y-%m')
ORDER BY month_key DESC, total_amount DESC;

-- ============================================================================
SELECT '✅ All verification queries completed!' AS status;
SELECT 'Views are ready for dashboard implementation!' AS next_step;
