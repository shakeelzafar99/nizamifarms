-- ================================================================
-- PERFORMANCE OPTIMIZATION FOR OPEN ORDER QUANTITIES
-- Date: November 6, 2025
-- Purpose: Add indexes and optimizations to speed up Open Order Quantities queries
-- ================================================================

-- ================================================================
-- 1. INDEXES ON JOIN COLUMNS (Most Critical)
-- ================================================================

-- Line Items: order_id for JOIN with orders table
CREATE INDEX IF NOT EXISTS idx_line_item_order_id 
ON t_crm_prod_order_line_item(order_id);

-- Line Items: variant_id for JOIN with variants table
CREATE INDEX IF NOT EXISTS idx_line_item_variant_id 
ON t_crm_prod_order_line_item(variant_id);

-- Line Items: product_id for direct product JOIN
CREATE INDEX IF NOT EXISTS idx_line_item_product_id 
ON t_crm_prod_order_line_item(product_id);

-- Product Variants: shopify_variant_id for matching
CREATE INDEX IF NOT EXISTS idx_product_variant_shopify_id 
ON t_crm_prod_product_variant(shopify_variant_id);

-- Product Variants: product_id for JOIN with products table
CREATE INDEX IF NOT EXISTS idx_product_variant_product_id 
ON t_crm_prod_product_variant(product_id);

-- ================================================================
-- 2. INDEXES ON FILTER COLUMNS
-- ================================================================

-- Orders: external_source (for filtering out Shopify orders)
CREATE INDEX IF NOT EXISTS idx_order_external_source 
ON t_crm_prod_order(external_source);

-- Orders: order_status (for excluding delivered/completed/cancelled)
CREATE INDEX IF NOT EXISTS idx_order_status 
ON t_crm_prod_order(order_status);

-- Orders: order_date (for date range filtering and sorting)
CREATE INDEX IF NOT EXISTS idx_order_date 
ON t_crm_prod_order(order_date DESC);

-- Orders: customer_id for customer JOIN
CREATE INDEX IF NOT EXISTS idx_order_customer_id 
ON t_crm_prod_order(customer_id);

-- Line Items: preparation_status (for filtering prepared items)
CREATE INDEX IF NOT EXISTS idx_line_item_preparation_status 
ON t_crm_prod_order_line_item(preparation_status);

-- Products: is_lean (for lean/non-lean split calculations)
CREATE INDEX IF NOT EXISTS idx_product_is_lean 
ON t_crm_prod_product(is_lean);

-- Products: product_type (for categorization)
CREATE INDEX IF NOT EXISTS idx_product_type 
ON t_crm_prod_product(product_type);

-- Products: attribute columns (for categorization)
CREATE INDEX IF NOT EXISTS idx_product_attribute_1 
ON t_crm_prod_product(attribute_1);

CREATE INDEX IF NOT EXISTS idx_product_attribute_2 
ON t_crm_prod_product(attribute_2);

CREATE INDEX IF NOT EXISTS idx_product_attribute_3 
ON t_crm_prod_product(attribute_3);

-- ================================================================
-- 3. COMPOSITE INDEXES FOR COMMON QUERY PATTERNS
-- ================================================================

-- Orders: external_source + order_status (most common filter combination)
CREATE INDEX IF NOT EXISTS idx_order_source_status 
ON t_crm_prod_order(external_source, order_status);

-- Orders: order_status + order_date (for open orders sorted by date)
CREATE INDEX IF NOT EXISTS idx_order_status_date 
ON t_crm_prod_order(order_status, order_date DESC);

-- Line Items: order_id + preparation_status (for counting prepared items per order)
CREATE INDEX IF NOT EXISTS idx_line_item_order_prep 
ON t_crm_prod_order_line_item(order_id, preparation_status);

-- Products: product_type + is_lean (for category + lean filtering)
CREATE INDEX IF NOT EXISTS idx_product_type_lean 
ON t_crm_prod_product(product_type, is_lean);

-- ================================================================
-- 4. COVERING INDEX FOR ORDERS LEVEL QUERY
-- ================================================================

-- This index covers the most common columns selected in orders-level queries
-- Helps avoid table lookups by including all needed columns in the index
CREATE INDEX IF NOT EXISTS idx_order_covering 
ON t_crm_prod_order(
    order_status, 
    external_source, 
    order_date, 
    id, 
    order_number, 
    customer_id, 
    name
);

-- ================================================================
-- 5. ANALYZE TABLES (Update Statistics for Query Optimizer)
-- ================================================================

ANALYZE TABLE t_crm_prod_order_line_item;
ANALYZE TABLE t_crm_prod_order;
ANALYZE TABLE t_crm_prod_product;
ANALYZE TABLE t_crm_prod_product_variant;
ANALYZE TABLE t_crm_prod_customer;

-- ================================================================
-- 6. VERIFICATION QUERIES
-- ================================================================

-- Check all indexes on line items table
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as COLUMNS,
    INDEX_TYPE,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order_line_item'
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE, NON_UNIQUE
ORDER BY INDEX_NAME;

-- Check all indexes on orders table
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as COLUMNS,
    INDEX_TYPE,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order'
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE, NON_UNIQUE
ORDER BY INDEX_NAME;

-- Check all indexes on products table
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as COLUMNS,
    INDEX_TYPE,
    NON_UNIQUE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_product'
GROUP BY TABLE_NAME, INDEX_NAME, INDEX_TYPE, NON_UNIQUE
ORDER BY INDEX_NAME;

-- ================================================================
-- 7. PERFORMANCE TESTING QUERY
-- ================================================================

-- Test query to check performance improvement
-- Run this BEFORE and AFTER applying indexes to compare execution time
EXPLAIN 
SELECT 
    o.id as order_id,
    o.order_number,
    o.order_status,
    COUNT(DISTINCT li.id) as line_item_count,
    SUM(li.quantity) as total_quantity,
    SUM(CASE WHEN p.is_lean = 1 THEN li.quantity ELSE 0 END) as lean_quantity,
    SUM(CASE WHEN li.preparation_status = 'preparing' THEN li.quantity ELSE 0 END) as preparing_quantity
FROM t_crm_prod_order_line_item li
JOIN t_crm_prod_order o ON li.order_id = o.id
LEFT JOIN t_crm_prod_product_variant pv ON (
    li.variant_id = pv.shopify_variant_id OR 
    li.variant_id = pv.id OR 
    li.product_id = pv.shopify_variant_id OR 
    li.product_id = pv.id
)
LEFT JOIN t_crm_prod_product p ON (
    pv.product_id = p.id OR 
    li.product_id = p.id
)
WHERE (o.external_source != 'shopify' OR o.external_source IS NULL)
  AND o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
GROUP BY o.id, o.order_number, o.order_status
ORDER BY o.order_date DESC
LIMIT 100;

-- ================================================================
-- NOTES:
-- ================================================================
-- 1. These indexes will significantly speed up Open Order Quantities queries
-- 2. The composite indexes target the most common filter combinations
-- 3. The covering index reduces disk I/O for the orders level query
-- 4. Run ANALYZE TABLE after applying indexes to update query optimizer statistics
-- 5. Monitor slow query log to identify any remaining bottlenecks
-- 6. Consider adding query result caching in Laravel for frequently accessed data
-- 
-- EXPECTED IMPROVEMENTS:
-- - 50-80% faster query execution for initial page load
-- - 60-90% faster drill-down operations
-- - Better performance with large datasets (1000+ orders)
-- 
-- ESTIMATED DISK SPACE:
-- - Each index will use approximately 1-5 MB depending on table size
-- - Total additional space: ~50-100 MB for all indexes
-- ================================================================

