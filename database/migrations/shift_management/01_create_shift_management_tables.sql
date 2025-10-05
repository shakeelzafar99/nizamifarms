-- ============================================================================
-- Shift Management System - Table Creation
-- Run this first in both DEV and PROD
-- ============================================================================

-- Table 1: Shift Templates
-- Stores reusable shift configurations (e.g., "Rider Shift 1", "Manager Shift")
CREATE TABLE IF NOT EXISTS t_ops_shift_template (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_name VARCHAR(100) NOT NULL COMMENT 'Display name (e.g., "Rider Shift 1")',
    shift_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unique code (e.g., "rider_shift_1")',
    shift_start TIME NOT NULL DEFAULT '09:00:00' COMMENT 'Shift start time',
    shift_end TIME NOT NULL DEFAULT '17:00:00' COMMENT 'Shift end time',
    working_days JSON NOT NULL COMMENT 'Array of working days: [1,2,3,4,5,6,7] where 1=Mon, 7=Sun',
    is_default TINYINT(1) DEFAULT 0 COMMENT '1 = default shift for users without assignment',
    description TEXT NULL COMMENT 'Optional notes about this shift',
    active TINYINT(1) DEFAULT 1 COMMENT '1 = active, 0 = inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    INDEX idx_active (active),
    INDEX idx_default (is_default),
    INDEX idx_code (shift_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Shift template definitions with working hours and days';

-- Table 2: User Shift Assignments
-- Maps users to shift templates (overrides role-based assignments)
CREATE TABLE IF NOT EXISTS t_ops_user_shift_assignment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shift_template_id INT NOT NULL,
    effective_from DATE NULL COMMENT 'Optional: When this shift assignment starts',
    effective_to DATE NULL COMMENT 'Optional: When this shift assignment ends',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_template_id) REFERENCES t_ops_shift_template(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_shift (user_id, shift_template_id),
    INDEX idx_user (user_id),
    INDEX idx_shift (shift_template_id),
    INDEX idx_effective (effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Maps users to shift templates with effective dates';

-- Table 3: Public Holidays
-- Centralized holiday calendar (applies to all shifts)
CREATE TABLE IF NOT EXISTS t_ops_public_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    holiday_name VARCHAR(200) NOT NULL COMMENT 'Holiday name (e.g., "Independence Day")',
    description TEXT NULL COMMENT 'Optional notes',
    is_active TINYINT(1) DEFAULT 1 COMMENT '1 = active, 0 = inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    INDEX idx_date (holiday_date),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Public holidays calendar - applies to all users';

-- Add migration tracking column to existing rider_profile table
-- This helps us track which users have been migrated to the new system
ALTER TABLE t_ops_rider_profile 
ADD COLUMN IF NOT EXISTS migrated_to_shift_system TINYINT(1) DEFAULT 0 
COMMENT '1 = migrated to new shift system, 0 = still using legacy shift_start/end' 
AFTER shift_end;

-- Add index for faster lookups
ALTER TABLE t_ops_rider_profile 
ADD INDEX IF NOT EXISTS idx_migrated (migrated_to_shift_system);

-- Verification queries (you can run these to check)
-- SELECT COUNT(*) FROM t_ops_shift_template;
-- SELECT COUNT(*) FROM t_ops_user_shift_assignment;
-- SELECT COUNT(*) FROM t_ops_public_holidays;
-- SHOW COLUMNS FROM t_ops_rider_profile LIKE 'migrated_to_shift_system';



