-- เพิ่มคอลัมน์สำหรับเปิด/ปิดการแสดงยาใน stock_confirm.php (ตัดสต๊อกอัตโนมัติ)
-- รันครั้งเดียวบนฐานข้อมูลที่ใช้กับระบบนี้

ALTER TABLE `drugitems`
  ADD COLUMN `auto_stock_cut` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=แสดงในตัดสต๊อกอัตโนมัติ, 0=ไม่แสดง'
  AFTER `barcode5`;
