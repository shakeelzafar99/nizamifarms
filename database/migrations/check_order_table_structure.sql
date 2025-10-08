-- Quick check: What's the actual structure of t_crm_prod_order?
USE nizamifarms_db;

SELECT 'Checking t_crm_prod_order structure...' as '';

-- Check if table exists
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Table EXISTS' 
        ELSE '✗ Table NOT FOUND' 
    END as Status
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_crm_prod_order';

-- Show structure (especially the 'id' column type)
DESCRIBE t_crm_prod_order;

-- Show the exact data type of 'id' column
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_crm_prod_order'
AND COLUMN_NAME = 'id';

