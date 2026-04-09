-- ============================================================================
-- Migration: Move Qurbani Fields from Order Level to Line Item Level
-- Date: April 7, 2026
-- Purpose: Each line item in an order can have its own qurbani day/slot/region/type
-- Run these one at a time on MySQL Workbench.
-- ============================================================================

-- STEP 1: Add qurbani columns to t_crm_prod_order_line_item
-- If column already exists you'll get a harmless error.

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_day` VARCHAR(50) NULL 
    COMMENT 'Qurbani day assignment: Day 1, Day 2, Day 3';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_slot` VARCHAR(50) NULL 
    COMMENT 'Qurbani time slot: Afternoon, Evening';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_region` VARCHAR(100) NULL 
    COMMENT 'Qurbani delivery region';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_delivery_type` VARCHAR(50) NULL 
    COMMENT 'Delivery or Self Collection';

-- STEP 2: Migrate existing order-level values to line items
-- This copies the order-level qurbani fields to all line items of that order
UPDATE `t_crm_prod_order_line_item` li
    INNER JOIN `t_crm_prod_order` o ON li.order_id = o.id
SET 
    li.qurbani_day = o.qurbani_day,
    li.qurbani_slot = o.qurbani_slot,
    li.qurbani_region = o.qurbani_region,
    li.qurbani_delivery_type = o.qurbani_delivery_type
WHERE o.qurbani_day IS NOT NULL 
   OR o.qurbani_slot IS NOT NULL 
   OR o.qurbani_region IS NOT NULL 
   OR o.qurbani_delivery_type IS NOT NULL;

-- STEP 3: Index for filtering
CREATE INDEX idx_li_qurbani_day ON t_crm_prod_order_line_item (qurbani_day);
CREATE INDEX idx_li_qurbani_region ON t_crm_prod_order_line_item (qurbani_region);

-- NOTE: We keep the order-level columns for backward compatibility and efficient filtering.
-- The order-level columns will be synced from line items when orders are created/updated.
-- This way, filtering qurbani orders by day/region can still use the order table indexes.
