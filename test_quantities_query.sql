-- This is the EXACT query that the getOpenOrderQuantitiesTree endpoint uses
-- Run this in your MySQL client to see what data is being returned

SELECT 
    o.id as order_id,
    o.order_number,
    o.order_status,
    o.order_date,
    COALESCE(
        NULLIF(TRIM(o.name), ''),
        NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''),
        TRIM(CONCAT(COALESCE(o.address_first_name, ''), ' ', COALESCE(o.address_last_name, '')))
    ) as customer_name,
    li.id as line_item_id,
    li.quantity as line_item_quantity,
    li.preparation_status as line_item_status,
    li.product_id as line_item_product_id,
    li.variant_id as line_item_variant_id,
    li.name as line_item_name,
    p.id as product_id,
    p.title as product_title,
    p.product_type,
    p.attribute_1,
    p.attribute_2,
    p.attribute_3,
    COALESCE(p.is_lean, 0) as is_lean
FROM t_crm_prod_order_line_item as li
INNER JOIN t_crm_prod_order as o ON li.order_id = o.id
LEFT JOIN t_crm_prod_product_variant as pv ON (
    li.variant_id = pv.shopify_variant_id 
    OR li.variant_id = pv.id
    OR li.product_id = pv.shopify_variant_id
    OR li.product_id = pv.id
)
LEFT JOIN t_crm_prod_product as p ON (
    (pv.product_id = p.id OR li.product_id = p.id)
    OR LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))
)
LEFT JOIN t_crm_prod_customer as c ON c.id = o.customer_id
WHERE 
    (o.external_source != 'shopify' OR o.external_source IS NULL)
    AND o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
    AND o.order_date >= DATE_SUB(NOW(), INTERVAL 20 DAY)
ORDER BY o.order_date DESC;

-- After running this, check:
-- 1. How many rows are returned?
-- 2. Are attribute_1, attribute_2, attribute_3 values populated or NULL?
-- 3. What values do you see in attribute_1? (Should be: Mutton, Chicken, Beef, Lamb, etc.)
-- 4. What values do you see in attribute_2? (Should be: Whole Chicken, Boneless, Wings, etc.)
-- 5. Are the p.id (product_id) values populated or NULL?







