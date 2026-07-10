ALTER TABLE drug_safety_rules
    ADD COLUMN alert_level VARCHAR(8) NOT NULL DEFAULT 'soft' COMMENT 'hard | soft' AFTER suggestion_msg;

UPDATE drug_safety_rules
SET alert_level = IF(action_type = 'omit', 'hard', 'soft')
WHERE alert_level IS NULL OR TRIM(alert_level) = '';
