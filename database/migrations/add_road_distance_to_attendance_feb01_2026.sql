-- =====================================================
-- Migration: Add road_distance column to t_ops_attendance
-- Created: Feb 01, 2026
-- Purpose: Store calculated road distance for daily travel
--          This is calculated automatically on checkout
-- =====================================================

-- Step 1: Add road_distance column
-- =====================================================
SELECT 'Adding road_distance column to t_ops_attendance...' as Status;

ALTER TABLE t_ops_attendance
ADD COLUMN road_distance_km DECIMAL(8, 2) NULL COMMENT 'Road distance calculated via OpenRouteService (km)' 
    AFTER checkout_location_captured_at,
ADD COLUMN road_distance_source VARCHAR(30) NULL COMMENT 'Source: openrouteservice, calculated, insufficient_data, skipped_stationary' 
    AFTER road_distance_km,
ADD COLUMN road_distance_calculated_at TIMESTAMP NULL COMMENT 'When road distance was calculated'
    AFTER road_distance_source,
ADD COLUMN gps_straight_distance_km DECIMAL(8, 2) NULL COMMENT 'Straight-line GPS distance for comparison (km)'
    AFTER road_distance_calculated_at,
ADD COLUMN gps_readings_used INT UNSIGNED NULL COMMENT 'Number of GPS readings used for calculation'
    AFTER gps_straight_distance_km;

SELECT 'Road distance columns added successfully' as Status;

-- Step 2: Add index for efficient queries
-- =====================================================
SELECT 'Adding index...' as Status;

ALTER TABLE t_ops_attendance
ADD INDEX idx_road_distance (road_distance_km);

SELECT 'Index added successfully' as Status;

-- Step 3: Verification
-- =====================================================
SELECT '' as '';
SELECT '============================================' as '';
SELECT 'Road Distance Migration Complete!' as Status;
SELECT '============================================' as '';

SELECT 
  COLUMN_NAME as 'Column',
  COLUMN_TYPE as 'Type',
  COLUMN_COMMENT as 'Description'
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 't_ops_attendance'
  AND COLUMN_NAME LIKE '%road%' OR COLUMN_NAME LIKE '%gps_straight%' OR COLUMN_NAME LIKE '%gps_readings%'
ORDER BY ORDINAL_POSITION;

SELECT '' as '';
SELECT '✓ Road distance will be calculated automatically on checkout' as Note;
SELECT '✓ Can also be calculated manually via Calculate button' as Note;
SELECT '✓ road_distance_km shown as primary indicator when available' as Note;
