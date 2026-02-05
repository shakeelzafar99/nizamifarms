-- =====================================================
-- FIND ORDERS WITH TOTAL MISMATCHES
-- Created: February 4, 2026
-- Purpose: Identify orders where stored total_price doesn't match calculated total
-- =====================================================

-- =====================================================
-- QUERY 1: Find all orders with total mismatches
-- Shows orders where total_price differs from calculated total by more than 1 PKR
-- =====================================================
SELECT 
    o.id AS order_id,
    o.order_number,
    o.external_source,
    o.order_status,
    o.order_date,
    o.name AS customer_name,
    
    -- Stored values
    o.subtotal_price AS stored_subtotal,
    o.discount_total AS stored_discount,
    o.shipping_total AS stored_shipping,
    o.tip_amount AS stored_tip,
    o.total_price AS stored_total,
    
    -- Calculated values from line items
    COALESCE(li.calculated_subtotal, 0) AS calculated_subtotal,
    COALESCE(o.discount_total, 0) AS discount,
    COALESCE(o.shipping_total, 0) AS shipping,
    COALESCE(o.tip_amount, 0) AS tip,
    (COALESCE(li.calculated_subtotal, 0) - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0)) AS calculated_total,
    
    -- Difference
    (o.total_price - (COALESCE(li.calculated_subtotal, 0) - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0))) AS total_difference,
    (o.subtotal_price - COALESCE(li.calculated_subtotal, 0)) AS subtotal_difference,
    
    -- Line item count
    COALESCE(li.item_count, 0) AS line_item_count,
    
    -- Creation info
    o.created_at,
    o.created_by
    
FROM t_crm_prod_order o
LEFT JOIN (
    SELECT 
        order_id,
        SUM(COALESCE(line_total, quantity * unit_price)) AS calculated_subtotal,
        COUNT(*) AS item_count
    FROM t_crm_prod_order_line_item
    GROUP BY order_id
) li ON o.id = li.order_id

WHERE 
    -- Only check orders with line items
    COALESCE(li.item_count, 0) > 0
    -- Where total mismatch is more than 1 PKR (to account for rounding)
    AND ABS(o.total_price - (COALESCE(li.calculated_subtotal, 0) - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0))) > 1

ORDER BY 
    ABS(o.total_price - (COALESCE(li.calculated_subtotal, 0) - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0))) DESC,
    o.created_at DESC;

-- =====================================================
-- QUERY 2: Summary statistics
-- =====================================================
SELECT 
    'Total Orders' AS metric,
    COUNT(*) AS value
FROM t_crm_prod_order

UNION ALL

SELECT 
    'Orders with Line Items' AS metric,
    COUNT(DISTINCT o.id) AS value
FROM t_crm_prod_order o
INNER JOIN t_crm_prod_order_line_item li ON o.id = li.order_id

UNION ALL

SELECT 
    'Orders with Total Mismatch (>1 PKR)' AS metric,
    COUNT(*) AS value
FROM t_crm_prod_order o
LEFT JOIN (
    SELECT 
        order_id,
        SUM(COALESCE(line_total, quantity * unit_price)) AS calculated_subtotal
    FROM t_crm_prod_order_line_item
    GROUP BY order_id
) li ON o.id = li.order_id
WHERE 
    li.calculated_subtotal IS NOT NULL
    AND ABS(o.total_price - (COALESCE(li.calculated_subtotal, 0) - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0))) > 1

UNION ALL

SELECT 
    'Orders with Subtotal Mismatch (>1 PKR)' AS metric,
    COUNT(*) AS value
FROM t_crm_prod_order o
LEFT JOIN (
    SELECT 
        order_id,
        SUM(COALESCE(line_total, quantity * unit_price)) AS calculated_subtotal
    FROM t_crm_prod_order_line_item
    GROUP BY order_id
) li ON o.id = li.order_id
WHERE 
    li.calculated_subtotal IS NOT NULL
    AND ABS(o.subtotal_price - COALESCE(li.calculated_subtotal, 0)) > 1;

-- =====================================================
-- QUERY 3: Fix script (PREVIEW - run SELECT first, then UPDATE)
-- Recalculates and fixes totals for mismatched orders
-- =====================================================

-- PREVIEW: See what would be updated
SELECT 
    o.id,
    o.order_number,
    o.subtotal_price AS old_subtotal,
    li.calculated_subtotal AS new_subtotal,
    o.total_price AS old_total,
    (li.calculated_subtotal - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0)) AS new_total
FROM t_crm_prod_order o
INNER JOIN (
    SELECT 
        order_id,
        SUM(COALESCE(line_total, quantity * unit_price)) AS calculated_subtotal
    FROM t_crm_prod_order_line_item
    GROUP BY order_id
) li ON o.id = li.order_id
WHERE 
    ABS(o.total_price - (li.calculated_subtotal - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0))) > 1;

-- =====================================================
-- ACTUAL FIX (Uncomment to run after reviewing preview)
-- =====================================================
/*
UPDATE t_crm_prod_order o
INNER JOIN (
    SELECT 
        order_id,
        SUM(COALESCE(line_total, quantity * unit_price)) AS calculated_subtotal
    FROM t_crm_prod_order_line_item
    GROUP BY order_id
) li ON o.id = li.order_id
SET 
    o.subtotal_price = li.calculated_subtotal,
    o.total_price = (li.calculated_subtotal - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0)),
    o.updated_at = NOW()
WHERE 
    ABS(o.total_price - (li.calculated_subtotal - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0))) > 1;
*/

-- =====================================================
-- QUERY 4: Check specific order (NF-16928)
-- =====================================================
SELECT 
    o.id,
    o.order_number,
    o.subtotal_price,
    o.discount_total,
    o.shipping_total,
    o.tip_amount,
    o.total_price AS stored_total,
    li.calculated_subtotal,
    (li.calculated_subtotal - COALESCE(o.discount_total, 0) + COALESCE(o.shipping_total, 0) + COALESCE(o.tip_amount, 0)) AS correct_total
FROM t_crm_prod_order o
LEFT JOIN (
    SELECT 
        order_id,
        SUM(COALESCE(line_total, quantity * unit_price)) AS calculated_subtotal
    FROM t_crm_prod_order_line_item
    GROUP BY order_id
) li ON o.id = li.order_id
WHERE o.order_number = 'NF-16928';

-- Show line items for NF-16928
SELECT 
    li.id,
    li.name,
    li.quantity,
    li.unit_price,
    li.line_total,
    (li.quantity * li.unit_price) AS calculated_line_total
FROM t_crm_prod_order_line_item li
INNER JOIN t_crm_prod_order o ON li.order_id = o.id
WHERE o.order_number = 'NF-16928';
