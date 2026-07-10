CREATE TABLE IF NOT EXISTS user_access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(100) NULL DEFAULT NULL,
    full_name VARCHAR(255) NULL DEFAULT NULL,
    action ENUM('login', 'logout', 'active') NOT NULL DEFAULT 'login',
    page_url VARCHAR(255) NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL DEFAULT NULL,
    user_agent VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_created (user_id, created_at),
    KEY idx_created (created_at),
    KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
