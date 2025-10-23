-- Fix Vendor Account Balances
-- This script recalculates the current_balance for all vendor accounts
-- based on opening_balance + purchases - payments

-- Backup current balances (optional, for safety)
-- SELECT id, account_name, account_code, opening_balance, current_balance 
-- FROM t_fin_accounts 
-- WHERE account_category = 'vendor';

-- Recalculate vendor balances
UPDATE t_fin_accounts a
SET current_balance = (
    -- Start with opening balance
    COALESCE(a.opening_balance, 0)
    
    -- Add all purchases (money we owe to vendor)
    + COALESCE((
        SELECT SUM(amount)
        FROM t_fin_ledger
        WHERE to_account_id = a.id
        AND transaction_type = 'vendor_purchase'
        AND approval_status = 'approved'
    ), 0)
    
    -- Subtract all payments (money we paid to vendor)
    - COALESCE((
        SELECT SUM(amount)
        FROM t_fin_ledger
        WHERE to_account_id = a.id
        AND transaction_type = 'vendor_payment'
        AND approval_status = 'approved'
    ), 0)
)
WHERE account_category = 'vendor';

-- Verify the results
SELECT 
    v.vendor_name,
    a.account_name,
    a.opening_balance,
    COALESCE((
        SELECT SUM(amount)
        FROM t_fin_ledger
        WHERE to_account_id = a.id
        AND transaction_type = 'vendor_purchase'
        AND approval_status = 'approved'
    ), 0) as total_purchases,
    COALESCE((
        SELECT SUM(amount)
        FROM t_fin_ledger
        WHERE to_account_id = a.id
        AND transaction_type = 'vendor_payment'
        AND approval_status = 'approved'
    ), 0) as total_payments,
    a.current_balance as new_balance,
    (a.opening_balance + 
     COALESCE((SELECT SUM(amount) FROM t_fin_ledger WHERE to_account_id = a.id AND transaction_type = 'vendor_purchase' AND approval_status = 'approved'), 0) -
     COALESCE((SELECT SUM(amount) FROM t_fin_ledger WHERE to_account_id = a.id AND transaction_type = 'vendor_payment' AND approval_status = 'approved'), 0)
    ) as calculated_balance
FROM t_fin_vendors v
JOIN t_fin_accounts a ON v.account_id = a.id
WHERE v.is_active = 1
ORDER BY v.vendor_name;

-- Expected result for your vendor (Ghousa Beef) Haji Nadeem:
-- Opening: 0
-- Purchases: 237,100 (38,250 + 52,000 + 52,000 + 94,850)
-- Payments: 45,000
-- Balance should be: 192,100 (not 237,100)


