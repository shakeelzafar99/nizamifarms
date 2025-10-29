-- Check order NF-14566 without delivery_date column
SELECT 
    o.id,
    o.order_number,
    o.order_date,
    o.order_status,
    osh.status_code,
    osh.changed_at as actual_delivered_at,
    DATE(osh.changed_at) as delivered_date_only,
    osh.delivery_latitude,
    osh.delivery_longitude
FROM t_crm_prod_order o
LEFT JOIN t_crm_order_status_history osh 
    ON osh.order_id = o.id 
    AND osh.status_code = 'delivered' 
    AND osh.is_current = 1
WHERE o.order_number = 'NF-14566';

