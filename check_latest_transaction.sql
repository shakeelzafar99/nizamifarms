USE nizamifarms_db;

-- Check the most recent transaction
SELECT 
    id,
    transaction_date,
    transaction_type,
    description,
    amount,
    bill_image,
    created_at
FROM t_fin_ledger
WHERE transaction_type = 'vendor_purchase'
ORDER BY created_at DESC
LIMIT 1;

-- Check if the column exists and its type
SHOW COLUMNS FROM t_fin_ledger LIKE 'bill_image';


