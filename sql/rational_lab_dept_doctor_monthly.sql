CREATE TABLE IF NOT EXISTS rational_lab_dept_doctor_monthly (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_ym CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    dept_code VARCHAR(20) NOT NULL DEFAULT '',
    doctor_code VARCHAR(20) NOT NULL DEFAULT '',
    doctor_name VARCHAR(255) NOT NULL DEFAULT '',
    item_count INT NOT NULL DEFAULT 0,
    total_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_month_dept_doctor (cache_ym, dept_code, doctor_code),
    KEY idx_dept_ym (dept_code, cache_ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
