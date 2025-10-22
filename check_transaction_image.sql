USE nizamifarms_db;

-- Check the most recent vendor purchase transactions
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
LIMIT 5;

-- Check if any transactions have bill images
SELECT 
    COUNT(*) as total_transactions,
    COUNT(bill_image) as transactions_with_images,
    COUNT(CASE WHEN bill_image IS NOT NULL AND bill_image != '' THEN 1 END) as non_empty_images
FROM t_fin_ledger
WHERE transaction_type = 'vendor_purchase';


