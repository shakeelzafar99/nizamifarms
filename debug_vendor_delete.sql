-- Debug script to check vendor account setup for transaction deletion
-- Run this to see what's happening with the vendor account

-- Check the transaction details
SELECT 
    l.id as transaction_id,
    l.transaction_type,
    l.transaction_date,
    l.amount,
    l.from_account_id,
    l.to_account_id,
    fa_from.account_name as from_account_name,
    fa_from.account_category as from_account_category,
    fa_to.account_name as to_account_name,
    fa_to.account_category as to_account_category
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts fa_from ON l.from_account_id = fa_from.id
LEFT JOIN t_fin_accounts fa_to ON l.to_account_id = fa_to.id
WHERE l.transaction_type IN ('vendor_purchase', 'vendor_payment')
ORDER BY l.created_at DESC
LIMIT 10;

-- Check if vendor accounts exist
SELECT 
    v.id as vendor_id,
    v.vendor_name,
    v.account_id,
    a.id as account_table_id,
    a.account_name,
    a.account_category,
    a.current_balance
FROM t_fin_vendors v
LEFT JOIN t_fin_accounts a ON v.account_id = a.id
WHERE v.is_active = 1;

-- Check for transactions with missing accounts
SELECT 
    l.id,
    l.transaction_type,
    l.to_account_id,
    CASE 
        WHEN a.id IS NULL THEN 'ACCOUNT MISSING'
        ELSE 'Account exists'
    END as account_status
FROM t_fin_ledger l
LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
WHERE l.transaction_type IN ('vendor_purchase', 'vendor_payment')
AND a.id IS NULL;

