-- Check customer table structure for lat/long columns
SHOW COLUMNS FROM t_crm_prod_customer;

-- Check if there are any existing lat/long columns
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 't_crm_prod_customer'
AND (
    COLUMN_NAME LIKE '%lat%' 
    OR COLUMN_NAME LIKE '%long%' 
    OR COLUMN_NAME LIKE '%location%'
    OR COLUMN_NAME LIKE '%coordinate%'
    OR COLUMN_NAME LIKE '%geo%'
);

