CREATE TABLE IF NOT EXISTS rational_lab_dept_monthly (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_ym CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    hipdata_code VARCHAR(12) NOT NULL DEFAULT '',
    dept_code VARCHAR(20) NOT NULL DEFAULT '',
    dept_name VARCHAR(255) NOT NULL DEFAULT '',
    income_group2 INT NOT NULL DEFAULT 0 COMMENT 'income.group2 — 0 = ไม่ระบุหมวด',
    item_count INT NOT NULL DEFAULT 0,
    total_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_month_dept_income (cache_ym, hipdata_code, dept_code, income_group2),
    KEY idx_cache_ym (cache_ym),
    KEY idx_income_group2 (income_group2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
