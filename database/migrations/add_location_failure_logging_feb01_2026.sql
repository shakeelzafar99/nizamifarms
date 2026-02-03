-- =============================================
-- Location Failure Logging
-- Created: 2026-02-01
-- Purpose: Track when location heartbeat attempts fail
--          to help diagnose GPS gaps
-- =============================================

-- ⭐ Table to log location fetch failures
-- This helps diagnose why GPS gaps occur (network, GPS off, permission, etc.)
CREATE TABLE IF NOT EXISTS t_ops_location_failures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'FK to t_sys_user.id (INT, not UNSIGNED)',
    failure_reason VARCHAR(50) NOT NULL COMMENT 'Reason: no_gps, network_error, timeout, permission_denied, gps_disabled, location_unavailable',
    failure_source VARCHAR(30) DEFAULT 'unknown' COMMENT 'Source: foreground_interval, native_fused, native_timer, retry_1, retry_2',
    error_message VARCHAR(255) NULL COMMENT 'Detailed error message if available',
    device_online TINYINT(1) NULL COMMENT '1 if device had network, 0 if offline, NULL if unknown',
    last_known_lat DECIMAL(10, 7) NULL COMMENT 'Last known latitude before failure',
    last_known_lng DECIMAL(10, 7) NULL COMMENT 'Last known longitude before failure',
    last_success_at DATETIME NULL COMMENT 'When was the last successful location capture',
    app_state VARCHAR(20) DEFAULT 'unknown' COMMENT 'App state: foreground, background, killed',
    app_version VARCHAR(20) NULL COMMENT 'App version when failure occurred',
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the failure was recorded',
    INDEX idx_user_date (user_id, captured_at),
    INDEX idx_reason (failure_reason),
    INDEX idx_captured (captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Logs location heartbeat failures to help diagnose GPS gaps';

-- ⭐ Add column to t_sys_api_usage to track location failures (optional)
-- This is for aggregate stats, not individual failures
ALTER TABLE t_sys_api_usage 
ADD COLUMN IF NOT EXISTS failure_count INT UNSIGNED DEFAULT 0 
COMMENT 'Number of failures this month';

-- ⭐ Scheduled cleanup: Keep only 7 days of failure logs
-- You can create a scheduled event or run this manually
-- DELETE FROM t_ops_location_failures WHERE captured_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- ⭐ Useful queries to analyze failures:

-- 1. Get failure summary by reason for a specific date
-- SELECT failure_reason, COUNT(*) as count 
-- FROM t_ops_location_failures 
-- WHERE DATE(captured_at) = CURDATE() 
-- GROUP BY failure_reason 
-- ORDER BY count DESC;

-- 2. Get failures for a specific user on a date
-- SELECT * FROM t_ops_location_failures 
-- WHERE user_id = ? AND DATE(captured_at) = ? 
-- ORDER BY captured_at;

-- 3. Get users with most failures today
-- SELECT user_id, COUNT(*) as failures 
-- FROM t_ops_location_failures 
-- WHERE DATE(captured_at) = CURDATE() 
-- GROUP BY user_id 
-- ORDER BY failures DESC 
-- LIMIT 10;

-- 4. Correlate failures with GPS gaps
-- This query shows failures that occurred during GPS audit gaps
-- (Run after getting gap times from GPS audit)
-- SELECT * FROM t_ops_location_failures 
-- WHERE user_id = ? 
-- AND captured_at BETWEEN ? AND ?
-- ORDER BY captured_at;

SELECT 'Location failure logging table created successfully!' as status;
