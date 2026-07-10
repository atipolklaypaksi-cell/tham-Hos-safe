CREATE TABLE IF NOT EXISTS smart_pharma_modules (
    module_key VARCHAR(32) NOT NULL PRIMARY KEY,
    label_th VARCHAR(120) NOT NULL,
    label_en VARCHAR(120) NULL DEFAULT NULL,
    icon VARCHAR(40) NULL DEFAULT 'check-circle',
    color VARCHAR(20) NULL DEFAULT 'secondary',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    enabled_opd TINYINT(1) NOT NULL DEFAULT 1,
    enabled_ipd TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO smart_pharma_modules (module_key, label_th, label_en, icon, color, enabled, enabled_opd, enabled_ipd, sort_order) VALUES
('di', 'Drug Interaction', 'Drug Interaction', 'pills', 'primary', 1, 1, 1, 10),
('cumulative', 'Cumulative Dose', 'Cumulative Dose', 'radiation-alt', 'warning', 0, 1, 1, 20),
('lab_renal', 'Lab ไต', 'Renal Lab', 'heartbeat', 'danger', 1, 1, 1, 30),
('lab_hepatic', 'Lab ตับ', 'Hepatic Lab', 'lungs', 'warning', 1, 1, 1, 40),
('lab_other', 'Lab อื่นๆ', 'Other Lab', 'vial', 'info', 0, 1, 1, 50),
('hbv', 'HBV คัดกรอง', 'HBV Screening', 'virus', 'danger', 1, 1, 1, 60),
('hla', 'ยีนแพ้ยา HLA', 'HLA Screening', 'dna', 'purple', 1, 1, 1, 70),
('ua', 'UA ปัสสาวะ', 'Urinalysis', 'flask', 'primary', 1, 1, 0, 80);

CREATE TABLE IF NOT EXISTS pharmacist_interventions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hn VARCHAR(20) NOT NULL,
    vn VARCHAR(20) NULL DEFAULT NULL,
    an VARCHAR(20) NULL DEFAULT NULL,
    patient_type ENUM('OPD','IPD') NOT NULL DEFAULT 'OPD',
    vstdate DATE NOT NULL,
    module_key VARCHAR(32) NOT NULL,
    alert_level VARCHAR(10) NULL DEFAULT 'soft',
    alert_summary VARCHAR(500) NULL DEFAULT NULL,
    intervention_type VARCHAR(40) NOT NULL DEFAULT 'recommend',
    deviation_category VARCHAR(50) NULL DEFAULT NULL,
    note_text TEXT NULL,
    recorded_by INT NULL DEFAULT NULL,
    recorded_name VARCHAR(120) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hn (hn),
    KEY idx_vstdate (vstdate),
    KEY idx_module (module_key),
    KEY idx_type (patient_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
