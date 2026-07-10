CREATE TABLE IF NOT EXISTS rational_lab_item_monthly (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_ym CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    hipdata_code VARCHAR(12) NOT NULL DEFAULT '',
    item_code VARCHAR(20) NOT NULL DEFAULT '',
    item_name VARCHAR(255) NOT NULL DEFAULT '',
    group_code VARCHAR(20) NOT NULL DEFAULT '',
    item_count INT NOT NULL DEFAULT 0,
    total_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_month_item (cache_ym, hipdata_code, item_code),
    KEY idx_cache_ym (cache_ym),
    KEY idx_hipdata (hipdata_code),
    KEY idx_group_code (group_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
