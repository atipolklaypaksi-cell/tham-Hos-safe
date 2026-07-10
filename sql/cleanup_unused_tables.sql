-- ลบตารางที่ไม่ใช้ในระบบ THAM SAFE (รันครั้งเดียวหลังย้ายระบบ)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS batch_items;
DROP TABLE IF EXISTS containers;
DROP TABLE IF EXISTS diagnoses;
DROP TABLE IF EXISTS diluents;
DROP TABLE IF EXISTS dispense_logs;
DROP TABLE IF EXISTS doctors;
DROP TABLE IF EXISTS drug_lots;
DROP TABLE IF EXISTS med_error_records;
DROP TABLE IF EXISTS med_error_types;
DROP TABLE IF EXISTS mix_details;
DROP TABLE IF EXISTS order_edit_logs;
DROP TABLE IF EXISTS order_files;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS personnel;
DROP TABLE IF EXISTS pharmacist_interventions;
DROP TABLE IF EXISTS prep_batches;
DROP TABLE IF EXISTS protocols;
DROP TABLE IF EXISTS rates;
DROP TABLE IF EXISTS routes;
DROP TABLE IF EXISTS stock_transactions;
DROP TABLE IF EXISTS storages;
DROP TABLE IF EXISTS units;
DROP TABLE IF EXISTS wards;

SET FOREIGN_KEY_CHECKS = 1;
