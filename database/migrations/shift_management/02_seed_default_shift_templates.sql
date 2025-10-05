-- ============================================================================
-- Shift Management System - Default Shift Templates
-- Run this after 01_create_shift_management_tables.sql
-- ============================================================================

-- Insert default shift templates based on your requirements
-- Working days format: [1,2,3,4,5,6,7] where 1=Monday, 2=Tuesday ... 7=Sunday

-- Shift 1: Rider Shift 1
-- Time: 11:00 AM - 7:00 PM
-- Off: Tuesday only (6 working days)
-- Working days: [1,3,4,5,6,7] = Mon, Wed, Thu, Fri, Sat, Sun
INSERT INTO t_ops_shift_template 
(shift_name, shift_code, shift_start, shift_end, working_days, is_default, description, active, created_by, updated_by) 
VALUES 
('Rider Shift 1', 'rider_shift_1', '11:00:00', '19:00:00', '[1,3,4,5,6,7]', 1, '6 days/week - Off on Tuesday only', 1, 1, 1);

-- Shift 2: Rider Shift 2
-- Time: 11:00 AM - 7:00 PM
-- Off: Tuesday AND Wednesday (5 working days)
-- Working days: [1,4,5,6,7] = Mon, Thu, Fri, Sat, Sun
INSERT INTO t_ops_shift_template 
(shift_name, shift_code, shift_start, shift_end, working_days, is_default, description, active, created_by, updated_by) 
VALUES 
('Rider Shift 2', 'rider_shift_2', '11:00:00', '19:00:00', '[1,4,5,6,7]', 0, '5 days/week - Off on Tuesday and Wednesday', 1, 1, 1);

-- Shift 3: Manager Shift
-- Time: 11:00 AM - 8:00 PM
-- Off: Tuesday only (6 working days)
-- Working days: [1,3,4,5,6,7] = Mon, Wed, Thu, Fri, Sat, Sun
INSERT INTO t_ops_shift_template 
(shift_name, shift_code, shift_start, shift_end, working_days, is_default, description, active, created_by, updated_by) 
VALUES 
('Manager Shift', 'manager_shift', '11:00:00', '20:00:00', '[1,3,4,5,6,7]', 0, '6 days/week - Off on Tuesday, extended hours for managers', 1, 1, 1);

-- Optional: System Fallback Shift (will be used by code if no other shift is found)
-- This is a safety net - you probably won't need to assign this explicitly
-- Time: 9:00 AM - 5:00 PM
-- Off: Sunday only (6 working days)
-- Working days: [1,2,3,4,5,6] = Mon-Sat
INSERT INTO t_ops_shift_template 
(shift_name, shift_code, shift_start, shift_end, working_days, is_default, description, active, created_by, updated_by) 
VALUES 
('System Default', 'system_default', '09:00:00', '17:00:00', '[1,2,3,4,5,6]', 0, 'Fallback shift for edge cases', 1, 1, 1);

-- Verification: Check what was inserted
SELECT 
    id,
    shift_name,
    shift_code,
    shift_start,
    shift_end,
    working_days,
    is_default,
    description
FROM t_ops_shift_template
ORDER BY id;

-- Expected result:
-- ID 1: Rider Shift 1 (11:00-19:00, [1,3,4,5,6,7], is_default=1)
-- ID 2: Rider Shift 2 (11:00-19:00, [1,4,5,6,7], is_default=0)
-- ID 3: Manager Shift (11:00-20:00, [1,3,4,5,6,7], is_default=0)
-- ID 4: System Default (09:00-17:00, [1,2,3,4,5,6], is_default=0)



