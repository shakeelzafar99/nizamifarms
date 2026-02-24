-- =====================================================
-- WAREHOUSE TRANSFER TABLE
-- Created: February 9, 2026
-- =====================================================
-- 
-- This SQL adds the warehouse transfer table to support
-- inventory transfers between warehouse and store with
-- an approval workflow.
--
-- FLOW:
-- 1. User initiates transfer (warehouse → store)
-- 2. Warehouse qty decreases IMMEDIATELY
-- 3. Transfer record created with status='pending'
-- 4. Admin/Taimur approves → store qty increases
-- 5. Admin/Taimur rejects → warehouse qty restored
--
-- =====================================================

-- STEP 1: Create warehouse transfer table
CREATE TABLE IF NOT EXISTS t_crm_warehouse_transfer (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  
  -- Product Reference
  product_id            BIGINT UNSIGNED NOT NULL,              -- FK to t_crm_prod_product
  product_variant_id    BIGINT UNSIGNED NULL,                  -- FK to t_crm_prod_product_variant
  
  -- Business Unit
  business_unit_id      INT NOT NULL,                          -- FK to t_fin_business_units
  
  -- Transfer Details
  from_location         ENUM('warehouse', 'store') NOT NULL DEFAULT 'warehouse',
  to_location           ENUM('warehouse', 'store') NOT NULL DEFAULT 'store',
  quantity              INT NOT NULL,                          -- Number of units being transferred
  
  -- Approval Workflow
  status                ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  requested_by          BIGINT NOT NULL,                       -- FK to t_sys_user (who initiated)
  
  -- Approval
  approved_by           BIGINT NULL,                           -- FK to t_sys_user (who approved)
  approved_at           DATETIME NULL,
  
  -- Rejection
  rejected_by           BIGINT NULL,                           -- FK to t_sys_user (who rejected)
  rejected_at           DATETIME NULL,
  rejection_reason      TEXT NULL,
  
  -- Notes
  notes                 TEXT NULL,
  
  -- Audit
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  
  -- Indexes
  INDEX idx_wt_product (product_id),
  INDEX idx_wt_variant (product_variant_id),
  INDEX idx_wt_bu (business_unit_id),
  INDEX idx_wt_status (status),
  INDEX idx_wt_requested_by (requested_by),
  INDEX idx_wt_date (created_at),
  
  -- Foreign Keys
  CONSTRAINT fk_wt_product FOREIGN KEY (product_id) REFERENCES t_crm_prod_product(id) ON DELETE CASCADE,
  CONSTRAINT fk_wt_variant FOREIGN KEY (product_variant_id) REFERENCES t_crm_prod_product_variant(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks inventory transfers between warehouse and store with approval workflow. Warehouse qty decreases immediately on initiation; store qty increases only after admin approval.';

SELECT '✅ Created t_crm_warehouse_transfer table' as Status;


-- =====================================================
-- VERIFICATION
-- =====================================================

SELECT '
=====================================
✅ WAREHOUSE TRANSFER TABLE CREATED
=====================================

TRANSFER WORKFLOW:
┌─────────────────────────────────────────────────────┐
│  1. USER initiates transfer (warehouse → store)     │
│     → Warehouse qty DECREASES immediately           │
│     → Transfer record: status = "pending"           │
│                                                     │
│  2. ADMIN/TAIMUR reviews pending transfers          │
│     → APPROVE: Store qty INCREASES                  │
│       (product variant inventory_quantity += qty)    │
│     → REJECT: Warehouse qty RESTORED                │
│       (warehouse_inventory.quantity += qty back)     │
│                                                     │
│  3. In Product Card (Khaas Mode):                   │
│     🏪 Store: 20        🏭 Warehouse: 50           │
│     ⏳ +10 pending                                  │
│     [📦 Stock In]  [↔️ Transfer to Store]           │
└─────────────────────────────────────────────────────┘

=====================================
' as Summary;

-- Show table structure
DESCRIBE t_crm_warehouse_transfer;
