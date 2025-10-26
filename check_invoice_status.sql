-- Check the current status of invoice NF-14556
SELECT 
    l.id,
    o.order_number,
    l.transaction_type,
    l.amount,
    l.settled_amount,
    l.settlement_status,
    l.approval_status,
    l.transaction_date,
    l.settled_at,
    l.created_at,
    l.updated_at
FROM t_fin_ledger l
LEFT JOIN t_crm_orders o ON l.order_id = o.id
WHERE l.transaction_type = 'invoice'
  AND o.order_number = 'NF-14556'
ORDER BY l.created_at DESC;

-- Check the deposit transactions for this invoice
SELECT 
    l.id,
    l.transaction_type,
    l.description,
    l.amount,
    l.approval_status,
    l.settlement_metadata,
    l.created_at,
    l.updated_at,
    l.approval_date,
    l.approved_by
FROM t_fin_ledger l
WHERE l.transaction_type = 'employee_deposit'
  AND l.description LIKE '%NF-14556%'
ORDER BY l.created_at DESC;

-- Check if the settlement was processed
SELECT 
    *
FROM t_fin_invoice_settlement
WHERE invoice_ledger_id IN (
    SELECT l.id
    FROM t_fin_ledger l
    LEFT JOIN t_crm_orders o ON l.order_id = o.id
    WHERE l.transaction_type = 'invoice'
      AND o.order_number = 'NF-14556'
)
ORDER BY created_at DESC;

