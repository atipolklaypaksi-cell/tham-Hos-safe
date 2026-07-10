<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include('connect.php');
mysqli_set_charset($con, "utf8");
require_once __DIR__ . '/menu_permissions_helper.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('admin.users')) {
    echo json_encode(['status' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;

// 🎯 ป้องกันไม่ให้แอดมินเผลอลบบัญชีที่ตัวเองกำลังใช้งานอยู่
if($id === $_SESSION['user_id']) {
    echo json_encode(['status' => 'error', 'msg' => 'ไม่สามารถลบบัญชีที่ท่านกำลังใช้งานอยู่ได้ครับ!']);
    exit;
}

if($id > 0) {
    mysqli_query($con, "DELETE FROM user_menu_permissions WHERE user_id = $id");
    if(mysqli_query($con, "DELETE FROM users WHERE id = $id")) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'msg' => mysqli_error($con)]);
    }
} else {
    echo json_encode(['status' => 'error', 'msg' => 'ไม่พบข้อมูลที่ต้องการลบ']);
}
?>