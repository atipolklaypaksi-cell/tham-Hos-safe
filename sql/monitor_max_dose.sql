CREATE TABLE IF NOT EXISTS monitor_max_dose_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    icode VARCHAR(20) NOT NULL,
    drug_name VARCHAR(255) NOT NULL DEFAULT '',
    max_dose_mg_per_day DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_duration_days INT NOT NULL DEFAULT 0,
    alert_level VARCHAR(10) NOT NULL DEFAULT 'hard',
    alert_message VARCHAR(500) NULL DEFAULT NULL,
    detail_text TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_icode (icode),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
