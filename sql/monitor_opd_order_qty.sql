CREATE TABLE IF NOT EXISTS monitor_opd_order_qty_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_type VARCHAR(20) NOT NULL DEFAULT 'drug' COMMENT 'drug|continuous',
    icode VARCHAR(20) NOT NULL,
    drug_name VARCHAR(255) NOT NULL DEFAULT '',
    excess_pills_allowed INT NOT NULL DEFAULT 10,
    shortage_pills_allowed INT NOT NULL DEFAULT 0,
    alert_level_shortage VARCHAR(10) NOT NULL DEFAULT 'hard',
    alert_level_excess VARCHAR(10) NOT NULL DEFAULT 'soft',
    alert_message_shortage VARCHAR(500) NULL DEFAULT NULL,
    alert_message_excess VARCHAR(500) NULL DEFAULT NULL,
    detail_text TEXT NULL,
    exclude_icodes TEXT NULL DEFAULT NULL COMMENT 'JSON array of icode to skip for continuous group',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_rule_target (rule_type, icode),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
