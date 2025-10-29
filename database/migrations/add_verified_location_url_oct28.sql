-- Add verified_location_url column to store Google Maps links
-- Date: October 28, 2025

-- Check if column exists before adding
SELECT COUNT(*) INTO @column_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_customer'
  AND COLUMN_NAME = 'verified_location_url';

-- Add column if it doesn't exist
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE t_crm_prod_customer 
     ADD COLUMN verified_location_url VARCHAR(500) NULL 
     COMMENT ''Google Maps link for verified location'' 
     AFTER longitude',
    'SELECT ''Column verified_location_url already exists'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify the change
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_customer'
  AND COLUMN_NAME IN ('latitude', 'longitude', 'verified_location_url')
ORDER BY ORDINAL_POSITION;

