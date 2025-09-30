-- Add status column to t_ops_attendance table
ALTER TABLE t_ops_attendance 
ADD COLUMN status VARCHAR(20) DEFAULT 'present' AFTER logout_time;
