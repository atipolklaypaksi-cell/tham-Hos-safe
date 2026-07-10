ALTER TABLE drug_safety_rules
    ADD COLUMN lab_items_code VARCHAR(20) NULL DEFAULT NULL AFTER lab_type,
    ADD COLUMN lab_items_name VARCHAR(255) NULL DEFAULT NULL AFTER lab_items_code;
