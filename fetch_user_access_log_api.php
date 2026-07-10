<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include('connect.php');
mysqli_set_charset($con, 'utf8');
require_once __DIR__ . '/menu_permissions_helper.php';
require_once __DIR__ . '/user_access_helper.php';

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

$filters = [
    'user_id' => (int)($_GET['user_id'] ?? 0),
    'action' => trim($_GET['action'] ?? ''),
    'q' => trim($_GET['q'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
    'limit' => (int)($_GET['limit'] ?? 100),
    'offset' => (int)($_GET['offset'] ?? 0),
];

$result = tham_user_access_fetch_logs($con, $filters);
$action_labels = [
    'login' => tham_user_access_action_label('login'),
    'logout' => tham_user_access_action_label('logout'),
    'active' => tham_user_access_action_label('active'),
];

echo json_encode([
    'status' => 'success',
    'rows' => $result['rows'],
    'total' => $result['total'],
    'limit' => $result['limit'],
    'offset' => $result['offset'],
    'action_labels' => $action_labels,
], JSON_UNESCAPED_UNICODE);
