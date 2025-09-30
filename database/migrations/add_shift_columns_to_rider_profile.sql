-- Add shift_start and shift_end columns to t_ops_rider_profile table
ALTER TABLE t_ops_rider_profile 
ADD COLUMN shift_start TIME DEFAULT '09:00:00' AFTER active,
ADD COLUMN shift_end TIME DEFAULT '17:00:00' AFTER shift_start;
