-- ============================================================================
-- MIGRATION: Dashboard Analytics Views & Functions (COMPLETE)
-- Date: December 10, 2025
-- Purpose: Create comprehensive analytics views for the enhanced dashboard
-- 
-- LEVERAGES EXISTING VIEWS:
--   - v_crm_all_orders (production + history orders)
--   - v_crm_all_order_line_items (production + history line items)
--   - v_crm_customer_order_summary (customer summary)
--
-- EXISTING TABLES USED:
--   - t_crm_prod_order (production orders, table column: external_source, order_number)
--   - t_crm_prod_order_line_item (production line items)
--   - t_crm_history_order (historical orders)
--   - t_crm_history_order_line_item (historical line items)
--   - t_crm_shopify_order (Shopify orders, table column: address_phone, order_date)
--   - t_crm_prod_customer (customers, column: phone_normalized)
--   - t_crm_prod_product (products, columns: attribute_1, attribute_2, attribute_3)
--   - t_crm_prod_product_variant (variants, column: sku)
--   - t_fin_ledger (financial transactions)
--
-- NEW FEATURES:
--   1. Order Source Classification (Shopify Converted vs Manual)
--   2. Phone normalization function for Shopify matching
--   3. All analytics views with source split capability
--   4. History order Shopify matching (within 3 days of Shopify order)
--
-- RUN THIS IN: MySQL Workbench (copy entire file and execute)
-- ============================================================================

-- ============================================================================
-- SECTION 0: HELPER FUNCTION - Phone Normalization
-- Extracts last 10 digits from phone number (Pakistan mobile format)
-- ============================================================================

DROP FUNCTION IF EXISTS fn_normalize_phone;

DELIMITER //

CREATE FUNCTION fn_normalize_phone(phone VARCHAR(255))
RETURNS VARCHAR(10)
DETERMINISTIC
BEGIN
    DECLARE digits VARCHAR(255);
    DECLARE normalized VARCHAR(10);
    DECLARE i INT DEFAULT 1;
    DECLARE c CHAR(1);
    
    -- Return empty if null or empty
    IF phone IS NULL OR phone = '' THEN
        RETURN '';
    END IF;
    
    -- Remove all non-digit characters
    SET digits = '';
    WHILE i <= LENGTH(phone) DO
        SET c = SUBSTRING(phone, i, 1);
        IF c REGEXP '[0-9]' THEN
            SET digits = CONCAT(digits, c);
        END IF;
        SET i = i + 1;
    END WHILE;
    
    -- Take last 10 digits
    IF LENGTH(digits) >= 10 THEN
        SET normalized = RIGHT(digits, 10);
    ELSE
        SET normalized = LPAD(digits, 10, '0');
    END IF;
    
    RETURN normalized;
END //

DELIMITER ;

-- ============================================================================
-- SECTION 1: ORDER SOURCE CLASSIFICATION VIEW
-- Determines if an order is: 
--   - 'shopify_converted' (order_number starts with 'SH' OR has matching Shopify order)
--   - 'manual' (direct order without Shopify origin)
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
    fn_normalize_phone(o.address_phone) AS phone_normalized,
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
            WHERE fn_normalize_phone(so.address_phone) = fn_normalize_phone(o.address_phone)
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
    fn_normalize_phone(h.address_phone) AS phone_normalized,
    h.total_price,
    h.order_status,
    
    -- Classification Logic for History:
    -- 1. If order_number starts with 'SH' or 'sh' = shopify_converted
    -- 2. If there's a matching Shopify order (same normalized phone within 3 days) = shopify_converted
    -- 3. Otherwise = manual
    CASE 
        WHEN UPPER(LEFT(h.order_number, 2)) = 'SH' THEN 'shopify_converted'
        WHEN EXISTS (
            SELECT 1 FROM t_crm_shopify_order so
            WHERE fn_normalize_phone(so.address_phone) = fn_normalize_phone(h.address_phone)
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
-- Enriches line items with product category information
-- Uses EXISTING v_crm_all_order_line_items view
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
-- Pre-aggregated daily stats (excluding Shopify) with Converted vs Manual split
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
-- Pre-aggregated monthly stats
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
-- Classifies customers as New, Regular, or Reactivated per month
-- Includes order source split
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
-- Aggregated product sales by category levels
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
-- Daily ledger summary for invoices, expenses, vendor payments
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
-- Monthly ledger summary
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
-- What are new customers ordering? With source split
-- ============================================================================
DROP VIEW IF EXISTS `v_new_customer_product_preferences`;

CREATE VIEW `v_new_customer_product_preferences` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    lic.category_level_1,
    lic.category_level_2,
    
    -- Only count orders from NEW customers (first order in this month)
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
  -- Filter: Customer's first order is in the same month as this order
  AND DATE_FORMAT(c.first_order_date, '%Y-%m') = DATE_FORMAT(o.order_date, '%Y-%m')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), 
         lic.category_level_1, lic.category_level_2;

-- ============================================================================
-- SECTION 11: CUSTOMER ACTIVITY SEGMENTS
-- Segment customers by recency and frequency (RFM-style)
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
    
    -- Days since last order
    DATEDIFF(CURDATE(), c.last_order_date) AS days_since_last_order,
    
    -- Activity segment based on last order
    CASE 
        WHEN c.last_order_date IS NULL THEN 'NEVER_ORDERED'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 7 THEN 'ACTIVE_7D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 30 THEN 'ACTIVE_30D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 60 THEN 'ACTIVE_60D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 90 THEN 'ACTIVE_90D'
        WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 180 THEN 'DORMANT_6M'
        ELSE 'CHURNED'
    END AS activity_segment,
    
    -- Frequency segment based on order count
    CASE 
        WHEN c.total_orders = 0 THEN 'NEVER'
        WHEN c.total_orders = 1 THEN 'ONE_TIME'
        WHEN c.total_orders BETWEEN 2 AND 5 THEN 'OCCASIONAL'
        WHEN c.total_orders BETWEEN 6 AND 15 THEN 'REGULAR'
        ELSE 'LOYAL'
    END AS frequency_segment,
    
    -- Value segment based on total spent
    CASE 
        WHEN c.total_spent <= 0 THEN 'ZERO'
        WHEN c.total_spent < 5000 THEN 'LOW'
        WHEN c.total_spent < 25000 THEN 'MEDIUM'
        WHEN c.total_spent < 100000 THEN 'HIGH'
        ELSE 'VIP'
    END AS value_segment,
    
    -- Average order value
    CASE WHEN c.total_orders > 0 THEN c.total_spent / c.total_orders ELSE 0 END AS avg_order_value
    
FROM t_crm_prod_customer c
WHERE c.is_active = 1;

-- ============================================================================
-- SECTION 12: WEEKLY DAY ANALYSIS (Best Selling Days)
-- Which days of the week perform best? With source split
-- ============================================================================
DROP VIEW IF EXISTS `v_weekly_day_performance`;

CREATE VIEW `v_weekly_day_performance` AS
SELECT 
    DAYOFWEEK(o.order_date) AS day_of_week,
    DAYNAME(o.order_date) AS day_name,
    
    -- Total metrics
    COUNT(DISTINCT o.id) AS total_orders,
    COUNT(DISTINCT o.customer_id) AS total_customers,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    AVG(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE NULL END) AS avg_order_value,
    
    -- By Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders,
    
    -- Days count for averaging
    COUNT(DISTINCT DATE(o.order_date)) AS days_count
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY DAYOFWEEK(o.order_date), DAYNAME(o.order_date)
ORDER BY DAYOFWEEK(o.order_date);

-- ============================================================================
-- SECTION 13: HOURLY ORDER DISTRIBUTION (Peak Hours)
-- What hours are busiest?
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
    
    -- By Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY HOUR(o.order_date)
ORDER BY HOUR(o.order_date);

-- ============================================================================
-- SECTION 14: CITY-WISE PERFORMANCE
-- Performance breakdown by customer city with source split
-- ============================================================================
DROP VIEW IF EXISTS `v_city_performance`;

CREATE VIEW `v_city_performance` AS
SELECT 
    COALESCE(c.city, o.address_city, 'Unknown') AS city,
    
    -- Total metrics
    COUNT(DISTINCT o.id) AS order_count,
    COUNT(DISTINCT o.customer_id) AS customer_count,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    AVG(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE NULL END) AS avg_order_value,
    
    -- By Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders,
    
    -- New customers from this city (current month)
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
-- SECTION 15: QUANTITY-BASED ORDER SUMMARY
-- Total quantity analysis by line items
-- ============================================================================
DROP VIEW IF EXISTS `v_quantity_summary`;

CREATE VIEW `v_quantity_summary` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    DATE(o.order_date) AS order_date,
    
    -- Quantity metrics
    SUM(li.quantity) AS total_quantity,
    COUNT(DISTINCT li.id) AS line_item_count,
    COUNT(DISTINCT o.id) AS order_count,
    
    -- By Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN li.quantity ELSE 0 END) AS shopify_quantity,
    SUM(CASE WHEN osc.order_source = 'manual' THEN li.quantity ELSE 0 END) AS manual_quantity,
    
    -- Average quantity per order
    SUM(li.quantity) / COUNT(DISTINCT o.id) AS avg_qty_per_order,
    
    -- Revenue per unit
    SUM(li.line_total) / NULLIF(SUM(li.quantity), 0) AS revenue_per_unit
    
FROM v_crm_all_orders o
JOIN v_crm_all_order_line_items li ON o.id = li.order_id AND o.source_type = li.source_type
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), DATE(o.order_date);

-- ============================================================================
-- SECTION 16: PAYMENT METHOD ANALYSIS
-- Breakdown by payment method over time
-- ============================================================================
DROP VIEW IF EXISTS `v_payment_method_analysis`;

CREATE VIEW `v_payment_method_analysis` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    COALESCE(o.payment_method, 'Unknown') AS payment_method,
    
    COUNT(DISTINCT o.id) AS order_count,
    SUM(o.total_price) AS total_amount,
    AVG(o.total_price) AS avg_order_value,
    
    -- By Source
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_orders,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_orders
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m'), COALESCE(o.payment_method, 'Unknown');

-- ============================================================================
-- SECTION 17: DISCOUNT/COUPON USAGE
-- Discount analysis
-- ============================================================================
DROP VIEW IF EXISTS `v_discount_analysis`;

CREATE VIEW `v_discount_analysis` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    
    -- Orders with discount
    SUM(CASE WHEN o.discount_total > 0 THEN 1 ELSE 0 END) AS orders_with_discount,
    SUM(CASE WHEN o.discount_total = 0 THEN 1 ELSE 0 END) AS orders_without_discount,
    COUNT(DISTINCT o.id) AS total_orders,
    
    -- Discount amounts
    SUM(o.discount_total) AS total_discount_amount,
    AVG(CASE WHEN o.discount_total > 0 THEN o.discount_total ELSE NULL END) AS avg_discount_amount,
    
    -- Discount as % of subtotal
    SUM(o.discount_total) / NULLIF(SUM(o.subtotal_price), 0) * 100 AS discount_percentage,
    
    -- Coupon usage
    SUM(CASE WHEN o.coupon_code IS NOT NULL AND o.coupon_code != '' THEN 1 ELSE 0 END) AS orders_with_coupon
    
FROM v_crm_all_orders o
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
  AND o.order_status IN ('delivered', 'completed', 'processing')
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m');

-- ============================================================================
-- SECTION 18: ORDER SOURCE SUMMARY (Shopify Converted vs Manual)
-- High-level summary of order sources
-- ============================================================================
DROP VIEW IF EXISTS `v_order_source_summary`;

CREATE VIEW `v_order_source_summary` AS
SELECT 
    DATE_FORMAT(o.order_date, '%Y-%m') AS month_key,
    
    -- Source counts
    SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) AS shopify_converted_count,
    SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) AS manual_count,
    COUNT(DISTINCT o.id) AS total_count,
    
    -- Source revenue
    SUM(CASE WHEN osc.order_source = 'shopify_converted' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS shopify_revenue,
    SUM(CASE WHEN osc.order_source = 'manual' AND o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS manual_revenue,
    SUM(CASE WHEN o.order_status IN ('delivered', 'completed', 'processing') THEN o.total_price ELSE 0 END) AS total_revenue,
    
    -- Percentages
    ROUND(SUM(CASE WHEN osc.order_source = 'shopify_converted' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(DISTINCT o.id), 0), 1) AS shopify_percentage,
    ROUND(SUM(CASE WHEN osc.order_source = 'manual' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(DISTINCT o.id), 0), 1) AS manual_percentage,
    
    -- Unique customers by source
    COUNT(DISTINCT CASE WHEN osc.order_source = 'shopify_converted' THEN o.customer_id END) AS shopify_customers,
    COUNT(DISTINCT CASE WHEN osc.order_source = 'manual' THEN o.customer_id END) AS manual_customers
    
FROM v_crm_all_orders o
LEFT JOIN v_order_source_classification osc ON o.id = osc.order_id AND o.source_type = osc.data_source
WHERE (o.external_source IS NULL OR o.external_source NOT IN ('shopify'))
GROUP BY DATE_FORMAT(o.order_date, '%Y-%m');

-- ============================================================================
-- SECTION 19: CUSTOMER COHORT ANALYSIS
-- Monthly cohort retention (based on first order month)
-- ============================================================================
DROP VIEW IF EXISTS `v_customer_cohort`;

CREATE VIEW `v_customer_cohort` AS
SELECT 
    DATE_FORMAT(c.first_order_date, '%Y-%m') AS cohort_month,
    COUNT(DISTINCT c.id) AS cohort_size,
    
    -- Activity breakdown
    SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 30 THEN 1 ELSE 0 END) AS active_30d,
    SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 60 THEN 1 ELSE 0 END) AS active_60d,
    SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 90 THEN 1 ELSE 0 END) AS active_90d,
    
    -- Retention rate (90 days)
    ROUND(SUM(CASE WHEN DATEDIFF(CURDATE(), c.last_order_date) <= 90 THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT c.id), 1) AS retention_rate_90d,
    
    -- Average metrics
    AVG(c.total_orders) AS avg_orders,
    AVG(c.total_spent) AS avg_lifetime_value
    
FROM t_crm_prod_customer c
WHERE c.is_active = 1
  AND c.first_order_date IS NOT NULL
GROUP BY DATE_FORMAT(c.first_order_date, '%Y-%m')
ORDER BY cohort_month DESC;

-- ============================================================================
-- SECTION 20: MONTH-OVER-MONTH GROWTH VIEW
-- Compares current month to previous month
-- ============================================================================
DROP VIEW IF EXISTS `v_month_over_month_growth`;

CREATE VIEW `v_month_over_month_growth` AS
SELECT 
    m1.month_key,
    m1.month_name,
    
    -- Current period
    m1.order_count AS current_orders,
    m1.total_revenue AS current_revenue,
    m1.unique_customers AS current_customers,
    
    -- Previous period
    m2.order_count AS previous_orders,
    m2.total_revenue AS previous_revenue,
    m2.unique_customers AS previous_customers,
    
    -- Growth calculations
    ROUND((m1.order_count - COALESCE(m2.order_count, 0)) * 100.0 / NULLIF(m2.order_count, 0), 1) AS order_growth_pct,
    ROUND((m1.total_revenue - COALESCE(m2.total_revenue, 0)) * 100.0 / NULLIF(m2.total_revenue, 0), 1) AS revenue_growth_pct,
    ROUND((m1.unique_customers - COALESCE(m2.unique_customers, 0)) * 100.0 / NULLIF(m2.unique_customers, 0), 1) AS customer_growth_pct,
    
    -- Source breakdown
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
-- HELPFUL INDEXES FOR ANALYTICS PERFORMANCE
-- Run these separately if any fail (index may already exist)
-- ============================================================================

-- Index on Shopify orders for phone matching (critical for source classification)
-- Drop first to avoid duplicate errors, then create
DROP INDEX IF EXISTS idx_shopify_order_phone ON t_crm_shopify_order;
DROP INDEX IF EXISTS idx_shopify_order_date ON t_crm_shopify_order;
DROP INDEX IF EXISTS idx_shopify_order_phone_date ON t_crm_shopify_order;

CREATE INDEX idx_shopify_order_phone ON t_crm_shopify_order(address_phone);
CREATE INDEX idx_shopify_order_date ON t_crm_shopify_order(order_date);
CREATE INDEX idx_shopify_order_phone_date ON t_crm_shopify_order(address_phone, order_date);

-- Index on ledger for financial views
DROP INDEX IF EXISTS idx_ledger_txn_date_type ON t_fin_ledger;
DROP INDEX IF EXISTS idx_ledger_approval_type ON t_fin_ledger;

CREATE INDEX idx_ledger_txn_date_type ON t_fin_ledger(transaction_date, transaction_type);
CREATE INDEX idx_ledger_approval_type ON t_fin_ledger(approval_status, transaction_type);

-- Index on history orders for combined views
DROP INDEX IF EXISTS idx_ho_order_date_status ON t_crm_history_order;
DROP INDEX IF EXISTS idx_ho_customer_status ON t_crm_history_order;

CREATE INDEX idx_ho_order_date_status ON t_crm_history_order(order_date, order_status);
CREATE INDEX idx_ho_customer_status ON t_crm_history_order(customer_id, order_status);

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================
SELECT '✅ Dashboard Analytics Views Created Successfully!' AS status;
SELECT 'Views Created: 20 analytics views + 1 helper function for comprehensive dashboard' AS summary;
SELECT 'Features: Order Source Classification (Shopify Converted vs Manual), Product Categories, Financial Summary, Customer Segments' AS features;
SELECT 'Run verification queries to test the views!' AS next_step;
