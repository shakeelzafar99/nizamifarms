-- Route Lock: prevents accidental route changes by other users
-- A rider can have at most one lock at a time

CREATE TABLE IF NOT EXISTS t_crm_route_lock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL COMMENT 'The rider whose route is locked (t_sys_user.id)',
    locked_by INT NOT NULL COMMENT 'The user who locked the route (t_sys_user.id)',
    locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
