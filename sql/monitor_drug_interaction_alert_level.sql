-- เปลี่ยนจาก severity (1-4) เป็น alert_level (hard / soft)
ALTER TABLE monitor_drug_interaction_settings
  ADD COLUMN alert_level VARCHAR(8) NOT NULL DEFAULT 'soft' COMMENT 'hard | soft' AFTER interaction_type;

UPDATE monitor_drug_interaction_settings
SET alert_level = IF(COALESCE(severity, 1) >= 3, 'hard', 'soft');

ALTER TABLE monitor_drug_interaction_settings DROP COLUMN severity;
