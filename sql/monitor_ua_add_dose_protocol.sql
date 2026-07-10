-- รองรับส่วน "กำหนดเงื่อนไขขนาดยา Protocol (Dose Range)" แบบเดียวกับ manage_safety_rules.php
-- รันครั้งเดียวบนฐานข้อมูลของ THAM CYTO

ALTER TABLE monitor_ua_settings
  ADD COLUMN use_dose_condition TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=ใช้ช่วง dose_min/max+dose_unit' AFTER max_dose,
  ADD COLUMN dose_unit VARCHAR(32) NULL DEFAULT NULL COMMENT 'หน่วยเดียวกับรายการใน manage_safety_rules' AFTER use_dose_condition;

-- ข้อมูลเดิมที่เคยมี min/max dose ให้ถือว่าเปิดใช้เงื่อนไข dose
UPDATE monitor_ua_settings
SET use_dose_condition = 1
WHERE (min_dose IS NOT NULL AND min_dose > 0)
   OR (max_dose IS NOT NULL AND max_dose > 0);
