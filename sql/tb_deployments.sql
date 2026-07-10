-- Step 1: ตารางเก็บรพ.ที่ติดตั้ง (Admin Spot Map)
CREATE TABLE IF NOT EXISTS tb_deployments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hospital_name VARCHAR(255) NOT NULL COMMENT 'ชื่อโรงพยาบาล',
    lat DECIMAL(10, 7) NOT NULL DEFAULT 13.7563000 COMMENT 'ละติจูด',
    lng DECIMAL(10, 7) NOT NULL DEFAULT 100.5018000 COMMENT 'ลองจิจูด',
    expire_date DATE NOT NULL COMMENT 'วันหมดอายุ License',
    status VARCHAR(20) NOT NULL DEFAULT 'Active' COMMENT 'Active | Expired | Revoked',
    license_file VARCHAR(255) NULL DEFAULT NULL,
    license_generated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hospital_name (hospital_name),
    KEY idx_status (status),
    KEY idx_expire_date (expire_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
