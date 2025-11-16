-- =====================================================
-- Migration: Add Multi-Level Approval Statuses
-- Purpose: Extend approval_status ENUM to support pending_l1 and pending_l2
-- Date: 2025-11-15
-- =====================================================

USE nizamifarms;

-- Step 1: Alter t_fin_ledger to add new approval statuses
SELECT '--- Step 1: Updating t_fin_ledger approval_status ENUM ---' as '';

ALTER TABLE t_fin_ledger 
MODIFY COLUMN approval_status ENUM('pending', 'pending_l1', 'pending_l2', 'approved', 'rejected', 'reversed') DEFAULT 'approved'
COMMENT 'pending/pending_l1 = L1 approval needed, pending_l2 = L2 approval needed';

-- Step 2: Update existing 'pending' records to 'pending_l1' for clarity (optional, can be skipped if you want legacy to stay as 'pending')
SELECT '--- Step 2: Updating existing pending records to pending_l1 (optional) ---' as '';

-- Uncomment the line below if you want to migrate all existing 'pending' to 'pending_l1'
-- UPDATE t_fin_ledger SET approval_status = 'pending_l1' WHERE approval_status = 'pending';

SELECT '--- Migration Complete ---' as '';
SELECT 'approval_status column now supports: pending, pending_l1, pending_l2, approved, rejected, reversed' as 'Status';

