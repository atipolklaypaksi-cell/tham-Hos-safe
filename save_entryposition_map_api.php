<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';
require_once __DIR__ . '/opduser_auth_helper.php';

function tham_save_map_json_response(array $payload) {
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    tham_save_map_json_response(['status' => 'error', 'msg' => 'Unauthorized']);
}
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('admin.users')) {
    tham_save_map_json_response(['status' => 'error', 'msg' => 'Unauthorized']);
}
session_write_close();

$data = null;
$raw = file_get_contents('php://input');
if ($raw !== false && trim($raw) !== '') {
    $data = json_decode($raw, true);
}
if (!is_array($data) && isset($_POST['positions'])) {
    $decoded = json_decode((string)$_POST['positions'], true);
    if (is_array($decoded)) {
        $data = isset($decoded['positions']) ? $decoded : ['positions' => $decoded];
    }
}
if (!is_array($data)) {
    tham_save_map_json_response(['status' => 'error', 'msg' => 'ข้อมูลไม่ถูกต้อง']);
}

$rows = $data['positions'] ?? [];
if (!is_array($rows)) {
    $rows = [];
}

try {
    $save = tham_opduser_save_role_map($con, $rows);
    if (empty($save['ok'])) {
        tham_save_map_json_response([
            'status' => 'error',
            'msg' => 'บันทึกไม่สำเร็จ: ' . ($save['error'] ?? 'unknown'),
        ]);
    }
    $updated = tham_opduser_apply_role_map_to_users($con);
    tham_save_map_json_response([
        'status' => 'success',
        'msg' => 'บันทึกการผูกสิทธิตามตำแหน่งเรียบร้อย',
        'users_updated' => $updated,
        'positions_saved' => (int)($save['saved'] ?? 0),
    ]);
} catch (Throwable $e) {
    tham_save_map_json_response([
        'status' => 'error',
        'msg' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage(),
    ]);
}
