-- =====================================================
-- Migration: Add Customer Type (regular / shop)
-- Date: June 2026
-- Purpose: Distinguish "shop" customers from "regular" customers.
--          Shop customers settle their ONLINE invoices via incremental
--          payments (like Qurbani) instead of a single full-invoice
--          approval. Everything defaults to 'regular'; users flip select
--          customers to 'shop' later.
--
-- NOTE: Run ONCE on production. Plain ALTER/CREATE statements (no SET /
--       PREPARE / stored-procedure syntax) so they work on any standard
--       MySQL host (incl. stackcp / phpMyAdmin). If a statement was already
--       applied, MySQL will report a harmless "Duplicate column"/"Duplicate
--       key" error — skip that statement and continue.
-- =====================================================

-- 1) Add the column (defaults every existing + future row to 'regular')
ALTER TABLE t_crm_prod_customer
  ADD COLUMN customer_type VARCHAR(20) NOT NULL DEFAULT 'regular'
  COMMENT 'Customer type: regular or shop' AFTER company;

-- 2) Index for filtering the shop queue efficiently
CREATE INDEX idx_customer_type ON t_crm_prod_customer (customer_type);

-- =====================================================
-- Verification:
-- SELECT customer_type, COUNT(*) FROM t_crm_prod_customer GROUP BY customer_type;
-- =====================================================
