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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$username = mysqli_real_escape_string($con, trim($_POST['username'] ?? ''));
$full_name = mysqli_real_escape_string($con, trim($_POST['full_name'] ?? ''));
$role = mysqli_real_escape_string($con, $_POST['role'] ?? '');
$password = $_POST['password'] ?? '';
$role_from_entryposition = !empty($_POST['role_from_entryposition']) ? 1 : 0;

$valid_roles = tham_opduser_valid_roles();
if (!in_array($role, $valid_roles, true)) {
    echo json_encode(['status' => 'error', 'msg' => 'ระดับสิทธิ์ไม่ถูกต้อง']);
    exit;
}

tham_opduser_ensure_tables($con);

if ($id > 0) {
    $q = mysqli_query($con, "SELECT * FROM users WHERE id = $id LIMIT 1");
    if (!$q || mysqli_num_rows($q) === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'ไม่พบผู้ใช้งาน']);
        exit;
    }
    $existing = mysqli_fetch_assoc($q);
    $auth_source = $existing['auth_source'] ?? 'local';

    if ($auth_source === 'opduser') {
        if ($role_from_entryposition) {
            $role = tham_opduser_resolve_role($con, $existing['entryposition'] ?? '');
            $role = mysqli_real_escape_string($con, $role);
        }
        $sql = "UPDATE users SET full_name = '$full_name', role = '$role', role_from_entryposition = $role_from_entryposition WHERE id = $id";
    } else {
        if (!empty($password)) {
            $hashed = md5($password);
            $sql = "UPDATE users SET full_name = '$full_name', role = '$role', password = '$hashed', role_from_entryposition = 0 WHERE id = $id";
        } else {
            $sql = "UPDATE users SET full_name = '$full_name', role = '$role', role_from_entryposition = 0 WHERE id = $id";
        }
    }
} else {
    if ($username === '' || $full_name === '') {
        echo json_encode(['status' => 'error', 'msg' => 'กรุณากรอกข้อมูลให้ครบ']);
        exit;
    }
    if ($password === '') {
        echo json_encode(['status' => 'error', 'msg' => 'กรุณากำหนดรหัสผ่านสำหรับบัญชีภายใน']);
        exit;
    }
    $chk = mysqli_query($con, "SELECT id FROM users WHERE username = '$username'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        echo json_encode(['status' => 'error', 'msg' => 'Username นี้มีอยู่ในระบบแล้ว']);
        exit;
    }
    $hashed = md5($password);
    $sql = "INSERT INTO users (username, password, full_name, role, auth_source, role_from_entryposition)
        VALUES ('$username', '$hashed', '$full_name', '$role', 'local', 0)";
}

if (mysqli_query($con, $sql)) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'msg' => mysqli_error($con)]);
}
