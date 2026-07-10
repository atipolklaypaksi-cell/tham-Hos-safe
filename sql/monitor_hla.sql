CREATE TABLE IF NOT EXISTS monitor_hla_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    icode VARCHAR(20) NOT NULL,
    drug_name VARCHAR(255) NOT NULL DEFAULT '',
    alert_level VARCHAR(8) NOT NULL DEFAULT 'hard' COMMENT 'hard | soft',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_icode (icode),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
