<?php
/**
 * การตั้งค่า Admin — License Generator & Map Tracker
 * ⚠️ ไฟล์นี้ควรอยู่บนเซิร์ฟเวอร์กลางของคุณเท่านั้น (ไม่แจกจ่ายให้รพ.)
 * เปลี่ยน PIN และ SECRET_KEY ก่อนใช้งานจริง
 */

// PIN สำหรับป้องกันหน้า Generate (เปลี่ยนเป็นค่าของคุณ)
define('LICENSE_MASTER_PIN', 'Pupae@150703');

// คีย์ลับสำหรับ AES-256-CBC — ต้องตรงกับฝั่งรพ.ที่ใช้ตรวจ license (อย่างน้อย 32 ตัวอักษร)
define('LICENSE_SECRET_KEY', 'tham-hossafe-license-secret-change-me-32ch');

// โฟลเดอร์เก็บไฟล์ license.key ที่สร้างแล้ว
define('LICENSE_STORAGE_DIR', __DIR__ . '/storage/licenses');

// พิกัดเริ่มต้นบนแผนที่ (กลางประเทศไทย)
define('LICENSE_DEFAULT_LAT', 13.7563);
define('LICENSE_DEFAULT_LNG', 100.5018);
