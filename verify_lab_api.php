<?php
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$host = isset($data['h_host']) ? $data['h_host'] : '';
$db   = isset($data['h_db']) ? $data['h_db'] : '';
$user = isset($data['h_user']) ? $data['h_user'] : '';
$pass = isset($data['h_pass']) ? $data['h_pass'] : '';
$code = isset($data['lab_code']) ? $data['lab_code'] : '';

if ($host == '' || $code == '') {
    echo '{"status":"error","message":"ส่งข้อมูลไปไม่ครบ"}';
    exit;
}

// PHP 8.1+ โยน exception เมื่อเชื่อมต่อล้มเหลว
mysqli_report(MYSQLI_REPORT_OFF);

// เริ่มเชื่อมต่อ
$con = @mysqli_connect($host, $user, $pass, $db);
if (!$con) {
    echo '{"status":"error","message":"เชื่อมต่อไม่ได้: ' . mysqli_connect_error() . '"}';
    exit;
}

mysqli_set_charset($con, "utf8");

$safe_code = mysqli_real_escape_string($con, $code);

// 🎯 1. แก้ไขคำสั่ง SQL ให้ดึงจากคอลัมน์ lab_items_name ให้ตรงกับรูปภาพของคุณต้า
$sql = "SELECT lab_items_name FROM lab_items WHERE lab_items_code = '$safe_code'";
$q = mysqli_query($con, $sql);

if ($q && mysqli_num_rows($q) > 0) {
    $row = mysqli_fetch_assoc($q);
    // 🎯 2. แสดงผลจากคอลัมน์ lab_items_name
    echo '{"status":"success","lab_name":"' . $row['lab_items_name'] . '"}';
} else {
    // 🎯 3. ดักจับ Error เผื่อว่า SQL ทำงานผิดพลาดจะได้บอกสาเหตุตรงๆ
    if (!$q) {
        $db_err = mysqli_error($con);
        echo '{"status":"error","message":"SQL Error: ' . $db_err . '"}';
    } else {
        echo '{"status":"error","message":"ไม่พบรหัส Lab นีัใน HOSxP"}';
    }
}

mysqli_close($con);
?>