-- Check packet tracking for order 2613
-- This query will show if expected_packets was saved

SELECT 
    id,
    order_number,
    order_status,
    total_price,
    expected_packets,
    actual_packets,
    note,
    updated_at
FROM t_crm_prod_order
WHERE id = 2613;

-- If expected_packets shows NULL, the save didn't work
-- If expected_packets shows 4, the save worked!

