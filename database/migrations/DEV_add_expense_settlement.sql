-- ============================================
-- DEV Environment: Add Expense Settlement Support
-- ============================================
-- This script adds all necessary columns and enums for the expense settlement feature
-- Run this on your DEV database

USE nizamifarms;

-- Step 1: Add settlement columns to t_req_master
ALTER TABLE t_req_master
ADD COLUMN IF NOT EXISTS settlement_status ENUM('not_required', 'pending', 'settled') DEFAULT 'not_required' 
    COMMENT 'Settlement status for expenses paid from temporary sources' AFTER status,
ADD COLUMN IF NOT EXISTS settled_at DATETIME NULL 
    COMMENT 'When the expense was settled' AFTER settlement_status,
ADD COLUMN IF NOT EXISTS settled_by INT(11) NULL 
    COMMENT 'User who settled the expense' AFTER settled_at,
ADD COLUMN IF NOT EXISTS settlement_transaction_id INT(11) NULL 
    COMMENT 'Reference to the settlement ledger transaction' AFTER settled_by,
ADD COLUMN IF NOT EXISTS settlement_destination_account_id INT(11) NULL 
    COMMENT 'Account that received the settlement (usually NF Cash)' AFTER settlement_transaction_id,
ADD COLUMN IF NOT EXISTS settlement_notes TEXT NULL 
    COMMENT 'Notes about the settlement' AFTER settlement_destination_account_id;

-- Step 2: Add foreign key constraints for new columns
ALTER TABLE t_req_master
ADD CONSTRAINT fk_req_master_settled_by 
    FOREIGN KEY (settled_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_req_master_settlement_transaction 
    FOREIGN KEY (settlement_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_req_master_settlement_destination_account 
    FOREIGN KEY (settlement_destination_account_id) REFERENCES t_fin_accounts(id) ON DELETE SET NULL;

-- Step 3: Modify t_fin_ledger transaction_type enum to include 'expense_settlement'
ALTER TABLE t_fin_ledger 
MODIFY COLUMN transaction_type ENUM(
    'invoice',
    'deposit', 
    'expense',
    'transfer',
    'adjustment',
    'opening_balance',
    'expense_settlement'
) NOT NULL DEFAULT 'invoice';

-- Step 4: Add index for better query performance
CREATE INDEX IF NOT EXISTS idx_settlement_status ON t_req_master(settlement_status);
CREATE INDEX IF NOT EXISTS idx_settled_at ON t_req_master(settled_at);

-- Verification queries
SELECT 'Settlement columns added successfully!' as Status;

SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE
FROM 
    INFORMATION_SCHEMA.COLUMNS
WHERE 
    TABLE_SCHEMA = 'nizamifarms'
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
    ORDINAL_POSITION;

