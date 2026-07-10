-- Lot / Min-Max สำหรับสต๊อกยาเสพติด/วัตถุออกฤทธิ์

ALTER TABLE `controlled_drug_items`
  ADD COLUMN IF NOT EXISTS `min_stock` DECIMAL(14,4) NULL DEFAULT NULL AFTER `current_qty`,
  ADD COLUMN IF NOT EXISTS `max_stock` DECIMAL(14,4) NULL DEFAULT NULL AFTER `min_stock`;

CREATE TABLE IF NOT EXISTS `controlled_drug_lots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `drug_type` ENUM('narcotic','psychotropic') NOT NULL,
  `icode` VARCHAR(20) NOT NULL,
  `lot_no` VARCHAR(50) NOT NULL DEFAULT '',
  `exp_date` DATE NULL DEFAULT NULL,
  `qty` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `note` VARCHAR(255) NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cdm_lots_type_icode` (`drug_type`, `icode`),
  KEY `idx_cdm_lots_exp` (`exp_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
