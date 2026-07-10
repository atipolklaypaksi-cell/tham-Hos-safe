<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';
require_once __DIR__ . '/opduser_auth_helper.php';

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

$result = tham_opduser_fetch_distinct_positions($con);
if (empty($result['ok'])) {
    echo json_encode(['status' => 'error', 'msg' => $result['error'] ?? 'โหลดไม่สำเร็จ']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'positions' => $result['positions'],
    'default_role' => $result['default_role'],
    'role_labels' => tham_menu_role_labels(),
], JSON_UNESCAPED_UNICODE);
