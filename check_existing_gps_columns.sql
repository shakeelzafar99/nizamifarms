-- Check what GPS and delivery columns already exist in t_crm_order_status_history

SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 't_crm_order_status_history'
AND (COLUMN_NAME LIKE '%delivery%' OR COLUMN_NAME LIKE '%latitude%' OR COLUMN_NAME LIKE '%longitude%')
ORDER BY ORDINAL_POSITION;

-- Also check a recent delivered order to see if GPS is being stored
SELECT 
    id,
    order_id,
    status_code,
    is_current,
    changed_at,
    delivery_latitude,
    delivery_longitude,
    notes
FROM t_crm_order_status_history
WHERE order_id = 2614
AND status_code = 'delivered'
ORDER BY changed_at DESC
LIMIT 1;

