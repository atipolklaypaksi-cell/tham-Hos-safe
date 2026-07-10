-- Monitor วิธีใช้ยา — ตรวจว่าวิธีใช้มีคำกำหนด (เช่น วัน/สัปดาห์) สำหรับยาที่ต้องระบุชัดเจน
CREATE TABLE IF NOT EXISTS monitor_drug_usage_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    icode VARCHAR(64) NOT NULL,
    drug_name VARCHAR(255) NOT NULL DEFAULT '',
    required_regex TEXT NOT NULL COMMENT 'วิธีใช้ต้องมีคำเหล่านี้ (REGEXP)',
    alert_level VARCHAR(8) NOT NULL DEFAULT 'hard' COMMENT 'hard | soft',
    alert_message TEXT NULL,
    detail_text TEXT NULL COMMENT 'คำอธิบายกฎสำหรับเภสัช',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_mdu_icode (icode),
    INDEX idx_mdu_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
