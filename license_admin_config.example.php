<?php
/**
 * ตัวอย่างการตั้งค่า Admin License (เซิร์ฟเวอร์กลางเท่านั้น)
 * คัดลอกเป็น license_admin_config.php แล้วแก้ค่า — ห้ามอัปขึ้น GitHub / ห้ามใส่ใน zip อัปเดต
 */
define('LICENSE_MASTER_PIN', 'change-me-pin');
define('LICENSE_SECRET_KEY', 'tham-hossafe-license-secret-change-me-32ch');
define('LICENSE_STORAGE_DIR', __DIR__ . '/storage/licenses');
define('LICENSE_DEFAULT_LAT', 13.7563);
define('LICENSE_DEFAULT_LNG', 100.5018);
