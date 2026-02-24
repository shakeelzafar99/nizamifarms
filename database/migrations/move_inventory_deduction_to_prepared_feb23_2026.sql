-- =========================================================================
-- Migration: Move inventory deduction from order creation to mark-as-prepared
-- Date: 2026-02-23
--
-- WHAT THIS CHANGES:
-- Before: Inventory was deducted when an order was created (storeOrderFromApi)
-- After:  Inventory is deducted when line items are marked as "preparing"
--         or when the order moves to "out_for_delivery" (auto-prepares remaining items)
--
-- The inventory_deducted flag prevents double deduction and ensures
-- cancellation only restores inventory that was actually deducted.
-- =========================================================================

-- 1. Add inventory_deducted flag to line items table
-- 0 = not yet deducted, 1 = inventory has been deducted for this line item
ALTER TABLE t_crm_prod_order_line_item 
ADD COLUMN inventory_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER preparation_status;

-- 2. Backfill: Mark existing webapp/internal order line items as inventory_deducted=1
-- These orders had inventory deducted under the OLD system (on order creation),
-- so we flag them properly for the new cancellation logic to work correctly.
-- Without this, cancelling an old order would NOT restore inventory (because
-- inventory_deducted=0), even though inventory WAS deducted when it was created.
UPDATE t_crm_prod_order_line_item li
JOIN t_crm_prod_order o ON li.order_id = o.id
SET li.inventory_deducted = 1
WHERE (o.external_source = 'webapp' OR o.external_source IS NULL OR o.external_source = '')
AND o.order_status NOT IN ('cancelled', 'refunded');

-- 3. Add an index for quick lookups on inventory_deducted
ALTER TABLE t_crm_prod_order_line_item 
ADD INDEX idx_inventory_deducted (inventory_deducted);

-- Done!
-- After running this migration, deploy the updated PHP code.
-- The new flow:
--   Order created → inventory NOT deducted (inventory_deducted=0)
--   Item marked as prepared → inventory deducted (inventory_deducted=1)
--   Order → out_for_delivery → unprepared items auto-prepared + deducted
--   Order cancelled → only items with inventory_deducted=1 get restored
--   Order edited → deducted items restored, re-deducted if still prepared
