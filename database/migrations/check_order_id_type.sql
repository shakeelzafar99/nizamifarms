-- Check the exact data type of t_crm_prod_order.id
USE nizamifarms_db;

SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_KEY,
    EXTRA
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_crm_prod_order'
AND COLUMN_NAME = 'id';

