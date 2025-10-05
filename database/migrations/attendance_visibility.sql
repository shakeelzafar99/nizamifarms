-- Table to track which users should appear in attendance tracking
-- By default, all users are visible unless explicitly hidden
CREATE TABLE IF NOT EXISTS t_ops_attendance_visibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    is_visible TINYINT(1) DEFAULT 1 COMMENT '1 = show in attendance, 0 = hide from attendance',
    hidden_by INT NULL COMMENT 'User ID who hid this user',
    hidden_at DATETIME NULL,
    notes VARCHAR(500) NULL COMMENT 'Reason for hiding (e.g., "System user", "Test account")',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user (user_id),
    FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE,
    FOREIGN KEY (hidden_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
    INDEX idx_visible (is_visible)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Controls which users appear in attendance tracking';

