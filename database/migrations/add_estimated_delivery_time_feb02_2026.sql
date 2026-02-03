-- =============================================
-- Estimated Delivery Time Feature
-- Created: 2026-02-02
-- Purpose: Store predicted delivery times for orders
--          to compare against actual delivery times
-- =============================================

-- ⭐ Add estimated_delivery_at column to orders table
-- This stores the predicted delivery time calculated when "Get Times" is pressed
ALTER TABLE t_crm_prod_order 
ADD COLUMN estimated_delivery_at DATETIME NULL 
COMMENT 'Predicted delivery time (set when ETA is requested, fixed once set)';

-- ⭐ Add eta_calculated_at to track when estimate was made
ALTER TABLE t_crm_prod_order 
ADD COLUMN eta_calculated_at DATETIME NULL 
COMMENT 'When the ETA was calculated (for audit trail)';

-- ⭐ Index for efficient queries
CREATE INDEX idx_order_eta ON t_crm_prod_order(estimated_delivery_at);

-- ⭐ Verify the changes
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 't_crm_prod_order' 
AND COLUMN_NAME IN ('estimated_delivery_at', 'eta_calculated_at');

SELECT 'Estimated delivery time columns added successfully!' as status;
