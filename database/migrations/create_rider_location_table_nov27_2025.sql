-- =====================================================
-- CREATE RIDER LOCATION TRACKING TABLE
-- Date: November 27, 2025
-- Purpose: Store periodic GPS location heartbeats from riders
-- =====================================================

-- Check if table already exists
SELECT 'Checking if t_ops_rider_location table exists...' as Status;

-- Create rider location table if it doesn't exist
CREATE TABLE IF NOT EXISTS t_ops_rider_location (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL COMMENT 'FK to t_sys_user (rider)',
  latitude DECIMAL(10, 8) NOT NULL COMMENT 'GPS latitude',
  longitude DECIMAL(11, 8) NOT NULL COMMENT 'GPS longitude',
  accuracy FLOAT NULL COMMENT 'GPS accuracy in meters',
  captured_at DATETIME NOT NULL COMMENT 'When the GPS fix was taken',
  source VARCHAR(20) DEFAULT 'heartbeat' COMMENT 'heartbeat, checkin, checkout, delivery, manual',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  INDEX idx_user_captured (user_id, captured_at DESC),
  INDEX idx_captured_at (captured_at),
  
  FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores periodic GPS location heartbeats from riders for tracking';

SELECT '✓ Rider location table created/verified' as Status;

-- Verification
SELECT '' as '';
SELECT '============================================' as '';
SELECT 'RIDER LOCATION TABLE READY!' as Status;
SELECT '============================================' as '';
SELECT '' as '';

SELECT 'Table Structure:' as Info;
SELECT 
  COLUMN_NAME as 'Column',
  COLUMN_TYPE as 'Type',
  COLUMN_COMMENT as 'Description'
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_ops_rider_location'
ORDER BY ORDINAL_POSITION;

SELECT '' as '';
SELECT '✓ Location heartbeats will be stored every 5 minutes' as Note;
SELECT '✓ Data older than 20 days should be cleaned up periodically' as Note;
SELECT '✓ Used for Riders Map feature in web app' as Note;

