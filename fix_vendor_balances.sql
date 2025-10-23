-- Fix Vendor Account Balances
-- This script recalculates all vendor account balances from scratch based on approved transactions
-- Run this after any manual database changes or if balances seem incorrect

-- Backup current balances (optional, for reference)
SELECT 
    a.id,
    a.account_name,
    a.current_balance as old_balance,
    a.opening_balance,
    COALESCE(SUM(CASE 
        WHEN l.transaction_type = 'vendor_purchase' THEN l.amount 
        ELSE 0 
    END), 0) as total_purchases,
    COALESCE(SUM(CASE 
        WHEN l.transaction_type = 'vendor_payment' THEN l.amount 
        ELSE 0 
    END), 0) as total_payments,
    a.opening_balance + 
    COALESCE(SUM(CASE 
        WHEN l.transaction_type = 'vendor_purchase' THEN l.amount 
        WHEN l.transaction_type = 'vendor_payment' THEN -l.amount 
        ELSE 0 
    END), 0) as new_balance
FROM t_fin_accounts a
LEFT JOIN t_fin_ledger l ON l.to_account_id = a.id 
    AND l.approval_status = 'approved'
    AND l.transaction_type IN ('vendor_purchase', 'vendor_payment')
WHERE a.account_category IN ('vendor', 'vendor_payable')
GROUP BY a.id, a.account_name, a.current_balance, a.opening_balance;

-- Update all vendor account balances
UPDATE t_fin_accounts a
SET current_balance = (
    a.opening_balance + 
    COALESCE((
        SELECT SUM(CASE 
            WHEN l.transaction_type = 'vendor_purchase' THEN l.amount 
            WHEN l.transaction_type = 'vendor_payment' THEN -l.amount 
            ELSE 0 
        END)
        FROM t_fin_ledger l
        WHERE l.to_account_id = a.id 
            AND l.approval_status = 'approved'
            AND l.transaction_type IN ('vendor_purchase', 'vendor_payment')
    ), 0)
)
WHERE a.account_category IN ('vendor', 'vendor_payable');

-- Verify the update
SELECT 
    a.id,
    a.account_name,
    a.current_balance,
    a.opening_balance,
    COALESCE(SUM(CASE 
        WHEN l.transaction_type = 'vendor_purchase' THEN l.amount 
        ELSE 0 
    END), 0) as total_purchases,
    COALESCE(SUM(CASE 
        WHEN l.transaction_type = 'vendor_payment' THEN l.amount 
        ELSE 0 
    END), 0) as total_payments
FROM t_fin_accounts a
LEFT JOIN t_fin_ledger l ON l.to_account_id = a.id 
    AND l.approval_status = 'approved'
    AND l.transaction_type IN ('vendor_purchase', 'vendor_payment')
WHERE a.account_category IN ('vendor', 'vendor_payable')
GROUP BY a.id, a.account_name, a.current_balance, a.opening_balance
ORDER BY a.account_name;
