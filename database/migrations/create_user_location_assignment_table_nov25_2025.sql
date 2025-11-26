-- =====================================================
-- CREATE USER LOCATION ASSIGNMENT TABLE
-- Date: November 25, 2025
-- Purpose: Assign users to specific office locations for attendance tracking
-- =====================================================

-- Check if table already exists
SELECT 'Checking if t_ops_user_location_assignment table exists...' as Status;

-- Create user-location assignment table if it doesn't exist
CREATE TABLE IF NOT EXISTS t_ops_user_location_assignment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL COMMENT 'User ID',
  location_id INT NOT NULL COMMENT 'Company location ID',
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT NULL COMMENT 'Who assigned this user',
  is_active BOOLEAN DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  UNIQUE KEY unique_user_location (user_id, location_id),
  FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES t_ops_company_locations(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
  
  INDEX idx_user (user_id),
  INDEX idx_location (location_id),
  INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Assigns users to specific office locations for attendance tracking';

SELECT '✓ User-location assignment table created/verified' as Status;

-- Verification
SELECT '' as '';
SELECT '============================================' as '';
SELECT 'USER LOCATION ASSIGNMENT TABLE READY!' as Status;
SELECT '============================================' as '';
SELECT '' as '';

SELECT 'Table Structure:' as Info;
SELECT 
  COLUMN_NAME as 'Column',
  COLUMN_TYPE as 'Type',
  COLUMN_COMMENT as 'Description'
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_ops_user_location_assignment'
ORDER BY ORDINAL_POSITION;

SELECT '' as '';
SELECT '✓ Users can now be assigned to specific office locations' as Note;
SELECT '✓ If no assignment exists, user checks in against primary location' as Note;
SELECT '✓ This allows different teams to work from different offices' as Note;

