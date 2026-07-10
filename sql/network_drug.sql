CREATE TABLE IF NOT EXISTS network_drug_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL DEFAULT '',
    uploaded_by INT NOT NULL DEFAULT 0,
    uploaded_by_name VARCHAR(255) NOT NULL DEFAULT '',
    row_count INT NOT NULL DEFAULT 0,
    hospital_count INT NOT NULL DEFAULT 0,
    drug_count INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    hospitals_affected TEXT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_active (is_active),
    KEY idx_uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS network_drug_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    upload_id INT NOT NULL,
    tpu_code_list VARCHAR(64) NOT NULL DEFAULT '',
    name VARCHAR(500) NOT NULL DEFAULT '',
    generic_name VARCHAR(500) NOT NULL DEFAULT '',
    strength VARCHAR(255) NOT NULL DEFAULT '',
    tmtdrug VARCHAR(500) NOT NULL DEFAULT '',
    units VARCHAR(100) NOT NULL DEFAULT '',
    dosageform VARCHAR(255) NOT NULL DEFAULT '',
    hosname VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_upload (upload_id),
    KEY idx_tmtdrug (tmtdrug(191)),
    KEY idx_hosname (hosname(191)),
    KEY idx_upload_tmtdrug_hos (upload_id, tmtdrug(100), hosname(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
