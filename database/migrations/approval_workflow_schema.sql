-- ========================================
-- APPROVAL WORKFLOW SYSTEM - DATABASE SCHEMA
-- ========================================
-- Run these in order in your MySQL workbench
-- Database: nizamifarms_db (adjust if needed)

USE nizamifarms_db;

-- ========================================
-- 1. ROLE APPROVAL LEVELS TABLE
-- Maps roles to approval levels (Level 1, Level 2, etc.)
-- ========================================
CREATE TABLE IF NOT EXISTS t_sys_role_approval_level (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference to t_sys_role',
    approval_level TINYINT NOT NULL COMMENT '1=Level 1, 2=Level 2, etc.',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Active status',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT(11) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT(11) NULL,
    
    UNIQUE KEY unique_role_level (role_id, approval_level),
    INDEX idx_role_id (role_id),
    INDEX idx_approval_level (approval_level),
    
    CONSTRAINT fk_role_approval_level_role FOREIGN KEY (role_id) 
        REFERENCES t_sys_role(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_approval_level_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    CONSTRAINT fk_role_approval_level_updated_by FOREIGN KEY (updated_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Maps roles to approval levels';


-- ========================================
-- 2. REQUEST CATEGORIES TABLE
-- Types of requests: leave, advance, expense, etc.
-- ========================================
CREATE TABLE IF NOT EXISTS t_req_category (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unique code: leave, advance, expense, etc.',
    category_name VARCHAR(100) NOT NULL COMMENT 'Display name',
    description TEXT NULL COMMENT 'Category description',
    icon VARCHAR(50) NULL COMMENT 'Icon class',
    color_class VARCHAR(50) NULL COMMENT 'Color class for UI',
    is_active TINYINT(1) DEFAULT 1,
    sequence_order INT DEFAULT 0 COMMENT 'Display order',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT(11) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT(11) NULL,
    
    INDEX idx_category_code (category_code),
    INDEX idx_is_active (is_active),
    
    CONSTRAINT fk_req_category_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    CONSTRAINT fk_req_category_updated_by FOREIGN KEY (updated_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Request categories/types';


-- ========================================
-- 3. REQUEST CATEGORY APPROVAL CONFIGURATION
-- Defines which approval levels are required for each category
-- ========================================
CREATE TABLE IF NOT EXISTS t_req_category_approval_config (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference to t_req_category',
    requires_level_1 TINYINT(1) DEFAULT 1 COMMENT 'Requires Level 1 approval',
    requires_level_2 TINYINT(1) DEFAULT 0 COMMENT 'Requires Level 2 approval',
    auto_approve_threshold DECIMAL(10,2) NULL COMMENT 'Auto-approve if amount below this (optional)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT(11) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT(11) NULL,
    
    UNIQUE KEY unique_category (category_id),
    
    CONSTRAINT fk_req_cat_approval_config_category FOREIGN KEY (category_id) 
        REFERENCES t_req_category(id) ON DELETE CASCADE,
    CONSTRAINT fk_req_cat_approval_config_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    CONSTRAINT fk_req_cat_approval_config_updated_by FOREIGN KEY (updated_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Approval level requirements per category';


-- ========================================
-- 4. REQUEST MASTER TABLE
-- Main table for all requests
-- ========================================
CREATE TABLE IF NOT EXISTS t_req_master (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'Auto-generated request number',
    category_id BIGINT UNSIGNED NOT NULL COMMENT 'Request category',
    requester_user_id INT(11) NOT NULL COMMENT 'User making the request',
    
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
    created_by INT(11) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT(11) NULL,
    
    INDEX idx_request_number (request_number),
    INDEX idx_category_id (category_id),
    INDEX idx_requester_user_id (requester_user_id),
    INDEX idx_status (status),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_leave_dates (leave_start_date, leave_end_date),
    
    CONSTRAINT fk_req_master_category FOREIGN KEY (category_id) 
        REFERENCES t_req_category(id) ON DELETE RESTRICT,
    CONSTRAINT fk_req_master_requester FOREIGN KEY (requester_user_id) 
        REFERENCES t_sys_user(id) ON DELETE RESTRICT,
    CONSTRAINT fk_req_master_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL,
    CONSTRAINT fk_req_master_updated_by FOREIGN KEY (updated_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Master table for all requests';


-- ========================================
-- 5. REQUEST APPROVALS TABLE
-- Tracks individual approval actions
-- ========================================
CREATE TABLE IF NOT EXISTS t_req_approval (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL COMMENT 'Reference to t_req_master',
    approval_level TINYINT NOT NULL COMMENT '1=Level 1, 2=Level 2',
    approver_user_id INT(11) NOT NULL COMMENT 'User who approved/rejected',
    
    status VARCHAR(20) NOT NULL COMMENT 'approved, rejected',
    comments TEXT NULL COMMENT 'Approver comments',
    action_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When action was taken',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT(11) NULL,
    
    INDEX idx_request_id (request_id),
    INDEX idx_approval_level (approval_level),
    INDEX idx_approver_user_id (approver_user_id),
    INDEX idx_action_date (action_date),
    
    CONSTRAINT fk_req_approval_request FOREIGN KEY (request_id) 
        REFERENCES t_req_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_req_approval_approver FOREIGN KEY (approver_user_id) 
        REFERENCES t_sys_user(id) ON DELETE RESTRICT,
    CONSTRAINT fk_req_approval_created_by FOREIGN KEY (created_by) 
        REFERENCES t_sys_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Individual approval actions';


-- ========================================
-- 6. UPDATE ATTENDANCE TABLE
-- Add link to leave requests
-- ========================================
ALTER TABLE t_ops_attendance
ADD COLUMN leave_request_id BIGINT UNSIGNED NULL COMMENT 'Link to approved leave request' AFTER notes,
ADD COLUMN leave_type VARCHAR(50) NULL COMMENT 'Type of leave if from request' AFTER leave_request_id,
ADD INDEX idx_leave_request_id (leave_request_id);

-- Add foreign key constraint
ALTER TABLE t_ops_attendance
ADD CONSTRAINT fk_attendance_leave_request FOREIGN KEY (leave_request_id) 
    REFERENCES t_req_master(id) ON DELETE SET NULL;


-- ========================================
-- 7. SEED INITIAL DATA
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

-- Insert default approval configurations for each category
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, created_by)
SELECT id, 1, 0, 1 
FROM t_req_category
ON DUPLICATE KEY UPDATE requires_level_1 = VALUES(requires_level_1);


-- ========================================
-- VERIFICATION QUERIES
-- Run these to verify the tables were created successfully
-- ========================================

-- Show all new tables
SHOW TABLES LIKE 't_req%';
SHOW TABLES LIKE '%approval%';

-- Show structure of main tables
DESCRIBE t_sys_role_approval_level;
DESCRIBE t_req_category;
DESCRIBE t_req_master;
DESCRIBE t_req_approval;

-- Verify attendance table update
DESCRIBE t_ops_attendance;

-- Check initial data
SELECT * FROM t_req_category;
SELECT * FROM t_req_category_approval_config;

-- ========================================
-- SAMPLE: Assign approval levels to roles
-- ========================================
-- Run these after you've identified which roles should have which approval levels
-- Example:

-- INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
-- VALUES 
--     (2, 1, 1),  -- Manager role (id=2) has Level 1 approval rights
--     (1, 2, 1);  -- Admin role (id=1) has Level 2 approval rights

-- First, check your roles:
SELECT id, urole_name FROM t_sys_role WHERE is_active = 1;

