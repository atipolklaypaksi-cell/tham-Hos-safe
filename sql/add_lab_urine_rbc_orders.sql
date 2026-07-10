-- เพิ่มคอลัมน์ผล LAB ปัสสาวะใน orders (ตรงกับ upgrade.php db_version 1.0.4)
-- รันบน DB ที่ใช้จริงถ้ายังไม่ได้กดอัปเดตผ่าน upgrade.php
-- ถ้ามีคอลัมน์แล้วให้ข้ามคำสั่งนั้น (หรือใช้ปุ่มอัปเกรดใน upgrade.php)

ALTER TABLE `orders` ADD COLUMN `lab_urine_rbc` VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `orders` ADD COLUMN `lab_urine_protein_24h` VARCHAR(255) NULL DEFAULT NULL;
