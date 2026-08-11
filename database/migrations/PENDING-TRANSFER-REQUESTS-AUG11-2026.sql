-- =====================================================
-- WAREHOUSE TRANSFER REQUESTS (store/manager asks, warehouse ships)
-- Created: August 11, 2026
-- Run on: LOCAL first, then PROD. Run BEFORE uploading the web files.
-- =====================================================
--
-- WHY A SEPARATE TABLE (do not "simplify" this into a new status on
-- t_crm_warehouse_transfer):
--
--   On t_crm_warehouse_transfer, status='pending' already means THE STOCK HAS
--   PHYSICALLY LEFT THE WAREHOUSE — initiateTransfer() debits
--   t_crm_warehouse_inventory immediately and writes a warehouse-ledger row, so a
--   pending transfer is "in transit, in neither stock layer". Every consumer
--   depends on that: the products-page "In transit" row, the Combined Total
--   exclusion, WarehouseTransferModel::pendingToStoreQty(), the batched push in
--   FirebaseService::flushDueTransferAlerts(), and all five approve surfaces.
--
--   A REQUEST has moved nothing. Adding a 'requested' value to that ENUM would
--   make every one of those places silently wrong. Worse, the existing reject
--   path RETURNS stock to the warehouse (change_type='adjustment',
--   reference_type='transfer_rejected') — routing a declined request through it
--   would create inventory out of thin air. Declining a row in THIS table cannot
--   touch stock, by construction.
--
-- FLOW:
--   1. Manager (web products page) or store staff (mobile store mode) creates a
--      request. Nothing moves. status='pending'.
--   2. Only ONE pending request may exist per product per BU — a second "Request"
--      on the same product EDITS the existing row (quantity/notes). Enforced by
--      the app (an upsert) and by uq_wtr_one_pending below.
--   3. Warehouse incharge accepts, optionally with a DIFFERENT quantity
--      (accepted_quantity). That runs the normal transfer initiation: warehouse
--      debited, t_crm_warehouse_transfer row created 'pending', link kept in
--      transfer_id. From there the existing store-side accept flow is unchanged.
--   4. Or declines with a reason — no inventory or ledger write at all.
--   5. Or the requester cancels while still pending.
--
-- =====================================================

CREATE TABLE IF NOT EXISTS t_crm_warehouse_transfer_request (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Product Reference
  product_id            BIGINT UNSIGNED NOT NULL,              -- FK to t_crm_prod_product
  product_variant_id    BIGINT UNSIGNED NULL,                  -- FK to t_crm_prod_product_variant

  -- Business Unit (Khaas/Frozen = code 'KHAAS')
  business_unit_id      INT NOT NULL,

  -- What is being asked for (latest value after any edit)
  quantity              INT NOT NULL,
  status                ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
  notes                 TEXT NULL,

  -- Who asked. Stays the ORIGINAL creator across edits.
  requested_by          BIGINT NOT NULL,

  -- Acceptance. accepted_quantity is what was actually shipped and may be lower
  -- (or higher) than `quantity` — the ask and the fulfilment are both kept so the
  -- audit trail reads "asked 30, shipped 25".
  accepted_by           BIGINT NULL,
  accepted_at           DATETIME NULL,
  accepted_quantity     INT NULL,
  transfer_id           BIGINT UNSIGNED NULL,                  -- t_crm_warehouse_transfer.id created on accept

  -- Decline
  declined_by           BIGINT NULL,
  declined_at           DATETIME NULL,
  decline_reason        TEXT NULL,

  -- Cancellation by the requester
  cancelled_by          BIGINT NULL,
  cancelled_at          DATETIME NULL,

  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_wtr_product (product_id),
  INDEX idx_wtr_bu      (business_unit_id),
  INDEX idx_wtr_status  (status),
  INDEX idx_wtr_date    (created_at),
  INDEX idx_wtr_transfer (transfer_id),

  -- ⭐ "One pending request per product per BU" enforced in the DATABASE, not just
  -- in the app. pending_key is a generated column that equals product_id while the
  -- row is pending and NULL otherwise; MySQL unique indexes ignore NULLs, so any
  -- number of accepted/declined/cancelled rows may coexist for the same product
  -- while at most one pending row can exist. This makes a double-submit (manager
  -- double-clicks, or web + mobile at the same instant) a clean DB error instead
  -- of two competing requests.
  pending_key           BIGINT UNSIGNED AS (CASE WHEN status = 'pending' THEN product_id ELSE NULL END) STORED,
  UNIQUE KEY uq_wtr_one_pending (business_unit_id, pending_key),

  CONSTRAINT fk_wtr_product FOREIGN KEY (product_id)
    REFERENCES t_crm_prod_product(id) ON DELETE CASCADE,
  CONSTRAINT fk_wtr_variant FOREIGN KEY (product_variant_id)
    REFERENCES t_crm_prod_product_variant(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE ON USER COLUMNS: requested_by / accepted_by / declined_by / cancelled_by
-- deliberately have NO foreign keys, matching requested_by / approved_by /
-- rejected_by / counted_by on t_crm_warehouse_transfer. Don't "improve" these into
-- FKs without changing the sibling table too.

-- Verify:
--   SHOW CREATE TABLE t_crm_warehouse_transfer_request;
--   SELECT COUNT(*) FROM t_crm_warehouse_transfer_request;   -- expect 0
