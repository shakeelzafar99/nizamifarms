-- Fix existing partial invoices that have settled_amount > 0 but status is still 'open'
-- This script will update invoices that have been partially paid but weren't marked as 'partial'

-- Preview what will be updated (run this first to verify)
SELECT 
    id,
    transaction_type,
    description,
    amount,
    settled_amount,
    settlement_status AS current_status,
    'partial' AS new_status,
    transaction_date,
    created_at
FROM t_fin_ledger
WHERE transaction_type = 'invoice'
  AND settlement_status = 'open'
  AND settled_amount > 0
  AND settled_amount < amount
  AND approval_status != 'reversed'
ORDER BY transaction_date DESC;

-- If the preview looks correct, run this update:
UPDATE t_fin_ledger
SET settlement_status = 'partial'
WHERE transaction_type = 'invoice'
  AND settlement_status = 'open'
  AND settled_amount > 0
  AND settled_amount < amount
  AND approval_status != 'reversed';

-- Verify the update
SELECT 
    COUNT(*) as updated_count,
    'Invoices updated to partial status' as description
FROM t_fin_ledger
WHERE transaction_type = 'invoice'
  AND settlement_status = 'partial'
  AND settled_amount > 0
  AND settled_amount < amount;

-- Show the specific invoice that was just updated (NF-14556)
SELECT 
    l.id,
    o.order_number,
    l.description,
    l.amount,
    l.settled_amount,
    l.settlement_status,
    l.transaction_date,
    a.account_name as rider_account
FROM t_fin_ledger l
LEFT JOIN t_crm_orders o ON l.order_id = o.id
LEFT JOIN t_fin_accounts a ON l.to_account_id = a.id
WHERE l.transaction_type = 'invoice'
  AND l.settlement_status = 'partial'
  AND l.settled_amount > 0
ORDER BY l.transaction_date DESC
LIMIT 10;

