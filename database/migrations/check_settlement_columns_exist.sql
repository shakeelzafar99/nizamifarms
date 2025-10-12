-- Check if settlement columns exist in t_req_master table
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE
FROM 
    INFORMATION_SCHEMA.COLUMNS
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 't_req_master'
    AND COLUMN_NAME IN (
        'settlement_status',
        'settled_at',
        'settled_by',
        'settlement_transaction_id',
        'settlement_destination_account_id',
        'settlement_notes'
    )
ORDER BY 
    COLUMN_NAME;

-- Check if expense_settlement is in t_fin_ledger transaction_type enum
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE
FROM 
    INFORMATION_SCHEMA.COLUMNS
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 't_fin_ledger'
    AND COLUMN_NAME = 'transaction_type';

