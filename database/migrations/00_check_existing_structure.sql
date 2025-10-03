-- ========================================
-- DIAGNOSTIC SCRIPT - Check Existing Table Structures
-- ========================================
-- Run this FIRST to understand your current database structure
-- This will help us create foreign keys correctly

USE nizamifarms_db;

-- Check t_sys_role table structure
SELECT 'Checking t_sys_role structure:' as '';
DESCRIBE t_sys_role;

-- Check t_sys_user table structure
SELECT 'Checking t_sys_user structure:' as '';
DESCRIBE t_sys_user;

-- Check t_req_category table structure (if exists)
SELECT 'Checking if t_req_category exists:' as '';
SHOW TABLES LIKE 't_req_%';

-- Check t_ops_attendance table structure
SELECT 'Checking t_ops_attendance structure:' as '';
DESCRIBE t_ops_attendance;

-- Check existing foreign keys on t_sys_user_role
SELECT 'Checking existing foreign keys on t_sys_user_role:' as '';
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'nizamifarms_db'
  AND TABLE_NAME = 't_sys_user_role'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Show sample role IDs to understand the data
SELECT 'Sample roles in t_sys_role:' as '';
SELECT id, urole_name, is_active FROM t_sys_role LIMIT 5;

-- Show sample user IDs to understand the data
SELECT 'Sample users in t_sys_user:' as '';
SELECT id, fullname, is_active FROM t_sys_user LIMIT 5;

