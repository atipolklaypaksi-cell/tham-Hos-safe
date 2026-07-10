CREATE TABLE IF NOT EXISTS monitor_mg_ml_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    icode VARCHAR(20) NOT NULL,
    drug_name VARCHAR(255) NOT NULL DEFAULT '',
    strength_text VARCHAR(255) NULL DEFAULT NULL COMMENT 'strength จาก TMT หรือกำหนดเอง',
    mg_per_ml DECIMAL(12,6) NOT NULL DEFAULT 0 COMMENT 'ความเข้มข้น mg/mL ที่ใช้คำนวณ',
    tolerance_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT 'อนุโลม % จากค่าที่คำนวณ',
    tolerance_ml DECIMAL(8,4) NOT NULL DEFAULT 0.0200 COMMENT 'อนุโลมขั้นต่ำ (mL)',
    auto_from_tmt TINYINT(1) NOT NULL DEFAULT 0,
    alert_level VARCHAR(10) NOT NULL DEFAULT 'hard',
    alert_message VARCHAR(500) NULL DEFAULT NULL,
    detail_text TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_icode (icode),
    KEY idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
