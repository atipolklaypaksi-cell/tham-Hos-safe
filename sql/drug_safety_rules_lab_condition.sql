ALTER TABLE drug_safety_rules
    ADD COLUMN lab_condition_type VARCHAR(32) NOT NULL DEFAULT 'range' AFTER lab_items_name,
    ADD COLUMN lab_condition_value VARCHAR(255) NULL DEFAULT NULL AFTER lab_condition_type;
