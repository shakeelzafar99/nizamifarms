-- ========================================
-- TEST DATA CLEANUP SCRIPT
-- ========================================
-- Purpose: Clean all transactional data while preserving:
--   - Accounts (t_fin_accounts)
--   - Vendors (t_fin_vendors)
--   - Users (t_sys_user)
--   - Products, Customers, etc.
--
-- This script will DELETE:
--   ✓ All ledger transactions (t_fin_ledger)
--   ✓ All requests (t_req_master)
--   ✓ All salary slips (t_hr_salary_slips)
--   ✓ Invoice settlement tracking (t_fin_invoice_settlements)
--   ✓ Ledger adjustments (t_fin_ledger_adjustments)
--   ✓ Vendor purchase items (t_fin_vendor_purchase_items)
--   ✓ Action items (t_fin_action_items)
--
-- ⚠️ WARNING: This will permanently delete all financial transaction data!
-- ⚠️ Make sure you have a backup before running this script!
-- ========================================

USE nizamifarms_db;

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

SELECT '========================================' as '';
SELECT 'STARTING TEST DATA CLEANUP' as Status;
SELECT '========================================' as '';
SELECT '' as '';

-- ========================================
-- STEP 1: BACKUP CURRENT COUNTS
-- ========================================
SELECT 'Current Data Counts (BEFORE CLEANUP):' as '';
SELECT CONCAT('  • Ledger Transactions: ', COUNT(*)) as '' FROM t_fin_ledger;
SELECT CONCAT('  • Requests: ', COUNT(*)) as '' FROM t_req_master;
SELECT CONCAT('  • Salary Slips: ', COUNT(*)) as '' FROM t_hr_salary_slips WHERE 1;
SELECT CONCAT('  • Invoice Settlements: ', COUNT(*)) as '' FROM t_fin_invoice_settlements WHERE 1;
SELECT CONCAT('  • Ledger Adjustments: ', COUNT(*)) as '' FROM t_fin_ledger_adjustments WHERE 1;
SELECT CONCAT('  • Vendor Purchase Items: ', COUNT(*)) as '' FROM t_fin_vendor_purchase_items WHERE 1;
SELECT CONCAT('  • Action Items: ', COUNT(*)) as '' FROM t_fin_action_items WHERE 1;
SELECT '' as '';

-- ========================================
-- STEP 2: DELETE TRANSACTIONAL DATA
-- ========================================

-- 1. Delete Invoice Settlement Tracking
SELECT '🗑️  Deleting invoice settlement tracking...' as Status;
DELETE FROM t_fin_invoice_settlements;
SELECT CONCAT('✓ Deleted ', ROW_COUNT(), ' invoice settlement records') as Status;

-- 2. Delete Ledger Adjustments
SELECT '🗑️  Deleting ledger adjustments...' as Status;
DELETE FROM t_fin_ledger_adjustments;
SELECT CONCAT('✓ Deleted ', ROW_COUNT(), ' ledger adjustment records') as Status;

-- 3. Delete Vendor Purchase Items
SELECT '🗑️  Deleting vendor purchase items...' as Status;
DELETE FROM t_fin_vendor_purchase_items;
SELECT CONCAT('✓ Deleted ', ROW_COUNT(), ' vendor purchase item records') as Status;

-- 4. Delete Action Items
SELECT '🗑️  Deleting action items...' as Status;
DELETE FROM t_fin_action_items;
SELECT CONCAT('✓ Deleted ', ROW_COUNT(), ' action item records') as Status;

-- 5. Delete Salary Slips
SELECT '🗑️  Deleting salary slips...' as Status;
DELETE FROM t_hr_salary_slips;
SELECT CONCAT('✓ Deleted ', ROW_COUNT(), ' salary slip records') as Status;

-- 6. Delete All Requests (expenses, salary advances, leave, etc.)
SELECT '🗑️  Deleting all requests...' as Status;
DELETE FROM t_req_master;
SELECT CONCAT('✓ Deleted ', ROW_COUNT(), ' request records') as Status;

-- 7. Delete All Ledger Transactions
SELECT '🗑️  Deleting all ledger transactions...' as Status;
DELETE FROM t_fin_ledger;
SELECT CONCAT('✓ Deleted ', ROW_COUNT(), ' ledger transaction records') as Status;

SELECT '' as '';

-- ========================================
-- STEP 3: RESET ACCOUNT BALANCES
-- ========================================
SELECT '💰 Resetting account balances to opening balances...' as Status;

UPDATE t_fin_accounts 
SET current_balance = opening_balance;

SELECT CONCAT('✓ Reset ', ROW_COUNT(), ' account balances') as Status;
SELECT '' as '';

-- ========================================
-- STEP 4: RESET AUTO_INCREMENT VALUES
-- ========================================
SELECT '🔄 Resetting auto-increment values...' as Status;

ALTER TABLE t_fin_ledger AUTO_INCREMENT = 1;
SELECT '✓ Reset t_fin_ledger auto-increment' as Status;

ALTER TABLE t_req_master AUTO_INCREMENT = 1;
SELECT '✓ Reset t_req_master auto-increment' as Status;

ALTER TABLE t_hr_salary_slips AUTO_INCREMENT = 1;
SELECT '✓ Reset t_hr_salary_slips auto-increment' as Status;

ALTER TABLE t_fin_invoice_settlements AUTO_INCREMENT = 1;
SELECT '✓ Reset t_fin_invoice_settlements auto-increment' as Status;

ALTER TABLE t_fin_ledger_adjustments AUTO_INCREMENT = 1;
SELECT '✓ Reset t_fin_ledger_adjustments auto-increment' as Status;

ALTER TABLE t_fin_vendor_purchase_items AUTO_INCREMENT = 1;
SELECT '✓ Reset t_fin_vendor_purchase_items auto-increment' as Status;

ALTER TABLE t_fin_action_items AUTO_INCREMENT = 1;
SELECT '✓ Reset t_fin_action_items auto-increment' as Status;

SELECT '' as '';

-- ========================================
-- STEP 5: VERIFY CLEANUP
-- ========================================
SELECT 'Data Counts (AFTER CLEANUP):' as '';
SELECT CONCAT('  • Ledger Transactions: ', COUNT(*)) as '' FROM t_fin_ledger;
SELECT CONCAT('  • Requests: ', COUNT(*)) as '' FROM t_req_master;
SELECT CONCAT('  • Salary Slips: ', COUNT(*)) as '' FROM t_hr_salary_slips;
SELECT CONCAT('  • Invoice Settlements: ', COUNT(*)) as '' FROM t_fin_invoice_settlements;
SELECT CONCAT('  • Ledger Adjustments: ', COUNT(*)) as '' FROM t_fin_ledger_adjustments;
SELECT CONCAT('  • Vendor Purchase Items: ', COUNT(*)) as '' FROM t_fin_vendor_purchase_items;
SELECT CONCAT('  • Action Items: ', COUNT(*)) as '' FROM t_fin_action_items;
SELECT '' as '';

-- ========================================
-- STEP 6: VERIFY PRESERVED DATA
-- ========================================
SELECT 'Preserved Data (UNCHANGED):' as '';
SELECT CONCAT('  • Accounts: ', COUNT(*)) as '' FROM t_fin_accounts;
SELECT CONCAT('  • Vendors: ', COUNT(*)) as '' FROM t_fin_vendors;
SELECT CONCAT('  • Vendor Products: ', COUNT(*)) as '' FROM t_fin_vendor_products;
SELECT CONCAT('  • Users: ', COUNT(*)) as '' FROM t_sys_user;
SELECT CONCAT('  • Customers: ', COUNT(*)) as '' FROM t_crm_customer;
SELECT CONCAT('  • Products: ', COUNT(*)) as '' FROM t_crm_product;
SELECT CONCAT('  • Orders: ', COUNT(*)) as '' FROM t_crm_prod_order;
SELECT '' as '';

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

SELECT '========================================' as '';
SELECT '✅ CLEANUP COMPLETE!' as Status;
SELECT '========================================' as '';
SELECT '' as '';
SELECT '📋 Summary:' as '';
SELECT '  ✓ All transactional data deleted' as '';
SELECT '  ✓ Account balances reset to opening balances' as '';
SELECT '  ✓ Auto-increment values reset to 1' as '';
SELECT '  ✓ Accounts, Vendors, Users, Products preserved' as '';
SELECT '' as '';
SELECT '⚠️  Note: You can now test the Short Cash feature with clean data!' as '';
SELECT '' as '';

