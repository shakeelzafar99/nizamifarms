-- Add tracking columns for verified location
-- Date: October 28, 2025

-- Check if verified_location_saved_by column exists
SELECT COUNT(*) INTO @saved_by_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_customer'
  AND COLUMN_NAME = 'verified_location_saved_by';

-- Add verified_location_saved_by column if it doesn't exist
SET @sql1 = IF(@saved_by_exists = 0,
    'ALTER TABLE t_crm_prod_customer 
     ADD COLUMN verified_location_saved_by INT NULL 
     COMMENT ''User ID who saved/updated the verified location'' 
     AFTER verified_location_url',
    'SELECT ''Column verified_location_saved_by already exists'' AS message');

PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- Check if verified_location_saved_at column exists
SELECT COUNT(*) INTO @saved_at_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_customer'
  AND COLUMN_NAME = 'verified_location_saved_at';

-- Add verified_location_saved_at column if it doesn't exist
SET @sql2 = IF(@saved_at_exists = 0,
    'ALTER TABLE t_crm_prod_customer 
     ADD COLUMN verified_location_saved_at TIMESTAMP NULL 
     COMMENT ''When the verified location was last saved/updated'' 
     AFTER verified_location_saved_by',
    'SELECT ''Column verified_location_saved_at already exists'' AS message');

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Verify the changes
SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_customer'
  AND COLUMN_NAME IN ('verified_location_url', 'verified_location_saved_by', 'verified_location_saved_at')
ORDER BY ORDINAL_POSITION;

