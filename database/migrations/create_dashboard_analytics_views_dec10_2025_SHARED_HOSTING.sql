-- ============================================================================
-- MIGRATION: Dashboard Analytics Views (SHARED HOSTING COMPATIBLE)
-- Date: December 10, 2025
-- Version: SHARED HOSTING SAFE - No Functions, No Triggers, Compatible Indexes
-- 
-- COMPATIBILITY:
--   ✅ No stored functions (inline SQL instead)
--   ✅ No triggers
--   ✅ No DELIMITER commands
--   ✅ Compatible CREATE INDEX syntax
--   ✅ Standard MySQL 5.7+ compatible
--
-- LEVERAGES EXISTING VIEWS:
--   - v_crm_all_orders (production + history orders)
--   - v_crm_all_order_line_items (production + history line items)
--   - v_crm_customer_order_summary (customer summary)
--
-- RUN THIS IN: phpMyAdmin, MySQL Workbench, or any SQL client
-- ============================================================================

-- ============================================================================
-- SECTION 1: ORDER SOURCE CLASSIFICATION VIEW
-- Determines if an order is:
--   - 'shopify_converted' (order_number starts with 'SH' OR has matching Shopify order)
--   - 'manual' (direct order without Shopify origin)
-- 
-- NOTE: Phone normalization is done inline using RIGHT() and REGEXP_REPLACE
-- For MySQL 5.7 (no REGEXP_REPLACE), use simpler logic
-- ============================================================================
DROP VIEW IF EXISTS `v_order_source_classification`;

CREATE VIEW `v_order_source_classification` AS
-- Production Orders Classification
SELECT 
    'production' AS data_source,
    o.id AS order_id,
    o.order_number,
    o.order_date,
    o.customer_id,
    o.address_phone,
    -- Phone normalization: Take last 10 digits
    -- Using REPLACE to remove common non-digit chars, then RIGHT to get last 10
    RIGHT(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            COALESCE(o.address_phone, ''),
            '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''),
        10
    ) AS phone_normalized,
    o.total_price,
    o.order_status,
    
    -- Classification Logic for Production:
    -- 1. If order_number starts with 'SH' or 'sh' = shopify_converted
    -- 2. If external_source = 'woocommerce' and there's a matching Shopify order within 3 days = shopify_converted
    -- 3. Otherwise = manual
    CASE 
        WHEN UPPER(LEFT(o.order_number, 2)) = 'SH' THEN 'shopify_converted'
        WHEN o.external_source = 'woocommerce' AND EXISTS (
            SELECT 1 FROM t_crm_shopify_order so
            WHERE RIGHT(
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(so.address_phone, ''),
                        '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''),
                    10
                  ) = RIGHT(
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(o.address_phone, ''),
                        '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''),
                    10
                  )
              AND ABS(DATEDIFF(so.order_date, o.order_date)) <= 3
        ) THEN 'shopify_converted'
        ELSE 'manual'
    END AS order_source
    
FROM t_crm_prod_order o
WHERE (o.external_source IS NULL OR o.external_source != 'shopify')

UNION ALL

-- History Orders Classification
SELECT 
    'history' AS data_source,
    h.id AS order_id,
    h.order_number,
    h.order_date,
    h.customer_id,
    h.address_phone,
    RIGHT(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            COALESCE(h.address_phone, ''),
            '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''),
        10
    ) AS phone_normalized,
    h.total_price,
    h.order_status,
    
    -- Classification Logic for History:
    CASE 
        WHEN UPPER(LEFT(h.order_number, 2)) = 'SH' THEN 'shopify_converted'
        WHEN EXISTS (
            SELECT 1 FROM t_crm_shopify_order so
            WHERE RIGHT(
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(so.address_phone, ''),
                        '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''),
                    10
                  ) = RIGHT(
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        COALESCE(h.address_phone, ''),
                        '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''),
                    10
                  )
              AND ABS(DATEDIFF(so.order_date, h.order_date)) <= 3
        ) THEN 'shopify_converted'
        ELSE 'manual'
    END AS order_source
    
FROM t_crm_history_order h
WHERE (h.external_source IS NULL OR h.external_source != 'shopify');

-- ============================================================================
-- SECTION 2: PRODUCT SKU LOOKUP VIEW
-- Maps SKUs to product categories (attribute_1, attribute_2, attribute_3)
-- ============================================================================
DROP VIEW IF EXISTS `v_product_sku_lookup`;

CREATE VIEW `v_product_sku_lookup` AS
SELECT 
    pv.sku,
    pv.id AS variant_id,
    p.id AS product_id,
    p.title AS product_name,
    p.vendor AS product_vendor,
    p.product_type,
    COALESCE(p.attribute_1, 'Uncategorized') AS category_level_1,
    COALESCE(p.attribute_2, 'Uncategorized') AS category_level_2,
    COALESCE(p.attribute_3, 'Uncategorized') AS category_level_3,
    p.is_lean,
    p.weight_factor,
    pv.price AS variant_price,
    pv.cost_price
FROM t_crm_prod_product_variant pv
JOIN t_crm_prod_product p ON pv.product_id = p.id
WHERE p.is_active = 1;

-- ============================================================================
-- SECTION 3: ORDER LINE ITEMS WITH CATEGORIES
-- ============================================================================
DROP VIEW IF EXISTS `v_order_line_item_categories`;

CREATE VIEW `v_order_line_item_categories` AS
SELECT 
    li.source_type,
    li.id AS line_item_id,
    li.order_id,
    li.sku,
    li.name AS item_name,
    li.quantity,
    li.unit_price,
    li.line_total,
    COALESCE(pl.category_level_1, 'Uncategorized') AS category_level_1,
    COALESCE(pl.category_level_2, 'Uncategorized') AS category_level_2,
    COALESCE(pl.category_level_3, 'Uncategorized') AS category_level_3,
    COALESCE(pl.product_name, li.name) AS product_name,
    pl.is_lean,
    pl.weight_factor
FROM v_crm_all_order_line_items li
LEFT JOIN v_product_sku_lookup pl ON li.sku = pl.sku;

-- ============================================================================
-- SECTION 4: DAILY ORDER SUMMARY WITH SOURCE SPLIT
-- ============================================================================
DROP VIEW IF EXISTS `v_daily_order_summary`;

CREATE VIEW `v_daily_order_summary` AS
SELECT 
    DATE(o.order_date) AS order_day,
    YEAR(o.order_date) AS order_year,
    MONTH(o.order_date) AS order_month,
    DAYOFWEEK(o.order_date) AS day_of_week,
    DAYNAME(o.order_date) AS day_name,
    
    -- Order counts - Total
    COUNT(DISTINCT o.id) AS order_count,
    COUNT(DISTINCT o.customer_id) AS unique_customers,
    
    -- Order counts by Source (Shopify Converted vs Manual)
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_converted_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders,
    
    -- Unique customers by Source
    COUNT(DISTINCT CASE WHEN osc.order_source = 'shopify_converted' THEN o.customer_id END) AS shopify_unique_customers,
    COUNT(DISTINCT CASE WHEN osc.order_source = 'manual' THEN o.customer_id END) AS manual_unique_customers,
    
    -- Financial metrics - Total
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.discount_total ELSE 0 END) AS total_discounts,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.shipping_total ELSE 0 END) AS total_shipping,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.tip_amount ELSE 0 END) AS total_tips,
    
    -- Revenue by Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS shopify_revenue,
    SUM(CASE WHEN osc.order_source = 'manual' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS manual_revenue,
    
    -- Status breakdown
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed') THEN 1 ELSE 0 END) AS delivered_orders,
    SUM(CASE WHEN o.order_status = 'processing' THEN 1 ELSE 0 END) AS processing_orders,
    SUM(CASE WHEN o.order_status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
    SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
    
    -- Payment method breakdown
    SUM(CASE WHEN o.payment_method IN ('cash', 'cash_on_delivery') THEN 1 ELSE 0 END) AS cash_orders,
    SUM(CASE WHEN o.payment_method IN ('online', 'bank_transfer', 'card') THEN 1 ELSE 0 END) AS online_orders,
    
    -- Average metrics
    AVG(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE NULL END) AS avg_order_value
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY DATE(o.order_date), YEAR(o.order_date), MONTH(o.order_date), 
         DAYOFWEEK(o.order_date), DAYNAME(o.order_date);

-- ============================================================================
-- SECTION 5: MONTHLY ORDER SUMMARY WITH SOURCE SPLIT
-- ============================================================================
DROP VIEW IF EXISTS `v_monthly_order_summary`;

CREATE VIEW `v_monthly_order_summary` AS
SELECT 
    YEAR(o.order_date) AS order_year,
    MONTH(o.order_date) AS order_month,
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    DATE_FORMAT(o.order_date, '%b %Y') AS month_name,
    
    -- Order counts - Total
    COUNT(DISTINCT o.id) AS order_count,
    COUNT(DISTINCT o.customer_id) AS unique_customers,
    
    -- Order counts by Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_converted_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders,
    
    -- Revenue - Total
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.discount_total ELSE 0 END) AS total_discounts,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.tip_amount ELSE 0 END) AS total_tips,
    
    -- Revenue by Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS shopify_revenue,
    SUM(CASE WHEN osc.order_source = 'manual' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS manual_revenue,
    
    -- Status breakdown
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed') THEN 1 ELSE 0 END) AS delivered_orders,
    SUM(CASE WHEN o.order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
    
    -- Average metrics
    AVG(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE NULL END) AS avg_order_value,
    
    -- Working days analysis
    COUNT(DISTINCT DATE(o.order_date)) AS working_days_with_orders
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY YEAR(o.order_date), MONTH(o.order_date), 
         DATE_FORMAT(o.order_date, '%Y-%m'), DATE_FORMAT(o.order_date, '%b %Y');

-- ============================================================================
-- SECTION 6: CUSTOMER MONTHLY CLASSIFICATION VIEW
-- ============================================================================
DROP VIEW IF EXISTS `v_customer_monthly_classification`;

CREATE VIEW `v_customer_monthly_classification` AS
SELECT 
    YEAR(o.order_date) AS order_year,
    MONTH(o.order_date) AS order_month,
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    o.customer_id,
    c.first_name,
    c.last_name,
    c.first_order_date,
    
    -- Customer Classification
    CASE 
        WHEN DATE_FORMAT(c.first_order_date, '%Y-%m') = DATE_FORMAT(o.order_date, '%Y-%m') THEN 'NEW'
        ELSE 'RETURNING'
    END AS customer_classification,
    
    -- Order Source breakdown for this customer this month
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders,
    
    -- Totals
    COUNT(DISTINCT o.id) AS orders_this_month,
    SUM(o.total_price) AS spend_this_month
    
FROM v_crm_all_orders o
JOIN t_crm_prod_customer c ON o.customer_id = c.id
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY YEAR(o.order_date), MONTH(o.order_date), DATE_FORMAT(o.order_date, '%Y-%m'),
         o.customer_id, c.first_name, c.last_name, c.first_order_date;

-- ============================================================================
-- SECTION 7: PRODUCT CATEGORY SUMMARY WITH SOURCE SPLIT
-- ============================================================================
DROP VIEW IF EXISTS `v_product_category_summary`;

CREATE VIEW `v_product_category_summary` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    lic.category_level_1,
    lic.category_level_2,
    lic.category_level_3,
    
    -- Order metrics - Total
    COUNT(DISTINCT o.id) AS order_count,
    COUNT(DISTINCT o.customer_id) AS unique_customers,
    
    -- Order metrics by Source
    COUNT(DISTINCT CASE WHEN osc.order_source = 'shopify_converted' THEN o.id END) AS shopify_orders,
    COUNT(DISTINCT CASE WHEN osc.order_source = 'manual' THEN o.id END) AS manual_orders,
    
    -- Quantity metrics
    SUM(lic.quantity) AS total_quantity,
    
    -- Revenue metrics - Total
    SUM(lic.line_total) AS total_revenue,
    AVG(lic.unit_price) AS avg_unit_price,
    
    -- Revenue by Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN lic.line_total ELSE 0 END) AS shopify_revenue,
    SUM(CASE WHEN osc.order_source = 'manual' THEN lic.line_total ELSE 0 END) AS manual_revenue
    
FROM v_crm_all_orders o
JOIN v_order_line_item_categories lic ON o.id = lic.order_id AND o.source_type = lic.source_type
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), 
         lic.category_level_1, lic.category_level_2, lic.category_level_3;

-- ============================================================================
-- SECTION 8: FINANCIAL DAILY SUMMARY
-- ============================================================================
DROP VIEW IF EXISTS `v_financial_daily_summary`;

CREATE VIEW `v_financial_daily_summary` AS
SELECT 
    DATE(l.transaction_date) AS txn_date,
    YEAR(l.transaction_date) AS txn_year,
    MONTH(l.transaction_date) AS txn_month,
    DATE_FORMAT(l.transaction_date, '%Y-%m') AS month_key,
    
    -- Invoice totals (only approved)
    SUM(CASE WHEN l.transaction_type = 'invoice' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) AS invoice_total,
    COUNT(CASE WHEN l.transaction_type = 'invoice' AND l.approval_status = 'approved' THEN 1 END) AS invoice_count,
    
    -- Expense totals (only approved)
    SUM(CASE WHEN l.transaction_type = 'expense' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) AS expense_total,
    COUNT(CASE WHEN l.transaction_type = 'expense' AND l.approval_status = 'approved' THEN 1 END) AS expense_count,
    
    -- Vendor payment totals (only approved)
    SUM(CASE WHEN l.transaction_type = 'vendor_payment' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) AS vendor_payment_total,
    COUNT(CASE WHEN l.transaction_type = 'vendor_payment' AND l.approval_status = 'approved' THEN 1 END) AS vendor_payment_count,
    
    -- Vendor purchase totals (only approved)
    SUM(CASE WHEN l.transaction_type = 'vendor_purchase' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) AS vendor_purchase_total,
    COUNT(CASE WHEN l.transaction_type = 'vendor_purchase' AND l.approval_status = 'approved' THEN 1 END) AS vendor_purchase_count,
    
    -- Employee deposits
    SUM(CASE WHEN l.transaction_type = 'employee_deposit' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) AS deposit_total,
    COUNT(CASE WHEN l.transaction_type = 'employee_deposit' AND l.approval_status = 'approved' THEN 1 END) AS deposit_count,
    
    -- Calculated profit (Invoices - Expenses - Vendor Payments)
    SUM(CASE WHEN l.transaction_type = 'invoice' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) -
    SUM(CASE WHEN l.transaction_type = 'expense' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) -
    SUM(CASE WHEN l.transaction_type = 'vendor_payment' AND l.approval_status = 'approved' THEN l.amount ELSE 0 END) AS daily_profit
    
FROM t_fin_ledger l
GROUP BY DATE(l.transaction_date), YEAR(l.transaction_date), 
         MONTH(l.transaction_date), DATE_FORMAT(l.transaction_date, '%Y-%m');

-- ============================================================================
-- SECTION 9: MONTHLY FINANCIAL SUMMARY
-- ============================================================================
DROP VIEW IF EXISTS `v_financial_monthly_summary`;

CREATE VIEW `v_financial_monthly_summary` AS
SELECT 
    txn_year,
    txn_month,
    month_key,
    DATE_FORMAT(CONCAT(month_key, '-01'), '%b %Y') AS month_name,
    
    SUM(invoice_total) AS invoice_total,
    SUM(invoice_count) AS invoice_count,
    SUM(expense_total) AS expense_total,
    SUM(expense_count) AS expense_count,
    SUM(vendor_payment_total) AS vendor_payment_total,
    SUM(vendor_payment_count) AS vendor_payment_count,
    SUM(vendor_purchase_total) AS vendor_purchase_total,
    SUM(vendor_purchase_count) AS vendor_purchase_count,
    SUM(deposit_total) AS deposit_total,
    SUM(deposit_count) AS deposit_count,
    SUM(daily_profit) AS monthly_profit
    
FROM v_financial_daily_summary
GROUP BY txn_year, txn_month, month_key;

-- ============================================================================
-- SECTION 10: NEW CUSTOMER PRODUCT PREFERENCES
-- ============================================================================
DROP VIEW IF EXISTS `v_new_customer_product_preferences`;

CREATE VIEW `v_new_customer_product_preferences` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    lic.category_level_1,
    lic.category_level_2,
    
    COUNT(DISTINCT o.id) AS order_count,
    COUNT(DISTINCT o.customer_id) AS unique_new_customers,
    SUM(lic.quantity) AS total_quantity,
    SUM(lic.line_total) AS total_revenue,
    
    -- By Source
    COUNT(DISTINCT CASE WHEN osc.order_source = 'shopify_converted' THEN o.id END) AS shopify_orders,
    COUNT(DISTINCT CASE WHEN osc.order_source = 'manual' THEN o.id END) AS manual_orders
    
FROM v_crm_all_orders o
JOIN v_order_line_item_categories lic ON o.id = lic.order_id AND o.source_type = lic.source_type
JOIN t_crm_prod_customer c ON o.customer_id = c.id
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
  AND DATE_FORMAT(c.first_order_date, '%Y-%m') = DATE_FORMAT(o.order_date, '%Y-%m')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), 
         lic.category_level_1, lic.category_level_2;

-- ============================================================================
-- SECTION 11: CUSTOMER ACTIVITY SEGMENTS
-- ============================================================================
DROP VIEW IF EXISTS `v_customer_activity_segments`;

CREATE VIEW `v_customer_activity_segments` AS
SELECT 
    c.id AS customer_id,
    c.first_name,
    c.last_name,
    c.phone_normalized,
    c.first_order_date,
    c.last_order_date,
    c.total_orders,
    c.total_spent,
    
    DATEDIFF(CURDATE(), c.last_order_date) AS days_since_last_order,
    
    CASE 
        WHEN c.last_order_date IS NULL THEN 'NEVER_ORDERED'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 7 THEN 'ACTIVE_7D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 30 THEN 'ACTIVE_30D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 60 THEN 'ACTIVE_60D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 90 THEN 'ACTIVE_90D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 180 THEN 'DORMANT_6M'
        ELSE 'CHURNED'
    END AS activity_segment,
    
    CASE 
        WHEN c.total_orders = 0 THEN 'NEVER'
        WHEN c.total_orders = 1 THEN 'ONE_TIME'
        WHEN c.total_orders BETWEEN 2 AND 5 THEN 'OCCASIONAL'
        WHEN c.total_orders BETWEEN 6 AND 15 THEN 'REGULAR'
        ELSE 'LOYAL'
    END AS frequency_segment,
    
    CASE 
        WHEN c.total_spent <= 0 THEN 'ZERO'
        WHEN c.total_spent < 5000 THEN 'LOW'
        WHEN c.total_spent < 25000 THEN 'MEDIUM'
        WHEN c.total_spent < 100000 THEN 'HIGH'
        ELSE 'VIP'
    END AS value_segment,
    
    CASE WHEN c.total_orders > 0 THEN c.total_spent / c.total_orders ELSE 0 END AS avg_order_value
    
FROM t_crm_prod_customer c
WHERE c.is_active = 1;

-- ============================================================================
-- SECTION 12: WEEKLY DAY ANALYSIS
-- ============================================================================
DROP VIEW IF EXISTS `v_weekly_day_performance`;

CREATE VIEW `v_weekly_day_performance` AS
SELECT 
    DAYOFWEEK(o.order_date) AS day_of_week,
    DAYNAME(o.order_date) AS day_name,
    
    COUNT(DISTINCT o.id) AS total_orders,
    COUNT(DISTINCT o.customer_id) AS total_customers,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    AVG(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE NULL END) AS avg_order_value,
    
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders,
    
    COUNT(DISTINCT DATE(o.order_date)) AS days_count
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY DAYOFWEEK(o.order_date), DAYNAME(o.order_date)
ORDER BY DAYOFWEEK(o.order_date);

-- ============================================================================
-- SECTION 13: HOURLY ORDER DISTRIBUTION
-- ============================================================================
DROP VIEW IF EXISTS `v_hourly_order_distribution`;

CREATE VIEW `v_hourly_order_distribution` AS
SELECT 
    HOUR(o.order_date) AS order_hour,
    CASE 
        WHEN HOUR(o.order_date) < 6 THEN 'Early Morning (12AM-6AM)'
        WHEN HOUR(o.order_date) < 12 THEN 'Morning (6AM-12PM)'
        WHEN HOUR(o.order_date) < 17 THEN 'Afternoon (12PM-5PM)'
        WHEN HOUR(o.order_date) < 21 THEN 'Evening (5PM-9PM)'
        ELSE 'Night (9PM-12AM)'
    END AS time_slot,
    
    COUNT(DISTINCT o.id) AS order_count,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS revenue,
    
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY HOUR(o.order_date)
ORDER BY HOUR(o.order_date);

-- ============================================================================
-- SECTION 14: CITY-WISE PERFORMANCE
-- ============================================================================
DROP VIEW IF EXISTS `v_city_performance`;

CREATE VIEW `v_city_performance` AS
SELECT 
    COALESCE(c.city, o.address_city, 'Unknown') AS city,
    
    COUNT(DISTINCT o.id) AS order_count,
    COUNT(DISTINCT o.customer_id) AS customer_count,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    AVG(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE NULL END) AS avg_order_value,
    
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders,
    
    SUM(CASE 
        WHEN DATE_FORMAT(c.first_order_date, '%Y-%m') = DATE_FORMAT(o.order_date, '%Y-%m') THEN 1 
        ELSE 0 
    END) AS new_customers
    
FROM v_crm_all_orders o
LEFT JOIN t_crm_prod_customer c ON o.customer_id = c.id
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY COALESCE(c.city, o.address_city, 'Unknown')
HAVING city IS NOT NULL AND city != ''
ORDER BY total_revenue DESC;

-- ============================================================================
-- SECTION 15: QUANTITY SUMMARY
-- ============================================================================
DROP VIEW IF EXISTS `v_quantity_summary`;

CREATE VIEW `v_quantity_summary` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    DATE(o.order_date) AS order_date,
    
    SUM(li.quantity) AS total_quantity,
    COUNT(DISTINCT li.id) AS line_item_count,
    COUNT(DISTINCT o.id) AS order_count,
    
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN li.quantity ELSE 0 END) AS shopify_quantity,
    SUM(CASE WHEN osc.order_source = 'manual' THEN li.quantity ELSE 0 END) AS manual_quantity,
    
    SUM(li.quantity) / COUNT(DISTINCT o.id) AS avg_qty_per_order,
    SUM(li.line_total) / NULLIF(SUM(li.quantity), 0) AS revenue_per_unit
    
FROM v_crm_all_orders o
JOIN v_crm_all_order_line_items li ON o.id = li.order_id AND o.source_type = li.source_type
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), DATE(o.order_date);

-- ============================================================================
-- SECTION 16: PAYMENT METHOD ANALYSIS
-- ============================================================================
DROP VIEW IF EXISTS `v_payment_method_analysis`;

CREATE VIEW `v_payment_method_analysis` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    COALESCE(o.payment_method, 'Unknown') AS payment_method,
    
    COUNT(DISTINCT o.id) AS order_count,
    SUM(o.total_price) AS total_amount,
    AVG(o.total_price) AS avg_order_value,
    
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), COALESCE(o.payment_method, 'Unknown');

-- ============================================================================
-- SECTION 17: DISCOUNT ANALYSIS
-- ============================================================================
DROP VIEW IF EXISTS `v_discount_analysis`;

CREATE VIEW `v_discount_analysis` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    
    SUM(CASE WHEN o.discount_total > 0 THEN 1 ELSE 0 END) AS orders_with_discount,
    SUM(CASE WHEN o.discount_total = 0 THEN 1 ELSE 0 END) AS orders_without_discount,
    COUNT(DISTINCT o.id) AS total_orders,
    
    SUM(o.discount_total) AS total_discount_amount,
    AVG(CASE WHEN o.discount_total > 0 THEN o.discount_total ELSE NULL END) AS avg_discount_amount,
    
    SUM(o.discount_total) / NULLIF(SUM(o.subtotal_price), 0) * 100 AS discount_percentage,
    
    SUM(CASE WHEN o.coupon_code IS NOT NULL AND o.coupon_code != '' THEN 1 ELSE 0 END) AS orders_with_coupon
    
FROM v_crm_all_orders o
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m');

-- ============================================================================
-- SECTION 18: ORDER SOURCE SUMMARY
-- ============================================================================
DROP VIEW IF EXISTS `v_order_source_summary`;

CREATE VIEW `v_order_source_summary` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_converted_count,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_count,
    COUNT(DISTINCT o.id) AS total_count,
    
    SUM(CASE WHEN osc.order_source = 'shopify_converted' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS shopify_revenue,
    SUM(CASE WHEN osc.order_source = 'manual' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS manual_revenue,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    
    ROUND(SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(DISTINCT o.id), 0), 1) AS shopify_percentage,
    ROUND(SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(DISTINCT o.id), 0), 1) AS manual_percentage,
    
    COUNT(DISTINCT CASE WHEN osc.order_source = 'shopify_converted' THEN o.customer_id END) AS shopify_customers,
    COUNT(DISTINCT CASE WHEN osc.order_source = 'manual' THEN o.customer_id END) AS manual_customers
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m');

-- ============================================================================
-- SECTION 19: CUSTOMER COHORT ANALYSIS
-- ============================================================================
DROP VIEW IF EXISTS `v_customer_cohort`;

CREATE VIEW `v_customer_cohort` AS
SELECT 
    DATE_FORMAT(c.first_order_date, '%Y-%m') AS cohort_month,
    COUNT(DISTINCT c.id) AS cohort_size,
    
    SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 30 THEN 1 ELSE 0 END) AS active_30d,
    SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 60 THEN 1 ELSE 0 END) AS active_60d,
    SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 90 THEN 1 ELSE 0 END) AS active_90d,
    
    ROUND(SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 90 THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT c.id), 1) AS retention_rate_90d,
    
    AVG(c.total_orders) AS avg_orders,
    AVG(c.total_spent) AS avg_lifetime_value
    
FROM t_crm_prod_customer c
WHERE c.is_active = 1
  AND c.first_order_date IS NOT NULL
GROUP BY DATE_FORMAT(c.first_order_date, '%Y-%m')
ORDER BY cohort_month DESC;

-- ============================================================================
-- SECTION 20: MONTH-OVER-MONTH GROWTH
-- ============================================================================
DROP VIEW IF EXISTS `v_month_over_month_growth`;

CREATE VIEW `v_month_over_month_growth` AS
SELECT 
    m1.month_key,
    m1.month_name,
    
    m1.order_count AS current_orders,
    m1.total_revenue AS current_revenue,
    m1.unique_customers AS current_customers,
    
    m2.order_count AS previous_orders,
    m2.total_revenue AS previous_revenue,
    m2.unique_customers AS previous_customers,
    
    ROUND((m1.order_count - COALESCE(m2.order_count, 0)) * 100.0 / NULLIF(m2.order_count, 0), 1) AS order_growth_pct,
    ROUND((m1.total_revenue - COALESCE(m2.total_revenue, 0)) * 100.0 / NULLIF(m2.total_revenue, 0), 1) AS revenue_growth_pct,
    ROUND((m1.unique_customers - COALESCE(m2.unique_customers, 0)) * 100.0 / NULLIF(m2.unique_customers, 0), 1) AS customer_growth_pct,
    
    m1.shopify_converted_orders AS current_shopify,
    m1.manual_orders AS current_manual,
    m2.shopify_converted_orders AS previous_shopify,
    m2.manual_orders AS previous_manual
    
FROM v_monthly_order_summary m1
LEFT JOIN v_monthly_order_summary m2 ON m2.month_key = DATE_FORMAT(
    DATE_SUB(STR_TO_DATE(CONCAT(m1.month_key, '-01'), '%Y-%m-%d'), INTERVAL 1 MONTH), 
    '%Y-%m'
)
ORDER BY m1.month_key DESC;

-- ============================================================================
-- OPTIONAL: INDEXES (Run separately if you have ALTER TABLE permissions)
-- These are optional performance improvements. Skip if you get permission errors.
-- ============================================================================

-- Run each index separately to avoid errors if some already exist:
-- ALTER TABLE t_crm_shopify_order ADD INDEX idx_shopify_order_phone (address_phone);
-- ALTER TABLE t_crm_shopify_order ADD INDEX idx_shopify_order_date (order_date);
-- ALTER TABLE t_fin_ledger ADD INDEX idx_ledger_txn_date_type (transaction_date, transaction_type);

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================
SELECT '✅ Dashboard Analytics Views Created Successfully!' AS status;
SELECT 'Views Created: 20 analytics views (SHARED HOSTING COMPATIBLE)' AS summary;
SELECT 'No functions or triggers - safe for shared hosting!' AS compatibility;

