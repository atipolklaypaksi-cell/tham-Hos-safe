-- ประเภทความคลาดเคลื่อนใน Intervention Log
-- ค่า: ความคลาดเคลื่อนจริง | ไม่ใช่ความคลาดเคลื่อน
ALTER TABLE pharmacist_interventions
    ADD COLUMN deviation_category VARCHAR(50) NULL DEFAULT NULL AFTER alert_type;
