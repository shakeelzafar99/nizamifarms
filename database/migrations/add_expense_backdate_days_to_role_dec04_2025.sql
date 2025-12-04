-- =====================================================
-- Add Expense Backdate Days Setting to Role
-- Date: December 4, 2025
-- Purpose: Allow role-based control of expense date backdating
-- =====================================================

-- Add expense_backdate_days column to t_sys_role
-- Default 0 = current date only
-- 4 = up to 4 days back allowed
-- 10 = up to 10 days back allowed
ALTER TABLE t_sys_role 
ADD COLUMN IF NOT EXISTS expense_backdate_days INT DEFAULT 0 
COMMENT 'Number of days allowed for backdating expense entries (0 = current date only)';

-- Add expense_date column to t_req_master (correct table name!)
-- This stores the actual date of the expense
-- NEVER NULL - defaults to current date if not backdated
ALTER TABLE t_req_master
ADD COLUMN IF NOT EXISTS expense_date DATE NULL
COMMENT 'The actual date of the expense (defaults to created_at date)';

-- Set expense_date to created_at date for all existing records
UPDATE t_req_master 
SET expense_date = DATE(created_at) 
WHERE expense_date IS NULL;

-- =====================================================
-- Verification Queries
-- =====================================================

-- Check the columns were added
DESCRIBE t_sys_role;
DESCRIBE t_req_master;

-- Check current values
SELECT id, urole_name, expense_backdate_days FROM t_sys_role;

-- Verify expense_date is populated
SELECT id, request_number, expense_date, created_at FROM t_req_master LIMIT 10;

-- =====================================================
-- Example: Allow Admin role (id=1) to backdate up to 10 days
-- =====================================================
-- UPDATE t_sys_role SET expense_backdate_days = 10 WHERE id = 1;

-- =====================================================
-- Rollback (if needed)
-- =====================================================
-- ALTER TABLE t_sys_role DROP COLUMN expense_backdate_days;
-- ALTER TABLE t_req_master DROP COLUMN expense_date;

