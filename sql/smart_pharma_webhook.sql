CREATE TABLE IF NOT EXISTS smart_pharma_webhook_endpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    webhook_url VARCHAR(500) NOT NULL,
    secret VARCHAR(255) NULL DEFAULT NULL,
    notes VARCHAR(255) NULL DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS smart_pharma_webhook_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint_id INT NOT NULL,
    patient_type ENUM('OPD','IPD') NOT NULL,
    module_key VARCHAR(32) NOT NULL,
    notify_hard TINYINT(1) NOT NULL DEFAULT 1,
    notify_soft TINYINT(1) NOT NULL DEFAULT 0,
    link_mode VARCHAR(10) NOT NULL DEFAULT 'auto',
    extra_message VARCHAR(500) NULL DEFAULT NULL,
    extra_link VARCHAR(500) NULL DEFAULT NULL,
    extra_link_label VARCHAR(120) NULL DEFAULT NULL,
    hard_repeat_mode VARCHAR(10) NOT NULL DEFAULT 'off',
    hard_repeat_minutes INT NOT NULL DEFAULT 0,
    soft_repeat_mode VARCHAR(10) NOT NULL DEFAULT 'off',
    soft_repeat_minutes INT NOT NULL DEFAULT 0,
    stop_mode VARCHAR(10) NOT NULL DEFAULT 'off',
    stop_minutes INT NOT NULL DEFAULT 0,
    hard_stop_mode VARCHAR(10) NOT NULL DEFAULT 'off',
    hard_stop_minutes INT NOT NULL DEFAULT 0,
    soft_stop_mode VARCHAR(10) NOT NULL DEFAULT 'off',
    soft_stop_minutes INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_rule (endpoint_id, patient_type, module_key),
    KEY idx_endpoint (endpoint_id),
    KEY idx_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS smart_pharma_webhook_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dedupe_key VARCHAR(191) NOT NULL,
    patient_type VARCHAR(10) NOT NULL,
    screen_date DATE NOT NULL,
    hn VARCHAR(20) NOT NULL,
    module_key VARCHAR(32) NOT NULL,
    alert_level VARCHAR(10) NOT NULL,
    endpoint_id INT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dedupe (dedupe_key),
    KEY idx_sent_date (screen_date, patient_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO sys_config (config_name, config_value) VALUES
('sp_webhook_enabled', '0');
