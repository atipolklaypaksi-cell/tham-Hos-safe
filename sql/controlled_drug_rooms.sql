-- ห้อง/คลังสต๊อกยาเสพติด/วัตถุออกฤทธิ์ (แยกบัญชีตามห้องจ่ายยา)

CREATE TABLE IF NOT EXISTS `controlled_drug_rooms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drug_type` ENUM('narcotic','psychotropic') NOT NULL,
  `room_code` VARCHAR(32) NOT NULL,
  `room_name` VARCHAR(120) NOT NULL,
  `service_mode` ENUM('all','ipd','opd') NOT NULL DEFAULT 'all',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cdm_room_type_code` (`drug_type`, `room_code`),
  KEY `idx_cdm_room_type_active` (`drug_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `controlled_drug_room_stock` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` INT UNSIGNED NOT NULL,
  `drug_type` ENUM('narcotic','psychotropic') NOT NULL,
  `icode` VARCHAR(20) NOT NULL,
  `qty` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cdm_room_stock` (`room_id`, `drug_type`, `icode`),
  KEY `idx_cdm_room_stock_icode` (`drug_type`, `icode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
