-- Check the latest deposit and its metadata

SELECT 
    'Latest Employee Deposits' as Section;

SELECT 
    id,
    transaction_date,
    description,
    amount,
    approval_status,
    created_at
FROM t_fin_ledger
WHERE transaction_type = 'employee_deposit'
ORDER BY created_at DESC
LIMIT 5;

-- Check for Short Cash specific deposits
SELECT 
    'Short Cash Deposits' as Section;

SELECT 
    id,
    transaction_date,
    description,
    amount,
    approval_status,
    settlement_metadata,
    created_at
FROM t_fin_ledger
WHERE transaction_type = 'employee_deposit'
AND (description LIKE '%Short Cash%' OR settlement_metadata IS NOT NULL)
ORDER BY created_at DESC
LIMIT 3;

-- Check the metadata in detail
SELECT 
    'Metadata Breakdown' as Section;

SELECT 
    id,
    amount,
    JSON_EXTRACT(settlement_metadata, '$.is_short_cash_settlement') as is_short_cash,
    JSON_EXTRACT(settlement_metadata, '$.deposit_amount') as deposit_amt,
    JSON_EXTRACT(settlement_metadata, '$.short_cash_amount') as shortage_amt,
    JSON_EXTRACT(settlement_metadata, '$.expense_request_id') as expense_req_id,
    JSON_EXTRACT(settlement_metadata, '$.expense_category') as expense_cat
FROM t_fin_ledger
WHERE transaction_type = 'employee_deposit'
AND settlement_metadata IS NOT NULL
ORDER BY created_at DESC
LIMIT 3;

-- Check expense requests that match short cash
SELECT 
    'Matching Expense Requests' as Section;

SELECT 
    rm.request_number,
    rm.title,
    rm.amount,
    rm.status,
    rm.settlement_status,
    rm.created_at,
    rm.updated_at
FROM t_req_master rm
JOIN t_req_category rc ON rm.category_id = rc.id
WHERE rc.category_code = 'expense'
AND rm.description LIKE 'Short cash from invoice%'
ORDER BY rm.created_at DESC
LIMIT 5;

