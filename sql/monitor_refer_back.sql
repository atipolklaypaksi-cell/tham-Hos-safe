CREATE TABLE IF NOT EXISTS refer_back_monitor_settings (
    patient_type ENUM('OPD','IPD') NOT NULL PRIMARY KEY,
    min_match_percent INT NOT NULL DEFAULT 80,
    min_similar_score INT NOT NULL DEFAULT 90,
    pttype_mode VARCHAR(20) NOT NULL DEFAULT 'default',
    pttype_codes TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO refer_back_monitor_settings (patient_type, min_match_percent, min_similar_score, pttype_mode)
VALUES ('OPD', 80, 90, 'default'), ('IPD', 80, 90, 'default');

CREATE TABLE IF NOT EXISTS refer_back_exclude_drugs (
    icode VARCHAR(64) NOT NULL PRIMARY KEY,
    drug_name VARCHAR(255) NOT NULL DEFAULT '',
    exclude_opd TINYINT(1) NOT NULL DEFAULT 1,
    exclude_ipd TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS refer_back_substitute_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    icode_a VARCHAR(64) NOT NULL,
    icode_b VARCHAR(64) NOT NULL,
    name_a VARCHAR(255) NOT NULL DEFAULT '',
    name_b VARCHAR(255) NOT NULL DEFAULT '',
    tmtdrug_a VARCHAR(255) NOT NULL DEFAULT '',
    tmtdrug_b VARCHAR(255) NOT NULL DEFAULT '',
    tpu_a VARCHAR(64) NOT NULL DEFAULT '',
    tpu_b VARCHAR(64) NOT NULL DEFAULT '',
    strength_a VARCHAR(64) NOT NULL DEFAULT '',
    strength_b VARCHAR(64) NOT NULL DEFAULT '',
    UNIQUE KEY uk_pair_a_tmt (icode_a, tmtdrug_b(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS refer_back_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_uid VARCHAR(191) NOT NULL,
    hn VARCHAR(20) NOT NULL,
    vn VARCHAR(20) NULL DEFAULT '',
    an VARCHAR(20) NULL DEFAULT '',
    patient_type ENUM('OPD','IPD') NOT NULL DEFAULT 'OPD',
    screen_date DATE NOT NULL,
    ptname VARCHAR(200) NULL DEFAULT '',
    amp_name VARCHAR(120) NULL DEFAULT '',
    pttype_name VARCHAR(120) NULL DEFAULT '',
    match_hospital VARCHAR(200) NULL DEFAULT '',
    match_percent INT NULL DEFAULT NULL,
    match_total INT NOT NULL DEFAULT 0,
    match_matched INT NOT NULL DEFAULT 0,
    ref_vn VARCHAR(20) NULL DEFAULT '',
    ref_an VARCHAR(20) NULL DEFAULT '',
    ref_visit_type VARCHAR(10) NULL DEFAULT 'opd',
    print_status ENUM('pending','printed') NOT NULL DEFAULT 'pending',
    printed_at TIMESTAMP NULL DEFAULT NULL,
    printed_by INT NULL DEFAULT NULL,
    printed_by_name VARCHAR(120) NULL DEFAULT '',
    intervention_status ENUM('none','done','not_done') NOT NULL DEFAULT 'none',
    intervention_note TEXT NULL,
    intervention_by INT NULL DEFAULT NULL,
    intervention_by_name VARCHAR(120) NULL DEFAULT '',
    intervention_at TIMESTAMP NULL DEFAULT NULL,
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_uid (case_uid),
    KEY idx_screen (screen_date, patient_type, print_status),
    KEY idx_hn (hn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
