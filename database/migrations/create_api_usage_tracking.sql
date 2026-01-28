-- API Usage Tracking Table
-- Tracks external API calls to manage costs and implement safeguards
-- Created: 2026-01-28

CREATE TABLE IF NOT EXISTS t_sys_api_usage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_name VARCHAR(50) NOT NULL,           -- 'google_directions', 'openroute', 'locationiq'
    month_key VARCHAR(7) NOT NULL,           -- '2026-01' format for easy monthly grouping
    call_count INT NOT NULL DEFAULT 0,       -- Number of calls made
    last_called_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_api_month (api_name, month_key),
    INDEX idx_month_key (month_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- View current month usage
-- SELECT * FROM t_sys_api_usage WHERE month_key = DATE_FORMAT(NOW(), '%Y-%m');
