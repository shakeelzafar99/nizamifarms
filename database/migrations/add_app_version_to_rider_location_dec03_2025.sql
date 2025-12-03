-- =====================================================
-- ADD APP VERSION TO USER TABLE (not rider_location)
-- Date: December 3, 2025
-- Purpose: Track mobile app version per user (updated on login)
-- =====================================================

-- Add app_version column to users table (updated on each login)
ALTER TABLE t_sys_user
ADD COLUMN IF NOT EXISTS app_version VARCHAR(20) NULL COMMENT 'Mobile app version (e.g., 2.0.3)'
AFTER is_active;

-- Add app_version_updated_at to track when version was last updated
ALTER TABLE t_sys_user
ADD COLUMN IF NOT EXISTS app_version_updated_at TIMESTAMP NULL COMMENT 'When app version was last updated'
AFTER app_version;

-- Verification
SELECT 'Checking columns were added to t_sys_user...' as Status;

SELECT 
  COLUMN_NAME as 'Column',
  COLUMN_TYPE as 'Type',
  COLUMN_COMMENT as 'Description'
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_sys_user'
  AND COLUMN_NAME IN ('app_version', 'app_version_updated_at');

SELECT '✓ app_version and app_version_updated_at columns added to t_sys_user' as Status;

