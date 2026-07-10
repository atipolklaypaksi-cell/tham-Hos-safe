-- Monitor Drug Allergy — ตรวจแพ้ยา (ใช้ชื่อ TMT) + ข้ามกลุ่ม
CREATE TABLE IF NOT EXISTS monitor_drug_allergy_cross_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_name VARCHAR(150) NOT NULL DEFAULT '',
    allergy_regex VARCHAR(500) NOT NULL COMMENT 'จับคู่ opd_allergy.agent',
    rx_regex VARCHAR(500) NOT NULL COMMENT 'จับคู่ชื่อ TMT / active_ingredient ของยาที่สั่ง',
    alert_level VARCHAR(8) NOT NULL DEFAULT 'hard' COMMENT 'hard | soft',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mda_cross_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
