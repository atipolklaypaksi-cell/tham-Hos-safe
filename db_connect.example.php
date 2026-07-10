<?php
/**
 * ตัวอย่างการเชื่อมต่อฐานข้อมูล
 * คัดลอกเป็น db_connect.php แล้วใส่ค่าจริงของโรงพยาบาล
 * ไฟล์ db_connect.php ห้ามอัปขึ้น GitHub
 */
require_once __DIR__ . '/check_license.php';
tham_check_license();

$con = mysqli_connect('127.0.0.1', 'db_user', 'db_password', 'db_thamhossafe');

if (!$con) {
    die('Connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($con, 'utf8mb4');
date_default_timezone_set('Asia/Bangkok');
