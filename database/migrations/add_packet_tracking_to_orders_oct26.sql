-- Add packet tracking columns to orders table
-- October 26, 2025
-- 
-- Purpose: Allow managers to enter expected number of packets for an order,
-- and riders to enter actual number of packets delivered for verification purposes.
-- This is an optional field and does not affect ledger or delivery flow.

-- Check if columns already exist before adding them
SET @dbname = DATABASE();
SET @tablename = 't_crm_prod_order';

-- Add expected_packets column (entered by manager/admin)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname 
    AND TABLE_NAME = @tablename 
    AND COLUMN_NAME = 'expected_packets');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `t_crm_prod_order` ADD COLUMN `expected_packets` INT UNSIGNED NULL DEFAULT NULL COMMENT ''Number of packets expected (entered by manager/admin)'' AFTER `note`',
    'SELECT ''Column expected_packets already exists'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add actual_packets column (entered by rider on delivery)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname 
    AND TABLE_NAME = @tablename 
    AND COLUMN_NAME = 'actual_packets');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `t_crm_prod_order` ADD COLUMN `actual_packets` INT UNSIGNED NULL DEFAULT NULL COMMENT ''Number of packets actually delivered (entered by rider)'' AFTER `expected_packets`',
    'SELECT ''Column actual_packets already exists'' AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Show the new columns
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT, 
    COLUMN_COMMENT
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME = 't_crm_prod_order'
AND COLUMN_NAME IN ('expected_packets', 'actual_packets');

SELECT '✅ Packet tracking columns added successfully!' AS status;

