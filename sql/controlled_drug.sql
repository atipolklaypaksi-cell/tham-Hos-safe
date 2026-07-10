-- บริหารยาเสพติด/วัตถุออกฤทธิ์ — รายการยาและสต๊อกคงคลัง

CREATE TABLE IF NOT EXISTS `controlled_drug_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drug_type` ENUM('narcotic','psychotropic') NOT NULL,
  `icode` VARCHAR(20) NOT NULL,
  `drug_name` VARCHAR(255) NOT NULL DEFAULT '',
  `unit` VARCHAR(50) NOT NULL DEFAULT '',
  `opening_qty` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `current_qty` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `stock_status` ENUM('none','pending_form','pending_remains','returned_incomplete') NOT NULL DEFAULT 'none',
  `stock_status_note` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cdm_type_icode` (`drug_type`, `icode`),
  KEY `idx_cdm_type_active` (`drug_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `controlled_drug_stock_moves` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drug_type` ENUM('narcotic','psychotropic') NOT NULL,
  `icode` VARCHAR(20) NOT NULL,
  `move_date` DATE NOT NULL,
  `move_type` ENUM('opening','hosxp_usage','adjust','manual') NOT NULL,
  `qty_change` DECIMAL(14,4) NOT NULL,
  `qty_before` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `qty_after` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `ref_date_start` DATE NULL,
  `ref_date_end` DATE NULL,
  `hosxp_rows` INT NULL,
  `note` VARCHAR(500) NULL,
  `user_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cdm_moves_type_icode` (`drug_type`, `icode`),
  KEY `idx_cdm_moves_date` (`move_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `controlled_drug_hosxp_sync_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drug_type` ENUM('narcotic','psychotropic') NOT NULL,
  `sync_date_start` DATE NOT NULL,
  `sync_date_end` DATE NOT NULL,
  `icodes_synced` INT NOT NULL DEFAULT 0,
  `total_qty_cut` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `user_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cdm_sync_type` (`drug_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
