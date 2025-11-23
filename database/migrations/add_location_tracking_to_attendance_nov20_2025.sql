-- =====================================================
-- ADD LOCATION TRACKING TO ATTENDANCE
-- Date: November 20, 2025
-- Purpose: Track GPS location for attendance check-in/check-out
-- Base Office: 33.7081159745921, 73.08868749610448
-- =====================================================

-- Step 1: Create company locations table
-- =====================================================
CREATE TABLE IF NOT EXISTS t_ops_company_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_name VARCHAR(100) NOT NULL COMMENT 'Office, Warehouse, etc.',
  latitude DECIMAL(10, 8) NOT NULL,
  longitude DECIMAL(11, 8) NOT NULL,
  radius_meters INT NOT NULL DEFAULT 2000 COMMENT 'Allowed radius in meters (default 2km)',
  is_primary BOOLEAN DEFAULT 0 COMMENT 'Primary location for attendance',
  is_active BOOLEAN DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_primary (is_primary),
  INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Company locations for attendance tracking';

SELECT 'Company locations table created' as Status;

-- Step 2: Add location tracking columns to attendance
-- =====================================================
SELECT 'Adding location columns to t_ops_attendance...' as Status;

ALTER TABLE t_ops_attendance
ADD COLUMN checkin_latitude DECIMAL(10, 8) NULL COMMENT 'GPS latitude at check-in' AFTER logout_time,
ADD COLUMN checkin_longitude DECIMAL(11, 8) NULL COMMENT 'GPS longitude at check-in' AFTER checkin_latitude,
ADD COLUMN checkin_accuracy FLOAT NULL COMMENT 'GPS accuracy in meters' AFTER checkin_longitude,
ADD COLUMN checkin_distance_from_base INT NULL COMMENT 'Distance from base location (meters)' AFTER checkin_accuracy,
ADD COLUMN checkin_location_captured_at TIMESTAMP NULL COMMENT 'When GPS was captured' AFTER checkin_distance_from_base,

ADD COLUMN checkout_latitude DECIMAL(10, 8) NULL COMMENT 'GPS latitude at check-out' AFTER checkin_location_captured_at,
ADD COLUMN checkout_longitude DECIMAL(11, 8) NULL COMMENT 'GPS longitude at check-out' AFTER checkout_latitude,
ADD COLUMN checkout_accuracy FLOAT NULL COMMENT 'GPS accuracy in meters' AFTER checkout_longitude,
ADD COLUMN checkout_location_captured_at TIMESTAMP NULL COMMENT 'When GPS was captured' AFTER checkout_accuracy,

ADD COLUMN is_remote_checkin BOOLEAN DEFAULT 0 COMMENT '1 if checkin >2km from base' AFTER checkout_location_captured_at;

SELECT 'Location columns added successfully' as Status;

-- Step 3: Add indexes for performance
-- =====================================================
SELECT 'Adding indexes...' as Status;

ALTER TABLE t_ops_attendance
ADD INDEX idx_remote_checkin (is_remote_checkin),
ADD INDEX idx_checkin_location (checkin_latitude, checkin_longitude),
ADD INDEX idx_checkout_location (checkout_latitude, checkout_longitude);

SELECT 'Indexes added successfully' as Status;

-- Step 4: Insert base office location
-- =====================================================
SELECT 'Inserting base office location...' as Status;

INSERT INTO t_ops_company_locations 
  (location_name, latitude, longitude, radius_meters, is_primary, is_active)
VALUES 
  ('Nizami Farms Office', 33.70811597, 73.08868750, 2000, 1, 1);

SELECT 'Base office location inserted' as Status;

-- Step 5: Verification
-- =====================================================
SELECT '' as '';
SELECT '============================================' as '';
SELECT 'LOCATION TRACKING SETUP COMPLETE!' as Status;
SELECT '============================================' as '';
SELECT '' as '';

SELECT 'Base Office Location:' as Info;
SELECT 
  location_name as 'Location',
  latitude as 'Latitude',
  longitude as 'Longitude',
  CONCAT(radius_meters, ' meters (', ROUND(radius_meters/1000, 1), ' km)') as 'Radius'
FROM t_ops_company_locations 
WHERE is_primary = 1;

SELECT '' as '';
SELECT 'New Attendance Columns:' as Info;
SELECT 
  COLUMN_NAME as 'Column',
  COLUMN_TYPE as 'Type',
  COLUMN_COMMENT as 'Description'
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 't_ops_attendance'
  AND COLUMN_NAME LIKE '%checkin%' 
   OR COLUMN_NAME LIKE '%checkout%'
   OR COLUMN_NAME = 'is_remote_checkin'
ORDER BY ORDINAL_POSITION;

SELECT '' as '';
SELECT '✓ Ready to use!' as Status;
SELECT '✓ Mobile app will capture location on check-in/check-out' as Note;
SELECT '✓ Check-in distance flagged if >2km from office' as Note;
SELECT '✓ Check-out location captured for audit (no distance check)' as Note;

