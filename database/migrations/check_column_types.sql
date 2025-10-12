-- Check actual column types for FK compatibility
USE nizamifarms_db;

SELECT 'Checking t_sys_user.id type:' as Info;
SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_sys_user' 
AND COLUMN_NAME = 'id';

SELECT '' as '';
SELECT 'Checking t_fin_ledger.id type:' as Info;
SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_fin_ledger' 
AND COLUMN_NAME = 'id';

SELECT '' as '';
SELECT 'Checking t_fin_accounts.id type:' as Info;
SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_fin_accounts' 
AND COLUMN_NAME = 'id';

SELECT '' as '';
SELECT 'Checking existing FKs on t_req_master for comparison:' as Info;
SELECT 
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    c1.COLUMN_TYPE as 'Local Column Type',
    c2.COLUMN_TYPE as 'Referenced Column Type'
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
JOIN INFORMATION_SCHEMA.COLUMNS c1 
    ON c1.TABLE_SCHEMA = kcu.TABLE_SCHEMA 
    AND c1.TABLE_NAME = kcu.TABLE_NAME 
    AND c1.COLUMN_NAME = kcu.COLUMN_NAME
JOIN INFORMATION_SCHEMA.COLUMNS c2 
    ON c2.TABLE_SCHEMA = kcu.REFERENCED_TABLE_SCHEMA 
    AND c2.TABLE_NAME = kcu.REFERENCED_TABLE_NAME 
    AND c2.COLUMN_NAME = kcu.REFERENCED_COLUMN_NAME
WHERE kcu.TABLE_SCHEMA = 'nizamifarms_db' 
AND kcu.TABLE_NAME = 't_req_master' 
AND kcu.REFERENCED_TABLE_NAME IS NOT NULL;

SELECT '' as '';
SELECT 'Checking newly added columns:' as Info;
SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_req_master' 
AND COLUMN_NAME IN ('settled_by', 'settlement_transaction_id', 'settlement_destination_account_id');



