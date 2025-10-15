-- =====================================================================
-- Ledger Adjustments - Track Invoice Changes After Delivery
-- =====================================================================
-- Purpose: When a delivered order's invoice is modified, create an 
--          adjustment request that requires L1→L2 approval before 
--          updating the ledger.
-- Safety: 100% - Non-destructive, adds new features only
-- Date: 2025-10-15
-- =====================================================================

USE nizamifarms_db;

-- =====================================================================
-- STEP 1: Create t_fin_ledger_adjustments table
-- =====================================================================

CREATE TABLE IF NOT EXISTS t_fin_ledger_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Links to existing records
    ledger_id INT NOT NULL COMMENT 'FK to t_fin_ledger.id (the original invoice entry)',
    order_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to t_crm_prod_order.id',
    
    -- Amount details
    old_amount DECIMAL(15,2) NOT NULL COMMENT 'Original ledger amount',
    new_amount DECIMAL(15,2) NOT NULL COMMENT 'Proposed new amount',
    adjustment_amount DECIMAL(15,2) NOT NULL COMMENT 'Difference (new - old)',
    reason TEXT NULL COMMENT 'Reason for adjustment',
    
    -- Overall status
    adjustment_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    
    -- L1 Approval
    requires_level_1 BOOLEAN DEFAULT TRUE COMMENT 'Does this adjustment require Level 1 approval?',
    level_1_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    level_1_approved_by INT NULL COMMENT 'FK to t_sys_user.id',
    level_1_approved_at TIMESTAMP NULL,
    level_1_comments TEXT NULL,
    
    -- L2 Approval
    requires_level_2 BOOLEAN DEFAULT TRUE COMMENT 'Does this adjustment require Level 2 approval?',
    level_2_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    level_2_approved_by INT NULL COMMENT 'FK to t_sys_user.id',
    level_2_approved_at TIMESTAMP NULL,
    level_2_comments TEXT NULL,
    
    -- Metadata
    requested_by INT NOT NULL COMMENT 'FK to t_sys_user.id - User who modified the order',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finalized_at TIMESTAMP NULL COMMENT 'When adjustment was approved/rejected',
    
    -- Standard timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_adjustment_status (adjustment_status),
    INDEX idx_ledger_id (ledger_id),
    INDEX idx_order_id (order_id),
    INDEX idx_level_1_status (level_1_status),
    INDEX idx_level_2_status (level_2_status),
    INDEX idx_requested_by (requested_by),
    INDEX idx_requested_at (requested_at)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks ledger adjustments for orders modified after delivery. Requires L1→L2 approval.';

SELECT '✓ Step 1: Created t_fin_ledger_adjustments table' as Status;

-- =====================================================================
-- STEP 2: Add Foreign Key Constraints
-- =====================================================================
-- Note: Adding FKs separately to ensure table exists first and to handle
--       dependencies correctly

-- FK to t_fin_ledger
ALTER TABLE t_fin_ledger_adjustments
ADD CONSTRAINT fk_ledger_adj_ledger
FOREIGN KEY (ledger_id) REFERENCES t_fin_ledger(id) ON DELETE CASCADE;

SELECT '✓ Step 2a: Added FK: t_fin_ledger_adjustments.ledger_id -> t_fin_ledger.id' as Status;

-- FK to t_crm_prod_order (BIGINT UNSIGNED to match)
ALTER TABLE t_fin_ledger_adjustments
ADD CONSTRAINT fk_ledger_adj_order
FOREIGN KEY (order_id) REFERENCES t_crm_prod_order(id) ON DELETE CASCADE;

SELECT '✓ Step 2b: Added FK: t_fin_ledger_adjustments.order_id -> t_crm_prod_order.id' as Status;

-- FK to t_sys_user (requested_by)
ALTER TABLE t_fin_ledger_adjustments
ADD CONSTRAINT fk_ledger_adj_requested_by
FOREIGN KEY (requested_by) REFERENCES t_sys_user(id) ON DELETE CASCADE;

SELECT '✓ Step 2c: Added FK: t_fin_ledger_adjustments.requested_by -> t_sys_user.id' as Status;

-- FK to t_sys_user (level_1_approved_by)
ALTER TABLE t_fin_ledger_adjustments
ADD CONSTRAINT fk_ledger_adj_l1_approver
FOREIGN KEY (level_1_approved_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT '✓ Step 2d: Added FK: t_fin_ledger_adjustments.level_1_approved_by -> t_sys_user.id' as Status;

-- FK to t_sys_user (level_2_approved_by)
ALTER TABLE t_fin_ledger_adjustments
ADD CONSTRAINT fk_ledger_adj_l2_approver
FOREIGN KEY (level_2_approved_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;

SELECT '✓ Step 2e: Added FK: t_fin_ledger_adjustments.level_2_approved_by -> t_sys_user.id' as Status;

-- =====================================================================
-- STEP 3: Add Invoice Adjustment Category
-- =====================================================================
-- Add a new category for invoice adjustments in the approval system

INSERT INTO t_req_category (category_code, category_name, description, is_active, created_at, updated_at)
VALUES 
    ('invoice_adjustment', 'Invoice Adjustment', 'Invoice amount adjustments after delivery', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    category_name = 'Invoice Adjustment',
    description = 'Invoice amount adjustments after delivery',
    is_active = 1;

SELECT '✓ Step 3a: Added category: invoice_adjustment' as Status;

-- =====================================================================
-- STEP 4: Configure Approval Levels for Invoice Adjustment
-- =====================================================================
-- Set to require both L1 and L2 approval by default

INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at, updated_at)
SELECT 
    id,
    1 as requires_level_1,
    1 as requires_level_2,
    NULL as auto_approve_threshold,
    NOW(),
    NOW()
FROM t_req_category
WHERE category_code = 'invoice_adjustment'
ON DUPLICATE KEY UPDATE
    requires_level_1 = 1,
    requires_level_2 = 1;

SELECT '✓ Step 4: Configured approval levels for invoice_adjustment (L1 + L2 required)' as Status;

-- =====================================================================
-- VERIFICATION QUERIES
-- =====================================================================

SELECT '--- Verification: t_fin_ledger_adjustments table structure ---' as '';
DESCRIBE t_fin_ledger_adjustments;

SELECT '--- Verification: Foreign Key Constraints ---' as '';
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME = 't_fin_ledger_adjustments'
AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY CONSTRAINT_NAME;

SELECT '--- Verification: Invoice Adjustment Category ---' as '';
SELECT 
    c.id,
    c.category_code,
    c.category_name,
    c.is_active,
    cfg.requires_level_1,
    cfg.requires_level_2,
    cfg.auto_approve_threshold
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
WHERE c.category_code = 'invoice_adjustment';

SELECT '--- Verification: Indexes ---' as '';
SHOW INDEX FROM t_fin_ledger_adjustments;

-- =====================================================================
-- SUMMARY
-- =====================================================================

SELECT '========================================' as '';
SELECT '✓✓✓ LEDGER ADJUSTMENTS TABLE CREATED! ✓✓✓' as '';
SELECT '========================================' as '';
SELECT 'Table: t_fin_ledger_adjustments' as '';
SELECT 'Foreign Keys: 5 (ledger, order, 3x user)' as '';
SELECT 'Category: invoice_adjustment' as '';
SELECT 'Approval: L1 + L2 required (configurable in settings)' as '';
SELECT 'Status: Ready for use' as '';
SELECT '========================================' as '';

-- =====================================================================
-- NEXT STEPS
-- =====================================================================
-- 1. Assign permission to roles that should approve adjustments
-- 2. Deploy LedgerAdjustmentModel (app/Models/FIN/LedgerAdjustmentModel.php)
-- 3. Update OrderController::update() to detect changes
-- 4. Update ApprovalController to show pending adjustments
-- 5. Test with a delivered order modification
-- =====================================================================

