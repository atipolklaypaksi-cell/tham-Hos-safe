<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$host = isset($data['h_host']) ? trim($data['h_host']) : '';
$db = isset($data['h_db']) ? trim($data['h_db']) : '';
$user = isset($data['h_user']) ? trim($data['h_user']) : '';
$pass = isset($data['h_pass']) ? $data['h_pass'] : '';

if (empty($host) || empty($db) || empty($user)) {
    echo json_encode(['status' => 'warning', 'message' => 'กรุณากรอก Host, Database Name และ Username ให้ครบถ้วน']);
    exit;
}

// PHP 8.1+ โยน mysqli_sql_exception เมื่อเชื่อมต่อล้มเหลว — ต้องปิด report mode
mysqli_report(MYSQLI_REPORT_OFF);

$test_conn = @mysqli_connect($host, $user, $pass, $db);

if ($test_conn) {
    mysqli_close($test_conn);
    echo json_encode(['status' => 'success', 'message' => 'สุดยอด! เชื่อมต่อฐานข้อมูล HOSxP สำเร็จพร้อมใช้งานครับ']);
} else {
    $error_msg = mysqli_connect_error() ?: 'ไม่สามารถเชื่อมต่อได้';
    echo json_encode(['status' => 'error', 'message' => 'เชื่อมต่อล้มเหลว: ' . $error_msg]);
}
