-- Migration: Add is_free flag to order line items
-- Date: Feb 24, 2026
-- Purpose: Allow marking individual line items as "free" (complimentary) on invoices.
--          The original unit_price is preserved for display; line_total is set to 0 for free items.
--          Default is 0 (not free), so all existing orders are unaffected.

-- Step 1: Add the is_free column
ALTER TABLE t_crm_prod_order_line_item
ADD COLUMN is_free TINYINT(1) NOT NULL DEFAULT 0 AFTER inventory_deducted;

-- Verify
SELECT 'Migration complete: is_free column added to t_crm_prod_order_line_item' AS status;
SELECT COUNT(*) AS total_line_items, SUM(is_free) AS free_items FROM t_crm_prod_order_line_item;
