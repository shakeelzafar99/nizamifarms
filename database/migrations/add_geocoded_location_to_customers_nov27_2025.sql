-- =====================================================
-- ADD GEOCODED LOCATION COLUMNS TO CUSTOMERS
-- Date: November 27, 2025
-- Purpose: Store auto-geocoded coordinates separately from verified location
-- =====================================================

-- Verified location (latitude, longitude) = manually set by rider (high accuracy)
-- Geocoded location = auto-generated from address text (approximate)

SELECT 'Adding geocoded location columns to t_crm_prod_customer...' as Status;

-- Add geocoded location columns (separate from verified lat/long)
ALTER TABLE t_crm_prod_customer
ADD COLUMN geocoded_latitude DECIMAL(10, 8) NULL COMMENT 'Auto-geocoded latitude from address' AFTER longitude,
ADD COLUMN geocoded_longitude DECIMAL(11, 8) NULL COMMENT 'Auto-geocoded longitude from address' AFTER geocoded_latitude,
ADD COLUMN geocoded_at TIMESTAMP NULL COMMENT 'When the address was geocoded' AFTER geocoded_longitude;

-- Add index for geocoded coordinates
CREATE INDEX idx_customer_geocoded_coords ON t_crm_prod_customer (geocoded_latitude, geocoded_longitude);

SELECT '✓ Geocoded location columns added' as Status;

-- Verification
SELECT '' as '';
SELECT '============================================' as '';
SELECT 'GEOCODED LOCATION COLUMNS READY!' as Status;
SELECT '============================================' as '';
SELECT '' as '';

SELECT 'New columns added:' as Info;
SELECT '  - geocoded_latitude: Auto-geocoded from address (approximate)' as Column1;
SELECT '  - geocoded_longitude: Auto-geocoded from address (approximate)' as Column2;
SELECT '  - geocoded_at: Timestamp when geocoding was done' as Column3;
SELECT '' as '';
SELECT '✓ latitude/longitude remain for VERIFIED locations (high accuracy)' as Note;
SELECT '✓ geocoded_latitude/longitude for AUTO-GEOCODED from address (approximate)' as Note;

