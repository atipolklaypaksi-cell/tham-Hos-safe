<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

include('connect.php');
require_once __DIR__ . '/network_drug_helper.php';
mysqli_set_charset($con, 'utf8');
nd_ensure_tables($con);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_GET;
}

$action = trim($input['action'] ?? 'matrix');
$upload_id = (int)($input['upload_id'] ?? 0);
$search = trim($input['search'] ?? '');

if ($action === 'uploads') {
    echo json_encode([
        'status' => 'success',
        'uploads' => nd_list_uploads($con, 30),
        'active_upload_id' => nd_get_active_upload_id($con),
    ]);
    exit;
}

if ($action === 'detail') {
    $export_all = !empty($input['all']);
    echo json_encode([
        'status' => 'success',
        'hospitals' => nd_list_hospitals($con, 0),
        'stats' => nd_global_stats($con),
        'rows' => nd_fetch_detail_rows($con, 0, $search, $export_all ? 0 : 5000),
    ]);
    exit;
}

echo json_encode(nd_fetch_matrix($con, $upload_id, $search));
exit;
