-- ========================================
-- APPROVAL WORKFLOW SYSTEM - SAFE VERSION
-- ========================================
-- This version creates tables step-by-step without foreign keys first
-- Then adds foreign keys separately so we can see exactly what fails
-- Database: nizamifarms_db

USE nizamifarms_db;

-- ========================================
-- STEP 1: CREATE TABLES WITHOUT FOREIGN KEYS
-- ========================================

-- 1. Role Approval Levels Table
-- Maps roles to approval levels (Level 1, Level 2, etc.)
DROP TABLE IF EXISTS t_sys_role_approval_level;
CREATE TABLE t_sys_role_approval_level (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL COMMENT 'Reference to t_sys_role.id',
    approval_level TINYINT NOT NULL COMMENT '1=Level 1, 2=Level 2, etc.',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Active status',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    UNIQUE KEY unique_role_level (role_id, approval_level),
    INDEX idx_role_id (role_id),
    INDEX idx_approval_level (approval_level),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Maps roles to approval levels';

SELECT 'Created t_sys_role_approval_level (without FKs)' as Status;


-- 2. Request Categories Table
DROP TABLE IF EXISTS t_req_category;
CREATE TABLE t_req_category (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unique code: leave, advance, expense, etc.',
    category_name VARCHAR(100) NOT NULL COMMENT 'Display name',
    description TEXT NULL COMMENT 'Category description',
    icon VARCHAR(50) NULL COMMENT 'Icon class',
    color_class VARCHAR(50) NULL COMMENT 'Color class for UI',
    is_active TINYINT(1) DEFAULT 1,
    sequence_order INT DEFAULT 0 COMMENT 'Display order',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    INDEX idx_category_code (category_code),
    INDEX idx_is_active (is_active),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Request categories/types';

SELECT 'Created t_req_category (without FKs)' as Status;


-- 3. Request Category Approval Configuration
DROP TABLE IF EXISTS t_req_category_approval_config;
CREATE TABLE t_req_category_approval_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL COMMENT 'Reference to t_req_category',
    requires_level_1 TINYINT(1) DEFAULT 1 COMMENT 'Requires Level 1 approval',
    requires_level_2 TINYINT(1) DEFAULT 0 COMMENT 'Requires Level 2 approval',
    auto_approve_threshold DECIMAL(10,2) NULL COMMENT 'Auto-approve if amount below this (optional)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    UNIQUE KEY unique_category (category_id),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Approval level requirements per category';

SELECT 'Created t_req_category_approval_config (without FKs)' as Status;


-- 4. Request Master Table
DROP TABLE IF EXISTS t_req_master;
CREATE TABLE t_req_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'Auto-generated request number',
    category_id INT NOT NULL COMMENT 'Request category',
    requester_user_id INT NOT NULL COMMENT 'User making the request',
    
    -- Request details
    title VARCHAR(255) NOT NULL COMMENT 'Request title',
    description TEXT NULL COMMENT 'Detailed description',
    amount DECIMAL(10,2) NULL COMMENT 'Amount (if applicable)',
    
    -- Leave-specific fields
    leave_start_date DATE NULL COMMENT 'For leave requests',
    leave_end_date DATE NULL COMMENT 'For leave requests',
    leave_type VARCHAR(50) NULL COMMENT 'sick, annual, casual, emergency, etc.',
    leave_days INT NULL COMMENT 'Number of days',
    
    -- Status tracking
    status VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected, cancelled',
    priority VARCHAR(20) DEFAULT 'normal' COMMENT 'low, normal, high, urgent',
    
    -- Approval tracking
    requires_level_1 TINYINT(1) DEFAULT 1,
    requires_level_2 TINYINT(1) DEFAULT 0,
    level_1_status VARCHAR(20) NULL COMMENT 'pending, approved, rejected',
    level_2_status VARCHAR(20) NULL COMMENT 'pending, approved, rejected',
    
    -- Attachments
    attachments JSON NULL COMMENT 'Array of file paths',
    
    -- Notes and audit
    remarks TEXT NULL COMMENT 'General remarks',
    rejection_reason TEXT NULL COMMENT 'If rejected, reason why',
    
    -- Timestamps
    submitted_at TIMESTAMP NULL COMMENT 'When submitted',
    completed_at TIMESTAMP NULL COMMENT 'When fully approved/rejected',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    
    INDEX idx_request_number (request_number),
    INDEX idx_category_id (category_id),
    INDEX idx_requester_user_id (requester_user_id),
    INDEX idx_status (status),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_leave_dates (leave_start_date, leave_end_date),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Master table for all requests';

SELECT 'Created t_req_master (without FKs)' as Status;


-- 5. Request Approvals Table
DROP TABLE IF EXISTS t_req_approval;
CREATE TABLE t_req_approval (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL COMMENT 'Reference to t_req_master',
    approval_level TINYINT NOT NULL COMMENT '1=Level 1, 2=Level 2',
    approver_user_id INT NOT NULL COMMENT 'User who approved/rejected',
    
    status VARCHAR(20) NOT NULL COMMENT 'approved, rejected',
    comments TEXT NULL COMMENT 'Approver comments',
    action_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When action was taken',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    
    INDEX idx_request_id (request_id),
    INDEX idx_approval_level (approval_level),
    INDEX idx_approver_user_id (approver_user_id),
    INDEX idx_action_date (action_date),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual approval actions';

SELECT 'Created t_req_approval (without FKs)' as Status;

-- ========================================
-- STEP 2: INSERT SEED DATA
-- ========================================

-- Insert default request categories
INSERT INTO t_req_category (category_code, category_name, description, icon, color_class, sequence_order, created_by) 
VALUES 
    ('leave', 'Leave Request', 'Employee leave/absence requests', 'calendar', 'blue', 1, 1),
    ('advance', 'Salary Advance', 'Salary advance requests', 'dollar-sign', 'green', 2, 1),
    ('expense', 'Expense Reimbursement', 'Expense reimbursement requests', 'receipt', 'purple', 3, 1),
    ('equipment', 'Equipment Request', 'Request for equipment or supplies', 'package', 'orange', 4, 1),
    ('other', 'Other Request', 'General requests', 'file-text', 'gray', 5, 1)
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);

SELECT 'Inserted seed data into t_req_category' as Status;

-- Insert default approval configurations for each category
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, created_by)
SELECT id, 1, 0, 1 
FROM t_req_category
ON DUPLICATE KEY UPDATE requires_level_1 = VALUES(requires_level_1);

SELECT 'Inserted default approval configs' as Status;

-- ========================================
-- STEP 3: ADD FOREIGN KEY CONSTRAINTS
-- ========================================
-- We add these separately so we can see exactly which one fails if any

SELECT 'Now adding foreign key constraints...' as Status;

-- FK for t_sys_role_approval_level
ALTER TABLE t_sys_role_approval_level
ADD CONSTRAINT fk_role_approval_level_role 
    FOREIGN KEY (role_id) REFERENCES t_sys_role(id) ON DELETE CASCADE;
    
SELECT 'Added FK: t_sys_role_approval_level -> t_sys_role' as Status;

ALTER TABLE t_sys_role_approval_level
ADD CONSTRAINT fk_role_approval_level_created_by 
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_sys_role_approval_level.created_by -> t_sys_user' as Status;

ALTER TABLE t_sys_role_approval_level
ADD CONSTRAINT fk_role_approval_level_updated_by 
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_sys_role_approval_level.updated_by -> t_sys_user' as Status;


-- FK for t_req_category
ALTER TABLE t_req_category
ADD CONSTRAINT fk_req_category_created_by 
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_req_category.created_by -> t_sys_user' as Status;

ALTER TABLE t_req_category
ADD CONSTRAINT fk_req_category_updated_by 
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_req_category.updated_by -> t_sys_user' as Status;


-- FK for t_req_category_approval_config
ALTER TABLE t_req_category_approval_config
ADD CONSTRAINT fk_req_cat_approval_config_category 
    FOREIGN KEY (category_id) REFERENCES t_req_category(id) ON DELETE CASCADE;
    
SELECT 'Added FK: t_req_category_approval_config -> t_req_category' as Status;

ALTER TABLE t_req_category_approval_config
ADD CONSTRAINT fk_req_cat_approval_config_created_by 
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_req_category_approval_config.created_by -> t_sys_user' as Status;

ALTER TABLE t_req_category_approval_config
ADD CONSTRAINT fk_req_cat_approval_config_updated_by 
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_req_category_approval_config.updated_by -> t_sys_user' as Status;


-- FK for t_req_master
ALTER TABLE t_req_master
ADD CONSTRAINT fk_req_master_category 
    FOREIGN KEY (category_id) REFERENCES t_req_category(id) ON DELETE RESTRICT;
    
SELECT 'Added FK: t_req_master -> t_req_category' as Status;

ALTER TABLE t_req_master
ADD CONSTRAINT fk_req_master_requester 
    FOREIGN KEY (requester_user_id) REFERENCES t_sys_user(id) ON DELETE RESTRICT;
    
SELECT 'Added FK: t_req_master.requester_user_id -> t_sys_user' as Status;

ALTER TABLE t_req_master
ADD CONSTRAINT fk_req_master_created_by 
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_req_master.created_by -> t_sys_user' as Status;

ALTER TABLE t_req_master
ADD CONSTRAINT fk_req_master_updated_by 
    FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_req_master.updated_by -> t_sys_user' as Status;


-- FK for t_req_approval
ALTER TABLE t_req_approval
ADD CONSTRAINT fk_req_approval_request 
    FOREIGN KEY (request_id) REFERENCES t_req_master(id) ON DELETE CASCADE;
    
SELECT 'Added FK: t_req_approval -> t_req_master' as Status;

ALTER TABLE t_req_approval
ADD CONSTRAINT fk_req_approval_approver 
    FOREIGN KEY (approver_user_id) REFERENCES t_sys_user(id) ON DELETE RESTRICT;
    
SELECT 'Added FK: t_req_approval.approver_user_id -> t_sys_user' as Status;

ALTER TABLE t_req_approval
ADD CONSTRAINT fk_req_approval_created_by 
    FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
    
SELECT 'Added FK: t_req_approval.created_by -> t_sys_user' as Status;


-- ========================================
-- STEP 4: UPDATE t_ops_attendance TABLE
-- ========================================
-- Add columns for leave request integration

SELECT 'Now updating t_ops_attendance table...' as Status;

-- Check if columns already exist before adding
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_ops_attendance' 
    AND COLUMN_NAME = 'leave_request_id'
);

-- Add leave_request_id column if it doesn't exist
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE t_ops_attendance ADD COLUMN leave_request_id INT NULL COMMENT ''Link to approved leave request'' AFTER notes',
    'SELECT ''Column leave_request_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if leave_type column exists
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'nizamifarms_db' 
    AND TABLE_NAME = 't_ops_attendance' 
    AND COLUMN_NAME = 'leave_type'
);

-- Add leave_type column if it doesn't exist
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE t_ops_attendance ADD COLUMN leave_type VARCHAR(50) NULL COMMENT ''Type of leave if from request'' AFTER leave_request_id',
    'SELECT ''Column leave_type already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index if it doesn't exist
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_ops_attendance'
    AND INDEX_NAME = 'idx_leave_request_id'
);

SET @sql = IF(@index_exists = 0,
    'ALTER TABLE t_ops_attendance ADD INDEX idx_leave_request_id (leave_request_id)',
    'SELECT ''Index idx_leave_request_id already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'nizamifarms_db'
    AND TABLE_NAME = 't_ops_attendance'
    AND CONSTRAINT_NAME = 'fk_attendance_leave_request'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE t_ops_attendance ADD CONSTRAINT fk_attendance_leave_request FOREIGN KEY (leave_request_id) REFERENCES t_req_master(id) ON DELETE SET NULL',
    'SELECT ''FK fk_attendance_leave_request already exists'' as Status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Updated t_ops_attendance table successfully' as Status;

-- ========================================
-- VERIFICATION
-- ========================================

SELECT '============================================' as '';
SELECT 'INSTALLATION COMPLETE!' as Status;
SELECT '============================================' as '';

-- Show all new tables
SELECT 'New tables created:' as '';
SHOW TABLES LIKE 't_req%';
SHOW TABLES LIKE '%approval%';

-- Show counts
SELECT 'Request categories seeded:' as '';
SELECT COUNT(*) as category_count FROM t_req_category;

SELECT 'Approval configs created:' as '';
SELECT COUNT(*) as config_count FROM t_req_category_approval_config;

-- Show updated attendance structure
SELECT 'Attendance table updated - new columns:' as '';
DESCRIBE t_ops_attendance;

SELECT '============================================' as '';
SELECT 'Next Steps:' as '';
SELECT '1. Assign roles to approval levels using INSERT statements' as Step;
SELECT '2. Test by creating a leave request' as Step;
SELECT '3. Check SETUP_INSTRUCTIONS.md for configuration' as Step;
SELECT '============================================' as '';

