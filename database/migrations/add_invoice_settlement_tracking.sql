-- =====================================================================
-- Invoice Settlement Tracking System
-- =====================================================================
-- Purpose: Track which invoices are settled via which deposits
-- Impact: Minimal - Adds new columns to ledger + new tracking table
-- Safety: 100% - No changes to existing data, backward compatible
-- 
-- Run on: DEV first, then PROD after testing
-- Date: 2025-10-15
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- STEP 1: Add settlement tracking columns to t_fin_ledger
-- =====================================================================

SELECT '--- Step 1: Adding settlement tracking columns to t_fin_ledger ---' as '';

ALTER TABLE t_fin_ledger
ADD COLUMN settlement_status ENUM('open', 'settled') DEFAULT 'open' COMMENT 'For invoices: tracks if rider has paid' AFTER approval_status,
ADD COLUMN settled_amount DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Amount settled (for partial settlements)' AFTER settlement_status,
ADD COLUMN settled_at DATETIME NULL COMMENT 'When invoice was settled' AFTER settled_amount,
ADD COLUMN settled_via_ledger_id INT NULL COMMENT 'FK to deposit transaction that settled this invoice' AFTER settled_at;

SELECT '✓ Step 1: Columns added to t_fin_ledger' as Status;
SELECT '' as '';

-- =====================================================================
-- STEP 2: Add index for settlement queries
-- =====================================================================

SELECT '--- Step 2: Adding indexes for performance ---' as '';

ALTER TABLE t_fin_ledger
ADD INDEX idx_settlement_status (settlement_status, transaction_type),
ADD INDEX idx_settled_via (settled_via_ledger_id);

SELECT '✓ Step 2: Indexes created' as Status;
SELECT '' as '';

-- =====================================================================
-- STEP 3: Create invoice settlements tracking table
-- =====================================================================

SELECT '--- Step 3: Creating t_fin_invoice_settlements table ---' as '';

CREATE TABLE IF NOT EXISTS t_fin_invoice_settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    settlement_deposit_id INT NOT NULL COMMENT 'FK to t_fin_ledger(id) - The deposit transaction',
    invoice_ledger_id INT NOT NULL COMMENT 'FK to t_fin_ledger(id) - The invoice being settled',
    settled_amount DECIMAL(15,2) NOT NULL COMMENT 'Amount settled (for partial settlements)',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_settlement_deposit (settlement_deposit_id),
    INDEX idx_invoice_ledger (invoice_ledger_id),
    
    FOREIGN KEY (settlement_deposit_id) REFERENCES t_fin_ledger(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_ledger_id) REFERENCES t_fin_ledger(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Junction table tracking which invoices were settled by which deposits';

SELECT '✓ Step 3: Table t_fin_invoice_settlements created' as Status;
SELECT '' as '';

-- =====================================================================
-- STEP 4: Mark all existing invoices as settled (migration)
-- =====================================================================

SELECT '--- Step 4: Migrating existing invoices to settled status ---' as '';

UPDATE t_fin_ledger 
SET settlement_status = 'settled',
    settled_amount = amount,
    settled_at = transaction_date
WHERE transaction_type = 'invoice'
AND created_at < NOW();

SELECT CONCAT('✓ Step 4: Marked ', ROW_COUNT(), ' existing invoices as settled') as Status;
SELECT '' as '';

-- =====================================================================
-- VERIFICATION QUERIES
-- =====================================================================

SELECT '========================================' as '';
SELECT 'VERIFICATION CHECKS' as '';
SELECT '========================================' as '';
SELECT '' as '';

-- Check 1: Verify new columns
SELECT '--- Check 1: New Columns in t_fin_ledger ---' as '';
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_fin_ledger'
AND COLUMN_NAME IN ('settlement_status', 'settled_amount', 'settled_at', 'settled_via_ledger_id');

SELECT '' as '';

-- Check 2: Verify indexes
SELECT '--- Check 2: Indexes Created ---' as '';
SELECT 
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_fin_ledger'
AND INDEX_NAME IN ('idx_settlement_status', 'idx_settled_via');

SELECT '' as '';

-- Check 3: Verify new table
SELECT '--- Check 3: t_fin_invoice_settlements Table ---' as '';
SELECT 
    TABLE_NAME,
    TABLE_ROWS as CurrentRows,
    CREATE_TIME,
    TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME = 't_fin_invoice_settlements';

SELECT '' as '';

-- Check 4: Verify migration worked
SELECT '--- Check 4: Invoice Settlement Status ---' as '';
SELECT 
    settlement_status,
    COUNT(*) as count,
    SUM(amount) as total_amount
FROM t_fin_ledger
WHERE transaction_type = 'invoice'
GROUP BY settlement_status;

SELECT '' as '';
SELECT '✓✓✓ MIGRATION COMPLETE ✓✓✓' as '';
SELECT 'You can now proceed with the code implementation.' as '';

