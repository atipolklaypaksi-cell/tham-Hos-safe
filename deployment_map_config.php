<?php
/**
 * การตั้งค่า Spot Map — ใช้ได้ทุก รพ. (แจกจ่ายไฟล์นี้พร้อมโปรแกรม)
 * เปลี่ยน URL เป็นเซิร์ฟเวอร์กลางที่เก็บ tb_deployments
 */
define('THAM_DEPLOYMENT_MAP_URL', 'http://61.7.167.197/thamhossafe/map_dashboard.php');

/**
 * เปิดเมนู "สร้าง License" ใน Admin
 * ค่าเริ่มต้นสำหรับ รพ. = false
 * เซิร์ฟเวอร์กลางให้สร้าง deployment_map_local.php แล้วตั้งเป็น true
 */
define('THAM_LICENSE_ADMIN_ENABLED', false);
