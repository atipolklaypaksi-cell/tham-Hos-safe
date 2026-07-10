ALTER TABLE monitor_hla_settings
    ADD COLUMN alert_level VARCHAR(8) NOT NULL DEFAULT 'hard' COMMENT 'hard | soft' AFTER drug_name;

UPDATE monitor_hla_settings
SET alert_level = 'hard'
WHERE alert_level IS NULL OR TRIM(alert_level) = '';
