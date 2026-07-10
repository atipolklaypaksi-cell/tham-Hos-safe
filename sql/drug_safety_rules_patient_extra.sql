ALTER TABLE drug_safety_rules
    ADD COLUMN use_age_condition TINYINT(1) NOT NULL DEFAULT 0 AFTER dose_unit,
    ADD COLUMN age_min DECIMAL(10,2) NULL DEFAULT NULL AFTER use_age_condition,
    ADD COLUMN age_max DECIMAL(10,2) NULL DEFAULT NULL AFTER age_min,
    ADD COLUMN use_weight_condition TINYINT(1) NOT NULL DEFAULT 0 AFTER age_max,
    ADD COLUMN weight_min DECIMAL(10,2) NULL DEFAULT NULL AFTER use_weight_condition,
    ADD COLUMN weight_max DECIMAL(10,2) NULL DEFAULT NULL AFTER weight_min;
