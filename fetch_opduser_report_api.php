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

$action = $_GET['action'] ?? 'search';

if ($action === 'positions') {
    $result = tham_opduser_fetch_distinct_positions($con);
    if (empty($result['ok'])) {
        echo json_encode(['status' => 'error', 'msg' => $result['error'] ?? 'โหลดไม่สำเร็จ']);
        exit;
    }
    $positions = array_map(function ($p) {
        return [
            'entryposition' => $p['entryposition'],
            'user_count' => $p['user_count'],
        ];
    }, $result['positions'] ?? []);
    $positions[] = ['entryposition' => '__empty__', 'label' => '(ไม่ระบุตำแหน่ง)', 'user_count' => null];
    echo json_encode(['status' => 'success', 'positions' => $positions], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'departments') {
    echo json_encode([
        'status' => 'success',
        'departments' => tham_opduser_fetch_departments($con),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'entryposition' => trim($_GET['entryposition'] ?? ''),
    'department' => trim($_GET['department'] ?? ''),
    'limit' => (int)($_GET['limit'] ?? 500),
    'offset' => (int)($_GET['offset'] ?? 0),
];

$result = tham_opduser_fetch_users_report($con, $filters);
if (empty($result['ok'])) {
    echo json_encode(['status' => 'error', 'msg' => $result['error'] ?? 'ค้นหาไม่สำเร็จ']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'rows' => $result['rows'],
    'total' => $result['total'],
    'limit' => $result['limit'],
    'offset' => $result['offset'],
    'truncated' => $result['truncated'],
    'role_labels' => tham_menu_role_labels(),
], JSON_UNESCAPED_UNICODE);
