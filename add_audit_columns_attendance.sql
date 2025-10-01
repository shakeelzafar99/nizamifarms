-- Add audit tracking columns to t_ops_attendance table
-- Run this in your MySQL workbench

ALTER TABLE t_ops_attendance
ADD COLUMN created_by INT(11) NULL COMMENT 'User who created the record',
ADD COLUMN updated_by INT(11) NULL COMMENT 'User who last updated the record',
ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp';

-- Add foreign key constraints for referential integrity (optional but recommended)
ALTER TABLE t_ops_attendance
ADD CONSTRAINT fk_attendance_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_attendance_updated_by FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;

-- Add indexes for better query performance
ALTER TABLE t_ops_attendance
ADD INDEX idx_created_by (created_by),
ADD INDEX idx_updated_by (updated_by);

-- Verify the changes
DESCRIBE t_ops_attendance;

