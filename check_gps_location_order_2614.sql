-- Check GPS location for order 2614 (recently delivered)
-- This will show if GPS coordinates are being stored

-- 1. Check the order details
SELECT 
    id,
    order_number,
    order_status,
    order_date,
    delivery_date,
    expected_packets,
    actual_packets,
    created_at,
    updated_at
FROM t_crm_prod_order
WHERE id = 2614;

-- 2. Check the status history table structure
DESCRIBE t_crm_order_status_history;

-- 3. Check if GPS columns exist
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_crm_order_status_history'
AND COLUMN_NAME IN ('delivery_latitude', 'delivery_longitude');

-- 4. Check status history for order 2614
SELECT 
    id,
    order_id,
    status_code,
    is_current,
    changed_at,
    changed_by,
    notes
FROM t_crm_order_status_history
WHERE order_id = 2614
ORDER BY changed_at DESC;

-- 5. If GPS columns exist, check their values
-- SELECT 
--     id,
--     order_id,
--     status_code,
--     delivery_latitude,
--     delivery_longitude,
--     changed_at
-- FROM t_crm_order_status_history
-- WHERE order_id = 2614
-- AND status_code = 'delivered';

