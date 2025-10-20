-- Check if the deposit has the expense_request_id in metadata

SELECT 
    'Latest Short Cash Deposit' as '';

SELECT 
    id,
    transaction_date,
    description,
    amount,
    approval_status,
    settlement_metadata
FROM t_fin_ledger
WHERE transaction_type = 'employee_deposit'
AND description LIKE '%Short Cash%'
ORDER BY created_at DESC
LIMIT 3;

-- Check the settlement metadata in detail
SELECT 
    'Metadata Check' as '';

SELECT 
    id,
    amount,
    JSON_EXTRACT(settlement_metadata, '$.is_short_cash_settlement') as is_short_cash,
    JSON_EXTRACT(settlement_metadata, '$.deposit_amount') as deposit_amt,
    JSON_EXTRACT(settlement_metadata, '$.short_cash_amount') as shortage_amt,
    JSON_EXTRACT(settlement_metadata, '$.expense_request_id') as expense_req_id,
    JSON_EXTRACT(settlement_metadata, '$.expense_category') as expense_cat,
    JSON_EXTRACT(settlement_metadata, '$.invoice_ids') as invoice_ids
FROM t_fin_ledger
WHERE transaction_type = 'employee_deposit'
AND description LIKE '%Short Cash%'
ORDER BY created_at DESC
LIMIT 1;

