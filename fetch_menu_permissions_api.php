<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';

function tham_menu_api_json(array $payload) {
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tham_menu_api_read_input() {
    $input = null;
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $input = json_decode($raw, true);
    }
    if (!is_array($input) && isset($_POST['payload'])) {
        $input = json_decode((string)$_POST['payload'], true);
    }
    return is_array($input) ? $input : null;
}

if (!isset($_SESSION['user_id'])) {
    tham_menu_api_json(['status' => 'error', 'msg' => 'Unauthorized']);
}
if (empty($_SESSION['menu_permissions_loaded'])) {
    tham_menu_load_session_permissions($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');
}
if (!tham_menu_can('admin.menu_permissions')) {
    tham_menu_api_json(['status' => 'error', 'msg' => 'Unauthorized']);
}
session_write_close();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'registry') {
    tham_menu_api_json([
        'status' => 'success',
        'groups' => tham_menu_registry_groups(),
        'roles' => tham_menu_role_labels(),
        'all_keys' => tham_menu_all_keys(),
    ]);
}

if ($action === 'get_role') {
    $role = trim((string)($_GET['role'] ?? ''));
    $labels = tham_menu_role_labels();
    if (!isset($labels[$role])) {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ไม่พบ Role']);
    }
    $keys = tham_menu_fetch_role_permissions($con, $role);
    $hasCustom = !empty($keys);
    tham_menu_api_json([
        'status' => 'success',
        'role' => $role,
        'menu_keys' => $hasCustom ? $keys : tham_menu_all_keys(),
        'use_custom' => $hasCustom,
    ]);
}

if ($action === 'get_user') {
    $user_id = (int)($_GET['user_id'] ?? 0);
    $q = mysqli_query($con, "SELECT id, username, full_name, role, use_custom_menus FROM users WHERE id = $user_id LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ไม่พบผู้ใช้งาน']);
    }
    $user = mysqli_fetch_assoc($q);
    $useCustom = (int)($user['use_custom_menus'] ?? 0) === 1;
    $keys = $useCustom
        ? tham_menu_fetch_user_permissions($con, $user_id)
        : tham_menu_resolve_allowed_keys($con, $user_id, $user['role']);
    tham_menu_api_json([
        'status' => 'success',
        'user' => $user,
        'menu_keys' => $keys,
        'use_custom' => $useCustom,
        'role_keys' => tham_menu_fetch_role_permissions($con, $user['role']),
    ]);
}

if ($action === 'save_role' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = tham_menu_api_read_input();
    if (!$input) {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ข้อมูลไม่ถูกต้อง']);
    }
    $role = trim((string)($input['role'] ?? ''));
    $useCustom = !empty($input['use_custom']);
    $menuKeys = $input['menu_keys'] ?? [];
    $labels = tham_menu_role_labels();
    if (!isset($labels[$role])) {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ไม่พบ Role']);
    }
    if ($role === 'admin') {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ผู้ดูแลระบบเข้าถึงเมนูทั้งหมดโดยอัตโนมัติ']);
    }
    if ($useCustom) {
        if (!tham_menu_save_role_permissions($con, $role, is_array($menuKeys) ? $menuKeys : [])) {
            tham_menu_api_json(['status' => 'error', 'msg' => 'บันทึกไม่สำเร็จ']);
        }
    } else {
        $esc = mysqli_real_escape_string($con, $role);
        mysqli_query($con, "DELETE FROM role_menu_permissions WHERE role = '$esc'");
    }
    tham_menu_api_json(['status' => 'success']);
}

if ($action === 'save_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = tham_menu_api_read_input();
    if (!$input) {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ข้อมูลไม่ถูกต้อง']);
    }
    $user_id = (int)($input['user_id'] ?? 0);
    $useCustom = !empty($input['use_custom']);
    $menuKeys = $input['menu_keys'] ?? [];
    $q = mysqli_query($con, "SELECT id, role FROM users WHERE id = $user_id LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ไม่พบผู้ใช้งาน']);
    }
    $user = mysqli_fetch_assoc($q);
    if ($user['role'] === 'admin') {
        tham_menu_api_json(['status' => 'error', 'msg' => 'ผู้ดูแลระบบเข้าถึงเมนูทั้งหมดโดยอัตโนมัติ']);
    }
    if (!tham_menu_save_user_permissions($con, $user_id, $useCustom, is_array($menuKeys) ? $menuKeys : [])) {
        tham_menu_api_json(['status' => 'error', 'msg' => 'บันทึกไม่สำเร็จ']);
    }
    tham_menu_api_json(['status' => 'success']);
}

tham_menu_api_json(['status' => 'error', 'msg' => 'Invalid action']);
