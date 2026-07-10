-- ตารางตั้งค่า Drug Interaction: จับคู่ยา 2 รายการจาก HOSxP (ชื่อจาก TMT)
CREATE TABLE IF NOT EXISTS monitor_drug_interaction_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    drug_id INT NOT NULL DEFAULT 0 COMMENT 'legacy — ไม่ใช้แล้ว',
    drug_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ชื่อยาที่ 1 จาก TMT',
    drug_icode VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'icode ยาที่ 1 จาก HOSxP',
    hosxp_icode VARCHAR(64) NOT NULL COMMENT 'icode ยาที่ 2 จาก HOSxP',
    hosxp_drug_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ชื่อยาที่ 2 จาก TMT',
    interaction_type VARCHAR(64) NOT NULL DEFAULT 'drug_drug',
    alert_level VARCHAR(8) NOT NULL DEFAULT 'soft' COMMENT 'hard | soft',
    detail_text TEXT NULL,
    flag_warning TINYINT(1) NOT NULL DEFAULT 1,
    flag_sticker TINYINT(1) NOT NULL DEFAULT 0,
    flag_block TINYINT(1) NOT NULL DEFAULT 0,
    flag_unfinished TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ตรวจรายการยาที่ยังไม่เรียกเก็บ/ใบสั่งไม่เสร็จ',
    check_mode ENUM('same_visit','lookback') NOT NULL DEFAULT 'same_visit',
    lookback_days INT NOT NULL DEFAULT 0 COMMENT 'จำนวนวันย้อนหลัง (ใช้เมื่อ check_mode=lookback)',
    active_status TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_drug_icode (drug_icode),
    INDEX idx_hosxp_icode (hosxp_icode),
    INDEX idx_active (active_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
