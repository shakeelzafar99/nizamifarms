-- Add default_product flag to vendor products table
-- Run Date: November 10, 2025
-- Purpose: Allow vendors to have a default product that auto-loads in purchase forms

-- Add is_default column to vendor products
ALTER TABLE t_fin_vendor_products 
ADD COLUMN is_default TINYINT(1) DEFAULT 0 AFTER is_active;

-- Add index for faster lookups
CREATE INDEX idx_vendor_default_product ON t_fin_vendor_products(vendor_id, is_default);

-- Ensure only one default product per vendor (optional constraint)
-- Note: This will be enforced in application logic to allow flexibility

SELECT 'Migration completed successfully!' as status;

