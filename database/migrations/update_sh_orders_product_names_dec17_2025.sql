-- ============================================================================
-- UPDATE CONVERTED SHOPIFY ORDERS WITH CORRECT PRODUCT NAMES
-- Date: December 17, 2025
-- Purpose: Update line item names for orders converted from Shopify (SH- prefix)
--          to use product names from our local product table
-- ============================================================================

-- ============================================================================
-- STEP 1: PREVIEW - See what will be updated (RUN THIS FIRST!)
-- ============================================================================

SELECT 
    o.id AS order_id,
    o.order_number,
    o.order_date,
    li.id AS line_item_id,
    li.sku,
    li.name AS current_name,
    p.title AS new_name_from_system,
    CASE 
        WHEN li.name = p.title THEN 'NO CHANGE'
        ELSE 'WILL UPDATE'
    END AS action
FROM t_crm_prod_order o
INNER JOIN t_crm_prod_order_line_item li ON li.order_id = o.id
INNER JOIN t_crm_prod_product_variant pv ON pv.sku = li.sku
INNER JOIN t_crm_prod_product p ON p.id = pv.product_id
WHERE o.order_number LIKE 'SH-%'
  AND li.sku IS NOT NULL 
  AND li.sku != ''
  AND li.name != p.title  -- Only show items that need updating
ORDER BY o.order_date DESC, o.id, li.id;

-- ============================================================================
-- STEP 2: COUNT - How many line items will be affected?
-- ============================================================================

SELECT 
    COUNT(*) AS total_line_items_to_update,
    COUNT(DISTINCT o.id) AS total_orders_affected
FROM t_crm_prod_order o
INNER JOIN t_crm_prod_order_line_item li ON li.order_id = o.id
INNER JOIN t_crm_prod_product_variant pv ON pv.sku = li.sku
INNER JOIN t_crm_prod_product p ON p.id = pv.product_id
WHERE o.order_number LIKE 'SH-%'
  AND li.sku IS NOT NULL 
  AND li.sku != ''
  AND li.name != p.title;

-- ============================================================================
-- STEP 3: UPDATE - Run this ONLY after reviewing the preview above
-- ============================================================================

-- START TRANSACTION (Recommended for safety)
START TRANSACTION;

UPDATE t_crm_prod_order_line_item li
INNER JOIN t_crm_prod_order o ON o.id = li.order_id
INNER JOIN t_crm_prod_product_variant pv ON pv.sku = li.sku
INNER JOIN t_crm_prod_product p ON p.id = pv.product_id
SET li.name = p.title,
    li.updated_at = NOW()
WHERE o.order_number LIKE 'SH-%'
  AND li.sku IS NOT NULL 
  AND li.sku != ''
  AND li.name != p.title;

-- Check how many rows were updated
SELECT ROW_COUNT() AS rows_updated;

-- If everything looks good, COMMIT the changes
-- COMMIT;

-- If something went wrong, ROLLBACK instead
-- ROLLBACK;

-- ============================================================================
-- STEP 4: VERIFICATION - Run after committing to verify the update
-- ============================================================================

-- Check if any SH orders still have mismatched names (should be 0)
SELECT 
    COUNT(*) AS remaining_mismatches
FROM t_crm_prod_order o
INNER JOIN t_crm_prod_order_line_item li ON li.order_id = o.id
INNER JOIN t_crm_prod_product_variant pv ON pv.sku = li.sku
INNER JOIN t_crm_prod_product p ON p.id = pv.product_id
WHERE o.order_number LIKE 'SH-%'
  AND li.sku IS NOT NULL 
  AND li.sku != ''
  AND li.name != p.title;

-- ============================================================================
-- OPTIONAL: Show summary of updated orders
-- ============================================================================

SELECT 
    o.order_number,
    o.order_date,
    COUNT(li.id) AS line_items_count,
    o.total_price
FROM t_crm_prod_order o
INNER JOIN t_crm_prod_order_line_item li ON li.order_id = o.id
WHERE o.order_number LIKE 'SH-%'
GROUP BY o.id
ORDER BY o.order_date DESC
LIMIT 20;

-- ============================================================================
-- NOTES:
-- 1. Run STEP 1 first to preview what will change
-- 2. Run STEP 2 to see the count of affected records
-- 3. Run STEP 3 (the UPDATE) only after reviewing
-- 4. Uncomment COMMIT after verifying ROW_COUNT looks correct
-- 5. Run STEP 4 to verify no mismatches remain
-- ============================================================================

