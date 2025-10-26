-- Add 'reversed' status to ledger approval_status enum
-- Run this SQL script to update the database

-- Check current enum values
SELECT COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_ledger' 
  AND COLUMN_NAME = 'approval_status';

-- Add 'reversed' to the enum
ALTER TABLE t_fin_ledger 
MODIFY COLUMN approval_status ENUM('pending', 'approved', 'rejected', 'reversed') 
DEFAULT 'pending';

-- Verify the change
SELECT COLUMN_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_fin_ledger' 
  AND COLUMN_NAME = 'approval_status';

