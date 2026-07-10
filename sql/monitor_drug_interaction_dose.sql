ALTER TABLE monitor_drug_interaction_settings
    ADD COLUMN use_dose1_condition TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'เงื่อนไขขนาดยา 1' AFTER lookback_days,
    ADD COLUMN dose1_min DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 1' AFTER use_dose1_condition,
    ADD COLUMN dose1_max DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 1' AFTER dose1_min,
    ADD COLUMN use_dose2_condition TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'เงื่อนไขขนาดยา 2' AFTER dose1_max,
    ADD COLUMN dose2_min DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 2' AFTER use_dose2_condition,
    ADD COLUMN dose2_max DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'mg/วัน ยา 2' AFTER dose2_min;
