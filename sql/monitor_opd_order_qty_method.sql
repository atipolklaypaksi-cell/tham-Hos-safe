CREATE TABLE IF NOT EXISTS monitor_opd_order_qty_method_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_mode VARCHAR(20) NOT NULL DEFAULT 'skip' COMMENT 'skip|daily|per_week|weekdays',
    match_pattern VARCHAR(255) NOT NULL,
    match_type VARCHAR(20) NOT NULL DEFAULT 'contains' COMMENT 'contains|regex',
    label VARCHAR(255) NOT NULL DEFAULT '',
    times_per_day DECIMAL(8,2) NULL DEFAULT NULL,
    times_per_week DECIMAL(8,2) NULL DEFAULT NULL,
    weekdays_json VARCHAR(100) NULL DEFAULT NULL COMMENT 'JSON [1=Mon..7=Sun], empty=auto from text',
    units_per_dose DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'null=ดึงจากวิธีใช้',
    priority INT NOT NULL DEFAULT 50,
    sample_method VARCHAR(500) NULL DEFAULT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual|auto',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_active_priority (active, priority),
    KEY idx_match (match_pattern(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
